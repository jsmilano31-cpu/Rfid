# Campus notifications

MySQL stores notification content, ownership, revisions, read state, device tokens,
and a retry outbox. Plain PHP handles generation and FCM HTTP v1 delivery using
cURL/OpenSSL. Vanilla JavaScript handles the web inbox. Android uses Compose and
the FCM messaging SDK. No Firebase database, Firebase Auth, Supabase, Bootstrap,
or PHP framework is used.

## Rules

- Each Late attendance record generates a warning with the student's cumulative
  late count in that class offering through the attendance date. LEC/LAB offerings
  remain distinct; the count does not mix semesters or subjects.
- Every new Absent record generates an absence warning, including RFID cutoff
  results and records created by attendance session finalization.
- Corrections replace the previous warning with a new revision. Marking an older
  revision read cannot hide the correction. Repeated scans do not duplicate alerts.
- Upcoming events, recollections and retreats notify materialized participants
  at 24 hours and 1 hour before start. If the scheduler first sees an activity
  within 1 hour, only that reminder is created. Cancelled, draft, past, and
  unrelated activities do not generate upcoming reminders. Existing reminders
  are withdrawn on cancellation/removal, or updated when schedules change.
- The additive migration sets a cutover timestamp: existing historical attendance
  is not broadcast on installation. Subsequent corrections can generate notices.
- The web inbox refreshes every 15 seconds while visible and on foreground FCM
  messages. Mobile refreshes on resume, every 15 seconds while visible, and on
  FCM receipt. Inbox read state is shared across devices.

## Required activation (Firebase is delivery only)

1. Create a Firebase project, without enabling Analytics or any database product.
2. Register the Android application with package `com.example.campustrack`.
   Download `google-services.json` to
   `rfid-attendance-mobile/app/google-services.json`. Rebuild/install the APK.
   Builds without this file still support the in-app inbox and clearly show that
   push setup is pending; they cannot receive FCM pushes.
3. Enable the Firebase Cloud Messaging HTTP v1 API. Generate a service-account
   credential authorized for messaging in this project. Store it on Hostinger
   OUTSIDE `public_html`, for example under the domain's private
   `storage/private/firebase-service-account.json` directory. Use restrictive
   filesystem permissions. Do not put this file in the app, web assets, or Git.
4. In the private Hostinger `.env`, set `FCM_SERVICE_ACCOUNT_FILE` to that file's
   absolute path. PHP needs PDO MySQL, cURL and OpenSSL, with outbound HTTPS allowed.
5. For browser push, register a Firebase Web app in the same project. Copy its
   PUBLIC configuration and the Cloud Messaging Web Push certificate PUBLIC VAPID
   key into a file shaped like `config/firebase-web.example.json`. Set the
   private `.env` variable `FCM_WEB_CONFIG_FILE` to its absolute path. The service
   only exposes the six explicitly whitelisted public values, never server keys.
   Browser push requires HTTPS and explicit notification permission.
6. Run `database/migrations/20260907_notifications.sql` once (safe to rerun).
   Fresh installations already include these tables in `database/schema.sql`.
7. Add a Hostinger cron job EVERY MINUTE to run the private PHP worker:

   ```text
   /usr/bin/php /home/u847001018/domains/jsmkj.space/scripts/notification-worker.php
   ```

   This is the verified deployment path for this account. This is a CLI-only
   worker, not a public URL. Do not put private keys or
   a long-running worker in `public_html`. Hostinger's minimum scheduling interval
   determines scheduled reminder precision. Run once manually to inspect output.

8. Android: open the bell, choose Enable push, and grant Android notification
   permission. Browser: open the bell and choose Enable push. Denial does not
   disable the inbox. Use the opt-in isolated live tests below to verify transport
   without modifying student attendance.

## Delivery guarantees and privacy

Attendance/activity write hooks queue notifications immediately; FCM delivery is
attempted after the HTTP response on FastCGI and retried by cron. On servers without
`fastcgi_finish_request`, shutdown delivery can add request latency. Cron reconciles
missed generation, so it must be configured even if live hooks seem to work.
Outbox attempts back off and stop after eight failed sends. Check `status='failed'`
and `last_error` in `notification_outbox` for operational issues. Fix configuration
before requeuing failed items. Unregistered tokens are disabled; read, expired,
superseded, inactive-user, and reassigned-device jobs are skipped.

