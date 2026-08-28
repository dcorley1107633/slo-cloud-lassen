# Sessions Directory

This directory stores PHP session files for SLOCloud.

## Purpose

PHP session data is stored here instead of the system temp directory for:
- **Windows compatibility**: Avoids `C:\Windows\TEMP` permission issues
- **Security**: Sessions isolated from other applications
- **Portability**: Works consistently across different server configurations

## Maintenance

Session files are automatically cleaned up by PHP's garbage collection based on:
- `session.gc_maxlifetime` (default: 1440 seconds / 24 minutes)
- `session.gc_probability` and `session.gc_divisor` settings

## Security

- Session files contain serialized PHP data only (no passwords)
- Stored outside web root for security (if `src/` is web root, move this up one level)
- Automatic cleanup prevents disk space issues
- Directory permissions should be 755 (readable/executable by web server)

## Troubleshooting

If you see "Failed to write session data" errors:
1. Verify directory exists and is writable by web server user
2. Check disk space availability
3. Verify PHP has `session.save_path` configured correctly

To manually clear all sessions:
```bash
rm -rf sessions/*
# Or on Windows:
del /Q sessions\*
```
