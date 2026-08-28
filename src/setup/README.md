# SLOCloud Setup

This directory contains the web-based setup interface for SLOCloud.

## Prerequisites

- **PHP 8.1+** with PDO and YAML extensions enabled
- **MySQL 8.0+** OR **SQL Server 2016+** server running
- Database credentials with permission to CREATE DATABASE (MySQL) or CREATE SCHEMA (SQL Server)

## Quick Start

### 1. Initial Installation

When you first install SLOCloud:

1. From the deployed application root, run `php system/scripts/create_setup_token.php`.
2. Open the tokenized setup URL printed by the command from localhost. To use a remote management workstation, first add its IP to `SLOCLOUD_SETUP_ALLOWED_IPS`.
3. Fill in the database connection and initial-administrator form:
   - **Database Driver**: Select `mysql` or `mssql`
   - **Database Host**: Usually `localhost` or `127.0.0.1`
   - **Port**: Default is `3306` (MySQL) or `1433` (SQL Server)
   - **Database Name**: Will be created if it doesn't exist (e.g., `slocloud`)
   - **Username**: Database username with appropriate privileges
   - **Password**: Database user password
   - **Base URL Path**: Auto-detected (e.g., `/` or `/beta/`). Confirm or adjust if needed.

4. Click "Install SLOCloud"

The setup wizard will:
- Auto-detect the base URL path (supports subdirectory installations)
- Use the appropriate schema file (`schema.sql` for MySQL, `schema_mssql.sql` for SQL Server)
- Test the database connection
- Create the database if it doesn't exist
- Execute the complete schema (all tables, indexes, foreign keys)
- Save credentials and base URL to `src/config/config.yaml`
- Protect the config directory with `.htaccess`

All administration routes require an active global administrator role and deny unclassified routes by default.

### 2. Verify Setup

Start the development server:

```powershell
php -S localhost:8000
```

Access the demo portal:
```
http://localhost:8000/slocloud-slo/demo/
```

## Files

### `index.php`
Web-based database setup wizard. Creates database, executes schema, and saves configuration.

**Features:**
- Browser-based form interface (no CLI required)
- Auto-detects base URL path for subdirectory installations
- Connection testing before schema execution
- UTF8MB4 charset enforcement
- User-friendly success/error messages
- Security: Protects config directory with .htaccess after installation

**Process:**
1. Displays form for database credentials
2. Tests connection on form submission
3. Creates database if it doesn't exist
4. Executes schema.sql (all tables, indexes, constraints)
5. Saves config.yaml with credentials
6. Creates .htaccess to protect config directory
7. Shows success message with next steps

### `../src/system/database/schema.sql`
Complete MySQL schema for SLOCloud platform.

**Includes:**
- 21 tables covering all entities
- Foreign key constraints with appropriate cascades
- Indexes for performance optimization
- Standard roles (admin, user)
- Audit trail fields (created_by, updated_by, created_at, updated_at)
- **Logging tables:**
  - `audit_log` - Change history tracking (INSERT/UPDATE/DELETE operations)
  - `error_log` - Application errors with stack traces
  - `security_log` - Security events (logins, permission denials, threats)

**Key tables:**
- `institution` (includes LTI consumer keys), `institutional_outcomes`
- `departments`, `programs`, `program_outcomes`
- `courses`, `slo_sets`, `student_learning_outcomes`
- `terms`, `course_sections`, `enrollment`
- `students`, `assessments`
- `users`, `roles`, `user_roles`
- `lti_nonces`

## Configuration File

After running the setup wizard, credentials are saved to:

```
src/config/config.yaml
```

**Structure:**
```yaml
database:
  host: localhost
  port: 3306
  name: slocloud_slo
  username: slocloud_user
  password: ********
  charset: utf8mb4

app:
  name: SLOCloud
  timezone: America/Los_Angeles
  base_url: /
```

**base_url Examples:**
- Root installation: `base_url: /`
- Subdirectory installation: `base_url: /beta/`
- Multiple levels: `base_url: /apps/slocloud/`

The `base_url` is used throughout the application for generating correct URLs and redirects. It's auto-detected during setup but can be manually adjusted in the config file if you move the installation.

**Security Note:** The config file contains sensitive credentials. On Unix-like systems, permissions are automatically set to `0600` (owner read/write only). Do not commit this file to version control.

## Logging System

