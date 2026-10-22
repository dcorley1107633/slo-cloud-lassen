# SLOCloud System Requirements

App version: 5.2.2

SLOCloud is a plain PHP web application.

- No framework.
- No Composer or third-party PHP packages.
- No Node.js or build step.
- Browser libraries (Bootstrap, jQuery, DataTables, AdminLTE, Font Awesome) are bundled in `web\assets\vendor`. No CDN access required.
- Deployment: the contents of `src\` become the web root.
- Runtime requirements: PHP and the extensions in 1.4.

**Automated install.** The web node in section 1 is built by the PowerShell installer in [automation/README.md](../automation/README.md). It handles IIS, PHP, folders, permissions, environment variables, the localhost HTTPS binding and application deployment, and it can roll back or uninstall. This document is the reference specification behind it and the checklist for a manual install. Paths below use the installer's default layout.

| Node | Count | Role |
|---|---|---|
| Web node | 1 | Windows Server, IIS, PHP FastCGI |
| Database node | 1 | Windows Server, SQL Server |

Web node paths (install root `D:\SLO Cloud 5`):

| Item | Path |
|---|---|
| Web root (IIS site physical path) | `D:\SLO Cloud 5\web` |
| Config | `D:\SLO Cloud 5\web\config\config.yaml` |
| Secrets (encryption keys) | `D:\SLO Cloud 5\secrets\key.yaml` |
| App data | `D:\SLO Cloud 5\data` |
| Logs | `D:\SLO Cloud 5\data\logs` |
| Temp / uploads | `D:\SLO Cloud 5\data\tmp` |
| Application snapshots | `D:\SLO Cloud 5\backup` |
| PHP | `D:\SLO Cloud 5\php` |
| Source checkout | `D:\SLO Cloud 5\repo` |
| Installer journal and backups | `C:\ProgramData\SLOCloud5\installer` |

The install root contains spaces, so quote every path in `php.ini`, `appcmd` and scripts.

---

## 1. Web Node

### 1.1 Hardware / OS

| Item | Minimum | Recommended |
|---|---|---|
| OS | Windows Server 2022 Standard | Windows Server 2025 Standard |
| vCPU | 2 | 4 |
| RAM | 8 GB | 16 GB |
| C: OS | 160 GB | 240 GB |
| D: PHP, site, secrets, app data, snapshots, logs, temp | 80 GB | 200 GB |

- The installer itself runs on Windows Server 2016 or later; 2022 or later is the supported baseline.
- NTP time sync. LTI max clock skew 300 s. SAML max clock skew 60 s.
- Sessions, login throttle and report definitions are stored on local disk.

### 1.2 IIS Role Services

```powershell
Install-WindowsFeature Web-Server,Web-Default-Doc,Web-Static-Content,Web-Http-Errors,Web-Http-Logging,Web-Request-Monitor,Web-Filtering,Web-CGI,Web-Mgmt-Console,Web-Scripting-Tools
```

- Do not install: ASP.NET, WebDAV, Directory Browsing. If Windows adds Directory Browsing as a default role service, the application's `web.config` disables it.

### 1.3 Software

| Component | Version |
|---|---|
| IIS URL Rewrite | 2.1 (required by `web.config`; without it every request returns 500.19) |
| Visual C++ Redistributable | 2015–2022 x64, latest |
| PHP | 8.3 NTS x64 (8.1 minimum). Installer pins 8.3.35 |
| Microsoft ODBC Driver for SQL Server | 18 x64 (17 supported) |
| Microsoft Drivers for PHP for SQL Server | 5.11+ (installer pins 5.13.3); `pdo_sqlsrv` DLL matching the PHP version, NTS, x64 |
| PECL yaml | DLL matching the PHP version, NTS, x64 (installer pins 2.3.0). `yaml.dll` goes beside `php-cgi.exe` |
| CA bundle | `cacert.pem` in `D:\SLO Cloud 5\php\extras\ssl\` |
| PHP CLI | `php.exe` on the system PATH |

Pinned URLs and checksums: [automation/sources.json](../automation/sources.json).

### 1.4 PHP Extensions

| Extension | Required |
|---|---|
| pdo | Yes |
| pdo_sqlsrv | Yes |
| yaml | Yes |
| openssl | Yes |
| mbstring | Yes |
| curl | Yes (Canvas deploy) |
| fileinfo | Yes |
| opcache | Recommended |
| json, session, hash, random, ctype, filter, dom, libxml, zlib, iconv, pcre | Yes (built in) |
| Argon2 (PASSWORD_ARGON2ID) | Yes (built in) |
| sqlsrv | No |

The status page treats `pdo`, `yaml`, `json`, `mbstring`, `openssl`, a PDO database driver and Argon2 as breaking checks (`src/api/api.php`).

```powershell
php -m
php -r "echo defined('PASSWORD_ARGON2ID') ? 'argon2 ok' : 'argon2 missing';"
```

### 1.5 php.ini

Base: `php.ini-production`. The installer appends these settings in a marked block.

```ini
extension_dir           = "D:\SLO Cloud 5\php\ext"
max_execution_time      = 900
max_input_time          = 900
memory_limit            = 512M
upload_max_filesize     = 64M
post_max_size           = 64M
max_input_vars          = 3000
upload_tmp_dir          = "D:\SLO Cloud 5\data\tmp"
sys_temp_dir            = "D:\SLO Cloud 5\data\tmp"
date.timezone           = America/Los_Angeles
session.gc_maxlifetime  = 28800
session.cookie_httponly = 1
session.use_strict_mode = 1
display_errors          = Off
display_startup_errors  = Off
log_errors              = On
error_log               = "D:\SLO Cloud 5\data\logs\php_errors.log"
expose_php              = Off
allow_url_fopen         = Off
cgi.force_redirect      = 0
cgi.fix_pathinfo        = 1
fastcgi.impersonate     = 1
fastcgi.logging         = 0
curl.cainfo             = "D:\SLO Cloud 5\php\extras\ssl\cacert.pem"
openssl.cafile          = "D:\SLO Cloud 5\php\extras\ssl\cacert.pem"

