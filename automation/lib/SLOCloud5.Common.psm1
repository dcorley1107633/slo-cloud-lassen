#Requires -Version 5.1
<#
    SLO Cloud 5 installer - shared module.

    Used by Install-SLOCloud5.ps1 and Undo-SLOCloud5.ps1 (Test-SLOCloud5.ps1 is
    deliberately independent and does not import this module).

    Provides:
      - logging with credential redaction
      - the durable change journal (undo-manifest.json) and per-run backups
      - native-command, hashing, ACL, machine-environment and IIS helpers
      - pinned/verified source downloads
      - the inverse ("revert") engine used by rollback and uninstall

    Target: Windows PowerShell 5.1 (Desktop edition) on Windows Server, elevated.
    Nothing in this module reads the contents of secret files.
#>

# Version 1: catches unset variables. Version 2+ would also throw on reading optional
# properties of journal records and $null results, which these scripts do deliberately.
Set-StrictMode -Version 1.0
# Module functions do not inherit the calling script's preference; make cmdlet errors terminating
# so a failed step is never recorded as applied or reverted.
$ErrorActionPreference = 'Stop'

$script:SloStateRoot   = Join-Path $env:ProgramData 'SLOCloud5\installer'
$script:SloJournalPath = Join-Path $script:SloStateRoot 'undo-manifest.json'
$script:SloLockPath    = Join-Path $script:SloStateRoot 'installer.lock'
$script:SloApphost     = 'MACHINE/WEBROOT/APPHOST'
$script:SloJournal     = $null
$script:SloRun         = $null
$script:SloLogPath     = $null
$script:SloPlanMode    = $false
$script:SloLockStream  = $null
$script:SloPlanned     = New-Object System.Collections.ArrayList

# Well-known SIDs (locale-independent).
$script:SidSystem = 'S-1-5-18'
$script:SidAdmins = 'S-1-5-32-544'
$script:SidUsers  = 'S-1-5-32-545'

# ---------------------------------------------------------------------------
# State, logging
# ---------------------------------------------------------------------------

function Get-SloStateRoot { $script:SloStateRoot }
function Get-SloJournalPath { $script:SloJournalPath }
function Get-SloRun { $script:SloRun }
function Get-SloJournal { $script:SloJournal }
function Test-SloPlanMode { [bool]$script:SloPlanMode }
function Get-SloPlannedChanges { $script:SloPlanned.ToArray() }

function Protect-SloText {
    param([AllowNull()][string]$Text)
    if ($null -eq $Text) { return $null }
    # Strip credentials embedded in URLs (scheme://user:secret@host).
    $t = [regex]::Replace($Text, '(?i)([a-z][a-z0-9+.\-]*://)[^/@\s:]+(:[^/@\s]*)?@', '$1***@')
    # Common token / password key=value shapes.
    $t = [regex]::Replace($t, '(?i)\b(password|passwd|pwd|secret|token|apikey|api_key)\s*[=:]\s*\S+', '$1=***')
    return $t
}

function Write-SloLog {
    param(
        [Parameter(Mandatory)][AllowEmptyString()][string]$Message,
        [ValidateSet('INFO', 'STEP', 'PLAN', 'WARN', 'ERROR', 'OK', 'IT')][string]$Level = 'INFO'
    )
    $safe = Protect-SloText $Message
    $line = '{0} [{1}] {2}' -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Level, $safe
    $color = switch ($Level) {
        'STEP'  { 'Cyan' }
        'PLAN'  { 'Magenta' }
        'WARN'  { 'Yellow' }
        'ERROR' { 'Red' }
        'OK'    { 'Green' }
        'IT'    { 'Yellow' }
        default { 'Gray' }
    }
    Write-Host $line -ForegroundColor $color
    if ($script:SloLogPath) {
        try { Add-Content -LiteralPath $script:SloLogPath -Value $line -Encoding UTF8 } catch { }
    }
}

function Initialize-SloStateRoot {
    if (-not (Test-Path -LiteralPath $script:SloStateRoot)) {
        New-Item -ItemType Directory -Path $script:SloStateRoot -Force | Out-Null
    }
    # The journal and backups are administrator-only and live outside the install root.
    Set-SloAdminOnlyAcl -Path $script:SloStateRoot
    foreach ($sub in @('runs', 'baseline', 'downloads', 'validation')) {
        $p = Join-Path $script:SloStateRoot $sub
        if (-not (Test-Path -LiteralPath $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
    }
}

function Set-SloAdminOnlyAcl {
    <# Protected DACL: SYSTEM and Administrators full control, nothing else. #>
    param([Parameter(Mandatory)][string]$Path)
    $item = Get-Item -LiteralPath $Path -Force
    $sd = $item.GetAccessControl('Access')
    $sd.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($sd.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier]))) {
        [void]$sd.RemoveAccessRuleSpecific($rule)
    }
    foreach ($sid in @($script:SidSystem, $script:SidAdmins)) {
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            (New-Object System.Security.Principal.SecurityIdentifier($sid)),
            [System.Security.AccessControl.FileSystemRights]::FullControl,
            ([System.Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'),
            [System.Security.AccessControl.PropagationFlags]::None,
            [System.Security.AccessControl.AccessControlType]::Allow)
        $sd.AddAccessRule($rule)
    }
    $item.SetAccessControl($sd)
}

function Enter-SloLock {
    try {
        $script:SloLockStream = [System.IO.File]::Open($script:SloLockPath, 'OpenOrCreate', 'ReadWrite', 'None')
    } catch {
        throw "Another SLO Cloud 5 installer or undo process is running (lock: $($script:SloLockPath))."
    }
}

function Exit-SloLock {
    if ($script:SloLockStream) {
        $script:SloLockStream.Dispose()
        $script:SloLockStream = $null
    }
}

# ---------------------------------------------------------------------------
# Durable file writes, hashing
# ---------------------------------------------------------------------------

function Write-SloFileDurable {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][AllowEmptyString()][string]$Content)
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $tmp = Join-Path $dir ('.' + [System.IO.Path]::GetFileName($Path) + '.' + [guid]::NewGuid().ToString('N') + '.tmp')
    $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($Content)
    $fs = New-Object System.IO.FileStream($tmp, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write,
        [System.IO.FileShare]::None, 4096, [System.IO.FileOptions]::WriteThrough)
    try {
        $fs.Write($bytes, 0, $bytes.Length)
        $fs.Flush($true)
    } finally {
        $fs.Dispose()
    }
    if (Test-Path -LiteralPath $Path) {
        [System.IO.File]::Replace($tmp, $Path, ($Path + '.prev'))
    } else {
        [System.IO.File]::Move($tmp, $Path)
    }
}

function Write-SloJsonFile {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)]$Object)
    Write-SloFileDurable -Path $Path -Content ($Object | ConvertTo-Json -Depth 32)
}

function Read-SloJsonFile {
    param([Parameter(Mandatory)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    $raw = [System.IO.File]::ReadAllText($Path)
    if ([string]::IsNullOrWhiteSpace($raw)) { return $null }
    return ($raw | ConvertFrom-Json)
}

function Get-SloFileHash {
    param([Parameter(Mandatory)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToUpperInvariant()
}

function Write-SloTextFileNoBom {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][AllowEmptyString()][string]$Content)
    [System.IO.File]::WriteAllText($Path, $Content, (New-Object System.Text.UTF8Encoding($false)))
}

# ---------------------------------------------------------------------------
# Native commands
# ---------------------------------------------------------------------------

function Invoke-SloNative {
    <#
        Runs a native executable without letting stderr output become a
        terminating PowerShell error (Windows PowerShell 5.1 behaviour with
        2>&1 under ErrorActionPreference=Stop). -Interactive leaves stdin/stdout
        attached to the console (used for git operations that may prompt for
        credentials, so nothing typed is captured or logged).
    #>
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [string[]]$Arguments = @(),
        [switch]$AllowFailure,
        [switch]$Interactive,
        [switch]$Quiet
    )
    $display = Protect-SloText ('"{0}" {1}' -f $FilePath, ($Arguments -join ' '))
    if (-not $Quiet) { Write-SloLog "exec: $display" }
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($Interactive) {
            & $FilePath @Arguments
            $code = $LASTEXITCODE
            $out = @()
        } else {
            $out = @(& $FilePath @Arguments 2>&1 | ForEach-Object { "$_" })
            $code = $LASTEXITCODE
        }
    } finally {
        $ErrorActionPreference = $old
    }
    if ($null -eq $code) { $code = 0 }
    $result = [pscustomobject]@{ ExitCode = [int]$code; Output = $out; Text = ($out -join [Environment]::NewLine) }
    if (-not $AllowFailure -and $result.ExitCode -ne 0) {
        throw ("Command failed (exit {0}): {1}{2}{3}" -f $result.ExitCode, $display, [Environment]::NewLine, (Protect-SloText $result.Text))
    }
    return $result
}

# ---------------------------------------------------------------------------
# Journal
# ---------------------------------------------------------------------------

function New-SloJournalObject {
    [pscustomobject]@{
        schemaVersion = 1
        product       = 'SLO Cloud 5'
        createdAt     = (Get-Date).ToString('o')
        stateRoot     = $script:SloStateRoot
        runs          = @()
    }
}

function Read-SloJournal {
    $journal = $null
    try {
        $journal = Read-SloJsonFile -Path $script:SloJournalPath
    } catch {
        $prev = $script:SloJournalPath + '.prev'
        Write-SloLog "Journal unreadable ($($_.Exception.Message)); trying last-good copy $prev" 'WARN'
        $journal = Read-SloJsonFile -Path $prev
        if ($null -eq $journal) { throw "The undo journal is corrupt and no last-good copy exists: $($script:SloJournalPath)" }
    }
    if ($null -eq $journal) { $journal = New-SloJournalObject }
    if ($null -eq $journal.runs) { $journal.runs = @() }
    $script:SloJournal = $journal
    return $journal
}