SLOCloud includes comprehensive logging for audit trails, errors, and security events.

### Log Types

1. **Audit Logs** (`audit_log` table)
   - Tracks all data changes (INSERT, UPDATE, DELETE)
   - Records before/after values
   - Includes user context and IP address
   - Used for compliance and forensic analysis

2. **Error Logs** (`error_log` table + `logs/error_*.log`)
   - Application errors with stack traces
   - SQL errors and exceptions
   - Request context for debugging
   - Severity levels: debug, info, warning, error, critical

3. **Security Logs** (`security_log` table + `logs/security_*.log`)
   - Login attempts (success/failure)
   - Permission denials
   - LTI launch attempts
   - Password changes
   - Potential threats flagged

### Log Files

Located in `logs/` directory (outside web root):
- `admin_user_YYYY-MM-DD_HHMMSS.log` - User creation logs
- `error_YYYY-MM-DD.log` - Daily error logs
- `security_YYYY-MM-DD.log` - Daily security logs
- `audit_YYYY-MM-DD.log` - Daily audit logs
- `app_YYYY-MM-DD.log` - General application logs

### Using the Logger

```php
use SLOCloud\Core\Logger;

// Initialize
$logger = Logger::getInstance($config, $db);

// Log an error
$logger->error('Database query failed', 'SQL_ERROR', 'ERR001', 
    $exception->getTraceAsString(), __FILE__, __LINE__, $userPk);

// Log a security event
$logger->security(Logger::EVENT_LOGIN_FAILED, 
    'Invalid credentials for user: admin', null, 'admin', 'warning');

// Log an audit trail
$logger->audit('users', $userPk, 'UPDATE', $currentUserPk, 
    ['email' => 'new@example.com'], ['email' => 'old@example.com']);

// Log general info
$logger->info('Application started');
$logger->warning('Low disk space');
$logger->debug('Variable value', ['var' => $value]);
```

### Log Cleanup

**Note:** Log cleanup functionality will be available through the administration interface. For now, manage logs directly in MySQL if needed.

**Manual Database Cleanup (if needed):**
```sql
-- Keep logs for 90 days
DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND is_resolved = 1;
DELETE FROM security_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND is_threat = 0;
```

## Data Import Process

After schema creation, populate tables from CSV staging data using the multi-step import process.

### Prerequisites

1. **Schema created** via setup wizard (applies slocloud_tbl_ prefix automatically)
2. **Staging tables loaded:**
   - `slo_cslo` (~7k rows) - Course catalog with SLO descriptions
   - `slo_import` (~182k rows) - Student assessment transactions
3. **MySQL timeout configured:**
   - MySQL Workbench: Edit → Preferences → SQL Execution → DBMS connection read timeout = **600 seconds**
   - Server timeouts already configured (see below)

### Import Workflow

The import is split into 4 scripts for manageability and performance:

#### Step 1: Dimension Tables (2-5 seconds)

**File:** `src/setup/01_import_dimensions.sql`

Populates lookup/reference tables:
- Programs (from both slo_cslo and slo_import)
- Subjects (SUBJ codes + names)
- Courses (course catalog)
- SLO Sets (academic years)
- Terms
- Student Learning Outcomes
- Course-SLO mappings (which courses assess which SLOs)
- Students (~50k unique student IDs)

**Expected:** ~300-500 dimension records + ~50k students

**Execution:**
```sql
-- In MySQL Workbench or CLI
USE slocloud_slo;
SOURCE src/setup/01_import_dimensions.sql;
```

#### Step 2: Course Sections (10-20 seconds)

**File:** `src/setup/02_import_course_sections.sql`

Creates course section records (CRN offerings per term):
- Links courses to terms
- Captures modality (In-Person, Online, Hybrid)
- Ready for instructor assignment

**Expected:** ~1000-3000 course sections

**Execution:**
```sql
SOURCE src/setup/02_import_course_sections.sql;
```

#### Step 3: Enrollment (1-2 minutes)

**File:** `src/setup/03_import_enrollment.sql`

Links students to course sections:
- Uses DISTINCT to collapse 182k assessments → ~50k unique enrollments
- One enrollment record per student per course section
- Fully normalized (`student_fk`, `section_fk`)

**Expected:** ~50k enrollments

**Execution:**
```sql
SOURCE src/setup/03_import_enrollment.sql;
```

