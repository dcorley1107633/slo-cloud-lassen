# Plugin Implementation Guide

This guide provides practical patterns for building plugins as described in [../concepts/PLUGIN.md](../concepts/PLUGIN.md).

## Plugin Directory Structure

Plugins use a simple, flexible structure:

```
src/system/plugins/local/{plugin-id}/
├── plugin.json           # Manifest (metadata, hooks, routes)
├── {PluginName}.php      # Main plugin class (optional)
├── handler.php           # Direct request handler (for simple plugins)
├── template.php          # HTML template (if needed)
├── assets/               # CSS, JS, images
│   ├── css/
│   ├── js/
│   └── images/
├── config.php            # Configuration defaults (optional)
└── README.md            # Plugin documentation
```

**For simple plugins:**
- Use `handler.php` with direct logic
- Include `template.php` for rendering
- Skip the class-based structure

**For complex plugins:**
- Use class-based structure with `{PluginName}.php`
- Organize related functions together
- Still keep it simple - no MVC overhead

## Creating a Plugin Manifest

### Basic Manifest Structure

**File: `plugin.json`**

Include required fields:
- `id` - Unique identifier (lowercase-with-dashes)
- `name` - Display name
- `version` - Semantic version (1.0.0)
- `description` - Brief description
- `author` - Author name/organization
- `type` - Plugin type (dashboard, report, theme, export, connector)

Include hook registrations:
- Map hook names to plugin method names
- System calls these methods when hooks fire

Include routes (if plugin has UI):
- Define URL paths, controller, action
- Specify required permissions

Include permissions (if plugin adds new actions):
- Define permission identifiers
- Follow `plugin-id.action` naming

### Dependency Declaration

Specify requirements:
- Minimum SLOCloud version
- PHP version/extensions needed
- Other plugins (if dependent)

## Implementing Plugin Class

### Class Structure

Main plugin class:
- Extends base Plugin class
- Named after plugin (CamelCase)
- Located in plugin root directory

Constructor receives:
- Plugin ID
- Plugin file path
- Base class stores these

### Required Methods

**activate()**
- Called when plugin is enabled
- Initialize configuration
- Register plugin with system
- Return true on success, false on failure

**deactivate()**
- Called when plugin is disabled
- Clean up temporary state (session data, cache)
- Return true on success

**uninstall()**
- Called when plugin is removed
- Remove configuration
- Clean up any plugin-specific settings
- Cannot be undone

### Hook Handler Methods

Methods that handle hook events:
- Receive data array as parameter
- Process or modify data
- Return modified data or result
- Use return null to pass through unchanged

## Plugin Types

### Dashboard Widget Plugin

Adds card/widget to admin dashboard.

**Simple Implementation Example:**

```php
<?php
// src/system/plugins/local/my-widget/widget.php

require_once APP_ROOT . '/Core/Database.php';
$db = new \SLOCloud\Core\Database();

// Query data for widget
$stmt = $db->prepare("SELECT COUNT(*) FROM assessments WHERE assessment_date > ?");
$stmt->execute([date('Y-m-d', strtotime('-30 days'))]);
$recentCount = $stmt->fetchColumn();

// Return widget HTML
return [
    'id' => 'my-widget',
    'title' => 'Recent Assessments',
    'icon' => 'fa-chart-line',
    'content' => "<p class='display-4'>$recentCount</p><p>assessments in last 30 days</p>",
    'order' => 10
];
```

**Implementation steps:**
1. Hook into `dashboard.widgets` 
2. Query database directly for needed data
3. Generate and return widget HTML
4. Include inline styles or load CSS asset from `assets/`
5. No models/controllers/views needed

### Report Plugin

Adds new report type to reporting system.

**Implementation steps:**
1. Hook into `reports.types`
2. Define report metadata (id, name, description)
3. Implement report generation method
4. Query required data from models
5. Format data for display
6. Support multiple output formats (HTML, CSV, PDF)

### Theme Plugin

Provides alternative visual styling.

**Implementation steps:**
1. Create CSS file with style overrides
2. On activation, copy theme CSS to src/assets/css/ directory
3. Hook into `themes.available`
4. Add theme option to list
5. System loads chosen theme CSS for user

### Export Plugin

Adds new export format for reports/data.

**Implementation steps:**
1. Hook into `export.formats`
2. Register format (id, name, MIME type, file extension)
3. Implement export method
4. Transform data array to format
5. Return formatted content
6. Set appropriate headers for download

### Data Connector Plugin

See [DATA_CONNECTORS.md](DATA_CONNECTORS.md) for comprehensive guide.

## Database Operations

### When Plugins Need Database Schema
Access

### Read-Only Plugin Pattern

**All plugins are read-only** - they query existing core tables but don't create their own schema.

