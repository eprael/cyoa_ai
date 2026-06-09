<?php
/**
 * Pagination helper (Phase 40) — shared by the gallery (index.php) and the Job
 * History page (job_history.php). Emits an accessible numbered pager that
 * preserves the current query params (genre/sort/filter/q) and adds &page=N.
 *
 * Kept as a tiny standalone include (a view helper) rather than living in
 * db_functions.php, which is reserved for data access.
 */

/** Build a page URL, merging $baseParams + page=N and dropping empty params. */
function pager_url(string $baseUrl, array $baseParams, int $page): string {
    $params = $baseParams;
    $params['page'] = $page;
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $baseUrl . (empty($params) ? '' : '?' . http_build_query($params));
}

/**
 * Render a numbered pager: « Prev 1 … 4 5 [6] 7 8 … 20 Next »
 * Returns '' when there is only a single page (nothing to navigate).
 *
 * @param int    $current     Current page (1-based; clamped to range).
 * @param int    $totalPages  Total number of pages.
 * @param array  $baseParams  Query params to preserve on every link.
 * @param string $baseUrl     Page URL (e.g. 'index.php'); '' = current path.
 */
function render_pager(int $current, int $totalPages, array $baseParams = [], string $baseUrl = ''): string {
    if ($totalPages <= 1) return '';
    $current = max(1, min($current, $totalPages));

    // Windowed page list: 1 … (current-2 .. current+2) … last
    $window = 2;
    $pages  = [1];
    for ($p = $current - $window; $p <= $current + $window; $p++) {
        if ($p > 1 && $p < $totalPages) $pages[] = $p;
    }
    $pages[] = $totalPages;
    $pages   = array_values(array_unique($pages));
    sort($pages);

    $html = '<nav class="pager" aria-label="Pagination">';

    // Prev
    $html .= ($current > 1)
        ? '<a class="pager-btn pager-step" rel="prev" href="' . htmlspecialchars(pager_url($baseUrl, $baseParams, $current - 1)) . '">&laquo; Prev</a>'
        : '<span class="pager-btn pager-step is-disabled" aria-hidden="true">&laquo; Prev</span>';

    $prev = 0;
    foreach ($pages as $p) {
        if ($prev && $p - $prev > 1) {
            $html .= '<span class="pager-ellipsis">…</span>';
        }
        $html .= ($p === $current)
            ? '<span class="pager-btn pager-num is-current" aria-current="page">' . $p . '</span>'
            : '<a class="pager-btn pager-num" href="' . htmlspecialchars(pager_url($baseUrl, $baseParams, $p)) . '">' . $p . '</a>';
        $prev = $p;
    }

    // Next
    $html .= ($current < $totalPages)
        ? '<a class="pager-btn pager-step" rel="next" href="' . htmlspecialchars(pager_url($baseUrl, $baseParams, $current + 1)) . '">Next &raquo;</a>'
        : '<span class="pager-btn pager-step is-disabled" aria-hidden="true">Next &raquo;</span>';

    return $html . '</nav>';
}
