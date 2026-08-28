# Code Implementation Guide

This guide provides practical implementation patterns for the organized procedural approach described in [../concepts/CODE_ORGANIZATION.md](../concepts/CODE_ORGANIZATION.md).

## Application Constants

The application defines several constants for use throughout the codebase:

### Core Constants
- **`APP_ROOT`** - Absolute filesystem path to the `src/` directory
- **`BASE_URL`** - URL path from web root to the application (e.g., `/`, `/beta/`, `/slocloud/`)
- **`BASE_PATH`** - Same as `APP_ROOT`, for filesystem operations
- **`CONFIG_PATH`** - Path to `config.yaml` file
- **`SITE_NAME`** - Display name for the installation from config

### Email Constants
- **`EMAIL_METHOD`** - Email delivery method (`disabled`, `server`, or `smtp`)
- **`EMAIL_FROM_EMAIL`** - Sender email address
- **`EMAIL_FROM_NAME`** - Sender display name

**Configuration Details:** See [../concepts/CONFIGURATION.md](../concepts/CONFIGURATION.md) for complete configuration documentation.

**BASE_URL Performance Note:**

The `BASE_URL` is detected once during setup and stored in `config.yaml` for optimal performance. On each request, it's read from the config file rather than being recalculated. This avoids filesystem operations on every page load.

During initial setup (when config doesn't exist yet), the `BASE_URL` is auto-detected from the request path. The setup wizard displays the detected value in an editable form field, allowing administrators to confirm or adjust it before installation.

**Using Constants in Code:**

```php
// Display site name
echo htmlspecialchars(SITE_NAME);

// Check if email is enabled
if (EMAIL_METHOD !== 'disabled') {
    // Send notification email
    sendEmail(EMAIL_FROM_EMAIL, EMAIL_FROM_NAME, $recipient, $subject, $body);
}
```

**Using BASE_URL for Links and Redirects:**

```php
// Building URLs
<a href="<?php echo BASE_URL; ?>dashboard/outcomes">Outcomes</a>
<a href="<?php echo BASE_URL; ?>reports/slo">SLO Reports</a>

// Form actions
<form method="POST" action="<?php echo BASE_URL; ?>dashboard/outcomes">

// Redirects using Path helper
\SLOCloud\Core\Path::redirect('dashboard/outcomes'); // Prepends BASE_URL automatically

// Manual redirects
header('Location: ' . BASE_URL . 'dashboard/outcomes');
```

**Why BASE_URL is Important:**

- Supports installation in subdirectories (e.g., `slocloud-slo.org/beta/`)
- Prevents broken links when moving between environments
- Automatically detected by the `Path` helper class
- Hardcoding `/` breaks subdirectory installations

## Page Structure Pattern

### Basic Page Template

```php
<?php
declare(strict_types=1);

// Session security configuration
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Authentication check (if required)
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Authorization check (if required)
if (!hasPermission($_SESSION['user_id'], 'outcomes.view')) {
    require_once __DIR__ . '/includes/message_page.php';
    render_message_page('error', 'Access Denied', 'You do not have permission to view this page.', 'fa-lock');
    exit;
}

// Database connection
require_once __DIR__ . '/Core/Database.php';
$db = new \SLOCloud\Core\Database();

// Handle form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    // Process form data
    // ... (validation, database operations, etc.)
    
    // Redirect after successful POST
    header('Location: ' . BASE_URL . 'dashboard/outcomes?success=1');
    exit;
}

// Generate CSRF token for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch data for page
$outcomes = $db->query(
    "SELECT * FROM {$dbPrefix}institutional_outcomes WHERE is_active = 1 ORDER BY sequence_num"
)->fetchAll();

// Render page
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'      => 'admin',
    'pageTitle'   => 'Manage Outcomes',
    'currentPage' => 'admin_outcomes',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Outcomes']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<div class="app-content">
    <div class="container-fluid">
        <!-- Page content here -->
        <?php foreach ($outcomes as $outcome): ?>
            <div class="card">
                <div class="card-body">
                    <h3><?= htmlspecialchars($outcome['outcome_title']) ?></h3>
                    <p><?= htmlspecialchars($outcome['outcome_description']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php $theme->showFooter($context); ?>
```

### LTI Endpoint Pattern (No Sidebar)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';

// Verify LTI session set by lti/launch.php
if (empty($_SESSION['lti_authenticated']) || empty($_SESSION['lti_is_instructor'])) {
    render_message_page('error', 'Access Denied', 'This page is only accessible via LTI launch.');
    exit;
}

$ltiCrn      = $_SESSION['lti_crn'] ?? null;
$ltiTermId   = $_SESSION['lti_term_id'] ?? null;
$ltiCourseName = $_SESSION['lti_course_name'] ?? 'Course';

// Render LTI page (embedded layout - no sidebar)
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'    => 'embedded',
    'pageTitle' => 'Assessment Tool - ' . SITE_NAME,
]);

