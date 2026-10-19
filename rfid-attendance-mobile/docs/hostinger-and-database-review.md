# Hostinger and student database review

Initial review performed September 5, 2026, using HTTPS and authenticated FTPS reads. Remote desktop MySQL authentication was rejected during that audit, so its first findings used the **August 29 Hostinger import export, local migrations, and PHP services**. A September 6 implementation and deployment subsequently verified the live schema through a temporary protected server-side reader; the current production results are recorded at the end of this document.

## Hosting checks

| Check | Result |
| --- | --- |
| `https://jsmkj.space/` | Reachable; redirects to `/auth/login.php`, which returns HTTP 200. This confirms the login page, not authenticated database workflows. |
| HTTP entry point | Redirects to HTTPS. |
| Login session cookie | Observed `Secure`, `HttpOnly`, and `SameSite=Lax`. |
| Public private-file probes | `/.env` and `/.git/HEAD`: 403. `/config/database.php`: 404. |
| FTPS | Login and directory/file reads succeed on the configured `145.79.14.215:21` endpoint using TLS 1.3 and protected data transfers. No upload was attempted. |
| Domain layout | Public PHP/assets are under `domains/jsmkj.space/public_html`; `.env`, `config`, `src`, and `templates` are one level above the public root. |
| Production environment | Deployed values match `.env.hostinger` for production mode, debug disabled, app URL, public directory, and all database connection fields. FTP credentials are not copied into the deployed `.env`. |
| Web database host | `DB_HOST=localhost`, appropriate to the existing server-side PHP arrangement. Remote developer access uses a separate hostname. |
| Remote MySQL | `srv1983.hstgr.io:3306` resolves to `153.92.15.84` and is reachable, but rejects the stored database credentials with error 1045. The website/FTP endpoint also exposes MySQL and rejects the same credentials remotely. No table metadata or row counts could be retrieved. |
| Environment files in Git | `.env` and `.env.hostinger` are ignored and are not tracked. |

