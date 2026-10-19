# Teacher mobile workspace

Implemented against the existing PHP teacher pages and shared services. The mobile API uses the same production database and service rules; no schema migration is needed.

| Web teacher workflow | Mobile implementation |
| --- | --- |
| Authentication | Existing teacher login, session restoration, permission-aware navigation, dual-role workspace switch |
| Teaching dashboard | Assigned classes, rooms, meeting times and suspension/time-off notices |
| Schedule and calendar | Weekly schedule, campus calendar, activities and class attendance calendar |
| Class directory | Searchable, paginated assigned-class roster and permitted directory profiles |
| Attendance | Date selection, searchable roster, manual correction with reason, physical room-scanner updates, finalization and class policy |
| Grades | Enabled grading terms, draft/finalize, locked record protection and authorized reopening |
| Compliance | Searchable, paginated assigned-student deficiencies |
| Classroom seats | Shared assigned-room grid editor, accessible preset copies/updates/deletion, student seat assignment and live attendance status |
| Teaching time off | Create, edit and cancel own entries with web conflict validation |
| Communication | Inbox, sent history, recipient search, individual/section/role/campus composition and announcement option |
| Account and campus | Contact email, profile photo, password change, campus feed and directory |

The interface uses Material 3, shared shimmer skeletons, explicit empty/error states and confirmation dialogs for consequential actions. Live seats refresh every ten seconds only while the screen is active in the foreground. Shared room layout changes affect other classes using that room; the save dialog explains this.

Scope: standard teacher workflows. School administration, global grading configuration, room inventory management and account provisioning remain web workflows. Physical RFID devices submit attendance directly to the server; manual corrections remain available to teachers. The Android app does not impersonate a room scanner. Presets can be copied from an available layout/preset; a standalone blank personal preset workflow is not included. There is no offline write queue.

Validation: 81 isolated HTTP checks cover student regressions and teacher authorization, attendance correction/finalization, policy changes, grade locks, time-off conflicts, isolated messaging and seat/preset changes. Android debug assembly, JVM tests, lint and instrumentation-test compilation are checked separately. A connected emulator/device is still required for visual and touch-flow verification.

Production API base: `https://jsmkj.space/rfid-attendance-web/public/`. Teacher wrappers deploy under `public_html/rfid-attendance-web/public/teacher/`; implementation files stay outside the document root.

Build from the mobile directory with `./gradlew.bat :app:assembleDebug`. Install on a connected device with `D:/android-sdk/platform-tools/adb.exe install -r app/build/outputs/apk/debug/app-debug.apk`.

Live verification: deployed PHP files were downloaded and compared byte-for-byte. All eight teacher read areas returned the expected JSON sections through a temporary read-only verifier, which was removed and confirmed 404 afterward. Anonymous requests returned 401. No production attendance, grades, seat plans or messages were modified by verification.
