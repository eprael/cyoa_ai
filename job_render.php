<?php
/**
 * Shared job-table rendering (Phase 40) — used by job_queue.php (today's jobs)
 * and job_history.php (older jobs, paginated). Holds the label/badge/duration
 * helpers plus render_job_table(), so the two pages render identical tables.
 *
 * Requires db_functions.php (for db_get_chain_cost) to be loaded already.
 */

function job_type_label(string $type): string {
    return match($type) {
        'image'        => 'Image',
        'scene'        => 'Scene',
        'full_story'   => 'Full Story',
        'story' => 'Create Story',
        default        => htmlspecialchars($type),
    };
}

function job_type_icon(string $type): string {
    return match($type) {
        'image'        => '&#128444;',   // 🖼
        'scene'        => '&#128196;',   // 📄
        'full_story'   => '&#128218;',   // 📚
        'story' => '&#128218;',   // 📚
        default        => '&#129302;',   // 🤖
    };
}

function status_badge(string $status): string {
    $map = [
        'pending'               => ['label' => 'Pending',          'class' => 'badge-pending'],
        'running'               => ['label' => 'Running',          'class' => 'badge-running'],
        'completed'             => ['label' => 'Completed',        'class' => 'badge-completed'],
        'failed'                => ['label' => 'Failed',           'class' => 'badge-failed'],
        'cancelled'             => ['label' => 'Cancelled',        'class' => 'badge-cancelled'],
        'completed_with_errors' => ['label' => 'Done &#9888;',     'class' => 'badge-warn'],
    ];
    $b = $map[$status] ?? ['label' => ucfirst($status), 'class' => ''];
    return '<span class="badge ' . $b['class'] . '">' . $b['label'] . '</span>';
}

function format_duration(?string $from, ?string $to): string {
    if (!$from || !$to) return '—';
    $secs = max(0, strtotime($to) - strtotime($from));
    if ($secs < 60)   return $secs . 's';
    if ($secs < 3600) return floor($secs / 60) . 'm ' . ($secs % 60) . 's';
    return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
}

function job_link(array $job): string {
    if (!empty($job['scene_id']) && !empty($job['story_id'])) {
        return '<a href="editor.php?action=edit_scene&storyID=' . (int)$job['story_id'] . '&sceneID=' . (int)$job['scene_id'] . '">Go to scene &rarr;</a>';
    }
    if (!empty($job['story_id'])) {
        return '<a href="editor.php?storyID=' . (int)$job['story_id'] . '">Go to story &rarr;</a>';
    }
    return '—';
}

/**
 * Render one jobs table (parent rows with collapsible child rows).
 * $childrenByParent maps parent_job_id => [child job rows].
 */
