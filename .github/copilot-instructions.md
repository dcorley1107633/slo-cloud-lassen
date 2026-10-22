# SLOCloud - Copilot Instructions

## Project Overview

**SLOCloud** (Modular Outcomes System for Achievement and Institutional Compliance) is an open-source Student Learning Outcomes (SLO) assessment platform for higher education with LTI 1.1/1.3 integration. Built in PHP 8.1+ with MySQL using organized procedural architecture (Page Controller pattern). FERPA-compliant student data handling is critical.

**Current Status:** Early development phase with comprehensive design docs in `design_concepts/` and working demo implementations in `slocloud-slo/demo/` using AdminLTE 4 framework.

## [SECURITY] CRITICAL: New Security Architecture (March 2026)

**Two-file encryption approach for maximum security:**

```
key.yaml (master keys - PROTECTED)
    v decrypts
config.yaml (encrypted credentials - safe for git)
    v cached in
Session (decrypted config - performance)
```

**Files:**
- `src/config/key.yaml` - Master encryption keys (NEVER commit, store outside web root)
- `src/config/config.yaml` - Encrypted configuration (CAN commit if all sensitive values encrypted)
- `tools/encrypt_config_value.php` - Utility to encrypt values for config.yaml

**Implementation:**
- Config class loads key from `key.yaml` first (automatic search paths)
- Uses that key to decrypt encrypted fields in `config.yaml`
- Session caching keeps decrypted config in memory (no repeated decryption)
- Encrypted fields: `database.password`, `email.smtp_password`, `lti.consumer_secret`, `security.student_encryption_key`

**See:**
- [src/config/README.md](../src/config/README.md) - Complete setup guide
- [src/config/key.yaml.example](../src/config/key.yaml.example) - Key file template

## [SYNC] Configuration Template Merging (Backward Compatibility)

**SLOCloud automatically merges user config with template defaults for seamless upgrades:**

```
config.yaml.template (defaults)
    v merged with
config.yaml (user values - take precedence)
    v cached in
Session (merged + decrypted config)
```

**How it works:**
- Config class loads `config.yaml.template` on every config load (if present)
- Template defaults are merged with user's `config.yaml`
- User values **always** take precedence over template defaults
- Merged config is cached in session for performance
- Cache invalidates when **either** config.yaml or template changes

**Benefits:**
- New config options from updates work immediately with safe defaults
- Old config files continue working after upgrades without manual editing
- No breaking changes when new features are added
- Administrators can upgrade without touching config.yaml

**Example:** When `php_error_mode` was added:
- Template provides: `php_error_mode: logged` (safe default)
- Old deployments don't have this key in their config.yaml
- System uses template default automatically
- No downtime, no manual intervention required

**Implementation:** See `src/system/Core/Config.php` -> `mergeTemplateDefaults()`

AVOID UNICODE characters in code and documentation to ensure compatibility across all environments. Use ASCII characters only.

- X (BALLOT X) -> X or [X] or [FAIL]
- OK (CHECK MARK) -> OK or [OK] or [PASS]
- +===+ (box drawing) -> +===+ or similar ASCII
- | (box drawing) -> |

## Architecture-First Principle

**ALWAYS consult architecture docs before implementing features or making architectural decisions.** 

### Documentation Structure

**Architecture Concepts** (`docs/concepts/`)
- High-level system design and architectural decisions
- What the system does and why
- Non-negotiable requirements and constraints

**Implementation Guides** (`docs/implementation/`)
- Step-by-step patterns and workflows
- How to build features
- Code organization and best practices

### Before You Code

- **Configuration**: Read [CONFIGURATION.md](../docs/concepts/CONFIGURATION.md) for config structure and available constants
- **Code Structure**: Read [CODE_ORGANIZATION.md](../docs/concepts/CODE_ORGANIZATION.md) for architecture overview
- **Implementation**: Read [CODE_GUIDE.md](../docs/implementation/CODE_GUIDE.md) for practical patterns
- **Database**: Read [SCHEMA.md](../docs/concepts/SCHEMA.md) for table structure and naming conventions
- **Auth**: Read [AUTH.md](../docs/concepts/AUTH.md) and [SECURITY.md](../docs/concepts/SECURITY.md)
- **Plugins**: Read [PLUGIN.md](../docs/concepts/PLUGIN.md), then [PLUGIN_GUIDE.md](../docs/implementation/PLUGIN_GUIDE.md)
- **Data Connectors**: Read [PLUGIN.md](../docs/concepts/PLUGIN.md), then [DATA_CONNECTORS.md](../docs/implementation/DATA_CONNECTORS.md)
- **Tests**: Read [TESTING.md](../docs/concepts/TESTING.md)

