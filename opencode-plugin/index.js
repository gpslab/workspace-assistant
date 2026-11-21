// @ts-check

/**
 * OpenCode plugin of the Workspace Assistant, in the two modes of a run.
 *
 * OpenCode has no command hooks: a PreToolUse `Bash` hook of Claude Code has no counterpart there,
 * only JavaScript plugins. Copying the PM gate, the review commit hook or the secret detector into a
 * plugin would put a second implementation of one security check next to the PHP one, and only the PHP
 * one is covered by phpunit. This plugin therefore holds no such logic: it starts the PHP hooks with the
 * engine-neutral input and turns their deny into `effect: "deny"` with the reason the hook produced.
 *
 * Every run starts the secret guard: `guard(event)` runs `bin/secret-guard-hook.php` with the command
 * alone, denies a call that carries a secret and lets every ordinary command pass. The rule itself is
 * `src/SecretGuard.php`; this file only starts the hook and translates its deny.
 *
 * Gate mode — the run carries a gate kind (the `pm` and `review` modes, and the epic runs, which are
 * those two modes with the gate kind of the run kind): after the guard it dumps the session history and
 * starts `bin/pm-gate-hook.php` and, on a review run, `bin/review-commit-hook.php`.
 *
 * Guard-only mode — the run carries no gate kind (`arch`, `/compact`): there is no gate to run and no
 * state directory, run id or gate kind to validate, so the guard runs alone. The session history is not
 * read: the guard needs no history. The plugin is listed in the configuration of every run.
 *
 * The plugin is a plain ES module: no build step and no import of `@opencode/plugin`. OpenCode 2.3.0
 * loads a default-exported `{id, setup(ctx)}` object. It reads no types at runtime, so phpunit can
 * run it under bare `node` with a stub `ctx`.
 *
 * Fail-closed: any failure of this file or of a PHP hook denies the call, and so does a gated run whose
 * environment is invalid or whose review-commit flag does not match the run. A gate that lets a command
 * through because it crashed is no gate. This holds in both modes: a guard that cannot decide denies.
 *
 * The environment of a gated run carries the gate variables (state directory, run kind, run id, PHP
 * binary, review-commit flag); a run with no gate kind carries none of them, which is what makes the
 * mode. The input validation of the gate itself stays in the PHP hook: a gate variable that is missing
 * or empty in a run that does reach the gate is invalid and denied. The same holds the other way round:
 * a run that carries a state directory, a run id or a review flag and no gate kind is a broken wiring,
 * not an `arch` run, and is denied instead of silently running ungated.
 */

import {spawnSync} from "node:child_process";
import {mkdtempSync, rmSync, writeFileSync} from "node:fs";
import {tmpdir} from "node:os";
import {dirname, join} from "node:path";
import {fileURLToPath} from "node:url";

/** Root of the bot: this file is `<bot>/opencode-plugin/index.js`. */
const BOT_ROOT = dirname(dirname(fileURLToPath(import.meta.url)));

const PM_HOOK = join(BOT_ROOT, "bin", "pm-gate-hook.php");
const REVIEW_HOOK = join(BOT_ROOT, "bin", "review-commit-hook.php");
const GUARD_HOOK = join(BOT_ROOT, "bin", "secret-guard-hook.php");

const STATE_ENV = "WORKSPACE_ASSISTANT_GATE_STATE_DIR";
const KIND_ENV = "WORKSPACE_ASSISTANT_GATE_KIND";
const RUN_ENV = "WORKSPACE_ASSISTANT_GATE_RUN_ID";
const PHP_ENV = "WORKSPACE_ASSISTANT_GATE_PHP";
const REVIEW_ENV = "WORKSPACE_ASSISTANT_REVIEW_COMMIT_GATE";
const GIT_PHP_ENV = "WORKSPACE_ASSISTANT_PHP";

/** What the model sees when the shim or a hook fails without a reason of its own. */
const FAILURE_REASON = "The command was blocked: the gate failed.";

/** The same for the secret guard, whose failure is no gate failure in either mode. */
const GUARD_FAILURE_REASON = "The command was blocked: the secret guard failed.";

/** What the model sees when the review-commit flag of the run is not the one this run needs. */
const REVIEW_REASON = "The command was blocked: the review commit gate of this run is not the one the run needs.";

/** What the model sees when the gate variables of a run are set without a gate kind. */
const INCOMPLETE_ENV_REASON = "The command was blocked: the gate environment of this run is incomplete.";

/**
 * @typedef {{deny: boolean, reason: string}} Decision
 */

/** @param {string} name @returns {string} */
function env(name) {
    const value = process.env[name];
    return typeof value === "string" ? value : "";
}

