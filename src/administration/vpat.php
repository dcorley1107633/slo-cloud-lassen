<?php
/**
 * VPAT (Voluntary Product Accessibility Template) Index
 * Lists all VPAT documents for SLOCloud interfaces
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';


$pageTitle = 'Accessibility Conformance Reports (VPAT)';
$currentPage = 'admin_vpat';

// Load theme
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout'      => 'admin',
    'pageTitle'   => $pageTitle,
    'currentPage' => $currentPage,
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Accessibility (VPAT)']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<div class="row">
    <div class="col-12">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h2 class="card-title"><i class="bi bi-universal-access" aria-hidden="true"></i> WCAG 2.2 Accessibility Conformance Reports</h2>
            </div>
            <div class="card-body">
                <p class="lead">
                    SLOCloud is committed to improving conformance with Web Content Accessibility Guidelines (WCAG) 2.2.
                    The current reports distinguish implemented support from criteria that still require independent manual testing.
                </p>
                
                <div class="alert alert-info" role="alert">
                    <i class="bi bi-info-circle me-2" aria-hidden="true"></i>
                    <strong>What is a VPAT?</strong> A Voluntary Product Accessibility Template (VPAT) is an industry-standard document that explains how a product conforms to accessibility standards. These reports help institutions evaluate accessibility compliance for procurement and legal requirements.
                </div>

                <h3 class="mt-4">Assessment Interface (LTI)</h3>
                <p>Student Learning Outcome assessment entry interface for instructors, embedded in Learning Management Systems via LTI.</p>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <caption class="visually-hidden">LTI assessment interface accessibility reports by product version</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Version</th>
                                <th scope="col">Date</th>
                                <th scope="col">Conformance Level</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>v<?= htmlspecialchars(APP_VERSION) ?></strong> <span class="badge bg-primary">Current</span></td>
                                <td>October 24, 2026</td>
                                <td>
                                    <span class="badge bg-success me-1">Level A: Supports</span>
                                    <span class="badge bg-success">Level AA: Supports</span>
                                    <span class="visually-hidden">All applicable WCAG 2.2 Level A and AA criteria support conformance in the evaluated scope</span>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>administration/vpat_view.php?doc=assessment-page-vpat-v5.2.12.md" class="btn btn-sm btn-primary">
                                        <i class="bi bi-filetype-md me-1" aria-hidden="true"></i> View current report
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3 class="mt-4">Admin Interface</h3>
                <p>Dashboard and management interface for administrators.</p>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <caption class="visually-hidden">Administrative interface accessibility reports by product version</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Version</th>
                                <th scope="col">Date</th>
                                <th scope="col">Conformance Level</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>v<?= htmlspecialchars(APP_VERSION) ?></strong> <span class="badge bg-primary">Current</span></td>
                                <td>October 24, 2026</td>
                                <td>
                                    <span class="badge bg-success me-1">Level A: Supports</span>
                                    <span class="badge bg-success">Level AA: Supports</span>
                                    <span class="visually-hidden">All applicable WCAG 2.2 Level A and AA criteria support conformance in the evaluated scope</span>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>administration/vpat_view.php?doc=admin-interface-vpat-v5.2.12.md" class="btn btn-sm btn-primary">
                                        <i class="bi bi-filetype-md me-1" aria-hidden="true"></i> View current report
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3 class="mt-4">Additional Resources</h3>
                <ul class="list-group">
                    <li class="list-group-item">
                        <i class="bi bi-book me-2" aria-hidden="true"></i>
                        <a href="<?= BASE_URL ?>administration/vpat_view.php?doc=admin-accessibility-guide.md">
                            Admin Accessibility Guide
                        </a>
                        <p class="mb-0 ms-4 text-muted">Best practices for creating accessible content in SLOCloud</p>
                    </li>
                    <li class="list-group-item">
                        <i class="bi bi-file-text me-2" aria-hidden="true"></i>
                        <a href="<?= BASE_URL ?>administration/vpat_view.php?doc=README.md">
                            VPAT Documentation Overview
                        </a>
                        <p class="mb-0 ms-4 text-muted">Introduction to SLOCloud accessibility documentation</p>
                    </li>
                    <li class="list-group-item">
                        <i class="bi bi-globe me-2" aria-hidden="true"></i>
                        <a href="https://www.w3.org/WAI/WCAG22/quickref/" target="_blank" rel="noopener noreferrer">
                            WCAG 2.2 Quick Reference <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                            <span class="visually-hidden"> (opens in a new tab)</span>
                        </a>
                        <p class="mb-0 ms-4 text-muted">Official W3C guide to WCAG 2.2 success criteria</p>
                    </li>
                </ul>

                <div class="alert alert-light mt-4" role="alert">
                    <h4 class="alert-heading"><i class="bi bi-question-circle me-2" aria-hidden="true"></i>Questions or Concerns?</h4>
                    <p class="mb-0">
                        If you have questions about SLOCloud accessibility or need assistance with accommodations, please contact the SLOCloud Project Team.
                        We are committed to continuous improvement and welcome feedback on accessibility.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$theme->showFooter($context);
?>
