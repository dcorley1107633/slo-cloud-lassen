# SLO Cloud 5 Web Node Installer

PowerShell automation that prepares a Windows Server web node for SLO Cloud 5 and hands over to the application's own setup wizard. It also validates the result and can roll back or uninstall everything it did.

| Phase | Who does it | What happens |
|---|---|---|
| 1. Infrastructure | `Install-SLOCloud5.ps1` | IIS, PHP 8.5 FastCGI, folders and permissions, a localhost HTTPS binding, application files from Git |
| 2. Application setup | `/setup/` wizard (part of the app) | Database and schema, admin account, encryption keys (`key.yaml`), `config.yaml`, LTI credentials |
| 3. Finalize | `Install-SLOCloud5.ps1 -FinalizeSetup` | Final permissions on the secrets folder |

The installer **never** asks for SQL credentials, connects to SQL Server beyond a TCP port check, creates databases, or reads, copies or validates secret files. Those belong to the wizard.

> **Status:** written and tested off Windows (see [Verification status](#21-verification-status)). Run the [test matrix](#20-before-production-test-matrix) on a disposable Windows Server VM before production use.

---

## Contents

1. [Files in this folder](#1-files-in-this-folder)
2. [Requirements](#2-requirements)
3. [Quick start](#3-quick-start)
4. [What gets created on the server](#4-what-gets-created-on-the-server)
5. [Installer parameters](#5-installer-parameters)
6. [What the installer does, step by step](#6-what-the-installer-does-step-by-step)
7. [Prompts you may see](#7-prompts-you-may-see)
8. [Setup wizard handoff and finalization](#8-setup-wizard-handoff-and-finalization)
9. [Validation](#9-validation)
10. [Reruns and upgrades](#10-reruns-and-upgrades)
11. [Rollback and uninstall](#11-rollback-and-uninstall)
12. [Change journal, logs and backups](#12-change-journal-logs-and-backups)
13. [Download sources, integrity and offline installs](#13-download-sources-integrity-and-offline-installs)
14. [Security model](#14-security-model)
15. [What the installer deliberately does not change](#15-what-the-installer-deliberately-does-not-change)
16. [Items for IT](#16-items-for-it)
17. [Troubleshooting](#17-troubleshooting)
18. [Application prerequisites (traced to code)](#18-application-prerequisites-traced-to-code)
19. [Design decisions](#19-design-decisions)
20. [Before production: test matrix](#20-before-production-test-matrix)
21. [Verification status](#21-verification-status)
22. [Repository issues found during analysis](#22-repository-issues-found-during-analysis)
23. [Requirements-to-implementation matrix](#23-requirements-to-implementation-matrix)

---

## 1. Files in this folder

| File | Purpose | Changes the system |
|---|---|---|
| `Install-SLOCloud5.ps1` | Preflight, dry run (`-Plan`), install or upgrade, post-wizard finalization | Yes; every change is journaled before it is made |
| `Test-SLOCloud5.ps1` | Independent validation. It deliberately doesn't load the installer module, so a shared bug can't hide itself | Only a temporary PHP probe, deleted at once |
| `Undo-SLOCloud5.ps1` | Roll back one run, uninstall, list runs, archive the journal | Yes; driven by the journal |
| `lib/SLOCloud5.Common.psm1` | Shared module: journal, backups, ACL/IIS/registry helpers, verified downloads, revert engine | n/a |
| `sources.json` | Pinned download URLs, versions and integrity rules | n/a |
| `README.md` | This guide | n/a |

The scripts load `lib\` and `sources.json` from their own folder, so always copy the **whole** folder.

## 2. Requirements

| Item | Requirement |
|---|---|
| OS | Windows Server 2016 or later; 2022/2025 recommended. Client Windows is refused |
| Shell | Elevated, 64-bit **Windows PowerShell 5.1** (`powershell.exe`). PowerShell 7 (`pwsh`) is refused because the IIS WebAdministration provider is unreliable there |
| Access | Local administrator over RDP. WinRM is not used |
| Disk | The install drive must be a local fixed disk with at least 2 GB free (80 GB+ recommended for data and logs) |
| Network (outbound) | HTTPS to `downloads.php.net`, `github.com`, `curl.se`, `aka.ms`/`download.microsoft.com`/`go.microsoft.com`, and your Git host. Not needed with `-SourceCache` |
| SQL Server | Host and TCP port (default 1433). Only reachability is checked |
| Source | Git URL plus a **branch, tag or commit**. This repository has no `main` branch, so there's no default. A local folder is the alternative |
| Before you start | Take a **VM snapshot**. Undo can't guarantee a return to the exact earlier state of the whole server (section 11) |

Push before installing: the server deploys what's in Git, not uncommitted work on a developer machine.

## 3. Quick start

1. Copy this whole `automation` folder to the server as `D:\Install\automation`. Keep it outside the install root and outside any IIS site.
2. Open **Windows PowerShell (Admin)** and unblock the copied files:

   ```powershell
   Get-ChildItem D:\Install\automation -Recurse | Unblock-File
   cd D:\Install\automation
   ```

3. Dry run: preflight plus a list of every change. Nothing is changed.

   ```powershell
   .\Install-SLOCloud5.ps1 -Plan
   ```

4. Install. It prompts once for each value, shows a summary, and asks you to type `YES`.

   ```powershell
   .\Install-SLOCloud5.ps1
   ```

5. When prompted, run the setup wizard at `https://localhost/setup/` on the server (section 8), then press Enter so the installer finalizes.
6. Check the result:

   ```powershell
   .\Test-SLOCloud5.ps1
   ```

If the execution policy blocks the scripts, run them as `powershell.exe -ExecutionPolicy Bypass -File .\Install-SLOCloud5.ps1`.

`D:\Install` is only a launch folder. The journal and backups live in `C:\ProgramData\SLOCloud5\installer`, so deleting `D:\Install` later doesn't affect undo. After the first install the same scripts are also in `D:\SLO Cloud 5\repo\automation`, and you can run upgrades and undo from there.

Non-interactive example:

```powershell
.\Install-SLOCloud5.ps1 -NonInteractive -InstallRoot 'D:\SLO Cloud 5' `
  -RepositoryUrl https://github.com/aeron100/SLO-Cloud-5.git -Ref dev `
  -SqlHost sql01.campus.local -SqlPort 1433 -SecretsAccessAfterSetup Modify `
  -AcceptOdbcLicense -TrustedHashes @{ yaml = '<sha256 you verified>' } -SkipWizardWait
```

## 4. What gets created on the server

### Folders

The default install root is `D:\SLO Cloud 5`.

| Folder | Contents | App pool access | On uninstall |
|---|---|---|---|
| *(root)* | Container | read & execute on the folder itself only (not inherited) | Removed only if empty |
| `repo` | Staging copy of the source: a Git checkout reset to the deployed commit on every run. Never edit it | none | Removed (only if it has no local changes) |
| `web` | Contents of `repo\src`; the IIS site root | read & execute | Deployed files removed; folder kept if `config` remains |
| `web\config` | `config.yaml` and other files written by the wizard and the admin UI | modify | **Kept** |
| `data` | `SLOCLOUD_DATA_PATH`: `var\sessions`, `var\reports`, login throttles, locks, `logs`, `tmp` | modify | **Kept** |
| `secrets` | Keys folder for the wizard (`key.yaml`) | modify during setup, then per `-SecretsAccessAfterSetup` | **Kept**, contents never read |
| `backup` | Snapshots from the application's snapshot function (to be implemented); created empty | modify | **Kept** |
| `php` | PHP 8.5 NTS x64, `pdo_sqlsrv`, `yaml`, CA bundle, `php.ini` | read & execute | Removed if nothing else references it |
| `tools\mingit` | MinGit, only if `git.exe` wasn't installed; never added to PATH | none | Removed |

The install root has inheritance disabled: SYSTEM and Administrators get full control, plus only the grants above. Users, Authenticated Users, Everyone, IUSR and IIS_IUSRS get nothing.

The app pool's read & execute on the root lets it see the names of the folders there, not their contents. PHP needs it: on Windows, `realpath()` lists every parent folder, so without it the setup wizard reports the `secrets` folder as missing. Each subfolder keeps its own permissions; `repo` and `tools` stay closed to the app pool.

### IIS

| Resource | Setting |
|---|---|
| Application pool `SLOCloud5` | No Managed Code, Integrated pipeline, ApplicationPoolIdentity (`IIS AppPool\SLOCloud5`), 64-bit, no time-based recycling, daily recycle at 03:00 |
| Site `SLOCloud5` | Physical path `web`, **only** the binding `https *:443:localhost` (SNI) |
| Handler | `*.php` → FastCGI `php\php-cgi.exe`, scoped to this site only, first in the list |
| FastCGI application | `php-cgi.exe`; activity and request timeouts 3600 s, idle 300 s, 10000 requests per instance |
| FastCGI environment | `SLOCLOUD_DATA_PATH`, `SLOCLOUD_ENV=production`, `SLOCLOUD_FORCE_HTTPS=true`, `PHPRC`, `PHP_FCGI_MAX_REQUESTS=10000` |
| Authentication | Anonymous, running as the app pool identity |
| Request limit | `maxAllowedContentLength` 64 MB (CSV imports) |
| Certificate | Self-signed, SAN `DNS=localhost`, RSA 2048, non-exportable key, valid 2 years, in `LocalMachine\My` |
| HTTP.sys | `hostnameport=localhost:443` bound to that certificate |

**Browsers will not trust the certificate.** The warning on `https://localhost/` is expected for this phase, and the trust store is not modified.

### Machine-level settings

- Environment variables `SLOCLOUD_DATA_PATH` and `SLOCLOUD_ENV=production`, so CLI scripts such as migrations behave like the web app.
- The `php` folder is appended to the machine `PATH`. `REG_EXPAND_SZ` is preserved, and undo removes only that one entry.

### Shared components (installed only if missing)

IIS role services, Visual C++ 2015–2022 x64 runtime, IIS URL Rewrite 2.1, Microsoft ODBC Driver 18 for SQL Server. Undo never removes these (section 11).

## 5. Installer parameters

Values you don't pass are prompted for once. On a rerun, the previous answers become the defaults.

| Parameter | Default | Meaning |
|---|---|---|
| `-InstallRoot` | `D:\SLO Cloud 5` | Install root. Must be on a local fixed disk, not a drive root or system folder, and must not contain `' " % ; \| < > * ?` |
| `-RepositoryUrl` | `https://github.com/aeron100/SLO-Cloud-5.git` | Git URL. URLs with embedded credentials are refused |
| `-Ref` | *(none)* | Branch, tag or full commit SHA to deploy |
| `-LocalSourcePath` | | Use a local folder containing `src\index.php` instead of Git |
| `-SqlHost` / `-SqlPort` | / `1433` | SQL Server, used for a TCP check only |
| `-SiteName` / `-AppPoolName` | `SLOCloud5` | IIS names. Existing names not created by this installer are refused |
| `-SecretsAccessAfterSetup` | `Modify` | `Modify` keeps UI key rotation working; `Read` is least privilege (section 8) |
| `-TimeZone` | `America/Los_Angeles` | PHP `date.timezone` (required; the app doesn't apply `app.timezone`) |
| `-SourceCache` | | Folder with pre-downloaded sources (section 13) |
| `-TrustedHashes` | | e.g. `@{ yaml = '<sha256>' }` for sources without a published checksum |
| `-AcceptOdbcLicense` | | Accept the Microsoft ODBC Driver license without a prompt |
| `-AllowDefaultWebSiteStop` | | Approve stopping the Default Web Site if it already claims `https localhost:443` |
| `-OverwriteModifiedWebFiles` | | Replace web files changed outside the installer (backed up first) |
| `-OverwritePhpIni` | | Replace a hand-edited `php.ini` (backed up first) |
| `-Plan` | | Dry run: preflight plus a list of every change |
| `-FinalizeSetup` | | Only run the post-wizard step |
| `-NonInteractive` | | Never prompt; missing required values are errors |
| `-SkipValidation` | | Don't run `Test-SLOCloud5.ps1` at the end |
| `-SkipWizardWait` | | Don't wait for the wizard at the end |

Exit codes: `0` success, `1` failure (nothing after the failing step ran), `2` installed but validation reported failures.

## 6. What the installer does, step by step

Every change goes through the journal first (section 12). A step whose resource is already in the desired state is recorded as *skipped*.

1. **Preflight** (read-only). Each finding is labelled BLOCK, CONFIRM, WARN or INFO, and any BLOCK stops the run before anything changes. It checks:
   - the OS and build, the drive and its free space, a pending restart, and the install root path;
   - IIS site and application pool name collisions;
   - any IIS site, application or virtual directory that would serve the install root;
   - other sites' `localhost:443` bindings, HTTP.sys certificate bindings, and non-HTTP.sys processes listening on port 443;
   - a preexisting `php` folder, the Git checkout state, URLs with embedded credentials, and SQL TCP reachability;
   - machine-level `SLOCLOUD_DATA_PATH` or `SLOCLOUD_ENV` already set to a different value (CONFIRM: another install on the server may rely on it).
2. **Confirm**: type `YES` after the summary, plus any CONFIRM items.
3. **IIS configuration backup** (`appcmd add backup`, plus a copy of `applicationHost.config`).
4. **Windows features**: installs only the missing IIS role services and records exactly which were new.
5. **Shared packages**: Visual C++ runtime, URL Rewrite and the ODBC driver, each only if missing.
6. **Folders**: `root`, `web`, `secrets`, `backup`, `data` (`logs`, `tmp`, `var`).
7. **Source**: Git clone or fetch, then a forced detached checkout of the resolved commit. Any local edits or untracked files in `repo` are discarded (and listed as a warning in the log and journal), the result is verified to match the commit exactly, and `core.autocrlf=false` is set in the repo so manual Git commands there can't rewrite line endings. Or a mirror of `-LocalSourcePath`.
8. **PHP**: downloads and verifies PHP, `pdo_sqlsrv`, `yaml` and the CA bundle, then stages them and moves them into place. On an upgrade, the previous PHP is kept as a backup.
9. **`php.ini`**: `php.ini-production` plus a clearly marked managed block. Then a CLI check of the modules and Argon2id.
10. **Machine environment** and the PATH entry.
11. **Application pool**.
12. **Folder ACLs** (section 4).
13. **Default Web Site conflict**: handled only if that site already claims `localhost:443`.
14. **FastCGI application** and its environment variables.
15. **Site**: binding, anonymous authentication, request limit, PHP handler.
16. **Certificate** and the **HTTP.sys binding**.
17. **Deploy** `repo\src` → `web` (section 10), then grant Modify on `web\config`.
18. **Start and smoke test** `https://localhost/`. If IIS returns 500.19 because a section the app's `web.config` uses is locked at server level, the installer unlocks just that section for this site and retries.
19. **Manifest, IT items, validation**, then the **wizard handoff** and **finalization**.

## 7. Prompts you may see

| Prompt | When | What to do |
|---|---|---|
| `YES` | Before any change, and for CONFIRM findings (for example, an install root that already contains other items, or a machine-level `SLOCLOUD_*` variable that would be changed) | Read the summary and type `YES` |
| `TRUST` | First use of PECL `yaml`, which has no published checksum | Compare the shown SHA-256 with an independent download (for example from another machine), then type `TRUST`. The hash is pinned for later runs |
| `ACCEPT` | ODBC Driver 18 must be installed | Review the license (link shown), then type `ACCEPT` |
| `STOP` | The Default Web Site already has `https localhost:443` | Type `STOP` to stop it and disable its auto-start. It is never deleted, and undo restores it |
| Git credentials | Private repository over HTTPS | Enter them at Git's own prompt; they are not logged. MinGit has no credential manager, so use a personal access token as the password, or SSH |
| Enter / `LATER` | After install | Press Enter once the wizard has finished, or type `LATER` and run `-FinalizeSetup` afterwards |

## 8. Setup wizard handoff and finalization

The installer prints these steps when it finishes:

1. On the **server**, open `https://localhost/setup/`. The wizard only accepts requests from localhost, so no firewall change is needed. Accept the certificate warning.
2. Keys folder: `D:\SLO Cloud 5\secrets`.
3. Open `D:\SLO Cloud 5\secrets\slocloud-setup-code.txt` in Notepad and paste the code into the wizard. It expires after 15 minutes and allows 5 attempts.
4. Database driver `mssql`, with the host, port and SQL login from your DBA.
5. Save the LTI consumer key and secret shown on the completion screen.

The wizard then writes `web\config\config.yaml` and `secrets\key.yaml`. It refuses to run again while `config.yaml` exists.

**Finalization** runs automatically when you press Enter at the installer's prompt, or manually:

```powershell
.\Install-SLOCloud5.ps1 -FinalizeSetup -InstallRoot 'D:\SLO Cloud 5'
```

It checks only that `config.yaml` and `secrets\key.yaml` **exist**; neither file is opened. It then applies the final ACL on `secrets`:

- **Modify** (default): System > Configuration key rotation keeps working. `api/rotate_key.php` rewrites `key.yaml` and writes `backups\` beside it.
- **Read**: least privilege. Key rotation from the UI then fails until an administrator temporarily grants Modify to `IIS AppPool\SLOCloud5` for the rotation.

It also warns about a leftover setup-code file and about `SLOCLOUD_SETUP_ALLOWED_IPS` still being set.

## 9. Validation

```powershell
.\Test-SLOCloud5.ps1
```

Defaults come from `C:\ProgramData\SLOCloud5\installer\install-manifest.json`. You can override them with `-InstallRoot`, `-SiteName`, `-AppPoolName`, `-SqlHost`, `-SqlPort` and `-OutputPath`, and pass `-SkipProbe` to skip the FastCGI probe.

| Area | Checks |
|---|---|
| IIS | Role services; W3SVC and WAS running; global modules (RewriteModule, FastCgiModule and others); site state, path and pool; anonymous identity; request limit; first `*.php` handler; FastCGI timeouts and environment |
| Exposure | No site, application or virtual directory serves the root, `repo`, `secrets`, `data`, `backup` or `php` |
| TLS | HTTP.sys `localhost:443` binding; certificate present, SAN `localhost`, not expiring within 30 days, has its private key |
| PHP CLI | Version ≥ 8.1, NTS x64, Argon2id, loaded `php.ini`, `date.timezone`, required modules, ODBC driver registered |
| Environment | Machine `SLOCLOUD_DATA_PATH`, `SLOCLOUD_ENV`, PATH entry |
| ACLs | Effective app pool rights per folder (none / read / modify as in section 4); no access for broad groups |
| Setup state | `config.yaml` and `key.yaml` existence; no `key.yaml` inside the web root; no leftover setup code; no `phpinfo`/`info`/`test`/probe files |
| HTTPS | `/` responds below 500; security headers present; `X-Powered-By` absent; `/setup/` returns 200 before setup and 403 after |
| Blocked URLs | `/config/`, `/config/*.yaml*`, `/config/web.config`, `/config/backups/`, `/var/`, `/logs/`, `/sessions/`, `/web.config`, `/.git/config` and the `.sql` schema files must return 403 or 404. `.md` files are a WARN |
| FastCGI probe | PHP as `cgi-fcgi` inside `SLOCloud5`; sees the environment variables; extensions; ini values; can write `data`, `data\tmp` and `web\config`; **cannot** write the web root or list `repo` |
| SQL | TCP reachability (port only; not proof that the database is ready) |
| Time | `w32tm` status (LTI allows 300 s of clock skew, SAML 60 s) |

The probe is a PHP file with a random name. It answers only loopback requests carrying a random token, returns selected values only (never `phpinfo`), and is deleted in a `finally` block. The validator then confirms the file is gone.

Exit code `0` means no FAIL. A JSON report is written to `C:\ProgramData\SLOCloud5\installer\validation\`.

## 10. Reruns and upgrades

Rerun the installer with a newer `-Ref`. Everything converges idempotently.

`repo` is staging only. Each run fetches and force-resets it to the deployed commit, so anything changed there by hand is discarded rather than deployed. The discarded paths are listed in the run log and the journal. Make code changes in Git and push them; never edit `repo` on the server.

The deploy step:

- adds new files and replaces changed ones;
- removes files the previous deployment added that are gone from the source, but only if they're unchanged since then;
- **never touches** `web\config\config.yaml`, `config.yaml.bak`, `key.yaml`, `index.php`, `.htaccess`, `config\backups\`, `config\.*` temporary files or runtime session files, nor anything in `data`, `secrets` or `backup`;
- **stops before changing anything** if a web file was modified outside the installer, and lists the files. Rerun with `-OverwriteModifiedWebFiles` to replace them; they are backed up first;
- verifies every copied file by hash.

`php.ini` works the same way: if it was edited by hand, the run stops. Use `-OverwritePhpIni` to replace it after a backup. A new PHP version in `sources.json` is installed side by side: the previous PHP is backed up, and the app pool is stopped while the files are swapped.

Database migrations after an upgrade remain the application's job: `system\scripts\apply_migration.php` and `docs/upgrades/`.

## 11. Rollback and uninstall

```powershell
.\Undo-SLOCloud5.ps1 -Mode ListRuns
.\Undo-SLOCloud5.ps1 -Mode RollbackRun -RunId 20261015-101500-ab12 -PreviewOnly
.\Undo-SLOCloud5.ps1 -Mode RollbackRun -RunId 20261015-101500-ab12     # type: ROLLBACK <run id>
.\Undo-SLOCloud5.ps1 -Mode Uninstall                                   # type: UNINSTALL
.\Undo-SLOCloud5.ps1 -Mode ArchiveJournal                              # type: ARCHIVE
```

Both RollbackRun and Uninstall first print a **dry-run preview** grouped into: *will be removed*, *will be restored*, *will be retained*, *cannot be reversed automatically*, *conflicts*, and *already in original state*. Nothing changes until you type the confirmation. Pass `-Yes` to skip it in automation; the preview is still printed.

- **RollbackRun** reverses one run's changes, newest first. If a later run changed the same resource, that item is a conflict until you roll back the later run.
- **Uninstall** reverses every installer run, newest first. It removes:
  - the site, app pool, handler, FastCGI entry and HTTP.sys binding;
  - the certificate, only if nothing else is bound to it;
  - installer-owned PHP and MinGit, only if no other handler references them;
  - the repo, only if it has no local changes;
  - deployed web files, machine variables and the PATH entry.

  It restores the Default Web Site and any preexisting folder ACLs and settings.
- **Always kept**: SQL databases and logins, `secrets` and its contents, `data`, `backup`, `web\config`, preexisting folders, and the journal. Secrets are never read or exported.
- **Manual items**: Windows features, the VC++ runtime, URL Rewrite and the ODBC driver are shared and are never uninstalled; the preview lists them with instructions.
- **Conflict rule**: each change is reversed only if the resource is still exactly as the installer left it. Anything changed since then is reported and left for you.
- **Resumable**: a failing step stops the undo. Fix the cause and rerun the same command; completed steps aren't repeated.
- An IIS configuration backup is taken before every undo (`appcmd list backup`).

**Limits.** A script can't prove complete environment-level restoration. Windows feature installs, shared package installs and upgrades, and database changes made by the wizard are outside what undo can reverse. Use a VM snapshot or full backup when you need a guaranteed earlier state.

## 12. Change journal, logs and backups

All state lives in `C:\ProgramData\SLOCloud5\installer\` (SYSTEM and Administrators only), outside every folder undo can remove.

| Path | Contents |
|---|---|
| `undo-manifest.json` (+ `.prev`) | The change journal. Every run and operation, with ID, timestamp, resource, ownership (*created*, *modified* or *preexisting*), prior state, new state, backup path and hash, inverse action, dependencies, status (*pending*, *applied*, *failed* or *skipped*) and revert status |
| `install-manifest.json` | Latest install summary: paths, source commit, app version, PHP version, modules and source hashes, IIS version, site, bindings, HTTP.sys entries, certificate thumbprint, ODBC/VC++ versions, machine variables, folder ACLs (SDDL), SQL check, setup state, IT items, warnings |
| `runs\<run-id>\` | `install.log` / `undo.log`, `transcript.txt`, `deploy.json` (file-level deployment record), `install-manifest.json`, MSI logs, `backups\` (applicationHost.config, php.ini, replaced web files, `icacls /save` ACL backups, the previous PHP) |
| `baseline\` | First-ever copies of configuration files; never overwritten |
| `sources.lock.json` | Hashes pinned at first use |
| `downloads\` | Verified installer sources |
| `validation\` | Validator reports |
| `installer.lock` | Prevents two installer or undo processes from running at once |

Each operation is written to the journal (write-through, atomic replace, last-good `.prev` copy) **before** the change is made, and marked applied or failed afterwards. A crash mid-run therefore leaves an accurate record for both resume and undo.

Keep this folder while you might need undo. `Undo-SLOCloud5.ps1 -Mode ArchiveJournal` zips it to `C:\ProgramData\SLOCloud5\archive\` (also admin-only). Deleting the originals afterwards needs `-RemoveJournalAfterArchive` and a typed `DELETE`.

## 13. Download sources, integrity and offline installs

Pinned in `sources.json` (reviewed 2026-10-15):

| Key | Version | Verification |
|---|---|---|
| `php` | PHP 8.5.12 NTS x64 (VS17) | SHA-256 from php.net `releases.json` |
| `pdo_sqlsrv` | Microsoft Drivers for PHP for SQL Server 5.13.3 | SHA-256 from the GitHub release |
| `yaml` | PECL yaml 2.3.0 for PHP 8.5 NTS x64 | No published hash: confirmed by the operator at first use, then pinned |
| `cacert` | Mozilla CA bundle 2026-09-25 (curl.se) | SHA-256 |
| `vcredist` | Visual C++ 2015–2022 x64 | Authenticode, signer Microsoft Corporation |
| `urlrewrite` | IIS URL Rewrite 2.1 x64 | Authenticode, signer Microsoft Corporation |
| `odbc` | ODBC Driver 18 for SQL Server x64 | Authenticode, signer Microsoft Corporation |
| `mingit` | MinGit 2.56.0.2 x64 (only if `git.exe` is missing) | SHA-256 from the GitHub release |

A mismatched file is deleted and the run stops. Zip extraction refuses entries that would escape the target folder.

**Offline install:** put the files named in each entry's `fileName` into one folder and pass `-SourceCache D:\Install\cache`. For `yaml`, also pass `-TrustedHashes @{ yaml = '<sha256>' }` when running non-interactively.

**Updating a version:** edit the entry's `version`, `fileName`, `urls` and `sha256` together. php.net moves superseded releases to `/releases/archives/`; the second URL covers that.

## 14. Security model

- **Secrets:** `secrets` starts empty. The installer checks `key.yaml` for existence only and never opens, copies or validates it. The app pool gets temporary write access for the wizard, then the access chosen in section 8.
- **Least privilege:** the app pool can write only `web\config`, `data`, `backup` and, per your choice, `secrets`. The web root is read-only to PHP, and `repo` is unreadable to it. Broad groups get nothing under the install root.
- **Isolation:** `repo`, `secrets`, `data` and `backup` sit outside the web root. Preflight refuses any IIS site that would serve the install root.
- **Logs:** URL credentials and `password=`/`token=` style values are redacted. No secret is ever prompted for. Git credentials go to Git's own prompt.
- **HTTPS:** the certificate's private key is non-exportable, and validation requests are pinned to its thumbprint without changing machine-wide trust. HSTS is not enabled, because it would pin every localhost site in the browser to HTTPS.
- **Validation probe:** loopback-only, random name and token, no `phpinfo`, deleted immediately.

## 15. What the installer deliberately does not change

- Windows Firewall, RDP, WinRM and network configuration
- SCHANNEL / TLS protocol settings and the certificate trust store
- Other IIS sites, their bindings, or wildcard/IP bindings on port 443 (the Default Web Site is stopped only on a real `localhost:443` conflict, and with approval)
- Server-wide IIS handlers (the PHP handler is site-scoped)
- SQL Server, databases and logins
- Services, scheduled tasks, and registry values other than the machine environment key
- Production hostname, DNS and certificates

## 16. Items for IT

The installer prints these at the end and saves them in `install-manifest.json` (`itItems`):

- Production hostname, DNS record, CA-issued certificate and HTTPS binding, as a separate approved change.
- Windows Firewall: inbound 443/TCP from users; outbound 1433/TCP to the database node.
- Disabling SSL 3.0, TLS 1.0 and TLS 1.1 (server-wide SCHANNEL settings).
- SQL Server login with setup-time rights (`db_datareader`, `db_datawriter`, `CREATE TABLE`, `ALTER ON SCHEMA::dbo`); revoke the DDL rights after setup. Allow 1433 from this server only.
- Antivirus real-time scan exclusions: `data\var\sessions` and `data\tmp`.
- Backups:
  - `web\config\config.yaml`;
  - the key file in `secrets`, stored apart from database backups;
  - `data\var\reports`;
  - off-server copies of the snapshots in `backup`.
- Accurate NTP time.
- A restart, if Windows or a package install reported one.
- A SQL TCP check that failed during install.
- A VM snapshot or full backup before production changes.

## 17. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Preflight BLOCK: site or app pool name exists | Not created by this installer. Choose `-SiteName` / `-AppPoolName`, or remove it yourself |
| Preflight BLOCK: HTTP.sys `localhost:443` binding exists | Another product owns it. Resolve with IT; the installer won't take it over |
| Preflight BLOCK: port 443 held by another process | A non-IIS web server is listening. Stop or reconfigure it |
| Preflight BLOCK: a site serves the install root | Choose a different root; otherwise `repo` and `secrets` would be exposed |
| `Ref '…' was not found` | Use an existing branch, tag or commit. There is no `main` branch |
| Warning: "local change(s) in … repo will be discarded" | Expected if someone edited `repo` or ran Git there with `core.autocrlf=true`. The installer resets `repo` to the deployed commit; nothing in `web`, `secrets`, `data` or `backup` is affected |
| PHP startup warning, `pdo_sqlsrv` missing | The ODBC driver or VC++ runtime is missing or needs a restart. Check Programs and Features, or `HKLM:\SOFTWARE\ODBC\ODBCINST.INI` for "ODBC Driver 18 for SQL Server" |
| `yaml` hash prompt in `-NonInteractive` | Pass `-TrustedHashes @{ yaml = '<sha256>' }` after verifying it |
| HTTP 500.19 | URL Rewrite missing (installed by step 5), or a locked config section (auto-unlocked for listed sections). Browse `https://localhost/` on the server for IIS's detailed error |
| HTTP 503 | The app pool stopped. Check the System event log and `data\logs` |
| App writes to `web-data` | `SLOCLOUD_DATA_PATH` is missing from the FastCGI environment. Rerun the installer |
| Wizard: "web server account cannot write to that folder" | The `secrets` ACL is wrong, or finalize already ran with Read. Rerun the installer (it grants setup-time Modify while `config.yaml` is absent) |
| Key rotation fails with "check permissions" | `-SecretsAccessAfterSetup Read`. Temporarily grant Modify on `secrets` |
| Deploy stops: files "changed outside the installer" | Review the listed files, then rerun with `-OverwriteModifiedWebFiles` |
| `php.ini was edited after the installer wrote it` | Review the edit, then rerun with `-OverwritePhpIni` |
| "Another … installer or undo process is running" | Another elevated session holds the lock. Finish or close that session; the lock is released when its process exits |
| Undo reports conflicts | The resource changed after install. Resolve manually using the journal's recorded prior state and the backups |

## 18. Application prerequisites (traced to code)

This is why the installer configures what it does. Each line was traced against the 5.2.1 source (commit `2b62ba3`); 5.2.2 doesn't change any of them.

### PHP

| Requirement | Evidence |
|---|---|
| PHP ≥ 8.1, NTS x64 | `private readonly` properties in `system/reporting/*.php`; 8.5 recommended |
| `pdo`, `yaml`, `json`, `mbstring`, `openssl`, Argon2id | `api/api.php` check 2b (`php_extensions` is a breaking check) |
| `pdo_sqlsrv` + ODBC 17/18 | Setup DSN `sqlsrv:Server=host,port;Encrypt=yes;TrustServerCertificate=yes` |
| `yaml_parse` / `yaml_emit` (PECL) | `setup/index.php` `setupRequireYamlExtension()`, `Config` |
| `curl` + CA bundle | `CanvasClient.php` (Canvas deploy) |
| `dom`, `iconv`, `session` | Used in code; built into the Windows builds |
| `fileinfo`, `opcache` | Listed in the requirements doc; enabled (no code use of `fileinfo` was found) |
| `date.timezone` | The app does not apply `app.timezone` |
| `session.gc_maxlifetime` ≥ 28800 | 2 h admin and 8 h LTI sessions |
| CLI `php.exe` plus machine environment | `system/scripts/*.php` are CLI-only (`PHP_SAPI` guard) |

### Filesystem

| Path | Written by |
|---|---|
| `web\config` | Setup (`config.yaml`, `index.php`, `.htaccess`); `Config::save()` from the Configuration page and Canvas deploy (`config.yaml`, `.bak`); `api/rotate_key.php` |
| `secrets` | Setup (`slocloud-setup-code.txt`, `key.yaml`); UI key rotation (`key.yaml`, `backups\`). System > Configuration can move `key.yaml` and `backups\` to another existing folder; the installer then still manages only `secrets`, and the test script warns that `key.yaml` is elsewhere |
| `data` | `Security::dataRoot()` honours `SLOCLOUD_DATA_PATH`; otherwise it falls back to a sibling of the web root (`<root>\web-data`). Sessions, login and LTI throttles, report definitions and catalog, Canvas deploy locks, key-rotation lock, logs |
| `backup` | The application's snapshot function (to be implemented) |

### Setup wizard behaviour the installer relies on

- Localhost-only unless `SLOCLOUD_SETUP_ALLOWED_IPS` is set (`setup/index.php` lines 110–116).
- The keys folder must already exist, be writable by the app pool, and sit outside the web root (`setupResolveKeysDirectory`).
- `config.yaml` existing means setup is done: setup then returns 403, and `init.php` stops redirecting to it.

### HTTPS and environment

- `Security::requireHttps()` redirects when `SLOCLOUD_FORCE_HTTPS=true`, using `SERVER_NAME`, so `localhost` works.
- `SLOCLOUD_ENV=production` makes `Config` refuse debug or on-screen errors and forces secure cookies.
- `SLOCloud_KEY_PATH` (mixed case in code) isn't set. The wizard records `security.key_path` in `config.yaml`, which the app and the CLI both read.

### IIS

- `src/web.config` needs URL Rewrite (`<rewrite>` rules; without it every request fails with 500.19) and uses `httpErrors`, `requestFiltering` (`hiddenSegments`, `fileExtensions`), `httpProtocol`, `defaultDocument`, `directoryBrowse` and a `lti/launch.php` location.
- The only HTTP verbs used are GET, HEAD and POST.
- `.sql` and `.md` files in the web root are unreachable only because IIS has no MIME type for them. Never add MIME maps for `.sql`, `.md`, `.yaml` or `.log`.

## 19. Design decisions

| Decision | Reason |
|---|---|
| `data` folder added (`SLOCLOUD_DATA_PATH`) | Otherwise the app silently creates `web-data` with inherited permissions |
| `backup` folder added, app pool Modify, kept on undo | Target for the application's snapshot function |
| `secrets` access after setup is the operator's choice, default Modify | UI key rotation provably needs write access (`api/rotate_key.php`); Read remains available |
| No default Git ref | The repository has no `main`; deploying an unintended branch is worse than asking |
| PHP is installer-owned under `<root>\php`; a foreign `php` folder blocks the install | Clean ownership for upgrades and undo; a shared PHP's `php.ini` is never edited |
| MinGit only when Git is missing, never on PATH | No system-wide Git install; easy to remove |
| Site-scoped PHP handler, `localhost`-only SNI binding | No effect on other sites; no production exposure |
| No HTTP (port 80) binding | Not needed; HTTPS-only |
| Strict mode level 1 | Catches unset variables. Level 2 would throw on deliberate reads of optional journal fields |
| Validator independent of the module | A shared bug can't mask itself |

## 20. Before production: test matrix

Run each case on a disposable Windows Server VM from a snapshot. Keep the validator report and the undo output for each.

| # | Scenario | Expected |
|---|---|---|
| 1 | `-Plan` on a clean server | No changes, and no run in `undo-manifest.json` |
| 2 | Clean install → wizard → finalize | Validator 0 FAIL; `/setup/` returns 403 |
| 3 | Rerun with the same ref | Every operation *skipped* |
| 4 | Rerun with a newer commit | Only changed files replaced; config, data, secrets and backup untouched |
| 5 | Partial failure (block outbound HTTPS after the PHP download, or kill the process mid-deploy) | Run marked failed; a rerun resumes; RollbackRun reverses exactly the applied steps |
| 6 | Hand-edit a web file or `php.ini`, then rerun | Stops with a list; `-Overwrite*` backs up first |
| 7 | Hand-change the app pool or site, then RollbackRun | That item is a conflict and left alone; the rest is reversed |
| 8 | Default Web Site with `https localhost:443` | `STOP` prompt; uninstall restores its state |
| 9 | RollbackRun of the latest run, then Uninstall | IIS, environment and ACLs back to pre-install, except the manual items |
| 10 | Uninstall, then reinstall on the same root | Kept folders reused; wizard not offered again while `config.yaml` exists |
| 11 | Install root with spaces (default) and `-LocalSourcePath` with spaces | Works end to end; validator passes |

## 21. Verification status

Checked so far, all off Windows:

- All four scripts parse without errors.
- PSScriptAnalyzer 1.24 reports no findings, with the PowerShell 5.1 syntax, command and type compatibility rules on (Windows Server 2019 / PS 5.1 profile).
- Behaviour tests under PowerShell 7 on macOS:
  - **Journal** (22 checks): writes before and after each change, failure recording, `.prev` recovery, plan mode writing nothing, file restore, conflict and no-op paths, retained and remove-if-empty folders.
  - **Deploy** (17 checks): first install, no-op rerun, upgrade with stale-file removal, an out-of-band edit stopping the deploy, upgrade revert, first-deploy revert keeping wizard files.
  - **`netsh http show sslcert` parsing** (4 checks).
- An independent code review found eight defects, all fixed. Examples: variables clobbered through case-insensitive names, `$Matches` reset in the netsh parser, list-returning helpers breaking pipeline filters, and cmdlet errors inside the module not being terminating.

**Not yet verified:** anything that needs Windows, IIS, HTTP.sys, NTFS ACLs or the registry. Run section 20 before production use.

## 22. Repository issues found during analysis

These aren't installer inputs; they're worth fixing in the application:

1. `src/setup/index.php` line 1094 unlinks `$setupTokenFile`, which is never defined. This is a leftover of the token flow; it's suppressed by `@`, so harmless.
2. `.sql` files in the web root are protected only by IIS's default MIME table. Consider adding `.sql` to `fileExtensions` in `src/web.config`.

`docs/SYSTEM_REQUIREMENTS.md` and `src/setup/README.md` were updated in 5.2.2 to match the current setup flow.

## 23. Requirements-to-implementation matrix

| Requirement | Implementation | Notes |
|---|---|---|
| Inspect the repository; don't guess dependencies | Section 18 | Each prerequisite traced to a file or line |
| Elevated PowerShell over RDP, no WinRM | `#Requires -RunAsAdministrator`, Desktop and 64-bit checks | No remoting |
| Prompt once for the install root (default `D:\SLO Cloud 5`) | `Read-Input`; defaults from the previous run | Path validated |
| `repo`, `web`, `secrets` | `Step-Directories`, `Step-Source`, `Step-Deploy` | Plus `data`, `backup`, `php` (section 19) |
| Repo URL and ref; safe clone or update; record commit | `Step-Source` | Origin and clean-tree checks, detached checkout, HEAD verified |
| Default `main` only if appropriate | No default | Remote has only `dev` |
| Deploy only public application files | `Step-Deploy` | `src\` only, excluding `TODO.md` and `.gitignore`; hash-verified |
| Preserve data and config on reruns | Protected-file rules, conflict stop | Section 10 |
| Local source alternative | `-LocalSourcePath` | Recorded by content digest |
| `secrets` empty; never read, copy or validate secrets | Existence checks only | Same rule in the validator |
| Least-privilege ACLs; temporary wizard write | `Step-Acls`, `Step-Finalize`, `-SecretsAccessAfterSetup` | Ongoing write proven necessary only for UI key rotation |
| IIS features, PHP, FastCGI, extensions | `Step-WindowsFeatures`, `Step-Php`, `Step-PhpIni`, `Step-FastCgi`, `Test-PhpCli` | |
| Dedicated app pool and site at `web` | `Step-AppPool`, `Step-Site` | Site-scoped handler |
| localhost-only binding; self-signed SAN certificate; HTTP.sys-compatible | `Step-Certificate`, `Step-SslBinding` | `hostnameport=localhost:443` |
| Don't take over other 443 bindings | Preflight HTTP.sys, binding and listener checks | |
| Disable Default Web Site only if necessary; never delete | `Step-DefaultWebSiteConflict` | Journaled; restored on undo |
| Explain certificate trust | Section 4, IT item, wizard instructions | Trust store untouched |
| SQL host and port; TCP test only | `Test-SloTcpPort` | No credentials, no DDL |
| Review wizard and status code first | Section 18 | `setup/index.php`, `api/api.php`, `Config.php`, `init.php` |
| Independent IIS/PHP checks without exposing phpinfo | `Test-SLOCloud5.ps1` probe | Section 9 |
| Machine-readable manifest and readable log | `install-manifest.json`, `install.log`, `transcript.txt` | Section 12 |
| IT items reported separately | `Add-SloItItem`; end-of-run list; manifest | Section 16 |
| Undo script plus persistent journal and backups, outside removable folders | `Undo-SLOCloud5.ps1`, `undo-manifest.json`, `runs\*\backups`, `baseline\` | Admin-only `%ProgramData%` |
| Record prior state, intent, ID, timestamp, resource, backup, new value, ownership, inverse before each change; durable; mark the outcome | `Invoke-SloChange`, `Write-SloFileDurable` | Write-through, atomic replace, `.prev` |
| No secrets in logs or manifest | `Protect-SloText`; credential URLs refused | |
| Resumable, rerunnable undo | `Invoke-SloRevert` state checks, `revertedBy`, stop on failure | |
| Track every resource type touched | Operation kinds for dirs, ACLs, features, packages, PHP, files, environment, PATH, app pools, sites, bindings, HTTP.sys, FastCGI, handlers, config properties, section unlocks, site state, certificates, Git, deploys, IIS backups | |
| Created / modified / preexisting | `ownership` on every operation | Preexisting folders never removed |
| Back up configuration, record hashes, capture full security descriptors | `Backup-SloFile`, `backupHash`, `Get-SloAclSnapshot` (SDDL incl. SACL when readable), `icacls /save` | Undo restores the DACL only; owner and SACL are never changed |
| IIS backup; baselines never overwritten | `appcmd add backup` per run; `baseline\` first-copy rule | |
| Reverse dependency order | `dependsOn`; newest-first by sequence | |
| Restore preexisting, remove created only when unused | Per-type guards | Pool in use, handler/FastCGI references, certificate bound, repo dirty |
| Conflict detection before undo | Current state compared with the recorded result | |
| Two undo modes, dry-run preview, explicit confirmation | `RollbackRun`, `Uninstall`, grouped preview, typed tokens | |
| Preserve databases, secrets, uploads, preexisting folders; keep the journal | `retain` inverses; journal never auto-deleted | |
| Never uninstall shared IIS, PHP, Git or SQL components | `manual` inverses | |
| Admit limits; recommend a snapshot | `reversibility` field; section 11 | |
| Dry run, preflight, idempotency, failure-safe | `-Plan`, `Invoke-Preflight`, `Add-SloSkip`, stop on first failure | |
| No RDP, firewall or network changes | Section 15 | Listed as IT items |
| Pin sources and verify integrity | `sources.json`, section 13 | |
| Separate infrastructure, wizard and penetration testing | Installer stops at the wizard handoff | No security testing included |
| Test clean install, rerun, partial failure, undo | Section 20 | Requires a Windows VM |