$theme = ThemeLoader::getActiveTheme(null, 'lti');
$theme->showHeader($context);
?>

<div class="container-fluid">
    <h1><?= htmlspecialchars($ltiCourseName) ?></h1>
    <!-- LTI tool content here -->
</div>

<?php $theme->showFooter($context); ?>
```

## Direct Database Pattern

### Simple Query

```php
<?php
require_once __DIR__ . '/Core/Database.php';
$db = new \SLOCloud\Core\Database();

// SELECT query with parameter
$stmt = $db->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    // Handle not found
    require_once __DIR__ . '/includes/message_page.php';
    render_message_page('error', 'Not Found', 'Student not found.', 'fa-user-slash');
    exit;
}
```

### INSERT with Auto-increment PK

```php
<?php
// Insert new record
$stmt = $db->prepare("
    INSERT INTO students (student_id, first_name, last_name, email, is_active)
    VALUES (?, ?, ?, ?, 1)
");
$stmt->execute([
    $_POST['student_id'],
    $_POST['first_name'],
    $_POST['last_name'],
    $_POST['email']
]);

// Get the auto-increment primary key
$newStudentPk = (int)$db->lastInsertId();

// Log the action
require_once APP_ROOT . '/Core/Logger.php';
$logger = new \SLOCloud\Core\Logger();
$logger->info('Student created', [
    'student_pk' => $newStudentPk,
    'student_id' => $_POST['student_id'],
    'user_id' => $_SESSION['user_id']
]);
```

### UPDATE Query

```php
<?php
// Update existing record
$stmt = $db->prepare("
    UPDATE students 
    SET first_name = ?, last_name = ?, email = ?
    WHERE students_pk = ?
");
$stmt->execute([
    $_POST['first_name'],
    $_POST['last_name'],
    $_POST['email'],
    $studentPk
]);

$rowsAffected = $stmt->rowCount();
if ($rowsAffected === 0) {
    // No rows updated - record may not exist
}
```

### Soft DELETE (Recommended)

```php
<?php
// Soft delete - set is_active to false
$stmt = $db->prepare("UPDATE students SET is_active = 0 WHERE students_pk = ?");
$stmt->execute([$studentPk]);
```

### JOIN Query

```php
<?php
// Complex query with joins
$stmt = $db->prepare("
    SELECT 
        e.enrollment_pk,
        s.student_id,
        s.first_name,
        s.last_name,
        cs.course_name,
        t.term_name
    FROM enrollment e
    JOIN students s ON e.student_fk = s.students_pk
    JOIN course_sections cs ON e.course_section_fk = cs.course_sections_pk
    JOIN terms t ON cs.term_fk = t.terms_pk
    WHERE cs.course_sections_pk = ?
    ORDER BY s.last_name, s.first_name
");
$stmt->execute([$courseSectionPk]);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

## Optional Model Pattern

### When to Use Models

Create a Model class when you have:
- Same query used in 3+ places
- Complex business logic
- Data validation patterns
- Related methods that belong together

### Model Implementation

```php
<?php
namespace SLOCloud\Models;

use SLOCloud\Core\Model;

class Student extends Model
{
    protected $table = 'students';
    protected $primaryKey = 'students_pk';
    
    /**
     * Find student by external student ID
     */
    public function findByStudentId(string $studentId): ?array
    {
        return $this->findOne(['student_id' => $studentId]);
    }
    
    /**
     * Get all enrollments for a student
     */
    public function getEnrollments(int $studentPk): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                e.enrollment_pk,
                cs.crn,
                cs.course_name,
                cs.section_number,
                t.term_name,
                t.start_date,
                t.end_date
            FROM enrollment e
            JOIN course_sections cs ON e.course_section_fk = cs.course_sections_pk
            JOIN terms t ON cs.term_fk = t.terms_pk
            WHERE e.student_fk = ?
            ORDER BY t.start_date DESC
        ");
        $stmt->execute([$studentPk]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get assessment history for a student
     */
    public function getAssessments(int $studentPk, ?int $sloSetFk = null): array
    {
        $sql = "
            SELECT 
                a.assessments_pk,
                a.score,
                a.assessment_date,
                slo.outcome_code,
                slo.outcome_title,
                cs.course_name
            FROM assessments a
            JOIN enrollment e ON a.enrollment_fk = e.enrollment_pk
            JOIN student_learning_outcomes slo ON a.slo_fk = slo.slos_pk
            JOIN course_sections cs ON e.course_section_fk = cs.course_sections_pk
            WHERE e.student_fk = ?
        ";
        
        $params = [$studentPk];
        
        if ($sloSetFk !== null) {
            $sql .= " AND slo.slo_set_fk = ?";
            $params[] = $sloSetFk;
        }
        
        $sql .= " ORDER BY a.assessment_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate student data before save
     */
    public function validate(array $data): array
    {
        $errors = [];
        
        if (empty($data['student_id'])) {
            $errors[] = 'Student ID is required';
        }
        
        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }
        
        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        }
        
        // Check for duplicate student_id
        if (!empty($data['student_id'])) {
            $existing = $this->findByStudentId($data['student_id']);
            if ($existing && $existing[$this->primaryKey] != ($data[$this->primaryKey] ?? null)) {
                $errors[] = 'Student ID already exists';
            }
        }
        
        return $errors;
    }
}
```

### Using Models in Pages

```php
<?php
require_once __DIR__ . '/Models/Student.php';

$studentModel = new \SLOCloud\Models\Student();

// Find by primary key
$student = $studentModel->find($studentPk);

// Find by student ID
$student = $studentModel->findByStudentId($_POST['student_id']);

// Get enrollments
$enrollments = $studentModel->getEnrollments($studentPk);

// Validate before saving
$errors = $studentModel->validate($_POST);
if (!empty($errors)) {
    // Show errors
    foreach ($errors as $error) {
        echo "<p class='text-danger'>" . htmlspecialchars($error) . "</p>";
    }
} else {
    // Save data
    $studentModel->create($_POST);
}
```

## Form Handling Patterns

### Basic Form with Validation

```php
<?php
$errors = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    // Capture form data
    $formData = [
        'outcome_code' => trim($_POST['outcome_code'] ?? ''),
        'outcome_title' => trim($_POST['outcome_title'] ?? ''),
        'outcome_description' => trim($_POST['outcome_description'] ?? ''),
    ];
    
    // Validation
    if (empty($formData['outcome_code'])) {
        $errors[] = 'Outcome code is required';
    }
    
    if (empty($formData['outcome_title'])) {
        $errors[] = 'Outcome title is required';
    }
    
    if (strlen($formData['outcome_code']) > 20) {
        $errors[] = 'Outcome code must be 20 characters or less';
    }
    
    // If valid, save to database
    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO institutional_outcomes (outcome_code, outcome_title, outcome_description, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([
            $formData['outcome_code'],
            $formData['outcome_title'],
            $formData['outcome_description']
        ]);
        
        // Redirect after successful save
        header('Location: ' . BASE_URL . 'dashboard/outcomes?success=1');
        exit;
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Add Outcome';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-wrapper">
    <section class="content">
        <div class="card">
            <div class="card-header">
                <h3>Add Institutional Outcome</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="form-group">
                        <label>Outcome Code</label>
                        <input type="text" name="outcome_code" class="form-control" 
                               value="<?= htmlspecialchars($formData['outcome_code'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Outcome Title</label>
                        <input type="text" name="outcome_title" class="form-control" 
                               value="<?= htmlspecialchars($formData['outcome_title'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="outcome_description" class="form-control" rows="4"><?= htmlspecialchars($formData['outcome_description'] ?? '') ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?= BASE_URL ?>dashboard/outcomes" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
```

### File Upload Handling

```php
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Validate CSRF token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    // Validate file upload
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed';
    } else {
        $fileInfo = pathinfo($_FILES['csv_file']['name']);
        
        if ($fileInfo['extension'] !== 'csv') {
            $errors[] = 'File must be a CSV';
        } else {
            // Process CSV file
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== false) {
                // Process each row
                $data = array_combine($header, $row);
                
                // Insert into database
                $stmt = $db->prepare("INSERT INTO students (student_id, first_name, last_name) VALUES (?, ?, ?)");
                $stmt->execute([$data['student_id'], $data['first_name'], $data['last_name']]);
            }
            
            fclose($handle);
            
            // Success redirect
            header('Location: ' . BASE_URL . 'dashboard/outcomes?imported=1');
            exit;
        }
    }
}
```

## Security Patterns

### CSRF Protection

Always include CSRF tokens in forms:

```php
<?php
// Generate token (store in session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!-- In form -->
<form method="POST" action="<?= BASE_URL ?>dashboard/outcomes">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <!-- form fields -->
</form>

<?php
// Validate on submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    // Process form
}
?>
```

### SQL Injection Prevention

**ALWAYS use prepared statements:**

```php
// CORRECT
$stmt = $db->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->execute([$studentId]);

// WRONG - Never concatenate user input!
$query = "SELECT * FROM students WHERE student_id = '$studentId'"; // SQL injection!
```

### XSS Prevention

**ALWAYS escape output:**

```php
<!-- HTML context -->
<p><?= htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8') ?></p>

<!-- Attribute context -->
<input value="<?= htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8') ?>">

<!-- JavaScript context -->
<script>
var data = <?= json_encode($userInput, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
```

### Session Security

Configure at the start of every page that uses sessions:

```php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_start();

// Regenerate session ID periodically
if (empty($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
```

### Authorization Checks

```php
<?php
// Simple permission check
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Role-based check
if (!in_array($_SESSION['role'], ['admin', 'coordinator'])) {
    require_once __DIR__ . '/includes/message_page.php';
    render_message_page('error', 'Access Denied', 'Insufficient permissions.', 'fa-lock');
    exit;
}

// Database-driven permission check
$stmt = $db->prepare("
    SELECT COUNT(*) FROM user_permissions 
    WHERE user_fk = ? AND permission_name = ?
");
$stmt->execute([$_SESSION['user_id'], 'outcomes.edit']);
$hasPermission = $stmt->fetchColumn() > 0;

if (!$hasPermission) {
    http_response_code(403);
    die('Access denied');
}
?>
```

## Common Includes Usage

### Header and Footer Pattern

All admin pages use `ThemeLoader`. Never use `includes/header.php` directly in admin pages.

```php
<?php
require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

// ... page logic ...

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'      => 'admin',        // 'admin' for admin pages, 'embedded' for LTI
    'pageTitle'   => 'My Page',
    'currentPage' => 'admin_mypage', // matches sidebar nav key
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'My Page']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<div class="app-content">
    <div class="container-fluid">
        <!-- Page content here -->
    </div>
</div>

<?php $theme->showFooter($context); ?>
```

### Error Page Pattern

```php
<?php
require_once __DIR__ . '/includes/message_page.php';
require_once __DIR__ . '/includes/header.php';

render_message_page(
    'error',                          // Type: error, success, warning, info
    'Database Error',                 // Title
    'Unable to connect to database.', // Message
    'fa-database text-danger'        // Icon class (optional)
);

require_once __DIR__ . '/includes/footer.php';
exit;
?>
```

### Success Messages

```php
<?php
// After successful operation, show success message
if (isset($_GET['success'])) {
    echo '<div class="alert alert-success alert-dismissible">';
    echo '<button type="button" class="close" data-dismiss="alert">&times;</button>';
    echo 'Operation completed successfully!';
    echo '</div>';
}
?>
```

## Logging Pattern

```php
<?php
require_once APP_ROOT . '/Core/Logger.php';
$logger = new \SLOCloud\Core\Logger();

// Log levels
$logger->debug('Debug message for development');
$logger->info('User logged in', ['user_id' => $userId]);
$logger->warning('Deprecated feature used', ['feature' => 'old_api']);
$logger->error('Database query failed', ['query' => $sql, 'error' => $e->getMessage()]);

// Always log security events
$logger->info('Login attempt', [
    'user_id' => $userId,
    'ip' => $_SERVER['REMOTE_ADDR'],
    'success' => true
]);

$logger->warning('Failed login attempt', [
    'username' => $username,
    'ip' => $_SERVER['REMOTE_ADDR']
]);
?>
```

## Common Workflows

### List Page with Search/Filter

```php
<?php
// Get search/filter parameters
$search = $_GET['search'] ?? '';
$filterActive = $_GET['active'] ?? 'all';
$page = (int)($_GET['page'] ?? 1);
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build query with conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filterActive !== 'all') {
    $conditions[] = "is_active = ?";
    $params[] = ($filterActive === 'active') ? 1 : 0;
}

$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM students $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Get page records
$stmt = $db->prepare("
    SELECT * FROM students 
    $whereClause
    ORDER BY last_name, first_name
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Render page with results and pagination
?>
```

### Create/Edit Form Handler

```php
<?php
// Determine if create or edit mode
$isEdit = isset($_GET['id']);
$studentPk = $isEdit ? (int)$_GET['id'] : null;

if ($isEdit) {
    // Load existing record
    $stmt = $db->prepare("SELECT * FROM students WHERE students_pk = ?");
    $stmt->execute([$studentPk]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        require_once __DIR__ . '/includes/message_page.php';
        render_message_page('error', 'Not Found', 'Student not found.', 'fa-user-slash');
        exit;
    }
} else {
    $student = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and save
    // ... (validation logic)
    
    if ($isEdit) {
        // UPDATE
        $stmt = $db->prepare("
            UPDATE students 
            SET student_id = ?, first_name = ?, last_name = ?, email = ?
            WHERE students_pk = ?
        ");
        $stmt->execute([
            $_POST['student_id'],
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email'],
            $studentPk
        ]);
    } else {
        // INSERT
        $stmt = $db->prepare("
            INSERT INTO students (student_id, first_name, last_name, email, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $_POST['student_id'],
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email']
        ]);
    }
    
    // Redirect after save
    header('Location: ' . BASE_URL . 'dashboard/students?saved=1');
    exit;
}
?>
```

### Delete Handler

```php
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    // Validate CSRF token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    $studentPk = (int)$_POST['student_pk'];
    
    // Check for dependent records
    $stmt = $db->prepare("SELECT COUNT(*) FROM enrollment WHERE student_fk = ?");
    $stmt->execute([$studentPk]);
    $enrollmentCount = $stmt->fetchColumn();
    
    if ($enrollmentCount > 0) {
        // Cannot delete - has enrollments
        $errors[] = "Cannot delete student with existing enrollments. Use deactivate instead.";
    } else {
        // Soft delete (preferred)
        $stmt = $db->prepare("UPDATE students SET is_active = 0 WHERE students_pk = ?");
        $stmt->execute([$studentPk]);
        
        // Or hard delete (if appropriate)
        // $stmt = $db->prepare("DELETE FROM students WHERE students_pk = ?");
        // $stmt->execute([$studentPk]);
        
        header('Location: ' . BASE_URL . 'dashboard/students?deleted=1');
        exit;
    }
}
?>
```

## Testing Patterns

### Manual Testing Checklist

For each page, verify:
- [ ] CSRF token present in forms
- [ ] All user input is escaped in output
- [ ] SQL queries use prepared statements
- [ ] Authorization checks before sensitive operations
- [ ] Session security configured
- [ ] Error handling displays user-friendly messages
- [ ] Success redirects use POST-REDIRECT-GET pattern
- [ ] Form validation prevents invalid data
- [ ] Database constraints enforced
- [ ] Logging captures security events

See [../concepts/TESTING.md](../concepts/TESTING.md) for comprehensive testing strategy.

## Related Documentation

**Concepts:**
- [CODE_ORGANIZATION.md](../concepts/CODE_ORGANIZATION.md) - Architecture overview
- [ARCHITECTURE.md](../concepts/ARCHITECTURE.md) - System design
- [SCHEMA.md](../concepts/SCHEMA.md) - Database schema
- [SECURITY.md](../concepts/SECURITY.md) - Security requirements
- [CONFIGURATION.md](../concepts/CONFIGURATION.md) - Configuration management
- [TESTING.md](../concepts/TESTING.md) - Testing strategy