**All implementations must align with architecture docs.** Concepts explain *what* and *why*, guides explain *how*.

## Architecture Overview

**Drop-in Install:** The `src/` directory is designed as the web root for drop-in installation. Point your web server document root at `src/` and the application is ready to run after database setup. See [ARCHITECTURE.md](../docs/concepts/ARCHITECTURE.md) for security requirements.

**Base URL Handling:** The application automatically detects its installation path during setup and stores it in `config.yaml` for optimal performance. The `BASE_URL` constant is available throughout the application for building URLs. This supports installations at domain root (`/`) or subdirectories (`/beta/`, `/slocloud/`). Use `BASE_URL` for all internal links and redirects.

### Hierarchical Assessment Model

```text
Institution -> Institutional Outcomes -> Program Outcomes -> SLOs -> Assessments
Department -> Program -> Course -> Course Section -> Student Enrollment
```

**Critical Files:**

- [docs/concepts/ARCHITECTURE.md](../docs/concepts/ARCHITECTURE.md) - System architecture and entity relationships
- [docs/concepts/CODE_ORGANIZATION.md](../docs/concepts/CODE_ORGANIZATION.md) - Code structure and patterns
- [docs/concepts/CONFIGURATION.md](../docs/concepts/CONFIGURATION.md) - Configuration structure, constants, and settings
- [docs/concepts/SCHEMA.md](../docs/concepts/SCHEMA.md) - Complete database schema with naming conventions
- [docs/implementation/CODE_GUIDE.md](../docs/implementation/CODE_GUIDE.md) - Practical implementation patterns

### Code Structure

Organized procedural PHP with optional data access layer:

- **Feature Directories** (`src/dashboard/`, `src/lti/`): Pages organized by feature area
- **System Infrastructure** (`src/system/`): Core framework code
  - **Core** (`src/system/Core/`): Database, Config, Logger, Path helpers
  - **Models** (`src/system/Models/`): Optional data access layer - Base class in `src/system/Core/Model.php` for shared patterns
  - **Common Includes** (`src/system/includes/`): Shared page components
    - `admin_session.php` - Session/auth check for admin pages (replaces old `header.php`)
    - `init.php` - Database (`$db`) and config (`$dbPrefix`) initialization
    - `message_page.php` - Helper function for error/success pages
  - **Theme System** (`src/system/Core/ThemeLoader.php`): Handles header/footer/sidebar rendering
    - `ThemeLoader::getActiveTheme(null, 'admin')` - Load admin theme
    - `ThemeLoader::getActiveTheme(null, 'lti')` - Load LTI/student-facing theme
    - `ThemeLoader::getActiveTheme()` - Load default theme
  - **Plugins** (`src/system/plugins/`): Plugin framework

**Using the Theme System (Admin Pages):**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

$pageTitle = 'My Page Title';
$currentPage = 'my_page'; // matches sidebar nav identifier

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'      => 'admin',
    'pageTitle'   => $pageTitle,
    'currentPage' => $currentPage,
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'My Page Title']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<!-- Your page content here -->

<?php $theme->showFooter($context); ?>
```

**Using the Theme System (LTI Pages):**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'    => 'lti',
    'pageTitle' => 'Assessment',
]);

$theme = ThemeLoader::getActiveTheme(null, 'lti');
$theme->showHeader($context);
?>

<!-- Your page content here -->

<?php $theme->showFooter($context); ?>
```

**Naming Conventions:**

- Primary keys: `{table_name}_pk` (e.g., `courses_pk`, `students_pk`)
- Foreign keys: `{referenced_table}_fk` (e.g., `course_fk`, `program_fk`)
- Ordering fields: `sequence_num`
- Soft deletes: `is_active` BOOLEAN

**Database Query Pattern:**

All database queries MUST use the `$dbPrefix` variable for table names. This variable is set by `init.php` based on the configured table prefix in `config.yaml`.

