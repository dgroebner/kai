        <?php if (!empty($allBounties)): ?>
            <div class="gamif-active-child-card" style="margin-top: 1rem; border: 2px solid var(--color-orange);">
                <div class="gamif-active-child-header" style="background: rgba(249, 115, 22, 0.1);">
                    <span class="gamif-avatar" style="font-size:1.4rem; width:2rem; height:2rem;">🛡️</span>
                    <div>
                        <strong>Schwarzes Brett</strong>
                        <span class="text-muted" style="font-size:0.8rem; display:block;">Offene Bounties & gerettete Aufgaben</span>
                    </div>
                    <span class="gamif-active-count"><?= count($allBounties) ?></span>
                </div>
                <ul class="gamif-active-task-list">
                    <?php foreach ($allBounties as $at): 
                        $isInProgress = $at['status'] === 'in_progress';
                    ?>
                        <li class="gamif-active-task-item <?= $isInProgress ? 'gamif-active-task-item--active' : '' ?>">
                            <span class="gamif-active-task-name"><?= htmlspecialchars($at['title'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="gamif-active-task-meta">
                                <?php if ($isInProgress): ?><span class="gamif-tag" style="font-size:0.7rem; padding:0.1rem 0.4rem;">▶ reserviert von <?= htmlspecialchars($at['claimed_name'] ?? '?', ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                <span class="gamif-tag gamif-tag--rescue" style="font-size:0.7rem; padding:0.1rem 0.4rem;">Von: <?= htmlspecialchars($at['origin_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></span>
                                <button type="button" class="btn btn-outline btn-sm js-delete-task-btn" style="padding:0.1rem 0.5rem; font-size:0.75rem; color:#ef4444;" data-task-id="<?= (int)$at['id'] ?>" data-title="<?= htmlspecialchars($at['title'], ENT_QUOTES, 'UTF-8') ?>" title="Aufgabe löschen">🗑️</button>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
