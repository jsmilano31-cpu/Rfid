<?php
$canManageReports = \App\Core\Auth::can('manage_reports');
$canViewAuditLogs = \App\Core\Auth::can('view_audit_logs');
?>
<div class="section-navigation-page" data-section-navigation>
    <header class="page-header">
        <div><p class="eyebrow">Print-friendly summaries & exports</p><h1>Reports & compliance audit</h1><p>Operational attendance, event, compliance, and management audit summaries.</p></div>
        <?php if ($canManageReports): ?><div class="page-header-actions">
            <a class="button button-secondary" href="<?= e(url('/admin/?page=reports&export=compliance_csv')) ?>">
                <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 14v2h12v-2M10 3v9M6 8l4 4 4-4"/></svg>
                Export compliance CSV
            </a>
            <button class="button button-primary" onclick="window.print()">
                <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 6V3h10v3M5 14H3a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1h-2M5 11h10v6H5z"/></svg>
                Print report
            </button>
        </div><?php endif; ?>
    </header>
    <div class="section-navigation-layout">
        <?php
        $sectionNavigationLabel = 'Reports page sections';
        $sectionNavigation = [];
        if ($canManageReports) {
            $sectionNavigation[] = ['id' => 'reports-class-attendance', 'label' => 'Class attendance'];
            $sectionNavigation[] = ['id' => 'reports-event-attendance', 'label' => 'Event attendance'];
            $sectionNavigation[] = ['id' => 'reports-deficiencies', 'label' => 'Deficiency report'];
        }
        if ($canViewAuditLogs) $sectionNavigation[] = ['id' => 'reports-audit-trail', 'label' => 'Audit trail'];
        require BASE_PATH . '/templates/admin/partials/section_navigation.php';
        ?>
        <div class="section-navigation-content">
            <?php if ($canManageReports): ?><section class="card section-scroll-target" id="reports-class-attendance" data-section-target>
                <h2>Class attendance summary</h2>
                <div class="table-wrap"><table><thead><tr><th>Course</th><th>Section</th><th>Status</th><th>Total</th></tr></thead><tbody>
                    <?php foreach ($attendanceSummary as $row): ?><tr><td><?= e($row['course_code']) ?></td><td><?= e($row['section_name']) ?></td><td><span class="badge badge-<?= e(strtolower($row['status'])) ?>"><?= e($row['status']) ?></span></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section><?php endif; ?>

            <?php if ($canManageReports): ?><section class="card section section-scroll-target" id="reports-event-attendance" data-section-target>
                <h2>Event attendance summary</h2>
                <div class="table-wrap"><table><thead><tr><th>Event</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody>
                    <?php foreach ($eventSummary as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['event_date']) ?></td><td><span class="badge badge-<?= e(strtolower($row['status'])) ?>"><?= e($row['status']) ?></span></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section><?php endif; ?>

            <?php if ($canManageReports): ?><section class="card section section-scroll-target" id="reports-deficiencies" data-section-target>
                <div class="section-heading"><div><h2>Compliance & deficiency report</h2><p class="muted">High-performance query indexed for 15,000+ students. Loaded in paginated batches.</p></div><span class="muted"><?= e($reportPagination['total']) ?> total students</span></div>
                <div class="table-wrap"><table data-no-datatable><thead><tr><th>Student</th><th>Section</th><th>Absences</th><th>Tardies</th><th>Tardy equiv</th><th>Events</th><th>Recollections</th><th>Total</th></tr></thead><tbody>
                    <?php foreach ($compliance as $row): ?><tr><td><a href="<?= e(url('/admin/?page=profile&id=' . $row['id'])) ?>" class="entity-user-trigger" data-entity-user data-user-id="<?= e($row['id']) ?>" data-user-name="<?= e($row['student_name']) ?>" data-user-school-id="<?= e($row['school_id']) ?>" data-user-role="Student"><strong><?= e($row['student_name']) ?></strong></a><br><span class="muted"><?= e($row['school_id']) ?></span></td><td><?= e($row['section_name'] ?? 'Unassigned') ?></td><td><?= e($row['absences']) ?></td><td><?= e($row['tardies']) ?></td><td><?= e($row['tardy_equivalent']) ?></td><td><?= e($row['missed_events']) ?></td><td><?= e($row['missed_recollections']) ?></td><td><span class="badge <?= $row['total_deficiencies'] > 0 ? 'badge-absent' : 'badge-active' ?>"><?= e($row['total_deficiencies']) ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php if ($reportPagination['pages'] > 1): ?><nav class="server-pagination" aria-label="Compliance report pages"><?php for ($pageNumber = 1; $pageNumber <= $reportPagination['pages']; $pageNumber++): ?><a class="<?= $pageNumber === $reportPagination['page'] ? 'is-active' : '' ?>" href="<?= e(url('/admin/?page=reports&report_page=' . $pageNumber . '#reports-deficiencies')) ?>" <?= $pageNumber === $reportPagination['page'] ? 'aria-current="page"' : '' ?>><?= e($pageNumber) ?></a><?php endfor; ?></nav><?php endif; ?>
            </section><?php endif; ?>

            <?php if ($canViewAuditLogs): ?><section class="card section section-scroll-target" id="reports-audit-trail" data-section-target>
                <div class="section-heading"><div><h2>Management audit trail</h2><p class="muted">Review who changed managed records, including attendance policy updates and resets.</p></div><span class="muted"><?= e($auditPagination['total']) ?> logged actions</span></div>
                <form class="audit-filter-form" method="get" action="<?= e(url('/admin/')) ?>">
                    <input type="hidden" name="page" value="reports">
                    <label class="field"><span>Search actions or users</span><input type="search" name="audit_q" value="<?= e($auditFilters['query']) ?>" minlength="2" placeholder="Action, entity, school ID, or name"></label>
                    <label class="field"><span>Entity type</span><select name="audit_entity" data-no-search><option value="">All entities</option><?php foreach ($auditEntities as $entity): ?><option value="<?= e($entity) ?>" <?= $auditFilters['entity'] === $entity ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $entity))) ?></option><?php endforeach; ?></select></label>
                    <button class="button button-primary">Apply filters</button>
                    <a class="button button-secondary" href="<?= e(url('/admin/?page=reports#reports-audit-trail')) ?>">Clear</a>
                </form>
                <div class="table-wrap"><table data-no-datatable><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Record</th><th>Details</th><th>IP</th></tr></thead><tbody>
                    <?php if (!$auditRows): ?><tr><td colspan="7" class="empty-table-cell">No audit entries match these filters.</td></tr><?php endif; ?>
                    <?php foreach ($auditRows as $row): ?><tr><td><?= e(format_datetime($row['created_at'])) ?></td><td><?php if ($row['user_id']): ?><a href="<?= e(url('/admin/?page=profile&id=' . $row['user_id'])) ?>" class="entity-user-trigger" data-entity-user data-user-id="<?= e($row['user_id']) ?>" data-user-name="<?= e($row['actor_name']) ?>" data-user-school-id="<?= e($row['school_id']) ?>"><strong><?= e($row['actor_name'] ?: 'System') ?></strong></a><?php else: ?><strong><?= e($row['actor_name'] ?: 'System') ?></strong><?php endif; ?><?php if ($row['school_id']): ?><br><span class="muted"><?= e($row['school_id']) ?></span><?php endif; ?></td><td><?= e(ucwords(str_replace('_', ' ', $row['action']))) ?></td><td><?= e(ucwords(str_replace('_', ' ', $row['entity_type']))) ?></td><td><?= e($row['entity_id'] ?? '—') ?></td><td class="audit-details-cell"><?= e($row['details'] ?: '—') ?></td><td><?= e($row['ip_address'] ?: 'Local/system') ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php if ($auditPagination['pages'] > 1): ?>
                    <?php $auditFirstPage = max(1, $auditPagination['page'] - 3); $auditLastPage = min($auditPagination['pages'], $auditPagination['page'] + 3); ?>
                    <nav class="server-pagination" aria-label="Audit trail pages">
                        <?php if ($auditPagination['page'] > 1): ?><?php $auditPageQuery = http_build_query(['page' => 'reports', 'audit_q' => $auditFilters['query'], 'audit_entity' => $auditFilters['entity'], 'audit_page' => $auditPagination['page'] - 1]); ?><a href="<?= e(url('/admin/?' . $auditPageQuery . '#reports-audit-trail')) ?>">Previous</a><?php endif; ?>
                        <?php for ($pageNumber = $auditFirstPage; $pageNumber <= $auditLastPage; $pageNumber++): ?><?php $auditPageQuery = http_build_query(['page' => 'reports', 'audit_q' => $auditFilters['query'], 'audit_entity' => $auditFilters['entity'], 'audit_page' => $pageNumber]); ?><a class="<?= $pageNumber === $auditPagination['page'] ? 'is-active' : '' ?>" href="<?= e(url('/admin/?' . $auditPageQuery . '#reports-audit-trail')) ?>" <?= $pageNumber === $auditPagination['page'] ? 'aria-current="page"' : '' ?>><?= e($pageNumber) ?></a><?php endfor; ?>
                        <?php if ($auditPagination['page'] < $auditPagination['pages']): ?><?php $auditPageQuery = http_build_query(['page' => 'reports', 'audit_q' => $auditFilters['query'], 'audit_entity' => $auditFilters['entity'], 'audit_page' => $auditPagination['page'] + 1]); ?><a href="<?= e(url('/admin/?' . $auditPageQuery . '#reports-audit-trail')) ?>">Next</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section><?php endif; ?>
        </div>
    </div>
</div>