**Note:** This step requires DISTINCT operation on large dataset. Ensure MySQL timeout is set to 600 seconds.

#### Step 4: Assessments (5-10 minutes)

**File:** `src/setup/04_import_assessments.sql`

Imports all assessment records:
- All 182k assessment transactions from slo_import
- Resolves enrollment_fk via student + CRN + term
- Resolves student_learning_outcome_fk via CSLO code
- Converts Met/Not Met to score values

**Expected:** ~182k assessments

**Execution:**
```sql
SOURCE src/setup/04_import_assessments.sql;
```

**Note:** Largest import with complex FK resolution. May take 5-10 minutes depending on hardware.

### Master Import Script

**File:** `src/setup/00_import_master.sql`

Documentation-only script showing the complete workflow. Contains:
- Step-by-step execution guide
- Expected counts and durations
- Post-import verification queries
- Referential integrity checks
- Assessment quality metrics

**To use:** Open in MySQL Workbench and execute each section manually, verifying counts between steps.

### Database Server Timeouts

#### MySQL Server Configuration

The following timeouts are pre-configured in your MySQL server (no changes needed):

```sql
-- Check current timeout settings (should already be configured)
SHOW VARIABLES LIKE '%timeout%';

-- wait_timeout = 28800 (8 hours)
-- interactive_timeout = 28800 (8 hours)
-- net_read_timeout = 30
-- net_write_timeout = 60
```

**Client timeout** (MySQL Workbench) must be set manually:
- Edit → Preferences → SQL Execution
- DBMS connection read timeout interval = **600 seconds**

### Verification Queries

After import, verify data integrity:

```sql
-- Check all table counts
SELECT 
    (SELECT COUNT(*) FROM slocloud_tbl_programs) AS programs,
    (SELECT COUNT(*) FROM slocloud_tbl_subjects) AS subjects,
    (SELECT COUNT(*) FROM slocloud_tbl_courses) AS courses,
    (SELECT COUNT(*) FROM slocloud_tbl_slo_sets) AS slo_sets,
    (SELECT COUNT(*) FROM slocloud_tbl_terms) AS terms,
    (SELECT COUNT(*) FROM slocloud_tbl_student_learning_outcomes) AS slos,
    (SELECT COUNT(*) FROM slocloud_tbl_course_slos) AS course_slo_mappings,
    (SELECT COUNT(*) FROM slocloud_tbl_students) AS students,
    (SELECT COUNT(*) FROM slocloud_tbl_sections) AS course_sections,
    (SELECT COUNT(*) FROM slocloud_tbl_enrollment) AS enrollments,
    (SELECT COUNT(*) FROM slocloud_tbl_assessments) AS assessments;

-- Check assessment success rate
SELECT 
    COUNT(*) AS total_assessments,
    SUM(CASE WHEN achievement_level = 'met' THEN 1 ELSE 0 END) AS met_count,
    ROUND(SUM(CASE WHEN achievement_level = 'met' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) AS success_rate_pct
FROM slocloud_tbl_assessments;

-- Verify no orphaned records (all should return 0)
SELECT 
    (SELECT COUNT(*) FROM slocloud_tbl_courses WHERE discipline_fk NOT IN (SELECT disciplines_pk FROM slocloud_tbl_disciplines)) 
        AS orphaned_courses,
    (SELECT COUNT(*) FROM slocloud_tbl_sections WHERE course_fk NOT IN (SELECT courses_pk FROM slocloud_tbl_courses)) 
        AS orphaned_sections,
    (SELECT COUNT(*) FROM slocloud_tbl_enrollment WHERE student_fk NOT IN (SELECT students_pk FROM slocloud_tbl_students)) 
        AS orphaned_enrollments,
    (SELECT COUNT(*) FROM slocloud_tbl_assessments WHERE enrollment_fk NOT IN (SELECT enrollment_pk FROM slocloud_tbl_enrollment)) 
        AS orphaned_assessments;
```

### Import Troubleshooting

**Error:** "Unknown column 'slocloud_tbl_programs.programs_pk'"

**Solution:** Schema prefix mismatch. Check that `src/setup/index.php` applied the correct prefix during schema creation. If using custom prefix, update import scripts with find/replace: `slocloud_tbl_` → `your_prefix_`

**Error:** "Duplicate entry for key 'program_code'"