/**
 * The command of the shell call. `resources` holds it as a string; an array is joined and any other
 * shape is serialised, so nothing is silently dropped from what the hooks inspect.
 *
 * @param {any} event
 * @returns {string}
 */
function commandOf(event) {
    const resources = event.resources;
    if (typeof resources === "string") {
        return resources;
    }
    if (Array.isArray(resources)) {
        return resources.map((part) => (typeof part === "string" ? part : JSON.stringify(part))).join(" ");
    }
    return resources === null || resources === undefined ? "" : JSON.stringify(resources);
}

/** @param {any} error @returns {string} */
function messageOf(error) {
    return error instanceof Error && error.message !== "" ? error.message : String(error);
}

/**
 * The PHP binary of the run. The gate variable is set on a gated run only; `GitHooks::PHP_ENV` is set
 * on every run of both engines, so the guard of a run with no gate kind uses it. `php` from `PATH` is
 * the last resort, as before.
 *
 * @returns {string}
 */
function phpBin() {
    return env(PHP_ENV) || env(GIT_PHP_ENV) || "php";
}

/**
 * Runs one PHP hook and reads its answer: `exit 0` with no output allows, `exit 0` with the deny JSON of
 * `PmGateHook::denyJson()` denies, anything else (a non-zero exit, a signal, a timeout, unreadable
 * output) denies too.
 *
 * @param {string} script
 * @param {string[]} args
 * @param {{command: string, history?: string, engine: string}} input
 * @param {string} failure what the model sees when the hook fails without a reason of its own
 * @returns {Decision}
 */
function runHook(script, args, input, failure = FAILURE_REASON) {
    const result = spawnSync(phpBin(), [script, ...args], {
        input: JSON.stringify(input),
        encoding: "utf8",
        timeout: 120_000,
        maxBuffer: 16 * 1024 * 1024,
    });
    if (result.error || result.status === null) {
        return {deny: true, reason: failure + " " + messageOf(result.error ?? "no exit status")};
    }
    if (result.status !== 0) {
        const stderr = typeof result.stderr === "string" ? result.stderr.trim() : "";
        return {deny: true, reason: stderr !== "" ? stderr : failure};
    }

    const output = typeof result.stdout === "string" ? result.stdout.trim() : "";
    if (output === "") {
        return {deny: false, reason: ""};
    }

    let parsed;
    try {
        parsed = JSON.parse(output);
    } catch {
        return {deny: true, reason: failure};
    }
    const decision = parsed?.hookSpecificOutput;
    if (decision?.permissionDecision === "deny") {
        const reason = decision.permissionDecisionReason;
        return {deny: true, reason: typeof reason === "string" && reason !== "" ? reason : failure};
    }

    // Output that is not the deny JSON of the hooks is not an answer that may be trusted.
    return {deny: true, reason: failure};
}

/**
 * The secret guard of every run, with or without a gate kind: the hook denies a command that carries a
 * secret and allows every ordinary one. No session history is read: the guard needs none.
 *
 * @param {any} event
 * @returns {Decision}
 */
function guard(event) {
    const command = commandOf(event);
    // A `shell` action with no readable command has no legitimate use, and a command the plugin cannot
    // read is a command the guard cannot scan: deny it fail-closed rather than let every call through.
    if (command.trim() === "") {
        return {deny: true, reason: GUARD_FAILURE_REASON + " The shell call carries no command."};
    }
    return runHook(GUARD_HOOK, [], {command, engine: "opencode"}, GUARD_FAILURE_REASON);
}

/**
 * The gate variables of the run that are set while the gate kind is empty, the mode being this file's
 * business: `JobRunner` sets them together on a gated run, so a run that carries some of them and no
 * kind is a broken wiring and not an `arch` run. Every one of them is invalid on its own — the gate
 * could not run and the run would silently be ungated.
 *
 * The review flag is read from `process.env` and not through `env()`: an empty flag is a present flag.
 *
 * @returns {string[]}
 */
function gateVariablesWithoutKind() {
    const set = [];
    if (env(STATE_ENV) !== "") {
        set.push(STATE_ENV);
    }
    if (env(RUN_ENV) !== "") {
        set.push(RUN_ENV);
    }
    if (process.env[REVIEW_ENV] !== undefined) {
        set.push(REVIEW_ENV);
    }
    return set;
}

/**
 * The whole check of a run that carries a gate kind: the secret guard first, then the review-commit flag
 * of the run, the dumped history, the PM gate and, where the flag is `1`, the commit hook. The guard goes
 * first because the PM gate records an applied skip: a skip must not be counted for a command the guard
 * then denies. A guard deny is final. Every path out of it that is not an explicit allow denies.
 *
 * @param {any} event
 * @param {any} history
 * @returns {Decision}
 */
