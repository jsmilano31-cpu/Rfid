# CampusTrack on this laptop

Run commands from `C:\Users\Windows 11\Desktop\rfid-attendance`.

```powershell
.\.campustrack-local\Start-Local.ps1
.\.campustrack-local\Stop-Local.ps1
```

Double-click `Start-Local.cmd` to start the services and open CampusTrack.
Services run as your Windows account and do not install Windows services or
start automatically at login. Stopping them preserves the local database.

| Component | Local address |
| --- | --- |
| Apache web application | http://127.0.0.1:8080/ |
| PHP mobile API development server | http://127.0.0.1:8097/ |
| MariaDB 11.8.8, compatible with the project's MySQL/PDO code | 127.0.0.1:3306 |

Apache 2.4.69 uses the installed PHP 8.3.33 and serves only the web repository's
`public/` directory. Required PHP extensions are enabled. All three listeners
bind only to `127.0.0.1`.

## Demo sign-in

All demo accounts use `Password123!`.

| Role | School ID |
| --- | --- |
| Administrator | ADMIN-001 |
| Teacher | TCH-001 |
| Student | 2026-0001 |
| Staff | STF-001 |
| Event Coordinator | EVT-001 |

These accounts come from the repository's local demo seed. Production data,
uploads, and credentials were not imported. External Firebase push delivery is
not provisioned locally. The mobile application's existing production API URL
was left unchanged; its local API server is available for development clients.

## Local database and migrations

The Git-ignored `rfid-attendance-web/.env` uses `APP_ENV=local`, the loopback
database, and a generated local application password. `.env.hostinger` remains
separate and is never read by this setup.

The new local instance imported `database/schema.sql`, `database/seed.sql`, and
`database/catalog.sql`. All 36 migration files were processed in date order.
Schema operations already present in the base were detected rather than
reapplied. The obsolete duplicate RFID ENUM intermediate definition was skipped
after verifying the final definition. Fresh demo Teacher grants were
consolidated once using the latest teacher portal migration's intended grants.
Audit, analytics/history, event tracking, and recollection date-range installers
completed. Initial summary: 67 tables, 269 triggers, 25 demo accounts, and 537
catalog courses.

For future repository migrations:

```powershell
.\.campustrack-local\Migrate-Local.ps1
```

This command checks the exact workspace database directory and local connection
settings, makes a local SQL backup, then applies pending migrations. It retains
existing local data and does not reload the base schema or seed. Applied
migration hashes are stored in `state/migrations.json`; changed historical
migrations are rejected. Review new migrations before applying them.

Local root credentials and SQL backups are in `private/`, restricted to your
Windows account and SYSTEM. Application credentials are in the ignored `.env`.
Do not share those files. The database files live in `data/mysql/`.

## Tools and checks

```powershell
. .\.campustrack-local\Activate-Tools.ps1
php --version
mysql --version
httpd -v
python .\.campustrack-local\check-local.py
```

The checks exercise demo sign-in for five roles, major admin/student/teacher
pages, local API health and authorization, and both student and teacher API
portals. They use only loopback URLs and block external redirects. Report:
`state/http-checks.json`. Database summary: `state/database-summary.json`.

Logs are in `logs/`. The start command validates Apache configuration and refuses
to take over ports owned by unrelated processes. The stop command targets only
this workspace's servers. Both repository working trees have no tracked source
changes from this setup.
