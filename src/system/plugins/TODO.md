# TODO: Plugin Manager

## Overview

Create a comprehensive plugin management system to handle plugin lifecycle, hooks, and administration.

## Plugin Directory Structure (IMPORTANT)

**Use flat structure:**
```
plugins/local/
├── banner-connector/          # type: "connector"
├── custom-slo-report/         # type: "report"
├── recent-assessments-widget/ # type: "widget"
└── institution-theme/         # type: "theme"
```

**NOT type-based folders:**
```
plugins/
├── connectors/    # ❌ NO - don't do this
├── reports/       # ❌ NO - unnecessary structure
└── widgets/       # ❌ NO - manifest already categorizes
```

**Rationale:**
- Plugin `type` field in `plugin.json` handles categorization
- Avoids ambiguity for multi-type plugins (e.g., connector that also adds widgets)
- Simpler discovery: `glob('plugins/local/*/plugin.json')`
- Filter by type in memory: `array_filter($plugins, fn($p) => $p['type'] === 'connector')`
- Performance non-issue: 20-50 plugins max, JSON parsing is fast
- Follows "Simplicity Over Flexibility" principle

**DO NOT add type-based subdirectories without revisiting this decision.**

## Required Components

### 1. Plugin Manager Class (`Core/PluginManager.php`)

**Responsibilities:**
- Scan `plugins/local/` directory for installed plugins
- Load and parse `plugin.json` manifests
- Track plugin activation status
- Execute plugin lifecycle methods (activate, deactivate, uninstall)
- Register and fire hooks
- Handle plugin dependencies

**Key Methods:**
```php
- discoverPlugins(): array           // Scan directory, load manifests
- activatePlugin(string $id): bool   // Enable plugin, run activate()
- deactivatePlugin(string $id): bool // Disable plugin, run deactivate()
- uninstallPlugin(string $id): bool  // Remove plugin data, run uninstall()
- isActive(string $id): bool         // Check if plugin is active
- getPlugins(): array                // Get all discovered plugins
- getActivePlugins(): array          // Get only active plugins
- registerHook(string $hook, callable $callback): void
- fireHook(string $hook, array $data = []): mixed
```

### 2. Plugin Base Class (`Core/Plugin.php`)

**Responsibilities:**
- Base class all plugins extend
- Define standard plugin interface
- Provide helper methods for database access, config, logging

**Required Methods:**
```php
abstract public function activate(): bool;
abstract public function deactivate(): bool;
abstract public function uninstall(): bool;
```

### 3. Plugin Admin UI (`dashboard/plugins.php`)

**Functionality:**
- List all discovered plugins with metadata
- Show activation status
- Enable/disable plugins with one click
- Display plugin errors/warnings
- Show plugin dependencies
- Uninstall plugins (with confirmation)

**UI Requirements:**
- Card-based layout (AdminLTE cards)
- Activation toggle switches
- Plugin metadata display (name, version, author, description)
- Settings link (if plugin has config UI)
- Delete/uninstall button with confirmation modal

### 4. Database Schema for Plugins

**Table: `plugins`**
```sql
CREATE TABLE plugins (
    plugins_pk INT AUTO_INCREMENT PRIMARY KEY,
    plugin_id VARCHAR(100) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT 0,
    installed_version VARCHAR(20),
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMP NULL,
    deactivated_at TIMESTAMP NULL,
    settings JSON,
    INDEX idx_active (is_active),
    INDEX idx_plugin_id (plugin_id)
);
```

### 5. Hook System

**Core Hooks to Implement:**
- `dashboard.widgets` - Add dashboard widgets
- `reports.types` - Register report types
- `export.formats` - Add export formats
- `themes.available` - Register themes
- `data.sync` - Trigger data connector syncs
- `user.login` - Post-login actions
- `assessment.created` - Post-assessment actions
- `assessment.updated` - Post-update actions

