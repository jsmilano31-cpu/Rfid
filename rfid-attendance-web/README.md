# CampusTrack Web

Framework-free PHP and MariaDB MVP for campus RFID attendance, events, recollections, grades, compliance, messaging, and role-aware dashboards.

## Requirements

- Apache 2.4 with PHP 8.2 or newer
- MariaDB or MySQL
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, and `zip`

Apache's document root must point to this repository's `public/` directory. Application code, database configuration, and SQL files intentionally remain outside the public web root.

## Local setup

1. Copy `.env.example` to `.env` and configure the database values.
2. From the repository root, initialize the local database:

   ```powershell
   mysql --host=127.0.0.1 --user=root --password --execute="SOURCE database/local_setup.sql;"
   ```

3. Open <http://127.0.0.1/>.

The included local setup creates the `rfid_app` database account with password `rfid_app_local`. Change it outside local development.

The academic catalog is maintained in `database/data/programs.txt` and `database/data/class-offerings.txt`. Local setup imports these files through `database/catalog.sql`; despite its source filename, each class-offerings row is initially a course-catalog record because section, teacher, semester, room, and schedule details are not provided.

## Demo accounts

All seeded accounts use the password `Password123!`.

| Role | School ID |
| --- | --- |
| Administrator | `ADMIN-001` |
| Teacher | `TCH-001` |
| Student | `2026-0001` |
| Staff | `STF-001` |
| Event Coordinator | `EVT-001` |

Users may sign in with either their school ID or seeded email address.

## Compliance and Analytics

Compliance is the single administrative workspace for student deficiencies, class attendance summaries, event attendance summaries, and the management audit trail. Its section links use shared permission checks: student records require `view_compliance`, `manage_compliance`, or `manage_reports`; attendance summaries and CSV export require `manage_reports`; and audit history requires `view_audit_logs`. Violation actions require `manage_compliance`, which also permits viewing the records being managed. Custom roles with only `view_compliance` use the consolidated management workspace. Teacher and student viewing grants retain their assigned-class/personal scope; explicit campus-wide `manage_compliance`, `manage_reports`, or `view_audit_logs` grants select the management workspace for any role. Additional role permissions are combined, and View As uses only the selected role. Old portal Compliance links and forms use the corresponding authorized workspace.

The student CSV export follows the current search/program/section filters and includes every matching student, across all pages. Print renders the selected section without filters or action controls. Legacy `page=reports` links, exports, audit filters, and section anchors open the corresponding Compliance view. The sidebar and universal search now use Analytics for the former Reports entry; Analytics is a placeholder until its separate feature work begins. No database migration is needed.

Run `python tests/compliance_workspace_integration.py` with the disposable MariaDB server on port 3340. It creates and removes isolated fixtures and verifies permission scopes, View As, exports, pagination, legacy links, violation controls, keyboard navigation, mobile layouts, both themes, and printing. `python scripts/deploy-compliance-workspace.py --deploy` backs up scoped files, checks production drift, verifies uploaded bytes, and rolls back failed uploads.

## Bulk deletion

Managed academic lists, accounts, rooms, scanners, campus activities, custom roles, and manageable seat-plan presets support checkbox selection and deletion of up to 100 records at a time. The header checkbox selects the current page or visible collection; selections remain across filters and pagination until cleared or navigation leaves the page.

Deletion is partial by design: each eligible record and its audit entry commit together; blocked records remain selected. The shared dialog groups clickable dependencies by selected record, supports dependency pagination and read-only refresh, and preserves editors underneath. Refresh never retries deletion. Existing cascades and protected institutional history remain unchanged. Permissions and personal-preset ownership are checked on the server; no database migration is required.

Run `python tests/bulk_deletion_integration.py` against the disposable local MariaDB server on port 3340. The test creates and drops its own database and includes database rollback/concurrency checks and real browser coverage. `python scripts/deploy-bulk-deletion.py --deploy` checks production drift, backs up scoped files, verifies uploaded bytes, and restores the snapshot if an upload fails.

## Multiple roles

An account has one primary role, which selects its default portal experience, and may have any number of additional roles through `user_roles`. Permissions are the union of every assigned role. Shared records and navigation are rendered once, and role-targeted queries use deduplicated user IDs so a Teacher who is also a Dean or Event Coordinator does not receive duplicate content.

Existing databases can enable this model with `database/migrations/20260824_multiple_user_roles.sql`; the migration preserves every current `users.role_id` assignment.

