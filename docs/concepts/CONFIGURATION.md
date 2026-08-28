# Configuration Guide

## Overview

SLOCloud uses a YAML-based configuration file (`config.yaml`) for all system settings. This file is generated during the web-based setup wizard and should never be committed to version control.

**Location:** `src/config/config.yaml`

## Configuration Structure

Configuration is loaded from `config.yaml` and cached in the session for performance. The cache is automatically invalidated when the config file is modified.

**Performance Optimization:**
- First request: Parse YAML, decrypt sensitive values, merge with template defaults, cache in session
- Subsequent requests: Load from session cache (no file I/O or parsing)
- Cache invalidation: Automatic when `config.yaml` modification time changes
- Session storage: Decrypted values stored in session (session is already secured with HttpOnly, Secure flags, and IP validation)

**Template Merging (Backward Compatibility):**
- Config class automatically loads `config.yaml.template` on every config load
- Template default values are merged with your `config.yaml`
- Your config values always take precedence over template defaults
- **Benefit:** New config options added in updates are automatically available with sensible defaults, even if not in your deployed config.yaml
- **Result:** Old config files continue to work after upgrades without manual updates

### Application Settings

```yaml
app:
  name: Springfield University Assessment
  timezone: America/Los_Angeles
  base_url: /beta/
  debug_mode: false
  php_error_mode: logged
  maintenance_mode: false
  maintenance_message: "SLOCloud is currently undergoing maintenance. Please check back later."
```

