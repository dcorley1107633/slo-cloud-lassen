#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs the SLO Cloud 5 web node (IIS + PHP FastCGI) on Windows Server.

.DESCRIPTION
    Infrastructure installer only. It prepares IIS, PHP, folders, permissions,
    a localhost HTTPS binding and the application files, then hands over to the
    application's own setup wizard (https://localhost/setup/), which owns the
    database, encryption keys and config.yaml.

    It never asks for SQL credentials, never connects to SQL Server beyond a
    TCP port check, and never reads, copies or validates secret files.

    Every change is written to a durable change journal before it is made:
        C:\ProgramData\SLOCloud5\installer\undo-manifest.json
    with backups under C:\ProgramData\SLOCloud5\installer\runs\<run-id>\.
    Undo-SLOCloud5.ps1 uses that journal to roll back a run or uninstall.

    Runs are idempotent: rerunning converges to the same state, records
    unchanged resources as "skipped", and deploys a newer commit as an
    upgrade while preserving config, data and secrets.

.PARAMETER InstallRoot
    Installation root. Default D:\SLO Cloud 5. Holds repo, web, secrets, data, php, tools.

.PARAMETER RepositoryUrl
    Git URL. Must not contain embedded credentials; use a credential helper or SSH key.

.PARAMETER Ref
    Branch, tag or full commit SHA to deploy. There is no default: the repository has no 'main' branch.

.PARAMETER LocalSourcePath
    Alternative to Git: a folder (an extracted release or checkout) that contains src\index.php.

.PARAMETER SqlHost / SqlPort
    SQL Server host and TCP port (default 1433) for a reachability check only.

.PARAMETER SecretsAccessAfterSetup
    Access the application pool keeps on the secrets folder after the wizard finishes.
    Modify (default): required by System > Configuration > key rotation, which rewrites key.yaml
    and writes backups beside it (src/api/rotate_key.php). Read: rotation from the UI will fail
    until an administrator grants write access temporarily.

.PARAMETER Plan
    Dry run: preflight plus a list of every change that would be made. Nothing is changed.

.PARAMETER FinalizeSetup
    Only run the post-wizard step (apply the final secrets ACL, record the result).

.EXAMPLE
    .\Install-SLOCloud5.ps1 -Plan

.EXAMPLE
    .\Install-SLOCloud5.ps1 -InstallRoot 'D:\SLO Cloud 5' -RepositoryUrl https://github.com/aeron100/SLO-Cloud-5.git -Ref dev -SqlHost sql01.campus.local
#>
[CmdletBinding()]
param(
    [string]$InstallRoot,
    [string]$RepositoryUrl,
    [string]$Ref,
    [string]$LocalSourcePath,
    [string]$SqlHost,
    [int]$SqlPort = 0,
    [string]$SiteName = 'SLOCloud5',
    [string]$AppPoolName = 'SLOCloud5',
    [ValidateSet('Modify', 'Read')][string]$SecretsAccessAfterSetup,
    [string]$TimeZone,
    [string]$SourceCache,
    [hashtable]$TrustedHashes = @{},
    [switch]$AcceptOdbcLicense,
    [switch]$AllowDefaultWebSiteStop,
    [switch]$OverwriteModifiedWebFiles,
    [switch]$OverwritePhpIni,
    [switch]$Plan,
    [switch]$FinalizeSetup,
    [switch]$NonInteractive,
    [switch]$SkipValidation,
    [switch]$SkipWizardWait
)

# Version 1: catches unset variables. Version 2+ would also throw on reading optional
# properties of journal records and $null results, which these scripts do deliberately.
Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

if ($PSVersionTable.PSEdition -ne 'Desktop') {
    throw 'Run this installer in Windows PowerShell 5.1 (powershell.exe). PowerShell 7 cannot load the IIS WebAdministration provider reliably.'
}
if (-not [Environment]::Is64BitProcess) {
    throw 'Run this installer in 64-bit Windows PowerShell.'
}

$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Import-Module (Join-Path $ScriptRoot 'lib\SLOCloud5.Common.psm1') -Force -DisableNameChecking
$Sources = Get-Content -LiteralPath (Join-Path $ScriptRoot 'sources.json') -Raw | ConvertFrom-Json

$IisAppId = '{4dc3e181-e14b-4a21-b022-59fc669b0914}'   # well-known IIS application id for HTTP.sys bindings
$PhpHandlerName = 'SLOCloud5-PHP_via_FastCGI'
$RequiredPhpModules = @('PDO', 'pdo_sqlsrv', 'yaml', 'openssl', 'mbstring', 'curl', 'fileinfo', 'json', 'dom', 'iconv', 'session', 'Zend OPcache')
$WindowsFeatures = @(
    'Web-Server', 'Web-WebServer', 'Web-Common-Http', 'Web-Default-Doc', 'Web-Static-Content', 'Web-Http-Errors',
    'Web-Health', 'Web-Http-Logging', 'Web-Request-Monitor', 'Web-Security', 'Web-Filtering', 'Web-App-Dev', 'Web-CGI',
    'Web-Mgmt-Tools', 'Web-Mgmt-Console', 'Web-Scripting-Tools'
)
$UnwantedFeatures = @('Web-Dir-Browsing', 'Web-DAV-Publishing', 'Web-Asp-Net45', 'Web-Asp-Net', 'Web-ASP')

# Script state (initialised for Set-StrictMode).
$script:Prev = $null
$script:SourceInfo = $null
$script:AppVersion = $null
$script:PhpModules = @()
$script:PhpSources = @()
$script:Thumbprint = $null
$script:PoolSid = $null
$script:SqlCheck = $null
$script:GitExe = $null
$P = $null

# ---------------------------------------------------------------------------
# Input
# ---------------------------------------------------------------------------

function Read-Input {
    param([string]$Prompt, [string]$Default, [switch]$Required)
    if ($NonInteractive) {
        if ($Required -and [string]::IsNullOrWhiteSpace($Default)) { throw "Missing required value: $Prompt (pass it as a parameter in -NonInteractive mode)." }
        return $Default
    }
    while ($true) {
        $label = if ($Default) { "$Prompt [$Default]" } else { $Prompt }
        $v = Read-Host $label
        if ([string]::IsNullOrWhiteSpace($v)) { $v = $Default }
        if ($Required -and [string]::IsNullOrWhiteSpace($v)) { Write-Host 'A value is required.' -ForegroundColor Yellow; continue }
        return $v.Trim()
    }
}

function Confirm-Typed {
    param([string]$Message, [string]$Token = 'YES')
    if ($NonInteractive) { return $true }
    Write-Host ''
    Write-Host $Message -ForegroundColor Yellow
    return ((Read-Host "Type $Token to continue") -ceq $Token)
}

function Get-PreviousParameters {
    $journal = Get-SloJournal
    foreach ($run in (@($journal.runs) | Sort-Object startedAt -Descending)) {
        if ($run.kind -eq 'install' -and $run.parameters) { return $run.parameters }
    }
    return $null
}

function Get-Prev([string]$Name, [string]$Fallback) {
    if ($script:Prev -and $script:Prev.PSObject.Properties.Name -contains $Name -and $script:Prev.$Name) { return [string]$script:Prev.$Name }
    return $Fallback
}

# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

function Get-Paths([string]$Root) {
    $php = Join-Path $Root 'php'
    $data = Join-Path $Root 'data'
    [pscustomobject]@{
        Root      = $Root
        Repo      = Join-Path $Root 'repo'
        Web       = Join-Path $Root 'web'
        WebConfig = Join-Path (Join-Path $Root 'web') 'config'
        Secrets   = Join-Path $Root 'secrets'
        Backup    = Join-Path $Root 'backup'
        Data      = $data
        DataLogs  = Join-Path $data 'logs'
        DataTmp   = Join-Path $data 'tmp'
        DataVar   = Join-Path $data 'var'
        Php       = $php
        PhpCgi    = Join-Path $php 'php-cgi.exe'
        PhpExe    = Join-Path $php 'php.exe'
        PhpIni    = Join-Path $php 'php.ini'
        CaBundle  = Join-Path $php 'extras\ssl\cacert.pem'
        Tools     = Join-Path $Root 'tools'
        MinGit    = Join-Path (Join-Path $Root 'tools') 'mingit'
    }
}

