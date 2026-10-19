# Web permission coverage — 2026-10-04

Page navigation and native form actions use `App\Core\PermissionRegistry`. JSON endpoints also check the same permission codes before calling their services. A page grant is not a substitute for its mutation grants or record scope. Unknown native actions fail closed.

| Capability | Required grants and scope |
|---|---|
| Dashboard | `view_dashboard`; each count, feed, calendar and academic panel also checks its own domain |
| Users: create, edit, pictures, status, passwords, academic placement, role assignments, single/bulk delete | `manage_users`; protected-account and dependency rules remain |
| Roles: create, metadata, delete, permission grants | `manage_roles`; built-in Administrator stays full access |
| RFID: scanner registration sessions, capture, reassignment, unassignment | `manage_rfid`; user selection works without account-edit access |
| Departments, programs, subjects, sections, enrollments, offering removal | `manage_academic_structure` |
| Offering creation and edits, meeting schedules and teacher assignment | `manage_academic_structure` **and** `manage_teacher_assignments` |
| Department weekly days off, school years, academic term boundaries, no-class holiday overrides | `manage_attendance_policies` **and** `manage_academic_calendar`, through forms and holiday API |
| Campus attendance defaults | `manage_attendance_policies` on a campus management role; Teacher/Student grants apply only to assigned-class policies |
| Assigned-class attendance policy overrides | `manage_attendance_policies` and `manage_attendance`, with assigned-class scope |
| Attendance scanning, quick marking and finalization | `manage_attendance`; campus management grants allow global scope |
| Assigned-class attendance corrections and roster workspace | `correct_class_attendance` or `manage_attendance`; relationship scope still applies |
| Student assignments on prepared class seat plans | `assign_class_seats` or `manage_seat_plans`; layout/preset mutations still require `manage_seat_plans` |
| Personal attendance | `view_attendance`, own records |
| Grades: entry, finalization, roster and transcript lookup | `manage_grades`; shared lecture/lab identity and teacher assignment scope remain |
| Grade reopening and conflict reconciliation | `manage_grades` with existing campus records authority; Teacher alone is insufficient |
| Personal grades | `view_grades`, own records |
| Campus grading periods, weights and passing settings | `manage_grading_settings`, independently of attendance policy editing |
| Rooms, scanners, API key rotation, activation, single/bulk deletion | `manage_rooms` |
| Room seat plans, presets and class seat assignment/removal | `manage_seat_plans`, room/class relationship checks; live attendance assignment also requires `manage_attendance` |
| Events: audiences, images, creation, editing, cancellation, attendance, single/bulk deletion | `manage_events` |
| Recollections/retreats: invitees, editing, attendance, status, single/bulk deletion | `manage_recollections` |
| Event and recollection cards, feeds and calendar entries | `view_events` / `view_recollections` or corresponding management grant; permitted audience only outside management scope |
| Schedules, timetable details and related rooms | `view_schedule`, permitted enrollment/teaching relationships |
| Class roster destination | `view_class_rosters`, existing class relationship checks |
| Teacher personal time off | `manage_teacher_time_off`, Teacher role and ownership; cannot overwrite recorded attendance |
| Compliance | `view_compliance` for permitted records; `manage_compliance` for campus resolution and disciplinary issuance; `manage_reports` for operational exports/printing. Existing disciplinary restrictions remain |
| Audit Trail and old audit-report bookmarks | `view_audit_logs`; admin workspace, read-only |
| Analytics, drilldown, print/CSV export | `view_analytics` **and** each metric's domain permission and academic scope; Audit Trail alone never supplies academic scope |
| Mailbox list/detail, read/unread, stars, trash/restore, attachments | `view_messages`, own sent/received messages only |
| Compose, rich text, To/CC people/sections/roles/all users, attachments, replies/forwarding | `send_messages`; replying/forwarding an existing message additionally needs mailbox access. Send-only accounts can compose |
| People directory and other profiles | `view_people` or account management access; private identifiers/contact fields cannot be matched or exposed by directory search |
| Own profile, picture, contact, privacy, password | `manage_own_profile`, owner only |
| Preferences: appearance, accessibility, landing page and notification/privacy settings | `manage_own_settings`, owner only |
| Notification inbox, marking read, push-device registration/revocation | `manage_own_notifications`, own notifications and tokens; inbox and queued push content also require the corresponding attendance/activity/mailbox grants |
| Universal search | `use_search`; every page/action/provider also checks its destination/domain grants and record scope |
| Deletion-conflict review, refresh, protected-history viewer, bulk preflight/delete | Target entity's management grant; linked record fields/destinations have separate access checks; deletion/history protections unchanged |

Authentication, CSRF-protected sign-out and View As are security mechanisms rather than configurable content features. View As is restricted to an actual Administrator and uses only the selected role's grants. Device scan/status endpoints use configured scanner credentials, not a browser user's role. Public branding assets and holiday names contain no school/private records. Scheduled workers, audit capture and history preservation are infrastructure rather than user actions.

The **Class schedule** section on another person's administrative profile requires `manage_users` plus either `view_schedule` or `manage_academic_structure`. It reads that student's enrolled classes and/or that teacher's assignments, deduplicates shared relationships, excludes cancelled offerings, and filters by an assigned academic term. Meeting-specific room overrides are retained. Profile privacy and effective View As grants apply before the schedule query; this does not grant ordinary portal users access to another person's full timetable. Context actions retain their own domain permissions. `tests/profile_schedule_integration.py` covers these boundaries, term selection, empty/deleted records, multiple roles, more than 100 offerings, and responsive themes in isolated fixtures.

Multiple assigned roles are additive: disabling a grant on one role does not revoke another role's grant. The primary role participates even when a legacy `user_roles` association is missing. Ownership, privacy, protected history and teacher/student relationship restrictions still apply after a grant is enabled.

The migration adds eleven controls for formerly login-only features. On the **first** install, existing roles retain their former access to these features. Existing denied management grants are preserved. Re-running the migration never restores a subsequently removed grant. New custom roles start without grants. Permission names/descriptions and the role editor explain the calendar's two required grants.

Validation: `tests/permission_coverage_integration.py` provisions and drops a disposable MariaDB database on port 3340 and exercises migration/revocation, native actions, JSON APIs, custom roles, multiple roles, View As, privacy, scoped inspectors and browser controls. Run with PHP, PyMySQL, Playwright and a local Edge installation. It never connects to production.
