(function () {
    'use strict';

    var managedPages = [
        'terms.php', 'disciplines.php', 'institutional_outcomes.php',
        'programs.php', 'program_outcomes.php', 'courses.php', 'sections.php',
        'student_learning_outcomes.php', 'students.php', 'enrollment.php',
        'assessments.php', 'xlist.php'
    ];
    var page = window.location.pathname.split('/').pop();
    if (managedPages.indexOf(page) === -1) return;
    var inactiveMode = new URL(window.location.href).searchParams.get('status') === 'inactive';

    function escapeHtml(value) {
        var element = document.createElement('div');
        element.textContent = value === null || value === undefined ? '' : String(value);
        return element.innerHTML;
    }

    function statusBadge(value) {
        var active = value === true || value === 1 || value === '1';
        return active
            ? '<span class="badge text-bg-success">Active</span>'
            : '<span class="badge text-bg-secondary">Inactive</span>';
    }

    function achievementLabel(value) {
        var labels = {
            met: 'Met',
            partially_met: 'Partially Met',
            not_met: 'Not Met',
            not_assessed: 'Not Assessed'
        };
        return escapeHtml(labels[value] || 'Not Assessed');
    }

    function renderRelatedGroup(group, displayLimit) {
        var headings = group.columns.map(function (column) {
            return '<th scope="col">' + escapeHtml(column.label) + '</th>';
        }).join('');
        var rows = group.records.map(function (record) {
            var cells = group.columns.map(function (column) {
                var value = column.type === 'status'
                    ? statusBadge(record[column.key])
                    : column.type === 'achievement'
                        ? achievementLabel(record[column.key])
                        : escapeHtml(record[column.key]);
                return '<td>' + value + '</td>';
            }).join('');
            return '<tr>' + cells + '</tr>';
        }).join('');
        if (!rows) {
            rows = '<tr><td colspan="' + group.columns.length
                + '" class="text-muted text-center py-3">No related records.</td></tr>';
        }
        var limitNote = group.total > displayLimit
            ? '<span class="small text-muted ms-2">Showing first ' + displayLimit + '</span>'
            : '';
        var groupId = 'related-group-' + Math.random().toString(36).slice(2);

        return '<section class="col-12 col-xl-6" aria-labelledby="' + groupId + '">'
            + '<h4 class="h6" id="' + groupId + '">' + escapeHtml(group.label)
            + ' <span class="badge text-bg-secondary">' + Number(group.total).toLocaleString() + '</span>'
            + limitNote + '</h4>'
            + '<div class="table-responsive border rounded bg-body" style="max-height:20rem"'
            + ' tabindex="0" role="region" aria-labelledby="' + groupId + '">'
            + '<table class="table table-sm table-striped align-middle mb-0">'
            + '<caption class="visually-hidden">' + escapeHtml(group.label) + ' related records</caption>'
            + '<thead><tr>' + headings + '</tr></thead><tbody>' + rows + '</tbody></table></div></section>';
    }

    function renderRelatedRecords(data, detailsId) {
        var headingId = detailsId + '-heading';
        return '<div class="bg-body-tertiary p-3" id="' + escapeHtml(detailsId)
            + '" role="region" aria-labelledby="' + escapeHtml(headingId) + '">'
            + '<span class="visually-hidden" role="status" aria-live="polite">Related records loaded.</span>'
            + '<div class="d-flex align-items-center mb-3">'
            + '<i class="fas fa-sitemap me-2 text-muted" aria-hidden="true"></i>'
            + '<h3 class="h6 mb-0" id="' + escapeHtml(headingId) + '">Related records</h3>'
            + '<span class="text-muted ms-2">Direct dependencies of this record</span></div>'
            + '<div class="row g-3">' + data.groups.map(function (group) {
                return renderRelatedGroup(group, data.display_limit);
            }).join('') + '</div></div>';
    }

    function renderRelatedError(message, detailsId) {
        return '<div class="bg-body-tertiary p-3" id="' + escapeHtml(detailsId) + '">'
            + '<div class="alert alert-danger mb-0" role="alert">'
            + '<i class="fas fa-exclamation-circle me-1" aria-hidden="true"></i>'
            + escapeHtml(message) + '</div></div>';
    }

    function setRelatedButtonState(button, expanded) {
        var icon = button.querySelector('i');
        var currentLabel = button.getAttribute('aria-label') || '';
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.setAttribute('title', expanded ? 'Hide related records' : 'Show related records');
        button.setAttribute(
            'aria-label',
            currentLabel.replace(expanded ? /^Show / : /^Hide /, expanded ? 'Hide ' : 'Show ')
        );
        if (icon) {
            icon.classList.toggle('fa-chevron-right', !expanded);
            icon.classList.toggle('fa-chevron-down', expanded);
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-toggle-related');
        if (!button || !window.jQuery || !window.jQuery.fn.DataTable) return;

        var tableElement = button.closest('table');
        var tableRow = button.closest('tr');
        if (!tableElement || !tableRow) return;
        var table = window.jQuery(tableElement).DataTable();
        var row = table.row(tableRow);
        var detailsId = button.getAttribute('aria-controls');

        if (row.child.isShown()) {
            row.child.hide();
            tableRow.classList.remove('shown');
            setRelatedButtonState(button, false);
            return;
        }

        row.child(
            '<div class="bg-body-tertiary p-3 text-muted" id="' + escapeHtml(detailsId)
            + '" role="status" aria-live="polite">'
            + '<i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>'
            + 'Loading related records...</div>'
        ).show();
        tableRow.classList.add('shown');
        setRelatedButtonState(button, true);

        window.jQuery.ajax({
            url: 'related_records_data.php',
            data: {
                entity: button.getAttribute('data-related-entity'),
                record_id: button.getAttribute('data-record-id')
            },
            dataType: 'json'
        }).done(function (data) {
            if (button.getAttribute('aria-expanded') !== 'true') return;
            row.child(data.error
                ? renderRelatedError(data.error, detailsId)
                : renderRelatedRecords(data, detailsId)).show();
        }).fail(function (xhr) {
            if (button.getAttribute('aria-expanded') !== 'true') return;
            var message = xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Unable to load related records.';
            row.child(renderRelatedError(message, detailsId)).show();
        });
    });

    window.configureLifecycleEditAction = function (
        formSelector,
        recordId,
        isActiveValue,
        entityLabel,
        recordLabel
    ) {
        var form = document.querySelector(formSelector);
        if (!form) return;
        var recordIdInput = form.querySelector('.js-lifecycle-record-id');
        var newStatusInput = form.querySelector('.js-lifecycle-new-status');
        var button = form.querySelector('.js-edit-lifecycle-action');
        var inheritedStatus = form.querySelector('.js-edit-inherited-status');
        if (!recordIdInput || !newStatusInput || !button || !inheritedStatus) return;

        var isActive = isActiveValue === true || isActiveValue === 1 || isActiveValue === '1';
        var inheritedInactive = isActive && inactiveMode;
        recordIdInput.value = recordId;
        button.hidden = inheritedInactive;
        inheritedStatus.hidden = !inheritedInactive;
        if (inheritedInactive) return;

        var actionLabel = isActive ? 'Deactivate' : 'Reactivate';
        var icon = button.querySelector('i');
        newStatusInput.value = isActive ? '0' : '1';
        button.hidden = false;
        button.classList.toggle('btn-outline-secondary', isActive);
        button.classList.toggle('btn-outline-success', !isActive);
        button.setAttribute(
            'aria-label',
            actionLabel + ' ' + entityLabel + (recordLabel ? ' ' + recordLabel : '')
        );
        if (icon) {
            icon.classList.toggle('fa-toggle-off', isActive);
            icon.classList.toggle('fa-toggle-on', !isActive);
        }
        var label = button.querySelector('.js-lifecycle-action-label');
        if (label) label.textContent = actionLabel;
        button.onclick = function () {
            return window.confirm(actionLabel + ' this ' + entityLabel + '?');
        };
    };

    if (window.jQuery) {
        window.jQuery(document).on('preXhr.dt', function (_event, _settings, data) {
            data.status = inactiveMode ? 'inactive' : 'active';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (new URL(window.location.href).searchParams.get('lifecycle_error') === 'parent') {
            var host = document.querySelector('.app-content .container-fluid, main .container-fluid, main');
            if (host) {
                var alert = document.createElement('div');
                alert.className = 'alert alert-warning';
                alert.setAttribute('role', 'alert');
                alert.textContent = 'This record cannot be reactivated until its inactive parent is reactivated.';
                host.insertBefore(alert, host.firstChild);
            }
        }
        document.querySelectorAll(
            'button[onclick^="confirmDelete"], button[aria-label^="Delete "], form input[name="action"][value="delete"]'
        ).forEach(function (element) {
            var target = element.matches('input') ? element.closest('form') : element;
            if (target) target.remove();
        });
        var tools = document.querySelector('.card-tools');
        if (!tools || tools.querySelector('[data-lifecycle-toggle]')) return;

        var url = new URL(window.location.href);
        var inactive = inactiveMode;
        var wrapper = document.createElement('div');
        wrapper.className = 'form-check form-switch d-inline-flex align-items-center me-2';
        wrapper.setAttribute('data-lifecycle-toggle', 'true');
        wrapper.innerHTML =
            '<input class="form-check-input me-1" type="checkbox" id="showDeactivatedRecords" '
            + (inactive ? 'checked ' : '')
            + 'aria-label="Show deactivated records only">'
            + '<label class="form-check-label small" for="showDeactivatedRecords">Show deactivated records</label>';
        tools.insertBefore(wrapper, tools.firstChild);
        wrapper.querySelector('input').addEventListener('change', function () {
            url.searchParams.set('status', this.checked ? 'inactive' : 'active');
            window.location.assign(url.toString());
        });
    });
}());