; php_pdo_sqlsrv_83_nts_x64.dll is copied to ext\php_pdo_sqlsrv.dll
extension      = pdo_sqlsrv
extension      = yaml
extension      = openssl
extension      = mbstring
extension      = curl
extension      = fileinfo
zend_extension = opcache
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
```

- `date.timezone` is required. The app does not apply `app.timezone`.
- `session.gc_maxlifetime` minimum 28800. The app uses a 2 h admin session and an 8 h LTI session.
- `session.save_path` is not needed: the app stores sessions in `<data>\var\sessions`.
- `disable_functions` must not include set_time_limit, ini_set, session_*, curl_*, openssl_*.

### 1.6 Timeouts

| Layer | Setting | Value |
|---|---|---|
| Load balancer / proxy | Idle timeout | ≥ 3600 s |
| IIS FastCGI | activityTimeout | 3600 s |
| IIS FastCGI | requestTimeout | 3600 s |
| PHP | max_execution_time | 900 s |
| App, CSV import | set_time_limit | 0 (unlimited; FastCGI limit applies) |
| App, Canvas deploy batch | set_time_limit | 90 s |

### 1.7 FastCGI / Handler

The handler is mapped for the SLOCloud site only, not server-wide.

```bat
%windir%\system32\inetsrv\appcmd set config -section:system.webServer/fastCgi /+"[fullPath='D:\SLO Cloud 5\php\php-cgi.exe',activityTimeout='3600',requestTimeout='3600',idleTimeout='300',instanceMaxRequests='10000',maxInstances='0',queueLength='1000']" /commit:apphost

%windir%\system32\inetsrv\appcmd set config -section:system.webServer/fastCgi /+"[fullPath='D:\SLO Cloud 5\php\php-cgi.exe'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']" /commit:apphost