```php
<?php
require_once __DIR__ . '/system/includes/init.php';

// CORRECT: Use $dbPrefix variable
$result = $db->query(
    "SELECT * FROM {$dbPrefix}users WHERE users_pk = ?",
    [$userId],
    'i'
);

// CORRECT: Multiple tables
$result = $db->query("
    SELECT u.*, r.role_name 
    FROM {$dbPrefix}users u
    LEFT JOIN {$dbPrefix}user_roles ur ON u.users_pk = ur.user_fk
    LEFT JOIN {$dbPrefix}roles r ON ur.role_fk = r.roles_pk
    WHERE u.users_pk = ?
", [$userId], 'i');

// WRONG: Hardcoded table names will break if prefix is configured
$result = $db->query("SELECT * FROM users WHERE users_pk = ?", [$userId], 'i');
$result = $db->query("SELECT * FROM slocloud_tbl_users WHERE users_pk = ?", [$userId], 'i');
```

**Key Points:**
- Always include `init.php` to get `$db` and `$dbPrefix` variables
- Use string interpolation: `{$dbPrefix}tablename` in all queries
- Table names in schema are prefixed with `slocloud_tbl_` which gets replaced during setup
- Application code uses `$dbPrefix` which reflects the actual configured prefix
- Never hardcode table names without the prefix variable

## Authentication & Security

**Two concurrent auth methods:**

1. **Dashboard**: Local Argon2id (64MB memory, 4 iterations) with session-based auth
2. **LTI**: OAuth 1.0 (LTI 1.1) or JWT/JWKS (LTI 1.3) from Learning Management Systems

**Emergency Admin (Break Glass)**:
- Configuration-based account in `config.yaml` (e.g., `emergency_admin.username`, `emergency_admin.password`)
- Email format required for login form compatibility
- Bypasses database for emergency recovery access
- Password stored in plain text in config.yaml ONLY - this is a deliberate exception to the Argon2id rule, required for DB-independent break-glass access;
- Checked BEFORE database authentication
- Disable by setting `emergency_admin.enabled: false`
- NOTE: All regular user passwords (in the database) MUST use Argon2id hashing; plain text applies only to this config.yaml entry

**Security Requirements** (see [docs/concepts/SECURITY.md](../docs/concepts/SECURITY.md)):

- **Always use prepared statements** - no string concatenation in SQL queries
- **Escape all output** - HTML escape user-generated content
- **CSRF tokens** - required on all POST/PUT/DELETE operations
- **HttpOnly, Secure, SameSite=Strict** cookies
- **2-hour session timeout** with ID regeneration on privilege change
- **Password hashing**: Argon2id with strong parameters (64MB memory, 4 iterations)
- **Role-based access control** - enforce permissions on all admin pages and actions  

**Application Pattern:**

- Uses `ThemeLoader::getActiveTheme(null, 'admin')` for admin pages (with sidebar)
- Uses `ThemeLoader::getActiveTheme(null, 'lti')` for LTI/student-facing pages
- Admin pages must `require_once admin_session.php` first for auth check

### Session Handling Pattern

```php
// Always configure session security
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Regenerate ID periodically (every 30 min)
if (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
```

## Plugin Architecture

Plugins extend functionality without modifying core code. Types: Dashboard widgets, Reports, Themes, Export formats.

**Structure** (see [docs/concepts/PLUGIN.md](../docs/concepts/PLUGIN.md)):

```text
src/system/plugins/local/{plugin-id}/
+-- plugin.json         # Manifest with hooks, routes, permissions
+-- {PluginName}.php    # Main class extending Plugin base
+-- assets/             # CSS, JS, images
```

**Plugin Base**: `src/system/Core/Plugin.php`

## Testing Strategy

See [docs/concepts/TESTING.md](../docs/concepts/TESTING.md) for comprehensive testing approach.

**Key testing areas:**

- Unit tests: Models, validation, business logic (80%+ coverage target)
- Integration tests: Controller-Model interactions, auth flows
- Security tests: SQL injection, XSS, CSRF, authorization violations
- E2E tests: LTI launches, assessment workflows

**No test files exist yet** - refer to TESTING.md when implementing.

## Code Style & Patterns

**PHP:**

- Strict types: `declare(strict_types=1);`
- Type hints on all method signatures
- PSR-12 coding standard
- No short PHP tags (`<?=` allowed in views only)

**HTML/CSS:**

- AdminLTE 4 framework (CDN: `https://cdn.jsdelivr.net/npm/adminlte4@4.0.0-rc.6.20260104/dist/css/adminlte.min.css`)
- Bootstrap 5 (AdminLTE dependency)
- jQuery 3.7 (optional, for custom scripting)
- Font Awesome icons (`https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`)
- Semantic HTML5 elements
- Color scheme: `--primary-dark: #0D47A1`, `--accent-blue: #1976D2`, `--brand-teal: #1565C0`

