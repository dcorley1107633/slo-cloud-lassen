# System Status Monitoring

Health check and monitoring endpoints for SLOCloud application.

## Overview

- **Admin Dashboard:** `/administration/system_status.php` - User-friendly themed interface (requires authentication)
- **JSON API:** `/api/api.php` - Public endpoint for automated monitoring systems

## Files

- **`api.php`** - JSON API endpoint for automated monitoring systems (public access)
- **`index.php`** - Legacy standalone status page (deprecated - use admin dashboard instead)

## API Endpoint

**URL:** `/api/api.php` (Public access)

**Response Format:**
```json
{
    "timestamp": "2026-03-22T14:30:00-05:00",
    "version": "1.0.0-beta",
    "overall_status": "operational",
    "load_time_ms": 45.23,
    "checks": {
        "config": {
            "status": "ok",
            "component": "configuration",
            "message": "Configuration accessible"
        },
        "session": {
            "status": "ok",
            "component": "infrastructure",
            "message": "Session storage operational"
        },
        "config_decrypt": {
            "status": "ok",
            "component": "configuration",
            "message": "Configuration parsed successfully"
        },
        "database_connection": {
            "status": "ok",
            "component": "database",
            "message": "Database connection established"
        },
        "database_query": {
            "status": "ok",
            "component": "database",
            "message": "Database queries functional"
        },
        "filesystem": {
            "status": "ok",
            "component": "infrastructure",
            "message": "File system writable"
        },
        "performance": {
            "status": "ok",
            "component": "application",
            "message": "Response time: 45.23ms (excellent)"
        }
    },
    "_meta": {
        "check_status_levels": ["ok", "warning", "critical"],
        "overall_status_levels": ["operational", "degraded", "down"]
    }
}
```

## Status Values

**Overall Status:**
- `operational` - All systems functioning normally
- `degraded` - Some non-critical issues detected
- `down` - Critical systems unavailable

**Check Status:**
- `ok` - Component functioning normally
- `warning` - Non-critical issue detected
- `critical` - Critical component failure

## Health Checks Performed

1. **config** (configuration) - Configuration file accessibility
2. **session** (infrastructure) - PHP session storage functionality
3. **config_decrypt** (configuration) - Configuration parsing and decryption
4. **database_connection** (database) - Database connectivity test
5. **database_query** (database) - Database query execution test
6. **filesystem** (infrastructure) - File system write permissions
7. **performance** (application) - API response time (< 100ms ok, < 500ms warning, >= 500ms critical)

## Component Categories

- **configuration** - Config file access and parsing
- **database** - Database connectivity and queries
- **infrastructure** - Session storage, filesystem, system resources
- **application** - Application-level metrics (performance, response times)

## Security Considerations

- **No sensitive data exposed** - Error messages are generic
- **No architecture details** - Internal structure not revealed
- **No credentials** - Database passwords and keys never displayed
- **No file paths** - Absolute paths not included in responses

## Usage Examples

### Automated Monitoring (Uptime Robot, Pingdom, etc.)

Monitor the API endpoint:
```
GET https://your-domain.edu/status/api.php
```

Check for `overall_status: "operational"` or HTTP 200 response.

### Command Line

```bash
# Check status
curl https://your-domain.edu/status/api.php

# Parse specific check
curl -s https://your-domain.edu/status/api.php | jq '.checks.database_connection.status'
```

### Human-Readable Dashboard

Visit: `https://your-domain.edu/status/`

Shows themed interface with color-coded status indicators.

## Integration

### Nagios/Icinga

```bash
#!/bin/bash
STATUS=$(curl -s https://your-domain.edu/status/api.php | jq -r '.overall_status')

if [ "$STATUS" == "operational" ]; then
    echo "OK - System operational"
    exit 0
elif [ "$STATUS" == "degraded" ]; then
    echo "WARNING - System degraded"
    exit 1
else
    echo "CRITICAL - System down"
    exit 2
fi
```

### Prometheus

Use `json_exporter` or similar to scrape the API endpoint.

## Performance

- **Fast execution** - Typical response time < 100ms
- **Minimal overhead** - No authentication required
- **Cacheable** - Can be proxied/cached for high traffic

## Maintenance Mode

When `MAINTENANCE_MODE` is enabled in config, the status page still functions to allow administrators to verify system health during maintenance windows.
