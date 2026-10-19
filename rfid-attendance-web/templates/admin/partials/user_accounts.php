<section class="section live-results" id="user-results" aria-live="polite" aria-busy="false">
    <div class="section-heading"><h2>Accounts</h2><span class="muted">Showing <?= e($userPagination['from']) ?>–<?= e($userPagination['to']) ?> of <?= e($userPagination['total']) ?></span></div>
    <div class="table-wrap"><table data-bulk-type="user" data-no-datatable><thead><tr><th>User</th><th>Role</th><th>Department / program</th><th>Section</th><th>RFID</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($users as $person): ?><tr>
            <td><div class="user-identity-cell">
                <?php if (!empty($person['profile_image_path'])): ?><img class="user-avatar" src="<?= e(url($person['profile_image_path'])) ?>" alt="" loading="lazy"><?php else: ?><span class="user-avatar user-avatar-placeholder" aria-hidden="true"><?= e(mb_substr($person['first_name'], 0, 1) . mb_substr($person['last_name'], 0, 1)) ?></span><?php endif; ?>
                <span><a class="user-profile-link entity-user-trigger" data-entity-user data-user-id="<?= e($person['id']) ?>" data-user-name="<?= e($person['first_name'] . ' ' . $person['last_name']) ?>" data-user-school-id="<?= e($person['school_id']) ?>" data-user-role="<?= e($person['role_name']) ?>" data-user-email="<?= e($person['email']) ?>" href="<?= e(url('/admin/?page=profile&id=' . $person['id'])) ?>"><strong><?= e($person['first_name'] . ' ' . $person['last_name']) ?></strong></a><br><span class="muted"><?= e($person['school_id'] . ' · ' . $person['email']) ?></span></span>
            </div></td>
            <td><?= e($person['role_names_label'] ?? $person['role_name']) ?></td><td><?= e($person['department_name'] ?? '—') ?><br><span class="muted"><?= e($person['program_name'] ?? '—') ?></span></td><td><?= e($person['section_name'] ?? '—') ?></td><td><code><?= e($person['uid'] ?? 'Not assigned') ?></code></td>
            <td><?php $statusIconValue = $person['status']; require BASE_PATH . '/templates/admin/partials/status_icon.php'; ?></td>
            <td><button type="button" class="icon-action-button" data-manage-user data-user-id="<?= e($person['id']) ?>" aria-haspopup="dialog" aria-label="Manage <?= e($person['first_name'] . ' ' . $person['last_name']) ?>" title="Manage user"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg></button></td>
        </tr><?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="7" class="empty-state">No users match the current search and filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($userPagination['pages'] > 1): ?>
        <nav class="server-pagination" aria-label="User result pages">
            <?php for ($pageNumber = 1; $pageNumber <= $userPagination['pages']; $pageNumber++): ?>
                <?php $query = array_filter(['page' => 'users', 'q' => $filters['q'], 'role_id' => $filters['role_id'], 'department_id' => $filters['department_id'], 'program_id' => $filters['program_id'], 'section_id' => $filters['section_id'], 'status' => $filters['status'], 'user_page' => $pageNumber], static fn($value): bool => $value !== '' && $value !== 0); ?>
                <a class="<?= $pageNumber === $userPagination['page'] ? 'is-active' : '' ?>" href="<?= e(url('/admin/?' . http_build_query($query))) ?>" data-live-page="<?= e($pageNumber) ?>" <?= $pageNumber === $userPagination['page'] ? 'aria-current="page"' : '' ?>><?= e($pageNumber) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
