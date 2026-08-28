# SLOCloud Logs Directory

This directory contains application log files organized by severity level and date.

## Log Files

Logs are named: `{level}-YYYY-MM-DD.log`

### Log Levels

- **debug-*.log** - Debug information (development only)
- **info-*.log** - Informational messages (user actions, operations)
- **warning-*.log** - Warnings and deprecated usage
- **error-*.log** - Runtime errors
- **critical-*.log** - Critical system failures

### PHP Error Log

- **php_errors.log** - PHP runtime errors, warnings, and notices
  - Controlled by `app.php_error_mode` setting in config.yaml
  - Modes: `off` (disabled), `logged` (file only), `onscreen` (display + log), `system` (php.ini defaults)
  - Production recommendation: Set to `logged` for security

## Log Format

```
[YYYY-MM-DD HH:MM:SS] LEVEL [User: username] [IP: xxx.xxx.xxx.xxx] Message {"context":"data"}
```

Example:
```
[2026-02-14 14:30:45] ERROR [User: admin001] [IP: 192.168.1.100] Database query failed {"table":"students","query":"SELECT * FROM..."}
```

## Permissions

This directory should be:
- Writable by web server user (www-data, apache, nginx)
- Not accessible via web browser
- Permissions: 755 (directory), 644 (log files)

## Cleanup

Old log files are automatically removed based on retention policy:

**Manual cleanup**:
```bash
php scripts/cleanup_logs.php --days=30
```

**Automated cleanup (cron)**:
```bash
# Daily at 2 AM
0 2 * * * /usr/bin/php /path/to/slocloud/scripts/cleanup_logs.php --days=30
```

## Retention Policy

- **Development**: 7 days
- **Staging**: 30 days
- **Production**: 90 days

Configured in `config.yml`:
```yaml
logging:
  retention_days: 30
```

## Monitoring

Monitor these logs regularly:

**Daily**:
- `critical-*.log` - Immediate attention required
- `error-*.log` - Check for recurring issues

**Weekly**:
- `warning-*.log` - Review patterns and deprecations
- `info-*.log` - Audit user actions if needed

**Alerts**:
Set up monitoring for:
- Any CRITICAL log entries
- Error rate > 100/hour
- Disk space < 10%

## Security

**Do NOT log**:
- Passwords or password hashes
- API keys or tokens
- Session IDs
- Credit card numbers
- Social Security numbers
- Student PII without necessity (FERPA compliance)

**Do log**:
- Login attempts (success and failure)
- Configuration changes
- Permission changes
- Data exports
- Failed authorization attempts
- System errors

## Troubleshooting

### Logs not being created
1. Check directory permissions: `ls -la logs/`
2. Ensure directory is writable: `chmod 755 logs/`
3. Check disk space: `df -h`
4. Verify logging enabled in config.yml

### Log files too large
1. Check log level (should be INFO or WARNING in production)
2. Reduce retention period
3. Implement log rotation
4. Check for infinite loop errors

### Cannot read logs
1. Check file permissions: `chmod 644 logs/*.log`
2. Ensure user has read access
3. Check file ownership

## Related Documentation

- [SECURITY.md](../docs/concepts/SECURITY.md) - Security requirements
