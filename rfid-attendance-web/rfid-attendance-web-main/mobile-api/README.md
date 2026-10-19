# Student and teacher mobile API

Production base: `https://jsmkj.space/rfid-attendance-web/public/`.

Public wrappers deploy under `public_html/rfid-attendance-web/public/`. Implementation files deploy outside the document root, into `domains/jsmkj.space/mobile-api/src/`, and use the existing domain-level PHP bootstrap and database configuration. No migration is required for the verified live 45-table schema.

| Method | Relative endpoint | Purpose |
| --- | --- | --- |
| GET | health.php | API availability |
| GET | auth/session.php | Session state and CSRF token |
| POST | auth/login.php | School ID/email and password |
| POST | auth/register.php | Student self-registration, pending activation |
| POST | auth/logout.php | End current session |
| POST | auth/password.php | Change password with current password |
| GET | student/portal.php | Student dashboard data |
| GET | student/profile.php | Safe profile and masked RFID registration |
| POST | student/profile-update.php | Update contact email |
| POST | student/message-read.php | Mark an owned received message read |

Responses use `{ "data": ... }` or `{ "error": { "code": ..., "message": ... } }`. POST bodies are JSON and require `X-CSRF-Token` from the session endpoint. Preserve the secure HttpOnly `campustrack_mobile` cookie. Login/password changes rotate the session; use the new CSRF token from their response. A 419 request was rejected before mutation and may be retried after refreshing the token. A 401 from a protected route requires sign-in.

Registration fields: `school_id`, `first_name`, optional `middle_name`, `last_name`, `email`, `password`, `password_confirmation`. Passwords are 8–72 bytes. The server assigns the Student role and inactive status; the existing school administration workflow must activate the account and assign academic details. Client-supplied role, activation and academic fields are ignored. No email verification or self-service forgotten-password reset is included.

Login accepts `identifier` and `password`. Profile updates accept `email`; password changes accept `current_password`, `password`, `password_confirmation`. Message read accepts numeric `message_id`. All student data is scoped to the authenticated user, irrespective of submitted IDs. Authentication and registration are rate limited; account deactivation or password change invalidates older mobile sessions. Sessions expire after eight hours.

Portal data includes all enrolled offerings irrespective of offering status, individual rooms/building/floor and meeting times, teacher profile IDs, time-off ranges, department days off, decimal/null server grades, grade update timestamps, enabled grading terms, complete personal attendance and inbox history, assigned activities including cancellations, and school compliance calculations. `attendance_window_days=0` means all recorded history. The Android UI pages history locally; the portal no longer imposes record caps. Campus timezone is returned and respected by the client.

Additional student routes:

| Method | Route | Purpose |
| --- | --- | --- |
| GET | student/people.php?q=...&role=...&page=1 | Active directory search (minimum 2 characters, 20/page) |
| GET | student/directory-profile.php?id=... | Web-authorized profile/class relationships; unauthorized contact email removed from JSON |
| GET | student/roster.php?offering_id=...&q=...&page=1 | Enrolled class roster (24/page), no classmates' email exposure |
| GET | student/calendar.php | Web calendar entries, time off and department policies |
| GET | student/sent.php?page=1 | Authenticated user's sent messages (30/page) |
| GET | student/feed.php | Web campus feed (50-post window), categorized updates and images |
| POST | student/photo.php | Multipart `profile_image`; same CSRF header and web image validation, JPG/PNG/WebP up to 2 MB |

Photo upload reuses the web managed-upload service, including file verification and replacement cleanup. Standard student permissions do not include message composition or administrative academic writes. Shared profile, directory, calendar, inbox and feed routes accept active Student or Teacher sessions. Student portal and enrollment roster remain student-scoped. Read-only directory and roster APIs preserve the web service authorization rules; roster additionally requires current enrollment.

Deploy from the web project:

```powershell
python scripts/deploy-mobile-api.py          # Show destinations
python scripts/deploy-mobile-api.py --deploy # Verified FTPS upload, existing-file backup
```

Credentials are read from the ignored `.env.hostinger`. Existing destination files are backed up under ignored `storage/mobile-api-backups/`; each upload is downloaded and compared byte-for-byte. Only mobile API PHP source/wrappers are included. Failed deployments can be restored from the backup; the script does not automatically roll back partial uploads.

Integration testing requires a dedicated local MariaDB instance bound to `127.0.0.1:3307`, root without a password, PHP with PDO MySQL/mbstring, and Python PyMySQL. Never point this harness at production. It creates a unique `mobile_api_test_...` database, imports structural fixture DDL, serves an isolated app copy on port 8097, and drops only that test database afterward:

```powershell
python mobile-api/tests/integration.py
```

September 5, 2026: 33 isolated HTTP checks passed. Live checks passed for health, session, unauthorized portal, CSRF, validation, and invalid credentials. A temporary authenticated, read-only verifier inspected live schema and executed the complete portal query successfully; it was removed and its URL verified as 404. No live student records were changed by tests.

Expanded parity validation: live read-only checks passed for all six new GET routes, including an enrolled roster. Seven new routes rejected unauthenticated access. The photo upload was validated against the isolated database with a real multipart PNG. No production student records were changed during verification.


## Teacher workspace

Login accepts existing active Teacher accounts; session responses include `roles`. Registration continues to create pending Student accounts only. Dual-role accounts can switch workspaces in the Android app. Teacher routes require the Teacher role and recheck current web permissions for each protected action.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | teacher/portal.php | Assigned classes, meetings, personal attendance, activities, inbox, permissions and time off |
| GET | teacher/roster.php | Assigned class directory; offering_id, q and page |
| GET | teacher/attendance.php | Dated roster; modes calendar, policy, live and seat_students |
| POST | teacher/attendance.php | Actions manual, finalize, policy, assign and unassign; RFID scans arrive through authenticated room scanners |
| GET/POST | teacher/grades.php | Assigned class grade roster; save draft/finalized grades, authorized reopen |
| GET | teacher/compliance.php | Assigned student compliance; q and page |
| GET/POST | teacher/time-off.php | Own time off; save and cancel |
| GET | teacher/recipients.php | Role and section recipient options |
| POST | teacher/messages.php | Send to individual, section, role or all; optional announcement |
| GET/POST | teacher/seats.php | Assigned room layouts and accessible presets; save_room, save_preset, update_preset, delete_preset |

Attendance and grade actions require an assigned class. Room layout reads/writes additionally require a room used by an active assigned class. Preset ownership, finalized-grade locks, time-off conflicts and roster eligibility are enforced by the existing web services. Reopening finalized grades requires global grade access, as on the web. All writes require CSRF; seat layout JSON has a 256 KB cap, other JSON a 16 KB cap.

Run `python mobile-api/tests/integration.py` against the dedicated local test MariaDB on port 3307. The suite creates and drops its own unique database. It never sends messages or changes records in production. Current suite: 81 HTTP checks covering student regressions and teacher reads, writes, authorization, CSRF, locking and validation.
