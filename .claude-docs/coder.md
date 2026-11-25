# Standalone coder storage

`GpsLab\WorkspaceAssistant\Coder\Store` is the durable storage and lease dispatcher for standalone
coder jobs. It is not connected to the Bot loop and starts no service. Its default file is
`state/coder.sqlite`; the Bot's `Database` continues to use `state/state.sqlite`. Each `Store`
instance owns a PDO connection, enables WAL, foreign keys and a busy timeout, and can reconnect after
`disconnect()`. There is no shared or static connection.

## Migrations and identities

Numbered migrations run in ascending order inside an immediate transaction and are recorded in
`coder_schema_migrations`. Opening an up-to-date store is safe. Jobs and workers are collections with
stable integer ids. Job rows retain repository, issue and pull-request numbers, branch, worktree,
session id, current head, diagnostics, progress, errors, retry/dispute counters, cancellation and
lease history. Processed intake keys are unique per repository; retry and dispute records are
separate rows and never implicitly change job state.

## Lifecycle and leases

The durable states are `queued`, `starting`, `running`, `reconciling`, `waiting_ci_review`,
`awaiting_action`, `cancelled`, `failed` and `done`.

- `assign()` alone moves `queued` to `starting`, selecting one job and one free worker in one
  immediate transaction. A worker can own only one active job, and a job has only one current lease.
- A current worker can move `starting` to `running`, `reconciling`, `waiting_ci_review` or
  `awaiting_action`.
- `running` can move to `reconciling`, `waiting_ci_review` or `awaiting_action`.
- `reconciling` can move to `running`, `waiting_ci_review` or `awaiting_action` while its lease is
  valid. After lease expiry it has no owner and only `requeue()` can return it to `queued`.
- `waiting_ci_review` can move to `running`, `reconciling` or `awaiting_action`.
- `awaiting_action` can move to `starting`, `running`, `reconciling` or `waiting_ci_review`.
- Any non-terminal job can become `cancelled`. The current lease holder can record `done` or `failed`.

Heartbeats, context updates, transitions and completion require the matching worker id and lease
token and reject an expired lease. Expiry is handled by `reconcileExpiredLeases()`, which releases
worker capacity and leaves the job in `reconciling`. Only explicit `requeue()` permits a new
assignment; it receives a new token and an incremented lease generation. Terminal states never
return to assignment.