%windir%\system32\inetsrv\appcmd set config "SLOCloud5" -section:system.webServer/handlers /+"[name='SLOCloud5-PHP_via_FastCGI',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='D:\SLO Cloud 5\php\php-cgi.exe',resourceType='File',requireAccess='Script']" /commit:apphost
```

The application uses only GET, HEAD and POST.

### 1.8 Site / App Pool

| Setting | Value |
|---|---|
| Site / app pool name | `SLOCloud5` |
| Physical path | `D:\SLO Cloud 5\web` (contents of `src\`) |
| Bindings, initial install | HTTPS `*:443:localhost` (SNI) with a self-signed certificate (SAN `DNS=localhost`). No HTTP binding |
| Bindings, production | HTTPS 443 with a CA-issued certificate for the production FQDN. HTTP 80 for redirect only. A separate IT change |
| TLS | 1.2+. SSL 3.0, TLS 1.0 and TLS 1.1 disabled (server-wide SCHANNEL, IT change) |
| maxAllowedContentLength | 67108864 (64 MB) |
| Authentication | Anonymous only |
| Anonymous identity | Application pool identity (`userName=""`) |
| .NET CLR | No Managed Code |
| Pipeline | Integrated |
| Identity | ApplicationPoolIdentity (`IIS AppPool\SLOCloud5`) or a dedicated service account |
| Recycling | Regular interval disabled. Fixed off-hours time (installer: 03:00) |

```bat
%windir%\system32\inetsrv\appcmd set config "SLOCloud5" -section:system.webServer/security/requestFiltering /requestLimits.maxAllowedContentLength:67108864 /commit:apphost
```

- Never add MIME maps for `.sql`, `.md`, `.yaml` or `.log`. Schema files and Markdown documents sit in the web root and are unreachable only because IIS has no MIME type for them.
- The application's `web.config` uses `rewrite`, `httpErrors`, `requestFiltering`, `httpProtocol`, `defaultDocument` and `directoryBrowse`. None of these sections may be locked for the site.

### 1.9 Environment Variables

Set in FastCGI `<environmentVariables>`. Variables marked Machine = Yes also go at machine level for CLI scripts, with the same values.

| Variable | Value | Required | Machine |
|---|---|---|---|
| SLOCLOUD_DATA_PATH | `D:\SLO Cloud 5\data` | Yes. Without it the app falls back to `<web root>-data` | Yes |
| SLOCLOUD_ENV | `production` | Yes | Yes |
| SLOCLOUD_FORCE_HTTPS | `true` | Yes | No |
| PHP_FCGI_MAX_REQUESTS | `10000` | Yes | No |
| PHPRC | `D:\SLO Cloud 5\php` | Recommended (pins the php.ini used by FastCGI) | No |
| SLOCloud_KEY_PATH | Full path to `key.yaml` | Optional. Setup records `security.key_path` in config.yaml. The name is mixed-case exactly as shown | Optional |
| SLOCLOUD_HSTS_MAX_AGE | `31536000` | Optional, production FQDN only. Do not set for localhost | No |
| SLOCLOUD_TRUSTED_PROXIES | Proxy IPs | Only behind a TLS-terminating proxy | No |
| SLOCLOUD_SETUP_ALLOWED_IPS | Admin workstation IP | Only to run setup from another machine. Not needed when setup runs on the server | No |

```powershell
[Environment]::SetEnvironmentVariable('SLOCLOUD_DATA_PATH','D:\SLO Cloud 5\data','Machine')
[Environment]::SetEnvironmentVariable('SLOCLOUD_ENV','production','Machine')
```

### 1.10 Folders / NTFS Permissions

```
D:\SLO Cloud 5\
    repo\                     Git checkout (not served)
    web\                      web root (IIS site): contents of repo\src
        config\               config.yaml, config.yaml.bak, index.php, .htaccess
    secrets\                  key.yaml (keys folder chosen in setup), backups\ from key rotation
    data\                     app data (SLOCLOUD_DATA_PATH)
        var\sessions\
        var\reports\          custom report definitions, catalog
        var\loginlimits\
        var\ltilimits\
        var\canvas_deploy\
        var\key_rotation.lock
        logs\                 90-day retention
        tmp\                  upload_tmp_dir, sys_temp_dir
    backup\                   application snapshots (snapshot function)
    php\                      PHP runtime
    tools\                    MinGit, only if Git was not installed