**Hook Pattern:**
```php
// Core fires hook
$widgets = PluginManager::fireHook('dashboard.widgets', [
    'user_id' => $_SESSION['user_id'],
    'context' => 'admin_dashboard'
]);

// Plugin responds
public function renderWidget(array $context): array {
    return [
        'id' => 'my-widget',
        'title' => 'My Widget',
        'content' => '<p>Widget content</p>'
    ];
}
```

### 6. Plugin Manifest Schema (`plugin.json`)

**Required Fields:**
```json
{
    "id": "my-plugin",
    "name": "My Plugin",
    "version": "1.0.0",
    "description": "Plugin description",
    "author": "Author Name",
    "type": "dashboard|report|theme|export|connector",
    "requires": {
        "slocloud": ">=1.0.0",
        "php": ">=8.1"
    },
    "hooks": {
        "dashboard.widgets": "renderWidget"
    },
    "routes": [
        {
            "path": "/plugins/my-plugin/settings",
            "handler": "settings.php",
            "permission": "admin"
        }
    ],
    "permissions": [
        "my-plugin.admin",
        "my-plugin.view"
    ]
}
```

### 7. Security Considerations

- Validate plugin manifests before loading
- Sanitize plugin IDs (alphanumeric + hyphens only)
- Check file permissions before activation
- Sandbox plugin execution (catch exceptions)
- Log all plugin lifecycle events
- Verify plugin signatures (future enhancement)
- Restrict plugin file access to designated directories

## Implementation Priority

1. **Phase 1 - Foundation:**
   - Plugin base class
   - Plugin manager (discovery, activation)
   - Database schema
   - Basic hook system

2. **Phase 2 - Admin UI:**
   - Plugin listing page
   - Activate/deactivate functionality
   - Plugin settings interface

3. **Phase 3 - Advanced Features:**
   - Dependency management
   - Plugin updates
   - Hook documentation
   - Developer tools

4. **Phase 4 - Marketplace Integration:**
   - Browse marketplace plugins
   - One-click install from marketplace
   - Automatic updates
   - Plugin ratings/reviews
   - Security verification (signatures)

## Marketplace Integration (Phase 4)

### Marketplace API

**Endpoints needed:**
- `GET /api/plugins` - Browse available plugins
- `GET /api/plugins/{id}` - Get plugin details
- `GET /api/plugins/{id}/download` - Download plugin package
- `GET /api/plugins/{id}/versions` - Get available versions
- `POST /api/plugins/{id}/install` - Track installations
- `GET /api/plugins/search?q={query}` - Search plugins

### Plugin Manager Marketplace Features

**Browse & Install:**
```php
class PluginManager {
    public function browseMarketplace(array $filters = []): array {
        // Fetch from marketplace API
        $url = MARKETPLACE_URL . '/api/plugins';
        $response = file_get_contents($url);
        return json_decode($response, true);
    }
    
    public function installFromMarketplace(string $pluginId): bool {
        // 1. Download plugin package from marketplace
        // 2. Verify signature/checksum
        // 3. Extract to plugins/local/{plugin-id}/
        // 4. Run activation
        // 5. Track installation stats
    }
    
    public function checkForUpdates(): array {
        // Compare installed versions with marketplace versions
        $updates = [];
        foreach ($this->getActivePlugins() as $plugin) {
            $latest = $this->getLatestVersion($plugin['id']);
            if (version_compare($plugin['version'], $latest, '<')) {
                $updates[] = [
                    'id' => $plugin['id'],
                    'current' => $plugin['version'],
                    'latest' => $latest
                ];
            }
        }
        return $updates;
    }
    
    public function updatePlugin(string $pluginId): bool {
        // 1. Deactivate plugin
        // 2. Backup current version
        // 3. Download new version
        // 4. Extract and replace
        // 5. Run upgrade routine if exists
        // 6. Reactivate plugin
    }
}
```

### Admin UI for Marketplace

**Dashboard/Plugins page tabs:**
- **Installed** - Currently installed plugins
- **Marketplace** - Browse available plugins
- **Updates** - Plugins with available updates