## Mandatory Update Rules

These rules apply any time related code changes. No exceptions.

### Setup Changes -> Update Setup Guide

Any change that affects how SLOCloud is installed or configured **must** also update `docs/SETUP_GUIDE.md`:

- Changes to `src/setup/index.php` (setup wizard logic, form fields, defaults)
- Changes to `src/config/config.yaml.template` (new or removed config keys)
- Changes to `src/system/database/schema.sql` or `schema_mssql.sql` (new tables, dropped tables, column changes)
- Changes to server or PHP extension requirements
- Changes to the encryption key architecture (`src/system/Core/Encryption.php`, `key.yaml` handling)
- Changes to file permissions or `.htaccess` rules
- Changes to the emergency admin account behavior

Update the relevant section(s) of `docs/SETUP_GUIDE.md` in the same commit as the code change.

### Version Changes -> Update Patch Notes

Any change to `src/VERSION` **must** be accompanied by a new entry in `src/PATCH_NOTES.md`:

- Format: `## vX.Y.Z (YYYY-MM-DD)`
- Sections: `New Features`, `Improvements`, `Security Fixes`, `Removed`, `Bug Fixes` (include only sections that apply)
- Each bullet must describe the user-visible change, not the implementation detail
- Security fixes must always be listed explicitly

## Common Tasks

### Adding a Page

1. **Determine directory** - `src/dashboard/` for admin pages, `src/lti/` for LTI tools, etc.
2. **Read [SCHEMA.md](../docs/concepts/SCHEMA.md)** - Understand required tables and relationships
3. **Review [CODE_GUIDE.md](../docs/implementation/CODE_GUIDE.md)** - Follow page structure patterns
4. **Include init.php** - Always `require_once __DIR__ . '/system/includes/init.php'` to get `$db` and `$dbPrefix`
5. **Use ThemeLoader** - `require_once ThemeLoader.php`, create `ThemeContext`, call `ThemeLoader::getActiveTheme(null, 'admin')` then `$theme->showHeader($context)` / `$theme->showFooter($context)`
6. **Database queries** - Use prepared statements with `{$dbPrefix}tablename` pattern (never hardcode table names)
7. **Follow security patterns** from [SECURITY.md](../docs/concepts/SECURITY.md) - CSRF tokens, output escaping, prepared statements

### Adding an Optional Model (When Useful)

1. **Read [SCHEMA.md](../docs/concepts/SCHEMA.md) first** - Verify table structure, relationships, and naming conventions
2. **Review [CODE_GUIDE.md](../docs/implementation/CODE_GUIDE.md)** - See when models help vs. direct queries
3. **Extend base Model class** (from `src/system/Core/Model.php`) if shared patterns emerge
4. **Set `$table` and `$primaryKey`** properties - Use base table name without prefix (e.g., `users` not `slocloud_tbl_users`)
5. **Use `$dbPrefix` in queries** - Model methods must use `{$this->dbPrefix}tablename` pattern
6. **Implement domain-specific methods** - Query, validation, business logic
7. **Follow security patterns** from [SECURITY.md](../docs/concepts/SECURITY.md) - use prepared statements with table prefixes

### Adding a Demo Page

1. **Review existing demos** in `slocloud-slo/demo/` for patterns
2. Create file in `slocloud-slo/demo/`
3. Include `includes/header.php` and `includes/sidebar.php` for admin pages (or omit sidebar for LTI pages)
4. Use `__DIR__` for relative paths to `sample.csv`
5. Implement session security pattern (see Session Handling Pattern above)
6. Add card to `slocloud-slo/demo/index.php` portal
7. Update `slocloud-slo/demo/README.md`

### Implementing LTI Integration

**Read [AUTH.md](../docs/concepts/AUTH.md) first** for complete LTI launch validation patterns:

- LTI 1.1: OAuth 1.0 signature validation
- LTI 1.3: JWKS public key verification
- Auto-provision users from launch parameters
- Role mapping to internal RBAC system per [AUTH.md](../docs/concepts/AUTH.md) role hierarchy

### Adding Application Logic

