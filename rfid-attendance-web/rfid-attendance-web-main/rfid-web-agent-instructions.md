# RFID Web Application — MVP Agent Instructions

## Goal

Build an MVP web application for a capstone RFID Attendance, Event, and Compliance Management System.

The MVP must support:

- user and role management;
- physical RFID registration for users;
- room and scanner registration;
- class attendance records;
- school-wide event management and attendance;
- class-specific recollection/retreat scheduling;
- grade viewing and teacher grade entry;
- internal messages/announcements;
- attendance-based compliance and deficiency tracking;
- role-aware dashboards.

Build only the web application in this repository. The physical ESP32/RFID system and Android application are separate projects.

---

## Technical Constraints

Use only:

- PHP
- MySQL
- HTML5
- CSS
- Vanilla JavaScript
- PDO for database access

Do not introduce application or frontend frameworks.

APIs and libraries used to support the development of the project are allowed (for example, jQuery, Google Maps, and other tools of the same nature). Supporting APIs and libraries should complement the core technology stack rather than replace it.

The application must work with a standard PHP + MySQL environment such as Apache/PHP/MySQL or PHP's local development server.

Use server-rendered PHP where practical. Use vanilla `fetch()` only where asynchronous behavior materially improves the UI.

---

## Application Entry Points

The application has two main authenticated areas:

```text
/admin
/index
```

### `/admin`

Administrative and management interface.

### `/index`

Normal authenticated user interface. Its visible modules and dashboard content depend on the logged-in user's role and permissions.

Unauthenticated users must be redirected to the login page.

---

# Roles

Seed these initial roles:

1. Administrator
2. Teacher
3. Student
4. Staff
5. Event Coordinator

Use role-based permissions instead of scattering role-name checks throughout the application.

A user may hold multiple roles at the same time (for example, Teacher and Dean, or Teacher and Event Coordinator). Keep one primary role to determine the default portal experience and store every assignment in a user-to-role junction table. Effective permissions are the union of all assigned roles. Role-aware navigation, dashboards, calendars, schedules, messages, events, and other shared records must be deduplicated so matching more than one role never repeats the same content.

Minimum permissions:

```text
manage_users
manage_roles
manage_rfid
manage_rooms
manage_academic_structure
manage_teacher_assignments
manage_events
manage_recollections
manage_attendance
manage_attendance_policies
manage_teacher_time_off
view_attendance
manage_grades
view_grades
manage_compliance
view_compliance
send_messages
manage_reports
```

Administrators receive all permissions.

Teachers can manage grades and attendance only for classes assigned to them.

Students can only view their own academic, attendance, event, compliance, and profile data.

Event Coordinators can manage school events and event attendance.

Staff capabilities may be granted through permissions.

---

# Authentication

Implement:

- login;
- logout;
- session-based authentication;
- password hashing using PHP `password_hash()` and `password_verify()`;
- authorization checks for every protected page and write action.

Users may log in using their school/user ID or email.

---

# `/admin` MVP Modules

## 1. Admin Dashboard

Display useful summary cards:

- total active students;
- total teachers;
- total staff;
- registered RFID cards;
- registered rooms;
- upcoming school events;
- upcoming recollections/retreats;
- students with active deficiencies;
- recent attendance activity.

Include an interactive Calendar.js campus calendar that combines school events and recollections/retreats. Authorized users can create school events directly from the calendar through a protected PHP endpoint without reloading the dashboard. Use the application's own event form and permissions instead of Calendar.js's standalone editor. Keep the calendar visually minimal: use compact previous/today/next navigation, a clean month grid, restrained event pills, and a focused day-agenda panel; hide Calendar.js controls and secondary views that are not part of this workflow.

Do not build complex analytics for the MVP.

---

## 2. User Management

Administrators must be able to:

- create a user;
- edit a user;
- activate/deactivate a user;
- assign one primary role and any number of additional roles;
- assign a department/program/section where applicable;
- view the user's RFID assignment;
- reset/change credentials.

The Accounts list must appear directly below the user search and filters. Each account must provide a Manage User action that loads and saves edits through protected PHP endpoints without navigating away from or reloading the Users & RFID page.

Create User and Register RFID must be compact actions in the Users & RFID page header. Each action opens its form in a responsive modal instead of occupying a permanent page section. RFID user selection must use a debounced, bounded server-backed search.

