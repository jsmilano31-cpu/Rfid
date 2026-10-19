# Student feature parity audit — September 5, 2026

> Historical baseline audit. See the implementation update below for the current status.

Scope: local web application controllers, services, templates and permission seeds compared with the mobile Compose screens, repository and deployed API source. This is a code-level audit, not a logged-in visual comparison with production. Production web files were previously found to differ from some local files. Live role-permission customizations were not revalidated for this audit.

The mobile app implements connected core student self-service, but does not have full web feature parity. Screen presence does not imply equivalent data coverage or actions.

| Area | Mobile coverage | Remaining difference from web |
| --- | --- | --- |
| Authentication | Login by school ID/email, persisted session, logout | Student-only app; does not expose additional roles' workspaces |
| Registration | New inactive Student account, pending school activation | Added mobile workflow; web auth routes contain login/logout, not equivalent public registration |
| Dashboard | Attendance counts/rate, today's classes, next activity, shortcuts | No campus month calendar/day agenda, latest-grade dashboard block, compliance summary block or equivalent categorized event feed; attendance totals use 90 days versus web's all-history dashboard query |
| Attendance | Search, status filters, date/time/source, course details | No per-subject month calendar or older-history loading; capped at 500 records over 90 days versus web's unrestricted personal-history query |
| Schedule | Weekday selection, per-meeting time/room, teachers and course details | No full timetable grid/list switch, teacher time-off/suspension notices, room/building/floor detail dialog, teacher-profile links or class-roster navigation |
| Grades | Nullable decimal term scores, server overall/status and enabled terms | API limits offerings to active status; web grades include all enrolled offerings irrespective of offering status. Older/closed offering grades can disappear on mobile |
| Compliance | Shared web services compute overall and per-subject totals | Mobile displays subject rows from its active-course list, rather than every returned compliance row; narrower subject coverage is possible |
| Events | Targeted events, details, required/optional, attendance, images, upcoming/past and type search | No status filter/date search; excludes cancelled/draft records unlike student web event query; capped at 200 events. Dashboard social-feed/category experience is not reproduced |
| Recollections/retreats | Combined Campus list, type, date/time/venue, facilitator and attendance | No section field or web status filter; excludes cancelled records; capped at 200 formation records |
| Messages | Inbox, detail, unread filter, server-persisted read state | No sent-items list or sender-profile navigation; latest 200 only. Compose/send is permission-gated on web and absent from mobile |
| Profile | Identity, roles, academic assignment, photo display, email/password updates | No photo selection/upload; web supports JPG/PNG/WebP up to 2 MB. Mobile intentionally masks RFID UID; web shows full UID |
| People directory | Missing | Web searches by name/ID/email, filters role, pages results and opens profiles |
| Directory profiles | Missing | Web shows permitted identity/class relationships and scoped links without granting students other students' private records |
| Class roster | Missing | Web lets an enrolled student browse/search/page classmates and open permitted profiles for that offering |
| Academic calendar/time off | Missing | Web month navigation, day agenda, event/formation entries and teacher time-off information have no mobile API/UI equivalent |

Permission boundary: the seed Student role has view_attendance, view_grades and view_compliance. It does not have send_messages, manage_seat_plans, manage_attendance or manage_grades. Therefore sending messages, editing seat plans, recording/finalizing class attendance and editing grades are not baseline student parity requirements. Sent history is still rendered by the web messages page. Students with additional assigned roles/permissions may have more web actions; mobile currently stays in student self-service mode.

Additional functional issues found:

- The mobile Home screen selects the next activity using start date only; an ongoing multi-day event can appear in Campus but disappear from Home.
- Home uses a recurring weekday schedule without teacher time-off/calendar exceptions and may show a suspended class as today's class.
- Mobile uses the device date/timezone even though the API returns the campus timezone.
- Lists stop at server caps without pagination. Hiding old records is a data-coverage gap, not merely a different layout.
- API fields omitted from the model/UI cannot be fixed with styling alone: new calendar/directory/roster/upload endpoints and broader existing queries are needed.

Suggested implementation order:

1. Correct data coverage: historical grades, pageable attendance/messages/activities, cancelled activity visibility, campus timezone and ongoing-event handling.
2. Add calendar and teacher time-off data; surface suspensions on Home and Schedule; add per-subject attendance calendars.
3. Add roster, directory, permitted profile details and cross-navigation using the existing web services' authorization rules.
4. Add profile photo upload, sent messages, status/date filters and timetable/detail enhancements.

Primary web evidence: `src/Controllers/PortalController.php`, `src/Services/ClassRosterService.php`, `src/Services/DirectoryProfileService.php`, `database/seed.sql`, `templates/portal/{attendance,people,profile,messages,events,recollections}.php`, `templates/partials/{portal_schedule_views,dashboard_calendar}.php`.

Primary mobile evidence: `../rfid-attendance-web/mobile-api/src/StudentApi.php`, `app/src/main/java/com/example/campustrack/data/{RemoteStudentRepository,StudentRepository}.kt`, and the `ui` screen files. Existing successful build/API tests establish implemented paths' validation; they do not establish full parity or device visual validation.


## Implementation update

The baseline gaps above have now been addressed for standard student permissions:

- Added campus calendar/day agenda and per-subject attendance month views.
- Added time-positioned weekly timetable, room details, teacher links, suspensions and department-day-off notices.
- Removed the 90-day/500-attendance and 200-message/activity cutoffs; added UI pagination. Grades include completed/cancelled offerings when enrollment remains enrolled, matching the web query.
- Added people search/role filters, permitted directory profiles, paginated class rosters and cross-navigation.
- Added photo upload with the web image validator, paginated sent messages and sender-profile links.
- Added activity status/date/section search, cancelled activity visibility, campus feed/categories/images and Home grade/compliance summaries.
- Corrected campus timezone use and ongoing multi-day activity selection. Compliance renders every returned subject total.

Validation: eight JVM unit tests, 33 isolated HTTP integration checks, Android debug build/lint and instrumentation compilation. Live Hostinger read-only checks passed for directory search/profile, enrolled class roster, calendar, feed and sent-message routes. All seven new routes rejected anonymous access. An enrolled live student was used to verify the roster route. Unauthorized roster, privacy and photo-upload cases were also tested against the isolated database. Temporary live verification scripts were deleted and verified as HTTP 404.

Remaining operational limits: no connected Android device/AVD was available for visual/instrumented execution. Complete personal history currently downloads with the portal before client-side paging; very large accounts may benefit from server pagination later. The campus feed retains the web's 50-post window. This is standard-student parity, not a port of additional teacher/admin permissions.
