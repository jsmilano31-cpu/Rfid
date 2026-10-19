# Shared lecture and laboratory grades

Web grading groups offerings by **course + section + semester**. The section fixes the program and the semester fixes the school year. Teachers, rooms, meeting times, component status, attendance, and seat assignments remain attached to each component.

`SharedGradeService` supplies the shared subject, enrollment, and grade read models used by grade management, personal grades, transcripts, profiles, dashboards, and academic analytics. The roster is the union of component enrollments, with one row per student. A teacher assigned to either component can manage its shared subject grades. Unrelated course/section/semester groups remain separate, including under View As.

The earliest offering ID is the workspace representative. Existing component links resolve to that workspace. Adding the other component keeps the representative and existing grade IDs. New grades are written once. When identical older grade rows exist on both components, edits and reopen operations synchronize those rows without removing their IDs or history.

Different stored scores or statuses yield **Needs review** and no inferred shared score. An authorized academic records manager must explicitly choose an existing score set as a shared draft, review it, and finalize it. Teachers can inspect the disagreement but cannot reconcile or reopen protected grades. Existing source rows and audit/history entries are preserved; no bulk data conversion or schema migration is required.

The component switches check the exact course, section, and semester. Existing components, including inactive ones, are disabled and link to their editors. Editing excludes the current record. Server validation and the existing `uq_offering_component` index enforce the same uniqueness even if a client bypasses the controls or two requests race. Creating a duplicate no longer appends schedules; use the existing offering editor's meeting controls instead.

Grade writes lock the component group and commit with audit/history changes. An offering with shared grades cannot be moved to another course, section, or semester. Existing foreign-key deletion protections remain in place.

Analytics counts one finalized subject result per student, unions historical enrollment eligibility, and looks up the policy on the actual original grade source. Conflicting component results are excluded from score distributions. Transcript completion counts finalized results; credit weighting is withheld if finalized results lack configured units.

Run `python tests/shared_grades_integration.py` with the disposable MariaDB fixture on port 3340. It covers shared rosters, component-first creation orders, distinct scopes, finalized protection, explicit reconciliation, retained IDs and history, historical policy lookup, duplicate creation, protected identity edits, direct navigation, keyboard controls, both themes, and mobile layout. Regression suites include grading policy, analytics, and bulk deletion tests. Never run fixture operations against production.

`python scripts/deploy-shared-grades.py --apply` checks production drift, makes private file/data backups, uploads only the listed web files with byte verification, and performs read-only shared-grade checks. It preserves the analytics baseline and removes its temporary token-protected inspector. It performs no grade reconciliation or other business-data writes.
