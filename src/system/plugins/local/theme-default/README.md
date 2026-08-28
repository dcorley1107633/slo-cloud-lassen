# Default Theme

Clean, minimal theme using Bootstrap 5 framework - the system default.

## Layouts

### Default Layout
- Basic container layout
- Minimal styling
- Used as fallback for other layouts
- Perfect for simple pages and forms

### Navbar Layout
- Top navigation bar
- Responsive menu
- User dropdown (when authenticated)
- Good for public-facing pages

### Fluid Layout
- Full-width container
- Gradient header
- Maximizes screen space
- Ideal for dashboards and data tables

**Note:** If you request an `admin` layout with this theme, it will automatically fall back to the `default` layout. For full admin functionality with sidebar navigation, use `theme-adminlte` instead.

## Usage

```php
<?php
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin', // or 'default', 'navbar', 'fluid'
    'pageTitle' => 'My Page',
    'currentPage' => 'page_id'
]);

$theme = ThemeLoader::getActiveTheme();
$theme->showHeader($context);
?>

<!-- Your page content here -->

<?php $theme->showFooter($context); ?>
```

## Customization

Add custom styles or scripts:

```php
<?php
$customStyles = '<style>.my-class { color: red; }</style>';
$customScripts = '<script>console.log("Hello");</script>';
?>
```

## Features

- Responsive design
- Bootstrap Icons included
- Modern, clean aesthetic
- No jQuery dependency
- Lightweight and fast
