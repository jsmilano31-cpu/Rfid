<?php
$classSelectorOfferings = array_values($offerings ?? []);
$classSelectorRequired = $classSelectorRequired ?? true;
$classSelectorCourses = [];
foreach ($classSelectorOfferings as $offering) {
    $classSelectorCourses[(int) $offering['course_id']] = $offering['course_code'];
}
?>
<div class="class-offering-selector"
     data-class-offering-selector
     data-offerings="<?= e(json_encode($classSelectorOfferings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="class_offering_id" data-class-offering-id>
    <div class="form-grid">
        <label class="field"><span>Course code</span><select data-class-course data-searchable <?= $classSelectorRequired ? 'required' : '' ?>><option value="">Select a course code</option><?php foreach ($classSelectorCourses as $courseId => $courseCode): ?><option value="<?= e($courseId) ?>"><?= e($courseCode) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Course title</span><input data-class-course-title placeholder="Autofilled from course code" readonly></label>
        <label class="field"><span>Program</span><select data-class-program data-searchable required disabled><option value="">Select a course first</option></select></label>
        <label class="field"><span>Program name</span><input data-class-program-name placeholder="Autofilled from program" readonly></label>
        <label class="field"><span>Year & section</span><select data-class-section data-searchable required disabled><option value="">Select a program first</option></select></label>
        <label class="field"><span>School year</span><select data-class-school-year data-searchable required disabled><option value="">Select a year & section first</option></select></label>
        <label class="field"><span>Semester</span><select data-class-semester data-no-search required disabled><option value="">Select a school year first</option></select></label>
    </div>
    <p class="field-help" data-class-selector-status>Select the fields in order. Fields with one valid match are filled automatically.</p>
</div>
<?php unset($classSelectorRequired, $classSelectorOfferings, $classSelectorCourses); ?>
