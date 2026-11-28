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

A worker's local commit is not `done`. The worker persists a `PublicationRequest`, moves to
`awaiting_action` and returns the worker id, lease generation and lease token only in the in-memory
hand-off. The token is excluded from the diagnostic array. `done` is reserved for a merged pull
request; local execution alone never reaches it.

## Controller-only GitHub publication

`Coder\Publication\PublicationController` is the only standalone coder GitHub write path. The worker
and engine receive no forge client or write credential. Manual Assistant review remains
engine-mediated with its own per-run reviewer credential; this controller does not take over that
path. Existing epic operations likewise retain their reviewer identity.

`Coder\Github\IdentityRouter` names two clients: reviewer and coder. Branch push/delete, draft pull
request find/create/update, `ready_for_review`, `openronin:do-it` / `openronin:in-progress` changes
and coder comments always select the coder. Reviewer actions always select the reviewer. The push
uses the coder's own git credential, not just the API token. Coder comments begin with the
compatibility marker `<!-- openronin:bot -->`.

Each `NamedGithubClient` has its own expected login, credential provider and `selfLogin` cache.
`GhCliTransport` supplies `GH_TOKEN` and `GH_CONFIG_DIR` only in the child-process environment; no
identity selection calls `putenv()` or mutates process-global authorization. The coder provider
rejects a configured `workflow` scope. `ControllerFactory::fromEnv()` performs startup validation:
the resolved human, reviewer and coder logins must be distinct and both clients must complete
non-mutating repository probes. These probes establish current read access only; they do not claim
to prove every future write permission. Read-only polling explicitly selects the coder client.

Immediately before every push, pull-request operation, readiness change, label operation and coder
comment, the controller rechecks the exact lease token and worker, cancellation state, stored and
worktree head SHA and request generation. `DiffGuard` also revalidates a clean worktree and the full
base-to-head patch, including deleted/renamed paths, workflow files, secret content and every
`CODER_PROTECTED_PATHS` glob. A failure emits no write. The C1b non-secret git author configuration
stays in `Worktree` and is not duplicated by publication.

Every action and response has a durable row in `coder_publications`, keyed by repository and an
idempotency key containing job and head, stable across lease generations. A pending or uncertain operation is
reconciled with a GitHub read before any retry. Push and deletion reconcile the branch head; pull
request creation finds the deterministic branch's existing PR and restores its local association;
labels read current labels; comments carry a hidden response key. An exception after a write marks
its outcome uncertain rather than authorizing a blind repeat.

## Terminal reconciliation and explicit retry

`LifecycleController` maps a merged PR to `done`; a closed-unmerged PR, closed issue or author
cancellation maps to `cancelled`. Before terminal release it removes both lifecycle labels through
the coder client. It then releases the lease and worker slot, closes the job cursor, retains all
execution diagnostics and records a deterministic worktree cleanup target. `CleanupScheduler` only
schedules the validated `worktree-root/job-ID` path outside the managed repository; it performs no
inline deletion. An already terminal job produces no GitHub operation and cannot be assigned again.
Closed issues are ignored by intake.

A fresh trusted `openronin:do-it` label event on a terminal issue is an explicit author retry.
`Store::retryIntake()` creates a new event identity and job while retaining the old terminal job and
its processed keys. Reusing an older event key creates nothing. Polling itself never revives a
processed key or starts a worker. The default repository allowlist remains empty.
