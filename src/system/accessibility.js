(() => {
    'use strict';

    const iconSelector = 'i.bi, i.fas, i.far, i.fab, i.fa';
    document.querySelectorAll(iconSelector).forEach((icon) => {
        if (!icon.hasAttribute('aria-label') && !icon.hasAttribute('title') && !icon.hasAttribute('role')) {
            icon.setAttribute('aria-hidden', 'true');
        }
    });

    const enhanceTables = (root = document) => root.querySelectorAll('table').forEach((table, index) => {
        if (!table.querySelector('caption') && !table.hasAttribute('aria-label') && !table.hasAttribute('aria-labelledby')) {
            const heading = table.closest('.card')?.querySelector('.card-title, h2, h3')
                || table.parentElement?.previousElementSibling?.querySelector?.('h2, h3')
                || null;
            if (heading) {
                if (!heading.id) heading.id = `table-heading-${index + 1}`;
                table.setAttribute('aria-labelledby', heading.id);
            } else {
                table.setAttribute('aria-label', `Data table ${index + 1}`);
            }
        }
        table.querySelectorAll('thead th:not([scope])').forEach((header) => header.setAttribute('scope', 'col'));
    });

    const enhanceTableSearch = (root = document) => root.querySelectorAll('.dataTables_filter input, .dt-search input').forEach((input) => {
        if (!input.getAttribute('aria-label') && !input.getAttribute('aria-labelledby')) {
            input.setAttribute('aria-label', 'Search table');
        }
    });

    const enhanceStatusMessages = (root = document) => {
        root.querySelectorAll('.alert-danger:not([role])').forEach((message) => message.setAttribute('role', 'alert'));
        root.querySelectorAll('.dataTables_info, .dt-info, .dataTables_processing, .dt-processing').forEach((message) => {
            message.setAttribute('role', 'status');
            message.setAttribute('aria-live', 'polite');
        });
        root.querySelectorAll('.alert-success[role="alert"]').forEach((message) => {
            message.setAttribute('role', 'status');
            message.setAttribute('aria-live', 'polite');
        });
    };
    enhanceTables();
    enhanceTableSearch();
    enhanceStatusMessages();

    document.querySelectorAll('.sidebar-menu, .nav-treeview, .navbar-nav[role="menubar"], .dropdown-menu[role="menu"]').forEach((navigation) => {
        navigation.removeAttribute('role');
        navigation.querySelectorAll('[role="menuitem"], [role="none"]').forEach((item) => item.removeAttribute('role'));
    });

    const sidebarToggle = document.querySelector('[data-lte-toggle="sidebar"][aria-controls]');
    const syncSidebarState = () => {
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', String(!document.body.classList.contains('sidebar-collapse')));
    };
    if (sidebarToggle) {
        syncSidebarState();
        new MutationObserver(syncSidebarState).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    document.querySelectorAll('.sidebar-menu > .nav-item > [aria-controls]').forEach((toggle) => {
        const syncTreeState = () => toggle.setAttribute('aria-expanded', String(toggle.closest('.nav-item')?.classList.contains('menu-open')));
        syncTreeState();
        toggle.addEventListener('click', () => window.requestAnimationFrame(syncTreeState));
        const item = toggle.closest('.nav-item');
        if (item) new MutationObserver(syncTreeState).observe(item, { attributes: true, attributeFilter: ['class'] });
    });

    document.addEventListener('focusin', (event) => {
        const focused = event.target;
        if (!(focused instanceof HTMLElement)) return;
        window.requestAnimationFrame(() => document.querySelectorAll('.toast-container').forEach((container) => {
            if (!(container instanceof HTMLElement) || container.contains(focused)) return;
            const target = focused.getBoundingClientRect();
            const overlay = container.getBoundingClientRect();
            const overlaps = target.left < overlay.right && target.right > overlay.left
                && target.top < overlay.bottom && target.bottom > overlay.top;
            if (!overlaps) return;
            if ((target.top + target.bottom) / 2 < window.innerHeight / 2) {
                container.style.setProperty('top', 'auto', 'important');
                container.style.setProperty('bottom', '1rem', 'important');
            } else {
                container.style.setProperty('top', '1rem', 'important');
                container.style.setProperty('bottom', 'auto', 'important');
            }
        }));
    });

    let lastDynamicFocus = null;
    document.addEventListener('focusin', (event) => {
        const control = event.target;
        if (!(control instanceof HTMLElement)) return;
        const panel = control.closest('#columnsPanel, #filtersPanel');
        const behaviorClass = ['field', 'label', 'aggregate', 'sort', 'break', 'summarize',
            'up', 'down', 'remove-column', 'control', 'operator', 'default', 'default-to',
            'required', 'remove-filter'].find((name) => control.classList.contains(name));
        lastDynamicFocus = {
            id: control.id || '',
            panelId: panel?.id || '',
            index: control.dataset.i ?? '',
            behaviorClass: behaviorClass || '',
        };
    });

    const restoreDynamicFocus = () => {
        if (document.activeElement !== document.body || !lastDynamicFocus) return;
        let replacement = lastDynamicFocus.id ? document.getElementById(lastDynamicFocus.id) : null;
        const panel = lastDynamicFocus.panelId ? document.getElementById(lastDynamicFocus.panelId) : null;
        if (!replacement && panel && lastDynamicFocus.behaviorClass) {
            replacement = panel.querySelector(`.${CSS.escape(lastDynamicFocus.behaviorClass)}[data-i="${CSS.escape(lastDynamicFocus.index)}"]`);
            if (!replacement && Number(lastDynamicFocus.index) > 0) {
                replacement = panel.querySelector(`.${CSS.escape(lastDynamicFocus.behaviorClass)}[data-i="${Number(lastDynamicFocus.index) - 1}"]`);
            }
        }
        if (!replacement && lastDynamicFocus.panelId === 'columnsPanel') replacement = document.getElementById('addColumn');
        if (!replacement && lastDynamicFocus.panelId === 'filtersPanel') replacement = document.getElementById('addFilter');
        if (replacement instanceof HTMLElement) replacement.focus();
    };

    const dynamicContentObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (!(node instanceof Element)) return;
            if (node.matches('table') || node.querySelector('table')) enhanceTables(node.parentElement || document);
            if (node.matches('.dataTables_filter, .dt-search') || node.querySelector('.dataTables_filter, .dt-search')) {
                enhanceTableSearch(node.parentElement || document);
            }
            enhanceStatusMessages(node.parentElement || document);
        }));
        window.requestAnimationFrame(restoreDynamicFocus);
    });
    dynamicContentObserver.observe(document.body, { childList: true, subtree: true });

    const controlName = (control) => {
        const label = control.labels?.[0]?.textContent?.trim();
        return label || control.getAttribute('aria-label') || control.name || 'This field';
    };

    const describeInvalidControl = (control) => {
        if (!control.id) control.id = `field-${Math.random().toString(36).slice(2, 10)}`;
        const messageId = `${control.id}-error`;
        let message = document.getElementById(messageId);
        if (!message) {
            message = document.createElement('div');
            message.id = messageId;
            message.className = 'invalid-feedback d-block';
            control.insertAdjacentElement('afterend', message);
        }
        message.textContent = control.validationMessage || `${controlName(control)} is invalid.`;
        const describedBy = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
        describedBy.add(messageId);
        control.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
        control.setAttribute('aria-invalid', 'true');
    };

    const clearInvalidControl = (control) => {
        control.removeAttribute('aria-invalid');
        if (!control.id) return;
        const messageId = `${control.id}-error`;
        document.getElementById(messageId)?.remove();
        const describedBy = (control.getAttribute('aria-describedby') || '')
            .split(/\s+/).filter((id) => id && id !== messageId);
        if (describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '));
        else control.removeAttribute('aria-describedby');
    };

    const showErrorSummary = (form) => {
        const invalidControls = Array.from(form.querySelectorAll(':invalid'));
        if (!invalidControls.length) return;
        invalidControls.forEach(describeInvalidControl);
        let summary = form.querySelector(':scope > .a11y-error-summary');
        if (!summary) {
            summary = document.createElement('div');
            summary.className = 'alert alert-danger a11y-error-summary';
            summary.setAttribute('role', 'alert');
            summary.setAttribute('tabindex', '-1');
            form.prepend(summary);
        }
        summary.replaceChildren();
        const heading = document.createElement('h2');
        heading.className = 'h4 alert-heading';
        heading.textContent = invalidControls.length === 1
            ? 'Correct the following error'
            : `Correct the following ${invalidControls.length} errors`;
        const list = document.createElement('ul');
        invalidControls.forEach((control) => {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = `#${CSS.escape(control.id)}`;
            link.textContent = `${controlName(control)}: ${control.validationMessage || 'Invalid value'}`;
            link.addEventListener('click', (event) => {
                event.preventDefault();
                control.focus();
            });
            item.append(link);
            list.append(item);
        });
        summary.append(heading, list);
        summary.focus();
    };

    const pendingSummaryForms = new WeakSet();
    document.addEventListener('invalid', (event) => {
        const control = event.target;
        if (!(control instanceof HTMLElement)) return;
        describeInvalidControl(control);
        const form = control.closest('form');
        if (form && !pendingSummaryForms.has(form)) {
            pendingSummaryForms.add(form);
            window.setTimeout(() => {
                pendingSummaryForms.delete(form);
                showErrorSummary(form);
            }, 0);
        }
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.noValidate || form.checkValidity()) return;
        event.preventDefault();
        showErrorSummary(form);
    }, true);

    document.addEventListener('input', (event) => {
        const control = event.target;
        if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
            // validity.valid, not checkValidity(): the latter fires 'invalid' on every
            // keystroke, which rebuilds the summary and pulls focus out of the field.
            if (control.validity.valid) clearInvalidControl(control);
        }
    });

    let modalTrigger = null;
    document.addEventListener('show.bs.modal', (event) => {
        modalTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const modal = event.target;
        if (!(modal instanceof HTMLElement)) return;
        if (!modal.hasAttribute('aria-labelledby')) {
            const title = modal.querySelector('.modal-title');
            if (title) {
                if (!title.id) title.id = `${modal.id || 'modal'}-title`;
                modal.setAttribute('aria-labelledby', title.id);
            }
        }
    });
    document.addEventListener('hidden.bs.modal', () => {
        if (modalTrigger?.isConnected) modalTrigger.focus();
        modalTrigger = null;
    });

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle || '');
            if (!(input instanceof HTMLInputElement)) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Show password' : 'Hide password';
            button.setAttribute('aria-pressed', String(!showing));
        });
    });

    document.querySelectorAll('[data-a11y-focus="true"]').forEach((message) => {
        message.setAttribute('tabindex', '-1');
        message.focus();
    });
    if (!document.querySelector('[data-a11y-focus="true"]')) {
        const serverError = document.querySelector('.alert-danger[role="alert"]');
        if (serverError instanceof HTMLElement) {
            serverError.setAttribute('tabindex', '-1');
            serverError.focus();
        }
    }

    const timeoutMeta = document.querySelector('meta[name="slo-session-timeout"]');
    if (timeoutMeta) {
        const timeoutSeconds = Number(timeoutMeta.content || 7200);
        const warningSeconds = Number(document.querySelector('meta[name="slo-session-warning"]')?.content || 120);
        const extendUrl = document.querySelector('meta[name="slo-session-extend-url"]')?.content || '';
        const logoutUrl = document.querySelector('meta[name="slo-session-logout-url"]')?.content || '';
        const csrfToken = document.querySelector('meta[name="slo-csrf-token"]')?.content || '';
        let deadline = Date.now() + timeoutSeconds * 1000;
        let countdownTimer = null;

        const dialog = document.createElement('dialog');
        dialog.className = 'a11y-session-dialog';
        dialog.setAttribute('aria-labelledby', 'session-warning-title');
        dialog.setAttribute('aria-describedby', 'session-warning-description');
        dialog.innerHTML = '<h2 id="session-warning-title">Your session is about to expire</h2>'
            + '<p id="session-warning-description">For your security, inactive sessions end automatically. '
            + 'Choose Continue session to keep working.</p>'
            + '<p><strong id="session-warning-countdown" role="timer" aria-live="polite"></strong></p>'
            + '<div class="a11y-session-actions"><button type="button" class="btn btn-primary" data-session-continue>Continue session</button>'
            + '<button type="button" class="btn btn-secondary" data-session-logout>Log out now</button></div>';
        document.body.append(dialog);
        const countdown = dialog.querySelector('#session-warning-countdown');
        const continueButton = dialog.querySelector('[data-session-continue]');

        const announceTime = () => {
            const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            const minutes = Math.floor(seconds / 60);
            const remainder = seconds % 60;
            countdown.textContent = `Session expires in ${minutes}:${String(remainder).padStart(2, '0')}.`;
            if (seconds <= 0) {
                clearInterval(countdownTimer);
                fetch(logoutUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: csrfToken })
                }).finally(() => { window.location.assign(logoutUrl.replace(/logout\.php.*$/, 'login.php?expired=1')); });
            }
        };
        const scheduleWarning = () => {
            clearTimeout(window.__sloSessionWarningTimer);
            window.__sloSessionWarningTimer = window.setTimeout(() => {
                if (!dialog.open) dialog.showModal();
                announceTime();
                countdownTimer = window.setInterval(announceTime, 1000);
                continueButton.focus();
            }, Math.max(0, (timeoutSeconds - warningSeconds) * 1000));
        };
        const extendSession = async () => {
            continueButton.disabled = true;
            try {
                const response = await fetch(extendUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                    body: new URLSearchParams({ csrf_token: csrfToken })
                });
                if (response.status === 401) {
                    // The session already ended; the login page says why.
                    const data = await response.json().catch(() => ({}));
                    if (data.error === 'session_ended' && data.login_url) {
                        window.location.href = data.login_url;
                        return;
                    }
                }
                if (!response.ok || response.redirected) throw new Error('Unable to extend session');
                deadline = Date.now() + timeoutSeconds * 1000;
                clearInterval(countdownTimer);
                dialog.close();
                scheduleWarning();
            } catch (error) {
                countdown.textContent = 'The session could not be extended. Save your work and log in again.';
            } finally {
                continueButton.disabled = false;
            }
        };
        dialog.addEventListener('cancel', (event) => event.preventDefault());
        continueButton.addEventListener('click', extendSession);
        dialog.querySelector('[data-session-logout]').addEventListener('click', () => {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = logoutUrl;
            const token = document.createElement('input');
            token.type = 'hidden'; token.name = 'csrf_token'; token.value = csrfToken;
            form.append(token); document.body.append(form); form.submit();
        });
        scheduleWarning();
    }

    // A session that has ended (2 hours idle or 20 hours after sign-in) answers background
    // requests with 401 "session_ended". Go to the login page, which explains why, instead
    // of letting tables report it as a DataTables "Ajax error".
    if (window.jQuery) {
        window.jQuery.ajaxPrefilter((options) => {
            const original = options.error;
            options.error = function (xhr, ...rest) {
                if (xhr.status === 401 && xhr.responseJSON?.error === 'session_ended') {
                    window.location.href = xhr.responseJSON.login_url;
                    return;
                }
                [].concat(original || []).forEach((handler) => handler.call(this, xhr, ...rest));
            };
        });
    }
})();
