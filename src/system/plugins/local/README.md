# Local Plugins

This directory contains locally installed plugins for SLOCloud.

## Installed Plugins

### Theme Plugins

| Plugin ID | Name | Layouts | Description |
|-----------|------|---------|-------------|
| `theme-bootstrap5` | Bootstrap 5 Theme | simple, navbar, fluid | Clean, minimal vanilla Bootstrap 5 theme |
| `theme-adminlte` | AdminLTE 4 Theme | admin, simple, embedded | Professional admin theme with sidebar |
| `theme-metis` | Metis Dashboard Theme | dashboard, compact, simple | Modern material design dashboard |

## Naming Convention

All plugins follow the naming pattern: `{type}-{name}`

**Examples:**
- `theme-adminlte` - AdminLTE theme plugin
- `connector-banner` - Banner SIS connector plugin
- `widget-recent-assessments` - Dashboard widget plugin
- `report-program-assessment` - Custom report plugin

## Plugin Structure

Each plugin directory contains:
- `plugin.yaml` - Plugin manifest and configuration
- Type-specific files and layouts
- `README.md` - Plugin documentation
- `assets/` - CSS, JavaScript, images (optional)

## Active Theme

**Current:** `theme-adminlte` (configured via `theme.active` in `config.yaml`)

When PluginManager is implemented, active themes will be stored in the database and switchable through the admin UI.

## Documentation

- [THEMES.md](../../../../docs/implementation/THEMES.md) - Theme plugin development guide
- [PLUGIN.md](../../../../docs/concepts/PLUGIN.md) - Plugin architecture concepts  
- [PLUGIN_GUIDE.md](../../../../docs/implementation/PLUGIN_GUIDE.md) - General plugin development

## Testing

To test your theme plugin:

1. Set as active theme in `src/config/config.yaml`:
   ```yaml
   theme:
     active_theme: 'your-theme-plugin-id'
   ```

2. Access any application page to see your theme in action