```

| Path | App pool identity | Administrators, SYSTEM | Users / IUSR / IIS_IUSRS |
|---|---|---|---|
| `D:\SLO Cloud 5` | Read & Execute, this folder only | Full | None |
| `repo` | None | Full | None |
| `web` | Read & Execute | Full | None |
| `web\config` | Modify | Full | None |
| `secrets` | Modify during setup; afterwards Modify (UI key rotation works) or Read | Full | None |
| `data` | Modify | Full | None |
| `backup` | Modify | Full | None |
| `php` | Read & Execute | Full | None |

- Disable inheritance on the install root, then set ACLs per subfolder.
- Give the app pool Read & Execute on the install root itself, not inherited: `icacls "D:\SLO Cloud 5" /grant:r "IIS AppPool\SLOCloud5:(RX)"`. PHP's `realpath()` on Windows lists every parent folder, so without it setup reports the keys folder as missing. Grant the full Read & Execute set; `(RD)` alone lacks Synchronize and still fails.
- Create `secrets` before running setup. Setup refuses a keys folder that is missing, not writable by the app pool, or inside the web root.
- `web\config` needs permanent Modify: setup writes config.yaml, and the Configuration page, Canvas deploy and key rotation rewrite it.
- Key rotation (System > Configuration) writes `key.yaml` and a `backups\` folder beside it, so its backups stay outside the web root. The status page reports any `key.yaml` inside the web root, including old copies under `web\config\backups\`, as critical.

### 1.11 Firewall

The installer does not change the firewall.

Inbound:

| Source | Port | Purpose | Required |
|---|---|---|---|
| Users (campus, internet) | 443/TCP | Application, LTI launch | Yes |
| Any | 80/TCP | HTTPS redirect | Optional |
| Monitoring | 443/TCP | Status API | Optional |
| Admin subnet | 3389/TCP, 5985–5986/TCP | RDP, WinRM | Admin subnet only |

Setup runs on the server itself (`https://localhost/setup/`) and needs no inbound rule.

Outbound:

| Destination | Port | Purpose | Required |
|---|---|---|---|
| Database node | 1433/TCP | SQL Server | Yes |
| Canvas host | 443/TCP | Deploy to Canvas | If used |
| NTP | 123/UDP | Time sync | Yes |
| CRL/OCSP, Windows Update | 80, 443/TCP | Certificate revocation, patching | Yes |
| Download hosts in `automation/sources.json`, Git host | 443/TCP | Installer only | During install, unless installing offline |

- LTI launch is a browser POST. The web node must be reachable from any network where users access Canvas.
- SAML: browser redirect only. No server-to-server traffic.
- SMTP: not used.

### 1.12 Operations

- AV real-time scan exclusions: `D:\SLO Cloud 5\data\var\sessions`, `D:\SLO Cloud 5\data\tmp`.
- File backup: `web\config\config.yaml`; `secrets\key.yaml` (stored apart from DB backups); `data\var\reports`; off-server copies of `backup\`.
- Monitoring endpoint: `GET https://<fqdn>/api/api.php`, header `X-Api-Key: <api.monitoring_key>`.
- Validation: `automation\Test-SLOCloud5.ps1` runs a read-only check of IIS, PHP, TLS, ACLs and blocked URLs.

---

## 2. Database Node

### 2.1 Hardware / OS

| Item | Minimum | Recommended |
|---|---|---|
| OS | Windows Server 2022 Standard | Windows Server 2025 Standard |
| SQL Server | 2022 Standard | 2022 / 2025 Standard |
| vCPU | 4 | 8 |
| RAM | 16 GB | 32 GB |
| C: OS, SQL binaries | 100 GB | 120 GB |
| D: data (.mdf) | 50 GB | 100 GB |
| L: log (.ldf) | 25 GB | 50 GB |
| T: tempdb | 20 GB | 40 GB |
| B: backups or network share | 100 GB | 250 GB |

- D:, L:, T: NTFS, 64 KB allocation unit.
- SQL Express: not recommended (10 GB database limit).
- NTP time sync.

### 2.2 Instance

| Setting | Value |
|---|---|
| Authentication | Mixed Mode |
| TCP/IP | Enabled, static port 1433, dynamic ports off |
| SQL Browser | Off (default instance) |
| TLS | 1.2. App connects with Encrypt=yes; TrustServerCertificate=yes |
| Max server memory | Total RAM minus 4 GB |
| tempdb data files | 1 per core, max 8 |
| SQL Agent | Running |
| Maintenance | Weekly index and statistics |