The Register RFID modal must include an animated card-scanner preview. The administrator selects an authenticated scanner, starts a short-lived registration request, taps the physical card, and verifies the captured UID before confirming. Assignment is submitted asynchronously and server validation errors remain visible in the modal.

Every non-administrator user must have a profile picture. New non-administrator accounts receive a neutral default image when no upload is supplied. Administrators can upload or replace profile pictures through Manage User without leaving the page, and non-administrator users can replace their own picture from Profile.

Minimum user fields:

```text
id
school_id
first_name
middle_name
last_name
email
password_hash
role_id (primary role)
department_id
program_id
section_id
status
created_at
updated_at
```

Not every field applies to every role.

---

## 3. Physical RFID User Registration

Use the authenticated physical RFID scanner to capture registrations.

Flow:

```text
Select User
→ Select scanner and start card scan
→ Tap the physical RFID card
→ Preview UID
→ Confirm Assignment
→ Save
```

Provide a two-minute registration window, live status polling, and explicit confirmation before reassigning a card from another user.

RFID UID values must be unique.

Support:

- assign RFID;
- replace RFID;
- deactivate RFID;
- view basic RFID assignment history.

Recommended tables:

```text
rfid_cards
rfid_assignment_history
```

The UI and backend should be structured so the card scan can later be replaced by a real ESP32/API scan without redesigning the entire registration workflow.

---

## 4. Room and Scanner Registration

Administrators can create and manage rooms.

Minimum room fields:

```text
id
room_code
room_name
building
floor
status
```

Also create a scanner/device entity even though the hardware is not implemented yet.

Minimum device fields:

```text
id
device_code
name
room_id
status
last_seen_at
created_at
```

Allow administrators to:

- create/edit/deactivate rooms;
- create card scanner/device records;
- assign one scanner to a room;
- change scanner-room assignment.

Do not implement real heartbeat/device networking yet.

---

## 5. Academic Structure Management

Create the minimum academic entities required by attendance and grading:

```text
departments
programs
sections
school_years
semesters
courses
class_offerings
teacher_assignments
class_schedules
class_enrollments
```

A `class_offering` represents a course taught to a specific section during a specific semester.

Example:

```text
Course: IT 301
Section: BSIT 3A
Semester: First Semester
School Year: 2026-2027
Teacher: Maria Santos
Room: IT-LAB-02
```

Administrators must be able to:

- create/edit courses;
- create/edit sections;
- create school years and semesters;
- create class offerings;
- assign teachers;
- enroll students into class offerings;
- configure class schedules and rooms.

Weekly academic schedules must support configurable regular days off per department. A department may, for example, have Monday or Friday off. Saturday classes are valid and must be treated like any other scheduled class day unless that department explicitly marks Saturday as a day off.

Timetable subject blocks must expose an accessible custom context menu on right-click or the keyboard context-menu shortcut. It provides subject-specific links to Grades and Attendance plus a non-native color palette. Custom colors are a per-browser visual preference and persist by class offering without changing academic data. Teacher links open the authorized class roster; student links open only that student's grade or attendance history for the selected subject.

Keep the UI functional rather than feature-heavy.

---

## 6. Attendance Management

Attendance uses authenticated physical RFID scanners with authorized manual corrections as a fallback.

Attendance records must support:

```text
Present
Late
Absent
Excused
```

Minimum attendance record fields:

```text
id
class_offering_id
student_id
attendance_date
time_in
time_out
status
source
recorded_by
notes
created_at
updated_at
```

`source` may initially contain:

```text
rfid
manual
system
```

Admin/authorized staff can:

- view attendance by date/class/student;
- create a physical RFID attendance record;
- manually correct attendance;
- provide a correction reason.

Teachers can manage attendance only for their assigned classes.

The default attendance management UI must be class-first rather than a large manual-entry form. Authorized users choose an assigned subject in a searchable card/list workspace, open a dated roster, and record or correct each student's attendance from compact row actions. The roster must show date-level status totals and expose session finalization at the roster level. Keep physical RFID and detailed manual selectors under an Advanced tools dialog.

Subject and student queries must be server-paginated and bounded. Realtime search begins after meaningful input, is debounced, cancels superseded requests, and must not fetch an entire school-wide student list. Users with broader attendance permission through an additional non-student/non-teacher role can manage the global subject set; teacher-only users remain limited to assigned offerings. Personal student history stays separate so multi-role users do not receive duplicated management content.

