<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\Authorization;
use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\ThemeLoader;

$isAdmin = Authorization::isGlobalAdmin($db, $dbPrefix);
$groups = [
    'Planning and curriculum' => [
        ['Terms', 'terms.php'],
        ['Disciplines', 'disciplines.php'],
        ['Institutional outcomes', 'institutional_outcomes.php'],
        ['Programs', 'programs.php'],
        ['Program outcomes', 'program_outcomes.php'],
        ['Courses', 'courses.php'],
        ['Student learning outcomes', 'student_learning_outcomes.php'],
        ['Sections', 'sections.php'],
        ['Cross-listed sections', 'xlist.php'],
    ],
    'Students and assessment' => [
        ['Students', 'students.php'],
        ['Enrollment', 'enrollment.php'],
        ['Assessments', 'assessments.php'],
        ['Custom report', 'dynamic_reports.php'],
    ],
    'Help and product information' => [
        ['Patch notes', 'patch_notes.php'],
        ['Accessibility reports and help', 'vpat.php'],
    ],
];
if ($isAdmin) {
    $groups['Administration'] = [
        ['Activity log', 'activity_log.php'],
        ['Users and roles', 'users.php'],
        ['Configuration', 'config.php'],
        ['Color scheme', 'color_scheme.php'],
        ['LTI integration', '../lti/'],
        ['Deploy to Canvas', 'canvas_deploy.php'],
        ['System status', 'system_status.php'],
    ];
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'Site Map',
    'currentPage' => 'admin_site_map',
    'breadcrumbs' => [
        ['url' => BASE_URL . 'administration/', 'label' => 'Administration'],
        ['label' => 'Site Map'],
    ],
]);
$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>
<p class="lead">Use this page as an alternative to the administration navigation.</p>
<div class="row g-3">
    <?php foreach ($groups as $heading => $links): ?>
        <section class="col-12 col-md-6" aria-labelledby="<?= htmlspecialchars(strtolower(str_replace([' ', '&'], ['-', 'and'], $heading))) ?>">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="h4 mb-0" id="<?= htmlspecialchars(strtolower(str_replace([' ', '&'], ['-', 'and'], $heading))) ?>"><?= htmlspecialchars($heading) ?></h2>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <?php foreach ($links as [$label, $url]): ?>
                            <li><a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($label) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php $theme->showFooter($context); ?>
