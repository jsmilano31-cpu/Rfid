# Teacher portal and permission audit — 2026-10-05

The live Teacher role had campus management grants for rooms, RFID, events, compliance, reports, the academic calendar and teacher time off. Those grants exposed administrative navigation despite Attendance Policies being disabled. The Teacher baseline is now a portal role, sharing the Student navigation groups and dashboard sections.

| Workspace | Teacher scope and permitted changes |
| --- | --- |
| Dashboard and Schedule | Assigned classes, campus calendar and accessible activities; no schedule, department days-off, term, holiday or time-off editing |
| Attendance | Assigned-class calendars and rosters; reasoned manual corrections only |
| Grades | Assigned-subject rosters, scoped student lookup, grade entry and finalization; no campus grading policy changes |
| Seat Plans | Assign or unassign enrolled students on prepared plans for assigned classes; no room layout or preset mutations |
| Analytics | Authorized domains and assigned academic relationships; no campus-wide Teacher scope |
| Events and Recollections | Accessible participant/audience records; no activity or attendance management |
| Compliance | Existing assigned-student scope; no violation issuance or deficiency management |
| People | Permitted public profiles; private identity protections remain |
| Messages, Profile, Settings | Personal mailbox, message sending, profile and preference changes |
| Users, RFID, Rooms, Academic setup, Policies, Audit Trail | No Teacher baseline grant |

The new `correct_class_attendance` and `assign_class_seats` permissions are independent of `manage_attendance` and `manage_seat_plans`. The broad grants remain supersets for administrators and custom managers. Assignment APIs validate the class, prepared plan, seat type and enrolled student. Teacher corrections require a reason; scanning, quick marking and session finalization require the broad attendance permission.

Migration `20261005_teacher_portal.sql` replaces the built-in Teacher grants once, preserves all existing non-Teacher grants, and supplies the new narrow capabilities to existing broad managers. Rerunning it does not restore a subsequently revoked grant. Fresh installations use the same baseline. This intentionally replaces previously customized **Teacher** grants. Other roles and additional role memberships are preserved; multiple roles remain additive. View As uses only the effective preview role.

Teacher and Student roles always use portal navigation. An explicitly added administrative role still provides its authorized management workspaces. Legacy academic GET bookmarks under `/admin/` redirect to corresponding portal pages, where permissions are checked again; administrative POSTs are rejected. Unknown or unrelated records remain subject to destination scope checks.

The administrator's personal Profile entry is absent from sidebar, search and avatar menu. Opening the personal Profile page redirects to Settings. Managing other users' profiles remains available under account/profile permissions.

Validation lives in `tests/permission_coverage_integration.py`: isolated migration from broad Teacher grants, revocation/idempotence, non-Teacher grant preservation, role combinations, View As, forbidden native/API mutations, successful scoped corrections/grading/seat assignments, tampered class/student/room/seat IDs, keyboard assignment, mobile and both themes. The test creates and drops a disposable database; it never connects to production.

Release validation: 146 PHP files and both affected JavaScript files passed syntax checks. Permission coverage and shared-grade integration suites passed. The scoped rollout verified all 22 deployment files, the 19 Teacher grants, unchanged analytics baseline and preserved non-Teacher grants. Authenticated live Teacher/Student previews passed portal navigation, denied management entry, empty assigned-class scope, hidden administrator personal Profile, both themes and 390px mobile sizing. Business mutations were tested only in isolated fixtures. Private file and permission backups are excluded from Git; the temporary production inspector was removed and its URL verified as 404.