Class scans, manual attendance, and attendance finalization are valid only when the selected class offering has a schedule on the selected date's weekday and its department has not marked that weekday as a regular day off. Saturday attendance is supported when a Saturday class schedule exists.

### Absence rule

Do not depend on an RFID scan to create an absence.

Absence is determined from:

```text
expected enrolled students
minus
students with valid attendance
```

Provide a simple action that finalizes a class attendance session and marks unmatched enrolled students as absent.

---

## 7. Attendance Rules

Create simple configurable attendance rules.

MVP settings:

```text
grace_period_minutes
absent_after_minutes
tardies_per_absence
```

Default seed values:

```text
grace_period_minutes = 15
absent_after_minutes = 30
tardies_per_absence = 3
```

Use the class schedule start time and the effective policy when determining a physical RFID check-in: through the grace period is Present, after the grace period but before `absent_after_minutes` is Late, and at or after `absent_after_minutes` is Absent.

Administrators and other explicitly authorized campus roles can update campus defaults. Teachers can create a per-subject override only for their assigned class offerings, or restore the campus defaults. Every policy change or reset must be written to the audit log. Schedule cards and list rows expose the subject policy through the shared right-click context menu.

---

## 8. School Event Management

Events are school-wide/institutional activities, not class-specific activities.

Examples:

- Foundation Day
- General Assembly
- University Week
- Intramurals

Minimum event fields:

```text
id
title
description
event_date
start_time
end_time
venue
attendance_required
check_in_start
check_in_end
check_out_required
status
created_by
created_at
updated_at
```

Allow eligible participants to be defined by:

- everyone, including students, teachers, staff, administrators, and other active roles;
- all students;
- all teachers;
- all staff;
- department;
- program;
- year level/section;
- selected users.

Event managers can:

- create/edit/cancel events;
- define required participants;
- open/close attendance;
- record physical RFID attendance;
- manually record/correct attendance;
- view attended, late, absent, and incomplete participants.

If check-out is required, a participant who checks in but does not complete the required check-out may be marked `Incomplete`.

Event attendance must later feed the compliance module.

---

## 9. Recollection / Retreat Scheduling

Recollections and retreats are separate from school-wide events.

They are class/section-specific.

Minimum fields:

```text
id
title
type
section_id
date
start_time
end_time
venue
facilitator
attendance_required
status
created_by
created_at
updated_at
```

`type`:

```text
Recollection
Retreat
```

Capabilities:

- schedule a recollection/retreat for one section;
- edit/cancel schedule;
- automatically identify students belonging to the section;
- record physical RFID or manual attendance;
- view attendees and absentees.

Attendance from this module must feed compliance/deficiency tracking.

---

## 10. Compliance & Deficiency Management

The compliance module derives information from existing records.

For the MVP, track:

- class absences;
- class tardiness;
- tardy-to-absence equivalent;
- missed required school events;
- missed required recollections/retreats.

Example:

```text
Student: Juan Dela Cruz

Class Attendance
Absences: 2
Tardies: 4
Tardy Equivalent: 1 absence

Required Events Missed: 1
Required Recollections/Retreats Missed: 0
```

Do not create a large generic rules engine.

Implement straightforward calculation services/functions from the existing data.

Provide:

- per-student compliance view;
- list of students with deficiencies;
- filter by section/program;
- clear source/reason for each deficiency.

Teacher-facing compliance is roster-scoped. A teacher may see only students enrolled
in class offerings assigned to that teacher. Display each assigned subject separately
with its course code so students shared across multiple subjects are not ambiguously
combined. Users with multiple roles receive this teacher scope when using the teacher
portal, without duplicating rows solely because they hold another role.

---

## 11. Reports

MVP reports should be HTML tables with a print-friendly view.

Provide:

- class attendance report;
- student attendance report;
- event attendance report;
- recollection/retreat attendance report;
- tardiness/absence report;
- compliance/deficiency report.

CSV export is optional if it can be implemented cleanly.

---

# `/index` MVP Modules

## 1. Dashboard

Use one dashboard route/layout, but render widgets according to permissions and role.

### Student

Show:

- today's classes;
- attendance summary;
- latest grades;
- upcoming required events;
- upcoming recollection/retreat;
- compliance warning/summary;
- unread messages.

