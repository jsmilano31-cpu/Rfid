<?php
$profileSchedule = $directory['schedule'];
$scheduleRows = $profileSchedule['rows'];
$scheduleViewId = $profileScheduleId;
$scheduleHeadingMode = 'section';
$scheduleTitle = 'Class schedule';
$scheduleDescription = $directory['is_teacher'] && $directory['is_student']
    ? 'Enrolled classes and teaching assignments for ' . $personName . '.'
    : ($directory['is_teacher'] ? 'Teaching assignments for ' : 'Enrolled classes for ') . $personName . '.';
$scheduleEmptyTitle = isset($profileSchedule['error']) ? 'Choose an academic term' : 'No classes scheduled';
$scheduleEmptyDescription = $profileSchedule['error'] ?? 'No meeting schedules are recorded for this person in the selected academic term.';
?>
<section class="section section-scroll-target" id="<?= e($profileScheduleId) ?>" data-section-target>
    <?php if ($profileSchedule['terms']): ?>
        <form class="card inline-form data-filter-form" method="get" action="<?= e(url((\App\Core\Auth::usesAdminArea() ? '/admin/' : '/index/') . '#' . $profileScheduleId)) ?>">
            <input type="hidden" name="page" value="profile">
            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
            <label class="field"><span>Academic term</span><select name="schedule_semester_id" aria-label="Academic term for this person's schedule">
                <?php foreach ($profileSchedule['terms'] as $term): ?><option value="<?= (int) $term['id'] ?>"<?= (int) $term['id'] === $profileSchedule['semester_id'] ? ' selected' : '' ?>><?= e($term['school_year_name'] . ' · ' . $term['name']) ?></option><?php endforeach; ?>
            </select></label>
            <button type="submit" class="button button-secondary">View schedule</button>
        </form>
    <?php endif; ?>
    <?php require BASE_PATH . '/templates/partials/portal_schedule_views.php'; ?>
</section>
<?php unset($scheduleEmptyTitle, $scheduleEmptyDescription); ?>