**Solution:** This is expected behavior with INSERT IGNORE. The UNION in Step 1 may produce duplicates, which are safely skipped due to unique constraints.

**Error:** "Foreign key constraint fails"

**Solution:** Schema was redesigned to NOT use foreign key constraints (only unique constraints). If you see FK errors, you may be running an old schema. Re-run setup wizard with latest schema.sql.

**Error:** Import times out during enrollment or assessments

**Solution:** 
1. Verify MySQL Workbench timeout (Edit → Preferences → SQL Execution) = 600 seconds
2. Check server load - imports may be slower on busy systems
3. Run steps individually rather than as batch

**Error:** Row count much lower than expected

**Solution:**
1. Verify staging tables have data: `SELECT COUNT(*) FROM slo_cslo; SELECT COUNT(*) FROM slo_import;`
2. Check for NULL values: Import scripts filter out NULL/empty values
3. Review JOIN conditions: Orphaned records (no matching FK) are excluded

## Troubleshooting

### Setup Failed

**Error:** Setup wizard shows error message

**Solution:**
- Check MySQL is running and accessible
- Verify username and password are correct
- Ensure user has CREATE DATABASE privilege
- Check browser console for JavaScript errors

```sql
-- Grant privileges to user
GRANT ALL PRIVILEGES ON slocloud_slo.* TO 'slocloud_user'@'localhost';
FLUSH PRIVILEGES;
```

### Cannot Access Setup

**Error:** Browser shows 404 when accessing /setup/

**Solution:**
- Verify web server is running and document root points to src/
- Check that setup/index.php exists in src/setup/ directory
- Verify .htaccess mod_rewrite is enabled (Apache)
- For Nginx, deny sensitive/runtime paths explicitly (even when the repository root is accidentally mapped as the web root):
  ```nginx
  location ~* ^/(?:src/)?(?:config|sessions|logs|backups|archives?|var)(?:/|$) { return 404; }
  ```

### Schema Execution Error

**Error:** "Schema execution error: Table already exists"

**Solution:**
The schema includes DROP TABLE statements at the beginning. If you encounter errors:

1. Drop the database and recreate:
   ```sql
   DROP DATABASE slocloud_slo;
   CREATE DATABASE slocloud_slo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Re-run setup script

### Password Too Short

**Error:** "Password must be at least 12 characters"

**Solution:**
Use a strong password with at least 12 characters. Recommended:
- Mix of uppercase and lowercase letters
- Numbers
- Special characters

### Config File Not Found

**Error:** "Configuration file not found. Please run setup first."

**Solution:**
Browse to your SLOCloud installation and complete the setup wizard at /setup/. The config file is created by the setup wizard.

## Manual Schema Execution

If you prefer to manually execute the schema:

```powershell
# Using MySQL command line
mysql -u slocloud_user -p slocloud_slo < src/system/database/schema.sql
```

Then manually create the config file in `src/config/config.yaml` using the structure above.

## Next Steps

After setup is complete:

1. **Review Configuration:** Edit `src/config/config.yaml` to adjust settings
2. **Initialize Institution Data:** Add your institution record
3. **Set Up Departments:** Add academic departments
4. **Configure Programs:** Create programs and outcomes
5. **Upload SLO Sets:** Import SLOs for assessment periods
6. **Configure LTI:** Add LMS consumer keys for LTI integration
7. **Test Access:** Log in with admin credentials and verify access

## Development vs Production

### Development
```yaml
app:
  debug: true
  log_level: 'debug'

session:
  cookie_secure: false  # For non-HTTPS local dev
```

### Production
```yaml
app:
  debug: false
  log_level: 'error'

session:
  cookie_secure: true  # HTTPS only
```

**Production Checklist:**
- [ ] Set `debug: false`
- [ ] Set `cookie_secure: true`
- [ ] Use strong database credentials
- [ ] Restrict file permissions on config.yaml
- [ ] Configure firewall rules for MySQL
- [ ] Enable HTTPS on web server
- [ ] Set up database backups
- [ ] Configure log rotation

## Additional Resources

- **Schema Documentation:** [docs/concepts/SCHEMA.md](../../docs/concepts/SCHEMA.md)
- **Security Guide:** [docs/concepts/SECURITY.md](../../docs/concepts/SECURITY.md)
- **Authentication:** [docs/concepts/AUTH.md](../../docs/concepts/AUTH.md)