function decide(event, history) {
    let guarded;
    try {
        guarded = guard(event);
    } catch (error) {
        return {deny: true, reason: GUARD_FAILURE_REASON + " " + messageOf(error)};
    }
    if (guarded.deny) {
        return guarded;
    }

    const stateDir = env(STATE_ENV);
    const kind = env(KIND_ENV);
    const runId = env(RUN_ENV);

    // A missing or empty state directory, kind or run id is not checked here: it falls through to the
    // PHP hook, whose `bad arguments` check is the single validation of the gate input (exit 2 denies).
    // The review-commit flag is the one variable only this file can check, because the PHP review hook
    // is started with the state directory alone. It is read from `process.env` and not through `env()`:
    // an empty flag is an invalid environment, and `env()` folds it into the same `""` as an unset one.
    const reviewFlag = process.env[REVIEW_ENV];
    const reviewEnabled = reviewFlag === "1";
    if (typeof reviewFlag === "string" && !reviewEnabled) {
        return {deny: true, reason: REVIEW_REASON + " " + REVIEW_ENV + " is set to something other than 1."};
    }
    if ((kind === "review" || kind === "epic_review") && !reviewEnabled) {
        return {deny: true, reason: REVIEW_REASON + " A " + kind + " run carries the flag as " + REVIEW_ENV + "=1."};
    }

    const dir = mkdtempSync(join(tmpdir(), "workspace-assistant-gate-"));
    try {
        const historyPath = join(dir, "history.json");
        // Exactly what `ctx.session.context()` returned: the reader of the PHP hook takes both the bare
        // message list of OpenCode and the wrapped shape of the fixtures.
        writeFileSync(historyPath, JSON.stringify(history), {mode: 0o600});
        const input = {command: commandOf(event), history: historyPath, engine: "opencode"};

        const pm = runHook(PM_HOOK, [stateDir, kind, runId], input);
        if (pm.deny) {
            return pm;
        }
        if (reviewEnabled) {
            const review = runHook(REVIEW_HOOK, [stateDir], input);
            if (review.deny) {
                return review;
            }
        }

        return {deny: false, reason: ""};
    } finally {
        rmSync(dir, {recursive: true, force: true});
    }
}

/**
 * Applies a decision to the event: a deny blocks the call and the model sees the reason.
 *
 * @param {any} event
 * @param {Decision} decision
 */
function apply(event, decision) {
    if (decision.deny) {
        event.effect = "deny";
        event.message = decision.reason;
    }
}

/**
 * Evaluates one shell call: the guard alone when the run carries no gate kind, the guard and the gate
 * otherwise. The session history of a gated run is read synchronously when the API returns it directly, and awaited
 * when it returns a promise; the PHP hooks always run synchronously.
 *
 * @param {any} ctx
 * @param {any} event
 * @returns {any}
 */
function evaluate(ctx, event) {
    if (env(KIND_ENV) === "") {
        const withoutKind = gateVariablesWithoutKind();
        if (withoutKind.length > 0) {
            apply(event, {deny: true, reason: INCOMPLETE_ENV_REASON + " " + KIND_ENV + " is empty while " + withoutKind.join(", ") + " is set."});
            return undefined;
        }
        try {
            apply(event, guard(event));
        } catch (error) {
            apply(event, {deny: true, reason: GUARD_FAILURE_REASON + " " + messageOf(error)});
        }
        return undefined;
    }

    try {
        const history = ctx.session.context({sessionID: event.sessionID});
        if (history !== null && typeof history === "object" && typeof history.then === "function") {
            return history.then(
                (value) => {
                    try {
                        apply(event, decide(event, value));
                    } catch (error) {
                        apply(event, {deny: true, reason: FAILURE_REASON + " " + messageOf(error)});
                    }
                },
                (error) => apply(event, {deny: true, reason: FAILURE_REASON + " " + messageOf(error)}),
            );
        }
        apply(event, decide(event, history));
        return undefined;
    } catch (error) {
        apply(event, {deny: true, reason: FAILURE_REASON + " " + messageOf(error)});
        return undefined;
    }
}

export default {
    id: "workspace-assistant-gate",
    /**
     * @param {any} ctx
     */
    setup(ctx) {
        ctx.permission.hook("evaluate", (event) => {
            if (event === null || typeof event !== "object" || event.action !== "shell") {
                return undefined;
            }
            return evaluate(ctx, event);
        });
    },
};