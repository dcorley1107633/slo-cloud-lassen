#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Independent, read-only validation of an SLO Cloud 5 web node.

.DESCRIPTION
    Deliberately does not import the installer module, so a bug shared by the
    installer cannot hide itself here. It checks IIS, PHP (CLI and through
    FastCGI), the HTTPS binding and certificate, NTFS permissions, blocked
    URLs, machine environment, SQL TCP reachability and setup state.

    The only write it makes: a temporary PHP probe with a random name in the
    web root, which answers only loopback requests carrying a random token,
    returns selected values (never phpinfo), and is deleted immediately.
    The probe also writes and deletes a temporary file in the data and
    web\config folders to prove the app pool can write there.

    It never opens config.yaml, key.yaml or anything in the secrets folder;
    secret files are checked for existence only.

    Exit code: 0 when nothing FAILs, 1 otherwise. A JSON report is written to
    C:\ProgramData\SLOCloud5\installer\validation\.
#>
[CmdletBinding()]
param(
    [string]$InstallRoot,
    [string]$SiteName,
    [string]$AppPoolName,
    [string]$SqlHost,
    [int]$SqlPort = 0,
    [string]$ManifestPath = (Join-Path $env:ProgramData 'SLOCloud5\installer\install-manifest.json'),
    [string]$OutputPath,
    [switch]$SkipProbe
)

# Version 1: catches unset variables. Version 2+ would also throw on reading optional
# properties of journal records and $null results, which these scripts do deliberately.
Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

$results = New-Object System.Collections.ArrayList
function Add-Result([string]$Area, [ValidateSet('PASS', 'WARN', 'FAIL', 'INFO', 'IT')][string]$Status, [string]$Message) {
    [void]$results.Add([pscustomobject]@{ area = $Area; status = $Status; message = $Message })
    $color = switch ($Status) { 'PASS' { 'Green' } 'WARN' { 'Yellow' } 'FAIL' { 'Red' } 'IT' { 'Yellow' } default { 'Gray' } }
    Write-Host ("[{0}] {1,-10} {2}" -f $Status, $Area, $Message) -ForegroundColor $color
}

function Invoke-Exe([string]$File, [string[]]$Arguments) {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $out = @(& $File @Arguments 2>&1 | ForEach-Object { "$_" }); $code = $LASTEXITCODE } finally { $ErrorActionPreference = $old }
    return [pscustomobject]@{ ExitCode = $code; Text = ($out -join "`n"); Output = $out }
}

# ---------------------------------------------------------------------------
# Inputs
# ---------------------------------------------------------------------------

$manifest = $null
if (Test-Path -LiteralPath $ManifestPath) {
    try { $manifest = Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json } catch { Add-Result 'inputs' 'WARN' "install manifest unreadable: $($_.Exception.Message)" }
}
if (-not $InstallRoot) { if ($manifest) { $InstallRoot = $manifest.paths.Root } else { $InstallRoot = 'D:\SLO Cloud 5' } }
if (-not $SiteName) { $SiteName = if ($manifest -and $manifest.iis.site) { $manifest.iis.site.name } else { 'SLOCloud5' } }
if (-not $AppPoolName) { $AppPoolName = if ($manifest) { $manifest.iis.appPool.name } else { 'SLOCloud5' } }
if (-not $SqlHost -and $manifest) { $SqlHost = $manifest.sql.host }
if ($SqlPort -le 0) { $SqlPort = if ($manifest -and $manifest.sql.port) { [int]$manifest.sql.port } else { 1433 } }
$secretsMode = if ($manifest -and $manifest.setup.secretsAccessAfterSetup) { [string]$manifest.setup.secretsAccessAfterSetup } else { 'Modify' }

