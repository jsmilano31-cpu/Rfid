<?php $eventTargetOptions = \App\Core\Auth::can('manage_events') ? \App\Services\EventAudienceService::options() : ['departments' => [], 'programs' => [], 'sections' => []]; ?>
<fieldset class="event-target-builder" data-event-target-builder data-lookup-url="<?= e(url('/lookups/users.php')) ?>">
    <legend>Recipients</legend>
    <p class="muted">Combine groups, cohorts, and people. Each person is included only once.</p>
    <input type="hidden" name="targets_json" value='[{"type":"all_students","id":null,"roleFilter":"any"}]' data-event-target-json>
    <script type="application/json" data-event-target-options><?= json_encode($eventTargetOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <div class="event-target-chips" data-event-target-chips aria-label="Selected recipients"></div>
    <div class="event-target-controls">
        <label class="field"><span>Add</span><select data-event-target-type data-no-search>
            <option value="all_students">All students</option><option value="all_teachers">All teachers</option><option value="all_staff">All staff</option><option value="everyone">Everyone</option>
            <option value="department">Department</option><option value="program">Program</option><option value="section">Section</option><option value="user">Individual person</option>
        </select></label>
        <label class="field" data-event-target-entity-field hidden><span>Choose a group</span><select data-event-target-entity data-searchable data-search-placeholder="Search departments, programs, or sections…"><option value="">Select a group</option></select></label>
        <label class="field" data-event-target-role-field hidden><span>Include</span><select data-event-target-role data-no-search><option value="any">Everyone in this group</option><option value="student">Students only</option><option value="teacher">Teachers only</option><option value="staff">Staff only</option></select></label>
        <div class="field event-target-person-field" data-event-target-person-field hidden><span>Find a person</span><input type="search" data-event-target-person-search aria-label="Find a person" autocomplete="off" placeholder="Search name or school ID"><div class="event-target-person-results" data-event-target-person-results role="listbox" hidden></div></div>
        <button type="button" class="button button-secondary event-target-add" data-event-target-add>Add recipient</button>
    </div>
    <p class="field-help" data-event-target-status role="status" aria-live="polite">Recipients are combined when the event is saved.</p>
</fieldset>
<?php unset($eventTargetOptions); ?>