This keeps plugins simple:
- No schema management
- No migrations
- No table prefix handling
- No database-specific SQL dialects
- Just query core data

**Plugins access core tables:**
- `{$dbPrefix}users` - User accounts
- `{$dbPrefix}students` - Student records
- `{$dbPrefix}courses` - Course catalog
- `{$dbPrefix}programs` - Academic programs
- `{$dbPrefix}assessments` - Assessment data
- `{$dbPrefix}institutional_outcomes` - ISLOs
- `{$dbPrefix}program_outcomes` - PSLOs
- See [SCHEMA.md](../concepts/SCHEMA.md) for complete table reference

### Querying Core Tables

**Always use `$dbPrefix` variable:**

```php
// Correct - uses configured table prefix
$result = $this->db->query(
    "SELECT COUNT(*) as total FROM {$this->dbPrefix}assessments WHERE term_code = ?",
    [$termCode], 's'
);

// Wrong - breaks if prefix is configured
$result = $this->db->query(
    "SELECT COUNT(*) FROM assessments WHERE term_code = ?",
    [$termCode], 's'
);
```

**Use prepared statements always:**

```php
// Query with parameters
$result = $this->db->query(
    "SELECT * FROM {$this->dbPrefix}students WHERE program_fk = ? AND is_active = ?",
    [$programId, 1],
    'ii'
);

// Parameter types: 's' = string, 'i' = integer, 'd' = double
```

**Query pattern for plugins:**

```php
<?php
// Get database instance (injected by base Plugin class)
$db = $this->db;
$dbPrefix = $this->dbPrefix;

// Query core tables
$result = $db->query("
    SELECT 
        c.course_name,
        COUNT(DISTINCT e.student_fk) as enrollment_count
    FROM {$dbPrefix}courses c
    LEFT JOIN {$dbPrefix}course_sections cs ON c.courses_pk = cs.course_fk
    LEFT JOIN {$dbPrefix}enrollment e ON cs.sections_pk = e.section_fk
    WHERE c.term_code = ?
    GROUP BY c.courses_pk
    ORDER BY enrollment_count DESC
    LIMIT 10
", [$termCode], 's');

$topCourses = $result->fetchAll();
```

### Using Core Models

**Preferred approach** - Use existing core models instead of writing SQL:

```php
<?php
// Load core models (injected or require)
$studentModel = new \SLOCloud\Models\Student($this->db, $this->dbPrefix);
$assessmentModel = new \SLOCloud\Models\Assessment($this->db, $this->dbPrefix);

// Use model methods
$students = $studentModel->findByProgram($programId);
$recentAssessments = $assessmentModel->findByDateRange($startDate, $endDate);
```

**Benefits:**
- No SQL required
- Consistent with core patterns
- Automatic `$dbPrefix` handling
- Built-in validation and error handling

### Plugin Settings Storage

**For plugin configuration, use existing `settings` table:**

```php
// Store plugin setting
$this->db->query(
    "INSERT INTO {$this->dbPrefix}settings (setting_key, setting_value) 
     VALUES (?, ?) 
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    ["plugin.{$this->id}.option", $value],
    'ss'
);

// Retrieve plugin setting
$result = $this->db->query(
    "SELECT setting_value FROM {$this->dbPrefix}settings WHERE setting_key = ?",
    ["plugin.{$this->id}.option"],
    's'
);
$value = $result->fetchColumn();
```

**Naming convention for plugin settings:**
- Pattern: `plugin.{plugin-id}.{setting-name}`
- Example: `plugin.excel-export.default-format`
- Keeps plugin settings namespaced and organized

### Database-Agnostic Queries

**Plugins piggyback on core's database driver:**
- Core handles MySQL vs SQL Server differences
- Plugins write standard SQL with parameter binding
- PDO abstracts database-specific features

**Stick to compatible SQL:**
- Use `?` parameter placeholders
- Avoid database-specific functions (use PHP instead)
- Don't rely on AUTO_INCREMENT or IDENTITY in queries
- Use standard SQL date functions or format dates in PHP
### Using Core Models

Load and use core models:
1. Reference model class
2. Instantiate model
3. Use model methods for CRUD
4. Never write directly to core tables with SQL

### Checking Permissions

Before sensitive actions:
1. Get authentication system
2. Check if user has permission
3. Throw exception if denied
4. Continue if authorized

**Permission check pattern:**
- Get current user
- Call permission check with permission string
- Handle forbidden exception if needed

### Input Validation

For all user input:
1. Define validation rules
2. Create validator instance
3. Run validation
4. Return errors if validation fails
5. Process data if validation passes

**Validation rules:**
- Use same validation system as core
- Define rules as strings or arrays
- Check required, type, length, format
- Custom validators for complex logic

## Asset Management

### Including CSS

