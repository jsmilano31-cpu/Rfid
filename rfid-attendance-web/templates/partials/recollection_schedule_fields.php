<label class="field"><span>Venue</span><input name="venue" maxlength="180" required></label>
<fieldset class="activity-date-range" data-activity-date-range>
    <legend class="sr-only">Activity dates and times</legend>
    <div class="form-grid">
        <label class="field"><span>Date start</span><input type="date" name="date" required data-activity-start-date></label>
        <label class="field"><span>Date end</span><input type="date" name="end_date" required data-activity-end-date></label>
        <label class="field"><span>Time start</span><input type="time" name="start_time" required></label>
        <label class="field"><span>Time end</span><input type="time" name="end_time" required></label>
    </div>
    <p class="field-help">For a single-day activity, use the same start and end date. Each time belongs to its corresponding date.</p>
</fieldset>
