# Deploying RFID Attendance to Hostinger

This guide covers the current production deployment for `https://jsmkj.space`.
Run all commands from the web application directory:

```powershell
cd C:\Users\Admin\Desktop\rfid-system\rfid-attendance-web
```

The application uses verified FTPS for files and MySQL as its primary database.
Firebase Cloud Messaging is only a notification transport and does not replace
MySQL.

## Production paths

| Content | Local path | Hostinger path |
| --- | --- | --- |
| PHP application code | `src/`, `templates/`, `config/` | `domains/jsmkj.space/` under the same directory names |
| Public web files | `public/` | `domains/jsmkj.space/public_html/` |
| Private environment file | generated from `.env.hostinger` | `domains/jsmkj.space/.env` |
| Mobile API implementation | `mobile-api/src/` | `domains/jsmkj.space/mobile-api/src/` |
| Mobile API public endpoints | `mobile-api/public/` | `domains/jsmkj.space/public_html/rfid-attendance-web/public/` |
| Notification worker | `scripts/notification-worker.php` | `domains/jsmkj.space/scripts/notification-worker.php` |

Do not place database credentials, FTP credentials, Firebase service accounts,
or other private keys in `public_html`.

## One-time local configuration

Create `.env.hostinger` in the project root. It is ignored by Git. Use this
shape and fill in the values from Hostinger:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://jsmkj.space
PUBLIC_DIRECTORY=public

DB_HOST=YOUR_HOSTINGER_MYSQL_HOST
DB_PORT=3306
DB_DATABASE=YOUR_DATABASE_NAME
DB_USERNAME=YOUR_DATABASE_USERNAME
DB_PASSWORD=YOUR_DATABASE_PASSWORD

FTP_HOST=YOUR_FTP_HOST_OR_IP
FTP_PORT=21
FTP_USERNAME=YOUR_FTP_USERNAME
FTP_PASSWORD=YOUR_FTP_PASSWORD
```

`FTP_HOST` must contain only a hostname or IP address, without `ftp://` or
`ftps://`. The scripts upgrade the connection to TLS and use encrypted data
transfers.

Never commit `.env.hostinger`. Confirm it remains ignored before committing:

```powershell
git check-ignore .env.hostinger
git status --short
```

## Before every deployment

1. Review the exact changes being released.
2. Lint each changed PHP file.
3. Syntax-check each changed JavaScript file.
4. Preview the selected deployment script when it supports a dry run.
5. Create a Hostinger database backup or export before applying a schema change.

Useful validation commands:

```powershell
C:\xampp\php\php.exe -n -l templates\admin\grades.php
node --check public\assets\js\admin-control.js
git diff --check
```

Replace the example filenames with the files changed in the release.

## Preferred: feature-specific deployment

The Python deployment scripts default to a dry run. Running without `--deploy`
prints every local-to-remote mapping without changing production:

```powershell
python scripts\deploy-user-settings.py
```

Review the list, then deploy:

```powershell
python scripts\deploy-user-settings.py --deploy
```

During deployment, these scripts normally:

1. Connect with verified FTPS.
2. download the current remote files into a timestamped local backup directory;
3. apply the script's idempotent database migration, if it has one;
4. upload the mapped files;
5. download every uploaded file and compare its bytes with the local source;
6. write a `manifest.json` into the backup directory.

Available scoped deployers are:

| Command | Intended scope |
| --- | --- |
| `python scripts\deploy-mobile-api.py --deploy` | All mobile API source files and public mobile endpoints; no schema migration |
| `python scripts\deploy-physical-rfid.py --deploy` | RFID devices, scans, attendance edge cases, event scanners, class components, and related web/mobile files |
| `python scripts\deploy-notifications.py --deploy` | Notification tables, services, endpoints, web assets, and worker |
| `python scripts\deploy-profile-visibility.py --deploy` | Profile visibility and associated session/notification UI |
| `python scripts\deploy-user-settings.py --deploy` | Shared web/mobile settings, preferences schema, and currently mapped UI assets |
| `python scripts\deploy-violations-and-role-preview.py --deploy` | Disciplinary violations and admin role preview |

Use the script whose mapping contains every changed file. Open the script and
update its mapping lists when a release adds a file within that feature. Always
run its dry run again after changing the mapping.

`deploy-physical-rfid.py` supports `--skip-migration`, but use that only when the
same migration has already succeeded and the release is intentionally uploading
files again. Application code that expects an unapplied schema can break live
requests.

## Deploying only application files

For a small file-only change that is not covered by a scoped Python deployer,
use the PowerShell uploader's `-Only` option:

```powershell
.\scripts\deploy-hostinger.ps1 -Only @(
    'public/assets/css/app.css',
    'public/assets/js/admin-control.js',
    'templates/admin/grades.php'
)
```

Paths passed to `-Only` are relative to the project root. The uploader accepts
files only from the application directories it knows how to deploy.

This PowerShell uploader does not apply SQL migrations and does not create the
same timestamped, byte-verified backup as the scoped Python deployers. Back up
affected remote files separately when using it.

## Full application deployment

Use a full deployment only for a deliberate complete release:

```powershell
.\scripts\deploy-hostinger.ps1
```

The script uploads `config`, `src`, `templates`, `storage`, and all files under
`public`. It also creates a production `.env` from the non-FTP values in
`.env.hostinger` and uploads it outside `public_html`.

