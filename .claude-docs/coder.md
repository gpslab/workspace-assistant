# Standalone coder intake and storage

`GpsLab\WorkspaceAssistant\Coder\Store` is the durable storage and lease dispatcher for standalone
coder jobs. Its default file is `state/coder.sqlite`; the Bot's `Database` continues to use
`state/state.sqlite`. Each `Store` instance owns a PDO connection, enables WAL, foreign keys and a
busy timeout, and can reconnect after `disconnect()`. There is no shared or static connection.

## Explicit intake

`Coder\IntakeController`, composed into `Bot`, is the only standalone polling component. With the
default empty `REPOSITORY_EXECUTORS` map it is not wired, performs no request and starts no worker.
The map assigns each listed repository exactly one executor, `openronin` or `coder`; only the
`coder` entries form the intake allowlist. Duplicate assignments and unknown executors fail
configuration validation. This map drives Workspace Assistant guards only: it does not reconfigure
or drain the external Openronin service.

A scheduled `poll()` examines only open issues in coder-routed repositories through read-only issue,
issue-event and GraphQL content-metadata APIs. An issue is admitted only when its current
`openronin:do-it` label is proven by a latest `labeled` event from `CODER_INTAKE_AUTHOR`, a separately
configured trusted human login. GitHub also touches the issue's generic `updated_at` when applying
that label, so intake instead compares the event's `created_at` with the content-specific
`lastEditedAt`. Original content has no edit timestamp and is admissible; otherwise the content edit
must be strictly earlier than approval. Equal content-edit and approval timestamps fail closed
because GitHub's one-second resolution cannot order a same-second edit and approval. This binds
approval to the title and body current at the trusted label event. A later edit, missing or malformed
approval or content-edit metadata, a label by itself, comments, arbitrary issues and unlisted
repositories create no instruction: a fresh trusted label at an unambiguously later time is required
after such an edit. The immutable label-event id is part of the
`(repository, event_key)` intake identity. `Store::enqueueIntake()` records that identity and creates
the queued job in one immediate transaction, so a repeated or concurrent event returns without
another job. The same transaction also checks for an active `(repository, issue_number)` job before
inserting, so distinct re-label events cannot create concurrent jobs for one issue.

The controller owns both durable cursors in `state/coder.sqlite`: the last examined issue number in
each repository's server-filtered, creation-ordered scan and the repository starting the next
round-robin pass. Passes normally resume from page one and skip only issue identities at or before
the issue cursor, so closing an earlier issue cannot shift a numeric offset past new work. When the
page cap contains only already-cursored records, the next page is retained for the following pass;
examining new work returns the durable scan to page one. This bounds each pass without permanently
starving candidates beyond the cap. Reaching the end resets the cursor to consider older re-labelled
issues. `Coder\Worker` only consumes leased jobs and never polls GitHub.
`CODER_INTAKE_POLL_SECONDS`, `CODER_INTAKE_TICK_BUDGET`, `CODER_INTAKE_WORK_BUDGET` and
`CODER_INTAKE_REQUEST_TIMEOUT` bound the pass. A repository failure is logged as a warning with its
cursor retained and isolated; a slow or full event page fails closed. Intake adds no GitHub write and
no production worker start.

`Coder\RoutingGuard` rejects coder routing at startup while an epic containing the repository is
`running`; `paused`, `blocked` and `done` epics permit startup. Resuming a paused or blocked epic
whose plan contains a coder-routed repository is refused, so a routing block cannot put the Bot into
a restart crash loop. The guard rejects new epic plans containing a coder-routed repository before
`Database::epicCreate()`, and `Epic\Orchestrator` checks it again immediately before applying
`openronin:do-it`, blocking the task and epic with an explicit reason instead of labelling the issue.

## Migrations and identities

Numbered migrations run in ascending order inside an immediate transaction and are recorded in
`coder_schema_migrations`. Opening an up-to-date store is safe. Jobs and workers are collections with
stable integer ids. Job rows retain repository, issue and pull-request numbers, branch, worktree,
session id, current head, diagnostics, progress, errors, retry/dispute counters, cancellation and
lease history. The generic `job()`/`jobs()` read model omits the lease token; only `assign()` returns
a newly issued token to its owner. Processed intake keys are unique per repository; retry and dispute
records are separate rows and never implicitly change job state.

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