Formation activities support additional individual and role targets through `recollection_target_users` and `recollection_target_roles`. Apply `database/migrations/20260824_recollection_involvement.sql` to existing databases. Recollection visibility then follows active class enrollment (including irregular/cross-section enrollment), teaching assignments, explicit people, targeted roles, and the activity creator.

## Seat plans

Messages use an email-style inbox and a rich composer for accounts with `send_messages`. To and CC both accept individual people, sections, roles, and all active users. Overlapping groups deliver one copy per person, with To taking precedence. Rich HTML is purified on the server; plain-text bodies are retained for previews and older clients. Images open in a keyboard-accessible lightbox, and other attachments download through an authenticated endpoint.

Existing databases need `database/migrations/20261001_recipient_mailbox.sql` followed by `database/migrations/20261001_message_composer.sql`. Attachments are stored outside the public directory in `storage/message-attachments`; back up that directory with `message_attachments`, `messages`, and `message_recipients`. Uploads allow ten files, 5 MB each and 20 MB in total, subject to lower PHP upload limits. Supported files include JPG/PNG/WebP/GIF, PDF, plain text/CSV/Markdown, ZIP, and Word/Excel/PowerPoint documents. PHP Fileinfo and ZipArchive are required. `scripts/deploy-message-composer.py --deploy` performs production drift checks, backups, schema checks, and byte-for-byte upload verification. Quill 2.0.3 and HTML Purifier 4.19.0 are bundled locally with their licenses.

Administrators can build room layouts and campus presets inside Rooms & Devices. Teachers have the same editor in the portal, scoped to rooms used by their assigned classes, and may create personal presets. Plans use a transparent 10×10 grid by default, support drag-to-toggle Student Seat, Teacher Table, and custom-label cells, and save through AJAX. Existing databases can enable this feature with `database/migrations/20260824_seat_plans.sql`.

Live class seat plans appear at the top of teacher dashboards and in attendance workspaces. The room plan remains the reusable physical layout, while `class_seat_assignments` stores each subject's roster placements independently. Occupancy is derived from physical RFID scans and refreshes while the page is visible. Apply `database/migrations/20260825_class_seat_assignments.sql` to existing databases.

## Physical RFID scanners

Apply `database/migrations/20260906_physical_rfid_devices.sql` before connecting an ESP32. It changes attendance source values from `mock_rfid` to `rfid` while preserving historical rows, then adds short-lived registration requests and device scan logs.

Each scanner has a unique device code, room assignment, and hashed API key. Create or edit it under **Rooms & devices**, then generate an API key and copy the one-time value into the firmware's ignored `secrets.h`. Rotating a key immediately invalidates the previous credential.

`POST /iot/scan.php` accepts JSON `{ "uid": "A1B2C3D4" }` with `X-Device-Code` and `X-Device-Key` headers. During a pending registration, it captures the card for confirmation in **Users & RFID**. Otherwise it resolves the current scheduled class from the device room and records attendance through the normal class policy. Inactive devices, unassigned rooms, schedule conflicts, unknown cards, unenrolled users, days off, teacher time off, and finalized sessions are rejected.

`GET /iot/status.php` uses the same headers and verifies a scanner's credentials, status, and room assignment without recording a card scan.

Administrators use `POST /admin/rfid.php` to start, complete, or cancel registration and `GET /admin/rfid.php?token=...` to poll capture status. These browser endpoints require an authenticated `manage_rfid` session and CSRF protection. Moving a card from another user requires an explicit reassignment flag and records both sides in RFID history.

## Browser libraries

- Manrope from Google Fonts for global typography.
- Choices.js 11.1 for fast searchable user, course, section, program, department, room, event, and other entity selectors.
- Simple-DataTables 10 for client-side table search, sorting, pagination, and adjustable page sizes.
- Flatpickr 4.6.13 for keyboard-friendly date and AM/PM time selectors with non-native calendar and clock controls.
- Calendar.js 2.12.6 for the interactive administrative event calendar.

The interface does not use jQuery. Browser libraries are version-pinned and loaded from jsDelivr; PHP remains server-rendered and all authorization stays on the server.

User-facing times use 12-hour AM/PM notation. PHP normalizes form values before persisting them in native MySQL `TIME` and `DATETIME` columns, preserving reliable temporal sorting and comparisons.