### Teacher

Show:

- today's assigned classes;
- class attendance summary;
- classes requiring grade entry;
- upcoming events;
- messages.

### Staff / Event Coordinator

Show relevant:

- upcoming events;
- assigned responsibilities;
- event attendance;
- messages.

### Administrator

May display the administrative summaries and provide a link to `/admin`.

---

## 2. Attendance

### Student

Can view only their own:

- attendance per course;
- present/late/absent counts;
- attendance history;
- attendance percentage.

When opened from the timetable, the selected subject attendance view includes a Calendar.js month view. Attendance dates tint the entire day cell by Present, Late, Absent, or Excused status and remain linked to the corresponding history row.

### Teacher

Can:

- view assigned classes;
- view class attendance;
- create/correct attendance for assigned classes.

The teacher class attendance workspace includes both the date picker and a Calendar.js month view. Selecting a calendar date loads the server-paginated class roster for that date; recorded sessions appear as calendar entries.

---

## 3. Grades

### Student

Read-only grade view.

Display grades by:

- semester;
- class/course.

### Teacher

Teachers can manage grades only for assigned class offerings.

MVP grade fields:

```text
prelim
midterm
final
overall
status
```

Allow:

- grade entry;
- grade editing;
- save;
- finalize.

The default management view lists the subjects available to the current user, with a card/list toggle and realtime server-backed course search. Opening a subject loads a bounded, paginated, searchable roster instead of fetching the school-wide student directory. Each roster row has an SVG edit action that opens draft-save and finalize controls without reloading the page. Keep the detailed class/student selector as an Advanced Search modal.

Apply this workflow to every role granted `manage_grades`. Teachers are restricted to assigned class offerings; a non-teaching academic records role explicitly granted the permission may manage its authorized broader scope. Finalized grades are read-only for teachers unless an administrator or authorized academic records role reopens them.

Do not implement complex grade computation rules in the MVP.

---

## 4. Events

Students can view:

- upcoming events;
- required/optional status;
- attendance status;
- past events.

Teachers/staff can view events relevant to them.

Authorized Event Coordinators can reach event management controls.

Events may have an optional banner image. Calendar event pills must be visibly interactive and open the applicable in-page management flow; formation entries route to their dedicated management module. Profile pictures and event banners are stored as validated JPG, PNG, or WebP files with randomized filenames, while the database stores nullable web-relative paths. Uploaded files must never be executable and replaced managed files should be removed.

---

## 5. Recollections / Retreats

Students can view their section's:

- upcoming recollection/retreat;
- date/time;
- venue;
- attendance/completion status.

Teachers/advisers may view schedules for sections relevant to them.

---

## 6. Compliance & Deficiencies

Students can view only their own compliance summary.

Show:

- absences;
- tardies;
- tardy equivalent;
- missed required events;
- missed recollection/retreat;
- current deficiencies.

The interface must explain why each deficiency exists.
Keep the general summary, and also provide a per-subject compliance breakdown for
class absences, tardies, tardy equivalents, and subject-level deficiencies. Student
attendance, grades, and per-subject compliance records must support both card and
list views without losing search, sorting, or pagination behavior.

---

## 7. Schedule

Students see their enrolled class schedule.

Teachers see their teaching schedule.

Teachers can schedule personal teaching time off from the shared calendar. Use the label **Schedule time off** for both a single teaching day and a date range. Right-clicking a calendar date opens the one-day flow; the dashboard action opens the date-range flow. These records are audited and appear on the affected teacher and enrolled students' calendars and schedules. Any class date covered by an active teacher time-off record is suspended: RFID scans, manual attendance, session finalization, and automatic absence generation must be rejected so students are never marked absent for a class the teacher cancelled.

The teacher Seat Plans page begins with a searchable card/list browser of assigned subjects. Show the subject's room and whether its room has a configured seat plan; load the full seat-plan workspace only after a subject is selected.

Schedule entities are interconnected. A course or section opens the subject's class roster, while an assigned room opens that subject's seat plan. The timetable context menu includes View Students, View Grades, View Attendance, View Seat Plan, attendance policy controls when permitted, and color customization; do not duplicate View Section or View Room actions there.

Display:

```text
day
start_time
end_time
course
section
room
```

---

## 8. Messages / Announcements

