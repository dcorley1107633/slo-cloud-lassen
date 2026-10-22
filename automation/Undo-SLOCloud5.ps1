#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Rolls back an SLO Cloud 5 installer run, or uninstalls SLO Cloud 5, from the change journal.

.DESCRIPTION
    Modes:
      ListRuns        Show installer runs and how many of their changes are still in effect.
      RollbackRun     Reverse the changes recorded for one run (-RunId), newest first.
      Uninstall       Reverse every installer change from every run, newest first.
      ArchiveJournal  Zip the journal and backups. Removing them afterwards needs -RemoveJournalAfterArchive.

    Before RollbackRun or Uninstall the script always prints a dry-run preview
    (remove / restore / retain / cannot be reversed automatically / conflicts)
    and asks for typed confirmation. -PreviewOnly stops after the preview.

    Safety rules:
      - Each change is reversed only if the resource is still exactly as the
        installer left it. Anything changed afterwards (by an administrator,
        another installer or a later run) is reported as a conflict and left alone.
      - Preexisting resources are restored to their recorded prior value, never deleted.
      - Installer-created resources are removed only when nothing else uses them
        (application pool, FastCGI entry, PHP folder, certificate).
      - Always preserved: databases, the secrets folder and its contents, the
        data and backup folders, web\config (config.yaml), and preexisting directories.
        Secret files are never read or exported.
      - Windows features and shared packages (VC++ runtime, URL Rewrite, ODBC
        driver) are listed as manual; they are never uninstalled automatically.
      - Resumable: rerunning after a partial failure continues where it stopped.

    A script cannot prove complete environment-level restoration. Use a VM
    snapshot or full backup when you need a guaranteed return to a prior state.
#>
[CmdletBinding()]
param(
    [ValidateSet('ListRuns', 'RollbackRun', 'Uninstall', 'ArchiveJournal')][string]$Mode,
    [string]$RunId,
    [switch]$PreviewOnly,
    [switch]$Yes,
    [switch]$RemoveJournalAfterArchive
)

# Version 1: catches unset variables. Version 2+ would also throw on reading optional
# properties of journal records and $null results, which these scripts do deliberately.
Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSEdition -ne 'Desktop') { throw 'Run in Windows PowerShell 5.1 (powershell.exe).' }
if (-not [Environment]::Is64BitProcess) { throw 'Run in 64-bit Windows PowerShell.' }

$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Import-Module (Join-Path $ScriptRoot 'lib\SLOCloud5.Common.psm1') -Force -DisableNameChecking

function Get-LiveOps($Run) {
    return @(@($Run.operations) | Where-Object { @('applied', 'failed', 'pending') -contains $_.status -and -not $_.revertedBy })
}

function Show-Runs($Journal) {
    $rows = foreach ($r in @($Journal.runs)) {
        $ops = @($r.operations)
        [pscustomobject]@{
            RunId    = $r.runId
            Kind     = $r.kind
            Mode     = $r.mode
            Status   = $r.status
            Started  = $r.startedAt
            Changes  = @($ops | Where-Object { $_.status -ne 'skipped' }).Count
            Live     = @(Get-LiveOps $r).Count
            Reverted = @($ops | Where-Object { $_.revertedBy }).Count
        }
    }
    $rows | Format-Table -AutoSize | Out-String -Width 200 | Write-Host
}

function Get-GitExe($InstallRoot) {
    $cmd = Get-Command git.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    if ($InstallRoot) {
        $m = Join-Path $InstallRoot 'tools\mingit\cmd\git.exe'
        if (Test-Path -LiteralPath $m) { return $m }
    }
    return $null
}

