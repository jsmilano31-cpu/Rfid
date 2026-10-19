# RFID Attendance Mobile

Native Kotlin and Jetpack Compose application for student progress, teacher classrooms and permission-based campus management.

## Run without Android Studio

From this directory in PowerShell:

```powershell
.\scripts\android.ps1 build
.\scripts\android.ps1 run
```

The script builds with the Gradle wrapper, starts `Pixel_8` if no device is connected, installs the APK, and opens CampusTrack. It reads the SDK from `local.properties` (currently `D:\android-sdk`), then `ANDROID_HOME`, then `$env:USERPROFILE\Documents\android-sdk`. Override with `-SdkPath`; select a connected device with `-Serial emulator-5554`. Android Studio stays closed; its bundled JDK can be used by the script.

If C: cannot accommodate the emulator's data partition, use a separate data directory on D: (the original AVD is retained):

```powershell
.\scripts\android.ps1 run -EmulatorDataPath D:\AndroidEmulators\CampusTrackPreview
```

## Connected student app

The app uses `https://jsmkj.space/rfid-attendance-web/public/`, configured in `app/build.gradle.kts`. It opens with login/registration and restores encrypted session cookies using Android Keystore, bound to the configured API root. Upgrading from older session storage can require signing in again. Android never connects directly to MySQL. New registrations require school activation before login; school administrators assign academic details.

- Home and attendance: live records, authoritative counts, animated attendance ring, search and status filters.
- Schedule: enrolled offerings with individual meeting times, rooms, teachers and semester/section details.
- Grades and compliance: decimal/null grades, enabled terms, server overall scores and school deficiency calculations.
- Campus: targeted events, announcements, retreats/recollections, multi-day dates, attendance state and image galleries.
- Messages: received updates, unread filters and server-persisted read state.
- Profile: academic information, profile photo display, masked RFID registration, editable email and password changes.

Material 3, Navigation Compose, Coil images, animated transitions, expandable cards, shimmer skeletons, light/dark themes and loading/error/empty states provide the UI. Production uses `RemoteStudentRepository`; demo fixtures are used only in tests/previews. There is no fallback to sample records when the server fails.

Student parity additions include a campus month calendar/day agenda, per-subject attendance calendars, a time-positioned weekly timetable, teacher time-off and department-day-off notices, historical grades, people search, permitted directory profiles, class rosters, profile photo uploads (JPG/PNG/WebP up to 2 MB), sent messages and a categorized campus feed. Home includes grade/compliance summaries and ongoing multi-day activities. Dates use the campus timezone.

The portal has a bounded recent-record window. Dedicated workspace endpoints provide paged full attendance, mailbox, event history and other large lists. Searches cancel obsolete requests; refresh retains visible results. Exports stream to the selected Android document destination without reloading the page. Failed/canceled downloads attempt to remove only their newly created incomplete document.


API details and deployment: `../rfid-attendance-web/mobile-api/README.md`. Live schema: `docs/live-schema.json`; configuration findings: `docs/hostinger-and-database-review.md`.

## Validation

```powershell
.\scripts\android.ps1 test
.\scripts\android.ps1 lint
.\gradlew.bat :app:connectedDebugAndroidTest --console=plain
```

Instrumentation tests require a booted device and explicitly inject demo data to avoid using production accounts. They cover navigation, attendance search/reload, message read state, schedule selection and profile validation. Unit tests cover attendance calculations and API parsing, including nullable decimal grades, historical offerings, multiple meeting times and server totals.

October 12, 2026: final debug build and 22 JVM tests passed; lint reports zero errors and 44 warnings. Local regression passed 195 mobile API checks, 78 mocked attendance/SMS checks and the canonical analytics pattern-total checks. Fourteen native checks passed on a narrow phone emulator; eight role-screen checks and a separately rerun fullscreen tracker check passed on a tablet emulator. Light/dark ready-content screenshots were inspected. Real scanner, FCM/provider delivery, physical-device accessibility and staging rollout remain separate verification work. No production changes were made during this parity work.

APK: `app/build/outputs/apk/debug/app-debug.apk`.

## Layout

`data/StudentRepository.kt` contains typed data and demo fixtures. `ui/CampusApp.kt` owns authentication; `ui/StudentApp.kt` owns student navigation; screen files group related student workflows. `ui/Components.kt` and `ui/theme/` contain reusable visuals and design tokens.


## Android 1.1 parity workspace

The current shell is `NativePortalApp`: separate **My progress**, **My classes**, and **Management** experiences follow effective server roles and independent permissions. Combined roles can switch contexts. Administrator own-profile navigation is intentionally omitted. Administrator SMS settings never appear in the Teacher workspace, even when its setting permission is enabled; teacher reviewed SMS sending remains independently grantable.

Native workflows include shared subject grades, private goals, guided analytics/records/export/print, attendance summaries and reviewed SMS jobs, targeted event CRUD/history/tracking with manual ID fallback, multi-day recollections, rich mailbox/To/CC/reply/forward/private attachments, profile schedules, role/account/academic/facility management, physical RFID registration, compliance/violations, audit and dependency-aware deletion.

CSV exports stream without reloading the screen. Printable reports have a 2 MB in-memory limit. The generated debug APK is for review; prepare a signed release build through the normal distribution process.

Review the [52-finding implementation and verification ledger](../rfid-attendance-web/guides/android_parity_implementation.md) before rollout. Matching **mobile API v2 and changed shared web services must deploy first**. No MySQL or PhilSMS credentials belong in the APK. Existing production configuration and migrations remain server-managed.

Build against the production/default HTTPS API:

```powershell
.\gradlew.bat :app:assembleDebug :app:testDebugUnitTest :app:lintDebug
```

For local emulator fixtures only:

```powershell
.\gradlew.bat :app:assembleDebug :app:assembleDebugAndroidTest -PcampusApiBaseUrl=http://10.0.2.2:8174/
```

Never distribute an APK with the local override. `NativeParityTest` skips without that override and explicit disposable fixture `studentId`, `teacherId`, and `adminId` instrumentation arguments. The opt-in suite covers native student/teacher/admin screens; real push/hardware/provider validation is separate.
