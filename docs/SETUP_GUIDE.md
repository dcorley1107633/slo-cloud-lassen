# SLOCloud Setup Guide

**Version:** 5.0.2
**Audience:** System administrators
**Platform:** Windows Server with SQL Server 2022/2025 and PHP 8.1+

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [SQL Server](#2-sql-server)
3. [PHP](#3-php)
4. [Web Server](#4-web-server)
5. [Deploy SLOCloud](#5-deploy-slocloud)
6. [Run the Web Installer](#6-run-the-web-installer)
7. [Post-Installation](#7-post-installation)
8. [LTI Integration (Optional)](#8-lti-integration-optional)
9. [Verification Checklist](#9-verification-checklist)
10. [Troubleshooting](#10-troubleshooting)
11. [Key Rotation](#11-key-rotation)
12. [Upgrading](#12-upgrading)

---

## 1. Prerequisites

| Component | Minimum |
|-----------|---------|
| PHP | 8.1 |
| SQL Server | 2022 |
| Web Server | IIS 10+ or Apache 2.4+ |
| SQLSRV PHP Driver | 5.11+ ([microsoft/msphpsql](https://github.com/microsoft/msphpsql/releases)) |

**Required PHP extensions:** `pdo`, `pdo_sqlsrv`, `sqlsrv`, `yaml`, `mbstring`, `openssl`, `json`, `session`, `fileinfo`

**Optional:** `curl`

**Required php.ini settings:**

```ini
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 120
display_errors = Off
log_errors = On
session.cookie_httponly = 1
session.use_strict_mode = 1
session.cookie_samesite = Strict
```

---

## 2. SQL Server

Install and harden SQL Server according to your institution's security standards. SLOCloud requires:

- A dedicated database with `SQL_Latin1_General_CP1_CI_AS` collation (case-insensitive, accent-sensitive).
- A dedicated application login. Do not reuse credentials from any other system or share this account with other applications.
- Runtime permissions: `db_datareader` and `db_datawriter` only.
- Setup-only permissions: `CREATE TABLE` and `ALTER ON SCHEMA::dbo` — revoke immediately after the setup wizard completes.
- TCP port 1433 reachable from the web server.

---

## 3. PHP

Install PHP 8.1+ with the extensions listed in section 1. Use `php.ini-production` as your baseline and apply the settings from section 1.

Verify:

```powershell
php --version
php -m | Select-String "sqlsrv|pdo_sqlsrv|yaml|mbstring|openssl|json|fileinfo"
```

---

## 4. Web Server

Install and harden your web server according to your institution's security standards. SLOCloud requires:

- Document root pointed at `src/` — do not expose the repository root.
- PHP handled via FastCGI.
- The web server process identity needs **read** on the deployed source, **read+write** on `config/`, and **read+write** on the protected application data directory.
- Web access to `*.yaml`, `*.sql`, and `*.log` files must be blocked. Apache deployments can use the included `.htaccess` in `src/config/`; IIS deployments require equivalent request filtering rules.
- HTTPS required in production.

Runtime data is created on first use and is not uploaded with `src/`. In a source checkout it defaults to root-level `logs/` and `var/`. A flat deployment that copies only the contents of `src/` uses a protected per-application sibling such as `C:\inetpub\slocloud-data\`. Set `SLOCLOUD_DATA_PATH` to an explicit absolute directory if a different location is required, and grant the web server identity read/write access to it.

---

## 5. Deploy SLOCloud

```powershell
git clone https://github.com/your-org/slocloud.git C:\slocloud-source
Copy-Item -Path "C:\slocloud-source\src\*" -Destination "C:\inetpub\slocloud\" -Recurse -Force
```

After copying, confirm these exist in the web root:

```
index.php
login.php
setup/index.php
config/config.yaml.template
system/database/schema_mssql.sql
```

---

## 6. Run the Web Installer

Generate a one-time installation token on the application server:

```powershell
cd C:\inetpub\slocloud
php system/scripts/create_setup_token.php
```

Open the tokenized `/setup/?token=...` URL printed by the command. The token expires after 15 minutes. Setup accepts localhost by default; for a remote management workstation, explicitly set `SLOCLOUD_SETUP_ALLOWED_IPS` to its IP address before generating the token.

Fill in the form:

- **Site name** and **Base URL** (`/` or subdirectory path such as `/slocloud/`)
- **Database driver:** `mssql`
- **Database host, port, database name, table prefix**
- **Database username and password** (the dedicated account from section 2)
- **Email/SMTP** (optional — can be configured later in `config.yaml`)

- **Initial administrator name, email, and password** (minimum 12 characters)

> **WARNING:** Database driver, database name, and table prefix are locked after installation. Changing them requires reinstalling.

The installer creates the schema, seeds default data, generates encryption keys, and writes `config.yaml`. No manual key setup is required.

Emergency access is disabled by default. If institutional policy requires it, provision a password hash through an approved break-glass procedure and store the source credential in the institution's offline password vault.

### Required upgrade cleanup for older installations

Before exposing an upgraded installation, delete or disable any legacy `sloadmin@example.com` account, replace any legacy shared LTI key/secret, and invalidate existing sessions. The application now refuses the shipped legacy placeholder values, so provision unique credentials before restarting the upgraded production service.

---

## 7. Post-Installation

Open `src/config/config.yaml` and confirm for production:

```yaml
app:
  debug_mode: false
  php_error_mode: logged
```

Revoke the setup-only DDL permissions from the application database user (`CREATE TABLE` and `ALTER ON SCHEMA::dbo`).

### Harden the Encryption Key Location (Recommended)

The setup wizard writes `key.yaml` (the master encryption keys) to `src/config/`, **inside the web root**. This is intentional — it makes the default install work with zero extra configuration, and the folder is blocked from HTTP access by the shipped `src/config/.htaccess` and `src/config/web.config`.

For production, best practice is to move the key **outside the web root** so it can never be exposed by a web-server misconfiguration, and point the application at the new location with an environment variable:

1. Move the file, e.g. `C:\secrets\slocloud\key.yaml` (Windows) or `/etc/slocloud/key.yaml` (Linux), and restrict read access to the web-server/app-pool identity only.
2. Set the environment variable **`SLOCloud_KEY_PATH`** to that absolute path. It is checked first in [Config.php](../src/system/Core/Config.php) — before the in-web-root fallback locations — so no code change is needed.
   - **IIS:** `applicationHost.config` → the site's FastCGI app → `<environmentVariables>`:
     `<add name="SLOCloud_KEY_PATH" value="C:\secrets\slocloud\key.yaml" />`, then recycle the app pool.
   - **Linux php-fpm:** add `env[SLOCloud_KEY_PATH] = /etc/slocloud/key.yaml` to the pool config (php-fpm clears the environment by default, so a shell `export` will not reach it).
3. Delete the copy left in `src/config/`, then confirm the app still loads and decrypts data.

> **Note:** environment variable names are case-sensitive on Linux (`SLOCloud_KEY_PATH` exactly) but case-insensitive on Windows. If the variable is misnamed, the app silently falls back to the in-web-root `key.yaml` and appears to work — so verify by removing the in-root copy and confirming the app still runs. See [src/config/README.md](../src/config/README.md) for full details.

---

## 8. LTI Integration (Optional)

SLOCloud supports LTI 1.1.

> **Important:** Correct credentials are required for a launch to succeed, but they are not sufficient for correct behavior. SLOCloud also requires that the LMS passes a course identifier matching an imported section. The field and format are configured in `config.yaml` under `lti.offering_field` and `lti.offering_format` (defaults: `lis_course_offering_sourcedid` and `{CRN}.{TERM}`). A mismatch here will not fail the launch — it will show no class or the wrong class.

### Finding Your Credentials

1. Log in to SLOCloud administration and go to **System > LTI Configuration**.
2. Create or locate the consumer record for your LMS.
3. The **Consumer Key** and **Consumer Secret** displayed there are what you enter in your LMS when registering SLOCloud as an external tool.
4. Set **LTI Version** to `1.1`.

### What SLOCloud Provides to Your LMS

| Item | Value |
|------|-------|
| LTI 1.1 launch URL | `https://yourdomain.edu/lti/launch.php` |
| Consumer Key | From the SLOCloud admin record |
| Consumer Secret | From the SLOCloud admin record |

Refer to your LMS documentation for how to register an external LTI tool.

### Testing

Launch the tool from a course in your LMS and verify roles (instructor/student) and course section are correctly identified.

---

## 9. Verification Checklist

### Infrastructure
- [ ] `php --version` shows 8.1+
- [ ] `php -m | Select-String "sqlsrv|pdo_sqlsrv|yaml|mbstring|openssl|json|fileinfo"` shows all extensions
- [ ] SQL Server reachable on port 1433
- [ ] Web server process has write access to deployed `config/` and the protected application data directory

### Database
- [ ] Tables created (24+ tables with configured prefix)
- [ ] Application user has `db_datareader` + `db_datawriter` only
- [ ] DDL permissions revoked after setup

### Application
- [ ] `src/config/config.yaml` exists
- [ ] `SLOCloud_KEY_PATH` environment variable set (if key file is stored outside the web root)
- [ ] Login page loads
- [ ] Navigating to `/setup/` redirects away

### Security
- [ ] `src/config/config.yaml` returns 403 in browser
- [ ] `src/config/key.yaml` returns 403 in browser (if inside web root)
- [ ] HTTPS enabled and HTTP redirects to HTTPS
- [ ] `debug_mode: false` in `config.yaml`
- [ ] Break-glass password set to a strong value and stored offline

### System Status

Log in and go to **System > System Status** for a built-in health check covering database connectivity, PHP extensions, file permissions, and configuration validity.

---

## 10. Troubleshooting

### PHP Cannot Connect to SQL Server

- SQL Server running with TCP/IP enabled (SQL Server Configuration Manager)
- Port 1433 reachable from the web server
- `sqlsrv` and `pdo_sqlsrv` loaded (`php -m`)
- PHP DLL version matches PHP version

### Setup Cannot Write config.yaml

- Web server process identity has write access to `src/config/`

### key.yaml Not Found

- `SLOCloud_KEY_PATH` environment variable is set and the web server was restarted after
- Path is absolute and the file exists at that location
- Web server process can read the key file

### Strict Types Fatal Error

A PHP file has a UTF-8 BOM. Re-save without BOM.

### Session Not Persisting / Constant Logouts

- The runtime-created `var/sessions/` exists under the protected application data directory, outside the document root, and is writable only by the web server process
- Site is served over HTTPS (`Secure` cookie flag requires it)

### Tables Not Created After Setup

- Application user had `CREATE TABLE` and `ALTER ON SCHEMA::dbo` during setup
- Check the root-level `logs/` directory for details

### LTI Launch Fails (Authentication)

A credential or role problem will reject the launch outright with an error.

- Consumer key and secret in the LMS match exactly what is in SLOCloud's consumer record
- Launch URL is reachable from the LMS server
- Check the root-level `logs/` directory for details

### LTI Launch Succeeds but Shows No Class or the Wrong Class

The launch was authenticated but SLOCloud could not match it to a section. This is a data problem, not a credential problem.

- `lti.offering_field` in `config.yaml` matches the LMS field that carries the course identifier
- `lti.offering_format` in `config.yaml` matches the format of the value the LMS sends (default: `{CRN}.{TERM}`)
- The CRN and term in the offering value have been imported into SLOCloud — a launch for a section that was never imported will find nothing
- Check the protected operational log for the launch-matching result (raw LTI payloads are never logged)

---

## 11. Key Rotation

Keys can be rotated from **System > Configuration > Encryption Key Rotation** without touching the filesystem.

Rotate when:

- Periodically (annually recommended)
- After a suspected compromise of `key.yaml` or `config.yaml`
- After a staff change for anyone with server filesystem access
- After migrating to a new server

> **WARNING:** Back up `key.yaml` and `config.yaml` before rotating the Config Key. Back up the database before rotating the Student Key.

Two rotation options:

- **Rotate Config Key** — re-encrypts all `ENC:` values in `config.yaml` with a new key.
- **Rotate Student Key** — re-encrypts all student PII in the database with a new key.

All steps must show `[OK]` before changes are committed. If any step fails, the originals are automatically restored.

---

## 12. Upgrading

### Minor (e.g., 5.0.1 to 5.0.2)

1. Back up `src/config/config.yaml` and your database.
2. Replace `src/` with the new release — do not overwrite `src/config/config.yaml`.
3. New config keys are applied automatically via template merging. No schema migration needed.

### Major (e.g., 4.x to 5.x)

1. Back up your database fully.
2. Review `src/PATCH_NOTES.md` for breaking changes and schema migrations.
3. Test in a staging environment before upgrading production.