$Root = $InstallRoot.TrimEnd('\')
$Web = Join-Path $Root 'web'
$WebConfig = Join-Path $Web 'config'
$Repo = Join-Path $Root 'repo'
$Secrets = Join-Path $Root 'secrets'
$Backup = Join-Path $Root 'backup'
$Data = Join-Path $Root 'data'
$Php = Join-Path $Root 'php'
$PhpExe = Join-Path $Php 'php.exe'
$PhpCgi = Join-Path $Php 'php-cgi.exe'
$PhpIni = Join-Path $Php 'php.ini'
$setupDone = Test-Path -LiteralPath (Join-Path $WebConfig 'config.yaml')
Add-Result 'inputs' 'INFO' "root=$Root site=$SiteName pool=$AppPoolName sql=${SqlHost}:$SqlPort setupComplete=$setupDone"

# ---------------------------------------------------------------------------
# OS / features / modules
# ---------------------------------------------------------------------------

$os = Get-CimInstance Win32_OperatingSystem
Add-Result 'os' 'INFO' "$($os.Caption) build $($os.BuildNumber)"
$needFeatures = @('Web-Server', 'Web-Default-Doc', 'Web-Static-Content', 'Web-Http-Errors', 'Web-Http-Logging', 'Web-Request-Monitor', 'Web-Filtering', 'Web-CGI', 'Web-Mgmt-Console', 'Web-Scripting-Tools')
$missing = @($needFeatures | Where-Object { -not (Get-WindowsFeature -Name $_).Installed })
if ($missing.Count -eq 0) { Add-Result 'iis' 'PASS' 'required IIS role services installed' } else { Add-Result 'iis' 'FAIL' "missing IIS role services: $($missing -join ', ')" }
foreach ($svc in @('W3SVC', 'WAS')) {
    $s = Get-Service -Name $svc -ErrorAction SilentlyContinue
    if ($s -and $s.Status -eq 'Running') { Add-Result 'iis' 'PASS' "$svc running" } else { Add-Result 'iis' 'FAIL' "$svc not running" }
}
Import-Module WebAdministration -ErrorAction Stop -Verbose:$false
$modules = @(Get-WebGlobalModule | ForEach-Object { $_.Name })
foreach ($m in @('RewriteModule', 'FastCgiModule', 'RequestFilteringModule', 'StaticFileModule', 'DefaultDocumentModule', 'CustomErrorModule')) {
    if ($modules -contains $m) { Add-Result 'iis' 'PASS' "global module $m" } else { Add-Result 'iis' 'FAIL' "global module $m missing" }
}
foreach ($bad in @('DirectoryListingModule', 'WebDAVModule')) {
    if ($modules -contains $bad) { Add-Result 'iis' 'WARN' "$bad is installed (not needed; web.config disables directory browsing)" }
}

# ---------------------------------------------------------------------------
# Site / pool / handler / FastCGI
# ---------------------------------------------------------------------------

$apphost = 'MACHINE/WEBROOT/APPHOST'
$site = Get-Item -Path "IIS:\Sites\$SiteName" -ErrorAction SilentlyContinue
if (-not $site) {
    Add-Result 'site' 'FAIL' "IIS site $SiteName not found"
} else {
    if ($site.State -eq 'Started') { Add-Result 'site' 'PASS' 'site started' } else { Add-Result 'site' 'FAIL' "site state $($site.State)" }
    if ([Environment]::ExpandEnvironmentVariables($site.physicalPath) -ieq $Web) { Add-Result 'site' 'PASS' "physical path $Web" } else { Add-Result 'site' 'FAIL' "physical path is $($site.physicalPath), expected $Web" }
    if ($site.applicationPool -eq $AppPoolName) { Add-Result 'site' 'PASS' "application pool $AppPoolName" } else { Add-Result 'site' 'FAIL' "site uses pool $($site.applicationPool)" }
    $bindings = @($site.Bindings.Collection)
    $want = @($bindings | Where-Object { $_.protocol -eq 'https' -and $_.bindingInformation -eq '*:443:localhost' })
    if ($want.Count -eq 1) {
        $flags = $want[0].sslFlags
        if (([int]$flags -band 1) -eq 1) { Add-Result 'binding' 'PASS' 'https *:443:localhost with SNI' } else { Add-Result 'binding' 'WARN' 'https localhost binding is not SNI (sslFlags lacks 1)' }
    } else { Add-Result 'binding' 'FAIL' 'https *:443:localhost binding missing' }
    foreach ($b in $bindings) {
        $hostPart = ($b.bindingInformation.Split(':') | Select-Object -Last 1)
        if ($hostPart -and $hostPart -ine 'localhost') { Add-Result 'binding' 'WARN' "non-localhost binding present: $($b.protocol)/$($b.bindingInformation) (production bindings are an IT change)" }
        if ($b.protocol -eq 'http') { Add-Result 'binding' 'INFO' "HTTP binding present: $($b.bindingInformation)" }
    }

    $anon = Get-WebConfiguration -PSPath $apphost -Location $SiteName -Filter 'system.webServer/security/authentication/anonymousAuthentication'
    if ($anon.enabled -and [string]$anon.userName -eq '') { Add-Result 'site' 'PASS' 'anonymous authentication uses the app pool identity' } else { Add-Result 'site' 'FAIL' "anonymous authentication enabled=$($anon.enabled) userName='$($anon.userName)'" }
    $limit = (Get-WebConfigurationProperty -PSPath $apphost -Location $SiteName -Filter 'system.webServer/security/requestFiltering/requestLimits' -Name maxAllowedContentLength).Value
    if ([int64]$limit -ge 67108864) { Add-Result 'site' 'PASS' "maxAllowedContentLength $limit" } else { Add-Result 'site' 'WARN' "maxAllowedContentLength $limit (< 64 MB; CSV uploads may fail)" }
    $handlers = @(Get-WebConfiguration -PSPath $apphost -Location $SiteName -Filter 'system.webServer/handlers/add')
    $phpHandlers = @($handlers | Where-Object { $_.path -eq '*.php' })
    if ($phpHandlers.Count -gt 0 -and [string]$phpHandlers[0].scriptProcessor -ieq $PhpCgi -and $phpHandlers[0].modules -eq 'FastCgiModule') { Add-Result 'site' 'PASS' "first *.php handler -> $PhpCgi" }
    elseif ($phpHandlers.Count -gt 0) { Add-Result 'site' 'FAIL' "first *.php handler is $($phpHandlers[0].name) -> $($phpHandlers[0].scriptProcessor)" }
    else { Add-Result 'site' 'FAIL' 'no *.php handler' }
}

$poolPath = "IIS:\AppPools\$AppPoolName"
if (Test-Path -Path $poolPath) {
    $pool = Get-Item -Path $poolPath
    if ($pool.State -eq 'Started') { Add-Result 'pool' 'PASS' 'application pool started' } else { Add-Result 'pool' 'FAIL' "application pool $($pool.State)" }
    if ([string]$pool.managedRuntimeVersion -eq '') { Add-Result 'pool' 'PASS' 'No Managed Code' } else { Add-Result 'pool' 'WARN' "managedRuntimeVersion '$($pool.managedRuntimeVersion)'" }
    if ([string]$pool.managedPipelineMode -eq 'Integrated') { Add-Result 'pool' 'PASS' 'Integrated pipeline' } else { Add-Result 'pool' 'FAIL' "pipeline $($pool.managedPipelineMode)" }
    if ([string]$pool.processModel.identityType -eq 'ApplicationPoolIdentity') { Add-Result 'pool' 'PASS' "identity IIS AppPool\$AppPoolName" } else { Add-Result 'pool' 'WARN' "identity type $($pool.processModel.identityType)" }
} else { Add-Result 'pool' 'FAIL' "application pool $AppPoolName not found" }

$fcgi = @(Get-WebConfiguration -PSPath $apphost -Filter 'system.webServer/fastCgi/application' | Where-Object { $_.fullPath -ieq $PhpCgi })
if ($fcgi.Count -eq 0) { Add-Result 'fastcgi' 'FAIL' "FastCGI application $PhpCgi not registered" } else {
    $a = $fcgi[0]
    if ([int]$a.activityTimeout -ge 3600 -and [int]$a.requestTimeout -ge 3600) { Add-Result 'fastcgi' 'PASS' 'activity/request timeouts >= 3600 s' } else { Add-Result 'fastcgi' 'WARN' "timeouts activity=$($a.activityTimeout) request=$($a.requestTimeout) (CSV import needs long requests)" }
    $fcgiEnv = @{}
    foreach ($e in @(Get-WebConfiguration -PSPath $apphost -Filter "system.webServer/fastCgi/application[@fullPath='$PhpCgi']/environmentVariables/environmentVariable")) { $fcgiEnv[[string]$e.name] = [string]$e.value }
    $wantEnv = @{ SLOCLOUD_DATA_PATH = $Data; SLOCLOUD_ENV = 'production'; SLOCLOUD_FORCE_HTTPS = 'true'; PHPRC = $Php }
    foreach ($k in $wantEnv.Keys) {
        if ($fcgiEnv.ContainsKey($k) -and $fcgiEnv[$k] -ieq $wantEnv[$k]) { Add-Result 'fastcgi' 'PASS' "env $k" } else { Add-Result 'fastcgi' 'FAIL' "env $k = '$(if ($fcgiEnv.ContainsKey($k)) { $fcgiEnv[$k] })', expected '$($wantEnv[$k])'" }
    }
    if ($fcgiEnv.ContainsKey('SLOCLOUD_SETUP_ALLOWED_IPS')) { Add-Result 'fastcgi' 'IT' 'SLOCLOUD_SETUP_ALLOWED_IPS is set; remove it after setup' }
    if ($fcgiEnv.ContainsKey('SLOCLOUD_HSTS_MAX_AGE')) { Add-Result 'fastcgi' 'WARN' 'HSTS is enabled for a localhost deployment with a self-signed certificate' }
}

# Exposure: no site may serve the install root, repo, secrets or data.
foreach ($s in @(Get-ChildItem IIS:\Sites)) {
    $paths = @([Environment]::ExpandEnvironmentVariables($s.physicalPath))
    $paths += @(Get-WebApplication -Site $s.Name | ForEach-Object { [Environment]::ExpandEnvironmentVariables($_.PhysicalPath) })
    $paths += @(Get-WebVirtualDirectory -Site $s.Name | ForEach-Object { [Environment]::ExpandEnvironmentVariables($_.PhysicalPath) })
    foreach ($pp in $paths) {
        if (-not $pp) { continue }
        $norm = $pp.TrimEnd('\') + '\'
        foreach ($sensitive in @($Root, $Repo, $Secrets, $Data, $Backup, $Php)) {
            if (($sensitive.TrimEnd('\') + '\').StartsWith($norm, [StringComparison]::OrdinalIgnoreCase)) { Add-Result 'exposure' 'FAIL' "site '$($s.Name)' serves $pp, which contains $sensitive" }
        }
    }
}
Add-Result 'exposure' 'PASS' 'checked all site, application and virtual directory paths'

# ---------------------------------------------------------------------------
# Certificate / HTTP.sys
# ---------------------------------------------------------------------------

$thumb = $null
$show = Invoke-Exe "$env:windir\System32\netsh.exe" @('http', 'show', 'sslcert', 'hostnameport=localhost:443')
if ($show.Text -match 'Certificate Hash\s*:\s*([0-9a-fA-F]{40})') {
    $thumb = $Matches[1].ToUpperInvariant()
    Add-Result 'tls' 'PASS' "HTTP.sys hostnameport=localhost:443 -> $thumb"
    $cert = Get-Item -LiteralPath "Cert:\LocalMachine\My\$thumb" -ErrorAction SilentlyContinue
    if (-not $cert) { Add-Result 'tls' 'FAIL' 'bound certificate not found in LocalMachine\My' } else {
        $dns = @($cert.DnsNameList | ForEach-Object { $_.Unicode })
        if ($dns -contains 'localhost') { Add-Result 'tls' 'PASS' 'certificate SAN contains DNS=localhost' } else { Add-Result 'tls' 'FAIL' "certificate SAN lacks localhost ($($dns -join ', '))" }
        if ($cert.NotAfter -lt (Get-Date)) { Add-Result 'tls' 'FAIL' "certificate expired $($cert.NotAfter)" } elseif ($cert.NotAfter -lt (Get-Date).AddDays(30)) { Add-Result 'tls' 'WARN' "certificate expires $($cert.NotAfter); rerun the installer to renew" } else { Add-Result 'tls' 'PASS' "certificate valid until $($cert.NotAfter.ToString('yyyy-MM-dd'))" }
        if (-not $cert.HasPrivateKey) { Add-Result 'tls' 'FAIL' 'certificate has no private key' }
        if ($cert.Subject -eq $cert.Issuer) { Add-Result 'tls' 'INFO' 'self-signed: browsers will not trust it (expected for the localhost phase)' }
    }
} else { Add-Result 'tls' 'FAIL' 'no HTTP.sys certificate binding for localhost:443' }

# ---------------------------------------------------------------------------
# PHP CLI
# ---------------------------------------------------------------------------

if (-not (Test-Path -LiteralPath $PhpExe)) { Add-Result 'php' 'FAIL' "$PhpExe not found" } else {
    $v = Invoke-Exe $PhpExe @('-c', $PhpIni, '-r', 'echo json_encode([''v''=>PHP_VERSION,''ts''=>PHP_ZTS,''bits''=>PHP_INT_SIZE*8,''argon''=>defined(''PASSWORD_ARGON2ID''),''ini''=>php_ini_loaded_file(),''tz''=>ini_get(''date.timezone'')]);')
    try { $cli = $v.Output[-1] | ConvertFrom-Json } catch { $cli = $null }
    if (-not $cli) { Add-Result 'php' 'FAIL' "php.exe failed: $($v.Text)" } else {
        if ([version]$cli.v -ge [version]'8.1.0') { Add-Result 'php' 'PASS' "PHP $($cli.v)" } else { Add-Result 'php' 'FAIL' "PHP $($cli.v) is older than 8.1" }
        if ($cli.ts -eq 0 -and $cli.bits -eq 64) { Add-Result 'php' 'PASS' 'NTS x64' } else { Add-Result 'php' 'FAIL' "thread-safe=$($cli.ts) bits=$($cli.bits); NTS x64 required" }
        if ($cli.argon) { Add-Result 'php' 'PASS' 'PASSWORD_ARGON2ID available' } else { Add-Result 'php' 'FAIL' 'PASSWORD_ARGON2ID missing' }
        if ([string]$cli.ini -ieq $PhpIni) { Add-Result 'php' 'PASS' "php.ini $PhpIni" } else { Add-Result 'php' 'WARN' "CLI loaded php.ini '$($cli.ini)'" }
        if ($cli.tz) { Add-Result 'php' 'PASS' "date.timezone $($cli.tz)" } else { Add-Result 'php' 'FAIL' 'date.timezone not set (required; the app does not apply app.timezone)' }
    }
    if ($v.Text -match 'Unable to load dynamic library|PHP Startup') { Add-Result 'php' 'FAIL' 'PHP startup warnings (extension failed to load)' }
    $m = Invoke-Exe $PhpExe @('-c', $PhpIni, '-m')
    $loaded = @($m.Output | ForEach-Object { $_.Trim() })
    foreach ($ext in @('PDO', 'pdo_sqlsrv', 'yaml', 'openssl', 'mbstring', 'json', 'curl', 'fileinfo', 'dom', 'iconv', 'session', 'Zend OPcache')) {
        if ($loaded -contains $ext) { Add-Result 'php' 'PASS' "CLI module $ext" } else { Add-Result 'php' 'FAIL' "CLI module $ext missing" }
    }
}
$odbc = @('ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server') | Where-Object { Test-Path -LiteralPath "HKLM:\SOFTWARE\ODBC\ODBCINST.INI\$_" } | Select-Object -First 1
if ($odbc) { Add-Result 'php' 'PASS' "$odbc registered" } else { Add-Result 'php' 'FAIL' 'no ODBC Driver 17/18 for SQL Server (pdo_sqlsrv cannot connect)' }

# ---------------------------------------------------------------------------
# Machine environment
# ---------------------------------------------------------------------------

$envKey = [Microsoft.Win32.Registry]::LocalMachine.OpenSubKey('SYSTEM\CurrentControlSet\Control\Session Manager\Environment')
try {
    $dp = $envKey.GetValue('SLOCLOUD_DATA_PATH', $null)
    $ev = $envKey.GetValue('SLOCLOUD_ENV', $null)
    $pathValue = [string]$envKey.GetValue('Path', '', 'DoNotExpandEnvironmentNames')
} finally { $envKey.Dispose() }
if ($dp -ieq $Data) { Add-Result 'env' 'PASS' 'machine SLOCLOUD_DATA_PATH' } else { Add-Result 'env' 'FAIL' "machine SLOCLOUD_DATA_PATH='$dp' (CLI scripts need it)" }
if ($ev -eq 'production') { Add-Result 'env' 'PASS' 'machine SLOCLOUD_ENV=production' } else { Add-Result 'env' 'WARN' "machine SLOCLOUD_ENV='$ev'" }
if (@($pathValue -split ';' | ForEach-Object { $_.TrimEnd('\') }) -icontains $Php.TrimEnd('\')) { Add-Result 'env' 'PASS' 'php folder on machine PATH' } else { Add-Result 'env' 'WARN' 'php folder not on machine PATH' }

# ---------------------------------------------------------------------------
# NTFS permissions (DACL only)
# ---------------------------------------------------------------------------

$poolSid = $null
try { $poolSid = (New-Object System.Security.Principal.NTAccount("IIS AppPool\$AppPoolName")).Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { Add-Result 'acl' 'FAIL' "cannot resolve IIS AppPool\$AppPoolName" }
$broad = @{ 'S-1-1-0' = 'Everyone'; 'S-1-5-11' = 'Authenticated Users'; 'S-1-5-32-545' = 'Users'; 'S-1-5-32-568' = 'IIS_IUSRS'; 'S-1-5-17' = 'IUSR' }
$W = [System.Security.AccessControl.FileSystemRights]
$writeMask = [int]($W::WriteData -bor $W::AppendData -bor $W::Delete -bor $W::ChangePermissions -bor $W::TakeOwnership -bor $W::WriteAttributes -bor $W::WriteExtendedAttributes)
$readMask = [int]($W::ReadData)

function Get-Rights([string]$Path, [string]$Sid) {
    $acl = Get-Acl -LiteralPath $Path
    $allow = 0; $deny = 0
    foreach ($r in $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {
        if ($r.IdentityReference.Value -ne $Sid) { continue }
        if ($r.AccessControlType -eq 'Allow') { $allow = $allow -bor [int]$r.FileSystemRights } else { $deny = $deny -bor [int]$r.FileSystemRights }
    }
    return ($allow -band (-bnot $deny))
}

function Test-Acl([string]$Path, [string]$Label, [ValidateSet('none', 'read', 'modify')][string]$PoolExpect, [switch]$NoBroad) {
    if (-not (Test-Path -LiteralPath $Path)) { Add-Result 'acl' 'FAIL' "$Label ($Path) missing"; return }
    if ($poolSid) {
        $r = Get-Rights $Path $poolSid
        $canRead = ($r -band $readMask) -ne 0
        $canWrite = ($r -band $writeMask) -ne 0
        $ok = switch ($PoolExpect) { 'none' { -not $canRead -and -not $canWrite } 'read' { $canRead -and -not $canWrite } 'modify' { $canRead -and $canWrite } }
        $desc = "read=$canRead write=$canWrite"
        if ($ok) { Add-Result 'acl' 'PASS' "$Label app pool $PoolExpect ($desc)" } else { Add-Result 'acl' 'FAIL' "$Label app pool expected $PoolExpect, has $desc" }
    }
    if ($NoBroad) {
        foreach ($sid in $broad.Keys) {
            $r = Get-Rights $Path $sid
            if ($r -ne 0) { Add-Result 'acl' 'FAIL' "$Label grants access to $($broad[$sid])" }
        }
    }
}
$rootAcl = Get-Acl -LiteralPath $Root
if ($rootAcl.AreAccessRulesProtected) { Add-Result 'acl' 'PASS' 'install root inheritance disabled' } else { Add-Result 'acl' 'WARN' 'install root inherits permissions from the drive' }
# The app pool reads the root folder itself (PHP's realpath() lists every parent folder), never its contents.
Test-Acl $Root 'root' 'read' -NoBroad
if ($poolSid) {
    $inheritable = @($rootAcl.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier]) | Where-Object { $_.IdentityReference.Value -eq $poolSid -and $_.InheritanceFlags -ne 'None' })
    if ($inheritable.Count -gt 0) { Add-Result 'acl' 'FAIL' 'root grants the app pool inheritable access; it must apply to the root folder only' }
    else { Add-Result 'acl' 'PASS' 'root app pool access applies to the root folder only' }
}
Test-Acl $Repo 'repo' 'none' -NoBroad
Test-Acl $Web 'web' 'read' -NoBroad
Test-Acl $WebConfig 'web\config' 'modify' -NoBroad
Test-Acl $Data 'data' 'modify' -NoBroad
Test-Acl $Php 'php' 'read'
$secretsExpect = if (-not $setupDone) { 'modify' } elseif ($secretsMode -eq 'Read') { 'read' } else { 'modify' }
Test-Acl $Secrets 'secrets' $secretsExpect -NoBroad
Test-Acl $Backup 'backup' 'modify' -NoBroad

# ---------------------------------------------------------------------------
# Setup state (existence checks only; nothing is opened)
# ---------------------------------------------------------------------------

if ($setupDone) { Add-Result 'setup' 'PASS' 'config.yaml exists (wizard completed)' } else { Add-Result 'setup' 'INFO' 'wizard not completed yet: open https://localhost/setup/ on this server' }
if (Test-Path -LiteralPath (Join-Path $Secrets 'key.yaml')) { Add-Result 'setup' 'PASS' 'key.yaml present in secrets folder' } elseif ($setupDone) { Add-Result 'setup' 'WARN' 'key.yaml not in the secrets folder (wizard used another keys folder)' }
if (Test-Path -LiteralPath (Join-Path $WebConfig 'key.yaml')) { Add-Result 'setup' 'FAIL' 'key.yaml exists inside the web root (web\config)' }
$exposedBackups = @(Get-ChildItem -LiteralPath (Join-Path $WebConfig 'backups') -Recurse -File -Filter 'key.yaml' -ErrorAction SilentlyContinue)
if ($exposedBackups.Count -gt 0) { Add-Result 'setup' 'FAIL' "key backups inside the web root: $($exposedBackups.Count) file(s) under web\config\backups" }
if (Test-Path -LiteralPath (Join-Path $Secrets 'slocloud-setup-code.txt')) { Add-Result 'setup' 'WARN' 'setup verification code file still present in secrets' }
$leftovers = @(Get-ChildItem -LiteralPath $Web -Recurse -File -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^(phpinfo.*|info|test|__slocloud_probe_.*)\.php$' })
if ($leftovers.Count -gt 0) { Add-Result 'setup' 'FAIL' ("diagnostic files in the web root: " + (($leftovers | ForEach-Object { $_.FullName.Substring($Web.Length) }) -join ', ')) } else { Add-Result 'setup' 'PASS' 'no diagnostic files in the web root' }

# ---------------------------------------------------------------------------
# HTTPS functional checks (certificate pinned; no global trust change)
# ---------------------------------------------------------------------------

if (-not ('SloValidatorHttp' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.IO;
using System.Net;
using System.Net.Security;
using System.Collections.Generic;
using System.Security.Cryptography.X509Certificates;
public static class SloValidatorHttp
{
    public class Result { public int Status; public string Body; public string Error; public Dictionary<string, string> Headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase); }
    public static Result Get(string url, string pin, string headerName, string headerValue)
    {
        Result r = new Result();
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
        req.AllowAutoRedirect = false; req.Timeout = 30000; req.ReadWriteTimeout = 30000; req.KeepAlive = false; req.UserAgent = "SLOCloud5-Validator";
        req.ServerCertificateValidationCallback = delegate (object s, X509Certificate c, X509Chain ch, SslPolicyErrors e)
        {
            if (pin == null || c == null) { return false; }
            return string.Equals(new X509Certificate2(c).Thumbprint, pin, StringComparison.OrdinalIgnoreCase);
        };
        if (!string.IsNullOrEmpty(headerName)) { req.Headers[headerName] = headerValue; }
        HttpWebResponse resp = null;
        try { resp = (HttpWebResponse)req.GetResponse(); }
        catch (WebException ex) { resp = ex.Response as HttpWebResponse; if (resp == null) { r.Error = ex.Status + ": " + ex.Message; return r; } }
        using (resp)
        {
            r.Status = (int)resp.StatusCode;
            foreach (string k in resp.Headers.AllKeys) { r.Headers[k] = resp.Headers[k]; }
            using (StreamReader sr = new StreamReader(resp.GetResponseStream())) { string b = sr.ReadToEnd(); r.Body = b.Length > 65536 ? b.Substring(0, 65536) : b; }
        }
        return r;
    }
}
'@
}

if (-not $thumb) {
    Add-Result 'http' 'FAIL' 'skipping HTTPS checks: no localhost certificate binding'
} else {
    $homeResp = [SloValidatorHttp]::Get('https://localhost/', $thumb, '', '')
    if ($homeResp.Status -eq 0) { Add-Result 'http' 'FAIL' "https://localhost/ unreachable: $($homeResp.Error)" }
    elseif ($homeResp.Status -ge 500) { Add-Result 'http' 'FAIL' "https://localhost/ returned $($homeResp.Status)$(if ($homeResp.Body -match '500\.19') { ' (IIS configuration error 500.19)' })" }
    else {
        Add-Result 'http' 'PASS' "https://localhost/ -> $($homeResp.Status)$(if ($homeResp.Headers.ContainsKey('Location')) { ' ' + $homeResp.Headers['Location'] })"
        if ($homeResp.Headers.ContainsKey('X-Content-Type-Options')) { Add-Result 'http' 'PASS' 'X-Content-Type-Options header present' } else { Add-Result 'http' 'WARN' 'X-Content-Type-Options missing (web.config headers not applied?)' }
        if ($homeResp.Headers.ContainsKey('X-Powered-By')) { Add-Result 'http' 'WARN' "X-Powered-By disclosed: $($homeResp.Headers['X-Powered-By'])" } else { Add-Result 'http' 'PASS' 'X-Powered-By not disclosed' }
    }
    $setup = [SloValidatorHttp]::Get('https://localhost/setup/', $thumb, '', '')
    if ($setupDone) {
        if ($setup.Status -eq 403) { Add-Result 'http' 'PASS' '/setup/ refuses to run again (403)' } else { Add-Result 'http' 'FAIL' "/setup/ returned $($setup.Status) after setup" }
    } else {
        if ($setup.Status -eq 200) { Add-Result 'http' 'PASS' '/setup/ reachable from localhost' } else { Add-Result 'http' 'FAIL' "/setup/ returned $($setup.Status) ($($setup.Error))" }
    }
    $blocked = [ordered]@{
        '/config/' = 'FAIL'; '/config/config.yaml.template' = 'FAIL'; '/config/key.yaml.example' = 'FAIL'; '/config/web.config' = 'FAIL'
        '/config/config.yaml' = 'FAIL'; '/config/backups/' = 'FAIL'; '/var/' = 'FAIL'; '/logs/' = 'FAIL'; '/sessions/' = 'FAIL'
        '/web.config' = 'FAIL'; '/.git/config' = 'FAIL'; '/system/database/schema_mssql.sql' = 'FAIL'; '/system/database/schema.sql' = 'FAIL'
        '/PATCH_NOTES.md' = 'WARN'; '/README.md' = 'WARN'
    }
    foreach ($u in $blocked.Keys) {
        $r = [SloValidatorHttp]::Get("https://localhost$u", $thumb, '', '')
        if (@(403, 404) -contains $r.Status) { Add-Result 'blocked' 'PASS' "$u -> $($r.Status)" }
        elseif ($r.Status -eq 0) { Add-Result 'blocked' 'WARN' "$u -> no response ($($r.Error))" }
        else { Add-Result 'blocked' $blocked[$u] "$u -> $($r.Status) (expected 403/404)" }
    }

    if (-not $SkipProbe) {
        foreach ($stale in @(Get-ChildItem -LiteralPath $Web -File -Filter '__slocloud_probe_*.php' -ErrorAction SilentlyContinue)) { Remove-Item -LiteralPath $stale.FullName -Force }
        $token = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
        $name = '__slocloud_probe_' + [guid]::NewGuid().ToString('N') + '.php'
        $probePath = Join-Path $Web $name
        $repoLit = $Repo.Replace('\', '\\').Replace("'", "\'")
        $probe = @"
<?php
declare(strict_types=1);
// Temporary probe written by Test-SLOCloud5.ps1 and deleted after one request.
`$addr = (string)(`$_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array(`$addr, ['127.0.0.1', '::1'], true) || !hash_equals('$token', (string)(`$_SERVER['HTTP_X_SLOCLOUD_PROBE'] ?? ''))) { http_response_code(404); exit; }
header('Content-Type: application/json'); header('Cache-Control: no-store');
function probe_write(string `$dir): ?bool {
    if (`$dir === '' || !is_dir(`$dir)) { return null; }
    `$f = `$dir . DIRECTORY_SEPARATOR . '.slocloud-probe-' . bin2hex(random_bytes(8));
    `$ok = @file_put_contents(`$f, 'x') !== false;
    if (`$ok) { @unlink(`$f); }
    return `$ok;
}
`$data = (string)getenv('SLOCLOUD_DATA_PATH');
`$ini = [];
foreach (['date.timezone','upload_tmp_dir','sys_temp_dir','curl.cainfo','openssl.cafile','max_execution_time','memory_limit','post_max_size','upload_max_filesize','session.gc_maxlifetime','display_errors','expose_php','allow_url_fopen','opcache.enable','error_log','extension_dir'] as `$k) { `$ini[`$k] = ini_get(`$k); }
echo json_encode([
    'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'zts' => PHP_ZTS, 'bits' => PHP_INT_SIZE * 8,
    'iniFile' => php_ini_loaded_file(), 'extensions' => get_loaded_extensions(), 'zend' => get_loaded_extensions(true),
    'argon2' => defined('PASSWORD_ARGON2ID'), 'ini' => `$ini,
    'env' => ['APP_POOL_ID' => getenv('APP_POOL_ID'), 'SLOCLOUD_DATA_PATH' => `$data, 'SLOCLOUD_ENV' => getenv('SLOCLOUD_ENV'), 'SLOCLOUD_FORCE_HTTPS' => getenv('SLOCLOUD_FORCE_HTTPS'), 'PHPRC' => getenv('PHPRC')],
    'https' => (string)(`$_SERVER['HTTPS'] ?? ''),
    'write' => ['data' => probe_write(`$data), 'dataTmp' => probe_write(`$data . DIRECTORY_SEPARATOR . 'tmp'), 'webConfig' => probe_write(__DIR__ . DIRECTORY_SEPARATOR . 'config'), 'webRoot' => probe_write(__DIR__)],
    'repoListable' => @scandir('$repoLit') !== false,
    'caBundle' => is_file((string)ini_get('curl.cainfo')),
], JSON_UNESCAPED_SLASHES);
"@
        try {
            [System.IO.File]::WriteAllText($probePath, $probe, (New-Object System.Text.UTF8Encoding($false)))
            $r = [SloValidatorHttp]::Get("https://localhost/$name", $thumb, 'X-SLOCloud-Probe', $token)
        } finally {
            if (Test-Path -LiteralPath $probePath) { Remove-Item -LiteralPath $probePath -Force }
        }
        if (Test-Path -LiteralPath $probePath) { Add-Result 'probe' 'FAIL' "could not delete probe $probePath; delete it manually" } else { Add-Result 'probe' 'PASS' 'probe removed' }
        $j = $null
        if ($r.Status -eq 200) { try { $j = $r.Body | ConvertFrom-Json } catch { } }
        if (-not $j) { Add-Result 'probe' 'FAIL' "FastCGI probe failed: HTTP $($r.Status) $($r.Error)" } else {
            Add-Result 'probe' 'PASS' "PHP $($j.version) via $($j.sapi), ini $($j.iniFile)"
            if ($j.sapi -ne 'cgi-fcgi') { Add-Result 'probe' 'FAIL' "SAPI is $($j.sapi), expected cgi-fcgi" }
            if ([string]$j.env.APP_POOL_ID -eq $AppPoolName) { Add-Result 'probe' 'PASS' "runs in app pool $AppPoolName" } else { Add-Result 'probe' 'FAIL' "APP_POOL_ID is '$($j.env.APP_POOL_ID)'" }
            if ([string]$j.env.SLOCLOUD_DATA_PATH -ieq $Data) { Add-Result 'probe' 'PASS' 'SLOCLOUD_DATA_PATH visible to PHP' } else { Add-Result 'probe' 'FAIL' "PHP sees SLOCLOUD_DATA_PATH='$($j.env.SLOCLOUD_DATA_PATH)' (app would fall back to $Root\web-data)" }
            if ([string]$j.env.SLOCLOUD_ENV -eq 'production') { Add-Result 'probe' 'PASS' 'SLOCLOUD_ENV=production' } else { Add-Result 'probe' 'FAIL' "SLOCLOUD_ENV='$($j.env.SLOCLOUD_ENV)'" }
            if ([string]$j.https -ieq 'on') { Add-Result 'probe' 'PASS' 'HTTPS=on seen by PHP' } else { Add-Result 'probe' 'WARN' "HTTPS server variable '$($j.https)'" }
            $ext = @($j.extensions) + @($j.zend)
            foreach ($e in @('PDO', 'pdo_sqlsrv', 'yaml', 'openssl', 'mbstring', 'json', 'curl', 'fileinfo', 'dom', 'iconv', 'session', 'Zend OPcache')) {
                if ($ext -contains $e) { Add-Result 'probe' 'PASS' "FastCGI extension $e" } else { Add-Result 'probe' 'FAIL' "FastCGI extension $e missing" }
            }
            if ($j.argon2) { Add-Result 'probe' 'PASS' 'Argon2id available under FastCGI' } else { Add-Result 'probe' 'FAIL' 'Argon2id missing under FastCGI' }
            if ($j.ini.'date.timezone') { Add-Result 'probe' 'PASS' "date.timezone $($j.ini.'date.timezone')" } else { Add-Result 'probe' 'FAIL' 'date.timezone empty under FastCGI' }
            if ([int]$j.ini.'session.gc_maxlifetime' -ge 28800) { Add-Result 'probe' 'PASS' 'session.gc_maxlifetime >= 28800' } else { Add-Result 'probe' 'FAIL' "session.gc_maxlifetime $($j.ini.'session.gc_maxlifetime') (< 8 h LTI session)" }
            if (@('', '0', 'Off') -contains [string]$j.ini.display_errors) { Add-Result 'probe' 'PASS' 'display_errors off' } else { Add-Result 'probe' 'FAIL' "display_errors '$($j.ini.display_errors)'" }
            if ($j.caBundle) { Add-Result 'probe' 'PASS' 'CA bundle readable for curl/openssl' } else { Add-Result 'probe' 'WARN' 'curl.cainfo bundle not readable (Canvas deploy TLS will fail)' }
            if ($j.write.data -eq $true -and $j.write.dataTmp -eq $true) { Add-Result 'probe' 'PASS' 'app pool can write data and data\tmp' } else { Add-Result 'probe' 'FAIL' "app pool write: data=$($j.write.data) tmp=$($j.write.dataTmp)" }
            if ($j.write.webConfig -eq $true) { Add-Result 'probe' 'PASS' 'app pool can write web\config (wizard and Configuration page)' } else { Add-Result 'probe' 'FAIL' 'app pool cannot write web\config' }
            if ($j.write.webRoot -eq $false) { Add-Result 'probe' 'PASS' 'app pool cannot write the web root' } else { Add-Result 'probe' 'FAIL' 'app pool can write the web root' }
            if ($j.repoListable -eq $false) { Add-Result 'probe' 'PASS' 'app pool cannot list the repo folder' } else { Add-Result 'probe' 'FAIL' 'app pool can list the repo folder' }
        }
    }
}

# ---------------------------------------------------------------------------
# SQL TCP / time
# ---------------------------------------------------------------------------

if ($SqlHost) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($SqlHost, $SqlPort, $null, $null)
        if ($iar.AsyncWaitHandle.WaitOne(5000)) { $client.EndConnect($iar); Add-Result 'sql' 'PASS' "TCP ${SqlHost}:$SqlPort reachable (port only; not proof of database readiness)" }
        else { Add-Result 'sql' 'IT' "TCP ${SqlHost}:$SqlPort timed out" }
    } catch { Add-Result 'sql' 'IT' "TCP ${SqlHost}:$SqlPort unreachable: $($_.Exception.InnerException.Message)" } finally { $client.Close() }
} else { Add-Result 'sql' 'INFO' 'no SQL host given' }
$w32 = Invoke-Exe "$env:windir\System32\w32tm.exe" @('/query', '/status')
if ($w32.ExitCode -eq 0) { Add-Result 'time' 'INFO' (($w32.Output | Where-Object { $_ -match '^(Source|Last Successful Sync Time)' }) -join '; ') } else { Add-Result 'time' 'WARN' 'w32tm /query /status failed; confirm NTP (LTI 300 s and SAML 60 s skew limits)' }

# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------

$fails = @($results | Where-Object { $_.status -eq 'FAIL' }).Count
$warns = @($results | Where-Object { $_.status -eq 'WARN' }).Count
$its = @($results | Where-Object { $_.status -eq 'IT' })
if (-not $OutputPath) {
    $dir = Join-Path $env:ProgramData 'SLOCloud5\installer\validation'
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $OutputPath = Join-Path $dir ('validation-{0}.json' -f (Get-Date).ToString('yyyyMMdd-HHmmss'))
}
$report = [ordered]@{
    generatedAt = (Get-Date).ToString('o'); host = $env:COMPUTERNAME; installRoot = $Root; site = $SiteName; appPool = $AppPoolName
    summary = [ordered]@{ fail = $fails; warn = $warns; it = $its.Count; pass = @($results | Where-Object { $_.status -eq 'PASS' }).Count }
    results = $results
}
[System.IO.File]::WriteAllText($OutputPath, ($report | ConvertTo-Json -Depth 8), (New-Object System.Text.UTF8Encoding($false)))
Write-Host ''
Write-Host ("Validation: {0} FAIL, {1} WARN, {2} IT. Report: {3}" -f $fails, $warns, $its.Count, $OutputPath) -ForegroundColor $(if ($fails -gt 0) { 'Red' } elseif ($warns -gt 0) { 'Yellow' } else { 'Green' })
if ($its.Count -gt 0) {
    Write-Host 'Items requiring IT:' -ForegroundColor Yellow
    foreach ($i in $its) { Write-Host " - $($i.message)" -ForegroundColor Yellow }
}
exit $(if ($fails -gt 0) { 1 } else { 0 })