function Save-SloJournal {
    if ($script:SloPlanMode -or $null -eq $script:SloJournal) { return }
    Write-SloJsonFile -Path $script:SloJournalPath -Object $script:SloJournal
}

function Start-SloRun {
    param(
        [Parameter(Mandatory)][ValidateSet('install', 'finalize', 'undo')][string]$Kind,
        [string]$Mode = '',
        [hashtable]$Parameters = @{},
        [switch]$Plan
    )
    $script:SloPlanMode = [bool]$Plan
    $script:SloPlanned.Clear()
    $runId = (Get-Date).ToString('yyyyMMdd-HHmmss') + '-' + ([guid]::NewGuid().ToString('N').Substring(0, 4))
    $runDir = Join-Path (Join-Path $script:SloStateRoot 'runs') $runId
    if (-not $Plan) {
        New-Item -ItemType Directory -Path (Join-Path $runDir 'backups') -Force | Out-Null
        $script:SloLogPath = Join-Path $runDir ("{0}.log" -f $Kind)
    } else {
        $script:SloLogPath = $null
    }
    $run = [pscustomobject]@{
        runId      = $runId
        kind       = $Kind
        mode       = $Mode
        startedAt  = (Get-Date).ToString('o')
        finishedAt = $null
        status     = 'running'
        host       = $env:COMPUTERNAME
        user       = ('{0}\{1}' -f $env:USERDOMAIN, $env:USERNAME)
        parameters = [pscustomobject]$Parameters
        runDir     = $runDir
        logPath    = $script:SloLogPath
        itItems    = @()
        warnings   = @()
        operations = @()
    }
    $script:SloRun = $run
    if (-not $Plan) {
        $script:SloJournal.runs = @($script:SloJournal.runs) + $run
        Save-SloJournal
    }
    return $run
}

function Complete-SloRun {
    param([Parameter(Mandatory)][ValidateSet('succeeded', 'failed', 'aborted', 'partial')][string]$Status)
    if ($null -eq $script:SloRun) { return }
    $script:SloRun.status = $Status
    $script:SloRun.finishedAt = (Get-Date).ToString('o')
    Save-SloJournal
}

function Add-SloItItem {
    param([Parameter(Mandatory)][string]$Text)
    Write-SloLog $Text 'IT'
    if ($script:SloRun) {
        if (@($script:SloRun.itItems) -notcontains $Text) { $script:SloRun.itItems = @($script:SloRun.itItems) + $Text }
        Save-SloJournal
    }
}

function Add-SloWarning {
    param([Parameter(Mandatory)][string]$Text)
    Write-SloLog $Text 'WARN'
    if ($script:SloRun) {
        $script:SloRun.warnings = @($script:SloRun.warnings) + $Text
        Save-SloJournal
    }
}

function Get-SloRunBackupDir {
    if ($null -eq $script:SloRun) { throw 'No active run.' }
    return (Join-Path $script:SloRun.runDir 'backups')
}

function Get-SloAllOperations {
    <# Applied/failed/pending operations from install and finalize runs, in journal order. #>
    param([string[]]$Kinds = @('install', 'finalize'))
    $list = New-Object System.Collections.ArrayList
    foreach ($run in @($script:SloJournal.runs)) {
        if ($Kinds -notcontains $run.kind) { continue }
        foreach ($op in @($run.operations)) { [void]$list.Add($op) }
    }
    return $list.ToArray()
}

function Get-SloLiveOperations {
    <# Operations that took effect and have not been reverted. Optionally filtered. #>
    param([string]$Kind, [string]$Resource)
    $result = @()
    foreach ($op in (Get-SloAllOperations)) {
        if (@('applied', 'failed', 'pending') -notcontains $op.status) { continue }
        if ($op.revertedBy) { continue }
        if ($Kind -and $op.kind -ne $Kind) { continue }
        if ($Resource -and $op.resource -ne $Resource) { continue }
        $result += $op
    }
    return $result
}

function Test-SloInstallerCreated {
    <# True when a live journal operation records that the installer created this resource. #>
    param([Parameter(Mandatory)][string]$Resource)
    foreach ($op in (Get-SloLiveOperations -Resource $Resource)) {
        if ($op.ownership -eq 'created' -and $op.status -eq 'applied') { return $true }
    }
    return $false
}

function Get-SloLastLiveOperation {
    param([Parameter(Mandatory)][string]$Kind, [Parameter(Mandatory)][string]$Resource)
    $ops = Get-SloLiveOperations -Kind $Kind -Resource $Resource
    $applied = @($ops | Where-Object { $_.status -eq 'applied' })
    if ($applied.Count -eq 0) { return $null }
    return $applied[$applied.Count - 1]
}

function Invoke-SloChange {
    <#
        Journal-first change wrapper.

        1. Records the operation (prior state, intended state, inverse) and
           saves the journal durably BEFORE anything changes.
        2. Runs -Apply.
        3. Marks the operation applied (optionally with a refreshed 'after'
           from -GetAfter) or failed, and saves again.

        In plan mode nothing is recorded or applied; the change is listed.
    #>
    param(
        [Parameter(Mandatory)][string]$Kind,
        [Parameter(Mandatory)][string]$Resource,
        [Parameter(Mandatory)][string]$Description,
        [Parameter(Mandatory)][ValidateSet('created', 'modified', 'preexisting')][string]$Ownership,
        $Before = $null,
        $After = $null,
        [Parameter(Mandatory)][hashtable]$Inverse,
        [ValidateSet('full', 'partial', 'manual', 'none')][string]$Reversibility = 'full',
        [string]$Backup = $null,
        [string[]]$DependsOn = @(),
        [Parameter(Mandatory)][scriptblock]$Apply,
        [scriptblock]$GetAfter
    )
    if ($script:SloPlanMode) {
        Write-SloLog ("would {0}: {1} [{2}, {3}]" -f $Kind, $Description, $Ownership, $Reversibility) 'PLAN'
        [void]$script:SloPlanned.Add([pscustomobject]@{ kind = $Kind; resource = $Resource; description = $Description; ownership = $Ownership; reversibility = $Reversibility })
        return [pscustomobject]@{ id = 'plan'; planned = $true; after = $After }
    }
    $seq = @($script:SloRun.operations).Count + 1
    $op = [pscustomobject]@{
        id            = ('{0}-{1:D4}' -f $script:SloRun.runId, $seq)
        seq           = $seq
        runId         = $script:SloRun.runId
        timestamp     = (Get-Date).ToString('o')
        kind          = $Kind
        resource      = $Resource
        ownership     = $Ownership
        description   = $Description
        before        = $Before
        after         = $After
        backup        = $Backup
        backupHash    = $(if ($Backup -and (Test-Path -LiteralPath $Backup -PathType Leaf)) { Get-SloFileHash $Backup } else { $null })
        inverse       = [pscustomobject]$Inverse
        reversibility = $Reversibility
        dependsOn     = @($DependsOn | Where-Object { $_ -and $_ -ne 'plan' })
        status        = 'pending'
        appliedAt     = $null
        error         = $null
        note          = $null
        revertedBy    = $null
        revertedAt    = $null
        revertResult  = $null
    }
    $script:SloRun.operations = @($script:SloRun.operations) + $op
    Save-SloJournal
    Write-SloLog ("{0}: {1}" -f $Kind, $Description) 'STEP'
    try {
        $null = & $Apply
        if ($GetAfter) { $op.after = (& $GetAfter) }
        $op.status = 'applied'
        $op.appliedAt = (Get-Date).ToString('o')
        Save-SloJournal
        return $op
    } catch {
        $op.status = 'failed'
        $op.error = (Protect-SloText $_.Exception.Message)
        Save-SloJournal
        throw
    }
}

function Add-SloSkip {
    <# Records that a resource was already in the desired state (idempotent rerun). #>
    param([Parameter(Mandatory)][string]$Kind, [Parameter(Mandatory)][string]$Resource, [Parameter(Mandatory)][string]$Description)
    if ($script:SloPlanMode) {
        Write-SloLog ("no change {0}: {1}" -f $Kind, $Description) 'PLAN'
        return
    }
    Write-SloLog ("unchanged {0}: {1}" -f $Kind, $Description) 'OK'
    $seq = @($script:SloRun.operations).Count + 1
    $op = [pscustomobject]@{
        id = ('{0}-{1:D4}' -f $script:SloRun.runId, $seq); seq = $seq; runId = $script:SloRun.runId
        timestamp = (Get-Date).ToString('o'); kind = $Kind; resource = $Resource; ownership = 'preexisting'
        description = $Description; before = $null; after = $null; backup = $null; backupHash = $null
        inverse = [pscustomobject]@{ type = 'none' }; reversibility = 'none'; dependsOn = @()
        status = 'skipped'; appliedAt = $null; error = $null; note = 'already in desired state'
        revertedBy = $null; revertedAt = $null; revertResult = $null
    }
    $script:SloRun.operations = @($script:SloRun.operations) + $op
    Save-SloJournal
}

