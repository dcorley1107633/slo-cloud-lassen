# AdminLTE 4 Theme

Professional responsive admin theme with sidebar navigation based on AdminLTE 4.

## Layouts

### Admin Layout
- Full sidebar navigation
- Top navbar with menu toggle
- User panel in sidebar
- Perfect for administration pages
- Responsive with mobile menu

### Simple Layout
- Top navbar only
- No sidebar
- Clean and minimal
- Good for public pages or simple forms

### Embedded Layout
- Minimal layout for LTI/iframe embedding
- No navigation elements
- Transparent background option
- Optimized for embedding in LMS

## Usage

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'      => 'admin', // or 'embedded'
    'pageTitle'   => 'Dashboard',
    'currentPage' => 'admin_dashboard',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Dashboard'],
    ],
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<!-- Your page content here -->

<?php $theme->showFooter($context); ?>
```

## Sidebar Active States

For admin layout, set `$currentPage` to highlight the active menu item:
- `admin_dashboard` - Dashboard
- `admin_institution` - Institution
- `admin_outcomes` - Outcome Hierarchy
- `admin_config` - Configuration
- `admin_lti` - LTI Integration

## Customization

Add custom styles or scripts:

```php
<?php
$customStyles = '<link rel="stylesheet" href="custom.css">';
$customScripts = '<script src="custom.js"></script>';
?>
```

## Features

- AdminLTE 4 framework
- Bootstrap 5 foundation
- Font Awesome icons
- Bootstrap Icons
- jQuery included
- Responsive sidebar
- Mobile-friendly
- Dark sidebar theme