Implement a simple internal messaging system, not real-time chat.

Support:

- inbox;
- sent messages;
- compose;
- read/unread;
- announcements.

Minimum recipient types:

```text
individual user
section/class
role
all users
```

Permissions determine who can send broadcasts.

Examples:

```text
Teacher → Student
Teacher → Assigned Class
Admin → All Students
Event Coordinator → Event Participants
```

Minimum tables:

```text
messages
message_recipients
```

---

## 9. Profile

Show:

- name;
- school/user ID;
- role;
- department/program/section;
- email;
- RFID registration status.

Allow:

- password change;
- limited editable contact/profile data.

Authoritative academic and role data should not be editable by students.

---

# Data Model

Create normalized MySQL tables sufficient for the MVP.

Expected core entities:

```text
users
roles
user_roles
permissions
role_permissions

rfid_cards
rfid_assignment_history

departments
programs
sections
school_years
semesters
courses
class_offerings
class_enrollments
teacher_assignments
class_schedules

rooms
devices

attendance_records
attendance_settings

events
event_target_groups
event_participants
event_attendance

recollections
recollection_participants
recollection_attendance

grades

messages
message_recipients

audit_logs
```

Use foreign keys where appropriate.

Use `created_at` / `updated_at` on important mutable records.

Use soft status fields where deleting a record would destroy useful history.

---

# Suggested Project Structure

```text
rfid-web/
├── admin/
│   ├── index.php
│   ├── users/
│   ├── rfid/
│   ├── rooms/
│   ├── academics/
│   ├── attendance/
│   ├── events/
│   ├── recollections/
│   ├── compliance/
│   └── reports/
│
├── index/
│   ├── index.php
│   ├── attendance/
│   ├── grades/
│   ├── schedule/
│   ├── events/
│   ├── recollections/
│   ├── compliance/
│   ├── messages/
│   └── profile/
│
├── auth/
│   ├── login.php
│   └── logout.php
│
├── api/
│   ├── rfid/
│   ├── attendance/
│   ├── events/
│   ├── grades/
│   └── messages/
│
├── includes/
│   ├── config.php
│   ├── database.php
│   ├── auth.php
│   ├── permissions.php
│   ├── validation.php
│   └── helpers.php
│
├── services/
│   ├── AttendanceService.php
│   ├── ComplianceService.php
│   ├── EventService.php
│   └── RfidService.php
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│
├── database/
│   ├── schema.sql
│   └── seed.sql
│
├── login.php
└── README.md
```

The exact structure may be adjusted if a simpler organization is clearer, but keep responsibilities separated.

---

# UI Requirements

Create a clean responsive administrative/student portal using custom CSS.

Required shared components:

- sidebar/navigation;
- top header;
- cards;
- tables;
- forms;
- modal/dialog pattern if needed;
- status badges;
- empty states;
- validation/error states.

Support desktop first, then make core pages usable on mobile widths.

Use semantic HTML and reusable CSS classes.

Use Flatpickr for all date and time selectors, with `allowInput` enabled and native mobile/browser pickers disabled so the interface remains consistent. Date inputs must show a calendar affordance and time inputs a clock affordance. Present every user-facing time in 12-hour AM/PM format, including Flatpickr controls, Calendar.js labels, tables, cards, and date-time summaries. Preserve `YYYY-MM-DD` for date values. Normalize submitted AM/PM values at the PHP boundary and store them in native MySQL `TIME`/`DATETIME` columns; never store formatted AM/PM strings in place of temporal data types.

All fetched entity collections must provide appropriate search or filtering controls. This includes users, departments, programs, sections, courses, class offerings, rooms, devices, events, recollections, and similar datasets. Large tables should support search, sorting, and pagination, while entity selection controls should be searchable. Prefer fast, dependency-light libraries and server-side filters for high-volume records. Server-backed searches must begin automatically after the user starts typing, with a short debounce, cancellation of stale requests, bounded result sets, and pagination where appropriate.

Do not spend MVP effort on elaborate animation.

---

# Security and Data Integrity

Implement at minimum:

- prepared PDO statements;
- password hashing;
- session regeneration on successful login;
- server-side validation;
- output escaping using `htmlspecialchars`;
- permission checks on server-side actions;
- CSRF protection for state-changing form submissions;
- unique constraints for school IDs and RFID UIDs;
- foreign keys;
- audit logs for important administrative changes.