**Marketplace Browse UI:**
```php
// dashboard/plugins-marketplace.php
- Search bar
- Category filters (connector, report, widget, theme)
- Plugin cards with:
  - Name, icon, description
  - Author, ratings, download count
  - "Install" button
  - "Details" link (opens modal with full info)
```

### Security Considerations

**Plugin Verification:**
- Digital signatures for marketplace plugins
- Checksum verification on download
- Whitelist of trusted authors
- Code scanning for known vulnerabilities
- User reviews and ratings

**Verification Flow:**
```php
public function verifyPluginPackage(string $package, string $signature): bool {
    // 1. Verify digital signature
    $publicKey = file_get_contents(MARKETPLACE_CERT);
    $verified = openssl_verify($package, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256);
    
    // 2. Verify checksum
    $expectedChecksum = $this->getMarketplaceChecksum($pluginId);
    $actualChecksum = hash('sha256', $package);
    
    if ($verified !== 1 || $expectedChecksum !== $actualChecksum) {
        throw new SecurityException('Plugin verification failed');
    }
    
    return true;
}
```

### Marketplace Database Schema

**Table: `plugin_marketplace_cache`**
```sql
CREATE TABLE plugin_marketplace_cache (
    cache_pk INT AUTO_INCREMENT PRIMARY KEY,
    plugin_id VARCHAR(100) NOT NULL UNIQUE,
    plugin_data JSON,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_updated (last_updated)
);
```

### Configuration for Marketplace

**In `config.yaml`:**
```yaml
plugins:
  marketplace:
    enabled: true
    url: "https://marketplace.slocloud-slo.org"
    api_key: ""  # Optional, for authenticated institutions
    auto_update_check: true
    update_frequency: "daily"  # daily, weekly, manual
```

### Marketplace Contribution

**For Plugin Developers:**
- Submit plugin to marketplace
- Automated validation checks
- Documentation requirements
- Semantic versioning enforcement
- Changelog tracking

**Submission Requirements:**
- Complete `plugin.json` manifest
- README.md with usage instructions
- LICENSE file (GPL, MIT, etc.)
- Test suite passing
- Security scan passing
- Code style compliance (PSR-12)

### Revenue Model (Optional)

**Free Marketplace:**
- All plugins free
- Community-driven
- Open submissions with moderation

**Freemium Option (Future):**
- Free plugins from community
- Premium/paid plugins from vendors
- Revenue sharing model
- Support tiers for enterprises

### Notes on Marketplace

- **Phase 4 feature** - Ship core functionality first
- **Community-driven initially** - GitHub-based submissions before building full marketplace
- **Simple start:** ZIP file downloads from GitHub releases
- **Evolve:** Full marketplace later if adoption warrants it
- **Consider:** WordPress plugin directory as model (simple, effective)
- **Don't overbuild:** Start with manual plugin installation, add marketplace when there are 10+ quality plugins available

## Testing Requirements
plugins/local/
- Unit tests for PluginManager class
- Integration tests for plugin lifecycle
- Security tests for malicious plugins
- UI tests for admin interface
- Test plugins for each plugin type

## Documentation Needed

- Plugin development guide (already exists in `docs/implementation/PLUGIN_GUIDE.md`)
- Hook reference documentation
- API documentation for Plugin base class
- Example plugins for each type
- Security best practices for plugin authors

## Plugin Types and Managers

### 1. Dashboard Widget Plugins

**Purpose:** Add custom widgets/cards to admin dashboard

**Manager:** `Core/WidgetManager.php`
- Register widget providers
- Render widgets for current user context
- Handle widget ordering/positioning
- Apply permission checks

**Sample Plugin:** `plugins/local/sample-widget/`
```
sample-widget/
├── plugin.json
├── SampleWidget.php
└── assets/
    └── widget.css
```