1. **Keep it simple** - Direct logic in page files unless complexity warrants extraction
2. **Review [CODE_GUIDE.md](../docs/implementation/CODE_GUIDE.md)** for request handling patterns
3. **Implement validation** per [SECURITY.md](../docs/concepts/SECURITY.md)
4. **Use CSRF tokens** on all POST/PUT/DELETE operations
5. **Follow authorization patterns** from [AUTH.md](../docs/concepts/AUTH.md)
6. **Extract to functions/classes** only when patterns repeat 3+ times

### Building Plugins

1. **Read [PLUGIN.md](../docs/concepts/PLUGIN.md)** for architecture and plugin types
2. **Review [PLUGIN_GUIDE.md](../docs/implementation/PLUGIN_GUIDE.md) or [DATA_CONNECTORS.md](../docs/implementation/DATA_CONNECTORS.md)** for step-by-step patterns
3. **Keep plugins simple** - Direct logic, no framework overhead required
4. **Follow non-invasive principle** - Never modify core schema
5. **Database access** - Use Core\Database directly or via Models, always use `{$dbPrefix}tablename`
6. **Follow authorization patterns** from [AUTH.md](../docs/concepts/AUTH.md)

## Key Dependencies

**Required (Hard Requirements):**

- PHP 8.1+ with extensions: pdo, pdo_mysql (or pdo_sqlsrv), mbstring, openssl, json
- MySQL 8.0+ (InnoDB engine, UTF8MB4 charset) OR SQL Server 2016+
  - PDO-based data layer supports both databases
  - Configure driver in config.yaml (driver: mysql or driver: mssql)
  - No ORM - direct SQL with PDO prepared statements

**Frontend (CDN):**

- AdminLTE 4.0 (includes Bootstrap 5.3.2)
- jQuery 3.7.0 (optional, for custom scripting)
- Font Awesome 6.4.0

**Note:** For external system integration (SIS, LMS), use data connector plugins rather than attempting database substitution. See [docs/concepts/PLUGIN.md](../docs/concepts/PLUGIN.md).

## Database Schema Requirements

**CRITICAL: Two-part table naming pattern:**

1. **Schema file** (`src/system/database/schema.sql`) uses `slocloud_tbl_` prefix
2. **Application code** uses `{$dbPrefix}` variable from `init.php`

### Schema File Location

The canonical schema file is **`src/system/database/schema.sql`**. This is the only version setup reads during installation.

The `database/schema.sql` at project root is optional (for version control reference only). All schema changes must be made to `src/system/database/schema.sql`.

### Schema File Pattern

Setup process replaces `slocloud_tbl_` with configured prefix (or removes it if no prefix configured):
- User configures table prefix during setup (e.g., `slocloud_`, `slo_`, or empty for no prefix)
- `src/setup/index.php` reads `database/schema.sql` and replaces all `slocloud_tbl_` instances
- This allows flexible deployment without maintaining multiple schema files

**Required naming pattern in schema.sql:**

```sql
CREATE TABLE slocloud_tbl_users (
    users_pk INT AUTO_INCREMENT PRIMARY KEY,
    ...
);

CREATE TABLE slocloud_tbl_roles (
    roles_pk INT AUTO_INCREMENT PRIMARY KEY,
    ...
    FOREIGN KEY (user_fk) REFERENCES slocloud_tbl_users(users_pk)
);
```

**DO NOT write schema tables without the `slocloud_tbl_` prefix.** Setup will fail to apply configured prefix.

When adding or modifying tables in schema:
1. Always use `slocloud_tbl_` prefix in CREATE TABLE statements
2. Always use `slocloud_tbl_` prefix in FOREIGN KEY REFERENCES
3. Always use `slocloud_tbl_` prefix in DROP TABLE statements
4. Always use `slocloud_tbl_` prefix in INSERT INTO statements
5. Test by running setup with different prefix configurations

### Application Code Pattern

**Never hardcode table names.** Always use `$dbPrefix` variable from `init.php`:

```php
// CORRECT
require_once __DIR__ . '/system/includes/init.php';
$result = $db->query("SELECT * FROM {$dbPrefix}users WHERE users_pk = ?", [$id], 'i');

// WRONG - will break if prefix is configured
$result = $db->query("SELECT * FROM users WHERE users_pk = ?", [$id], 'i');
$result = $db->query("SELECT * FROM slocloud_tbl_users WHERE users_pk = ?", [$id], 'i');
```

The `$dbPrefix` variable automatically reflects the configured prefix (e.g., `slocloud_`, `slo_`, or empty string).