Push payloads contain only a notification id, revision, and recipient account id.
The OS/browser notification is generic. Attendance details are fetched through
the authenticated inbox, keeping school records out of push payloads and lock
screens. Android checks the current account before displaying pushes, deduplicates
revisions, rebinds rotated tokens via WorkManager, and clears bindings on sign-out.
Browser service workers keep a local account binding and clear it on web sign-out.

FCM is best-effort near-real-time delivery, not a timing guarantee. Offline devices,
Android force-stop/battery restrictions, denied permissions, or browser restrictions
can delay/prevent delivery. The MySQL inbox remains authoritative. Tokens inactive
for 30 days are not used until the device registers again. Reminders use campus
time (Asia/Taipei, UTC+08:00).

No arbitrary send-to-user endpoint is exposed. All inbox reads, marks and device
registration require an active user session; writes require CSRF validation.
Device tokens are private server data. Notification content is rendered as text.

## Deployment and verification

`python scripts/deploy-notifications.py` previews paths; `--deploy` backs up existing
files, applies only the additive migration, uploads, and verifies bytes via FTPS.
It does NOT provision Firebase or install Hostinger cron jobs.

`php tests/notifications_integration.php` uses an isolated local database on port
3338 and cleans up only its randomly named test database. It covers migration
replay, deduplication, late counts, correction revisions, ownership, reminder
windows, cancellations, participant isolation, and device lifecycle.

Android: `./gradlew.bat :app:assembleDebug :app:testDebugUnitTest`.
Verify Android foreground/background/tap, browser foreground/background,
permission denial, sign-out/account switching, duplicate replay, and offline recovery.

September 7, 2026 verification: 19 isolated database checks, 14 Android JVM tests,
and desktop/mobile-width browser interaction checks passed. The APK was installed
and launched on the emulator. The additive migration and notification files were
deployed to Hostinger with byte verification and backups. The live web/mobile
notification endpoints return JSON 401 without a session; the site and assets
remain accessible.

### Firebase activation, September 7, 2026

- Project: `jsmkj-campus-push-2026`, number `349801355685`; Android and Web apps
  registered using Firebase CLI under the school project owner account.
- Dedicated `campus-notification-sender` service account has only the Firebase
  Cloud Messaging API Admin role. No Firebase database services were enabled.
- Android SDK configuration is installed locally in ignored `app/google-services.json`.
  The configured debug build and all 14 JVM tests passed.
- Service-account JSON and public web configuration are installed in Hostinger's
  private `storage/private` directory with mode 600. Private `.env` paths are set.
  Actual OAuth authorization from Hostinger succeeded. Web VAPID configuration
  uses the public key generated by the project owner in Firebase Console.
- The every-minute hPanel cron completed successfully, confirmed by the private
  `storage/private/notification-worker-status.json` completion record.
- Real Hostinger → FCM → browser delivery passed both foreground reception and
  background OS notification checks in an isolated browser profile.
- Real Hostinger → FCM → Android delivery passed on the Pixel_8 emulator: the
  actual messaging service displayed the expected OS notification. The opt-in
  instrumentation test passed and restored its original preference snapshot.
  No production attendance or user data was changed for transport verification.

Provisioning helpers: `node scripts/provision-firebase-messaging.cjs` reuses the
Firebase CLI login for IAM operations unavailable as CLI commands;
`python scripts/activate-notification-push.py` installs private files, preserves
and backs up `.env`, verifies server authorization, and removes its guarded probe.
Neither helper installs a Hostinger cron entry. After any cron changes, inspect
the private completion record for a recent `completed_at` and check hPanel output.

`python tests/live_browser_push.py` tests actual transport without an application
account. Android `LivePushTest` is skipped by default: explicitly run it with
instrumentation argument `livePushTest=true`, then run
`python scripts/verify-live-push.py --android` concurrently. It temporarily binds
only the emulator to `push-verification-only`, restores local preferences, and
removes the test notification and temporary token file. No production user,
attendance record, inbox row, or device binding is inserted by these tests.

Primary documentation:
- https://firebase.google.com/docs/cloud-messaging/android/get-started
- https://firebase.google.com/docs/cloud-messaging/android/receive-messages
- https://firebase.google.com/docs/cloud-messaging/send/v1-api
- https://firebase.google.com/docs/cloud-messaging/js/client