function render_job_table(array $jobList, bool $isAdmin, int $userID, array $childrenByParent): void { ?>
    <div class="queue-table-wrap">
        <table class="queue-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Story / Scene</th>
                    <?php if ($isAdmin): ?><th>User</th><?php endif; ?>
                    <th>Status</th>
                    <th>Cost</th>
                    <th>Submitted</th>
                    <th>Completed In</th>
                    <th>Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobList as $job):
                $jid      = (int)$job['job_id'];
                $status   = $job['status'];
                $jobOwner = (int)$job['user_id'];
                $canAct   = ($jobOwner === $userID || $isAdmin);
                $children = $childrenByParent[$jid] ?? [];
                $hasKids  = !empty($children);

                // Chain cost (root jobs only)
                $terminalStatuses = ['completed', 'failed', 'cancelled', 'completed_with_errors'];
                $chainAllDone = in_array($status, $terminalStatuses);
                foreach ($children as $c) {
                    if (!in_array($c['status'], $terminalStatuses)) { $chainAllDone = false; break; }
                }
                $chainCost = db_get_chain_cost($jid);
                $costLabel = '';
                if ($chainCost !== null && $chainCost > 0) {
                    $costLabel = '$' . number_format($chainCost, 4);
                    if (!$chainAllDone) $costLabel .= ' …';
                }

                // "Completed In" end time: the latest completion across the root job and
                // its children, so a parent's duration spans the whole chain (story +
                // images). Uses the stable completed_at, not the volatile updated_at.
                $chainEnd = $job['completed_at'] ?? null;
                foreach ($children as $c) {
                    if (!empty($c['completed_at']) && (empty($chainEnd) || $c['completed_at'] > $chainEnd)) {
                        $chainEnd = $c['completed_at'];
                    }
                }

                // Show parent as 'running' while any child is still pending/running
                $anyChildActive = $hasKids && in_array($status, ['completed', 'completed_with_errors']) && !$chainAllDone;
                $displayStatus  = $anyChildActive ? 'running' : $status;
            ?>
                <tr id="job-row-<?php echo $jid; ?>">
                    <td style="color:#999;font-size:0.8rem;"><?php echo $jid; ?></td>

                    <td class="job-type-cell">
                        <span class="job-type-icon"><?php echo job_type_icon($job['job_type']); ?></span>
                        <?php echo job_type_label($job['job_type']); ?>
                        <?php if ($hasKids): ?>
                            <br>
                            <button class="btn-toggle-children"
                                    data-toggle-children="<?php echo $jid; ?>"
                                    data-label-collapsed="&#9656; <?php echo count($children); ?> images"
                                    data-label-expanded="&#9662; <?php echo count($children); ?> images">
                                &#9656; <?php echo count($children); ?> image<?php echo count($children) !== 1 ? 's' : ''; ?>
                            </button>
                        <?php endif; ?>
                    </td>

                    <td class="job-target">
                        <?php if (!empty($job['story_title'])): ?>
                            <div><?php echo htmlspecialchars($job['story_title']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($job['scene_title'])): ?>
                            <div class="job-scene-name"><?php echo htmlspecialchars($job['scene_title']); ?></div>
                        <?php endif; ?>
                        <?php if (empty($job['story_title']) && empty($job['scene_title'])): ?>
                            <span style="color:#aaa;">—</span>
                        <?php endif; ?>
                        <?php if (!empty($job['error_message']) && $status === 'failed'): ?>
                            <div class="job-error" title="<?php echo htmlspecialchars($job['error_message']); ?>">
                                <?php echo htmlspecialchars($job['error_message']); ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <?php if ($isAdmin): ?>
                    <td style="font-size:0.82rem;">
                        <?php
                        $name = trim(($job['firstName'] ?? '') . ' ' . ($job['lastName'] ?? ''));
                        echo $name ? htmlspecialchars($name) : '<span style="color:#aaa;">—</span>';
                        ?>
                    </td>
                    <?php endif; ?>

                    <td class="job-status-cell"<?php echo ($status === 'failed' && !empty($job['error_message'])) ? ' title="' . htmlspecialchars($job['error_message']) . '"' : ''; ?>>
                        <?php echo status_badge($displayStatus); ?>
                        <?php if ($displayStatus === 'running'): ?>
                            <span class="spinner"></span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ($costLabel !== ''): ?>
                            <span class="job-cost"><?php echo htmlspecialchars($costLabel); ?></span>
                        <?php else: ?>
                            <span style="color:#ccc;font-size:0.8rem;">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="job-time">
                        <?php echo htmlspecialchars($job['created_at'] ?? ''); ?>
                    </td>

                    <td class="job-time">
                        <?php
                        // Use $displayStatus, not the raw DB $status: a parent whose
                        // story arc finished but whose image children are still running
                        // shows "Running", so its duration must stay blank until the
                        // whole chain is done.
                        if (in_array($displayStatus, ['completed', 'failed', 'cancelled', 'completed_with_errors'])) {
                            echo format_duration($job['started_at'] ?? null, $chainEnd);
                        } else {
                            echo '<span style="color:#aaa;">—</span>';
                        }
                        ?>
                    </td>

                    <td>
                        <?php echo job_link($job); ?>
                    </td>

                    <td>
                        <div class="job-actions">
                            <button class="btn-xs btn-view"
                                    onclick="showJobDetail(<?php echo $jid; ?>)">View</button>

                            <?php if ($status === 'pending' && $canAct): ?>
                                <button class="btn-xs btn-cancel"
                                        onclick="jobAction('cancel', <?php echo $jid; ?>, this)">
                                    Cancel
                                </button>
                            <?php endif; ?>

                            <?php if ($status === 'failed' && $canAct): ?>
                                <button class="btn-xs btn-retry"
                                        onclick="jobAction('retry', <?php echo $jid; ?>, this)">
                                    Retry
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php foreach ($children as $child):
                    $cid      = (int)$child['job_id'];
                    $cstatus  = $child['status'];
                    $cOwner   = (int)$child['user_id'];
                    $cCanAct  = ($cOwner === $userID || $isAdmin);
                ?>
                <tr id="job-row-<?php echo $cid; ?>" class="job-row-child"
                    data-child-of="<?php echo $jid; ?>" hidden>
                    <td class="job-child-indent">
                        <span class="job-child-marker">&#8627;</span><?php echo $cid; ?>
                    </td>

                    <td class="job-type-cell">
                        <span class="job-type-icon"><?php echo job_type_icon($child['job_type']); ?></span>
                        <?php echo job_type_label($child['job_type']); ?>
                    </td>

                    <td class="job-target">
                        <?php if (!empty($child['scene_title'])): ?>
                            <div class="job-scene-name"><?php echo htmlspecialchars($child['scene_title']); ?></div>
                        <?php elseif (!empty($child['story_title'])): ?>
                            <div><?php echo htmlspecialchars($child['story_title']); ?></div>
                        <?php else: ?>
                            <span style="color:#aaa;">—</span>
                        <?php endif; ?>
                        <?php if (!empty($child['error_message']) && $cstatus === 'failed'): ?>
                            <div class="job-error" title="<?php echo htmlspecialchars($child['error_message']); ?>">
                                <?php echo htmlspecialchars($child['error_message']); ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <?php if ($isAdmin): ?>
                    <td style="font-size:0.82rem;">
                        <?php
                        $cname = trim(($child['firstName'] ?? '') . ' ' . ($child['lastName'] ?? ''));
                        echo $cname ? htmlspecialchars($cname) : '<span style="color:#aaa;">—</span>';
                        ?>
                    </td>
                    <?php endif; ?>

                    <td class="job-status-cell"<?php echo ($cstatus === 'failed' && !empty($child['error_message'])) ? ' title="' . htmlspecialchars($child['error_message']) . '"' : ''; ?>>
                        <?php echo status_badge($cstatus); ?>
                        <?php if ($cstatus === 'running'): ?>
                            <span class="spinner"></span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($child['cost_usd'])): ?>
                            <span class="job-cost">$<?php echo number_format((float)$child['cost_usd'], 4); ?></span>
                        <?php else: ?>
                            <span style="color:#ccc;font-size:0.8rem;">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="job-time">
                        <?php echo htmlspecialchars($child['created_at'] ?? ''); ?>
                    </td>

                    <td class="job-time">
                        <?php
                        if (in_array($cstatus, ['completed', 'failed', 'cancelled', 'completed_with_errors'])) {
                            echo format_duration($child['started_at'] ?? null, $child['completed_at'] ?? null);
                        } else {
                            echo '<span style="color:#aaa;">—</span>';
                        }
                        ?>
                    </td>

                    <td>
                        <?php echo job_link($child); ?>
                    </td>

                    <td>
                        <div class="job-actions">
                            <button class="btn-xs btn-view"
                                    onclick="showJobDetail(<?php echo $cid; ?>)">View</button>
                            <?php if ($cstatus === 'pending' && $cCanAct): ?>
                                <button class="btn-xs btn-cancel"
                                        onclick="jobAction('cancel', <?php echo $cid; ?>, this)">
                                    Cancel
                                </button>
                            <?php endif; ?>
                            <?php if ($cstatus === 'failed' && $cCanAct): ?>
                                <button class="btn-xs btn-retry"
                                        onclick="jobAction('retry', <?php echo $cid; ?>, this)">
                                    Retry
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; // children ?>

            <?php endforeach; // parent jobs ?>
            </tbody>
        </table>
    </div>
<?php } // end render_job_table
