# SLOCloud Setup Wizard

App version: 5.2.2

`index.php` in this folder is the one-time, browser-based setup wizard. It creates the database schema, the first administrator, the encryption keys and `config/config.yaml`. Once `config.yaml` exists, the wizard refuses to run again.

On Windows Server, prepare the server with the installer in [automation/README.md](../../automation/README.md) first. It installs IIS and PHP, creates the keys folder with the right permissions, deploys the application and prints these steps. Server requirements: [docs/SYSTEM_REQUIREMENTS.md](../../docs/SYSTEM_REQUIREMENTS.md).

## Before you start

- **PHP 8.1+** (8.3 recommended) with `pdo`, `yaml`, `openssl`, `mbstring`, `json`, Argon2 and a PDO driver: `pdo_sqlsrv` for SQL Server, which is the production target, or `pdo_mysql`. The wizard greys out any driver whose extension is missing.
- **Database:** SQL Server 2016+ (2022 recommended) or MySQL 8.0+.
  - The login needs `db_datareader`, `db_datawriter`, `CREATE TABLE` and `ALTER ON SCHEMA::dbo` during setup.
  - The wizard tries `CREATE DATABASE` and falls back to an existing database if the login can't create one. Creating the database in advance is recommended.
  - Revoke the DDL rights after setup (`docs/SYSTEM_REQUIREMENTS.md` section 2.4).
- **Keys folder:** an existing folder **outside the web root** that the web server account can write to, for example `D:\SLO Cloud 5\secrets`. The wizard refuses a folder that is missing, not writable or inside the web root.
- **Writable folders for the web server account:** `config/` (the wizard writes `config.yaml` there) and the data folder (`SLOCLOUD_DATA_PATH`; it holds sessions and the setup log).
- **Access:** open the wizard from the server itself (`127.0.0.1` / `::1`). To run it from another machine, list that machine's IP in `SLOCLOUD_SETUP_ALLOWED_IPS` for the duration of setup, then remove it.

## Running setup

1. Open `https://<server>/setup/`; on the server this is `https://localhost/setup/`.
2. **Keys folder.** Enter the full path of the keys folder. The wizard writes `slocloud-setup-code.txt` into it.
3. **Verification code.** Open that file on the server and paste the code back. This proves the person at the browser can read the server's disk. The code expires after 15 minutes and allows 5 attempts; after that, enter the folder again for a new code.
4. **Installation form:**

   | Field | Notes |
   |---|---|
   | Site name | Shown in the interface |
   | Base URL path | Auto-detected (`/` or a subfolder such as `/beta/`); must start with `/` |
   | Database driver | `mssql` (SQL Server) or `mysql` |
   | Host / port | Default port 1433 (SQL Server) or 3306 (MySQL) |
   | Database name | Must start with a letter; letters, digits and `_` only |
   | Table prefix | Default `slocloud_tbl_`; letters, digits and `_` only |
   | Username / password | The SQL login. Its password is stored encrypted in `config.yaml` |
   | Administrator name, email, password | The first global administrator. Password at least 12 characters, not a common password, and must not contain the name or email |
   | Email delivery | Optional: disabled, server mail, or SMTP (host, port, user, password, from address, TLS/SSL/none) |

5. Click **Install SLOCloud**.
6. **Save the LTI consumer key and shared secret** shown on the completion screen. They are also available later under System > Configuration > LTI.

SQL Server connections use `Encrypt=yes;TrustServerCertificate=yes`.

## What setup does

1. Connects to the database server and creates the database if it can.
2. Runs `system/database/schema_mssql.sql` (SQL Server) or `schema.sql` (MySQL) with the chosen table prefix. This creates 22 tables, 15 triggers and indexes, and stamps the schema version in the `meta` table.
3. Creates the administrator account.
4. Pre-generates academic terms for the current year and the next five.
5. Generates the encryption keys and writes `key.yaml` to the keys folder.
6. Generates the LTI consumer key (`slocloud-…`) and a 32-byte shared secret, stored encrypted.
7. Writes `config/config.yaml`, which includes `security.key_path` so the application finds `key.yaml` without moving it.
8. Writes `config/index.php` (returns 403) and `config/.htaccess` as extra protection for the config folder.
9. Builds the report catalog from the new database and validates the bundled report definitions against it.
10. Writes a log to `<data>/logs/setup_<date>_<time>.log`. Credentials are redacted.

## After setup

- `/setup/` now returns 403 ("Setup Already Complete"), and the application stops redirecting to it.
- On Windows Server, run `Install-SLOCloud5.ps1 -FinalizeSetup` to apply the final permissions on the keys folder.
- Revoke the setup-time DDL rights from the SQL login.
- Remove `SLOCLOUD_SETUP_ALLOWED_IPS` if you set it.
- Back up `config.yaml` and `key.yaml`. Store `key.yaml` apart from database backups: without it, encrypted values can't be read.
- Check System > System Status; every check should be OK.

### Reinstalling

To run setup again, remove `config/config.yaml`. This doesn't delete the database or the old `key.yaml`. Use an empty database, or one you intend to replace, and keep the old keys folder until you are sure nothing encrypted with those keys is still needed.

## Troubleshooting

| Message | Cause |
|---|---|
| "Setup is not permitted from this address." | The browser isn't on the server. Use localhost, or add the client IP to `SLOCLOUD_SETUP_ALLOWED_IPS` |
| "That folder does not exist." / "cannot write to that folder" / "inside the web root" | The keys folder must exist, be writable by the web server account, and sit outside the web root |
| "The verification code expired." / "Too many incorrect codes." | Enter the keys folder again to get a new code |
| "Database connection failed." | Check host, port, login and network access to the database port. For SQL Server, also check that the ODBC Driver 17/18 is installed |
| "Signed in to the database server … but could not open database" | The login works, but the database doesn't exist and the login can't create it, or the login isn't a user in it. Create the database and add the login as a user with `db_owner`, or `db_ddladmin` plus `db_datareader` and `db_datawriter`. Read and write roles alone can't create the tables |
| "The required PHP YAML extension (ext-yaml) is not loaded." | Enable `extension = yaml` in `php.ini` |
| A password policy message | Choose a longer or less predictable administrator password |
| "Setup Already Complete" | `config/config.yaml` exists. See *Reinstalling* |

## Related documentation

- [automation/README.md](../../automation/README.md): Windows Server installer, validation and undo
- [docs/SYSTEM_REQUIREMENTS.md](../../docs/SYSTEM_REQUIREMENTS.md): server, PHP, IIS and SQL Server requirements
- [docs/SETUP_GUIDE.md](../../docs/SETUP_GUIDE.md): setup and configuration guide
- [docs/upgrades/](../../docs/upgrades/): schema upgrade plans for existing installations
