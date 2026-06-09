/*
 * Shared job-table interactions (Phase 40) — used by job_queue.php and
 * job_history.php: child-row expand/collapse, the cancel/retry actions, and the
 * job-detail modal with hand-rolled JSON highlighting. Live status polling stays
 * in job_queue.php (the history page has no active jobs).
 *
 * Requires modal.js (Modal.*) to be loaded.
 */
(function () {
    // Children expand/collapse
    document.querySelectorAll('[data-toggle-children]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var parentId = btn.dataset.toggleChildren;
            var rows     = document.querySelectorAll('[data-child-of="' + parentId + '"]');
            var showing  = rows.length > 0 && !rows[0].hidden;
            rows.forEach(function (r) { r.hidden = showing; });
            btn.innerHTML = showing ? btn.dataset.labelCollapsed : btn.dataset.labelExpanded;
        });
    });
})();

function jobAction(action, jobID, btn) {
    function proceed() {
        btn.disabled = true;
        btn.textContent = action === 'cancel' ? 'Cancelling…' : 'Retrying…';

        const body = new URLSearchParams({ action: action, job_id: jobID });

        fetch('api_jobs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                Modal.alert('Error: ' + (data.error || 'Unknown error'));
                btn.disabled = false;
                btn.textContent = action === 'cancel' ? 'Cancel' : 'Retry';
            }
        })
        .catch(function () {
            Modal.alert('Network error — please try again.');
            btn.disabled = false;
            btn.textContent = action === 'cancel' ? 'Cancel' : 'Retry';
        });
    }

    if (action === 'cancel') { Modal.confirm('Cancel this job?', proceed); return; }
    if (action === 'retry')  { Modal.confirm('Retry this job? It will be re-queued for processing.', proceed); return; }
    proceed();
}

/* ── Job detail modal with hand-rolled JSON highlighting ── */
function jqEscapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function jqJsonStr(v) {
    return (v === null || v === undefined) ? '(none)' : JSON.stringify(v, null, 2);
}
// Minimal JSON syntax highlighter — no external library.
function jqHighlightJson(json) {
    json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return json.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
        function (m) {
            var cls = 'json-num';
            if (/^"/.test(m))            cls = /:$/.test(m) ? 'json-key' : 'json-str';
            else if (/true|false/.test(m)) cls = 'json-bool';
            else if (/null/.test(m))       cls = 'json-null';
            return '<span class="' + cls + '">' + m + '</span>';
        }
    );
}
function jqTypeLabel(t) {
    return ({ image: 'Image', scene: 'Scene', story: 'Create Story', full_story: 'Full Story' })[t] || t;
}
function jqStatusClass(s) {
    return ({ pending: 'pending', running: 'running', completed: 'completed', failed: 'failed', cancelled: 'cancelled', completed_with_errors: 'warn' })[s] || '';
}
function jqJsonPanel(title, val) {
    var box = document.createElement('div'); box.className = 'json-panel';
    var h = document.createElement('div'); h.className = 'json-panel-title'; h.textContent = title;
    var pre = document.createElement('pre'); pre.className = 'json';
    pre.innerHTML = jqHighlightJson(jqJsonStr(val));
    box.appendChild(h); box.appendChild(pre);
    return box;
}
function showJobDetail(jobId) {
    fetch('api_jobs.php?action=detail&job_id=' + jobId)
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
            if (!res.ok) { Modal.alert((res.d && res.d.error) || 'Could not load job.'); return; }
            var d = res.d;
            var wrap = document.createElement('div'); wrap.className = 'job-detail';

            var meta = document.createElement('div'); meta.className = 'job-detail-meta';
            meta.innerHTML =
                '<strong>' + jqEscapeHtml(jqTypeLabel(d.job_type)) + '</strong> · ' +
                jqEscapeHtml(d.user_name || '—') +
                ' · <span class="badge badge-' + jqStatusClass(d.status) + '">' + jqEscapeHtml(d.status) + '</span>' +
                (d.story_title ? '<div class="jd-sub">' + jqEscapeHtml(d.story_title) + (d.scene_title ? ' › ' + jqEscapeHtml(d.scene_title) : '') + '</div>' : '') +
                (d.error_message ? '<div class="jd-err">' + jqEscapeHtml(d.error_message) + '</div>' : '');
            wrap.appendChild(meta);

            var panels = document.createElement('div'); panels.className = 'json-panels';
            panels.appendChild(jqJsonPanel('Input JSON', d.input_json));
            panels.appendChild(jqJsonPanel('Result JSON', d.result_json));
            wrap.appendChild(panels);

            var foot = document.createElement('div'); foot.className = 'job-detail-foot';
            foot.innerHTML = 'Created: ' + jqEscapeHtml(d.created_at || '—') +
                ' · Cost: ' + (d.cost_usd != null ? '$' + Number(d.cost_usd).toFixed(4) : '—');
            wrap.appendChild(foot);

            Modal.open({
                title: 'Job #' + d.job_id,
                body: wrap,
                buttons: [{ label: 'Close', className: 'btn-secondary', action: Modal.close }]
            });
        })
        .catch(function () { Modal.alert('Could not load job.'); });
}