function Test-UnderPath([string]$Child, [string]$Parent) {
    if (-not $Child -or -not $Parent) { return $false }
    $c = [System.IO.Path]::GetFullPath($Child).TrimEnd('\') + '\'
    $parentFull = [System.IO.Path]::GetFullPath($Parent).TrimEnd('\') + '\'
    return $c.StartsWith($parentFull, [StringComparison]::OrdinalIgnoreCase)
}

function Get-PhpVersionOf([string]$PhpExe) {
    if (-not (Test-Path -LiteralPath $PhpExe)) { return $null }
    $r = Invoke-SloNative -FilePath $PhpExe -Arguments @('-n', '-r', 'echo PHP_VERSION;') -AllowFailure -Quiet
    if ($r.ExitCode -ne 0) { return $null }
    return $r.Text.Trim()
}

function Get-UninstallEntry([string]$NamePattern) {
    $roots = @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall')
    foreach ($r in $roots) {
        foreach ($k in @(Get-ChildItem -LiteralPath $r -ErrorAction SilentlyContinue)) {
            $props = Get-ItemProperty -LiteralPath $k.PSPath -ErrorAction SilentlyContinue
            if ($props -and $props.PSObject.Properties.Name -contains 'DisplayName' -and $props.DisplayName -like $NamePattern) {
                return [pscustomobject]@{ displayName = $props.DisplayName; version = $(if ($props.PSObject.Properties.Name -contains 'DisplayVersion') { $props.DisplayVersion } else { $null }); productCode = $k.PSChildName }
            }
        }
    }
    return $null
}

function Get-VcRedistState {
    $k = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
    if (-not (Test-Path -LiteralPath $k)) { return [pscustomobject]@{ installed = $false; version = $null; minor = 0 } }
    $props = Get-ItemProperty -LiteralPath $k
    $installed = ($props.PSObject.Properties.Name -contains 'Installed') -and $props.Installed -eq 1
    $minor = if ($props.PSObject.Properties.Name -contains 'Minor') { [int]$props.Minor } else { 0 }
    $ver = if ($props.PSObject.Properties.Name -contains 'Version') { $props.Version } else { $null }
    return [pscustomobject]@{ installed = $installed; version = $ver; minor = $minor }
}

function Get-OdbcDriver {
    foreach ($name in @('ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server')) {
        $k = "HKLM:\SOFTWARE\ODBC\ODBCINST.INI\$name"
        if (Test-Path -LiteralPath $k) {
            $driver = (Get-ItemProperty -LiteralPath $k).Driver
            $ver = $null
            if ($driver -and (Test-Path -LiteralPath $driver)) { $ver = (Get-Item -LiteralPath $driver).VersionInfo.ProductVersion }
            return [pscustomobject]@{ name = $name; version = $ver }
        }
    }
    return $null
}

function Test-UrlRewriteInstalled {
    return (Test-Path -LiteralPath "$env:windir\System32\inetsrv\rewrite.dll")
}

function Test-PendingReboot {
    $keys = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending',
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    )
    foreach ($k in $keys) { if (Test-Path -LiteralPath $k) { return $true } }
    $sm = Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -ErrorAction SilentlyContinue
    if ($sm -and $sm.PSObject.Properties.Name -contains 'PendingFileRenameOperations' -and $sm.PendingFileRenameOperations) { return $true }
    return $false
}

function Get-BindingParts([string]$BindingInformation) {
    $parts = $BindingInformation.Split(':')
    $hostName = if ($parts.Count -ge 3) { ($parts[2..($parts.Count - 1)] -join ':') } else { '' }
    return [pscustomobject]@{ ip = $parts[0]; port = $(if ($parts.Count -ge 2) { $parts[1] } else { '' }); host = $hostName }
}

function Install-Msi([string]$Path, [string[]]$Properties, [string]$LogName) {
    $log = Join-Path (Get-SloRun).runDir $LogName
    $argLine = '/i "{0}" /qn /norestart /l*v "{1}" {2}' -f $Path, $log, ($Properties -join ' ')
    Write-SloLog "exec: msiexec.exe $argLine"
    $proc = Start-Process -FilePath "$env:windir\System32\msiexec.exe" -ArgumentList $argLine -Wait -PassThru
    if (@(0, 3010, 1641) -notcontains $proc.ExitCode) { throw "msiexec failed with exit code $($proc.ExitCode); see $log" }
    if ($proc.ExitCode -ne 0) { Add-SloItItem "A restart is required to complete installing $(Split-Path -Leaf $Path) (msiexec exit $($proc.ExitCode))." }
}

# ---------------------------------------------------------------------------
# Preflight (read-only)
# ---------------------------------------------------------------------------

function Invoke-Preflight {
    $findings = New-Object System.Collections.ArrayList
    function Add-Finding([string]$Level, [string]$Text) { [void]$findings.Add([pscustomobject]@{ level = $Level; text = $Text }) }

    $os = Get-CimInstance Win32_OperatingSystem
    if ($os.ProductType -eq 1) { Add-Finding 'BLOCK' "This is a client OS ($($os.Caption)). Windows Server 2016 or later is required." }
    $build = [int]$os.BuildNumber
    if ($build -lt 14393) { Add-Finding 'BLOCK' "Windows Server build $build is older than 2016 (14393)." }
    elseif ($build -lt 20348) { Add-Finding 'WARN' "Windows Server build ${build}: 2022 (20348) or later is recommended." }
    else { Add-Finding 'OK' "OS: $($os.Caption) build $build" }

    if ($P.Root -match "['""%;|<>*?]") { Add-Finding 'BLOCK' "Install root contains characters that IIS/XPath/php.ini cannot quote safely: $($P.Root)" }
    if (-not [System.IO.Path]::IsPathRooted($P.Root) -or $P.Root -notmatch '^[A-Za-z]:\\') { Add-Finding 'BLOCK' "Install root must be an absolute local path such as D:\SLO Cloud 5." }
    elseif ($P.Root.TrimEnd('\').Length -le 2) { Add-Finding 'BLOCK' 'Install root cannot be a drive root.' }
    foreach ($sys in @($env:windir, $env:ProgramFiles, ${env:ProgramFiles(x86)}, $env:ProgramData, (Join-Path $env:SystemDrive 'inetpub'))) {
        if ($sys -and (Test-UnderPath $P.Root $sys)) { Add-Finding 'BLOCK' "Install root must not be inside $sys." }
    }
    $drive = $P.Root.Substring(0, 2)
    $disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='$drive'" -ErrorAction SilentlyContinue
    if (-not $disk) { Add-Finding 'BLOCK' "Drive $drive does not exist." }
    elseif ($disk.DriveType -ne 3) { Add-Finding 'BLOCK' "Drive $drive is not a local fixed disk (type $($disk.DriveType))." }
    else {
        $freeGb = [math]::Round($disk.FreeSpace / 1GB, 1)
        if ($freeGb -lt 2) { Add-Finding 'BLOCK' "Drive $drive has only $freeGb GB free." }
        elseif ($freeGb -lt 10) { Add-Finding 'WARN' "Drive $drive has $freeGb GB free; 80 GB+ is recommended for data and logs." }
        else { Add-Finding 'OK' "Drive ${drive}: $freeGb GB free" }
    }
    if (Test-PendingReboot) { Add-Finding 'WARN' 'Windows reports a pending restart. Installing is allowed, but restart before production use.' }

    if (Test-Path -LiteralPath $P.Root) {
        $items = @(Get-ChildItem -LiteralPath $P.Root -Force -ErrorAction SilentlyContinue | Where-Object { @('repo', 'web', 'secrets', 'data', 'backup', 'php', 'tools') -notcontains $_.Name -and $_.Name -notlike '.php-staging-*' })
        if (-not (Test-SloInstallerCreated -Resource "dir:$($P.Root)") -and $items.Count -gt 0) {
            Add-Finding 'CONFIRM' ("Install root already exists and contains other items ({0}). Its ACL will be replaced (inheritance disabled; SYSTEM and Administrators only), which also applies to those items." -f (($items | Select-Object -First 5 | ForEach-Object { $_.Name }) -join ', '))
        }
    }
    if ((Test-Path -LiteralPath $P.Php) -and -not (Test-SloInstallerCreated -Resource "dir:$($P.Php)")) {
        Add-Finding 'BLOCK' "$($P.Php) exists but was not created by this installer. Move it or choose another install root."
    }
    if (Test-Path -LiteralPath (Join-Path $P.WebConfig 'config.yaml')) { Add-Finding 'INFO' 'config.yaml exists: the setup wizard has already run. Application files will be upgraded in place; config, data and secrets are preserved.' }
    foreach ($kv in @(@('SLOCLOUD_DATA_PATH', $P.Data), @('SLOCLOUD_ENV', 'production'))) {
        $cur = Get-SloMachineEnv -Name $kv[0]
        if ($cur.exists -and $cur.value.TrimEnd('\') -ne ([string]$kv[1]).TrimEnd('\')) {
            Add-Finding 'CONFIRM' ("Machine variable {0} is already set to '{1}' and will be changed to '{2}'. Any other application on this server that reads it (for example another SLO Cloud install) picks up the new value after its next restart. Undo restores '{1}'." -f $kv[0], $cur.value, $kv[1])
        }
    }

    if (-not $LocalSourcePath) {
        if ($RepositoryUrl -match '^[a-z][a-z0-9+.\-]*://[^/@]+@' -and $RepositoryUrl -notmatch '^ssh://git@') { Add-Finding 'BLOCK' 'Repository URL contains embedded credentials. They would be stored in .git\config. Use a credential helper, deploy key or SSH.' }
        $git = Get-Command git.exe -ErrorAction SilentlyContinue
        if ($git) { Add-Finding 'OK' "git: $($git.Source)" } elseif (Test-Path -LiteralPath (Join-Path $P.MinGit 'cmd\git.exe')) { Add-Finding 'OK' 'git: installer-owned MinGit' } else { Add-Finding 'INFO' "git.exe not found: MinGit $($Sources.sources.mingit.version) will be extracted to $($P.MinGit) (not added to PATH)." }
        $repoGit = Join-Path $P.Repo '.git'
        if ((Test-Path -LiteralPath $P.Repo) -and -not (Test-Path -LiteralPath $repoGit) -and @(Get-ChildItem -LiteralPath $P.Repo -Force -ErrorAction SilentlyContinue).Count -gt 0) {
            Add-Finding 'BLOCK' "$($P.Repo) is not empty and is not a git checkout."
        }
    } else {
        if (-not (Test-Path -LiteralPath (Join-Path $LocalSourcePath 'src\index.php'))) { Add-Finding 'BLOCK' "Local source $LocalSourcePath does not contain src\index.php." }
        if (Test-Path -LiteralPath (Join-Path $P.Repo '.git')) { Add-Finding 'BLOCK' "$($P.Repo) is a git checkout; it will not be overwritten with a local folder." }
    }

    $iisInstalled = $false
    try { $iisInstalled = [bool](Get-WindowsFeature -Name Web-Server).Installed } catch { Add-Finding 'BLOCK' "Get-WindowsFeature failed: $($_.Exception.Message)" }
    if ($iisInstalled) {
        Import-SloWebAdministration
        $siteOurs = Test-SloInstallerCreated -Resource "iis.site:$SiteName"
        $poolOurs = Test-SloInstallerCreated -Resource "iis.apppool:$AppPoolName"
        if ((Get-SloSiteSummary -Name $SiteName) -and -not $siteOurs) { Add-Finding 'BLOCK' "An IIS site named '$SiteName' already exists and was not created by this installer. Use -SiteName." }
        if ((Test-Path -Path "IIS:\AppPools\$AppPoolName") -and -not $poolOurs) { Add-Finding 'BLOCK' "An application pool named '$AppPoolName' already exists and was not created by this installer. Use -AppPoolName." }

        foreach ($site in @(Get-ChildItem IIS:\Sites)) {
            if ($site.Name -eq $SiteName -and $siteOurs) { continue }
            $paths = @([Environment]::ExpandEnvironmentVariables($site.physicalPath))
            $paths += @(Get-WebApplication -Site $site.Name | ForEach-Object { [Environment]::ExpandEnvironmentVariables($_.PhysicalPath) })
            $paths += @(Get-WebVirtualDirectory -Site $site.Name | ForEach-Object { [Environment]::ExpandEnvironmentVariables($_.PhysicalPath) })
            foreach ($pp in $paths) {
                if (-not $pp) { continue }
                if (Test-UnderPath $P.Root $pp) { Add-Finding 'BLOCK' "IIS site '$($site.Name)' serves $pp, which contains the install root. repo and secrets would be exposed over HTTP." }
                elseif (Test-UnderPath $pp $P.Root) { Add-Finding 'BLOCK' "IIS site '$($site.Name)' serves $pp, which is inside the install root." }
            }
        }
        foreach ($b in (Get-SloAllSiteBindings)) {
            if ($b.site -eq $SiteName) { continue }
            $bp = Get-BindingParts $b.bindingInformation
            if ($b.protocol -eq 'https' -and $bp.port -eq '443' -and $bp.host -ieq 'localhost') {
                if ($b.site -eq 'Default Web Site') { Add-Finding 'WARN' "Default Web Site has an https localhost:443 binding. It must be stopped (not deleted) so $SiteName can use localhost. Its state is journaled and restored by undo." }
                else { Add-Finding 'BLOCK' "Site '$($b.site)' already has binding https/$($b.bindingInformation). Resolve with IT; the installer will not take it over." }
            }
            if ($b.protocol -eq 'https' -and $bp.port -eq '443' -and -not $bp.host) { Add-Finding 'INFO' "Site '$($b.site)' has a non-SNI https binding on port 443 ($($b.bindingInformation)). It is left alone; an SNI binding for localhost coexists with it." }
        }
    }
    $ourThumbs = @(Get-SloLiveOperations -Kind 'cert.create' | ForEach-Object { if ($_.after) { [string]$_.after.thumbprint } })
    foreach ($e in (Get-SloHttpSysSslBindings)) {
        if ($e.type -eq 'hostnameport' -and $e.key -ieq 'localhost:443' -and $ourThumbs -notcontains $e.certHash) {
            Add-Finding 'BLOCK' "HTTP.sys already has a certificate binding for localhost:443 (certificate $($e.certHash)) that this installer did not create."
        }
        if ($e.type -eq 'ipport' -and $e.key -match ':443$') { Add-Finding 'INFO' "HTTP.sys IP binding $($e.key) exists (another site's certificate). Left unchanged." }
        if ($e.type -eq 'ccs' -and $e.key -match '443') { Add-Finding 'INFO' "HTTP.sys Central Certificate Store binding $($e.key) exists. Left unchanged." }
    }
    try {
        foreach ($l in @(Get-NetTCPConnection -LocalPort 443 -State Listen -ErrorAction SilentlyContinue)) {
            if ($l.OwningProcess -ne 4) {
                $proc = Get-Process -Id $l.OwningProcess -ErrorAction SilentlyContinue
                Add-Finding 'BLOCK' ("Port 443 is held by process {0} ({1}), not HTTP.sys. IIS cannot serve HTTPS until that is resolved." -f $l.OwningProcess, $(if ($proc) { $proc.ProcessName } else { 'unknown' }))
            }
        }
    } catch { }

    $tcp = Test-SloTcpPort -HostName $SqlHost -Port $SqlPort
    $script:SqlCheck = $tcp
    if ($tcp.reachable) { Add-Finding 'OK' "SQL Server TCP ${SqlHost}:$SqlPort reachable ($($tcp.detail)). Port reachability only; database readiness is checked by the setup wizard." }
    else { Add-Finding 'WARN' "SQL Server TCP ${SqlHost}:$SqlPort not reachable: $($tcp.detail). The infrastructure install continues; the wizard needs this port." }

    Add-Finding 'INFO' 'Not changed by this installer: Windows Firewall, RDP, network configuration, TLS protocol (SCHANNEL) settings, production hostname/DNS/certificate.'
    return ,$findings.ToArray()
}

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------

function Step-IisBackup([string]$Label) {
    $appcmd = "$env:windir\System32\inetsrv\appcmd.exe"
    if (-not (Test-Path -LiteralPath $appcmd)) { return }
    $run = Get-SloRun
    $name = "SLOCloud5-$($run.runId)-$Label"
    Invoke-SloChange -Kind 'iis.backup' -Resource "iis.backup:$name" -Description "IIS configuration backup '$name'" -Ownership 'created' `
        -Inverse @{ type = 'none'; note = "IIS backup kept for manual recovery: appcmd restore backup $name" } -Reversibility 'none' `
        -Apply {
            Invoke-SloNative -FilePath $appcmd -Arguments @('add', 'backup', $name) | Out-Null
            Backup-SloFile -Path "$env:windir\System32\inetsrv\config\applicationHost.config" -Name "iis\applicationHost.$Label.config" | Out-Null
            Backup-SloFile -Path "$env:windir\System32\inetsrv\config\redirection.config" -Name "iis\redirection.$Label.config" | Out-Null
            $adminCfg = "$env:windir\System32\inetsrv\config\administration.config"
            Backup-SloFile -Path $adminCfg -Name "iis\administration.$Label.config" | Out-Null
        } | Out-Null
}

function Step-WindowsFeatures {
    $before = @{}
    foreach ($f in @(Get-WindowsFeature -Name 'Web-*')) { $before[$f.Name] = [bool]$f.Installed }
    $missing = @($WindowsFeatures | Where-Object { -not $before.ContainsKey($_) -or -not $before[$_] })
    if ($missing.Count -eq 0) { Add-SloSkip -Kind 'feature.install' -Resource 'features:IIS' -Description 'required IIS role services already installed'; return }
    $instructions = 'Windows features are shared and are not removed automatically. If no other site needs them, remove manually with Uninstall-WindowsFeature (a restart may be required).'
    $op = Invoke-SloChange -Kind 'feature.install' -Resource 'features:IIS' -Description ("install Windows features: " + ($missing -join ', ')) `
        -Ownership 'created' -Before ([pscustomobject]@{ installed = @($before.Keys | Where-Object { $before[$_] }) }) `
        -Inverse @{ type = 'manual'; instructions = $instructions } -Reversibility 'manual' `
        -Apply {
            $r = Install-WindowsFeature -Name $missing
            if (-not $r.Success) { throw "Install-WindowsFeature failed: $($r.ExitCode)" }
            if ($r.RestartNeeded -eq 'Yes') { Add-SloItItem 'Windows reports that a restart is needed to finish installing IIS features.' }
        } `
        -GetAfter {
            $now = @(Get-WindowsFeature -Name 'Web-*' | Where-Object { $_.Installed } | ForEach-Object { $_.Name })
            [pscustomobject]@{ newlyInstalled = @($now | Where-Object { -not $before[$_] }) }
        }
    if ($op -and $op.PSObject.Properties.Name -contains 'after' -and $op.after -and $op.after.newlyInstalled) {
        $op.inverse.instructions = $instructions + ' Installed by this run: ' + (@($op.after.newlyInstalled) -join ', ')
        Save-SloJournal
        foreach ($u in $UnwantedFeatures) {
            if (@($op.after.newlyInstalled) -contains $u) { Add-SloWarning "Windows also installed $u as a default role service. SLO Cloud 5 does not need it; its web.config disables directory browsing. Remove it if your baseline forbids it." }
        }
    }
}

function Step-VcRedist {
    $state = Get-VcRedistState
    if ($state.installed -and $state.minor -ge 30) { Add-SloSkip -Kind 'package.install' -Resource 'package:vcredist-x64' -Description "Visual C++ 2015-2022 x64 runtime present ($($state.version))"; return }
    $src = Get-SloSourceFile -Key 'vcredist' -Sources $Sources -SourceCache $SourceCache -NonInteractive:$NonInteractive
    Invoke-SloChange -Kind 'package.install' -Resource 'package:vcredist-x64' -Description 'install Visual C++ 2015-2022 x64 runtime' `
        -Ownership $(if ($state.installed) { 'modified' } else { 'created' }) -Before $state `
        -Inverse @{ type = 'manual'; instructions = 'The Visual C++ runtime is shared by other software and is never removed by undo.' } -Reversibility 'manual' `
        -Apply {
            $proc = Start-Process -FilePath $src.path -ArgumentList '/install /quiet /norestart' -Wait -PassThru
            if (@(0, 1638, 3010, 1641) -notcontains $proc.ExitCode) { throw "vc_redist failed with exit code $($proc.ExitCode)" }
            if (@(3010, 1641) -contains $proc.ExitCode) { Add-SloItItem 'A restart is required to finish installing the Visual C++ runtime.' }
        } -GetAfter { Get-VcRedistState } | Out-Null
}

function Step-UrlRewrite {
    if (Test-UrlRewriteInstalled) { Add-SloSkip -Kind 'package.install' -Resource 'package:urlrewrite' -Description 'IIS URL Rewrite module present'; return }
    $src = Get-SloSourceFile -Key 'urlrewrite' -Sources $Sources -SourceCache $SourceCache -NonInteractive:$NonInteractive
    Invoke-SloChange -Kind 'package.install' -Resource 'package:urlrewrite' -Description 'install IIS URL Rewrite 2.1 (required by web.config)' `
        -Ownership 'created' -Inverse @{ type = 'manual'; instructions = 'URL Rewrite is a server-wide IIS module. Remove it from Programs and Features only if no other site uses rewrite rules.' } -Reversibility 'manual' `
        -Apply {
            Install-Msi -Path $src.path -Properties @() -LogName 'urlrewrite-msi.log'
            if (-not (Test-UrlRewriteInstalled)) { throw 'URL Rewrite installation finished but rewrite.dll is missing.' }
        } -GetAfter { Get-UninstallEntry 'IIS URL Rewrite Module*' } | Out-Null
}

function Step-Odbc {
    $existing = Get-OdbcDriver
    if ($existing) { Add-SloSkip -Kind 'package.install' -Resource 'package:odbc' -Description "$($existing.name) present ($($existing.version))"; return }
    if (-not $AcceptOdbcLicense) {
        if ($NonInteractive) { throw 'ODBC Driver 18 for SQL Server must be installed. Review its license terms and pass -AcceptOdbcLicense.' }
        Write-Host ''
        Write-Host 'Microsoft ODBC Driver 18 for SQL Server is required by pdo_sqlsrv.' -ForegroundColor Yellow
        Write-Host "License terms: $($Sources.sources.odbc.licenseUrl)"
        if ((Read-Host 'Type ACCEPT to accept the Microsoft ODBC Driver license terms and install it') -cne 'ACCEPT') { throw 'ODBC driver license not accepted.' }
    }
    $src = Get-SloSourceFile -Key 'odbc' -Sources $Sources -SourceCache $SourceCache -NonInteractive:$NonInteractive
    Invoke-SloChange -Kind 'package.install' -Resource 'package:odbc' -Description 'install Microsoft ODBC Driver 18 for SQL Server' `
        -Ownership 'created' -Inverse @{ type = 'manual'; instructions = 'The ODBC driver is shared by any SQL Server client on this machine. Remove it from Programs and Features only if nothing else uses it.' } -Reversibility 'manual' `
        -Apply {
            Install-Msi -Path $src.path -Properties @('IACCEPTMSODBCSQLLICENSETERMS=YES') -LogName 'odbc-msi.log'
            if (-not (Get-OdbcDriver)) { throw 'ODBC driver installation finished but the driver is not registered.' }
        } -GetAfter { Get-OdbcDriver } | Out-Null
}

function Step-Directory([string]$Path, [hashtable]$InverseOptions) {
    if (Test-Path -LiteralPath $Path) {
        if (Test-SloInstallerCreated -Resource "dir:$Path") { Add-SloSkip -Kind 'dir.create' -Resource "dir:$Path" -Description "$Path exists (installer-created)" }
        else { Add-SloSkip -Kind 'dir.create' -Resource "dir:$Path" -Description "$Path exists (preexisting, never removed by undo)" }
        return
    }
    $inv = @{ type = 'dir.remove'; path = $Path }
    foreach ($k in $InverseOptions.Keys) { $inv[$k] = $InverseOptions[$k] }
    Invoke-SloChange -Kind 'dir.create' -Resource "dir:$Path" -Description "create $Path" -Ownership 'created' `
        -Before ([pscustomobject]@{ exists = $false }) -After ([pscustomobject]@{ exists = $true }) -Inverse $inv `
        -Apply { New-Item -ItemType Directory -Path $Path -Force | Out-Null } | Out-Null
}

function Step-Directories {
    Step-Directory $P.Root @{ requireEmpty = $true }
    Step-Directory $P.Web @{ requireEmpty = $true }
    Step-Directory $P.Secrets @{ retain = $true; retainReason = 'secrets and their contents are always preserved; delete manually if intended' }
    # Target folder for the application's snapshot function (not implemented yet). Created empty.
    Step-Directory $P.Backup @{ retain = $true; retainReason = 'backups are always preserved; delete manually if intended' }
    Step-Directory $P.Data @{ retain = $true; retainReason = 'application data (sessions, logs, report definitions) is preserved' }
    foreach ($d in @($P.DataLogs, $P.DataTmp, $P.DataVar)) { Step-Directory $d @{ retain = $true; retainReason = 'application data is preserved' } }
}

function Resolve-Git {
    $cmd = Get-Command git.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $mingitExe = Join-Path $P.MinGit 'cmd\git.exe'
    if (Test-Path -LiteralPath $mingitExe) { return $mingitExe }
    Step-Directory $P.Tools @{ recurse = $true }
    $src = Get-SloSourceFile -Key 'mingit' -Sources $Sources -SourceCache $SourceCache -NonInteractive:$NonInteractive
    Invoke-SloChange -Kind 'tool.extract' -Resource "dir:$($P.MinGit)" -Description "extract MinGit $($src.version) to $($P.MinGit)" -Ownership 'created' `
        -Before ([pscustomobject]@{ exists = $false }) -After ([pscustomobject]@{ sha256 = $src.sha256 }) `
        -Inverse @{ type = 'dir.remove'; path = $P.MinGit; recurse = $true } `
        -Apply { Expand-SloZip -ZipPath $src.path -Destination $P.MinGit } | Out-Null
    if (Test-SloPlanMode) { return $mingitExe }
    if (-not (Test-Path -LiteralPath $mingitExe)) { throw "MinGit extracted but $mingitExe is missing." }
    return $mingitExe
}

function Invoke-Git([string[]]$Arguments, [switch]$Interactive, [switch]$AllowFailure, [switch]$Quiet) {
    $base = @('-c', 'safe.directory=*', '-c', 'core.autocrlf=false', '-c', 'advice.detachedHead=false')
    return (Invoke-SloNative -FilePath $script:GitExe -Arguments ($base + $Arguments) -Interactive:$Interactive -AllowFailure:$AllowFailure -Quiet:$Quiet)
}

function Step-Source {
    if ($LocalSourcePath) { Step-LocalSource; return }
    $script:GitExe = Resolve-Git
    if ((Test-SloPlanMode) -and -not (Test-Path -LiteralPath $script:GitExe)) {
        Write-SloLog "would clone $RepositoryUrl into $($P.Repo) and check out '$Ref'" 'PLAN'
        return
    }
    # repo is a staging copy of the source: it is never edited in place. Every run resets it to
    # exactly the commit being deployed, discarding local edits and untracked files, so nothing
    # outside Git can reach the web root.
    $repoGit = Join-Path $P.Repo '.git'
    $before = $null
    $created = $false
    $localChanges = @()
    if (Test-Path -LiteralPath $repoGit) {
        $origin = (Invoke-Git @('-C', $P.Repo, 'remote', 'get-url', 'origin')).Text.Trim()
        $norm = { param($u) $u.Trim().TrimEnd('/') -replace '\.git$', '' }
        if ((& $norm $origin) -ne (& $norm $RepositoryUrl)) { throw "Repository $($P.Repo) has origin $origin, not $RepositoryUrl. Resolve manually." }
        $localChanges = @((Invoke-Git @('-C', $P.Repo, 'status', '--porcelain', '--untracked-files=all') -Quiet).Output | Where-Object { $_ -and $_.Trim() })
        if ($localChanges.Count -gt 0) {
            $shown = ($localChanges | Select-Object -First 20) -join '; '
            $more = if ($localChanges.Count -gt 20) { " and $($localChanges.Count - 20) more" } else { '' }
            Add-SloWarning ("{0} local change(s) in {1} will be discarded (repo is staging only): {2}{3}" -f $localChanges.Count, $P.Repo, $shown, $more)
        }
        if (-not (Test-SloPlanMode)) {
            # Stop manual git commands in repo from rewriting line endings (Git for Windows defaults to autocrlf=true).
            Invoke-Git @('-C', $P.Repo, 'config', 'core.autocrlf', 'false') -Quiet | Out-Null
        }
        $before = (Invoke-Git @('-C', $P.Repo, 'rev-parse', '--verify', '--quiet', 'HEAD') -AllowFailure).Text.Trim()
        if (-not (Test-SloPlanMode)) {
            Write-SloLog "fetching $RepositoryUrl (git may prompt for credentials; they are not logged)"
            Invoke-Git @('-C', $P.Repo, 'fetch', '--prune', '--tags', 'origin') -Interactive | Out-Null
        }
    } else {
        Step-Directory $P.Repo @{ recurse = $true; guardGit = $true }
        if (Test-SloPlanMode) { Write-SloLog "would clone $RepositoryUrl into $($P.Repo) and check out '$Ref'" 'PLAN'; return }
        Invoke-SloChange -Kind 'git.clone' -Resource "git:$($P.Repo)" -Description "clone $RepositoryUrl into $($P.Repo)" -Ownership 'created' `
            -After ([pscustomobject]@{ url = $RepositoryUrl }) `
            -Inverse @{ type = 'none'; note = 'The clone is removed with the repo directory.' } `
            -Apply {
                Write-SloLog 'git may prompt for credentials; they are not logged'
                Invoke-Git @('clone', '--no-checkout', '--config', 'core.autocrlf=false', $RepositoryUrl, $P.Repo) -Interactive | Out-Null
            } | Out-Null
        $created = $true
    }

    $commit = $null
    foreach ($candidate in @("refs/remotes/origin/$Ref", "refs/tags/$Ref", $Ref)) {
        $r = Invoke-Git @('-C', $P.Repo, 'rev-parse', '--verify', '--quiet', "$candidate^{commit}") -AllowFailure
        if ($r.ExitCode -eq 0 -and $r.Text.Trim()) { $commit = $r.Text.Trim(); break }
    }
    if (-not $commit) { throw "Ref '$Ref' was not found in $RepositoryUrl (tried branch, tag and commit). Note: this repository has no 'main' branch." }

    $populated = Test-Path -LiteralPath (Join-Path $P.Repo 'src\index.php')
    if ($before -eq $commit -and $populated -and $localChanges.Count -eq 0) {
        Add-SloSkip -Kind 'git.checkout' -Resource "git:$($P.Repo)" -Description "repository already at $commit"
    } else {
        $desc = "force check out $Ref ($commit)"
        if ($localChanges.Count -gt 0) { $desc += " and discard $($localChanges.Count) local change(s)" }
        Invoke-SloChange -Kind 'git.checkout' -Resource "git:$($P.Repo)" -Description $desc `
            -Ownership $(if ($created) { 'created' } else { 'modified' }) `
            -Before ([pscustomobject]@{ commit = $before; discardedLocalChanges = @($localChanges) }) -After ([pscustomobject]@{ commit = $commit }) `
            -Inverse @{ type = 'git.restore'; repo = $P.Repo; before = $before; after = $commit } `
            -Apply {
                Invoke-Git @('-C', $P.Repo, 'checkout', '--force', '--detach', '--quiet', $commit) | Out-Null
                Invoke-Git @('-C', $P.Repo, 'reset', '--hard', '--quiet', $commit) | Out-Null
                Invoke-Git @('-C', $P.Repo, 'clean', '-ffdxq') | Out-Null
            } | Out-Null
    }
    if (-not (Test-SloPlanMode)) {
        $head = (Invoke-Git @('-C', $P.Repo, 'rev-parse', 'HEAD')).Text.Trim()
        if ($head -ne $commit) { throw "Checkout verification failed: HEAD is $head, expected $commit." }
        $left = (Invoke-Git @('-C', $P.Repo, 'status', '--porcelain', '--untracked-files=all')).Text.Trim()
        if ($left) { throw "Checkout verification failed: $($P.Repo) still differs from $commit after the reset: $left" }
    }
    $script:SourceInfo = [pscustomobject]@{
        mode       = 'git'
        url        = (Protect-SloText $RepositoryUrl)
        ref        = $Ref
        commit     = $commit
        describe   = (Invoke-Git @('-C', $P.Repo, 'describe', '--always', '--tags', '--dirty') -AllowFailure).Text.Trim()
        commitDate = (Invoke-Git @('-C', $P.Repo, 'log', '-1', '--format=%cI') -AllowFailure).Text.Trim()
    }
    Write-SloLog "source verified: $($script:SourceInfo.commit) ($($script:SourceInfo.describe))" 'OK'
}

function Get-TreeDigest([string]$Root) {
    $rootFull = (Resolve-Path -LiteralPath $Root).ProviderPath.TrimEnd('\')
    $lines = @(Get-ChildItem -LiteralPath $Root -Recurse -File -Force | Sort-Object FullName | ForEach-Object {
        '{0}:{1}' -f $_.FullName.Substring($rootFull.Length + 1), (Get-SloFileHash $_.FullName)
    })
    $bytes = [System.Text.Encoding]::UTF8.GetBytes(($lines -join "`n"))
    return [System.BitConverter]::ToString((New-Object System.Security.Cryptography.SHA256Managed).ComputeHash($bytes)).Replace('-', '')
}

function Step-LocalSource {
    Step-Directory $P.Repo @{ recurse = $true }
    $digest = Get-TreeDigest $LocalSourcePath
    $last = Get-SloLastLiveOperation -Kind 'source.copy' -Resource "source:$($P.Repo)"
    if ($last -and $last.after.digest -eq $digest -and (Test-Path -LiteralPath (Join-Path $P.Repo 'src\index.php'))) {
        Add-SloSkip -Kind 'source.copy' -Resource "source:$($P.Repo)" -Description "local source already copied (digest $digest)"
    } else {
        Invoke-SloChange -Kind 'source.copy' -Resource "source:$($P.Repo)" -Description "mirror $LocalSourcePath into $($P.Repo)" -Ownership 'modified' `
            -Before ([pscustomobject]@{ digest = $(if ($last) { $last.after.digest } else { $null }) }) -After ([pscustomobject]@{ digest = $digest }) `
            -Inverse @{ type = 'none'; note = 'The repo folder holds a copy of the source only; web files are restored by the deployment record.' } -Reversibility 'partial' `
            -Apply {
                $r = Invoke-SloNative -FilePath "$env:windir\System32\robocopy.exe" -Arguments @($LocalSourcePath.TrimEnd('\'), $P.Repo, '/MIR', '/R:1', '/W:1', '/NFL', '/NDL', '/NP', '/XD', '.git') -AllowFailure
                if ($r.ExitCode -ge 8) { throw "robocopy failed with exit code $($r.ExitCode)" }
            } | Out-Null
    }
    $script:SourceInfo = [pscustomobject]@{ mode = 'local'; path = $LocalSourcePath; digest = $digest; commit = "local:$digest" }
}

function Step-Php {
    $target = [string]$Sources.sources.php.version
    $majorMinor = ($target.Split('.')[0..1] -join '')
    $existingVersion = Get-PhpVersionOf $P.PhpExe
    $ours = Test-SloInstallerCreated -Resource "dir:$($P.Php)"
    if ($existingVersion -eq $target -and $ours) {
        Add-SloSkip -Kind 'php.install' -Resource "dir:$($P.Php)" -Description "PHP $target already installed at $($P.Php)"
        return
    }
    if ((Test-Path -LiteralPath $P.Php) -and -not $ours) { throw "$($P.Php) exists and is not installer-owned." }

    $phpSrc = Get-SloSourceFile -Key 'php' -Sources $Sources -SourceCache $SourceCache -TrustedHashes $TrustedHashes -NonInteractive:$NonInteractive
    $sqlSrc = Get-SloSourceFile -Key 'pdo_sqlsrv' -Sources $Sources -SourceCache $SourceCache -TrustedHashes $TrustedHashes -NonInteractive:$NonInteractive
    $yamlSrc = Get-SloSourceFile -Key 'yaml' -Sources $Sources -SourceCache $SourceCache -TrustedHashes $TrustedHashes -NonInteractive:$NonInteractive
    $caSrc = Get-SloSourceFile -Key 'cacert' -Sources $Sources -SourceCache $SourceCache -TrustedHashes $TrustedHashes -NonInteractive:$NonInteractive
    $script:PhpSources = @($phpSrc, $sqlSrc, $yamlSrc, $caSrc) | Where-Object { $_ }
    if (Test-SloPlanMode) {
        Write-SloLog ("would install PHP {0} to {1} with pdo_sqlsrv {2}, yaml {3}" -f $target, $P.Php, $Sources.sources.pdo_sqlsrv.version, $Sources.sources.yaml.version) 'PLAN'
        return
    }

    $run = Get-SloRun
    $stage = Join-Path $P.Root (".php-staging-" + $run.runId)
    $work = Join-Path $run.runDir 'staging'
    try {
        Expand-SloZip -ZipPath $phpSrc.path -Destination $stage
        if (-not (Test-Path -LiteralPath (Join-Path $stage 'php-cgi.exe'))) { throw 'php-cgi.exe not found in the PHP archive.' }
        if (-not (Test-Path -LiteralPath (Join-Path $stage 'php.ini-production'))) { throw 'php.ini-production not found in the PHP archive.' }

        Expand-SloZip -ZipPath $sqlSrc.path -Destination (Join-Path $work 'pdo_sqlsrv')
        $dllName = ([string]$Sources.sources.pdo_sqlsrv.dllPattern).Replace('{phpMajorMinor}', $majorMinor)
        $dll = @(Get-ChildItem -LiteralPath (Join-Path $work 'pdo_sqlsrv') -Recurse -File -Filter $dllName)
        if ($dll.Count -eq 0) { throw "pdo_sqlsrv $($sqlSrc.version) does not contain $dllName for PHP $target." }
        Copy-Item -LiteralPath $dll[0].FullName -Destination (Join-Path $stage 'ext\php_pdo_sqlsrv.dll') -Force

        Expand-SloZip -ZipPath $yamlSrc.path -Destination (Join-Path $work 'yaml')
        $yamlDll = @(Get-ChildItem -LiteralPath (Join-Path $work 'yaml') -Recurse -File -Filter 'php_yaml.dll')
        if ($yamlDll.Count -eq 0) { throw 'php_yaml.dll not found in the yaml archive.' }
        Copy-Item -LiteralPath $yamlDll[0].FullName -Destination (Join-Path $stage 'ext\php_yaml.dll') -Force
        foreach ($dep in @(Get-ChildItem -LiteralPath (Join-Path $work 'yaml') -Recurse -File -Filter '*.dll' | Where-Object { $_.Name -notlike 'php_*' })) {
            # libyaml (yaml.dll) must sit beside php-cgi.exe so Windows can load it.
            Copy-Item -LiteralPath $dep.FullName -Destination (Join-Path $stage $dep.Name) -Force
        }
        New-Item -ItemType Directory -Path (Join-Path $stage 'extras\ssl') -Force | Out-Null
        Copy-Item -LiteralPath $caSrc.path -Destination (Join-Path $stage 'extras\ssl\cacert.pem') -Force

        $markerHash = Get-SloFileHash (Join-Path $stage 'php-cgi.exe')
        if (Test-Path -LiteralPath $P.Php) {
            $previous = Join-Path (Get-SloRunBackupDir) 'php-previous'
            Invoke-SloChange -Kind 'php.replace' -Resource "php:$($P.Php)" -Description "upgrade PHP $existingVersion -> $target at $($P.Php)" -Ownership 'modified' `
                -Before ([pscustomobject]@{ version = $existingVersion; backup = $previous }) -After ([pscustomobject]@{ version = $target; phpCgiSha256 = $markerHash }) `
                -Backup $previous -Inverse @{ type = 'php.restore'; path = $P.Php; previous = $previous; afterHash = $markerHash } `
                -Apply {
                    # php-cgi.exe processes hold the DLLs open; stop this site's pool (restarted later by the smoke test).
                    if ((Get-Module WebAdministration) -and (Test-Path -Path "IIS:\AppPools\$AppPoolName") -and (Get-WebAppPoolState -Name $AppPoolName).Value -ne 'Stopped') {
                        Stop-WebAppPool -Name $AppPoolName
                        Start-Sleep -Seconds 5
                    }
                    Copy-Item -LiteralPath $P.Php -Destination $previous -Recurse -Force
                    Remove-Item -LiteralPath $P.Php -Recurse -Force
                    Rename-Item -LiteralPath $stage -NewName (Split-Path -Leaf $P.Php)
                } | Out-Null
        } else {
            Invoke-SloChange -Kind 'php.install' -Resource "dir:$($P.Php)" -Description "install PHP $target to $($P.Php)" -Ownership 'created' `
                -Before ([pscustomobject]@{ exists = $false }) -After ([pscustomobject]@{ version = $target; phpCgiSha256 = $markerHash }) `
                -Inverse @{ type = 'dir.remove'; path = $P.Php; recurse = $true; guardPhp = $true } `
                -Apply { Rename-Item -LiteralPath $stage -NewName (Split-Path -Leaf $P.Php) } | Out-Null
        }
    } finally {
        if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue }
        if (Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

function Get-DesiredPhpIni {
    $production = [System.IO.File]::ReadAllText((Join-Path $P.Php 'php.ini-production'))
    $q = { param($v) '"' + $v + '"' }
    $block = @(
        '',
        '; =====================================================================',
        '; SLO Cloud 5 managed settings - written by Install-SLOCloud5.ps1.',
        '; Settings below override the php.ini-production defaults above.',
        '; Local edits are detected on the next installer run (see -OverwritePhpIni).',
        '; =====================================================================',
        ('extension_dir = ' + (& $q (Join-Path $P.Php 'ext'))),
        'max_execution_time = 900',
        'max_input_time = 900',
        'memory_limit = 512M',
        'upload_max_filesize = 64M',
        'post_max_size = 64M',
        'max_input_vars = 3000',
        ('upload_tmp_dir = ' + (& $q $P.DataTmp)),
        ('sys_temp_dir = ' + (& $q $P.DataTmp)),
        ('date.timezone = ' + $TimeZone),
        'session.gc_maxlifetime = 28800',
        'session.cookie_httponly = 1',
        'session.use_strict_mode = 1',
        'display_errors = Off',
        'display_startup_errors = Off',
        'log_errors = On',
        ('error_log = ' + (& $q (Join-Path $P.DataLogs 'php_errors.log'))),
        'expose_php = Off',
        'allow_url_fopen = Off',
        'cgi.force_redirect = 0',
        'cgi.fix_pathinfo = 1',
        'fastcgi.impersonate = 1',
        'fastcgi.logging = 0',
        ('curl.cainfo = ' + (& $q $P.CaBundle)),
        ('openssl.cafile = ' + (& $q $P.CaBundle)),
        'extension = pdo_sqlsrv',
        'extension = yaml',
        'extension = openssl',
        'extension = mbstring',
        'extension = curl',
        'extension = fileinfo',
        'zend_extension = opcache',
        'opcache.enable = 1',
        'opcache.enable_cli = 0',
        'opcache.memory_consumption = 128',
        ''
    ) -join "`r`n"
    return ($production.TrimEnd() + "`r`n" + $block)
}

function Step-PhpIni {
    if ((Test-SloPlanMode) -and -not (Test-Path -LiteralPath (Join-Path $P.Php 'php.ini-production'))) {
        Write-SloLog "would write $($P.PhpIni) (php.ini-production + SLO Cloud 5 settings, timezone $TimeZone)" 'PLAN'
        return
    }
    $desired = Get-DesiredPhpIni
    $desiredHash = [System.BitConverter]::ToString((New-Object System.Security.Cryptography.SHA256Managed).ComputeHash((New-Object System.Text.UTF8Encoding($false)).GetBytes($desired))).Replace('-', '')
    $exists = Test-Path -LiteralPath $P.PhpIni
    $cur = if ($exists) { Get-SloFileHash $P.PhpIni } else { $null }
    if ($cur -eq $desiredHash) { Add-SloSkip -Kind 'file.write' -Resource "file:$($P.PhpIni)" -Description 'php.ini already as required'; return }
    $last = Get-SloLastLiveOperation -Kind 'file.write' -Resource "file:$($P.PhpIni)"
    if ($exists -and -not ($last -and $last.after.sha256 -eq $cur) -and -not $OverwritePhpIni) {
        throw "$($P.PhpIni) was edited after the installer wrote it. Review the change, then rerun with -OverwritePhpIni (the current file is backed up first)."
    }
    $backup = if ($exists) { Backup-SloFile -Path $P.PhpIni -Name 'php\php.ini' } else { $null }
    Invoke-SloChange -Kind 'file.write' -Resource "file:$($P.PhpIni)" -Description "write $($P.PhpIni)" -Ownership $(if ($exists) { 'modified' } else { 'created' }) `
        -Before ([pscustomobject]@{ exists = $exists; sha256 = $cur }) -After ([pscustomobject]@{ sha256 = $desiredHash }) -Backup $backup `
        -Inverse @{ type = 'file.restore'; path = $P.PhpIni; existedBefore = $exists; backup = $backup; beforeHash = $cur; afterHash = $desiredHash } `
        -Apply { Write-SloTextFileNoBom -Path $P.PhpIni -Content $desired } | Out-Null
}

function Test-PhpCli {
    if (Test-SloPlanMode) { return }
    $m = Invoke-SloNative -FilePath $P.PhpExe -Arguments @('-c', $P.PhpIni, '-m') -AllowFailure -Quiet
    if ($m.Text -match 'Unable to load dynamic library|PHP Startup|Warning') {
        throw ("PHP reported startup problems:{0}{1}{0}pdo_sqlsrv needs the ODBC driver and the VC++ runtime; yaml needs yaml.dll beside php.exe." -f [Environment]::NewLine, $m.Text)
    }
    $loaded = @($m.Output | ForEach-Object { $_.Trim() } | Where-Object { $_ -and $_ -notmatch '^\[' })
    $missing = @($RequiredPhpModules | Where-Object { $loaded -notcontains $_ })
    if ($missing.Count -gt 0) { throw "PHP CLI is missing required modules: $($missing -join ', ')" }
    $argon = (Invoke-SloNative -FilePath $P.PhpExe -Arguments @('-c', $P.PhpIni, '-r', "echo defined('PASSWORD_ARGON2ID') ? 'yes' : 'no';") -Quiet).Text.Trim()
    if ($argon -ne 'yes') { throw 'PHP build lacks PASSWORD_ARGON2ID (required by the application).' }
    $script:PhpModules = $loaded
    Write-SloLog ("PHP CLI OK: {0} modules, Argon2id available" -f $loaded.Count) 'OK'
}

function Step-MachineEnvironment {
    Set-SloMachineEnv -Name 'SLOCLOUD_DATA_PATH' -Value $P.Data
    Set-SloMachineEnv -Name 'SLOCLOUD_ENV' -Value 'production'
    Add-SloMachinePathEntry -Entry $P.Php
    $other = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($other -and -not (Test-UnderPath $other.Source $P.Php)) { Add-SloWarning "Another php.exe ($($other.Source)) appears earlier on PATH. CLI scripts should be run as `"$($P.PhpExe)`"." }
}

function Set-PoolProperty([string]$Name, $Value) {
    $path = "IIS:\AppPools\$AppPoolName"
    $cur = Get-SloIisItemValue -Path $path -Name $Name
    if ((ConvertTo-SloComparable $cur) -eq (ConvertTo-SloComparable $Value)) { return }
    Invoke-SloChange -Kind 'iis.item.set' -Resource "iis.apppool:$AppPoolName/$Name" -Description "app pool $AppPoolName $Name = '$Value'" -Ownership 'modified' `
        -Before ([pscustomobject]@{ value = $cur }) -After ([pscustomobject]@{ value = $Value }) `
        -Inverse @{ type = 'iis.item.restore'; path = $path; name = $Name; before = $cur; after = $Value } `
        -Apply { Set-ItemProperty -Path $path -Name $Name -Value $Value } | Out-Null
}

function Step-AppPool {
    if ((Test-SloPlanMode) -and -not (Get-Module WebAdministration)) { Write-SloLog "would create application pool $AppPoolName (No Managed Code, Integrated, ApplicationPoolIdentity)" 'PLAN'; return }
    Import-SloWebAdministration
    $path = "IIS:\AppPools\$AppPoolName"
    if (-not (Test-Path -Path $path)) {
        Invoke-SloChange -Kind 'iis.apppool.create' -Resource "iis.apppool:$AppPoolName" -Description "create application pool $AppPoolName" -Ownership 'created' `
            -Inverse @{ type = 'iis.apppool.remove'; name = $AppPoolName } `
            -Apply {
                New-WebAppPool -Name $AppPoolName | Out-Null
                Set-ItemProperty -Path $path -Name managedRuntimeVersion -Value ''
                Set-ItemProperty -Path $path -Name managedPipelineMode -Value 'Integrated'
                Set-ItemProperty -Path $path -Name processModel.identityType -Value 'ApplicationPoolIdentity'
                Set-ItemProperty -Path $path -Name enable32BitAppOnWin64 -Value $false
                Set-ItemProperty -Path $path -Name recycling.periodicRestart.time -Value ([TimeSpan]::Zero)
                Clear-ItemProperty -Path $path -Name recycling.periodicRestart.schedule
                Set-ItemProperty -Path $path -Name recycling.periodicRestart.schedule -Value @{ value = '03:00:00' }
            } -GetAfter { [pscustomobject]@{ identity = "IIS AppPool\$AppPoolName" } } | Out-Null
    } else {
        Set-PoolProperty 'managedRuntimeVersion' ''
        Set-PoolProperty 'managedPipelineMode' 'Integrated'
        Set-PoolProperty 'processModel.identityType' 'ApplicationPoolIdentity'
        Set-PoolProperty 'enable32BitAppOnWin64' $false
    }
    if (Test-SloPlanMode) { return }
    $deadline = (Get-Date).AddSeconds(20)
    while ($true) {
        try { $script:PoolSid = (Resolve-SloSid "IIS AppPool\$AppPoolName").Value; break } catch {
            if ((Get-Date) -gt $deadline) { throw "The identity IIS AppPool\$AppPoolName cannot be resolved." }
            Start-Sleep -Milliseconds 500
        }
    }
}

function Step-Acls {
    $pool = "IIS AppPool\$AppPoolName"
    if ((Test-SloPlanMode) -and -not $script:PoolSid) {
        Write-SloLog "would set ACLs: root admin-only plus RX on the folder itself for $pool; repo admin-only; web RX; data and backup Modify; secrets Modify during setup; php RX for $pool" 'PLAN'
        return
    }
    $created = { param($p) Test-SloInstallerCreated -Resource "dir:$p" }
    # PHP's realpath() on Windows lists every parent folder, so the app pool needs read & execute
    # on the root itself (not inherited) to resolve paths under it, such as the secrets folder.
    Set-SloDirectoryAcl -Path $P.Root -Exact -Rules @(@{ Identity = $pool; Rights = 'ReadAndExecute'; FolderOnly = $true }) -DirectoryCreatedByInstaller:(& $created $P.Root) -Purpose 'install root: SYSTEM + Administrators, inheritance disabled; app pool read & execute on this folder only' | Out-Null
    if (Test-Path -LiteralPath $P.Repo) { Set-SloDirectoryAcl -Path $P.Repo -Exact -DirectoryCreatedByInstaller:(& $created $P.Repo) -Purpose 'repo: not readable by the web server' | Out-Null }
    if (Test-Path -LiteralPath $P.Php) { Set-SloDirectoryAcl -Path $P.Php -Exact -Rules @(@{ Identity = $pool; Rights = 'ReadAndExecute' }) -DirectoryCreatedByInstaller:(& $created $P.Php) -Purpose 'php: read/execute for the app pool' | Out-Null }
    Set-SloDirectoryAcl -Path $P.Web -Exact -Rules @(@{ Identity = $pool; Rights = 'ReadAndExecute' }) -DirectoryCreatedByInstaller:(& $created $P.Web) -Purpose 'web root: read/execute only' | Out-Null
    Set-SloDirectoryAcl -Path $P.Data -Exact -Rules @(@{ Identity = $pool; Rights = 'Modify' }) -DirectoryCreatedByInstaller:(& $created $P.Data) -Purpose 'data: sessions, logs, reports, locks' | Out-Null
    Set-SloDirectoryAcl -Path $P.Backup -Exact -Rules @(@{ Identity = $pool; Rights = 'Modify' }) -DirectoryCreatedByInstaller:(& $created $P.Backup) -Purpose 'backup: written by the application snapshot function' | Out-Null
    if (-not (Test-SetupComplete)) {
        Set-SloDirectoryAcl -Path $P.Secrets -Exact -Rules @(@{ Identity = $pool; Rights = 'Modify' }) -DirectoryCreatedByInstaller:(& $created $P.Secrets) -Purpose 'secrets: temporary write so the setup wizard can create key.yaml' | Out-Null
    }
}

function Step-WebConfigAcl {
    if (-not (Test-Path -LiteralPath $P.WebConfig)) { if (Test-SloPlanMode) { Write-SloLog "would grant Modify on $($P.WebConfig) to the app pool" 'PLAN' }; return }
    Set-SloDirectoryAcl -Path $P.WebConfig -Rules @(@{ Identity = "IIS AppPool\$AppPoolName"; Rights = 'Modify' }) -RestoreOnUndo `
        -Purpose 'web\config: setup wizard, admin Configuration page and key rotation write config.yaml here' | Out-Null
}

function Step-DefaultWebSiteConflict {
    if ((Test-SloPlanMode) -and -not (Get-Module WebAdministration)) { return }
    Import-SloWebAdministration
    foreach ($b in (Get-SloAllSiteBindings)) {
        if ($b.site -eq $SiteName) { continue }
        $bp = Get-BindingParts $b.bindingInformation
        if (-not ($b.protocol -eq 'https' -and $bp.port -eq '443' -and $bp.host -ieq 'localhost')) { continue }
        if ($b.site -ne 'Default Web Site') { throw "Site '$($b.site)' owns https localhost:443." }
        $site = Get-SloSiteSummary -Name 'Default Web Site'
        if ($site.state -eq 'Stopped' -and -not $site.serverAutoStart) { return }
        if (Test-SloPlanMode) { Write-SloLog 'would stop Default Web Site and disable its auto-start (localhost:443 conflict); undo restores it' 'PLAN'; return }
        if (-not $AllowDefaultWebSiteStop -and -not (Confirm-Typed "Default Web Site has an https localhost:443 binding and must be stopped (not deleted) for $SiteName. Undo restores its state." 'STOP')) {
            throw 'Default Web Site conflict not approved.'
        }
        Invoke-SloChange -Kind 'iis.site.state' -Resource 'iis.site:Default Web Site/state' -Description 'stop Default Web Site and disable its auto-start (binding conflict on localhost:443)' -Ownership 'modified' `
            -Before ([pscustomobject]@{ state = $site.state; serverAutoStart = $site.serverAutoStart; bindings = $site.bindings }) -After ([pscustomobject]@{ state = 'Stopped'; serverAutoStart = $false }) `
            -Inverse @{ type = 'iis.siteState.restore'; name = 'Default Web Site'; beforeState = $site.state; beforeAutoStart = $site.serverAutoStart; afterState = 'Stopped'; afterAutoStart = $false } `
            -Apply {
                Set-ItemProperty -Path 'IIS:\Sites\Default Web Site' -Name serverAutoStart -Value $false
                if ((Get-Website -Name 'Default Web Site').State -ne 'Stopped') { Stop-Website -Name 'Default Web Site' }
            } | Out-Null
        return
    }
}

function Step-FastCgi {
    if ((Test-SloPlanMode) -and -not (Get-Module WebAdministration)) { Write-SloLog "would register FastCGI application $($P.PhpCgi)" 'PLAN'; return }
    Import-SloWebAdministration
    $apphost = Get-SloApphost
    $fp = $P.PhpCgi
    $appFilter = "system.webServer/fastCgi/application[@fullPath='$fp']"
    $exists = @(Get-SloFastCgiApps | Where-Object { $_.fullPath -ieq $fp }).Count -gt 0
    if (-not $exists) {
        Invoke-SloChange -Kind 'iis.fastcgi.create' -Resource "iis.fastcgi:$fp" -Description "register FastCGI application $fp" -Ownership 'created' `
            -Inverse @{ type = 'iis.fastcgi.remove'; fullPath = $fp } `
            -Apply {
                Add-WebConfigurationProperty -PSPath $apphost -Filter 'system.webServer/fastCgi' -Name '.' -Value @{
                    fullPath = $fp; arguments = ''; maxInstances = 0; instanceMaxRequests = 10000
                    activityTimeout = 3600; requestTimeout = 3600; idleTimeout = 300; queueLength = 1000
                }
            } | Out-Null
    } else {
        foreach ($kv in @(@('activityTimeout', 3600), @('requestTimeout', 3600), @('idleTimeout', 300), @('instanceMaxRequests', 10000), @('queueLength', 1000))) {
            Set-ConfigValue -Location '' -Filter $appFilter -Name $kv[0] -Value $kv[1] -Resource "iis.fastcgi:$fp/$($kv[0])"
        }
    }
    $vars = [ordered]@{
        PHP_FCGI_MAX_REQUESTS = '10000'
        PHPRC                 = $P.Php
        SLOCLOUD_DATA_PATH    = $P.Data
        SLOCLOUD_ENV          = 'production'
        SLOCLOUD_FORCE_HTTPS  = 'true'
    }
    foreach ($n in $vars.Keys) {
        $v = $vars[$n]
        $varFilter = "$appFilter/environmentVariables/environmentVariable[@name='$n']"
        $cur = if ((Test-SloPlanMode) -and -not $exists) { $null } else { Get-WebConfiguration -PSPath $apphost -Filter $varFilter }
        if ($cur -and [string]$cur.value -eq $v) { Add-SloSkip -Kind 'iis.fastcgi.env' -Resource "iis.fastcgi:$fp/env/$n" -Description "FastCGI $n already set"; continue }
        $beforeValue = if ($cur) { [string]$cur.value } else { $null }
        Invoke-SloChange -Kind 'iis.fastcgi.env' -Resource "iis.fastcgi:$fp/env/$n" -Description "FastCGI environment $n=$v" -Ownership $(if ($cur) { 'modified' } else { 'created' }) `
            -Before ([pscustomobject]@{ value = $beforeValue }) -After ([pscustomobject]@{ value = $v }) `
            -Inverse @{ type = 'iis.fastcgi.envRemove'; fullPath = $fp; name = $n; existedBefore = [bool]$cur; beforeValue = $beforeValue; afterValue = $v } `
            -Apply {
                if ($cur) { Set-WebConfigurationProperty -PSPath $apphost -Filter $varFilter -Name value -Value $v }
                else { Add-WebConfigurationProperty -PSPath $apphost -Filter "$appFilter/environmentVariables" -Name '.' -Value @{ name = $n; value = $v } }
            } | Out-Null
    }
}

function Set-ConfigValue([string]$Location, [string]$Filter, [string]$Name, $Value, [string]$Resource) {
    $cur = Get-SloWebConfigValue -Location $Location -Filter $Filter -Name $Name
    if ((ConvertTo-SloComparable $cur) -eq (ConvertTo-SloComparable $Value)) {
        Add-SloSkip -Kind 'iis.config.set' -Resource $Resource -Description "$Filter/@$Name already '$Value'"
        return
    }
    Invoke-SloChange -Kind 'iis.config.set' -Resource $Resource -Description "set $Filter/@$Name = '$Value' at '$Location'" -Ownership 'modified' `
        -Before ([pscustomobject]@{ value = $cur }) -After ([pscustomobject]@{ value = $Value }) `
        -Inverse @{ type = 'iis.config.restore'; location = $Location; filter = $Filter; name = $Name; before = $cur; after = $Value } `
        -Apply { Set-SloWebConfigValue -Location $Location -Filter $Filter -Name $Name -Value $Value } | Out-Null
}

function Step-Site {
    if ((Test-SloPlanMode) -and -not (Get-Module WebAdministration)) {
        Write-SloLog "would create IIS site $SiteName at $($P.Web) with binding https *:443:localhost (SNI), PHP handler, anonymous auth as app pool identity, 64 MB request limit" 'PLAN'
        return
    }
    Import-SloWebAdministration
    $apphost = Get-SloApphost
    $site = Get-SloSiteSummary -Name $SiteName
    $bindingInfo = '*:443:localhost'
    if (-not $site) {
        $nextId = 1 + [int](@(Get-ChildItem IIS:\Sites | ForEach-Object { [int]$_.id }) + 0 | Measure-Object -Maximum).Maximum
        Invoke-SloChange -Kind 'iis.site.create' -Resource "iis.site:$SiteName" -Description "create IIS site $SiteName (id $nextId) at $($P.Web), binding https/$bindingInfo" -Ownership 'created' `
            -Inverse @{ type = 'iis.site.remove'; name = $SiteName; physicalPath = $P.Web } `
            -Apply {
                New-Website -Name $SiteName -Id $nextId -PhysicalPath $P.Web -ApplicationPool $AppPoolName -Port 443 -HostHeader 'localhost' -Ssl -SslFlags 1 | Out-Null
                Set-ItemProperty -Path "IIS:\Sites\$SiteName" -Name serverAutoStart -Value $true
            } -GetAfter { Get-SloSiteSummary -Name $SiteName } | Out-Null
    } else {
        if ($site.physicalPath -ne $P.Web) {
            Invoke-SloChange -Kind 'iis.item.set' -Resource "iis.site:$SiteName/physicalPath" -Description "site $SiteName physicalPath = $($P.Web)" -Ownership 'modified' `
                -Before ([pscustomobject]@{ value = $site.physicalPath }) -After ([pscustomobject]@{ value = $P.Web }) `
                -Inverse @{ type = 'iis.item.restore'; path = "IIS:\Sites\$SiteName"; name = 'physicalPath'; before = $site.physicalPath; after = $P.Web } `
                -Apply { Set-ItemProperty -Path "IIS:\Sites\$SiteName" -Name physicalPath -Value $P.Web } | Out-Null
        }
        if ($site.applicationPool -ne $AppPoolName) {
            Invoke-SloChange -Kind 'iis.item.set' -Resource "iis.site:$SiteName/applicationPool" -Description "site $SiteName applicationPool = $AppPoolName" -Ownership 'modified' `
                -Before ([pscustomobject]@{ value = $site.applicationPool }) -After ([pscustomobject]@{ value = $AppPoolName }) `
                -Inverse @{ type = 'iis.item.restore'; path = "IIS:\Sites\$SiteName"; name = 'applicationPool'; before = $site.applicationPool; after = $AppPoolName } `
                -Apply { Set-ItemProperty -Path "IIS:\Sites\$SiteName" -Name applicationPool -Value $AppPoolName } | Out-Null
        }
        if (@($site.bindings) -notcontains "https/$bindingInfo") {
            Invoke-SloChange -Kind 'iis.binding.add' -Resource "iis.binding:$SiteName/https/$bindingInfo" -Description "add binding https/$bindingInfo (SNI) to $SiteName" -Ownership 'created' `
                -Inverse @{ type = 'iis.binding.remove'; site = $SiteName; protocol = 'https'; bindingInformation = $bindingInfo } `
                -Apply { New-WebBinding -Name $SiteName -Protocol https -Port 443 -HostHeader 'localhost' -SslFlags 1 } | Out-Null
        } else {
            Add-SloSkip -Kind 'iis.binding.add' -Resource "iis.binding:$SiteName/https/$bindingInfo" -Description "binding https/$bindingInfo present"
        }
    }
    if ((Test-SloPlanMode) -and -not (Get-SloSiteSummary -Name $SiteName)) {
        Write-SloLog "would configure anonymous auth (app pool identity), 64 MB request limit and the *.php handler at $SiteName" 'PLAN'
        return
    }
    Set-ConfigValue -Location $SiteName -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name 'enabled' -Value $true -Resource "iis.config:$SiteName/anonymousAuthentication/enabled"
    Set-ConfigValue -Location $SiteName -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name 'userName' -Value '' -Resource "iis.config:$SiteName/anonymousAuthentication/userName"
    Set-ConfigValue -Location $SiteName -Filter 'system.webServer/security/requestFiltering/requestLimits' -Name 'maxAllowedContentLength' -Value 67108864 -Resource "iis.config:$SiteName/requestLimits/maxAllowedContentLength"

    $handlerFilter = "system.webServer/handlers/add[@name='$PhpHandlerName']"
    $existing = @(Get-WebConfiguration -PSPath $apphost -Location $SiteName -Filter $handlerFilter)
    if ($existing.Count -eq 0) {
        Invoke-SloChange -Kind 'iis.handler.add' -Resource "iis.handler:$SiteName/$PhpHandlerName" -Description "map *.php to FastCGI ($($P.PhpCgi)) for $SiteName only" -Ownership 'created' `
            -Inverse @{ type = 'iis.handler.remove'; location = $SiteName; name = $PhpHandlerName } `
            -Apply {
                Add-WebConfigurationProperty -PSPath $apphost -Location $SiteName -Filter 'system.webServer/handlers' -Name '.' -AtIndex 0 -Value @{
                    name = $PhpHandlerName; path = '*.php'; verb = 'GET,HEAD,POST'; modules = 'FastCgiModule'
                    scriptProcessor = $P.PhpCgi; resourceType = 'File'; requireAccess = 'Script'
                }
            } | Out-Null
    } elseif ([string]$existing[0].scriptProcessor -ne $P.PhpCgi) {
        Set-ConfigValue -Location $SiteName -Filter $handlerFilter -Name 'scriptProcessor' -Value $P.PhpCgi -Resource "iis.handler:$SiteName/$PhpHandlerName/scriptProcessor"
    } else {
        Add-SloSkip -Kind 'iis.handler.add' -Resource "iis.handler:$SiteName/$PhpHandlerName" -Description 'PHP handler present'
    }
}

function Step-Certificate {
    $last = Get-SloLastLiveOperation -Kind 'cert.create' -Resource 'cert:localhost'
    if ($last -and $last.after -and $last.after.thumbprint) {
        $c = Get-Item -LiteralPath ("Cert:\LocalMachine\My\" + $last.after.thumbprint) -ErrorAction SilentlyContinue
        if ($c -and $c.NotAfter -gt (Get-Date).AddDays(30)) {
            Add-SloSkip -Kind 'cert.create' -Resource 'cert:localhost' -Description "reusing self-signed localhost certificate $($c.Thumbprint) (expires $($c.NotAfter.ToString('yyyy-MM-dd')))"
            $script:Thumbprint = $c.Thumbprint.ToUpperInvariant()
            return
        }
    }
    $run = Get-SloRun
    $friendly = "SLO Cloud 5 localhost (self-signed) $($run.runId)"
    $op = Invoke-SloChange -Kind 'cert.create' -Resource 'cert:localhost' -Description 'create self-signed certificate for localhost (LocalMachine\My, SAN DNS=localhost, non-exportable key)' -Ownership 'created' `
        -Inverse @{ type = 'cert.remove'; thumbprint = $null; friendlyName = $friendly } `
        -Apply {
            New-SelfSignedCertificate -DnsName 'localhost' -CertStoreLocation 'Cert:\LocalMachine\My' -FriendlyName $friendly `
                -KeyAlgorithm RSA -KeyLength 2048 -HashAlgorithm SHA256 -KeyExportPolicy NonExportable `
                -KeyUsage DigitalSignature, KeyEncipherment -NotAfter (Get-Date).AddYears(2) `
                -TextExtension @('2.5.29.37={text}1.3.6.1.5.5.7.3.1') | Out-Null
        } `
        -GetAfter {
            $c = @(Get-ChildItem Cert:\LocalMachine\My | Where-Object { $_.FriendlyName -eq $friendly })[0]
            [pscustomobject]@{ thumbprint = $c.Thumbprint.ToUpperInvariant(); subject = $c.Subject; notAfter = $c.NotAfter.ToString('o'); san = 'DNS=localhost' }
        }
    if (Test-SloPlanMode) { $script:Thumbprint = $null; return }
    $op.inverse.thumbprint = $op.after.thumbprint
    Save-SloJournal
    $script:Thumbprint = $op.after.thumbprint
}

function Step-SslBinding {
    if ((Test-SloPlanMode) -and -not $script:Thumbprint) { Write-SloLog 'would bind the certificate to HTTP.sys hostnameport=localhost:443' 'PLAN'; return }
    $hp = 'localhost:443'
    $entry = @(Get-SloHttpSysSslBindings | Where-Object { $_.type -eq 'hostnameport' -and $_.key -ieq $hp })
    if ($entry.Count -gt 0 -and $entry[0].certHash -eq $script:Thumbprint) { Add-SloSkip -Kind 'iis.sslcert.add' -Resource "sslcert:$hp" -Description "HTTP.sys $hp bound to $($script:Thumbprint)"; return }
    $netsh = "$env:windir\System32\netsh.exe"
    $addArgs = @('http', 'add', 'sslcert', "hostnameport=$hp", "certhash=$($script:Thumbprint)", "appid=$IisAppId", 'certstorename=MY')
    if ($entry.Count -gt 0) {
        $ours = @(Get-SloLiveOperations -Kind 'cert.create' | ForEach-Object { if ($_.after) { [string]$_.after.thumbprint } })
        if ($ours -notcontains $entry[0].certHash) { throw "HTTP.sys binding $hp uses certificate $($entry[0].certHash), which this installer did not create." }
        $old = $entry[0].certHash
        Invoke-SloChange -Kind 'iis.sslcert.replace' -Resource "sslcert:$hp" -Description "rebind $hp from $old to $($script:Thumbprint)" -Ownership 'modified' `
            -Before ([pscustomobject]@{ thumbprint = $old }) -After ([pscustomobject]@{ thumbprint = $script:Thumbprint }) `
            -Inverse @{ type = 'iis.sslcert.rebind'; hostnameport = $hp; before = $old; after = $script:Thumbprint; appId = $IisAppId } `
            -Apply {
                Invoke-SloNative -FilePath $netsh -Arguments @('http', 'delete', 'sslcert', "hostnameport=$hp") | Out-Null
                Invoke-SloNative -FilePath $netsh -Arguments $addArgs | Out-Null
            } | Out-Null
        return
    }
    Invoke-SloChange -Kind 'iis.sslcert.add' -Resource "sslcert:$hp" -Description "bind certificate $($script:Thumbprint) to HTTP.sys hostnameport=$hp" -Ownership 'created' `
        -After ([pscustomobject]@{ thumbprint = $script:Thumbprint }) `
        -Inverse @{ type = 'iis.sslcert.remove'; hostnameport = $hp; thumbprint = $script:Thumbprint } `
        -Apply { Invoke-SloNative -FilePath $netsh -Arguments $addArgs | Out-Null } | Out-Null
}

# Files the setup wizard, the admin UI or key rotation own. Never replaced or deleted by a deploy.
$ProtectedWebFiles = @(
    '^config\\config\.yaml$', '^config\\config\.yaml\.bak$', '^config\\key\.yaml$', '^config\\index\.php$',
    '^config\\\.htaccess$', '^config\\backups\\', '^config\\\.', '^sessions\\(?!README\.md$)'
)
$ExcludedSourceFiles = @('^TODO\.md$', '(^|\\)\.gitignore$', '(^|\\)\.DS_Store$', '(^|\\)Thumbs\.db$', '\.swp$')

function Test-Matches([string]$Rel, [string[]]$Patterns) {
    foreach ($pat in $Patterns) { if ($Rel -match $pat) { return $true } }
    return $false
}

function Step-Deploy {
    $srcRoot = Join-Path $P.Repo 'src'
    if ((Test-SloPlanMode) -and -not (Test-Path -LiteralPath (Join-Path $srcRoot 'index.php'))) {
        Write-SloLog "would deploy $srcRoot to $($P.Web) (config.yaml, key files, config\backups and runtime files preserved)" 'PLAN'
        return
    }
    foreach ($req in @('index.php', 'web.config', 'VERSION', 'setup\index.php', 'system\includes\init.php', 'config\web.config', 'config\config.yaml.template')) {
        if (-not (Test-Path -LiteralPath (Join-Path $srcRoot $req))) { throw "Source is incomplete: $srcRoot\$req is missing." }
    }
    $srcFull = (Resolve-Path -LiteralPath $srcRoot).ProviderPath.TrimEnd('\')
    $source = @{}
    foreach ($f in @(Get-ChildItem -LiteralPath $srcRoot -Recurse -File -Force)) {
        $rel = $f.FullName.Substring($srcFull.Length + 1)
        if (Test-Matches $rel $ExcludedSourceFiles) { continue }
        $source[$rel] = Get-SloFileHash $f.FullName
    }
    $prevManifest = @{}
    $lastDeploy = Get-SloLastLiveOperation -Kind 'web.deploy' -Resource "web:$($P.Web)"
    if ($lastDeploy) {
        $d = Read-SloJsonFile -Path ([string]$lastDeploy.inverse.dataPath)
        if ($d) { foreach ($m in @($d.manifest)) { $prevManifest[$m.rel] = $m.hash } }
    }

    $added = @(); $replaced = @(); $deleted = @(); $conflicts = @(); $keptStale = @(); $unchanged = 0; $preserved = 0
    foreach ($rel in $source.Keys) {
        $dest = Join-Path $P.Web $rel
        $destHash = Get-SloFileHash $dest
        if ($null -eq $destHash) { $added += $rel; continue }
        if ($destHash -eq $source[$rel]) { $unchanged++; continue }
        if (Test-Matches $rel $ProtectedWebFiles) { $preserved++; continue }
        if ($prevManifest.ContainsKey($rel) -and $prevManifest[$rel] -eq $destHash) { $replaced += $rel; continue }
        if ($OverwriteModifiedWebFiles) { $replaced += $rel; continue }
        $conflicts += $rel
    }
    foreach ($rel in $prevManifest.Keys) {
        if ($source.ContainsKey($rel) -or (Test-Matches $rel $ProtectedWebFiles)) { continue }
        $dest = Join-Path $P.Web $rel
        $destHash = Get-SloFileHash $dest
        if ($null -eq $destHash) { continue }
        if ($destHash -eq $prevManifest[$rel]) { $deleted += $rel } else { $keptStale += $rel }
    }
    if ($conflicts.Count -gt 0) {
        throw ("{0} web file(s) differ from both the new source and the last deployment (changed outside the installer): {1}. Nothing was deployed. Review them, then rerun with -OverwriteModifiedWebFiles (they are backed up first)." -f $conflicts.Count, (($conflicts | Select-Object -First 15) -join ', '))
    }
    foreach ($k in $keptStale) { Add-SloWarning "Kept ${k}: removed from the source but changed since the last deployment." }
    $summary = "deploy {0}: {1} added, {2} replaced, {3} removed, {4} unchanged, {5} preserved (wizard/admin-owned)" -f $script:SourceInfo.commit, $added.Count, $replaced.Count, $deleted.Count, $unchanged, $preserved
    if ($added.Count + $replaced.Count + $deleted.Count -eq 0) {
        Add-SloSkip -Kind 'web.deploy' -Resource "web:$($P.Web)" -Description "web files already match $($script:SourceInfo.commit)"
        return
    }
    if (Test-SloPlanMode) { Write-SloLog "would $summary" 'PLAN'; return }

    $run = Get-SloRun
    $backupRoot = Join-Path (Get-SloRunBackupDir) 'web'
    $dataPath = Join-Path $run.runDir 'deploy.json'
    $createdDirs = @()
    foreach ($rel in $added) {
        $dir = Split-Path -Parent $rel
        while ($dir -and -not (Test-Path -LiteralPath (Join-Path $P.Web $dir))) {
            if ($createdDirs -notcontains $dir) { $createdDirs += $dir }
            $dir = Split-Path -Parent $dir
        }
    }
    $manifest = @($source.Keys | Sort-Object | ForEach-Object {
        $h = $source[$_]
        if ((Test-Matches $_ $ProtectedWebFiles) -and (Test-Path -LiteralPath (Join-Path $P.Web $_)) -and $added -notcontains $_) { $h = Get-SloFileHash (Join-Path $P.Web $_) }
        [pscustomobject]@{ rel = $_; hash = $h }
    })
    $data = [pscustomobject]@{
        web         = $P.Web
        source      = $srcRoot
        commit      = $script:SourceInfo.commit
        createdAt   = (Get-Date).ToString('o')
        added       = @($added | ForEach-Object { [pscustomobject]@{ rel = $_; hash = $source[$_] } })
        replaced    = @($replaced | ForEach-Object { [pscustomobject]@{ rel = $_; hash = $source[$_]; beforeHash = (Get-SloFileHash (Join-Path $P.Web $_)); backup = (Join-Path $backupRoot $_) } })
        deleted     = @($deleted | ForEach-Object { [pscustomobject]@{ rel = $_; beforeHash = $prevManifest[$_]; backup = (Join-Path $backupRoot $_) } })
        createdDirs = $createdDirs
        manifest    = $manifest
    }
    Write-SloJsonFile -Path $dataPath -Object $data

    Invoke-SloChange -Kind 'web.deploy' -Resource "web:$($P.Web)" -Description $summary -Ownership 'modified' `
        -Before ([pscustomobject]@{ commit = $(if ($lastDeploy) { $lastDeploy.after.commit } else { $null }) }) `
        -After ([pscustomobject]@{ commit = $script:SourceInfo.commit; files = $manifest.Count }) -Backup $backupRoot `
        -Inverse @{ type = 'deploy.revert'; dataPath = $dataPath } `
        -Apply {
            foreach ($rel in @($replaced + $deleted)) {
                $b = Join-Path $backupRoot $rel
                $bd = Split-Path -Parent $b
                if (-not (Test-Path -LiteralPath $bd)) { New-Item -ItemType Directory -Path $bd -Force | Out-Null }
                Copy-Item -LiteralPath (Join-Path $P.Web $rel) -Destination $b -Force
            }
            foreach ($rel in @($added + $replaced)) {
                $dest = Join-Path $P.Web $rel
                $dd = Split-Path -Parent $dest
                if (-not (Test-Path -LiteralPath $dd)) { New-Item -ItemType Directory -Path $dd -Force | Out-Null }
                Copy-Item -LiteralPath (Join-Path $srcRoot $rel) -Destination $dest -Force
            }
            foreach ($rel in $deleted) { Remove-Item -LiteralPath (Join-Path $P.Web $rel) -Force }
            foreach ($rel in @($added + $replaced)) {
                if ((Get-SloFileHash (Join-Path $P.Web $rel)) -ne $source[$rel]) { throw "Deployed file failed verification: $rel" }
            }
        } | Out-Null
    $script:AppVersion = ([System.IO.File]::ReadAllText((Join-Path $P.Web 'VERSION'))).Trim()
}

function Step-StartAndSmokeTest {
    if (Test-SloPlanMode) { Write-SloLog "would start $SiteName and request https://localhost/ (certificate pinned to the new thumbprint)" 'PLAN'; return }
    Import-SloWebAdministration
    if ((Get-WebAppPoolState -Name $AppPoolName).Value -ne 'Started') { Start-WebAppPool -Name $AppPoolName }
    if ((Get-Website -Name $SiteName).State -ne 'Started') { Start-Website -Name $SiteName }
    $candidates = [ordered]@{
        'system.webServer/httpErrors' = '<httpErrors'
        'system.webServer/security/requestFiltering' = '<requestFiltering'
        'system.webServer/httpProtocol' = '<httpProtocol'
        'system.webServer/defaultDocument' = '<defaultDocument'
        'system.webServer/directoryBrowse' = '<directoryBrowse'
    }
    for ($attempt = 1; $attempt -le 6; $attempt++) {
        $r = Invoke-SloPinnedGet -Url 'https://localhost/' -Thumbprint $script:Thumbprint
        if ($r.Status -eq 500 -and $r.Body -match '500\.19') {
            $section = $null
            foreach ($k in $candidates.Keys) { if ($r.Body -match [regex]::Escape($candidates[$k])) { $section = $k; break } }
            if (-not $section -or $r.Body -notmatch 'cannot be used at this path|locked') { throw "IIS returned HTTP 500.19 (configuration error) that the installer cannot repair automatically. Request https://localhost/ on the server to see details." }
            $beforeMode = Get-SloOverrideMode -Location $SiteName -Section $section
            Invoke-SloChange -Kind 'iis.override.set' -Resource "iis.override:$SiteName/$section" -Description "allow $section in $SiteName's web.config (section locked at server level)" -Ownership 'modified' `
                -Before ([pscustomobject]@{ overrideMode = $beforeMode }) -After ([pscustomobject]@{ overrideMode = 'Allow' }) `
                -Inverse @{ type = 'iis.override.restore'; location = $SiteName; section = $section; before = $beforeMode; after = 'Allow' } `
                -Apply { Set-WebConfiguration -PSPath (Get-SloApphost) -Location $SiteName -Filter $section -Metadata overrideMode -Value Allow } | Out-Null
            Start-Sleep -Seconds 1
            continue
        }
        if ($r.Status -eq 0) { throw "HTTPS request to https://localhost/ failed: $($r.Error)" }
        if ($r.Status -ge 500) { throw "https://localhost/ returned HTTP $($r.Status). Check $($P.DataLogs) and the IIS logs." }
        if ($r.Headers.ContainsKey('X-Powered-By')) { Add-SloWarning 'Response carries X-Powered-By; expose_php or web.config headers did not apply.' }
        if (-not $r.Headers.ContainsKey('X-Content-Type-Options')) { Add-SloWarning 'Response lacks X-Content-Type-Options; check that web.config is applied.' }
        Write-SloLog ("https://localhost/ -> HTTP {0}{1}" -f $r.Status, $(if ($r.Location) { " Location: $($r.Location)" } else { '' })) 'OK'
        return
    }
    throw 'IIS kept returning HTTP 500.19 after unlocking configuration sections.'
}

function Test-SetupComplete {
    return (Test-Path -LiteralPath (Join-Path $P.WebConfig 'config.yaml'))
}

function Step-Finalize {
    # Existence checks only: neither file is ever opened.
    $configDone = Test-SetupComplete
    $keyInSecrets = Test-Path -LiteralPath (Join-Path $P.Secrets 'key.yaml')
    if (-not $configDone) {
        Write-SloLog 'Setup wizard has not completed yet (config.yaml not found); secrets keep temporary write access.' 'WARN'
        return $false
    }
    if (-not $keyInSecrets) {
        Add-SloItItem "config.yaml exists but $($P.Secrets)\key.yaml does not: the wizard was given a different keys folder. Confirm that folder is outside the web root and restricted to Administrators/SYSTEM and $AppPoolName; the installer did not change it."
    }
    if (Test-Path -LiteralPath (Join-Path $P.Secrets 'slocloud-setup-code.txt')) { Add-SloWarning "A setup verification code file remains in $($P.Secrets); the wizard normally deletes it. Delete it once setup is confirmed." }
    if ((Get-SloMachineEnv -Name 'SLOCLOUD_SETUP_ALLOWED_IPS').exists) { Add-SloItItem 'SLOCLOUD_SETUP_ALLOWED_IPS is set at machine level. Remove it now that setup is complete.' }
    $rights = if ($SecretsAccessAfterSetup -eq 'Read') { 'ReadAndExecute' } else { 'Modify' }
    $why = if ($rights -eq 'Modify') { 'secrets after setup: Modify kept for UI key rotation (api/rotate_key.php writes key.yaml and backups here)' } else { 'secrets after setup: read-only; UI key rotation needs a temporary grant' }
    Set-SloDirectoryAcl -Path $P.Secrets -Exact -Rules @(@{ Identity = "IIS AppPool\$AppPoolName"; Rights = $rights }) -RestoreOnUndo -Purpose $why | Out-Null
    if ($rights -eq 'ReadAndExecute') { Add-SloItItem "Key rotation from System > Configuration requires write access to $($P.Secrets) for IIS AppPool\$AppPoolName. Grant it temporarily during a rotation, then remove it." }
    Write-SloLog 'Post-wizard finalization complete.' 'OK'
    return $true
}

function Write-InstallManifest {
    if (Test-SloPlanMode) { return }
    Import-SloWebAdministration
    $run = Get-SloRun
    $installRun = @(@((Get-SloJournal).runs) | Where-Object { $_.kind -eq 'install' -and $_.status -ne 'aborted' } | Sort-Object startedAt)
    $installRun = if ($installRun.Count -gt 0) { $installRun[-1] } else { $run }
    $site = Get-SloSiteSummary -Name $SiteName
    $cert = if ($script:Thumbprint) { Get-Item -LiteralPath "Cert:\LocalMachine\My\$($script:Thumbprint)" -ErrorAction SilentlyContinue } else { $null }
    $inetStp = Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Microsoft\InetStp' -ErrorAction SilentlyContinue
    $acl = [ordered]@{}
    # Loop variable must not be $p: names are case-insensitive and it would replace $P.
    foreach ($dir in @($P.Root, $P.Repo, $P.Web, $P.WebConfig, $P.Data, $P.Secrets, $P.Backup, $P.Php)) { $acl[$dir] = Get-SloAccessSddl -Path $dir }
    $manifest = [ordered]@{
        schemaVersion   = 1
        product         = 'SLO Cloud 5'
        runId           = $run.runId
        generatedAt     = (Get-Date).ToString('o')
        host            = $env:COMPUTERNAME
        paths           = $P
        source          = $script:SourceInfo
        appVersion      = $(if (Test-Path -LiteralPath (Join-Path $P.Web 'VERSION')) { ([System.IO.File]::ReadAllText((Join-Path $P.Web 'VERSION'))).Trim() } else { $null })
        php             = [ordered]@{
            version  = (Get-PhpVersionOf $P.PhpExe)
            path     = $P.Php
            ini      = $P.PhpIni
            iniSha256 = (Get-SloFileHash $P.PhpIni)
            modules  = $script:PhpModules
            sources  = @($script:PhpSources | ForEach-Object { [ordered]@{ file = $_.fileName; version = $_.version; sha256 = $_.sha256; verifiedBy = $_.verifiedBy } })
        }
        iis             = [ordered]@{
            version         = $(if ($inetStp) { '{0}.{1}' -f $inetStp.MajorVersion, $inetStp.MinorVersion } else { $null })
            site            = $site
            appPool         = [ordered]@{ name = $AppPoolName; identity = "IIS AppPool\$AppPoolName"; sid = $script:PoolSid }
            phpHandler      = $PhpHandlerName
            fastCgi         = $P.PhpCgi
            urlRewrite      = (Get-UninstallEntry 'IIS URL Rewrite Module*')
            allSiteBindings = (Get-SloAllSiteBindings)
            httpSys443      = @(Get-SloHttpSysSslBindings | Where-Object { $_.key -match ':443$' })
            defaultWebSite  = (Get-SloSiteSummary -Name 'Default Web Site')
        }
        certificate     = $(if ($cert) { [ordered]@{ thumbprint = $cert.Thumbprint; subject = $cert.Subject; san = (($cert.DnsNameList | ForEach-Object { "DNS=$($_.Unicode)" }) -join ', '); notAfter = $cert.NotAfter.ToString('o'); trusted = $false } } else { $null })
        odbc            = (Get-OdbcDriver)
        vcRedist        = (Get-VcRedistState)
        machineEnv      = [ordered]@{ SLOCLOUD_DATA_PATH = (Get-SloMachineEnv 'SLOCLOUD_DATA_PATH').value; SLOCLOUD_ENV = (Get-SloMachineEnv 'SLOCLOUD_ENV').value; phpOnPath = (Test-SloPathEntry -PathValue ((Get-SloMachineEnv 'Path').value) -Entry $P.Php) }
        acls            = $acl
        sql             = [ordered]@{ host = $SqlHost; port = $SqlPort; tcpReachable = $script:SqlCheck.reachable; detail = $script:SqlCheck.detail; note = 'TCP reachability only. Not proof of database readiness; credentials and schema are handled by the setup wizard.' }
        setup           = [ordered]@{ wizardUrl = 'https://localhost/setup/'; keysFolder = $P.Secrets; configYamlExists = (Test-SetupComplete); keyYamlInSecrets = (Test-Path -LiteralPath (Join-Path $P.Secrets 'key.yaml')); secretsAccessAfterSetup = $SecretsAccessAfterSetup }
        itItems         = @(@($installRun.itItems) + @($run.itItems) | Select-Object -Unique)
        warnings        = @(@($installRun.warnings) + @($run.warnings) | Select-Object -Unique)
        journal         = (Get-SloJournalPath)
        runDirectory    = $run.runDir
    }
    Write-SloJsonFile -Path (Join-Path $run.runDir 'install-manifest.json') -Object $manifest
    Write-SloJsonFile -Path (Join-Path (Get-SloStateRoot) 'install-manifest.json') -Object $manifest
    Write-SloLog "install manifest: $(Join-Path (Get-SloStateRoot) 'install-manifest.json')" 'OK'
}

function Add-StandardItItems {
    Add-SloItItem 'Production hostname, DNS record, CA-issued certificate and its HTTPS binding are not configured (localhost only). Plan them as a separate IT-approved change.'
    Add-SloItItem 'Windows Firewall was not changed. Production needs inbound 443/TCP from users and outbound 1433/TCP to the database node (see docs/SYSTEM_REQUIREMENTS.md section 1.11).'
    Add-SloItItem 'TLS 1.0/1.1 and SSL 3.0 (SCHANNEL) were not changed. Disabling them is a server-wide setting for IT.'
    Add-SloItItem "SQL Server: create the database login and setup permissions (db_datareader, db_datawriter, CREATE TABLE, ALTER ON SCHEMA::dbo), then revoke the DDL rights after setup. Allow 1433/TCP from this server only."
    Add-SloItItem "Antivirus real-time scanning exclusions: $($P.Data)\var\sessions and $($P.DataTmp)."
    Add-SloItItem "Back up $($P.WebConfig)\config.yaml, the key file in $($P.Secrets) (stored apart from database backups) and $($P.DataVar)\reports. Copy application snapshots in $($P.Backup) off this server."
    Add-SloItItem 'Time sync (NTP) must be accurate: LTI allows 300 s clock skew, SAML 60 s.'
    Add-SloItItem 'Take a VM snapshot or full backup before production changes. Undo cannot guarantee environment-level restoration (Windows features, shared packages, wizard database changes).'
}

function Show-WizardInstructions {
    Write-Host ''
    Write-Host '==================== Next: application setup wizard ====================' -ForegroundColor Cyan
    Write-Host 'The application owns database creation, encryption keys and config.yaml.'
    Write-Host '  1. On THIS server, open  https://localhost/setup/'
    Write-Host '     The certificate is self-signed for localhost: browsers will warn that it is not trusted. That is expected.'
    Write-Host "  2. Keys folder:          $($P.Secrets)"
    Write-Host "  3. Open $($P.Secrets)\slocloud-setup-code.txt in Notepad and paste the code into the wizard."
    Write-Host "  4. Database driver mssql, host $SqlHost, port $SqlPort, credentials from your DBA."
    Write-Host '  5. Save the LTI consumer key and secret shown on the completion screen.'
    Write-Host '=========================================================================' -ForegroundColor Cyan
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

Initialize-SloStateRoot
Enter-SloLock
$transcriptStarted = $false
$exitCode = 0
try {
    [void](Read-SloJournal)
    $script:Prev = Get-PreviousParameters

    if (-not $InstallRoot) { $InstallRoot = Read-Input 'Installation root' (Get-Prev 'InstallRoot' 'D:\SLO Cloud 5') -Required }
    $InstallRoot = $InstallRoot.TrimEnd('\')
    $P = Get-Paths $InstallRoot

    if (-not $FinalizeSetup) {
        if (-not $LocalSourcePath -and -not $RepositoryUrl -and (Get-Prev 'LocalSourcePath' '')) { $LocalSourcePath = Get-Prev 'LocalSourcePath' '' }
        if (-not $LocalSourcePath) {
            if (-not $RepositoryUrl) { $RepositoryUrl = Read-Input 'Git repository URL' (Get-Prev 'RepositoryUrl' 'https://github.com/aeron100/SLO-Cloud-5.git') -Required }
            if (-not $Ref) { $Ref = Read-Input 'Branch, tag or commit to deploy (no default; e.g. dev or a commit SHA)' (Get-Prev 'Ref' '') -Required }
        }
        if (-not $SqlHost) { $SqlHost = Read-Input 'SQL Server host (TCP check only)' (Get-Prev 'SqlHost' '') -Required }
        if ($SqlPort -le 0) { $SqlPort = [int](Read-Input 'SQL Server TCP port' (Get-Prev 'SqlPort' '1433') -Required) }
        if ($SqlPort -lt 1 -or $SqlPort -gt 65535) { throw "Invalid SQL port $SqlPort." }
        if (-not $TimeZone) { $TimeZone = Read-Input 'PHP date.timezone' (Get-Prev 'TimeZone' 'America/Los_Angeles') -Required }
    } else {
        $SqlHost = Get-Prev 'SqlHost' ''
        $SqlPort = [int](Get-Prev 'SqlPort' '1433')
        $TimeZone = Get-Prev 'TimeZone' 'America/Los_Angeles'
        $RepositoryUrl = Get-Prev 'RepositoryUrl' ''
        $Ref = Get-Prev 'Ref' ''
    }
    if (-not $SecretsAccessAfterSetup) {
        $prevMode = Get-Prev 'SecretsAccessAfterSetup' 'Modify'
        if ($NonInteractive) { $SecretsAccessAfterSetup = $prevMode } else {
            Write-Host ''
            Write-Host 'After the wizard finishes, should the app pool keep write access to the secrets folder?' -ForegroundColor Cyan
            Write-Host '  Modify - System > Configuration key rotation works (it rewrites key.yaml and writes backups beside it).'
            Write-Host '  Read   - least privilege; key rotation from the UI fails until an administrator grants write access.'
            do { $SecretsAccessAfterSetup = Read-Input 'Secrets access after setup (Modify/Read)' $prevMode } while (@('Modify', 'Read') -notcontains $SecretsAccessAfterSetup)
        }
    }

    $parameters = @{
        InstallRoot = $InstallRoot; RepositoryUrl = (Protect-SloText $RepositoryUrl); Ref = $Ref; LocalSourcePath = $LocalSourcePath
        SqlHost = $SqlHost; SqlPort = $SqlPort; SiteName = $SiteName; AppPoolName = $AppPoolName
        SecretsAccessAfterSetup = $SecretsAccessAfterSetup; TimeZone = $TimeZone; SourceCache = $SourceCache
    }

    if ($FinalizeSetup) {
        $run = Start-SloRun -Kind 'finalize' -Mode 'finalize' -Parameters $parameters
        Start-Transcript -LiteralPath (Join-Path $run.runDir 'transcript.txt') | Out-Null; $transcriptStarted = $true
        Import-SloWebAdministration
        $done = Step-Finalize
        Write-InstallManifest
        Complete-SloRun -Status $(if ($done) { 'succeeded' } else { 'partial' })
        return
    }

    Write-Host ''
    Write-Host '==================== Preflight ====================' -ForegroundColor Cyan
    $findings = Invoke-Preflight
    foreach ($f in $findings) {
        $color = switch ($f.level) { 'BLOCK' { 'Red' } 'WARN' { 'Yellow' } 'CONFIRM' { 'Yellow' } 'OK' { 'Green' } default { 'Gray' } }
        Write-Host ("[{0,-7}] {1}" -f $f.level, $f.text) -ForegroundColor $color
    }
    $blockers = @($findings | Where-Object { $_.level -eq 'BLOCK' })
    if ($blockers.Count -gt 0) { throw "$($blockers.Count) preflight blocker(s). Nothing was changed." }

    $run = Start-SloRun -Kind 'install' -Mode $(if ($Plan) { 'plan' } else { 'apply' }) -Parameters $parameters -Plan:$Plan
    if (-not $Plan) {
        Start-Transcript -LiteralPath (Join-Path $run.runDir 'transcript.txt') | Out-Null; $transcriptStarted = $true
        foreach ($f in $findings) { if ($f.level -eq 'WARN') { Add-SloWarning $f.text } }
        foreach ($f in @($findings | Where-Object { $_.level -eq 'CONFIRM' })) {
            if (-not (Confirm-Typed $f.text 'YES')) { throw 'Not confirmed. Nothing was changed.' }
        }
        $summary = @"
About to install SLO Cloud 5 (infrastructure only):
  Install root : $($P.Root)
  Source       : $(if ($LocalSourcePath) { $LocalSourcePath } else { "$(Protect-SloText $RepositoryUrl) @ $Ref" })
  IIS site     : $SiteName (https://localhost/, self-signed certificate) / app pool $AppPoolName
  SQL check    : ${SqlHost}:$SqlPort (TCP only)
  Journal      : $(Get-SloJournalPath)
  Run id       : $($run.runId)
"@
        if (-not (Confirm-Typed $summary 'YES')) { Complete-SloRun -Status 'aborted'; throw 'Installation not confirmed. Nothing was changed.' }
    }

    $iisWasInstalled = [bool](Get-WindowsFeature -Name Web-Server).Installed
    if ($iisWasInstalled) { Step-IisBackup 'before' }
    Step-WindowsFeatures
    if (-not (Test-SloPlanMode)) {
        Import-SloWebAdministration
        if (-not $iisWasInstalled) { Step-IisBackup 'after-features' }
    } elseif ($iisWasInstalled) {
        Import-SloWebAdministration
    }
    Step-VcRedist
    Step-UrlRewrite
    Step-Odbc
    Step-Directories
    Step-Source
    Step-Php
    Step-PhpIni
    Test-PhpCli
    Step-MachineEnvironment
    Step-AppPool
    Step-Acls
    Step-DefaultWebSiteConflict
    Step-FastCgi
    Step-Site
    Step-Certificate
    Step-SslBinding
    Step-Deploy
    Step-WebConfigAcl
    Step-StartAndSmokeTest

    if ($Plan) {
        Write-Host ''
        Write-Host ("Plan complete: {0} change(s) would be made. Nothing was changed." -f (Get-SloPlannedChanges).Count) -ForegroundColor Magenta
        return
    }

    Add-StandardItItems
    if ($script:SqlCheck -and -not $script:SqlCheck.reachable) { Add-SloItItem "SQL Server ${SqlHost}:$SqlPort was not reachable over TCP from this server: $($script:SqlCheck.detail)" }
    Add-SloItItem 'Browsers do not trust the self-signed localhost certificate; the warning on https://localhost/ is expected. The trust store was not modified.'
    $finalized = Step-Finalize
    Write-InstallManifest
    Complete-SloRun -Status 'succeeded'

    if (-not $SkipValidation) {
        Write-Host ''
        Write-Host '==================== Independent validation ====================' -ForegroundColor Cyan
        & (Join-Path $ScriptRoot 'Test-SLOCloud5.ps1') -InstallRoot $P.Root -SiteName $SiteName -AppPoolName $AppPoolName -SqlHost $SqlHost -SqlPort $SqlPort
        if ($LASTEXITCODE -ne 0) { Write-SloLog "Validation reported failures (exit $LASTEXITCODE). See the report above." 'WARN'; $exitCode = 2 }
    }

    if (-not $finalized) {
        Show-WizardInstructions
        if (-not $NonInteractive -and -not $SkipWizardWait) {
            while ($true) {
                $answer = Read-Host 'Press Enter once the wizard has finished, or type LATER to finish later with -FinalizeSetup'
                if ($answer -ieq 'LATER') { break }
                if (Test-SetupComplete) {
                    $null = Start-SloRun -Kind 'finalize' -Mode 'finalize' -Parameters $parameters
                    if (Step-Finalize) { Write-InstallManifest; Complete-SloRun -Status 'succeeded'; break }
                    Complete-SloRun -Status 'partial'
                    break
                }
                Write-Host 'config.yaml not found yet; the wizard has not finished.' -ForegroundColor Yellow
            }
        }
        if (-not (Test-SetupComplete)) {
            Write-Host ''
            Write-Host 'After the wizard finishes, run:' -ForegroundColor Cyan
            Write-Host "  .\Install-SLOCloud5.ps1 -FinalizeSetup -InstallRoot '$($P.Root)'"
        }
    }

    Write-Host ''
    Write-Host '==================== Items requiring IT ====================' -ForegroundColor Yellow
    $journal = Get-SloJournal
    $installRun = @($journal.runs | Where-Object { $_.kind -eq 'install' } | Sort-Object startedAt)[-1]
    foreach ($i in @($installRun.itItems)) { Write-Host " - $i" -ForegroundColor Yellow }
    Write-Host ''
    Write-Host "Install complete. Run id $($installRun.runId). Log: $($installRun.logPath)" -ForegroundColor Green
    Write-Host "Roll back this run:  .\Undo-SLOCloud5.ps1 -Mode RollbackRun -RunId $($installRun.runId)"
    Write-Host 'Uninstall:           .\Undo-SLOCloud5.ps1 -Mode Uninstall'
} catch {
    $exitCode = 1
    Write-SloLog ("FAILED: " + $_.Exception.Message) 'ERROR'
    $run = Get-SloRun
    if ($run -and -not (Test-SloPlanMode) -and $run.status -eq 'running') {
        Complete-SloRun -Status 'failed'
        Write-Host ''
        Write-Host 'Every change made before the failure is recorded in the journal.' -ForegroundColor Yellow
        Write-Host "  Fix the cause and rerun (the installer resumes idempotently), or roll back this run:" -ForegroundColor Yellow
        Write-Host "  .\Undo-SLOCloud5.ps1 -Mode RollbackRun -RunId $($run.runId)" -ForegroundColor Yellow
    }
} finally {
    if ($transcriptStarted) { try { Stop-Transcript | Out-Null } catch { } }
    Exit-SloLock
}
exit $exitCode