**Sample Code:**
```php
// SampleWidget.php
<?php
namespace SLOCloud\Plugins\SampleWidget;

class SampleWidget extends \SLOCloud\Core\Plugin {
    public function activate(): bool {
        // No setup needed for simple widget
        return true;
    }
    
    public function deactivate(): bool {
        return true;
    }
    
    public function uninstall(): bool {
        return true;
    }
    
    public function renderWidget(array $context): array {
        require_once APP_ROOT . '/Core/Database.php';
        $db = new \SLOCloud\Core\Database();
        
        // Query data
        $stmt = $db->prepare("SELECT COUNT(*) FROM assessments WHERE assessment_date > ?");
        $stmt->execute([date('Y-m-d', strtotime('-30 days'))]);
        $count = $stmt->fetchColumn();
        
        return [
            'id' => 'sample-widget',
            'title' => 'Recent Assessments',
            'icon' => 'fa-chart-line',
            'content' => "<div class='text-center'><h2>$count</h2><p>in last 30 days</p></div>",
            'order' => 10
        ];
    }
}
```

### 2. Report Plugins

**Purpose:** Generate custom assessment reports and analytics

**Manager:** `Core/ReportManager.php`
- Register report types
- Provide report generation interface
- Handle export formats
- Apply data permissions

**Sample Plugin:** `plugins/local/sample-report/`
```
plugins/local/sample-report/
├── plugin.json
├── SampleReport.php
├── template.php
└── assets/
    ├── report.css
    └── report.js
```

**Sample Code:**
```php
// SampleReport.php
<?php
namespace SLOCloud\Plugins\SampleReport;

class SampleReport extends \SLOCloud\Core\Plugin {
    public function activate(): bool {
        return true;
    }
    
    public function deactivate(): bool {
        return true;
    }
    
    public function uninstall(): bool {
        return true;
    }
    
    public function generateReport(array $params): array {
        $db = new \SLOCloud\Core\Database();
        
        // Generate report data based on params
        $stmt = $db->prepare("
            SELECT 
                slo.outcome_code,
                slo.outcome_title,
                AVG(a.score) as avg_score,
                COUNT(a.assessments_pk) as total_assessments
            FROM student_learning_outcomes slo
            LEFT JOIN assessments a ON slo.slos_pk = a.slo_fk
            WHERE slo.slo_set_fk = ?
            GROUP BY slo.slos_pk
            ORDER BY slo.sequence_num
        ");
        $stmt->execute([$params['slo_set_fk']]);
        
        return [
            'title' => 'SLO Achievement Report',
            'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
}
```

### 3. Theme Plugins

**Purpose:** Provide alternative visual styling/branding

**Manager:** `Core/ThemeManager.php`
- Register available themes
- Apply theme CSS overrides
- Handle theme switching
- Provide theme preview

**Sample Plugin:** `plugins/local/sample-theme/`
```
plugins/local/sample-theme/
├── plugin.json
├── SampleTheme.php
└── assets/
    ├── theme.css
    ├── logo.png
    └── preview.png
```

**Sample Code:**
```php
// SampleTheme.php
<?php
namespace SLOCloud\Plugins\SampleTheme;

class SampleTheme extends \SLOCloud\Core\Plugin {
    public function activate(): bool {
        // Copy theme assets to public directory
        $sourceDir = __DIR__ . '/assets';
        $targetDir = APP_ROOT . '/assets/themes/sample-theme';
        
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        // Copy CSS file
        copy("$sourceDir/theme.css", "$targetDir/theme.css");
        copy("$sourceDir/logo.png", "$targetDir/logo.png");
        
        return true;
    }
    
    public function deactivate(): bool {
        return true;
    }
    
    public function uninstall(): bool {
        // Remove theme assets
        $targetDir = APP_ROOT . '/assets/themes/sample-theme';
        if (is_dir($targetDir)) {
            array_map('unlink', glob("$targetDir/*"));
            rmdir($targetDir);
        }
        return true;
    }
    
    public function getThemeInfo(): array {
        return [
            'id' => 'sample-theme',
            'name' => 'Sample Theme',
            'css_path' => BASE_URL . 'assets/themes/sample-theme/theme.css',
            'preview_image' => BASE_URL . 'assets/themes/sample-theme/preview.png'
        ];
    }
}
```

### 4. Export Format Plugins