function Backup-SloFile {
    <# Copies a file into this run's backup folder (never overwriting) and into the baseline folder if no baseline exists. #>
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Name)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    if ($script:SloPlanMode) { return '(plan)' }
    $dest = Join-Path (Get-SloRunBackupDir) $Name
    $i = 1
    while (Test-Path -LiteralPath $dest) { $dest = Join-Path (Get-SloRunBackupDir) ("{0}.{1}" -f $Name, $i); $i++ }
    $destDir = Split-Path -Parent $dest
    if (-not (Test-Path -LiteralPath $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
    Copy-Item -LiteralPath $Path -Destination $dest -Force
    $baseline = Join-Path (Join-Path $script:SloStateRoot 'baseline') $Name
    if (-not (Test-Path -LiteralPath $baseline)) {
        $bdir = Split-Path -Parent $baseline
        if (-not (Test-Path -LiteralPath $bdir)) { New-Item -ItemType Directory -Path $bdir -Force | Out-Null }
        Copy-Item -LiteralPath $Path -Destination $baseline -Force
    }
    return $dest
}

# ---------------------------------------------------------------------------
# ACLs
# ---------------------------------------------------------------------------

function Get-SloAclSnapshot {
    <# Full security descriptor (SDDL, SACL when readable) plus the DACL alone. #>
    param([Parameter(Mandatory)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        return [pscustomobject]@{ path = $Path; exists = $false; access = $null; full = $null; saclCaptured = $false; protected = $null }
    }
    $item = Get-Item -LiteralPath $Path -Force
    $accessSd = $item.GetAccessControl('Access')
    $full = $null
    $sacl = $false
    try {
        $full = $item.GetAccessControl('Access, Owner, Group, Audit').GetSecurityDescriptorSddlForm('All')
        $sacl = $true
    } catch {
        $full = $item.GetAccessControl('Access, Owner, Group').GetSecurityDescriptorSddlForm('All')
    }
    return [pscustomobject]@{
        path         = $Path
        exists       = $true
        access       = $accessSd.GetSecurityDescriptorSddlForm('Access')
        full         = $full
        saclCaptured = $sacl
        protected    = $accessSd.AreAccessRulesProtected
    }
}

function Get-SloAccessSddl {
    param([Parameter(Mandatory)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    return (Get-Item -LiteralPath $Path -Force).GetAccessControl('Access').GetSecurityDescriptorSddlForm('Access')
}

function Set-SloAccessSddl {
    <# Restores only the DACL (and its protection flag). Owner/group/SACL are never changed by the installer. #>
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Sddl)
    $item = Get-Item -LiteralPath $Path -Force
    $sd = $item.GetAccessControl('Access')
    $sd.SetSecurityDescriptorSddlForm($Sddl, 'Access')
    $item.SetAccessControl($sd)
}

function Resolve-SloSid {
    param([Parameter(Mandatory)][string]$Identity)
    if ($Identity -match '^S-1-') { return New-Object System.Security.Principal.SecurityIdentifier($Identity) }
    $acct = New-Object System.Security.Principal.NTAccount($Identity)
    return $acct.Translate([System.Security.Principal.SecurityIdentifier])
}

function New-SloDesiredAccessSddl {
    <#
        Computes the DACL SDDL the directory should have, without applying it.
        -Exact: protected DACL containing only SYSTEM, Administrators and -Rules.
        Otherwise: current DACL plus -Rules (inheritance kept).
        Rules: @{ Identity = 'IIS AppPool\X' | SID; Rights = 'Modify' | 'ReadAndExecute' | 'Read' | 'FullControl'; FolderOnly = $true (optional: not inherited by contents) }
    #>
    param([Parameter(Mandatory)][string]$Path, [object[]]$Rules = @(), [switch]$Exact)
    $item = Get-Item -LiteralPath $Path -Force
    $sd = $item.GetAccessControl('Access')
    $inherit = [System.Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
    $none = [System.Security.AccessControl.PropagationFlags]::None
    $allow = [System.Security.AccessControl.AccessControlType]::Allow
    if ($Exact) {
        $sd.SetAccessRuleProtection($true, $false)
        foreach ($rule in @($sd.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier]))) {
            [void]$sd.RemoveAccessRuleSpecific($rule)
        }
        foreach ($sid in @($script:SidSystem, $script:SidAdmins)) {
            $sd.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
                (New-Object System.Security.Principal.SecurityIdentifier($sid)),
                [System.Security.AccessControl.FileSystemRights]::FullControl, $inherit, $none, $allow)))
        }
    }
    foreach ($r in $Rules) {
        $sid = Resolve-SloSid $r.Identity
        if (-not $Exact) {
            # Replace any explicit rule for this identity so reruns converge.
            foreach ($existing in @($sd.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier]))) {
                if ($existing.IdentityReference.Value -eq $sid.Value) { [void]$sd.RemoveAccessRuleSpecific($existing) }
            }
        }
        $rights = [System.Security.AccessControl.FileSystemRights]$r.Rights
        $ruleInherit = if ($r.FolderOnly) { [System.Security.AccessControl.InheritanceFlags]::None } else { $inherit }
        $sd.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($sid, $rights, $ruleInherit, $none, $allow)))
    }
    return $sd.GetSecurityDescriptorSddlForm('Access')
}