The server saw the audit client as `143.44.168.212`. In Hostinger, confirm that this client IP is allowed for the selected database and that the hostname shown in Remote MySQL is still `srv1983.hstgr.io`. Error 1045 alone does not distinguish an incorrect password, wrong server/account, or host-specific access grant. The matching deployed `.env` does not prove remote access grants are valid. Hostinger documents where to configure the client IP and find the database hostname in its [Remote MySQL guide](https://www.hostinger.com/support/1583546-how-to-set-up-remote-mysql-access-in-hostinger/).

## Initial deployment findings (September 5)

These findings describe the state before the mobile API and physical RFID deployment. They are retained as audit history; the deployed API, synchronized PHP services, and scanner endpoints supersede the implementation gaps listed here.

1. **The FTPS certificate exception in the upload script is outdated.** `rfid-attendance-web/scripts/deploy-hostinger.ps1` connects to an IP and only accepts a name mismatch when the certificate subject matches `CN=*.hstgr.io*`. The certificate now has `CN=hostinger.com`, with `*.hstgr.io` among its subject alternative names. Its current logic would reject that name mismatch. The audit authenticated successfully with certificate-chain and provider-hostname verification against `hostinger.com`; it did not run the deployment script or disable certificate validation for credential transfers. Update the deployment transport to use a properly verified provider TLS identity before the next upload.
2. **Production and local authentication helpers differ.** Deployed `src/Core/Auth.php` lacks local `canAny()` and `requireAnyPermission()` helpers and the newer academic-calendar, grading-settings, and audit-log permission entries. Several inspected core/service files match after normalizing line endings, including `Database.php`, `bootstrap.php`, `GradeService.php`, and `ComplianceService.php`. Reconcile the deployment as a coherent version before adding API controllers that depend on the local helpers.
3. **Mobile API folders are placeholders.** Deployed `api/v1/auth`, `attendance`, `events`, and `devices` contain only `.gitkeep`. `/api/v1/` returns 403 because there is no usable directory index, not because a functioning JSON API has authenticated the request.
4. **The server's `.htaccess` is not in the local public directory.** The inspected deployed file contains `RewriteEngine Off`. Proposed API paths should use explicit PHP entry points unless routing is deliberately introduced later. Avoid assuming clean-path routes already work.

## Database coverage

`rfid-attendance-web/database/schema.sql` now defines **45 tables**, including `rfid_registration_requests` and `device_scan_logs`. The August 29 Hostinger import export also defines **45 tables**, but it predates those scanner tables and instead includes `grading_settings` and `event_images`; these have PHP migrations in `database/migrations`. The export includes `events.is_announcement`, which the base schema omits. Applying the complete migration history produces the **47-table** live structure documented below, so neither the base schema nor the older export is a complete description by itself.

These files are schema evidence, not commands to execute against production. In particular, the base schema includes table drops and is not an incremental migration.

| Student feature | Tables and relationship | API/UI implication |
| --- | --- | --- |
| Identity and permissions | `users.role_id` is the primary role; `user_roles` joins `roles`, `role_permissions`, and `permissions`. | Return an explicit student profile with primary role, all assigned roles, and permitted actions. Scope data to the authenticated user; do not expose `users.*` or password hashes. |
| Academic profile | `users` optionally references `departments`, `programs`, and `sections`. | Nullable department/program/section and middle name must be supported. Section membership alone is not class enrollment. |
| RFID registration | `rfid_cards.user_id` is nullable and unique; card states are `active`, `inactive`, `replaced`. `rfid_assignment_history` retains assignment history. | Return registration state separately from a masked card identifier. No database or FTP credentials belong in Android. |
| Enrolled subjects | `class_enrollments.student_id` → `users`; `class_enrollments.class_offering_id` → `class_offerings` → `courses`, `sections`, `semesters` → `school_years`. | Use **class offering ID**, not course code, as the stable identity for a student's subject. Respect enrollment state `enrolled`/`dropped`/`completed` and offering state. |
| Schedule | `class_schedules` belongs to an offering; `teacher_assignments` relates multiple teachers to the offering; rooms can be set on the schedule or offering. | One offering can have multiple meeting times and teachers. Resolve room with `COALESCE(class_schedules.room_id, class_offerings.room_id)`, as the web app does. Include teacher time off and department days off where relevant. |
| Class attendance | `attendance_sessions` is unique per offering/date. `attendance_records` is unique per offering/student/date. | Return nullable session, time-in/time-out, status, source, and date. Filter using the authenticated student, offering, and date range. |
| Events and announcements | `events`, `event_images`, `event_target_groups`, `event_participants`, `event_attendance`. | Student visibility currently comes from participant records. Preserve required participation, event state, optional end date, announcement flag, gallery, and check-out requirements. |
| Formation | `recollections`, participant and target-user/target-role tables, `recollection_attendance`, active enrollments and assignments. | Reuse `RecollectionService::involvementCondition`; visibility includes explicit participants, targeted roles/users, active class involvement, and creator. Do not filter only by the profile's home section. |
| Grades | `grades` is unique per offering/student; `grading_settings` controls enabled terms, weights, and passing threshold. | Preserve decimal scores, nullable terms, server-calculated overall, and `draft`/`finalized` states. |
| Compliance | `attendance_settings`, offering-specific policies, class attendance, required event participation/attendance, and formation attendance. | Compliance is derived by PHP services, not stored in a standalone compliance table. Return summary and per-subject results from the backend. |
| Inbox | `messages.sender_id` references a user; `message_recipients` is unique per message/user and stores `read_at`. | Return only addressed messages. Mark read by updating the authenticated recipient's row; reading a message must not change other recipients' states. |

## Changes required to the current Kotlin preview before real data

- Replace textual course IDs such as `IT301` with `classOfferingId`, `courseId`, and a separate display `courseCode`. Represent IDs as Kotlin `Long` within the API's documented range.
- Separate `Course`, `ClassOffering`, `ScheduleMeeting`, and `Teacher` models. The current single room/time/teacher string cannot represent the database's relationships.
- Replace integer grade fields and the preview's three-term average with nullable decimal values and server-provided overall. `GradeService::save()` applies institutional weights and rounds to two decimal places. The exported defaults enable midterm/final at 40%/60%, but **these are not verified current live settings**.
- Grade labels must distinguish `draft`, `finalized`, and missing/not-entered records. The preview's `Complete`/`In progress` labels are not database states.
- Use separate attendance status models: classes have `Present`, `Late`, `Absent`, `Excused`; events have `Attended`, `Late`, `Absent`, `Excused`, `Incomplete`; formation has `Attended`, `Late`, `Absent`, `Excused`. No attendance row is a nullable/pending UI state, not an enum value persisted in these tables.
- Physical scanner deployment migrates the source values to `rfid`, `manual`, and `system`; historical simulated RFID rows become `rfid` so existing reports remain continuous.
- Keep database `DATE` and `TIME` values structured. Class attendance uses date plus nullable time values; event and formation check-ins use `DATETIME`. The PHP application uses `Asia/Taipei`; the API must explicitly define timestamp offsets rather than assume the database session timezone has been verified.
- Split events and formation models or include an explicit activity kind plus real numeric ID; IDs overlap between their independent tables. Support multi-day events and cancelled/draft/open/closed states.
- Replace preview compliance values with API results. The general summary currently uses the global tardy threshold; per-subject compliance uses offering overrides. These scopes can produce different totals and should be reconciled in shared PHP rules rather than duplicated in Kotlin.
- Replace local message-read and email edits with authenticated writes, validation errors, and success responses. Student account/profile updates must continue to use the existing service validation and permissions.
- Do not include credential fields, raw `users.*` rows, scanner secrets, or administrative audit records in student responses.

## Original integration proposal

Android should call an **HTTPS JSON API on `jsmkj.space`**. The PHP API should reuse the existing database and PHP services with server-side authorization. Remote MySQL access from this PC is a development/inspection connection; it is not the Android connection architecture.

The following candidate student entry points were subsequently implemented and deployed under the stated base URL:

| Endpoint under `/api/v1/` | Purpose |
| --- | --- |
| `auth/login.php`, `auth/logout.php`, `auth/me.php` | Establish, end, and inspect the authenticated session. |
| `student/dashboard.php` | Student overview using shared service results. |
| `student/subjects.php`, `student/schedule.php` | Enrollments and structured meeting times. |
| `student/attendance.php` | Paginated attendance with offering/status/date filters. |
| `student/events.php`, `student/recollections.php` | Involvement-scoped activities and personal attendance. |
| `student/grades.php`, `student/compliance.php` | Authoritative grade and compliance values. |
| `student/messages.php`, `student/message-read.php` | Addressed messages and an idempotent recipient-scoped read action. |
| `student/profile.php` | Read profile and explicitly validated profile updates. |

The deployed mobile client uses the implemented HTTPS API contract and does not connect directly to MySQL. Authentication, authorization, and data scoping remain server-side concerns.

The September 6 server-side verification completed the structure check with read-only `information_schema` queries. It intentionally did not export personal records or claim live academic row counts.

## Evidence

- Local configuration and upload logic: `rfid-attendance-web/.env.hostinger`, `config/database.php`, `scripts/deploy-hostinger.ps1`.
- Local schema evidence: `rfid-attendance-web/database/schema.sql`, `database/exports/rfid_attendance_hostinger_import_20260829.sql`, `database/migrations/`.
- Application semantics: `src/Controllers/PortalController.php`, `src/Core/Auth.php`, `src/Services/GradeService.php`, `ComplianceService.php`, `RecollectionService.php`, and `EventService.php`.
- Frontend models: `rfid-attendance-mobile/app/src/main/java/com/example/campustrack/data/StudentRepository.kt`.
- Sanitized FTPS check results and schema-only extraction are in the workspace `.codex-tmp` directory. Passwords were kept in memory and were not included in this report.


## Live API and scanner verification — September 6, 2026

The mobile API is deployed at `https://jsmkj.space/rfid-attendance-web/public/`. FTPS upload verification passed for every deployed PHP file. Server-side MySQL authentication works: a live invalid-login request executed its database query and returned 401 as expected. The remote desktop MySQL login remains a separate unresolved 1045 failure.

A temporary random-path, secret-header-protected verifier ran a read-only transaction on Hostinger. It inspected **340 columns** and successfully executed the complete student portal query against an existing student, returning only section names rather than personal records. The verifier was deleted immediately; HTTP 404 confirmed removal. No production accounts or academic records were created or changed by verification.

Live schema contains **47 tables**. The physical RFID migration is applied: `rfid_registration_requests` and `device_scan_logs` exist, and class, event, and formation attendance sources are `rfid`, `manual`, or `system`. Columns are documented in `live-schema.json` (structure only).
