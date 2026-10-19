# Audit Trail

The management workspace is `/admin/?page=audit-trail`. Access requires the effective
role's `view_audit_logs` permission, including during View As. The JSON reader at
`/admin/audit-trail.php` applies the same checks. There are no editing or deletion
actions for audit entries.

## Tracking

Run `php scripts/install-audit-trail.php` after deploying the code and after any
schema migration that adds or changes tables or foreign keys. The installer reads
the actual schema, validates it, extends the existing `audit_logs` table, and installs
three row triggers on each business table. Repeated installation is safe and skips
unchanged trigger definitions. `audit_tracking_tables` records installed signatures.

- INSERT, UPDATE, and DELETE are recorded with the primary key, redacted row
  snapshots, changed field names, actor snapshot, request ID, IP, and server time.
- No-op updates create no entry. Failed statements and transaction rollbacks leave
  no phantom row changes. FK cascades and SET NULL effects are captured explicitly.
- The schema must use InnoDB and stable primary keys. Cyclic delete cascades,
  composite foreign keys, and cascading primary-key updates require an explicit
  policy; installation rejects them instead of silently missing changes.
- Existing application action summaries remain available alongside database changes.
  Their request IDs connect them to the underlying row changes.
- Passwords, hashes, tokens, RFID credentials, private message contents, and
  disciplinary narrative/evidence are redacted before storage and indexing.
- Application and scanner connections set trusted actor metadata. An external SQL
  connection without application context is labeled as a database connection; the
  human operator cannot be inferred from a shared database account.
- Schema DDL and TRUNCATE are not row changes and are not captured by these triggers.
  Re-run installation after schema changes. Do not use TRUNCATE for normal deletion.
- Earlier history is preserved; historical row snapshots cannot be reconstructed.
  Available account identities are added to legacy entries and explicitly labeled
  as the profile at setup, rather than claiming a historical name snapshot.

## Retrieval and performance

Filters use prepared statements and indexed identifiers, actors, actions, types,
operations, sources, and server date ranges. Text search uses an InnoDB FULLTEXT
index with word-prefix matching and exact identifier matching. Short queries use
indexed prefixes. Search documents are bounded; full redacted row snapshots remain
available in entry details. Private values are never indexed.

Lists fetch 25, 50, or 100 lightweight entries with keyset pagination, without deep
OFFSET scans or full-history count queries. Snapshots load only when opening an
entry. Actor suggestions are bounded; clicking any actor in the history filters
directly, including actors outside the suggestions.

## Deployment and checks

`python scripts/deploy-audit-trail.py --deploy` checks production drift, backs up
scoped files, existing schema/trigger definitions, grants, and historical audit
entries to ignored private storage, then installs tracking before releasing the UI.
It restores the built-in Administrator audit grant without expanding other roles.
Uploads are verified byte-for-byte; trigger coverage and access are checked, and
the temporary secret-protected migration runner is removed.

Rollback restores scoped runtime files, the previous managed triggers, and the
Administrator's previous grant. Additive columns/indexes and new history remain
intact. It never deletes business data or audit history.

`tests/audit_trail_integration.py` uses an isolated MariaDB database and browser to
check transaction behavior, cascades, redaction, indexes, large-history queries,
filters, sorting, pagination, details, themes, mobile sizing, permissions, and
session expiry. Bulk-deletion and Compliance integration suites also exercise the
application with tracking enabled. Production smoke tests use read-only navigation.