## Important Constraints

- **FERPA Compliance**: Student data must be protected, audit all access
- **Database**: MySQL 8.0+ or SQL Server 2016+ via PDO with prepared statements
  - Configure in config.yaml: driver: mysql or driver: mssql
  - Schema files: schema.sql (MySQL) or schema_mssql.sql (SQL Server)
  - For external integrations, use data connector plugins (see [PLUGIN.md](../docs/concepts/PLUGIN.md))
- **No Composer yet**: Dependencies via CDN or manual installation
- **Windows Development**: Use PowerShell commands, path separators handled by PHP
- **PowerShell file writes**: NEVER use `[System.Text.Encoding]::UTF8` to write PHP files - it adds a UTF-8 BOM which causes `PHP Fatal error: strict_types declaration must be the very first statement`. Always use `New-Object System.Text.UTF8Encoding($false)` for BOM-free UTF-8 writes:
  ```powershell
  $noBom = New-Object System.Text.UTF8Encoding($false)
  [System.IO.File]::WriteAllText($path, $content, $noBom)
  ```

## Project Philosophy: Simplicity Over Flexibility

**Prioritize usability and simplicity first. Be flexible when it doesn't compromise these.**

**Call out overengineering.** If a request adds unnecessary complexity, abstraction, or "flexibility" that isn't needed, push back firmly:

- [X] "Should we add support for PostgreSQL/SQLite/Oracle?"
  - **Response:** "No. MySQL 8.0+ and SQL Server 2016+ are supported via PDO. These cover 95% of higher ed institutions. Adding more databases adds testing burden and SQL dialect complexity without clear benefit. Use connector plugins if you need to integrate external systems."

- [X] "Let's make this configurable so users can customize..."
  - **Response:** "What's the actual use case? Don't add configuration for theoretical needs. Ship with good defaults. Add configurability only when users actually request it."

- [X] "We should support backward compatibility with..."
  - **Response:** "No. Set clear requirements. If adopters need old versions, they should upgrade their infrastructure first."

- [X] "What if someone wants to use NoSQL/PostgreSQL/Oracle/SQLite?"
  - **Response:** "Not supported. MySQL 8.0+ and SQL Server 2016+ cover the target market. If they need alternative storage for external systems, they can use connector plugins to bridge data."

**YAGNI (You Aren't Gonna Need It):**

- Build what's needed now, not what might be needed later
- Hard requirements are better than trying to please everyone
- Complexity is expensive: development time, bugs, maintenance, onboarding
- Good software has opinions and constraints

**When to challenge the user:**

- Premature abstraction or generalization
- Adding flexibility without concrete use cases
- Supporting multiple options when one good option exists
- "Future-proofing" that makes current development harder
- Trying to accommodate edge cases that may never happen

**When to be flexible:**

- When it simplifies the user experience (optional fields, sensible defaults)
- When institutions demonstrably differ (SIS connectors, auth methods, custom reports)
- When flexibility is already simple to implement (configuration over code changes)
- When it prevents forking (plugin hooks for common extensions)

**Push for:**

- Concrete implementations over abstract patterns
- Clear, non-negotiable requirements
- Simple, maintainable code
- Shipping working features over theoretical flexibility
- Real user problems over hypothetical scenarios

## Design Patterns & Code Reuse

**Detect opportunities to reuse, don't theorize abstractions.**

When building multiple similar features (admin tables, reports, data exports), actively look for patterns worth sharing:

**Consistent patterns across similar features:**

- **DataTables**: Use server-side AJAX everywhere for admin tables
  - Even if client-side works for small datasets now
  - Rationale: Shared handler matures over time, benefits all consumers
  - "It's not if, but when" - tables grow, requirements change
  - Consistency reduces cognitive load and maintenance burden

- **CRUD operations**: Establish one pattern, apply consistently
  - Don't mix direct queries in some places and models in others without reason
  - Pick the simplest approach that works for the current case

- **Form validation**: Shared validation handlers for common patterns
  - Email, dates, foreign keys, etc.

**Factor shared components when:**

- You're actively building multiple consumers (not theoretically)
- The pattern has proven itself in 2-3 real implementations
- Sharing reduces duplication without adding complexity

**Don't prematurely abstract when:**

- Building the first version of something
- The "pattern" only exists in one place
- The abstraction adds layers without clear benefit

**User will steer as needed.** Focus on shipping working features, but keep eyes open for practical reuse opportunities.
