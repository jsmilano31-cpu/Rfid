<?php
$lookupName = $lookupName ?? 'user_id';
$lookupLabel = $lookupLabel ?? 'Student';
$lookupPlaceholder = $lookupPlaceholder ?? 'Type a school ID or name';
$lookupContext = $lookupContext ?? 'users';
$lookupParentName = $lookupParentName ?? '';
$lookupSectionName = $lookupSectionName ?? '';
$lookupRequired = $lookupRequired ?? true;
$initialId = isset($lookupInitialId) ? (int) $lookupInitialId : (int) ($_GET['recipient_id'] ?? $_GET['recipient_user_id'] ?? $_GET['user_id'] ?? 0);
$initialLabel = $lookupInitialText ?? '';
if ($initialId > 0 && empty($initialLabel)) {
    try {
        $dbUserStmt = \App\Core\Database::connection()->prepare('SELECT id, school_id, first_name, last_name FROM users WHERE id = :id LIMIT 1');
        $dbUserStmt->execute(['id' => $initialId]);
        if ($foundUser = $dbUserStmt->fetch()) {
            $initialLabel = trim($foundUser['first_name'] . ' ' . $foundUser['last_name']) . ' (' . $foundUser['school_id'] . ')';
        }
    } catch (\Throwable $e) {}
}
?>
<div class="remote-user-lookup"
     data-remote-user-lookup
     data-endpoint="<?= e(url('/lookups/users.php')) ?>"
     data-context="<?= e($lookupContext) ?>"
     data-parent-name="<?= e($lookupParentName) ?>"
     data-section-name="<?= e($lookupSectionName) ?>"
     data-optional="<?= $lookupRequired ? '0' : '1' ?>">
    <label class="field">
        <span><?= e($lookupLabel) ?></span>
        <span class="lookup-input-shell">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
            <input type="search" data-remote-user-input placeholder="<?= e($lookupPlaceholder) ?>" value="<?= e($initialLabel) ?>" autocomplete="off" <?= $lookupRequired ? 'required' : '' ?>>
        </span>
    </label>
    <input type="hidden" name="<?= e($lookupName) ?>" data-remote-user-id value="<?= $initialId > 0 ? e((string) $initialId) : '' ?>">
    <div class="remote-user-results" data-remote-user-results hidden></div>
    <div class="remote-user-footer" data-remote-user-footer hidden>
        <button type="button" class="button button-small button-secondary" data-lookup-page="previous">Previous</button>
        <span class="muted" data-lookup-page-info></span>
        <button type="button" class="button button-small button-secondary" data-lookup-page="next">Next</button>
    </div>
    <p class="field-help" data-remote-user-status aria-live="polite">Type at least 2 characters to search. Results load 20 at a time.</p>
</div>