Reference CSS in views:
- Use plugin asset path helper
- Load from `src/system/plugins/local/{id}/assets/`
- Include in view `<head>` section
- Support theme customization

### Including JavaScript

Reference JavaScript in views:
- Load after page content
- Use plugin asset path helper
- Initialize after DOM ready
- Avoid conflicts with core JS

### Asset Organization

Organize assets by type:
- `assets/css/` - Stylesheets
- `assets/js/` - JavaScript files
- `assets/images/` - Images
- `assets/fonts/` - Fonts (if needed)

## Configuration Management

### Default Configuration

**File: `config.php`**

Return associative array:
- Configuration keys and default values
- Support environment variable overrides
- Document each option
- Use sensible defaults

### Accessing Configuration

In plugin code:
- Use `getConfig($key)` method
- Returns value from config.php
- Checks environment variables first
- Falls back to default

### User Configuration

If plugin needs user settings:
- Create settings page
- Store in plugin-specific table or core settings
- Provide admin UI to modify
- Validate on save

## Routes and Controllers

### Defining Routes

In plugin.json manifest:
- Specify path pattern
- Reference controller and action
- Define required permissions
- Support parameter placeholders

### Controller Implementation

Plugin controllers:
- Handle plugin-specific routes
- Extend base Controller
- Located in `controllers/` subdirectory
- Follow core controller patterns

**Controller responsibilities:**
- Validate input
- Check permissions
- Execute business logic
- Render views or return JSON

### View Rendering

Plugin views:
- Located in `views/` subdirectory
- PHP templates with data interpolation
- Include Bootstrap 5 classes
- Escape all output

## Security Best Practices

### Namespace Isolation

Prefix all plugin identifiers:
- Table names: `pluginid_tablename`
- Config keys: `pluginid.setting`
- CSS classes: `pluginid-classname`
- JavaScript: `PluginId` namespace

### Resource Cleanup

On uninstall:
- Drop all plugin tables
- Remove all assets
- Delete configuration entries
- Leave no traces

### Error Handling

Handle errors gracefully:
- Catch exceptions
- Log errors with context
- Don't break core functionality
- Return meaningful error messages

### Permission Checks

Before every action:
- Check user authentication
- Verify required permissions
- Check context (if scoped)
- Fail securely (deny by default)

## Testing Plugins
Settings keys: `plugin.pluginid.setting`
- CSS classes: `pluginid-classname`
- JavaScript: `PluginId` namespace
- Session keys: `plugin_pluginid_data`

### Resource Cleanup

On uninstall:
- Delete plugin settings from core settings table
- Clean up session data
- Remove cached files (if any)
- Leave core data intactration:
- Test activation/deactivation
- Test route handling
- Test database operations
- Test with core system

### Manual Testing

Test plugin functionality:
- Install and activate
- Test all features
- Test with different user roles
- Test deactivation and uninstall
- Verify cleanup

## Plugin Development Workflow

1. **Plan Plugin**
   - Define purpose and features
   - Identify required hooks
   - Design data model (if needed)
   - Plan user interface

2. **Create Structure**
   - Create plugin directory
   - Create manifest file
   - Create main plugin class
   - Set up directory structure

3. **Implement Features**
   - Implement activation logic
   - Write hook handlers
   - Create controllers and views
   - Add assets

4. **Test Thoroughly**
   - Write automated tests
   - Manual testing across scenarios
   - Test edge cases
   - Test cleanup

5. **Document**
   - Write README with installation steps
   - Document configuration options
   - Provide usage examples
   - Note dependencies

6. **Package** (for distribution)
   - Include all necessary files
   - Compress as ZIP
   - Include version number
   - Provide installation instructions

## Common Pitfalls

### Don't Modify Core

- ❌ Never modify core database tables directly
- ❌ Never modify core code files
- ✅ Use hooks to extend behavior
- ✅ Use models to interact with data

### Don't Assume Environment

- ❌ Don't hardcode paths
- ❌ Don't assume permissions
- ✅ Use path helpers
- ✅ Check permissions before actions

### Don't Break on Deactivation

- ❌ Don't leave broken references
- ❌ Don't delete user data on deactivation
- ✅ Gracefully handle missing plugin
- ✅ Only delete data on uninstall

### Don't Conflict with Other Plugins

- ❌ Don't use generic names
- ❌ Don't pollute global namespace
- ✅ Prefix everything with plugin ID
- ✅ Check for conflicts on activation

## Example Plugin Projects

See [../concepts/PLUGIN.md](../concepts/PLUGIN.md) for conceptual examples:
- CSV Export Plugin
- Dark Theme Plugin   (INSERT, UPDATE, DELETE)
- ❌ Never create custom tables
- ❌ Never modify core code files
- ✅ Read-only queries on core tables
- ✅ Use hooks to extend behavior
- ✅ Use core models when availableypes and patterns.
