# Configuration Directory

## � TEMPLATE MERGING (BACKWARD COMPATIBILITY)

**SLOCloud automatically merges config.yaml with config.yaml.template on every request:**

- **Template defaults** provide sensible values for new config options
- **Your config.yaml values** always take precedence
- **New features** from updates work immediately (even if not in your config.yaml)
- **Old config files** continue working after upgrades without manual updates

**How it works:**
1. System loads your `config.yaml`
2. System loads `config.yaml.template`
3. Template defaults are merged (your values win if present)
4. Merged config is cached in session for performance

**Example:** When `php_error_mode` was added in a recent update:
- Old config files don't have this field
- Template provides default: `php_error_mode: logged`
- Application works immediately with safe defaults
- No manual config editing required

## �🔐 NEW SECURITY ARCHITECTURE

**SLOCloud now uses a two-file approach for maximum security:**

```
key.yaml (master keys)
    ↓ decrypts
config.yaml (encrypted credentials)
    ↓ cached in
Session (decrypted config - performance)
```

### Why This Approach?

1. **key.yaml** = Master encryption keys (PROTECTED by .htaccess)
2. **config.yaml** = Encrypted credentials (CAN be in version control safely)
3. **Session** = Decrypted cache (already implemented - fast)

**Benefits:**
- config.yaml can be committed to git (encrypted values are safe)
- .htaccess blocks web access to all .yaml files
- Automatic decryption on every request (session cached)
- FERPA compliant with separate student data key

## ⚠️ CRITICAL: Never Commit key.yaml

The `.gitignore` in this directory protects:
- `key.yaml` - actual keys file
- `config.yaml` - could still contain sensitive data
- `.env` files

**Templates ARE in git:**
- `key.yaml.example`
- `config.yaml.template`

## Quick Setup Guide

### 1. Generate Master Keys

```bash
# Generate two unique keys (run command twice)
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 2. Create key.yaml OUTSIDE Web Root

```bash
# Create directory outside web root
mkdir -p /home/username/slocloud-keys
cd /home/username/slocloud-keys

# Copy template and edit
cp /path/to/src/config/key.yaml.example ./key.yaml
nano key.yaml  # Paste your two generated keys

# Secure the file
chmod 600 key.yaml
```

### 3. Configure Path to key.yaml

**Option A: Environment Variable (Recommended)**
```bash
# Add to .bashrc or Apache/Nginx config
export SLOCloud_KEY_PATH=/home/username/slocloud-keys/key.yaml
```

**Option B: Default Locations**  
If SLOCloud_KEY_PATH is not set, SLOCloud searches:
1. Same directory as config.yaml: `src/config/key.yaml`
2. One directory up: `key.yaml` (outside src/)

### 4. Encrypt Sensitive Values in config.yaml

```bash
# Use this script to encrypt values
php -r "
require 'src/system/Core/Encryption.php';
\$key = 'PASTE_YOUR_ENCRYPTION_KEY_FROM_KEY_YAML';
\$enc = new SLOCloud\Core\Encryption(\$key);
echo \$enc->encrypt('your-database-password') . PHP_EOL;
"
```

Then in `config.yaml`:
```yaml
database:
  password: ENC:generated_encrypted_value_here
```

**Fields that support encryption (optional but recommended):**
- `database.password` - Database password
- `email.smtp_password` - SMTP server password

**Fields stored in key.yaml (master keys):**
- `encryption_key` - Master key for decrypting config.yaml values
- `student_encryption_key` - FERPA-compliant key for student PII

**Fields stored in plaintext (for easy copy/paste to external systems):**
- `lti.consumer_key` - LTI OAuth consumer key (copy to LMS)
- `lti.consumer_secret` - LTI OAuth shared secret (copy to LMS)
- `emergency_admin.password` - Break-glass admin password

### 5. Test

Access your SLOCloud installation. Config should decrypt automatically and cache in session.

## Migration from Old Setup

If you installed with the old method (keys in config.yaml):

```bash
# 1. Create key.yaml outside web root
mkdir -p /home/username/slocloud-keys
cd /home/username/slocloud-keys

# 2. Extract your existing keys from config.yaml
grep "encryption_key:" /path/to/src/config/config.yaml

# 3. Create key.yaml with those keys
cat > key.yaml << 'EOF'
encryption_key: PASTE_OLD_KEY_HERE
student_encryption_key: PASTE_OLD_KEY_HERE
EOF

chmod 600 key.yaml

# 4. Set environment variable
export SLOCloud_KEY_PATH=/home/username/slocloud-keys/key.yaml

# 5. Remove keys from config.yaml
nano /path/to/src/config/config.yaml
# Delete the encryption_key and student_encryption_key lines
# under security: section

# 6. Test - application should still work
# Config class has backwards compatibility fallback

# 7. (Optional) Encrypt plaintext passwords in config.yaml
#    Use encryption script above
```

## File Permissions

```bash
chmod 700 /home/username/slocloud-keys/    # Directory
chmod 600 /home/username/slocloud-keys/key.yaml  # Keys file  
chmod 750 /path/to/src/config/           # Config directory
chmod 640 /path/to/src/config/config.yaml  # Config (if has encrypted values)

## Manual Configuration

If you need to manually create or update the configuration:

1. Copy `config.yaml.template` to `config.yaml`
2. Update all placeholders with your actual values
3. Set appropriate file permissions (readable by web server only)

## Theme Configuration

Available themes:
- `theme-default` - Clean Bootstrap 5 theme (system default, minimal)
- `theme-adminlte` - Professional admin dashboard with sidebar (recommended for admin pages)
- `theme-metis` - Modern material design theme

To change the active theme, edit `config.yaml`:

```yaml
theme:
  active_theme: theme-adminlte
```

**Recommendation:** Use `theme-adminlte` for installations that primarily use the administration interface. Use `theme-default` for simple deployments or public-facing pages.

If no theme is specified, `theme-default` is used automatically.

## Debug Mode

Debug mode enables detailed error messages and logging throughout the application:

```yaml
app:
  debug_mode: true  # Set to false in production
```

When enabled:
- Displays detailed error messages
- Logs authentication attempts (emergency admin checks, login details)
- Logs configuration values for troubleshooting

**Security Warning:** Debug mode may log sensitive information. Always set to `false` in production.

## Security

This directory is protected by `.htaccess` (Apache) to prevent direct access.
The `config.yaml` file contains sensitive information (database credentials) and must not be web-accessible.

## Emergency Admin Account (Break Glass)

The configuration includes an emergency admin account for recovery scenarios:

```yaml
emergency_admin:
  enabled: false
  username: ""
  password: ""  # Argon2id hash when explicitly provisioned
```

**What it does:**
- Provides emergency access when database users are locked/deleted
- Uses configuration-backed recovery authentication
- Checked BEFORE normal login attempts
- Uses email format for login form compatibility

**Security Warnings:**
- Plaintext passwords are rejected; store only a password hash
- Has full system admin access (user_id: 0)
- All logins logged with "Emergency admin login used" warning
- **CHANGE DEFAULT CREDENTIALS IMMEDIATELY** after setup

**To disable:**
- Set `enabled: false`, OR
- Remove the entire `emergency_admin` section

**Best practices:**
- Use complex, unique credentials
- Disable when not actively needed
- Re-enable only for emergency recovery
- Monitor logs for emergency admin usage