> **Current notification constraint:** `deploy-hostinger.ps1` writes only the
> core application and database keys into the remote `.env`. It does not preserve
> `FCM_SERVICE_ACCOUNT_FILE`, `FCM_WEB_CONFIG_FILE`, or other keys installed by
> the notification activation script. A full deployment in the repository's
> current state can therefore disable push delivery. Use the scoped Python
> deployers for routine releases. Before any future full deployment, update and
> test the PowerShell script so it carries every required production environment
> key, or restore the private notification settings immediately afterward with
> the approved activation procedure.

Review the local `storage` directory before a full deployment so temporary or
backup artifacts are not unintentionally included. A full deployment still does
not apply database migrations. Apply required migrations first using the matching
scoped deployer or the controlled manual process below.

`-StartAt` exists for resuming an interrupted full upload. Use it only with the
same unchanged source tree and the exact next transfer number printed by the
failed run. Do not combine `-StartAt` with `-Only`.

## Creating database migrations

For every production schema change, add a new migration file rather than editing
old migration history:

```text
database/migrations/YYYYMMDD_short_description.sql
```

Also update `database/schema.sql` so new installations represent the final
schema. `schema.sql` is a reference/fresh-install schema; uploading it does not
alter the existing production database.

Prefer additive and rerunnable SQL:

```sql
CREATE TABLE IF NOT EXISTS example_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

For a change that cannot be expressed safely as one rerunnable statement, use an
expand-and-contract rollout:

1. **Expand:** add nullable columns, new tables, or compatible indexes.
2. Deploy code that works with both the old and expanded schema.
3. Backfill or verify the data.
4. **Contract:** add final constraints or remove obsolete structures only after
   the new code is live and verified.

Avoid destructive operations such as `DROP TABLE`, narrowing a column, or
deleting data in the same release unless a tested backup and recovery procedure
exists.

## How automated migrations reach Hostinger MySQL

The scoped deployment scripts reuse `migrate()` from
`scripts/deploy-physical-rfid.py`. It:

1. creates a temporary PHP migration runner with a random request secret;
2. uploads the runner briefly to the production public directory;
3. calls it over HTTPS with the secret in a request header;
4. executes SQL through the application's private production `.env` and PDO
   connection;
5. checks the returned migration phase; and
6. deletes the runner and verifies that its URL returns `404`.

The runner accepts duplicate-column, duplicate-index, and already-removed-index
conditions so supported migrations can be rerun. This does not make arbitrary
SQL safe or idempotent. Design and test each migration explicitly.

To add a migration to a feature deployer:

1. add the SQL file under `database/migrations/`;
2. add it to that deployer's file mapping;
3. load and split its statements in the same way as the existing scoped scripts;
4. call `migrate()` before code that requires the new schema;
5. run the deployer without `--deploy` and review the mapping;
6. back up the production database; and
7. run the deployer with `--deploy`.

Do not build SQL by interpolating secrets or user input. Never leave a standalone
migration runner, Adminer, or phpMyAdmin clone in `public_html`.

## Manual database changes

Use Hostinger phpMyAdmin only when no scoped migration script covers the change.

1. Export the production database from Hostinger first.
2. Select the exact production database shown in `.env.hostinger`.
3. run the new migration file, not the entire `database/schema.sql`;
4. inspect the affected tables, indexes, and constraints;
5. record that the migration was applied; and
6. deploy the compatible application files.

Do not import `database/seed.sql` into production. Seed data is intended for local
or controlled test environments.

## Verification after deployment

A successful Python deploy must finish with `Verified ...` for each mapped file
and exit without an exception. After that:

1. Open `https://jsmkj.space/` in a private browser window.
2. Sign in with an appropriate test account for each affected role.
3. exercise the changed form or API endpoint;
4. check the browser network panel for non-2xx responses;
5. check `storage/logs/` and Hostinger logs for PHP errors; and
6. verify the expected database columns, indexes, and rows in phpMyAdmin.

For cache-sensitive CSS or JavaScript, request the asset with a temporary query
parameter:

```powershell
curl.exe -I "https://jsmkj.space/assets/css/app.css?v=$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())"
```

For notifications, also keep the Hostinger cron job enabled every minute:

```text
/usr/bin/php /home/u847001018/domains/jsmkj.space/scripts/notification-worker.php
```

See `docs/NOTIFICATIONS.md` for notification-specific activation and live tests.

## Rollback

Scoped Python deployers save previous remote files beneath `storage/` in a
timestamped feature backup directory, for example:

```text
storage/settings-backups/20260910-143000/
storage/notification-backups/20260910-143000/
storage/physical-rfid-backups/20260910-143000/
```

The directory structure mirrors the remote destination, and `manifest.json`
lists the files involved. To roll back files:

1. identify the backup created by the failed release;
2. copy the required backed-up file over its corresponding current local file or
   upload it directly to the matching remote path with an FTPS client;
3. deploy that exact file with a scoped script or `deploy-hostinger.ps1 -Only`;
4. verify the live behavior and asset contents.

File backups do not include a database dump. Restore a database only from the
Hostinger backup/export created before the migration. Prefer a forward-fix
migration when production has accepted new data since deployment, because a full
database restore would discard those newer writes.

## Recommended release checklist

- [ ] Changed PHP and JavaScript files pass syntax checks.
- [ ] `git diff --check` passes.
- [ ] A new migration exists for every schema change.
- [ ] `database/schema.sql` reflects the final schema.
- [ ] The production database was backed up before migration.
- [ ] The deployment mapping contains every changed runtime file.
- [ ] The scoped deployer's dry-run output was reviewed.
- [ ] Deployment completed with every file marked `Verified`.
- [ ] The temporary migration runner was removed and verified as `404`.
- [ ] The affected admin, teacher, and student flows were tested live.
- [ ] Production logs and the resulting database state were checked.