- **name**: Display name for your installation (used in page titles, emails, etc.)
- **timezone**: PHP timezone identifier for date/time formatting
- **base_url**: Installation path (/ for root, /subdirectory/ for subdirectory installs)
- **debug_mode**: Enable debug features and verbose logging (true/false). Set to false in production.
- **php_error_mode**: Controls PHP error display and logging behavior:
  - `off` - Disable all error reporting (not recommended)
  - `logged` - Log errors to `logs/php_errors.log` only, no screen display (recommended for production)
  - `onscreen` - Display errors on screen AND log to file (development only - UNSAFE for production)
  - `system` - Use php.ini defaults (don't override system configuration)
- **maintenance_mode**: Enable/disable maintenance mode (true/false). When enabled, only admin users can access the system.
- **maintenance_message**: Message displayed to non-admin users when maintenance mode is active

### Database Settings

```yaml
database:
  host: localhost
  port: 3306
  name: slocloud
  prefix: msc_
  username: root
  password: secret
  charset: utf8mb4
```

- **host**: Database server address (usually "localhost")
- **port**: Database port (MySQL: 3306, SQL Server: 1433)
- **name**: Database name (created automatically during setup)
- **username**: Database username
- **password**: Database password (can be encrypted - see Security Configuration)
- **charset**: Character set for MySQL (utf8mb4 recommended). SQL Server uses NVARCHAR with UTF-16.

### Email Settings

```yaml
email:
  method: smtp
  from_email: noreply@university.edu
  from_name: Springfield University Assessment
  smtp_host: smtp.gmail.com
  smtp_port: 587
  smtp_username: user@gmail.com
  smtp_password: app_password_here
  smtp_encryption: tls
```

- **method**: Email delivery method
  - `disabled`: No email notifications
  - `server`: Use PHP mail() function
  - `smtp`: Use SMTP server (recommended)
- **from_email**: Sender email address for outgoing notifications
- **from_name**: Display name for outgoing emails
- **smtp_host**: SMTP server address (required if method = smtp)
- **smtp_port**: SMTP port (587 for TLS, 465 for SSL)
- **smtp_username**: SMTP authentication username
- **smtp_password**: SMTP authentication password (can be encrypted - see Security Configuration)
- **smtp_encryption**: Encryption method (tls, ssl, or none)

#### Email Method Comparison

| Method | Pros | Cons |
|--------|------|------|
| **disabled** | No configuration needed | No notifications |
| **server** | Simple, uses server mail | May be blocked by spam filters, limited reliability |
| **smtp** | Reliable delivery, better reputation | Requires SMTP credentials |

#### Gmail SMTP Setup

For Gmail, use these settings:
- Host: `smtp.gmail.com`
- Port: `587`
- Encryption: `tls`
- Username: Your full Gmail address
- Password: [App Password](https://support.google.com/accounts/answer/185833) (not your Gmail password)

## Configuration Constants

After loading `config.yaml`, these constants are available throughout the application:

### Core Constants

```php
BASE_URL            // Installation path (e.g., '/beta/')
BASE_PATH           // Filesystem path to src/ directory
SITE_NAME           // Display name for the installation
CONFIG_PATH         // Path to config.yaml
APP_ROOT            // Same as BASE_PATH
MAINTENANCE_MODE    // Boolean for maintenance mode status
```

### Email Constants

```php
EMAIL_METHOD      // disabled|server|smtp
EMAIL_FROM_EMAIL  // Sender email address
EMAIL_FROM_NAME   // Sender display name
```

## Accessing Configuration Values

### Using Constants (Recommended)

```php
// Display site name
echo htmlspecialchars(SITE_NAME);

// Check if email is enabled
if (EMAIL_METHOD !== 'disabled') {
    // Send notification
}

// Check maintenance mode
if (MAINTENANCE_MODE) {
    // System is in maintenance mode
    // Only admin users should have access
}
```

### Using Config Object

```php
// Get Config instance
$config = \SLOCloud\Core\Config::getInstance(CONFIG_PATH);

// Read any config value
$timezone = $config->get('app.timezone', 'America/Los_Angeles');
$smtpHost = $config->get('email.smtp_host', '');

// Check if key exists
if ($config->has('email.smtp_host')) {
    // SMTP is configured
}
```

## Security Considerations

### Protecting config.yaml

The setup wizard automatically creates protection files:

**`.htaccess`** (Apache):
```apache
Deny from all
```

**`index.php`** (fallback):
```php
<?php
http_response_code(403);
exit('Forbidden');
```

### Version Control

**NEVER commit `config.yaml` to version control.** Add to `.gitignore`:

```gitignore
src/config/config.yaml
```

Provide a template instead:

```yaml
# config.yaml.example
# Copy to config.yaml and fill in your values

database:
  host: localhost
  port: 3306
  name: slocloud
  prefix: 
  username: your_username
  password: your_password
  charset: utf8mb4

app:
  name: Your Institution Name
  timezone: America/Los_Angeles
  base_url: /

email:
  method: disabled
  from_email: 
  from_name: 
```

## Reconfiguration

To change configuration after initial setup:

1. **Via Config File**: Edit `src/config/config.yaml` directly
2. **Via Reinstall**: Delete `config.yaml` and run setup wizard again (WARNING: This does NOT drop existing database tables)

For database changes, you may need to:
- Run migrations if schema changes
- Export/import data if changing database

## Security Configuration

### Encryption

SLOCloud uses AES-256-GCM encryption for sensitive data (LTI secrets, database passwords, API keys, etc.).

#### Encryption Key Sources

You have **four options** for managing encryption keys. Choose based on your security requirements:

##### 1. Separate Keys File (RECOMMENDED for Production)

Store keys in a separate PHP file **outside the web root** for maximum security.

```yaml
security:
  encryption_key_source: file
  keys_file_path: /home/username/slocloud-keys/keys.php
```

Create the keys file:
```php
<?php
// /home/username/slocloud-keys/keys.php
// Set permissions: chmod 600 keys.php
return [
    'encryption_key' => '64-char-hex-key-here',
    'student_encryption_key' => '64-char-hex-key-here',  // MUST be different
];
```

**Advantages:**
- Keys separated from application config
- File outside web root (not accessible via HTTP)
- Protected by file permissions (chmod 600)
- Can be excluded from version control
- Easy to rotate keys without touching main config

##### 2. Environment Variables

Store keys in server environment (`.bashrc`, Apache/Nginx config, or hosting control panel).

```yaml
security:
  encryption_key_source: env
```

Set environment variables:
```bash
export SLOCloud_ENCRYPTION_KEY="64-char-hex-key"
export SLOCloud_STUDENT_ENCRYPTION_KEY="64-char-hex-key"
```

**Advantages:**
- Keys never stored in files
- Managed via server configuration
- Works well with containerization (Docker, Kubernetes)
- Easy integration with CI/CD pipelines

##### 3. Key Protection Service (KPS) - Enterprise

Fetch encryption key from external key management service. Best for enterprise deployments with centralized key management.

```yaml
security:
  encryption_key_source: kps
  kps:
    endpoint: "https://kps.example.com/api/keys"
    token: "bearer_token_here"
    key_id: "slocloud-encryption-key"
    local_decryption_key: ""  # Optional: for encrypted KPS responses
```

**KPS Requirements:**
- Endpoint must return JSON: `{"key": "64-hex-key-or-encrypted-value"}`
- Authentication via Bearer token in Authorization header
- 5-second timeout on requests
- Final key must be 64 hex characters after any decryption
- Separates encryption key storage from application config

**KPS Response Options:**
1. **Plaintext key** (simple KPS): Return raw 64-hex key directly
2. **Encrypted key** (secure KPS): Return encrypted key, configure `local_decryption_key` to decrypt

**Note:** KPS currently supports only the main encryption key. Student encryption key falls back to static config.

##### 4. Static in config.yaml (Development Only - NOT SECURE)

**⚠️ NOT recommended for production.** Keys stored directly in `config.yaml`:

```yaml
security:
  encryption_key_source: static
  encryption_key: "64-char-hex-key"
  student_encryption_key: "64-char-hex-key"
```

**Use only for:**
- Local development
- Testing environments
- Non-production deployments

**Generate Encryption Key:**
```bash
# Linux/Mac
openssl rand -hex 32

# PowerShell
-join ((48..57) + (97..102) | Get-Random -Count 64 | ForEach-Object {[char]$_})

# PHP (any platform)
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

**Critical:** 
- Generate TWO DIFFERENT keys (one for each purpose)
- Keep encryption keys secure (600 permissions on files)
- Never commit keys to version control
- Changing a key will invalidate all data encrypted with that key
- Each key must be exactly 64 hexadecimal characters (32 bytes)

#### Migration Guide: From Static to Secure Keys

If you already installed SLOCloud with keys in `config.yaml`, here's how to migrate:

**Step 1: Copy your existing keys**
```bash
# View current keys in config.yaml
grep -A 2 "encryption_key:" /path/to/src/config/config.yaml
```

**Step 2: Create secure keys file**
```bash
# Create directory outside web root
mkdir -p /home/username/slocloud-keys
chmod 700 /home/username/slocloud-keys

# Create keys file
cat > /home/username/slocloud-keys/keys.php << 'EOF'
<?php
return [
    'encryption_key' => 'PASTE_YOUR_CURRENT_KEY_HERE',
    'student_encryption_key' => 'PASTE_YOUR_CURRENT_KEY_HERE',
];
EOF

# Secure the file
chmod 600 /home/username/slocloud-keys/keys.php
```

**Step 3: Update config.yaml**
```yaml
security:
  encryption_key_source: file  # Changed from 'static'
  keys_file_path: /home/username/slocloud-keys/keys.php
  # Remove these lines:
  # encryption_key: ...
  # student_encryption_key: ...
```

**Step 4: Test**
- Access your SLOCloud installation
- Verify login works (passwords still decrypt)
- Check that student data loads correctly
- Review logs for any encryption errors

**Step 5: Remove keys from config.yaml**
Once confirmed working, permanently remove the old key values from `config.yaml`.

**Usage in Code:**
```php
use SLOCloud\Core\Encryption;

$encryptionKey = $config->get('security.encryption_key');
$encryption = new Encryption($encryptionKey);

// Encrypt sensitive data
$encrypted = $encryption->encrypt('sensitive_value');

// Decrypt
$decrypted = $encryption->decrypt($encrypted);

// Check if value is already encrypted
if ($encryption->isEncrypted($value)) {
    $decrypted = $encryption->decrypt($value);
}
```

### Encrypting Sensitive Configuration Values

The following configuration values support encryption for enhanced security:
- **database.password**: Database user password
- **email.smtp_password**: SMTP authentication password
- **lti.consumer_secret**: LTI shared secret

**How It Works:**
1. Config class detects encrypted values automatically (via base64 format validation)
2. Auto-decrypts when you call `$config->get('database.password')` or other sensitive fields
3. If plaintext, returns value directly (allows gradual migration)
4. Transparent to application code - just use `$config->get()` normally

**Supported Fields:**
- `database.password`
- `email.smtp_password`
- `lti.consumer_secret`

**Encrypting Values:**

```bash
# Encrypt database password
php -r "
require 'src/system/Core/Encryption.php';
\$enc = new SLOCloud\\Core\\Encryption('YOUR_64_CHAR_HEX_KEY');
echo \$enc->encrypt('your_db_password');
"

# Encrypt SMTP password
php -r "
require 'src/system/Core/Encryption.php';
\$enc = new SLOCloud\\Core\\Encryption('YOUR_64_CHAR_HEX_KEY');
echo \$enc->encrypt('your_smtp_password');
"
```

**Update config.yaml:**
```yaml
database:
  password: "ENC:base64_encrypted_value_here"

email:
  smtp_password: "ENC:base64_encrypted_value_here"

lti:
  consumer_secret: "ENC:base64_encrypted_value_here"
```

**Benefits:**
- Protects credentials if config file is exposed
- Supports mixed plaintext/encrypted values during migration
- Transparent decryption at runtime (Config class handles automatically)
- No code changes needed to use encrypted values

**Usage in Code:**
```php
// Just use $config->get() - decryption happens automatically
$dbPassword = $config->get('database.password');  // Auto-decrypted if encrypted
$smtpPassword = $config->get('email.smtp_password');  // Auto-decrypted if encrypted
$ltiSecret = $config->get('lti.consumer_secret');  // Auto-decrypted if encrypted

// For raw encrypted value (rare use case), use getRaw():
$encryptedValue = $config->getRaw('database.password');
```

**Important:** Treat the encryption key as the master secret. Store it separately from encrypted configuration backups and restrict access to it.

## LTI Integration

### Configuration

**Config Settings:**
```yaml
lti:
  consumer_key: "institution-provisioned-key"
  consumer_secret: "ENC:encrypted_shared_secret"
  launch_url: "https://slo.example.edu/lti/launch.php"
  validate_signatures: true
  nonce_expiration: 3600
```

**Settings:**
- **consumer_key**: Shared key configured in your LMS (Canvas, Blackboard, etc.)
- **consumer_secret**: Shared secret - will be encrypted automatically during setup
- **validate_signatures**: OAuth 1.0 signature validation (always true in production)
- **nonce_expiration**: How long to keep nonces in seconds (prevents replay attacks)

### LMS Configuration

**Canvas:**
1. Navigate to Settings → Apps → View App Configurations
2. Click "+ App"
3. Configuration Type: "Manual Entry"
4. Name: "SLOCloud Assessment"
5. Consumer Key: (from your config.yaml)
6. Shared Secret: (from your config.yaml)
7. Launch URL: `https://yourdomain.edu/lti/launch.php`
8. Privacy: Public (Send name, email, role)
9. Save

**Blackboard Learn:**
1. System Admin → Building Blocks → LTI Tool Providers
2. Register Provider Domain
3. Enter Launch URL and credentials
4. Configure privacy settings

**Moodle:**
1. Site Administration → Plugins → Activity modules → External tool → Manage tools
2. Configure tool manually
3. Enter Tool URL, Consumer key, and Shared secret
4. Set privacy options

### Security Notes

**OAuth 1.0 Signature Validation:**
- Consumer secrets are encrypted with AES-256-GCM at rest
- OAuth signatures are validated against `lti.allowed_signature_methods`; HMAC-SHA256 is preferred, while HMAC-SHA1 is the documented LTI 1.1 compatibility option
- Nonces stored to prevent replay attacks
- Timestamps must be within 5 minutes

**Development/Testing:**
```yaml
lti:
  validate_signatures: false  # NEVER use in production!
```

Setting `validate_signatures: false` disables OAuth validation. Use ONLY for local testing.

**Production Checklist:**
- [ ] encryption_key is 64 random hex characters
- [ ] database.password is encrypted (see Encrypting Sensitive Configuration Values)
- [ ] email.smtp_password is encrypted (if using SMTP)
- [ ] consumer_secret is strong and unique
- [ ] validate_signatures: true (never false in production)
- [ ] LMS configured with matching credentials
- [ ] Test with instructor account before students
- [ ] Monitor logs for authentication failures
- [ ] Use maintenance_mode during updates (blocks non-admins)

## Maintenance Mode

SLOCloud includes a maintenance mode feature to restrict access during system updates, database migrations, or scheduled maintenance.

### Enabling Maintenance Mode

**Via Config File:**
```yaml
app:
  maintenance_mode: true
  maintenance_message: "SLOCloud is currently being upgraded. We'll be back at 3:00 PM PST."
```

**Behavior:**
- When enabled, non-admin users see the maintenance message
- Only users in the `users` table (admin accounts) can access the system
- LTI launches check user email against the `users` table
- Dashboard pages check authenticated user status

### Admin Access During Maintenance

Admin users are identified by:
- Active account in the `users` table (`is_active = 1`)
- Matching email address for LTI users
- Authenticated session for dashboard users

**Example LTI Maintenance Check:**
```php
if (MAINTENANCE_MODE) {
    $ltiUserEmail = $_SESSION['lti_user_email'] ?? '';
    $isAdmin = false;
    
    if (!empty($ltiUserEmail)) {
        $adminCheck = $db->query(
            "SELECT users_pk FROM {$dbPrefix}users WHERE email = ? AND is_active = 1 LIMIT 1",
            [$ltiUserEmail],
            's'
        );
        $isAdmin = ($adminCheck->rowCount() > 0);
    }
    
    if (!$isAdmin) {
        // Show maintenance message to non-admin users
    }
}
```

### Customizing Maintenance Message

Provide clear information to users:
- Expected downtime duration
- Reason for maintenance (optional)
- Contact information if urgent access needed
- Specific features affected (if partial outage)

**Example Messages:**
```yaml
# Scheduled maintenance
maintenance_message: "SLOCloud is undergoing scheduled maintenance. Expected completion: 3:00 PM PST. Thank you for your patience."

# Emergency maintenance
maintenance_message: "SLOCloud is temporarily unavailable due to an unexpected issue. Our team is working to restore service. Please check back in 30 minutes."

# Partial maintenance
maintenance_message: "Assessment data entry is temporarily disabled while we upgrade the database. Viewing reports remains available."
```

### Best Practices

1. **Announce in advance** - Email users about scheduled maintenance windows
2. **Enable during off-hours** - Minimize impact on instructors and students
3. **Test first** - Before extensive maintenance, verify in a staging environment
4. **Keep it brief** - Disable maintenance mode as soon as work is complete
5. **Monitor access** - Check logs for admin access during maintenance window

## Troubleshooting

### Config Not Loading

**Symptoms**: HTTP 500 error, "Configuration Error" message

**Solutions**:
1. Check that `config.yaml` exists in `src/config/`
2. Verify file permissions (readable by web server)
3. Check `logs/php_errors.log` for YAML parsing errors
4. Validate YAML syntax (no tabs, proper indentation)

### Email Not Sending

**Check Config**:
```php
echo EMAIL_METHOD; // Should be 'smtp' or 'server', not 'disabled'
echo EMAIL_FROM_EMAIL; // Should have a value
```

**Test SMTP Credentials**:
- Verify host, port, username, password
- Try telnet to SMTP server
- Check for firewall blocking outbound connections
- Review SMTP server logs

### Database Connection Issues

Better approach: Set prefix correctly during initial setup.

## Related Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) - System architecture overview
- [SCHEMA.md](SCHEMA.md) - Database schema with table naming conventions
- [CODE_GUIDE.md](../implementation/CODE_GUIDE.md) - Using config in application code
- [SECURITY.md](SECURITY.md) - Security best practices