**Purpose:** Add new data export formats (CSV, Excel, PDF, JSON, etc.)

**Manager:** `Core/ExportManager.php`
- Register export formats
- Transform data to format
- Set HTTP headers for download
- Handle large datasets

**Sample Plugin:** `plugins/local/sample-export/`
```
plugins/local/sample-export/
├── plugin.json
└── SampleExport.php
```

**Sample Code:**
```php
// SampleExport.php
<?php
namespace SLOCloud\Plugins\SampleExport;

class SampleExport extends \SLOCloud\Core\Plugin {
    public function activate(): bool {
        return true;
    }
    
    public function deactivate(): bool {
        return true;
    }
    
    public function uninstall(): bool {
        return true;
    }
    
    public function exportData(array $data, array $options = []): string {
        // Example: JSON export with pretty printing
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Set download headers
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="export-' . date('Y-m-d') . '.json"');
        
        return $json;
    }
    
    public function getFormatInfo(): array {
        return [
            'id' => 'json',
            'name' => 'JSON Format',
            'mime_type' => 'application/json',
            'file_extension' => 'json',
            'supports_streaming' => false
        ];
    }
}
```

### 5. Data Connector Plugins

**Purpose:** Sync data with external systems (SIS, LMS, HR systems, data warehouses)

**Manager:** `Core/ConnectorManager.php`
- Schedule sync operations
- Track sync status and errors
- Maintain ID mappings
- Handle conflict resolution

**Sample Plugin:** `plugins/local/sample-connector/`
```
plugins/local/sample-connector/
├── plugin.json
├── SampleConnector.php
├── sync.php (manual sync handler)
└── mapping_schema.sql
```

**Sample Code:**
```php
// SampleConnector.php
<?php
namespace SLOCloud\Plugins\SampleConnector;

class SampleConnector extends \SLOCloud\Core\Plugin {
    public function activate(): bool {
        // Create mapping table
        $db = new \SLOCloud\Core\Database();
        $db->exec("
            CREATE TABLE IF NOT EXISTS sample_connector_mappings (
                mapping_pk INT AUTO_INCREMENT PRIMARY KEY,
                internal_id INT NOT NULL,
                external_id VARCHAR(100) NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                sync_status VARCHAR(20) DEFAULT 'synced',
                error_message TEXT,
                UNIQUE KEY unique_mapping (entity_type, external_id),
                INDEX idx_internal (entity_type, internal_id)
            )
        ");
        return true;
    }
    
    public function deactivate(): bool {
        return true;
    }
    
    public function uninstall(): bool {
        // Drop mapping table
        $db = new \SLOCloud\Core\Database();
        $db->exec("DROP TABLE IF EXISTS sample_connector_mappings");
        return true;
    }
    
    public function syncStudents(): array {
        $db = new \SLOCloud\Core\Database();
        $results = ['imported' => 0, 'updated' => 0, 'errors' => []];
        
        // Fetch from external API (example)
        $externalStudents = $this->fetchExternalStudents();
        
        foreach ($externalStudents as $extStudent) {
            try {
                // Check if student exists in mapping
                $stmt = $db->prepare("
                    SELECT internal_id FROM sample_connector_mappings 
                    WHERE entity_type = 'student' AND external_id = ?
                ");
                $stmt->execute([$extStudent['id']]);
                $mapping = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($mapping) {
                    // Update existing student
                    $stmt = $db->prepare("
                        UPDATE students 
                        SET first_name = ?, last_name = ?, email = ?
                        WHERE students_pk = ?
                    ");
                    $stmt->execute([
                        $extStudent['first_name'],
                        $extStudent['last_name'],
                        $extStudent['email'],
                        $mapping['internal_id']
                    ]);
                    $results['updated']++;
                } else {
                    // Create new student
                    $stmt = $db->prepare("
                        INSERT INTO students (student_id, first_name, last_name, email, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $extStudent['student_id'],
                        $extStudent['first_name'],
                        $extStudent['last_name'],
                        $extStudent['email']
                    ]);
                    $internalId = $db->lastInsertId();
                    
                    // Create mapping
                    $stmt = $db->prepare("
                        INSERT INTO sample_connector_mappings 
                        (internal_id, external_id, entity_type) 
                        VALUES (?, ?, 'student')
                    ");
                    $stmt->execute([$internalId, $extStudent['id']]);
                    $results['imported']++;
                }
            } catch (\Exception $e) {
                $results['errors'][] = "Failed to sync student {$extStudent['id']}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    private function fetchExternalStudents(): array {
        // Example: Fetch from external API
        // In real implementation, use cURL or Guzzle
        return [
            [
                'id' => 'EXT123',
                'student_id' => 'S001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'jdoe@example.edu'
            ]
        ];
    }
}
```

