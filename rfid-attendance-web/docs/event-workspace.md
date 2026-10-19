# Event workspace and tracking

Events can be created from the Teacher portal when the effective role has `manage_events`. The grant is not added or changed by this rollout. Administrators with that permission manage campus events. Other managers control events they created under one of their effective roles. Ownership records the role at creation; View As never inherits administrator scope. Existing events receive a dated ownership baseline from their creator's primary role at installation.

Invitees can open full event details and their own activity history. Only the event's authorized managers see other participants' activity, scanner assignments, live tracking, and management controls. These scopes also apply to feeds, calendars, search, participant lookups, deletion conflict inspection, and activity analytics. Class suspension and device creation still require their separate domain permissions.

The event editor uses the existing audience builder, image validation, scanner conflict checks, CSRF protection, and notification delivery. Once attendance starts, schedule and audience edits are protected. Events with attendance or activity history cannot be deleted; cancel them to preserve records. Closing attendance uses the existing required-absence and incomplete-checkout rules.

## Activity history

Apply `database/migrations/20261006_event_tracking.php` through `EventTrackingSchema::install()` after the existing analytics and audit foundation. The migration adds indexed `event_activity_logs`, an installation marker, and transactional insert/update triggers on event attendance. Re-running it does not duplicate legacy rows or reset role grants or the analytics baseline. Audit Trail tracking is regenerated from the real schema to include the new tables and ownership field.

Saved historical check-in/out timestamps and existing status-only records are copied with `legacy=1`. Earlier scans that were overwritten are not reconstructed. Going forward, timestamp changes and status corrections are captured in the same transaction as attendance, including scanner, manual correction, and system-closing paths. School IDs and profile pictures are read from current profiles; raw RFID credentials and correction narratives are never sent to the tracking UI.

Connected event scanners select the event through its assigned scanner, event dates, and daily attendance window. A scanner records check-in, then checkout where required. A duplicate scan within 30 seconds of check-in is rejected; a completed daily attendance cannot be overwritten by repeated scans. A multi-day event can record a new day's check-in while retaining earlier activity. All times come from the database in the configured campus timezone.

## Live display and manual fallback

Start tracking opens a viewport-sized modal and requests browser fullscreen where supported. The display follows the supplied banner/photo/ID/name/outcome layout. Check-in and checkout use distinct text and semantic green/red surfaces. Exit, Escape, mobile layout, and theme accents are supported. Profile images have a local fallback.

Live updates poll a bounded cursor endpoint every two seconds while visible. Initial connection establishes a cursor without replaying old arrivals. Older responses cannot replace a newer manual entry. Network failures show a paused state and retry with a bounded delay; expired sessions or revoked scopes stop recording and polling. Leaving tracking cancels outstanding requests. The historical activity table remains independently searchable, date/source/activity-filtered, sorted, and paginated at 25 rows.

The manual fallback accepts a participant's school ID and an explicit check-in or checkout action. It does not accept USB/keyboard RFID card scans. Only eligible active participants can be recorded; the same server-timed, locked attendance operation powers connected scanners and the fallback. Manual entries retain their distinct source and actor in the audit trail.

## Validation and deployment

Run `python tests/event_workspace_integration.py` against the disposable MariaDB fixture server on port 3340. It checks Teacher portal creation/editing/deletion, ownership and View As isolation, scanner/manual history, duplicate protection, closed-event history, student-only visibility, revoked permissions, audit/analytics capture, indexes, keyboard dismissal, theme accents, and desktop/mobile geometry using Edge.

`python scripts/deploy-event-workspace.py --apply` performs scoped deployment with source drift checks and local private backups of changed files, event records, grants, and trigger definitions. It installs the additive history foundation before uploading the new runtime and templates, verifies audit coverage and unchanged grants/baseline, and removes its token-protected inspector. Failure restores prior runtime files; the additive history remains intact. Physical scanner hardware must be checked on campus; the automated fixture exercises its authenticated HTTP scan path.