Never trust hidden form fields or client-side role checks as authorization.

---

# Physical RFID Behavior

The MVP needs a reusable card scanner utility.

Create:

```text
RfidService
```

It should generate realistic hexadecimal UIDs, for example:

```text
A3F91C02
04B18F9A72C180
```

Use it for:

1. RFID registration;
2. class attendance testing;
3. event attendance testing;
4. recollection/retreat attendance testing.

Every card scan must pass through the same application-level processing functions that a future physical scanner request would use.

Do not duplicate attendance logic specifically for the scanner UI.

---

# Seed Data

Provide useful demo data.

Minimum:

- 1 Administrator;
- 3 Teachers;
- 15-20 Students across at least 2 sections;
- 2 Staff users;
- 1 Event Coordinator;
- 7 color-coded departments (CAS, CBA, CCS, CED, COE, CON, and CTH) and at least 2 seeded programs;
- 4-6 Courses;
- teacher assignments;
- class schedules;
- several rooms;
- card scanners;
- RFID cards for several users;
- attendance history;
- grades;
- 2 school-wide events;
- 1 recollection;
- 1 retreat;
- sample messages;
- at least several students with detectable compliance deficiencies.

Document demo login credentials in the README.

---

# MVP Workflow Demonstrations

The completed application must support these end-to-end flows.

## Flow A — User RFID Registration

```text
Admin logs in
→ opens User Management
→ selects student
→ opens RFID registration
→ performs Card scan
→ confirms UID
→ UID is stored and visible on profile
```

## Flow B — Class Attendance

```text
Teacher/Admin selects a scheduled class
→ starts/views attendance
→ physical RFID scan for student
→ system identifies student
→ system determines Present/Late
→ attendance record appears
→ attendance is visible to student
```

## Flow C — Absence

```text
Class attendance is finalized
→ system finds enrolled students without attendance
→ marks them Absent
→ absence appears in compliance calculations
```

## Flow D — Event Attendance

```text
Event Coordinator creates school-wide event
→ assigns required audience
→ physical RFID check-in
→ attendance is recorded
→ missing required participant becomes event deficiency
```

## Flow E — Recollection/Retreat

```text
Admin schedules recollection for Section A
→ Section A students automatically become participants
→ attendance is recorded
→ missed required attendance appears in compliance
```

## Flow F — Grades

```text
Admin assigns teacher to class
→ teacher opens Grades
→ class is visible
→ teacher enters/finalizes grades
→ enrolled student sees grade
```

## Flow G — Messages

```text
Teacher sends announcement to assigned class
→ students receive it in Messages
→ unread state is displayed
```

---

# Acceptance Criteria

The MVP is complete when:

- database schema and seed data can be imported from scratch;
- users can log in and authorization works;
- `/admin` and `/index` behave according to role/permissions;
- admins can manage users and roles;
- physical RFID registration works;
- rooms and card scanners can be registered;
- academic structure, classes, enrollments, schedules, and teacher assignments work;
- physical RFID class attendance works;
- attendance can classify Present/Late and finalize Absent students;
- school-wide events can be created and monitored;
- class-specific recollections/retreats can be scheduled and monitored;
- compliance calculations detect attendance/event/recollection deficiencies;
- teachers can enter grades for assigned classes;
- students can view their own grades;
- messages/announcements work;
- dashboards show useful role-specific data;
- core pages are responsive and navigable;
- server-side security checks are present;
- README contains setup instructions and demo credentials.

---

# Implementation Order

Build in this sequence:

1. Project structure and shared layout
2. Database schema and seed data
3. Authentication and permissions
4. User/role management
5. Academic structure
6. Room/device management
7. Physical RFID registration
8. Class attendance
9. Event management and attendance
10. Recollection/retreat scheduling and attendance
11. Compliance/deficiency calculations
12. Grades
13. Messages
14. Role-aware dashboards
15. Reports
16. Final validation, responsive cleanup, README

After each major module, verify its end-to-end workflow before moving to the next.

---

# Development Principles

Prioritize:

- correct workflows;
- understandable code;
- reusable PHP functions/services;
- normalized data;
- server-side authorization;
- clear UI;
- easy future replacement of physical RFID with the real ESP32 system.

Keep the MVP focused. Do not add unrelated school-management features unless they are required to support the workflows above.
