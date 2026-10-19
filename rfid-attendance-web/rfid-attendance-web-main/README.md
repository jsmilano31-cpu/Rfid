# RFID Attendance Web

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

## Multiple roles

An account has one primary role, which selects its default portal experience, and may have any number of additional roles through `user_roles`. Permissions are the union of every assigned role. Shared records and navigation are rendered once, and role-targeted queries use deduplicated user IDs so a Teacher who is also a Dean or Event Coordinator does not receive duplicate content.

Existing databases can enable this model with `database/migrations/20260824_multiple_user_roles.sql`; the migration preserves every current `users.role_id` assignment.

Formation activities support additional individual and role targets through `recollection_target_users` and `recollection_target_roles`. Apply `database/migrations/20260824_recollection_involvement.sql` to existing databases. Recollection visibility then follows active class enrollment (including irregular/cross-section enrollment), teaching assignments, explicit people, targeted roles, and the activity creator.

## Seat plans

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
