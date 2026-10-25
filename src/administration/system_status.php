<?php
declare(strict_types=1);

/**
 * System Status Dashboard - Admin Interface
 * 
 * User-friendly display of system health checks.
 * Requires admin authentication.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/init.php';

// Authentication is handled by init.php automatically for /administration/ pages

$pageTitle = 'System Status';
$currentPage = 'admin_system_status';

// Load theme system (auto-loads ThemeContext and Theme)
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => $currentPage,
    'breadcrumbs' => [
        ['text' => 'Dashboard', 'url' => BASE_URL . 'administration/'],
        ['text' => 'System', 'url' => '#'],
        ['text' => 'Status', 'url' => '', 'active' => true]
    ],
    'customCss' => '<style>
        .status-ok { color: #146c43; }
        .status-warning { color: #806000; }
        .status-error { color: #dc3545; }
        .badge-ok { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-error { background: #f8d7da; color: #721c24; }
        .overall-status { font-size: 24px; font-weight: bold; margin: 20px 0; }
        #statusLoading { text-align: center; padding: 40px; }
        .spinner-border { width: 3rem; height: 3rem; }
    </style>'
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);

$apiKey = $config->get('api.monitoring_key', null);
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fas fa-heartbeat" aria-hidden="true"></i> System Health Checks</h2>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary" id="refreshStatus">
                <i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh
            </button>
            <span class="form-check form-check-inline ms-2 mb-0">
                <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                <label class="form-check-label" for="autoRefresh">Auto-refresh (30 seconds)</label>
            </span>
        </div>
    </div>
    <div class="card-body">
        
        <!-- Loading State -->
        <div id="statusLoading" role="status" aria-live="polite">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Checking system status...</p>
        </div>
        
        <!-- Content (hidden until loaded) -->
        <div id="statusContent" style="display: none;" aria-busy="false">
            <div class="overall-status" id="overallStatus" role="status" aria-live="polite"></div>
            
            <p class="text-muted" id="statusMetadata"></p>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <caption class="visually-hidden">Current health of application services and dependencies</caption>
                    <thead>
                        <tr>
                            <th scope="col">Component</th>
                            <th scope="col">Category</th>
                            <th scope="col">Status</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody id="checksTable">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Error State -->
        <div id="statusError" style="display: none;" tabindex="-1">
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                <strong>Unable to load status data</strong>
                <p id="errorMessage" class="mb-0 mt-2"></p>
            </div>
        </div>
        
    </div>
    <div class="card-footer">
        <p class="mb-2">
            <strong><i class="fas fa-code" aria-hidden="true"></i> API Endpoint:</strong> 
            <code id="apiEndpoint"><?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/api/api.php</code>
            <a href="<?= BASE_URL ?>api/api.php" class="btn btn-sm btn-outline-primary ms-2">
                <i class="fas fa-external-link-alt" aria-hidden="true"></i> View JSON
            </a>
        </p>
        <p class="text-muted small mb-0">
            <i class="fas fa-info-circle" aria-hidden="true"></i> Use the API endpoint for automated monitoring, health checks, and integration with external systems.
        </p>
    </div>
</div>

<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;
const API_URL = BASE_URL + 'api/api.php';
const API_KEY = <?= json_encode($apiKey) ?>;
let autoRefreshInterval = null;

// Status mapping
const statusConfig = {
    operational: {
        class: 'status-ok',
        badge: 'badge-ok',
        text: 'All Systems Operational'
    },
    degraded: {
        class: 'status-warning',
        badge: 'badge-warning',
        text: 'Degraded Performance'
    },
    down: {
        class: 'status-error',
        badge: 'badge-error',
        text: 'System Down'
    },
    error: {
        class: 'status-error',
        badge: 'badge-error',
        text: 'Error'
    }
};

const checkStatusConfig = {
    ok: {
        class: 'status-ok',
        badge: 'badge-ok',
        icon: '<i class="fas fa-check-circle"></i>'
    },
    warning: {
        class: 'status-warning',
        badge: 'badge-warning',
        icon: '<i class="fas fa-exclamation-triangle"></i>'
    },
    critical: {
        class: 'status-error',
        badge: 'badge-error',
        icon: '<i class="fas fa-times-circle"></i>'
    }
};

function formatTimestamp(isoString) {
    const date = new Date(isoString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short'
    });
}

function ucwords(str) {
    return str.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function loadStatus() {
    // Show loading state
    document.getElementById('statusLoading').style.display = 'block';
    document.getElementById('statusContent').style.display = 'none';
    document.getElementById('statusContent').setAttribute('aria-busy', 'true');
    document.getElementById('statusError').style.display = 'none';
    
    const fetchHeaders = { 'Accept': 'application/json' };
    if (API_KEY) { fetchHeaders['X-Api-Key'] = API_KEY; }

    fetch(API_URL, {
        method: 'GET',
        credentials: 'same-origin',
        headers: fetchHeaders,
        cache: 'no-cache'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        renderStatus(data);
    })
    .catch(error => {
        showError(error.message);
    });
}

function renderStatus(data) {
    // Hide loading, show content
    document.getElementById('statusLoading').style.display = 'none';
    document.getElementById('statusContent').style.display = 'block';
    document.getElementById('statusContent').setAttribute('aria-busy', 'false');
    document.getElementById('statusError').style.display = 'none';
    
    // Render overall status
    const config = statusConfig[data.overall_status] || statusConfig.error;
    const overallHtml = `
        <span class="badge ${config.badge}">${data.overall_status.toUpperCase()}</span>
        <span style="margin-left: 10px;">${config.text}</span>
    `;
    document.getElementById('overallStatus').innerHTML = overallHtml;
    document.getElementById('overallStatus').className = 'overall-status ' + config.class;
    
    // Render metadata
    let metadataHtml = '<strong>Last checked:</strong> ' + formatTimestamp(data.timestamp);
    if (data.version) {
        metadataHtml = '<strong>Version:</strong> ' + htmlspecialchars(data.version) + ' | ' + metadataHtml;
    }
    if (data.php_version) {
        metadataHtml += ' | <strong>PHP:</strong> ' + htmlspecialchars(data.php_version);
    }
    if (data.load_time_ms) {
        metadataHtml += ' | <strong>Load time:</strong> ' + data.load_time_ms + 'ms';
    }
    document.getElementById('statusMetadata').innerHTML = metadataHtml;
    
    // Render checks table
    const checksTable = document.getElementById('checksTable');
    checksTable.innerHTML = '';
    
    for (const [component, check] of Object.entries(data.checks)) {
        const checkConfig = checkStatusConfig[check.status] || checkStatusConfig.critical;
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${ucwords(component)}</strong></td>
            <td>${ucwords(check.component || 'unknown')}</td>
            <td>
                <span class="badge ${checkConfig.badge} ${checkConfig.class}">
                    ${checkConfig.icon} ${check.status.toUpperCase()}
                </span>
            </td>
            <td>${htmlspecialchars(check.message)}</td>
        `;
        checksTable.appendChild(row);
    }
}

function showError(message) {
    document.getElementById('statusLoading').style.display = 'none';
    document.getElementById('statusContent').style.display = 'none';
    document.getElementById('statusError').style.display = 'block';
    document.getElementById('errorMessage').textContent = message;
    document.getElementById('statusError').focus();
}

function htmlspecialchars(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    autoRefreshInterval = setInterval(loadStatus, 30000); // 30 seconds
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// Event listeners
document.getElementById('refreshStatus').addEventListener('click', function() {
    const icon = this.querySelector('.fa-sync-alt');
    icon.classList.add('fa-spin');
    loadStatus();
    setTimeout(() => icon.classList.remove('fa-spin'), 1000);
});

document.getElementById('autoRefresh').addEventListener('change', function() {
    if (this.checked) {
        startAutoRefresh();
    } else {
        stopAutoRefresh();
    }
});

// Initial load
loadStatus();
startAutoRefresh();
</script>

<?php $theme->showFooter($context); ?>