High-volume user and compliance searches use debounced partial requests, abort stale requests, cache repeated queries, and return bounded server-side result sets instead of reloading full pages.

## Project layout

```text
public/                 Apache document root and browser entry points
  admin/                Administrative interface
  index/                Role-aware user portal
  auth/                 Login and logout
  api/v1/               Reserved API structure; handlers not implemented yet
  assets/               CSS, JavaScript, and images
src/
  Controllers/          Request orchestration and authorization
  Core/                 Database, authentication, CSRF, and view utilities
  Services/             Attendance, RFID, events, compliance, grades, and messages
config/                 Private application/database configuration
database/               Schema, seed data, and local setup
templates/              Server-rendered layouts and pages
storage/logs/           Runtime logs
```

## Security baseline

The MVP uses prepared PDO statements, password hashing, strict sessions, CSRF tokens, server-side permission checks, escaped output, foreign keys, uniqueness constraints, and administrative audit logs. The ESP32/mobile API folders are placeholders only and contain no public endpoints yet.

## University-wide grading periods

Administrators can configure **Prelims, Midterms, and Endterms** from Attendance Policies → Campus Policy Rules, or Grades → Grading System Policies. Both use the same `grading_settings` record and require `manage_grading_settings`; attendance thresholds retain their separate permission and save action. Clean installations default to Prelims disabled, Midterms 40%, Endterms 60%, and a passing score of 75%. Existing settings are preserved on deployment.

Each enabled period requires a positive weight, with a total of exactly 100%. Disabled periods are hidden from teacher/admin entry, grade tables, transcript lookup, and profile grade views. Saving a grade ignores supplied disabled-period values and preserves stored scores. The configured weights apply to new grade saves; changing policy never rewrites finalized grades or historical overall scores. The database/API key `final` remains compatible while its displayed label is Endterms.

Run `python tests/grading_policy_integration.py` against the disposable local MariaDB fixture on port 3340. It covers period combinations, validation, weighted previews and saves, stored-score preservation, audit rollback, role permissions, View As, CSRF, expired sessions, grade reopening, mobile layouts, both themes, and keyboard dismissal. Use `python scripts/deploy-grading-policy.py --deploy` for scoped deployment with backups, production drift checks, and byte verification. The deployment also backs up and restores the built-in Administrator's `manage_grading_settings` assignment if it is missing; `--restore-permission` performs only that idempotent, audited repair. It does not alter custom roles, grading settings, or grade records. The equivalent permission-only SQL migration is `database/migrations/20261002_restore_grading_policy_permission.sql`; no schema migration or grade recalculation is needed.

## Message timestamps

Every database connection sets its session time zone from the configured application zone (`Asia/Taipei`, UTC+08:00). Message creation, reads, stars, and Trash actions use the database clock; notification initialization does not change the connection's time zone. Client-supplied send times are ignored.

Mailbox responses retain `created_at` for compatibility and add `sent_at` (ISO 8601 with an explicit offset), `sent_at_display`, and `sent_at_date`. The response includes `time_zone`. Sent folders, recipient inboxes, full messages, and reply/forward quotes use server-formatted display text; the delivery log uses the same campus time. Browser clock, locale, and time zone cannot shift these labels. Existing `messages.created_at` TIMESTAMP instants are preserved; no data migration or historical timestamp rewrite is needed.

Run `python tests/message_timestamps_integration.py` against the disposable local MariaDB server on port 3340. It temporarily changes only that isolated server's default time zone, restores it afterward, and checks new and historical messages, mailbox action clocks, sender/recipient parity, spoofed client timestamps, four browser zones, a skewed browser clock, forwarded quotes, and mobile layouts. Deploy with `python scripts/deploy-message-timestamps.py --deploy` for scoped backups, production drift checks, and byte verification.

## Portal theme checks

Teacher and student portal controls use the shared surface and readable accent tokens in light, dark, and system modes. `python tests/portal_theme_integration.py` creates disposable fixtures on the local MariaDB server at port 3340 and checks rendered pages for unintended pale backgrounds and unreadable text. It covers populated People and class rosters, pagination, hover/focus states, grade and attendance dialogs, calendars, seat plans, automatic system-theme changes, and mobile layouts. No production records are changed by these tests. Deploy the stylesheet with `python scripts/deploy-portal-theme.py --deploy`; the script checks production drift, backs up the existing file, and verifies uploaded bytes.