Initialize-SloStateRoot
Enter-SloLock
$exit = 0
try {
    $journal = Read-SloJournal
    $installRuns = @(@($journal.runs) | Where-Object { @('install', 'finalize') -contains $_.kind } | Sort-Object startedAt)
    if ($installRuns.Count -eq 0) { Write-Host "No installer runs recorded in $(Get-SloJournalPath)."; return }

    if (-not $Mode) {
        Show-Runs $journal
        Write-Host '1) RollbackRun   2) Uninstall   3) ListRuns   4) ArchiveJournal'
        $choice = Read-Host 'Choose an option'
        $Mode = switch ($choice) { '1' { 'RollbackRun' } '2' { 'Uninstall' } '3' { 'ListRuns' } '4' { 'ArchiveJournal' } default { throw 'No option chosen.' } }
    }

    if ($Mode -eq 'ListRuns') { Show-Runs $journal; return }

    if ($Mode -eq 'ArchiveJournal') {
        $archiveDir = Join-Path $env:ProgramData 'SLOCloud5\archive'
        if (-not (Test-Path -LiteralPath $archiveDir)) { New-Item -ItemType Directory -Path $archiveDir -Force | Out-Null }
        # The archive holds the journal and configuration backups: same admin-only ACL as the state folder.
        Set-SloAdminOnlyAcl -Path $archiveDir
        $zip = Join-Path $archiveDir ('installer-journal-{0}.zip' -f (Get-Date).ToString('yyyyMMdd-HHmmss'))
        if (-not $Yes -and (Read-Host "Archive $(Get-SloStateRoot) to $zip? Type ARCHIVE") -cne 'ARCHIVE') { throw 'Not confirmed.' }
        Exit-SloLock
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [System.IO.Compression.ZipFile]::CreateFromDirectory((Get-SloStateRoot), $zip)
        Write-Host "Archived to $zip" -ForegroundColor Green
        if ($RemoveJournalAfterArchive) {
            $live = @($installRuns | ForEach-Object { Get-LiveOps $_ } | Where-Object { $_.inverse.type -ne 'none' }).Count
            if ($live -gt 0) { Write-Host "$live change(s) are still in effect; without the journal they cannot be undone automatically." -ForegroundColor Yellow }
            if ((Read-Host "Delete $(Get-SloStateRoot) now that it is archived? Type DELETE") -ceq 'DELETE') {
                Remove-Item -LiteralPath (Get-SloStateRoot) -Recurse -Force
                Write-Host 'Journal and backups removed. The archive is the only copy.' -ForegroundColor Yellow
            }
        }
        return
    }

    $lastParams = $installRuns[-1].parameters
    $installRoot = if ($lastParams -and $lastParams.PSObject.Properties.Name -contains 'InstallRoot') { [string]$lastParams.InstallRoot } else { $null }
    $siteName = if ($lastParams -and $lastParams.PSObject.Properties.Name -contains 'SiteName') { [string]$lastParams.SiteName } else { 'SLOCloud5' }

    if ($Mode -eq 'RollbackRun') {
        if (-not $RunId) {
            Show-Runs $journal
            $candidates = @($installRuns | Where-Object { @(Get-LiveOps $_).Count -gt 0 })
            $default = if ($candidates.Count -gt 0) { $candidates[-1].runId } else { '' }
            $RunId = Read-Host "Run id to roll back [$default]"
            if (-not $RunId) { $RunId = $default }
        }
        $target = @($installRuns | Where-Object { $_.runId -eq $RunId })
        if ($target.Count -ne 1) { throw "Run '$RunId' not found among installer runs." }
        $target = $target[0]
        $ops = @(Get-LiveOps $target | Sort-Object seq -Descending)
        $later = @($installRuns | Where-Object { $_.startedAt -gt $target.startedAt })
        $laterResources = @{}
        foreach ($lr in $later) { foreach ($lo in (Get-LiveOps $lr)) { if ($lo.inverse.type -ne 'none') { $laterResources[$lo.resource] = $lr.runId } } }
        $title = "Rollback of run $RunId ($($target.kind), started $($target.startedAt))"
    } else {
        $ops = @()
        foreach ($r in ($installRuns | Sort-Object startedAt -Descending)) { $ops += @(Get-LiveOps $r | Sort-Object seq -Descending) }
        $laterResources = @{}
        $title = 'Uninstall SLO Cloud 5 (all installer runs)'
    }
    if ($ops.Count -eq 0) { Write-Host 'Nothing to undo: every recorded change has already been reversed.' -ForegroundColor Green; return }

    try { Import-SloWebAdministration } catch { Write-Host "WebAdministration unavailable: $($_.Exception.Message)" -ForegroundColor Yellow }
    $context = @{ SiteName = $siteName; SiteRemoved = $false; GitExe = (Get-GitExe $installRoot); ReleasedSslBindings = @() }

    # ---------------- Preview ----------------
    $preview = @()
    foreach ($op in $ops) {
        if ($laterResources.ContainsKey($op.resource)) {
            $preview += [pscustomobject]@{ opId = $op.id; kind = $op.kind; resource = $op.resource; description = $op.description; action = 'conflict'; detail = "changed again by later run $($laterResources[$op.resource]); roll that run back first"; result = 'conflict' }
            continue
        }
        $preview += (Invoke-SloRevert -Op $op -Preview -Context $context)
    }
    $groups = [ordered]@{
        'WILL BE REMOVED (installer-created)' = 'remove'
        'WILL BE RESTORED (previous configuration)' = 'restore'
        'WILL BE RETAINED' = 'retain'
        'CANNOT BE REVERSED AUTOMATICALLY (manual)' = 'manual'
        'CONFLICTS (changed after install; left alone)' = 'conflict'
        'ALREADY IN ORIGINAL STATE / NOTHING TO DO' = 'skip'
    }
    Write-Host ''
    Write-Host "==================== $title - preview ====================" -ForegroundColor Cyan
    foreach ($g in $groups.Keys) {
        $items = @($preview | Where-Object { $_.action -eq $groups[$g] })
        if ($items.Count -eq 0) { continue }
        $color = switch ($groups[$g]) { 'conflict' { 'Red' } 'manual' { 'Yellow' } 'retain' { 'Green' } default { 'Gray' } }
        Write-Host ''
        Write-Host "$g ($($items.Count))" -ForegroundColor $color
        foreach ($i in $items) { Write-Host ("  - {0}" -f $i.detail) -ForegroundColor $color }
    }
    Write-Host ''
    Write-Host 'Always preserved: SQL databases and logins, the secrets folder and its contents, the data and backup folders,' -ForegroundColor Green
    Write-Host 'web\config (config.yaml and wizard output), preexisting directories, and this journal with its backups.' -ForegroundColor Green
    Write-Host 'Not reversible by a script: Windows feature installs, shared package installs/upgrades, database changes made' -ForegroundColor Yellow
    Write-Host 'by the setup wizard. Restore a VM snapshot or full backup if you need a guaranteed earlier state.' -ForegroundColor Yellow

    if ($PreviewOnly) { return }
    $token = if ($Mode -eq 'Uninstall') { 'UNINSTALL' } else { "ROLLBACK $RunId" }
    if (-not $Yes) {
        if ((Read-Host "Type '$token' to proceed") -cne $token) { Write-Host 'Not confirmed. Nothing was changed.'; return }
    }

    # ---------------- Execute ----------------
    $undoRun = Start-SloRun -Kind 'undo' -Mode $Mode -Parameters @{ Mode = $Mode; RunId = $RunId }
    $appcmd = "$env:windir\System32\inetsrv\appcmd.exe"
    if (Test-Path -LiteralPath $appcmd) {
        $bk = "SLOCloud5-undo-$($undoRun.runId)"
        $r = Invoke-SloNative -FilePath $appcmd -Arguments @('add', 'backup', $bk) -AllowFailure
        Write-SloLog "IIS configuration backup before undo: $bk (exit $($r.ExitCode))"
        Backup-SloFile -Path "$env:windir\System32\inetsrv\config\applicationHost.config" -Name 'iis\applicationHost.before-undo.config' | Out-Null
    }
    $context = @{ SiteName = $siteName; SiteRemoved = $false; GitExe = (Get-GitExe $installRoot); ReleasedSslBindings = @() }
    $outcome = @()
    $failed = $false
    foreach ($op in $ops) {
        if ($laterResources.ContainsKey($op.resource)) {
            $res = [pscustomobject]@{ opId = $op.id; kind = $op.kind; resource = $op.resource; description = $op.description; action = 'conflict'; detail = "changed again by later run $($laterResources[$op.resource])"; result = 'conflict' }
        } else {
            $res = Invoke-SloRevert -Op $op -Context $context
        }
        $outcome += $res
        $level = switch ($res.result) { 'reverted' { 'OK' } 'skipped' { 'INFO' } 'retained' { 'INFO' } 'manual' { 'IT' } 'partial' { 'WARN' } 'conflict' { 'WARN' } default { 'ERROR' } }
        Write-SloLog ("[{0}] {1}" -f $res.result, $res.detail) $level
        $undoRun.operations = @($undoRun.operations) + [pscustomobject]@{
            id = ('{0}-{1:D4}' -f $undoRun.runId, (@($undoRun.operations).Count + 1)); seq = (@($undoRun.operations).Count + 1); runId = $undoRun.runId
            timestamp = (Get-Date).ToString('o'); kind = 'revert'; resource = $op.resource; ownership = $op.ownership
            description = "revert $($op.id): $($op.description)"; before = $null; after = $null; backup = $null; backupHash = $null
            inverse = [pscustomobject]@{ type = 'none' }; reversibility = 'none'; dependsOn = @($op.id)
            status = $res.result; appliedAt = (Get-Date).ToString('o'); error = $(if ($res.result -eq 'failed') { $res.detail } else { $null }); note = $res.detail
            revertedBy = $null; revertedAt = $null; revertResult = $null
        }
        if (@('reverted', 'skipped') -contains $res.result) {
            $op.revertedBy = $undoRun.runId
            $op.revertedAt = (Get-Date).ToString('o')
            $op.revertResult = $res.result
        }
        Save-SloJournal
        if ($res.result -eq 'failed') { $failed = $true; break }
    }

    $counts = $outcome | Group-Object result | ForEach-Object { '{0}={1}' -f $_.Name, $_.Count }
    $open = @($outcome | Where-Object { @('conflict', 'manual', 'partial', 'failed') -contains $_.result })
    Complete-SloRun -Status $(if ($failed) { 'failed' } elseif ($open.Count -gt 0) { 'partial' } else { 'succeeded' })
    Write-Host ''
    Write-Host ("Undo {0}: {1}" -f $(if ($failed) { 'stopped at a failure' } else { 'finished' }), ($counts -join ', ')) -ForegroundColor $(if ($failed) { 'Red' } elseif ($open.Count) { 'Yellow' } else { 'Green' })
    if ($open.Count -gt 0) {
        Write-Host 'Needs manual attention:' -ForegroundColor Yellow
        foreach ($o in $open) { Write-Host " - [$($o.result)] $($o.detail)" -ForegroundColor Yellow }
    }
    if ($failed) { Write-Host 'Fix the cause and rerun the same command; completed steps are recorded and will not be repeated.' -ForegroundColor Yellow; $exit = 1 }
    Write-Host "Log: $($undoRun.logPath)"
    Write-Host "Journal and backups are kept in $(Get-SloStateRoot). Archive them with -Mode ArchiveJournal when no longer needed."
} catch {
    $exit = 1
    Write-SloLog ("FAILED: " + $_.Exception.Message) 'ERROR'
    $r = Get-SloRun
    if ($r -and $r.status -eq 'running') { Complete-SloRun -Status 'failed' }
} finally {
    Exit-SloLock
}
exit $exit