### 2.3 Database

| Setting | Value |
|---|---|
| Name | `slocloud_db` (locked after install) |
| Collation | SQL_Latin1_General_CP1_CI_AS |
| Compatibility level | 160 (2022) / 170 (2025) |
| Autogrowth | Data 256 MB, log 128 MB, fixed size |
| Recovery model | FULL with log backups, or SIMPLE with nightly full |
| Backup | Nightly full, restore tested |
| Objects created by setup | 22 tables (including `meta`, which records the schema version), 15 triggers, indexes |

Setup attempts `CREATE DATABASE` and falls back to an existing, empty database when the login lacks that right. Creating the database in advance is recommended.

### 2.4 Login / Permissions

| Phase | Permissions |
|---|---|
| Setup | db_datareader, db_datawriter, CREATE TABLE, ALTER ON SCHEMA::dbo |
| Runtime | db_datareader, db_datawriter |
| Upgrade / migration script | Temporary CREATE TABLE, ALTER ON SCHEMA::dbo |

- Dedicated SQL login. Not shared with other systems. Password policy enforced.
- Default database: `slocloud_db`.

```sql
-- Setup
ALTER ROLE db_datareader ADD MEMBER slocloud_user;
ALTER ROLE db_datawriter ADD MEMBER slocloud_user;
GRANT CREATE TABLE TO slocloud_user;
GRANT ALTER ON SCHEMA::dbo TO slocloud_user;

-- After setup
REVOKE CREATE TABLE FROM slocloud_user;
REVOKE ALTER ON SCHEMA::dbo FROM slocloud_user;
```

### 2.5 Firewall

Inbound:

| Source | Port | Purpose | Required |
|---|---|---|---|
| Web node IP only | 1433/TCP | SQL Server | Yes |
| Admin subnet | 3389/TCP, 5985–5986/TCP | RDP, WinRM | Admin subnet only |

Outbound:

| Destination | Port | Purpose | Required |
|---|---|---|---|
| Backup share | 445/TCP | SMB backups | If used |
| NTP | 123/UDP | Time sync | Yes |
| CRL/OCSP, Windows Update | 80, 443/TCP | Certificate revocation, patching | Yes |

---

## 3. Install Checklist

Full steps, prompts and troubleshooting: [automation/README.md](../automation/README.md).

Database node (DBA):

- [ ] Database `slocloud_db`, login and setup permissions created (2.3, 2.4)
- [ ] Inbound 1433/TCP allowed from the web node only

Web node:

- [ ] VM snapshot taken
- [ ] `automation` folder copied to `D:\Install\automation` and unblocked
- [ ] `.\Install-SLOCloud5.ps1 -Plan` reviewed
- [ ] `.\Install-SLOCloud5.ps1` completed (IIS, PHP, folders, ACLs, environment, localhost HTTPS, application files)
- [ ] On the server, open `https://localhost/setup/` and complete the wizard:
  - [ ] Keys folder `D:\SLO Cloud 5\secrets`; paste the code from `slocloud-setup-code.txt`
  - [ ] Database driver `mssql`, host, port 1433, database, SQL login
  - [ ] Administrator account (password at least 12 characters)
  - [ ] Save the LTI consumer key and secret from the completion screen
- [ ] `.\Install-SLOCloud5.ps1 -FinalizeSetup` (sets the final `secrets` permissions)
- [ ] `.\Test-SLOCloud5.ps1`: 0 FAIL
- [ ] DDL permissions revoked (2.4)
- [ ] `SLOCLOUD_SETUP_ALLOWED_IPS` removed, if it was set
- [ ] System > System Status: all checks OK

Production cut-over (IT change):

- [ ] DNS record for the FQDN
- [ ] CA-issued certificate installed and bound for the FQDN; HTTP 80 redirect if wanted
- [ ] Firewall rules (1.11), TLS 1.0/1.1 disabled
- [ ] `SLOCLOUD_HSTS_MAX_AGE` set once HTTPS coverage is verified
- [ ] AV exclusions and backups (1.12)
