</main>
<footer class="footer">
    <p>&copy; <?= date('Y') ?> <?= e(app_config('lab_name')) ?> — AI-Assisted LIS</p>
</footer>
<?php if (is_logged_in()): ?>
<?php
$guide = guide_for_role();
$guideStorageKey = 'ailis_guide_seen_' . (user_role() ?? 'guest');
?>
<div class="guide-modal" id="role-guide-modal" hidden data-guide-id="<?= e($guide['id']) ?>" data-storage-key="<?= e($guideStorageKey) ?>" role="dialog" aria-modal="true" aria-labelledby="guide-title">
    <div class="guide-backdrop" data-guide-close></div>
    <div class="guide-panel" role="document">
        <header class="guide-header">
            <div>
                <p class="guide-kicker"><?= e(role_label()) ?> · training</p>
                <h2 id="guide-title"><?= e($guide['title']) ?></h2>
                <p class="guide-subtitle"><?= e($guide['subtitle']) ?></p>
            </div>
            <button type="button" class="guide-close" data-guide-close aria-label="Close guide">&times;</button>
        </header>

        <div class="guide-progress" aria-hidden="true">
            <?php foreach ($guide['steps'] as $i => $_step): ?>
                <span class="guide-dot<?= $i === 0 ? ' is-active' : '' ?>" data-guide-dot="<?= (int) $i ?>"></span>
            <?php endforeach; ?>
        </div>

        <div class="guide-body">
            <?php foreach ($guide['steps'] as $i => $step): ?>
                <section class="guide-step<?= $i === 0 ? ' is-active' : '' ?>" data-guide-step="<?= (int) $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                    <div class="guide-step-copy">
                        <p class="guide-step-num">Step <?= (int) ($i + 1) ?> of <?= count($guide['steps']) ?></p>
                        <h3><?= e($step['title']) ?></h3>
                        <p><?= e($step['body']) ?></p>
                        <?php if (!empty($step['cta'])): ?>
                            <a class="btn btn-small" href="<?= e(base_url($step['cta']['href'])) ?>"><?= e($step['cta']['label']) ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="guide-demo" data-demo="<?= e($step['demo']) ?>">
                        <?php
                        $demo = $step['demo'];
                        if (str_contains($demo, 'path') || $demo === 'dashboard-watch'):
                            $nodes = match ($demo) {
                                'staff-path' => ['Patient', 'Request', 'Collect', 'Report'],
                                'medtech-path' => ['Process', 'Encode', 'AI review', 'Approve', 'Release'],
                                'manager-path' => ['Dashboard', 'Users', 'Ranges', 'Backup', 'Audit'],
                                'dashboard-watch' => ['Delays', 'Review', 'AI flags', 'Actions'],
                                default => ['Start', 'Work', 'Done'],
                            };
                        ?>
                            <div class="demo-flow" style="--n: <?= count($nodes) ?>">
                                <?php foreach ($nodes as $ni => $node): ?>
                                    <div class="demo-node" style="--i: <?= (int) $ni ?>"><span><?= e($node) ?></span></div>
                                    <?php if ($ni < count($nodes) - 1): ?><div class="demo-arrow" style="--i: <?= (int) $ni ?>"></div><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($demo === 'patient-register'): ?>
                            <div class="demo-form">
                                <div class="demo-field is-typing" style="--i:0"><span class="demo-label">Last name</span><span class="demo-input"><i></i></span></div>
                                <div class="demo-field is-typing" style="--i:1"><span class="demo-label">First name</span><span class="demo-input"><i></i></span></div>
                                <div class="demo-field is-typing" style="--i:2"><span class="demo-label">Contact</span><span class="demo-input"><i></i></span></div>
                                <div class="demo-btn-pulse">Save patient</div>
                            </div>
                        <?php elseif ($demo === 'request-create'): ?>
                            <div class="demo-checklist">
                                <label class="demo-check" style="--i:0"><span></span> CBC</label>
                                <label class="demo-check" style="--i:1"><span></span> Chemistry</label>
                                <label class="demo-check" style="--i:2"><span></span> Urinalysis</label>
                                <div class="demo-btn-pulse">Create request</div>
                            </div>
                        <?php elseif (in_array($demo, ['specimen-collect', 'specimen-process'], true)): ?>
                            <div class="demo-status-track">
                                <?php foreach (['pending', 'collected', 'processing', 'completed'] as $si => $st): ?>
                                    <div class="demo-status" style="--i: <?= (int) $si ?>"><?= e($st) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($demo === 'result-encode'): ?>
                            <div class="demo-values">
                                <div class="demo-value" style="--i:0"><span>WBC</span><b>6.2</b></div>
                                <div class="demo-value" style="--i:1"><span>HGB</span><b>13.4</b></div>
                                <div class="demo-value" style="--i:2"><span>PLT</span><b>245</b></div>
                                <div class="demo-btn-pulse">Validate</div>
                            </div>
                        <?php elseif ($demo === 'ai-review'): ?>
                            <div class="demo-ai">
                                <div class="demo-ai-card">
                                    <span class="badge badge-warning demo-pulse">AI warning</span>
                                    <p>Anomaly flagged — review required</p>
                                    <div class="demo-ai-actions">
                                        <span class="demo-btn-pulse">Approve</span>
                                        <span class="demo-btn-ghost">Reject</span>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (in_array($demo, ['report-view', 'report-release'], true)): ?>
                            <div class="demo-report">
                                <div class="demo-report-sheet">
                                    <div class="demo-report-line" style="--i:0"></div>
                                    <div class="demo-report-line" style="--i:1"></div>
                                    <div class="demo-report-line short" style="--i:2"></div>
                                    <div class="demo-stamp"><?= $demo === 'report-release' ? 'RELEASED' : 'PRINT' ?></div>
                                </div>
                            </div>
                        <?php elseif ($demo === 'manage-users'): ?>
                            <div class="demo-users">
                                <div class="demo-user-row" style="--i:0"><span>staff</span><em>Administrative Staff</em></div>
                                <div class="demo-user-row" style="--i:1"><span>medtech</span><em>Medical Technologist</em></div>
                                <div class="demo-user-row" style="--i:2"><span>manager</span><em>Laboratory Manager</em></div>
                            </div>
                        <?php elseif ($demo === 'manage-ranges'): ?>
                            <div class="demo-ranges">
                                <div class="demo-range-bar" style="--i:0"><i style="--w:62%"></i><span>Normal</span></div>
                                <div class="demo-range-bar" style="--i:1"><i style="--w:78%"></i><span>Age/sex aware</span></div>
                                <div class="demo-range-bar" style="--i:2"><i style="--w:45%"></i><span>Hard block</span></div>
                            </div>
                        <?php elseif ($demo === 'backup-audit'): ?>
                            <div class="demo-backup">
                                <div class="demo-backup-ring"></div>
                                <p>Backup + audit trail</p>
                            </div>
                        <?php else: ?>
                            <div class="demo-flow" style="--n:3">
                                <div class="demo-node" style="--i:0"><span>Learn</span></div>
                                <div class="demo-arrow" style="--i:0"></div>
                                <div class="demo-node" style="--i:1"><span>Practice</span></div>
                                <div class="demo-arrow" style="--i:1"></div>
                                <div class="demo-node" style="--i:2"><span>Done</span></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <footer class="guide-footer">
            <label class="guide-dont-show"><input type="checkbox" id="guide-dont-show"> Don't auto-open next time</label>
            <div class="guide-nav">
                <button type="button" class="btn btn-secondary" id="guide-prev" disabled>Back</button>
                <button type="button" class="btn" id="guide-next">Next</button>
                <button type="button" class="btn" id="guide-done" hidden>Finish</button>
            </div>
        </footer>
    </div>
</div>
<?php endif; ?>
<script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
</body>
</html>
