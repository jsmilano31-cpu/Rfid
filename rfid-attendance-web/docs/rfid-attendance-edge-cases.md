# RFID Attendance Edge Cases and Workarounds

This document records known edge cases for classroom and event RFID attendance. It describes the expected behavior and practical safeguards to use when the related features are implemented.

## 1. Early scans between classes in the same room

### Scenario

A class in room PS31 ends at 9:20 AM. Another section has a class in PS31 at 9:30 AM. Students for the 9:30 AM class may tap their cards before their class begins.

### Risks

- A student could be recorded against the class that is still active.
- A student who is not enrolled in the active class could receive an unclear enrollment error.
- If one class ends exactly when another begins, inclusive start and end comparisons can match both classes.

### Workarounds

- Treat schedule intervals as half-open ranges: include the start time and exclude the end time. A 9:00–9:30 class accepts scans from 9:00 up to, but not including, 9:30.
- Resolve the card owner before producing the final error so the server can identify the student's upcoming enrolled class.
- If no class is active but a later class exists, reject the scan with the course and opening time: `Attendance is not open yet. IT301 starts at 09:30.`
- Allow an optional per-class early check-in window, such as five minutes, rather than accepting any scan made before the scheduled start.
- Never create an attendance record until the selected class, student enrollment, room, date, and permitted time window all match.

## 2. Continuous classes in the same room

### Scenario

A section or an individual student has consecutive classes in the same room. The student may assume the first tap covers both periods.

### Risks

- The student may be absent from the second class because they did not scan again.
- A scan at the exact transition time may match both classes.
- Two periods belonging to the same class offering on the same date may collapse into one attendance record.

### Workarounds

- Require a new scan for every distinct class period.
- Display the active course prominently so students can see which class receives the scan.
- Use half-open schedule boundaries to make the transition between consecutive classes unambiguous.
- Tie attendance to a specific class-session occurrence, not only to class offering, student, and calendar date.
- Keep repeated scans for the same session idempotent and preserve the first accepted time-in.
- Notify teachers when a new period begins so they can remind students to scan again.

## 3. Events that suspend classes

### Scenario

An administrator creates an event during normal class hours and declares that affected participants will have no classes during that period.

### Risks

- Teachers may finalize overlapping classes and create absences for students attending the event.
- A campus-wide event could accidentally suspend classes for users outside its intended audience.
- Cancelling or rescheduling the event could leave stale class suspensions.

### Workarounds

- Add a `Suspend classes during this event` setting.
- Apply the suspension only to the event's date range, time range, and audience.
- For a whole section, suspend overlapping class sessions for that section and prevent absence finalization.
- For individual event participants, keep the class active and mark those students excused rather than cancelling attendance for everyone.
- Show the event or suspension on teacher calendars and attendance screens.
- Remove or recalculate the suspension when the event is cancelled, rescheduled, or its audience changes.
- Record the administrator, reason, original schedule, and affected classes in the audit log.

## 4. Assigning a room scanner to an attendance-required event

### Scenario

When `Attendance required` is enabled for an event, the administrator selects an existing room scanner for event check-in and optional check-out.

### Risks

- The same scanner may have an active class and event at the same time.
- Two overlapping events may claim the same scanner.
- A normal classroom scan could be routed to the event by mistake.
- Disabling attendance could leave a stale event-scanner assignment.

### Workarounds

- Show the scanner dropdown only while `Attendance required` is enabled.
- List only active scanners with a configured API key, including device and room labels such as `RFID_PS31 — PS31`.
- Keep the scanner's permanent room assignment; store the event relationship separately as a temporary assignment.
- Clear the event scanner and check-in/check-out requirements when event attendance is disabled.
- Reject overlapping event assignments for the same scanner.
- If a class and event overlap, require either class suspension or a separate event scanner.
- Route scans in this order: pending card registration, open assigned event, current class, contextual rejection.
- Validate the event audience before recording a participant and enforce its check-in/check-out window on the server.

## 5. Hallway congestion and students waiting for confirmation

### Scenario

Students line up because the scanner waits for each HTTPS response and displays the result before accepting another card.

### Risks

- A multi-second request and display delay severely limits throughput.
- Network latency or temporary outages can stop the line.
- Students may tap repeatedly because they cannot tell whether the card was read.
- A power loss can discard scans waiting in memory.

### Workarounds