### 6. Authentication Provider Plugins

**Purpose:** Add custom authentication methods (LDAP, OAuth, custom SSO)

**Manager:** `Core/AuthManager.php`
- Register auth providers
- Handle authentication flow
- Map external user data to internal users
- Support multiple concurrent auth methods

**Sample Plugin:** `plugins/local/sample-auth/`
```
plugins/local/sample-auth/
├── plugin.json
├── SampleAuth.php
└── login.php (custom login form)
```

**Sample Code:**
```php
// SampleAuth.php
<?php
namespace SLOCloud\Plugins\SampleAuth;

class SampleAuth extends \SLOCloud\Core\Plugin {
    public function activate(): bool {
        return true;
    }
    
    public function deactivate(): bool {
        return true;
    }
    
    public function uninstall(): bool {
        return true;
    }
    
    public function authenticate(string $username, string $password): ?array {
        // Example: Custom authentication logic
        // Could be LDAP, OAuth, API call, etc.
        
        if ($this->validateCredentials($username, $password)) {
            // Return user data on success
            return [
                'user_id' => $username,
                'email' => $username . '@example.edu',
                'first_name' => 'User',
                'last_name' => 'Name',
                'roles' => ['instructor']
            ];
        }
        
        return null; // Authentication failed
    }
    
    private function validateCredentials(string $username, string $password): bool {
        // Implement custom validation
        // Example: LDAP bind, API call, etc.
        return false;
    }
}
```

## Sample Plugin Manifest Templates

### Minimal Plugin (Widget)
```json
{
    "id": "sample-widget",
    "name": "Sample Widget",
    "version": "1.0.0",
    "description": "A sample dashboard widget",
    "author": "Your Name",
    "type": "dashboard",
    "requires": {
        "slocloud": ">=1.0.0",
        "php": ">=8.1"
    },
    "hooks": {
        "dashboard.widgets": "renderWidget"
    }
}
```

### Full-Featured Plugin (Data Connector)
```json
{
    "id": "sample-connector",
    "name": "Sample Data Connector",
    "version": "1.2.0",
    "description": "Syncs student data from external system",
    "author": "Institution Name",
    "type": "connector",
    "requires": {
        "slocloud": ">=1.0.0",
        "php": ">=8.1",
        "extensions": ["curl", "json"]
    },
    "hooks": {
        "data.sync": "syncStudents"
    },
    "routes": [
        {
            "path": "/plugins/sample-connector/sync",
            "handler": "sync.php",
            "permission": "admin"
        },
        {
            "path": "/plugins/sample-connector/settings",
            "handler": "settings.php",
            "permission": "sample-connector.admin"
        }
    ],
    "permissions": [
        "sample-connector.admin",
        "sample-connector.sync",
        "sample-connector.view"
    ],
    "settings": {
        "api_endpoint": "",
        "api_key": "",
        "sync_frequency": "daily",
        "conflict_resolution": "external_wins"
    }
}
```

## Notes

- Follow "simplicity over flexibility" principle - don't over-engineer
- Keep plugin architecture simple and approachable
- Prioritize discoverability and ease of use
- Plugins should work without requiring framework knowledge
- Direct database access is fine - no ORM overhead needed
- Each plugin type should have a working sample in `plugins/samples/`
- Plugin managers should be implementable incrementally (ship widget support first, add others later)
