/* Global Modal component — replaces alert() / confirm() / prompt() site-wide.
 *
 * Public API:
 *   Modal.alert(msg, onClose?)
 *   Modal.confirm(msg, onConfirm, onCancel?)
 *   Modal.success({ heading, message, okLabel?, onClose?, jobQueue? })
 *   Modal.open({ title, body, buttons: [{label, className, action}] })
 *   Modal.close()
 */
const Modal = (() => {
    let overlay, box, titleEl, bodyEl, actionsEl;

    function inject() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="modal-box">' +
                '<div class="modal-title"></div>' +
                '<div class="modal-body"></div>' +
                '<div class="modal-actions"></div>' +
            '</div>';
        document.body.appendChild(overlay);

        box       = overlay.querySelector('.modal-box');
        titleEl   = overlay.querySelector('.modal-title');
        bodyEl    = overlay.querySelector('.modal-body');
        actionsEl = overlay.querySelector('.modal-actions');

        // Close on backdrop click
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) Modal.close();
        });

        // Escape key closes
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('modal-open')) {
                Modal.close();
            }
        });
    }

    function open(opts) {
        inject();
        var title   = opts.title   || '';
        var body    = opts.body    || '';
        var buttons = opts.buttons || [];

        titleEl.textContent = title;
        titleEl.style.display = title ? '' : 'none';   // no empty header gap

        if (typeof body === 'string') {
            bodyEl.innerHTML = body;
        } else {
            bodyEl.innerHTML = '';
            bodyEl.appendChild(body);
        }

        actionsEl.innerHTML = '';
        var firstBtn = null;
        buttons.forEach(function (btnDef) {
            var el = document.createElement('button');
            el.className = 'btn ' + (btnDef.className || '');
            el.textContent = btnDef.label;
            el.addEventListener('click', function () {
                if (btnDef.action) btnDef.action();
            });
            actionsEl.appendChild(el);
            if (!firstBtn) firstBtn = el;
        });

        overlay.classList.add('modal-open');
        if (firstBtn) setTimeout(function () { firstBtn.focus(); }, 50);
    }

    function close() {
        if (overlay) overlay.classList.remove('modal-open');
    }

    function alert(msg, onClose) {
        open({
            title: 'Notice',
            body: msg,
            buttons: [{
                label: 'OK',
                className: 'btn-primary',
                action: function () { close(); if (onClose) onClose(); }
            }]
        });
    }

    function confirm(msg, onConfirm, onCancel) {
        open({
            title: 'Confirm',
            body: msg,
            buttons: [
                {
                    label: 'Cancel',
                    className: 'btn-secondary',
                    action: function () { close(); if (onCancel) onCancel(); }
                },
                {
                    label: 'OK',
                    className: 'btn-primary',
                    action: function () { close(); if (onConfirm) onConfirm(); }
                }
            ]
        });
    }

    function success(opts) {
        opts = opts || {};
        var wrap = document.createElement('div');
        wrap.className = 'modal-success';

        var icon = document.createElement('div');
        icon.className = 'modal-success-icon';
        icon.innerHTML =
            '<svg viewBox="0 0 52 52" width="64" height="64" aria-hidden="true">' +
                '<circle class="ms-circle" cx="26" cy="26" r="24"/>' +
                '<path class="ms-check" fill="none" d="M15 27 l7.5 7.5 l15 -16"/>' +
            '</svg>';
        wrap.appendChild(icon);

        if (opts.heading) {
            var h = document.createElement('div');
            h.className = 'modal-success-heading';
            h.textContent = opts.heading;
            wrap.appendChild(h);
        }
        var m = document.createElement('div');
        m.className = 'modal-success-msg';
        m.textContent = opts.message || '';
        wrap.appendChild(m);

        // Optional Job Queue hint (job submissions): iconed line letting the user
        // know they can track the job's status, linking to the queue.
        if (opts.jobQueue) {
            var jq = document.createElement('div');
            jq.className = 'modal-success-jobqueue';
            jq.innerHTML =
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                    '<path d="M2 5v11.5a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V5"/>' +
                    '<line x1="8" y1="5" x2="16" y2="5"/>' +
                    '<line x1="8" y1="10" x2="16" y2="10"/>' +
                    '<line x1="8" y1="15" x2="16" y2="15"/>' +
                '</svg>' +
                '<span>Track its status anytime on the <a href="job_queue.php">Job Queue</a>.</span>';
            wrap.appendChild(jq);
        }

        open({
            title: '',
            body: wrap,
            buttons: [{
                label: opts.okLabel || 'OK',
                className: 'btn-primary',
                action: function () { close(); if (opts.onClose) opts.onClose(); }
            }]
        });
    }

    // Destructive confirmation with the same iconed layout as success(), but a red
    // warning mark and Cancel / Delete buttons.
    function confirmDanger(opts) {
        opts = opts || {};
        var wrap = document.createElement('div');
        wrap.className = 'modal-success modal-danger';

        var icon = document.createElement('div');
        icon.className = 'modal-success-icon';
        icon.innerHTML =
            '<svg viewBox="0 0 52 52" width="64" height="64" aria-hidden="true">' +
                '<circle class="ms-circle" cx="26" cy="26" r="24"/>' +
                '<path class="ms-mark" fill="none" d="M26 15 L26 31"/>' +
                '<circle class="ms-dot" cx="26" cy="38" r="2"/>' +
            '</svg>';
        wrap.appendChild(icon);

        if (opts.heading) {
            var h = document.createElement('div');
            h.className = 'modal-success-heading';
            h.textContent = opts.heading;
            wrap.appendChild(h);
        }
        var m = document.createElement('div');
        m.className = 'modal-success-msg';
        m.textContent = opts.message || '';
        wrap.appendChild(m);

        open({
            title: '',
            body: wrap,
            buttons: [
                {
                    label: opts.cancelLabel || 'Cancel',
                    className: 'btn-secondary',
                    action: function () { close(); if (opts.onCancel) opts.onCancel(); }
                },
                {
                    label: opts.confirmLabel || 'Delete',
                    className: 'btn-danger',
                    action: function () { close(); if (opts.onConfirm) opts.onConfirm(); }
                }
            ]
        });
    }

    return { open: open, close: close, alert: alert, confirm: confirm, success: success, confirmDanger: confirmDanger };
})();