- Separate physical card capture from server confirmation.
- Give immediate feedback with one short beep and a brief yellow flash, then accept the next card within approximately 300–500 milliseconds.
- Queue scans locally and send them from a background ESP32 task.
- Persist pending scans in flash so a restart or Wi-Fi interruption does not lose them.
- Add a unique request ID to every scan and enforce idempotency on the server.
- Preserve the original scan timestamp and validate it against the relevant attendance window.
- Reuse HTTPS connections or submit small batches to reduce TLS overhead.
- Display queue state, such as `Ready | 3 pending`, rather than blocking on one student's result.
- Use distinct feedback for card captured, attendance confirmed, rejected scan, network failure, and full queue.
- Support multiple scanners for large rooms or events when physical tap speed remains the bottleneck.


### Multiple scanners serving one class or event

Large groups may use several entrances. All assigned devices should write to the same attendance session, and duplicate taps across devices must remain idempotent.

### Card remains on the reader or is tapped repeatedly

The firmware should suppress rapid duplicate reads for the same UID and context. The backend must still enforce uniqueness because retries, multiple scanners, and device restarts can bypass local cooldowns.

### Student taps in the wrong room

The server must resolve attendance from the scanner's room and current context. It should reject students who are not enrolled or targeted and, where possible, tell them the correct upcoming class rather than recording them elsewhere.

### Scanner has no room, an inactive room, or stale credentials

An unassigned or inactive scanner must not record class attendance. Authentication failure, inactive device, inactive room, and missing assignment should produce different administrative diagnostics while avoiding exposure of secret details on the public device response.

### Scanner is physically moved without updating its assignment

A scanner moved to another room could record students against the old room's schedule. Devices should display their assigned room at idle, and staff should verify it before use. Moving a scanner should require an audited room-assignment update.

### Wi-Fi interruption during a scan

The scanner should queue the scan and retry with exponential backoff. It must distinguish an unsent request from a response that was lost after the server committed the record; request IDs make either retry safe.

### Power loss while scans are queued

Pending scans need durable local storage with checksums and queue versioning. The device should remove an item only after receiving an authoritative server acknowledgement.

### Incorrect ESP32, PHP, or database time

Attendance decisions should use the server's configured campus timezone. Queued scan timestamps require ESP32 clock synchronization, a permitted clock-drift range, and rejection of implausible or backdated times. Database expiry comparisons should remain on one consistent clock.

### Delayed queued scan reaches a finalized session

A valid scan may have occurred before the deadline but arrive after a teacher finalized attendance. The server should place it in a review queue or reopen only that student's record through an audited correction workflow; it should not silently overwrite finalized attendance.

### Student enrolls, drops, or transfers during the day

Eligibility must be evaluated against the effective enrollment for the class session. Dropped students should not be accepted, while same-day administrative changes should not erase established attendance history.

### Lost, replaced, inactive, or reassigned RFID card

Only the currently active assignment should be accepted. Queued scans from a card that was reassigned after capture need a defined policy based on the assignment at scan time, with suspicious cases sent for review.

### One card is shared between students

RFID proves possession of a card, not identity. Repeated implausible scans, scans across distant rooms within an impossible interval, and unusually high card activity should be flagged for review. High-stakes attendance may require a second factor or teacher verification.

### Event check-in versus check-out ambiguity

For events requiring check-out, the first valid scan should check in and a later valid scan should check out. A minimum separation should prevent an accidental immediate second tap from checking the participant out. The display must clearly state which action occurred.

### Event is not open, already closed, or cancelled

The scanner must accept event attendance only during the configured window and while the event is open. Closing an attendance-required event may create absences; cancelling it must not create new absences.

### Event audience changes after attendance begins

Existing attendance history should be preserved. Newly targeted participants can be added, but removing participants with records should require an explicit audited decision rather than deleting their history.

### Event and class both valid for the same scanner

The system must never guess. Configuration should prevent the overlap, require class suspension, or use a dedicated scanner. If overlap is permitted, the scanner needs an explicit administrator-selected mode with a visible event/class label.

### Teacher finalizes attendance too early
Add guardrails e.g. Are you sure you want to end this class? (countdown timer on yes, 3 seconds)

### Department day off, campus closure, teacher time off, or cancelled class

The scanner should show that attendance is unavailable and state the applicable reason. Finalization must not generate absences on suspended dates or cancelled sessions.

## Recommended implementation order

1. Correct half-open class boundaries and add student-aware early-scan errors.
2. Add class-session occurrence identifiers for independently tracked periods.
3. Add event class suspension with audience-aware absence prevention.
4. Add event-scanner assignment and conflict validation.
5. Add an idempotent persistent ESP32 queue and background delivery.
6. Add operational dashboards for rejected, queued, delayed, and conflicting scans.
7. Add automated tests for every schedule boundary, event overlap, retry, and finalization rule above.
