# Calendar controls and recollection date ranges

The shared searchable-select helper excludes Flatpickr and Calendar.js controls. These libraries update their own native month selectors; wrapping them with Choices left a stale month label. Scoped styles keep the month selector, editable year and year arrows usable in every theme. Open popups realign within the viewport when their containing layout resizes or scrolls.

Recollection creation, the management editor and calendar editor share this field order:

1. Venue
2. Date start and Date end
3. Time start and Time end

The existing `date` column remains the start date. The migration adds nullable `end_date`, a `(date,end_date)` index and a date-order constraint. Existing single-day rows use their own stored date as the end date, preserving their original `updated_at`. New submissions store both dates. Legacy create clients default to a single day; legacy update clients preserve an existing multi-day end date. Validation compares complete dates and times, allowing overnight activities while rejecting invalid or reversed ranges.

Calendar entries, recollection lists, search results and dashboards show the complete range. Ongoing activities remain visible until their end date. Activity analytics include schedules overlapping the selected dates and expose both dates in record tables and exports; completion remains one participant per activity. The calculation version changes to invalidate earlier cached results.

Migration `database/migrations/20261007_recollection_range.php` refreshes only recollection history and audit triggers for the new column, retaining the existing schema-aware capture and deletion rules. Older recollection snapshots gain the end date from their own snapshot date; their validity periods remain intact. Re-running the installer preserves the analytics baseline and revoked permission grants. This update adds no access grants.

## Validation and deployment

Run `python tests/recollection_schedule_integration.py` with a disposable MariaDB server on port 3340, PHP, PyMySQL, Playwright and Edge available. The fixture creates and removes its own database and user. It uses Flatpickr 4.6.13 and Choices 11.1.0 to check month/year navigation, searchable-select isolation, mobile and both themes, date defaults, editor persistence, overnight validation, historical migration, revoked access, audit capture and overlapping analytics.

Run `python scripts/deploy-recollection-schedule.py` to inspect the scoped file count; `--apply` performs deployment. It checks production drift, saves private file/database/history/trigger backups, migrates before uploading dependent runtime files, verifies unchanged business records, baseline and permission grants, and removes its temporary inspector. Backups are ignored under `storage/recollection-schedule-backups/`. On a rollout failure, previous runtime files are restored; the additive date field and compatible history remain installed.