function Set-SloDirectoryAcl {
    <#
        Journaled ACL change. -DirectoryCreatedByInstaller decides the inverse:
        a directory the installer created keeps its protective ACL when retained
        (or loses it with the directory); a preexisting directory gets its exact
        prior DACL restored.
    #>
    param(
        [Parameter(Mandatory)][string]$Path,
        [object[]]$Rules = @(),
        [switch]$Exact,
        [switch]$DirectoryCreatedByInstaller,
        [string]$Purpose = '',
        [switch]$RestoreOnUndo
    )
    $resource = "acl:$Path"
    if ($script:SloPlanMode -and -not (Test-Path -LiteralPath $Path)) {
        $desc = "set ACL on $Path ($Purpose)"
        return (Invoke-SloChange -Kind 'acl.set' -Resource $resource -Description $desc -Ownership 'modified' -Inverse @{ type = 'none' } -Apply { })
    }
    $before = Get-SloAclSnapshot -Path $Path
    $desired = New-SloDesiredAccessSddl -Path $Path -Rules $Rules -Exact:$Exact
    if ($before.access -eq $desired) {
        Add-SloSkip -Kind 'acl.set' -Resource $resource -Description "ACL on $Path already as required ($Purpose)"
        return $null
    }
    $backup = $null
    if (-not $script:SloPlanMode) {
        $safeName = 'acl\' + ([regex]::Replace($Path, '[\\/:*?"<>| ]', '_')) + '.icacls'
        $backup = Join-Path (Get-SloRunBackupDir) $safeName
        $bdir = Split-Path -Parent $backup
        if (-not (Test-Path -LiteralPath $bdir)) { New-Item -ItemType Directory -Path $bdir -Force | Out-Null }
        $r = Invoke-SloNative -FilePath "$env:windir\System32\icacls.exe" -Arguments @($Path, '/save', $backup) -AllowFailure -Quiet
        if ($r.ExitCode -ne 0) { $backup = $null }
    }
    if ($DirectoryCreatedByInstaller -and -not $RestoreOnUndo) {
        $inverse = @{ type = 'none'; note = 'Directory created by the installer: its ACL is removed with it, or kept as a protective ACL when the directory is retained.' }
    } else {
        $inverse = @{ type = 'acl.restore'; path = $Path; before = $before.access; after = $desired }
    }
    return (Invoke-SloChange -Kind 'acl.set' -Resource $resource -Description "set ACL on $Path ($Purpose)" `
        -Ownership 'modified' -Before $before -After ([pscustomobject]@{ access = $desired }) -Backup $backup -Inverse $inverse `
        -Apply { Set-SloAccessSddl -Path $Path -Sddl $desired } `
        -GetAfter { [pscustomobject]@{ access = (Get-SloAccessSddl -Path $Path) } })
}

# ---------------------------------------------------------------------------
# Machine environment (registry-level, preserves REG_EXPAND_SZ)
# ---------------------------------------------------------------------------

$script:EnvKeyPath = 'SYSTEM\CurrentControlSet\Control\Session Manager\Environment'

function Get-SloMachineEnv {
    param([Parameter(Mandatory)][string]$Name)
    $key = [Microsoft.Win32.Registry]::LocalMachine.OpenSubKey($script:EnvKeyPath, $false)
    try {
        if (@($key.GetValueNames()) -notcontains $Name) {
            # Value names are case-insensitive.
            $match = @($key.GetValueNames() | Where-Object { $_ -ieq $Name })
            if ($match.Count -eq 0) { return [pscustomobject]@{ name = $Name; exists = $false; value = $null; kind = $null } }
            $Name = $match[0]
        }
        $value = $key.GetValue($Name, $null, [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
        $kind = $key.GetValueKind($Name).ToString()
        return [pscustomobject]@{ name = $Name; exists = $true; value = [string]$value; kind = $kind }
    } finally {
        $key.Dispose()
    }
}

function Send-SloEnvironmentBroadcast {
    if (-not ('SloNativeMethods' -as [type])) {
        Add-Type -Namespace '' -Name 'SloNativeMethods' -MemberDefinition @'
[System.Runtime.InteropServices.DllImport("user32.dll", SetLastError = true, CharSet = System.Runtime.InteropServices.CharSet.Auto)]
public static extern System.IntPtr SendMessageTimeout(System.IntPtr hWnd, uint Msg, System.UIntPtr wParam, string lParam, uint fuFlags, uint uTimeout, out System.UIntPtr lpdwResult);
'@
    }
    $result = [UIntPtr]::Zero
    [void][SloNativeMethods]::SendMessageTimeout([IntPtr]0xffff, 0x1A, [UIntPtr]::Zero, 'Environment', 2, 5000, [ref]$result)
}

function Set-SloMachineEnvRaw {
    param([Parameter(Mandatory)][string]$Name, [AllowNull()][string]$Value, [string]$Kind = 'String')
    $key = [Microsoft.Win32.Registry]::LocalMachine.OpenSubKey($script:EnvKeyPath, $true)
    try {
        if ($null -eq $Value) {
            if (@($key.GetValueNames()) -contains $Name) { $key.DeleteValue($Name) }
        } else {
            $key.SetValue($Name, $Value, [Microsoft.Win32.RegistryValueKind]$Kind)
        }
    } finally {
        $key.Dispose()
    }
    Send-SloEnvironmentBroadcast
}

function Set-SloMachineEnv {
    param([Parameter(Mandatory)][string]$Name, [Parameter(Mandatory)][string]$Value)
    $before = Get-SloMachineEnv -Name $Name
    if ($before.exists -and $before.value -eq $Value) {
        Add-SloSkip -Kind 'env.set' -Resource "env:Machine:$Name" -Description "machine variable $Name already set"
        return
    }
    $own = if ($before.exists) { 'modified' } else { 'created' }
    Invoke-SloChange -Kind 'env.set' -Resource "env:Machine:$Name" -Description "set machine variable $Name=$Value" `
        -Ownership $own -Before $before -After ([pscustomobject]@{ value = $Value }) `
        -Inverse @{ type = 'env.restore'; name = $Name; existedBefore = $before.exists; beforeValue = $before.value; beforeKind = $before.kind; afterValue = $Value } `
        -Apply { Set-SloMachineEnvRaw -Name $Name -Value $Value -Kind 'String' } | Out-Null
}

function Test-SloPathEntry {
    param([Parameter(Mandatory)][string]$PathValue, [Parameter(Mandatory)][string]$Entry)
    $norm = $Entry.TrimEnd('\')
    foreach ($p in ($PathValue -split ';')) {
        if ($p.Trim().TrimEnd('\') -ieq $norm) { return $true }
    }
    return $false
}

function Add-SloMachinePathEntry {
    param([Parameter(Mandatory)][string]$Entry)
    $before = Get-SloMachineEnv -Name 'Path'
    $current = if ($before.exists) { $before.value } else { '' }
    if (Test-SloPathEntry -PathValue $current -Entry $Entry) {
        Add-SloSkip -Kind 'env.path' -Resource "path:Machine:$Entry" -Description "machine PATH already contains $Entry"
        return
    }
    # Names below must not collide with Invoke-SloChange parameters (Kind, Before, After, ...):
    # the -Apply block is created in this module and resolves variables dynamically.
    $newPathValue = if ($current -and -not $current.EndsWith(';')) { "$current;$Entry" } else { "$current$Entry" }
    $pathValueKind = if ($before.kind) { $before.kind } else { 'ExpandString' }
    $pathValueName = if ($before.exists) { $before.name } else { 'Path' }
    $hashBefore = [System.BitConverter]::ToString((New-Object System.Security.Cryptography.SHA256Managed).ComputeHash([System.Text.Encoding]::UTF8.GetBytes($current))).Replace('-', '')
    Invoke-SloChange -Kind 'env.path' -Resource "path:Machine:$Entry" -Description "append $Entry to machine PATH" `
        -Ownership 'modified' -Before ([pscustomobject]@{ sha256 = $hashBefore; kind = $pathValueKind; length = $current.Length }) -After ([pscustomobject]@{ entry = $Entry }) `
        -Inverse @{ type = 'path.removeEntry'; entry = $Entry } `
        -Apply { Set-SloMachineEnvRaw -Name $pathValueName -Value $newPathValue -Kind $pathValueKind } | Out-Null
}

function Remove-SloMachinePathEntry {
    param([Parameter(Mandatory)][string]$Entry)
    $cur = Get-SloMachineEnv -Name 'Path'
    if (-not $cur.exists) { return $false }
    $norm = $Entry.TrimEnd('\')
    $parts = @($cur.value -split ';' | Where-Object { $_ -ne '' -and $_.Trim().TrimEnd('\') -ine $norm })
    $new = $parts -join ';'
    if ($new -eq $cur.value.TrimEnd(';')) { return $false }
    Set-SloMachineEnvRaw -Name $cur.name -Value $new -Kind $cur.kind
    return $true
}

# ---------------------------------------------------------------------------
# IIS
# ---------------------------------------------------------------------------

function Import-SloWebAdministration {
    if (-not (Get-Module -Name WebAdministration)) {
        Import-Module WebAdministration -ErrorAction Stop -Verbose:$false
    }
}

function Get-SloApphost { $script:SloApphost }

function Get-SloAllSiteBindings {
    Import-SloWebAdministration
    $list = @()
    foreach ($site in @(Get-ChildItem IIS:\Sites)) {
        foreach ($b in @($site.Bindings.Collection)) {
            $list += [pscustomobject]@{
                site               = $site.Name
                siteState          = $site.State
                protocol           = $b.protocol
                bindingInformation = $b.bindingInformation
                sslFlags           = $(try { $b.sslFlags } catch { $null })
            }
        }
    }
    return $list
}

function Get-SloSiteSummary {
    param([Parameter(Mandatory)][string]$Name)
    Import-SloWebAdministration
    $site = Get-Item -Path "IIS:\Sites\$Name" -ErrorAction SilentlyContinue
    if (-not $site) { return $null }
    return [pscustomobject]@{
        name            = $site.Name
        id              = $site.id
        state           = $site.State
        serverAutoStart = [bool]$site.serverAutoStart
        physicalPath    = $site.physicalPath
        applicationPool = $site.applicationPool
        bindings        = @($site.Bindings.Collection | ForEach-Object { '{0}/{1}' -f $_.protocol, $_.bindingInformation })
    }
}

function Get-SloHttpSysSslBindings {
    <# Parses 'netsh http show sslcert'. Returns objects with key (ipport/hostnameport/ccs) and certificate hash. #>
    $r = Invoke-SloNative -FilePath "$env:windir\System32\netsh.exe" -Arguments @('http', 'show', 'sslcert') -AllowFailure -Quiet
    $list = @()
    $current = $null
    foreach ($line in $r.Output) {
        if ($line -match '^\s*(IP:port|Hostname:port|Central Certificate Store)\s*:\s*(\S+)') {
            if ($current) { $list += $current }
            # Capture before any further -match/switch, which would overwrite $Matches.
            $label = $Matches[1]
            $value = $Matches[2]
            $type = if ($label -like 'IP*') { 'ipport' } elseif ($label -like 'Hostname*') { 'hostnameport' } else { 'ccs' }
            $current = [pscustomobject]@{ type = $type; key = $value; certHash = $null; appId = $null; storeName = $null }
        } elseif ($current -and $line -match '^\s*Certificate Hash\s*:\s*([0-9a-fA-F]+)') {
            $current.certHash = $Matches[1].ToUpperInvariant()
        } elseif ($current -and $line -match '^\s*Application ID\s*:\s*(\{[0-9a-fA-F\-]+\})') {
            $current.appId = $Matches[1]
        } elseif ($current -and $line -match '^\s*Certificate Store Name\s*:\s*(\S+)') {
            $current.storeName = $Matches[1]
        }
    }
    if ($current) { $list += $current }
    return $list
}

function Get-SloFastCgiApps {
    Import-SloWebAdministration
    return @(Get-WebConfiguration -PSPath $script:SloApphost -Filter 'system.webServer/fastCgi/application' | ForEach-Object {
        [pscustomobject]@{ fullPath = $_.fullPath; arguments = $_.arguments }
    })
}

function Get-SloHandlersReferencing {
    <# Every handler (server or any site location) whose scriptProcessor points inside -PathPrefix. #>
    param([Parameter(Mandatory)][string]$PathPrefix)
    Import-SloWebAdministration
    $prefix = $PathPrefix.TrimEnd('\') + '\'
    $hits = @()
    $locations = @('') + @(Get-ChildItem IIS:\Sites | ForEach-Object { $_.Name })
    foreach ($loc in $locations) {
        $handlers = if ($loc -eq '') {
            @(Get-WebConfiguration -PSPath $script:SloApphost -Filter 'system.webServer/handlers/add')
        } else {
            @(Get-WebConfiguration -PSPath $script:SloApphost -Location $loc -Filter 'system.webServer/handlers/add')
        }
        foreach ($h in $handlers) {
            $sp = [string]$h.scriptProcessor
            if ($sp -and ($sp.Split('|')[0]).StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
                $hits += [pscustomobject]@{ location = $loc; name = $h.name; scriptProcessor = $sp }
            }
        }
    }
    return $hits
}

# ---------------------------------------------------------------------------
# HTTPS client pinned to a certificate thumbprint (no global trust change)
# ---------------------------------------------------------------------------

function Initialize-SloPinnedHttp {
    if ('SloPinnedHttp' -as [type]) { return }
    Add-Type -TypeDefinition @'
using System;
using System.IO;
using System.Net;
using System.Net.Security;
using System.Collections.Generic;
using System.Security.Cryptography.X509Certificates;

public static class SloPinnedHttp
{
    public class Result
    {
        public int Status;
        public string Location;
        public string Body;
        public string Error;
        public Dictionary<string, string> Headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
    }

    public static Result Get(string url, string thumbprint, string headerName, string headerValue, int timeoutMs)
    {
        Result r = new Result();
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
        req.AllowAutoRedirect = false;
        req.Timeout = timeoutMs;
        req.ReadWriteTimeout = timeoutMs;
        req.Method = "GET";
        req.UserAgent = "SLOCloud5-Installer";
        req.KeepAlive = false;
        string pin = thumbprint == null ? null : thumbprint.Replace(" ", "").ToUpperInvariant();
        req.ServerCertificateValidationCallback = delegate (object sender, X509Certificate cert, X509Chain chain, SslPolicyErrors errors)
        {
            if (pin == null) { return errors == SslPolicyErrors.None; }
            if (cert == null) { return false; }
            X509Certificate2 c2 = new X509Certificate2(cert);
            return string.Equals(c2.Thumbprint, pin, StringComparison.OrdinalIgnoreCase);
        };
        if (!string.IsNullOrEmpty(headerName)) { req.Headers[headerName] = headerValue; }
        HttpWebResponse resp = null;
        try
        {
            resp = (HttpWebResponse)req.GetResponse();
        }
        catch (WebException ex)
        {
            resp = ex.Response as HttpWebResponse;
            if (resp == null)
            {
                r.Status = 0;
                r.Error = ex.Status.ToString() + ": " + ex.Message;
                return r;
            }
        }
        using (resp)
        {
            r.Status = (int)resp.StatusCode;
            foreach (string k in resp.Headers.AllKeys) { r.Headers[k] = resp.Headers[k]; }
            r.Location = resp.Headers["Location"];
            using (StreamReader sr = new StreamReader(resp.GetResponseStream()))
            {
                string body = sr.ReadToEnd();
                r.Body = body.Length > 65536 ? body.Substring(0, 65536) : body;
            }
        }
        return r;
    }
}
'@
}

function Invoke-SloPinnedGet {
    param([Parameter(Mandatory)][string]$Url, [string]$Thumbprint, [string]$HeaderName = '', [string]$HeaderValue = '', [int]$TimeoutMs = 30000)
    Initialize-SloPinnedHttp
    return [SloPinnedHttp]::Get($Url, $Thumbprint, $HeaderName, $HeaderValue, $TimeoutMs)
}

# ---------------------------------------------------------------------------
# TCP reachability
# ---------------------------------------------------------------------------

function Test-SloTcpPort {
    param([Parameter(Mandatory)][string]$HostName, [Parameter(Mandatory)][int]$Port, [int]$TimeoutMs = 5000)
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($HostName, $Port, $null, $null)
        if (-not $iar.AsyncWaitHandle.WaitOne($TimeoutMs)) {
            return [pscustomobject]@{ reachable = $false; detail = "no TCP response from ${HostName}:$Port within $TimeoutMs ms" }
        }
        $client.EndConnect($iar)
        return [pscustomobject]@{ reachable = $true; detail = ("TCP connection established to {0}" -f $client.Client.RemoteEndPoint) }
    } catch {
        $msg = if ($_.Exception.InnerException) { $_.Exception.InnerException.Message } else { $_.Exception.Message }
        return [pscustomobject]@{ reachable = $false; detail = $msg }
    } finally {
        $client.Close()
    }
}

# ---------------------------------------------------------------------------
# Sources: download + integrity verification
# ---------------------------------------------------------------------------

function Get-SloSourceFile {
    param(
        [Parameter(Mandatory)][string]$Key,
        [Parameter(Mandatory)]$Sources,
        [string]$SourceCache,
        [hashtable]$TrustedHashes = @{},
        [switch]$NonInteractive
    )
    $src = $Sources.sources.$Key
    if (-not $src) { throw "sources.json has no entry '$Key'." }
    $downloads = Join-Path $script:SloStateRoot 'downloads'
    $target = Join-Path $downloads $src.fileName
    $path = $null
    if ($SourceCache) {
        $cached = Join-Path $SourceCache $src.fileName
        if (Test-Path -LiteralPath $cached -PathType Leaf) { $path = $cached; Write-SloLog "using offline source $cached" }
    }
    if (-not $path -and (Test-Path -LiteralPath $target -PathType Leaf)) { $path = $target; Write-SloLog "using previously downloaded $target" }
    if (-not $path) {
        if ($script:SloPlanMode) {
            Write-SloLog ("would download {0} from {1}" -f $src.fileName, ($src.urls -join ' | ')) 'PLAN'
            return $null
        }
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $tmp = "$target.partial"
        $ok = $false
        $errs = @()
        foreach ($url in @($src.urls)) {
            try {
                Write-SloLog "downloading $url"
                $old = $ProgressPreference
                $ProgressPreference = 'SilentlyContinue'
                try { Invoke-WebRequest -Uri $url -OutFile $tmp -UseBasicParsing -MaximumRedirection 10 -ErrorAction Stop } finally { $ProgressPreference = $old }
                $ok = $true
                break
            } catch {
                $errs += "$url -> $($_.Exception.Message)"
            }
        }
        if (-not $ok) { throw ("Could not download {0}:{1}{2}" -f $src.fileName, [Environment]::NewLine, ($errs -join [Environment]::NewLine)) }
        Move-Item -LiteralPath $tmp -Destination $target -Force
        $path = $target
    }

    $hash = Get-SloFileHash $path
    $verifiedBy = $null
    switch ($src.verify) {
        'sha256' {
            if ($hash -ne ([string]$src.sha256).ToUpperInvariant()) {
                if ($path -eq $target) { Remove-Item -LiteralPath $path -Force }
                throw "SHA-256 mismatch for $($src.fileName): expected $($src.sha256), got $hash. The file was rejected."
            }
            $verifiedBy = "sha256 pinned in sources.json"
        }
        'authenticode' {
            $sig = Get-AuthenticodeSignature -LiteralPath $path
            $subject = if ($sig.SignerCertificate) { $sig.SignerCertificate.Subject } else { '' }
            if ($sig.Status -ne 'Valid' -or $subject -notmatch ('O=' + [regex]::Escape($src.signer))) {
                if ($path -eq $target) { Remove-Item -LiteralPath $path -Force }
                throw "Authenticode verification failed for $($src.fileName): status=$($sig.Status) signer='$subject'."
            }
            if ($src.sha256 -and $hash -ne ([string]$src.sha256).ToUpperInvariant()) {
                throw "SHA-256 mismatch for $($src.fileName): expected $($src.sha256), got $hash."
            }
            $verifiedBy = "Authenticode valid, signer $subject"
        }
        'sha256-confirm' {
            $lockPath = Join-Path $script:SloStateRoot 'sources.lock.json'
            $lock = Read-SloJsonFile -Path $lockPath
            if ($null -eq $lock) { $lock = [pscustomobject]@{} }
            $pinned = $null
            if ($lock.PSObject.Properties.Name -contains $src.fileName) { $pinned = $lock.($src.fileName).sha256 }
            if ($pinned) {
                if ($pinned -ne $hash) { throw "SHA-256 for $($src.fileName) ($hash) does not match the value pinned on a previous run ($pinned). The file was rejected." }
                $verifiedBy = "sha256 pinned in sources.lock.json"
            } elseif ($TrustedHashes.ContainsKey($Key)) {
                if (([string]$TrustedHashes[$Key]).ToUpperInvariant() -ne $hash) { throw "SHA-256 for $($src.fileName) ($hash) does not match -TrustedHashes['$Key']." }
                $verifiedBy = "sha256 supplied by operator (-TrustedHashes)"
            } elseif ($script:SloPlanMode) {
                Write-SloLog "would ask the operator to confirm SHA-256 $hash for $($src.fileName) (no published checksum)" 'PLAN'
                return [pscustomobject]@{ key = $Key; path = $path; sha256 = $hash; verifiedBy = 'unconfirmed (plan)'; version = $src.version; fileName = $src.fileName }
            } else {
                if ($NonInteractive) { throw "$($src.fileName) has no published hash. Supply -TrustedHashes @{ $Key = '<sha256>' } after verifying it out of band." }
                Write-Host ''
                Write-Host "$($src.description) has no publisher-supplied checksum." -ForegroundColor Yellow
                Write-Host "  File:    $($src.fileName)"
                Write-Host "  Source:  $($src.urls -join ', ')"
                Write-Host "  SHA-256: $hash" -ForegroundColor Yellow
                Write-Host 'Compare this value with an independent download (for example from another machine) before trusting it.'
                $answer = Read-Host "Type TRUST to pin this hash for future runs, anything else to stop"
                if ($answer -cne 'TRUST') { throw "Operator did not confirm the hash of $($src.fileName)." }
                $verifiedBy = "sha256 confirmed by operator at first use"
            }
            if (-not $script:SloPlanMode) {
                $entry = [pscustomobject]@{ sha256 = $hash; pinnedAt = (Get-Date).ToString('o'); by = $verifiedBy }
                if ($lock.PSObject.Properties.Name -contains $src.fileName) { $lock.($src.fileName) = $entry } else { $lock | Add-Member -NotePropertyName $src.fileName -NotePropertyValue $entry }
                Write-SloJsonFile -Path $lockPath -Object $lock
            }
        }
        default { throw "Unknown verification type '$($src.verify)' for $Key." }
    }
    Write-SloLog ("verified {0}: {1} (sha256 {2})" -f $src.fileName, $verifiedBy, $hash) 'OK'
    return [pscustomobject]@{ key = $Key; path = $path; sha256 = $hash; verifiedBy = $verifiedBy; version = $src.version; fileName = $src.fileName }
}

function Expand-SloZip {
    param([Parameter(Mandatory)][string]$ZipPath, [Parameter(Mandatory)][string]$Destination)
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Recurse -Force }
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    $full = (Resolve-Path -LiteralPath $Destination).ProviderPath.TrimEnd('\') + '\'
    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        foreach ($entry in $zip.Entries) {
            $dest = [System.IO.Path]::GetFullPath((Join-Path $Destination $entry.FullName))
            if (-not $dest.StartsWith($full, [StringComparison]::OrdinalIgnoreCase)) { throw "Archive entry escapes the extraction folder: $($entry.FullName)" }
            if ($entry.FullName.EndsWith('/') -or $entry.FullName.EndsWith('\')) {
                New-Item -ItemType Directory -Path $dest -Force | Out-Null
                continue
            }
            $dir = Split-Path -Parent $dest
            if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $dest, $true)
        }
    } finally {
        $zip.Dispose()
    }
}

# ---------------------------------------------------------------------------
# Revert engine (rollback / uninstall)
# ---------------------------------------------------------------------------

function ConvertTo-SloComparable {
    param($Value)
    if ($null -eq $Value) { return '' }
    if ($Value -is [bool]) { return $Value.ToString().ToLowerInvariant() }
    return ([string]$Value)
}

function Get-SloWebConfigValue {
    param([string]$Location, [Parameter(Mandatory)][string]$Filter, [Parameter(Mandatory)][string]$Name)
    Import-SloWebAdministration
    $splat = @{ PSPath = $script:SloApphost; Filter = $Filter; Name = $Name }
    if ($Location) { $splat.Location = $Location }
    $v = Get-WebConfigurationProperty @splat
    if ($null -ne $v -and $v.PSObject.Properties.Name -contains 'Value') { $v = $v.Value }
    return $v
}

function Set-SloWebConfigValue {
    param([string]$Location, [Parameter(Mandatory)][string]$Filter, [Parameter(Mandatory)][string]$Name, $Value)
    Import-SloWebAdministration
    $splat = @{ PSPath = $script:SloApphost; Filter = $Filter; Name = $Name; Value = $Value }
    if ($Location) { $splat.Location = $Location }
    Set-WebConfigurationProperty @splat
}

function Get-SloIisItemValue {
    <# Reads an IIS: provider item property (app pool / site), normalising ConfigurationAttribute wrappers. #>
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Name)
    Import-SloWebAdministration
    $v = Get-ItemProperty -Path $Path -Name $Name
    if ($null -ne $v -and $v -isnot [string] -and $v.PSObject.Properties.Name -contains 'Value') { $v = $v.Value }
    return $v
}

function Get-SloOverrideMode {
    param([Parameter(Mandatory)][string]$Location, [Parameter(Mandatory)][string]$Section)
    Import-SloWebAdministration
    $cfg = Get-WebConfiguration -PSPath $script:SloApphost -Location $Location -Filter $Section -Metadata
    $mode = $null
    try { $mode = $cfg.Metadata.overrideMode } catch { }
    if (-not $mode) { $mode = 'Inherit' }
    return [string]$mode
}

$script:SloInverseFields = @(
    'type', 'note', 'instructions', 'path', 'retain', 'retainReason', 'recurse', 'requireEmpty', 'guardPhp', 'guardGit',
    'before', 'after', 'existedBefore', 'backup', 'beforeHash', 'afterHash', 'name', 'beforeValue', 'beforeKind', 'afterValue',
    'entry', 'physicalPath', 'site', 'protocol', 'bindingInformation', 'hostnameport', 'thumbprint', 'friendlyName', 'appId',
    'fullPath', 'location', 'filter', 'section', 'beforeState', 'beforeAutoStart', 'afterState', 'afterAutoStart', 'repo',
    'previous', 'dataPath'
)

function Invoke-SloRevert {
    <#
        Evaluates (and unless -Preview, performs) the inverse of one journaled
        operation. Returns: action (remove|restore|retain|manual|skip|conflict),
        detail, and result (previewed|reverted|retained|manual|skipped|conflict|failed).
        Every branch checks current state first, so reruns after a partial
        undo are safe, and resources changed by someone else are left alone.
    #>
    param([Parameter(Mandatory)]$Op, [switch]$Preview, [hashtable]$Context = @{})
    # Journal records only carry the fields their operation needed. Normalise to the full
    # field set so optional fields read as $null under Set-StrictMode.
    $fields = [ordered]@{}
    foreach ($k in $script:SloInverseFields) { $fields[$k] = $null }
    if ($Op.PSObject.Properties.Name -contains 'inverse' -and $Op.inverse) {
        foreach ($prop in $Op.inverse.PSObject.Properties) { $fields[$prop.Name] = $prop.Value }
    }
    foreach ($k in @('SiteName', 'SiteRemoved', 'GitExe', 'ReleasedSslBindings')) { if (-not $Context.ContainsKey($k)) { $Context[$k] = $null } }
    $inv = [pscustomobject]$fields
    $type = if ($inv.type) { [string]$inv.type } else { 'none' }
    $res = [pscustomobject]@{ opId = $Op.id; kind = $Op.kind; resource = $Op.resource; description = $Op.description; action = 'skip'; detail = ''; result = 'skipped' }

    function Done([string]$action, [string]$detail, [scriptblock]$do) {
        $res.action = $action
        $res.detail = $detail
        if ($action -eq 'conflict') { $res.result = 'conflict'; return $res }
        if ($action -eq 'manual') { $res.result = 'manual'; return $res }
        if ($action -eq 'retain') { $res.result = 'retained'; return $res }
        if ($action -eq 'skip') { $res.result = 'skipped'; return $res }
        if ($Preview) { $res.result = 'previewed'; return $res }
        try {
            & $do
            $res.result = 'reverted'
        } catch {
            $res.result = 'failed'
            $res.detail = $detail + ' | error: ' + (Protect-SloText $_.Exception.Message)
        }
        return $res
    }

    switch ($type) {
        'none' {
            $note = if ($inv.note) { [string]$inv.note } else { 'informational; nothing to reverse' }
            return (Done 'skip' $note {})
        }
        'manual' {
            return (Done 'manual' ([string]$inv.instructions) {})
        }
        'dir.remove' {
            $path = [string]$inv.path
            if (-not (Test-Path -LiteralPath $path)) { return (Done 'skip' "$path no longer exists" {}) }
            if ($inv.retain) { return (Done 'retain' "$path is kept ($($inv.retainReason))" {}) }
            if ($inv.guardPhp) {
                Import-SloWebAdministration
                $others = @(Get-SloHandlersReferencing -PathPrefix $path | Where-Object { $_.location -ne [string]$Context.SiteName -or -not $Context.SiteRemoved })
                $fcgi = @(Get-SloFastCgiApps | Where-Object { $_.fullPath.StartsWith($path.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) })
                if ($others.Count -gt 0) { return (Done 'conflict' ("PHP at $path is still referenced by handler(s): " + (($others | ForEach-Object { "$($_.location)/$($_.name)" }) -join ', ')) {}) }
                if ($fcgi.Count -gt 0) { return (Done 'conflict' ("PHP at $path is still registered as a FastCGI application: " + (($fcgi | ForEach-Object { $_.fullPath }) -join ', ')) {}) }
            }
            if ($inv.guardGit -and (Test-Path -LiteralPath (Join-Path $path '.git'))) {
                $git = [string]$Context.GitExe
                if ($git -and (Test-Path -LiteralPath $git)) {
                    $st = Invoke-SloNative -FilePath $git -Arguments @('-c', 'safe.directory=*', '-C', $path, 'status', '--porcelain') -AllowFailure -Quiet
                    if ($st.ExitCode -eq 0 -and $st.Text.Trim() -ne '') { return (Done 'conflict' "repository $path has local changes; remove it manually after review" {}) }
                }
            }
            if ($inv.requireEmpty) {
                if ($Preview) { return (Done 'remove' "remove $path if it is empty once the steps above have run (otherwise kept)" {}) }
                $children = @(Get-ChildItem -LiteralPath $path -Force -ErrorAction SilentlyContinue)
                if ($children.Count -gt 0) { return (Done 'retain' "$path is not empty ($($children.Count) item(s), e.g. $($children[0].Name)); kept" {}) }
                return (Done 'remove' "remove empty directory $path" { Remove-Item -LiteralPath $path -Force })
            }
            return (Done 'remove' "remove directory tree $path" { Remove-Item -LiteralPath $path -Recurse -Force })
        }
        'acl.restore' {
            $path = [string]$inv.path
            if (-not (Test-Path -LiteralPath $path)) { return (Done 'skip' "$path no longer exists" {}) }
            $cur = Get-SloAccessSddl -Path $path
            if ($cur -eq [string]$inv.before) { return (Done 'skip' "ACL on $path already matches its original" {}) }
            if ($cur -ne [string]$inv.after) { return (Done 'conflict' "ACL on $path was changed after the installer set it; restore manually (original DACL is in the journal and the icacls backup)" {}) }
            $sddl = [string]$inv.before
            return (Done 'restore' "restore original ACL on $path" { Set-SloAccessSddl -Path $path -Sddl $sddl })
        }
        'file.restore' {
            $path = [string]$inv.path
            $exists = Test-Path -LiteralPath $path -PathType Leaf
            $curHash = if ($exists) { Get-SloFileHash $path } else { $null }
            if ($inv.existedBefore) {
                if ($curHash -and $curHash -eq [string]$inv.beforeHash) { return (Done 'skip' "$path already has its original content" {}) }
                if ($curHash -ne [string]$inv.afterHash) { return (Done 'conflict' "$path changed after the installer wrote it; original is at $($inv.backup)" {}) }
                $backup = [string]$inv.backup
                if (-not (Test-Path -LiteralPath $backup)) { return (Done 'conflict' "backup $backup is missing" {}) }
                return (Done 'restore' "restore $path from backup" { Copy-Item -LiteralPath $backup -Destination $path -Force })
            }
            if (-not $exists) { return (Done 'skip' "$path no longer exists" {}) }
            if ($curHash -ne [string]$inv.afterHash) { return (Done 'conflict' "$path changed after the installer created it; kept" {}) }
            return (Done 'remove' "remove installer-created file $path" { Remove-Item -LiteralPath $path -Force })
        }
        'env.restore' {
            $name = [string]$inv.name
            $cur = Get-SloMachineEnv -Name $name
            $curVal = if ($cur.exists) { $cur.value } else { $null }
            $beforeVal = if ($inv.existedBefore) { [string]$inv.beforeValue } else { $null }
            if ($curVal -eq $beforeVal) { return (Done 'skip' "machine variable $name already at its original value" {}) }
            if ($curVal -ne [string]$inv.afterValue) { return (Done 'conflict' "machine variable $name changed after the installer set it (now '$curVal')" {}) }
            if ($inv.existedBefore) {
                $kind = if ($inv.beforeKind) { [string]$inv.beforeKind } else { 'String' }
                return (Done 'restore' "restore machine variable $name to its original value" { Set-SloMachineEnvRaw -Name $name -Value $beforeVal -Kind $kind })
            }
            return (Done 'remove' "remove machine variable $name" { Set-SloMachineEnvRaw -Name $name -Value $null })
        }
        'path.removeEntry' {
            $entry = [string]$inv.entry
            $cur = Get-SloMachineEnv -Name 'Path'
            if (-not $cur.exists -or -not (Test-SloPathEntry -PathValue $cur.value -Entry $entry)) { return (Done 'skip' "machine PATH no longer contains $entry" {}) }
            return (Done 'remove' "remove $entry from machine PATH (other entries untouched)" { [void](Remove-SloMachinePathEntry -Entry $entry) })
        }
        'iis.apppool.remove' {
            Import-SloWebAdministration
            $name = [string]$inv.name
            if (-not (Test-Path -Path "IIS:\AppPools\$name")) { return (Done 'skip' "application pool $name no longer exists" {}) }
            $users = @(Get-ChildItem IIS:\Sites | Where-Object { $_.applicationPool -eq $name -and -not ($Context.SiteRemoved -and $_.Name -eq [string]$Context.SiteName) })
            $apps = @(Get-WebApplication | Where-Object { $_.applicationPool -eq $name -and -not ($Context.SiteRemoved -and [string]$_.ItemXPath -like ("*@name='" + [string]$Context.SiteName + "'*")) })
            if ($users.Count -gt 0 -or $apps.Count -gt 0) {
                return (Done 'conflict' ("application pool $name is still used by: " + ((@($users | ForEach-Object { $_.Name }) + @($apps | ForEach-Object { $_.path })) -join ', ')) {})
            }
            return (Done 'remove' "remove application pool $name" { Remove-WebAppPool -Name $name })
        }
        'iis.site.remove' {
            Import-SloWebAdministration
            $name = [string]$inv.name
            $site = Get-SloSiteSummary -Name $name
            if (-not $site) { $Context.SiteRemoved = $true; return (Done 'skip' "site $name no longer exists" {}) }
            if ($site.physicalPath -ne [string]$inv.physicalPath) { return (Done 'conflict' "site $name now points at $($site.physicalPath); remove manually" {}) }
            $r = Done 'remove' "remove IIS site $name" { Remove-Website -Name $name }
            if ($r.result -eq 'reverted' -or $r.result -eq 'previewed') { $Context.SiteRemoved = $true }
            return $r
        }
        'iis.binding.remove' {
            Import-SloWebAdministration
            $site = Get-SloSiteSummary -Name ([string]$inv.site)
            $key = '{0}/{1}' -f $inv.protocol, $inv.bindingInformation
            if (-not $site -or @($site.bindings) -notcontains $key) { return (Done 'skip' "binding $key no longer present" {}) }
            $parts = ([string]$inv.bindingInformation).Split(':')
            $s = [string]$inv.site; $proto = [string]$inv.protocol
            return (Done 'remove' "remove binding $key from $s" { Remove-WebBinding -Name $s -Protocol $proto -IPAddress $parts[0] -Port $parts[1] -HostHeader $parts[2] })
        }
        'iis.sslcert.remove' {
            $hp = [string]$inv.hostnameport
            $entry = @(Get-SloHttpSysSslBindings | Where-Object { $_.type -eq 'hostnameport' -and $_.key -ieq $hp })
            if ($entry.Count -eq 0) { return (Done 'skip' "no HTTP.sys certificate binding for $hp" {}) }
            if ($entry[0].certHash -ne ([string]$inv.thumbprint).ToUpperInvariant()) { return (Done 'conflict' "HTTP.sys binding $hp now uses certificate $($entry[0].certHash); left in place" {}) }
            $r = Done 'remove' "remove HTTP.sys certificate binding $hp" { Invoke-SloNative -FilePath "$env:windir\System32\netsh.exe" -Arguments @('http', 'delete', 'sslcert', "hostnameport=$hp") | Out-Null }
            if ($r.result -eq 'previewed' -or $r.result -eq 'reverted') { $Context.ReleasedSslBindings = @($Context.ReleasedSslBindings) + $hp }
            return $r
        }
        'cert.remove' {
            $thumb = ([string]$inv.thumbprint).ToUpperInvariant()
            if (-not $thumb -and $inv.friendlyName) {
                # The run stopped between creating the certificate and recording its thumbprint.
                $found = @(Get-ChildItem Cert:\LocalMachine\My | Where-Object { $_.FriendlyName -eq [string]$inv.friendlyName })
                if ($found.Count -eq 1) { $thumb = $found[0].Thumbprint.ToUpperInvariant() }
            }
            if (-not $thumb) { return (Done 'skip' "no certificate recorded for this operation" {}) }
            $certPath = "Cert:\LocalMachine\My\$thumb"
            if (-not (Test-Path -LiteralPath $certPath)) { return (Done 'skip' "certificate $thumb no longer in LocalMachine\My" {}) }
            $released = @($Context.ReleasedSslBindings)
            $inUse = @(Get-SloHttpSysSslBindings | Where-Object { $_.certHash -eq $thumb -and $released -notcontains $_.key })
            if ($inUse.Count -gt 0) { return (Done 'conflict' ("certificate $thumb is still bound: " + (($inUse | ForEach-Object { $_.key }) -join ', ')) {}) }
            return (Done 'remove' "delete self-signed certificate $thumb" { Remove-Item -LiteralPath $certPath -Force })
        }
        'iis.sslcert.rebind' {
            $hp = [string]$inv.hostnameport
            $entry = @(Get-SloHttpSysSslBindings | Where-Object { $_.type -eq 'hostnameport' -and $_.key -ieq $hp })
            $cur = if ($entry.Count -gt 0) { $entry[0].certHash } else { $null }
            $beforeThumb = ([string]$inv.before).ToUpperInvariant()
            if ($cur -eq $beforeThumb) { return (Done 'skip' "HTTP.sys binding $hp already uses the earlier certificate" {}) }
            if ($cur -ne ([string]$inv.after).ToUpperInvariant()) { return (Done 'conflict' "HTTP.sys binding $hp changed after install" {}) }
            if (-not (Test-Path -LiteralPath "Cert:\LocalMachine\My\$beforeThumb")) { return (Done 'conflict' "earlier certificate $beforeThumb is no longer in the store" {}) }
            $appId = [string]$inv.appId
            return (Done 'restore' "rebind $hp to the earlier certificate $beforeThumb" {
                Invoke-SloNative -FilePath "$env:windir\System32\netsh.exe" -Arguments @('http', 'delete', 'sslcert', "hostnameport=$hp") | Out-Null
                Invoke-SloNative -FilePath "$env:windir\System32\netsh.exe" -Arguments @('http', 'add', 'sslcert', "hostnameport=$hp", "certhash=$beforeThumb", "appid=$appId", 'certstorename=MY') | Out-Null
            })
        }
        'iis.fastcgi.remove' {
            Import-SloWebAdministration
            $fp = [string]$inv.fullPath
            $exists = @(Get-SloFastCgiApps | Where-Object { $_.fullPath -ieq $fp })
            if ($exists.Count -eq 0) { return (Done 'skip' "FastCGI application $fp no longer registered" {}) }
            $refs = @(Get-SloHandlersReferencing -PathPrefix (Split-Path -Parent $fp) | Where-Object { -not ($_.location -eq [string]$Context.SiteName -and $Context.SiteRemoved) })
            if ($refs.Count -gt 0) { return (Done 'conflict' ("FastCGI application $fp is still used by handler(s): " + (($refs | ForEach-Object { "$($_.location)/$($_.name)" }) -join ', ')) {}) }
            return (Done 'remove' "unregister FastCGI application $fp" {
                Remove-WebConfigurationProperty -PSPath (Get-SloApphost) -Filter 'system.webServer/fastCgi' -Name '.' -AtElement @{ fullPath = $fp }
            })
        }
        'iis.fastcgi.envRemove' {
            Import-SloWebAdministration
            $fp = [string]$inv.fullPath; $n = [string]$inv.name
            $f = "system.webServer/fastCgi/application[@fullPath='$fp']/environmentVariables/environmentVariable[@name='$n']"
            $cur = Get-WebConfiguration -PSPath (Get-SloApphost) -Filter $f
            if (-not $cur) { return (Done 'skip' "FastCGI variable $n not present" {}) }
            if ($inv.existedBefore) {
                if ([string]$cur.value -eq [string]$inv.beforeValue) { return (Done 'skip' "FastCGI variable $n already original" {}) }
                if ([string]$cur.value -ne [string]$inv.afterValue) { return (Done 'conflict' "FastCGI variable $n changed after install" {}) }
                $bv = [string]$inv.beforeValue
                return (Done 'restore' "restore FastCGI variable $n" { Set-WebConfigurationProperty -PSPath (Get-SloApphost) -Filter $f -Name value -Value $bv })
            }
            if ([string]$cur.value -ne [string]$inv.afterValue) { return (Done 'conflict' "FastCGI variable $n changed after install" {}) }
            return (Done 'remove' "remove FastCGI variable $n" { Remove-WebConfigurationProperty -PSPath (Get-SloApphost) -Filter "system.webServer/fastCgi/application[@fullPath='$fp']/environmentVariables" -Name '.' -AtElement @{ name = $n } })
        }
        'iis.handler.remove' {
            Import-SloWebAdministration
            $loc = [string]$inv.location; $n = [string]$inv.name
            if (-not (Get-SloSiteSummary -Name $loc)) { return (Done 'skip' "site $loc no longer exists; handler went with it" {}) }
            $h = @(Get-WebConfiguration -PSPath (Get-SloApphost) -Location $loc -Filter "system.webServer/handlers/add[@name='$n']")
            if ($h.Count -eq 0) { return (Done 'skip' "handler $n not present at $loc" {}) }
            return (Done 'remove' "remove handler $n from $loc" { Remove-WebConfigurationProperty -PSPath (Get-SloApphost) -Location $loc -Filter 'system.webServer/handlers' -Name '.' -AtElement @{ name = $n } })
        }
        'iis.config.restore' {
            Import-SloWebAdministration
            $loc = [string]$inv.location
            if ($loc -and -not (Get-SloSiteSummary -Name ($loc.Split('/')[0]))) { return (Done 'skip' "site $loc no longer exists; setting went with it" {}) }
            $cur = ConvertTo-SloComparable (Get-SloWebConfigValue -Location $loc -Filter ([string]$inv.filter) -Name ([string]$inv.name))
            if ($cur -eq (ConvertTo-SloComparable $inv.before)) { return (Done 'skip' "$($inv.filter)/@$($inv.name) already original" {}) }
            if ($cur -ne (ConvertTo-SloComparable $inv.after)) { return (Done 'conflict' "$($inv.filter)/@$($inv.name) at '$loc' changed after install (now '$cur')" {}) }
            $f = [string]$inv.filter; $n = [string]$inv.name; $bv = $inv.before
            return (Done 'restore' "restore $f/@$n at '$loc' to '$bv'" { Set-SloWebConfigValue -Location $loc -Filter $f -Name $n -Value $bv })
        }
        'iis.item.restore' {
            Import-SloWebAdministration
            $p = [string]$inv.path; $n = [string]$inv.name
            if (-not (Test-Path -Path $p)) { return (Done 'skip' "$p no longer exists" {}) }
            $cur = ConvertTo-SloComparable (Get-SloIisItemValue -Path $p -Name $n)
            if ($cur -eq (ConvertTo-SloComparable $inv.before)) { return (Done 'skip' "$p $n already original" {}) }
            if ($cur -ne (ConvertTo-SloComparable $inv.after)) { return (Done 'conflict' "$p $n changed after install (now '$cur')" {}) }
            $bv = $inv.before
            return (Done 'restore' "restore $p $n to '$bv'" { Set-ItemProperty -Path $p -Name $n -Value $bv })
        }
        'iis.override.restore' {
            Import-SloWebAdministration
            $loc = [string]$inv.location; $sec = [string]$inv.section
            if (-not (Get-SloSiteSummary -Name $loc)) { return (Done 'skip' "site $loc no longer exists; unlock went with it" {}) }
            $cur = Get-SloOverrideMode -Location $loc -Section $sec
            if ($cur -eq [string]$inv.before) { return (Done 'skip' "$sec override mode already original" {}) }
            if ($cur -ne [string]$inv.after) { return (Done 'conflict' "$sec override mode at $loc changed after install" {}) }
            $bv = [string]$inv.before
            return (Done 'restore' "restore $sec override mode at $loc to $bv" { Set-WebConfiguration -PSPath (Get-SloApphost) -Location $loc -Filter $sec -Metadata overrideMode -Value $bv })
        }
        'iis.siteState.restore' {
            Import-SloWebAdministration
            $name = [string]$inv.name
            $site = Get-SloSiteSummary -Name $name
            if (-not $site) { return (Done 'conflict' "site $name no longer exists; cannot restore its state" {}) }
            if ($site.state -eq [string]$inv.beforeState -and $site.serverAutoStart -eq [bool]$inv.beforeAutoStart) { return (Done 'skip' "site $name already in its original state" {}) }
            if ($site.state -ne [string]$inv.afterState -or $site.serverAutoStart -ne [bool]$inv.afterAutoStart) { return (Done 'conflict' "site $name state changed after install (now $($site.state), autostart $($site.serverAutoStart))" {}) }
            $bs = [string]$inv.beforeState; $ba = [bool]$inv.beforeAutoStart
            return (Done 'restore' "restore site $name to $bs (autostart $ba)" {
                Set-ItemProperty -Path "IIS:\Sites\$name" -Name serverAutoStart -Value $ba
                if ($bs -eq 'Started') { Start-Website -Name $name }
            })
        }
        'git.restore' {
            $repo = [string]$inv.repo
            $git = [string]$Context.GitExe
            if (-not (Test-Path -LiteralPath (Join-Path $repo '.git'))) { return (Done 'skip' "$repo is no longer a git repository" {}) }
            if (-not $git -or -not (Test-Path -LiteralPath $git)) { return (Done 'manual' "git.exe not available; check out $($inv.before) in $repo manually" {}) }
            $head = (Invoke-SloNative -FilePath $git -Arguments @('-c', 'safe.directory=*', '-C', $repo, 'rev-parse', 'HEAD') -Quiet).Text.Trim()
            if (-not $inv.before) { return (Done 'skip' "repository was created by this operation; removed with its directory" {}) }
            if ($head -eq [string]$inv.before) { return (Done 'skip' "repository already at $($inv.before)" {}) }
            if ($head -ne [string]$inv.after) { return (Done 'conflict' "repository HEAD is $head, not the installed $($inv.after)" {}) }
            $st = Invoke-SloNative -FilePath $git -Arguments @('-c', 'safe.directory=*', '-C', $repo, 'status', '--porcelain') -Quiet
            if ($st.Text.Trim() -ne '') { return (Done 'conflict' "repository $repo has local changes" {}) }
            $b = [string]$inv.before
            return (Done 'restore' "check out previous commit $b in $repo" { Invoke-SloNative -FilePath $git -Arguments @('-c', 'safe.directory=*', '-C', $repo, 'checkout', '--detach', '--quiet', $b) | Out-Null })
        }
        'php.restore' {
            $path = [string]$inv.path
            $prev = [string]$inv.previous
            $marker = Join-Path $path 'php-cgi.exe'
            if (-not (Test-Path -LiteralPath $prev)) { return (Done 'conflict' "backup of the previous PHP ($prev) is missing" {}) }
            if ((Get-SloFileHash $marker) -ne [string]$inv.afterHash) { return (Done 'conflict' "PHP at $path changed after install" {}) }
            return (Done 'restore' "restore previous PHP runtime into $path" {
                Remove-Item -LiteralPath $path -Recurse -Force
                Copy-Item -LiteralPath $prev -Destination $path -Recurse -Force
            })
        }
        'deploy.revert' {
            $data = Read-SloJsonFile -Path ([string]$inv.dataPath)
            if ($null -eq $data) { return (Done 'conflict' "deployment record $($inv.dataPath) is missing" {}) }
            $web = [string]$data.web
            $conflicts = @(); $toDelete = @(); $toRestore = @()
            foreach ($f in @($data.added)) {
                $p = Join-Path $web $f.rel
                if (-not (Test-Path -LiteralPath $p -PathType Leaf)) { continue }
                if ((Get-SloFileHash $p) -ne $f.hash) { $conflicts += $f.rel } else { $toDelete += $p }
            }
            foreach ($f in @($data.replaced)) {
                $p = Join-Path $web $f.rel
                $cur = Get-SloFileHash $p
                if ($cur -eq $f.beforeHash) { continue }
                if ($cur -ne $f.hash) { $conflicts += $f.rel } else { $toRestore += [pscustomobject]@{ path = $p; backup = $f.backup } }
            }
            foreach ($f in @($data.deleted)) {
                $p = Join-Path $web $f.rel
                if (Test-Path -LiteralPath $p) { if ((Get-SloFileHash $p) -ne $f.beforeHash) { $conflicts += $f.rel }; continue }
                $toRestore += [pscustomobject]@{ path = $p; backup = $f.backup }
            }
            if ($toDelete.Count -eq 0 -and $toRestore.Count -eq 0) {
                if ($conflicts.Count -gt 0) { return (Done 'conflict' ("{0} deployed file(s) were changed after deployment and are kept: {1}" -f $conflicts.Count, (($conflicts | Select-Object -First 10) -join ', ')) {}) }
                return (Done 'skip' 'deployed files already reverted' {})
            }
            $detail = "web deployment: delete $($toDelete.Count) added file(s), restore $($toRestore.Count) earlier file(s)"
            if ($conflicts.Count -gt 0) { $detail += ("; {0} changed file(s) kept: {1}" -f $conflicts.Count, (($conflicts | Select-Object -First 10) -join ', ')) }
            $dirs = @($data.createdDirs)
            $r = Done 'restore' $detail {
                foreach ($p in $toDelete) { Remove-Item -LiteralPath $p -Force }
                foreach ($x in $toRestore) {
                    $d = Split-Path -Parent $x.path
                    if (-not (Test-Path -LiteralPath $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null }
                    Copy-Item -LiteralPath $x.backup -Destination $x.path -Force
                }
                foreach ($rel in ($dirs | Sort-Object { $_.Length } -Descending)) {
                    $d = Join-Path $web $rel
                    if ((Test-Path -LiteralPath $d) -and @(Get-ChildItem -LiteralPath $d -Force).Count -eq 0) { Remove-Item -LiteralPath $d -Force }
                }
            }
            if ($conflicts.Count -gt 0 -and $r.result -eq 'reverted') { $r.result = 'partial' }
            return $r
        }
        default {
            return (Done 'manual' "unknown inverse type '$type'; review manually" {})
        }
    }
}

Export-ModuleMember -Function *-Slo*
