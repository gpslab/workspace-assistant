// @ts-check

/**
 * OpenCode gate shim of the Workspace Assistant.
 *
 * OpenCode has no command hooks: a PreToolUse `Bash` hook of Claude Code has no counterpart there,
 * only JavaScript plugins. Copying the PM gate and the review commit hook into a plugin would put a
 * second implementation of one security check next to the PHP one, and only the PHP one is covered
 * by phpunit. This shim therefore holds no gate logic: it dumps the session history, starts
 * `bin/pm-gate-hook.php` and `bin/review-commit-hook.php` with the engine-neutral input and turns
 * their deny into `effect: "deny"` with the reason the hooks produced.
 *
 * The shim is a plain ES module: no build step and no import of `@opencode/plugin`. OpenCode 2.3.0
 * loads a default-exported `{id, setup(ctx)}` object. It reads no types at runtime, so phpunit can
 * run it under bare `node` with a stub `ctx`.
 *
 * Fail-closed: any failure of this file or of a PHP hook denies the call, and so does a gated run whose
 * environment is invalid or whose review-commit flag does not match the run. A gate that lets a command
 * through because it crashed is no gate.
 *
 * The environment of a gated run carries the gate variables (state directory, run kind, run id, PHP
 * binary, review-commit flag). The plugin is listed in the configuration of a gated run only, so a run
 * with no gate (`arch`, `/compact`) never reaches this file; a run that does reach it with a variable
 * missing or empty is invalid and denied, and the input validation itself stays in the PHP hook.
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

const STATE_ENV = "WORKSPACE_ASSISTANT_GATE_STATE_DIR";
const KIND_ENV = "WORKSPACE_ASSISTANT_GATE_KIND";
const RUN_ENV = "WORKSPACE_ASSISTANT_GATE_RUN_ID";
const PHP_ENV = "WORKSPACE_ASSISTANT_GATE_PHP";
const REVIEW_ENV = "WORKSPACE_ASSISTANT_REVIEW_COMMIT_GATE";

/** What the model sees when the shim or a hook fails without a reason of its own. */
const FAILURE_REASON = "The command was blocked: the gate failed.";

/** What the model sees when the review-commit flag of the run is not the one this run needs. */
const REVIEW_REASON = "The command was blocked: the review commit gate of this run is not the one the run needs.";

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
 * Runs one PHP gate hook and reads its answer: `exit 0` with no output allows, `exit 0` with the
 * deny JSON of `PmGateHook::denyJson()` denies, anything else (a non-zero exit, a signal, a timeout,
 * unreadable output) denies too.
 *
 * @param {string} script
 * @param {string[]} args
 * @param {{command: string, history: string, engine: string}} input
 * @returns {Decision}
 */
function runHook(script, args, input) {
    const php = env(PHP_ENV) || "php";
    const result = spawnSync(php, [script, ...args], {
        input: JSON.stringify(input),
        encoding: "utf8",
        timeout: 120_000,
        maxBuffer: 16 * 1024 * 1024,
    });
    if (result.error || result.status === null) {
        return {deny: true, reason: FAILURE_REASON + " " + messageOf(result.error ?? "no exit status")};
    }
    if (result.status !== 0) {
        const stderr = typeof result.stderr === "string" ? result.stderr.trim() : "";
        return {deny: true, reason: stderr !== "" ? stderr : FAILURE_REASON};
    }

    const output = typeof result.stdout === "string" ? result.stdout.trim() : "";
    if (output === "") {
        return {deny: false, reason: ""};
    }

    let parsed;
    try {
        parsed = JSON.parse(output);
    } catch {
        return {deny: true, reason: FAILURE_REASON};
    }
    const decision = parsed?.hookSpecificOutput;
    if (decision?.permissionDecision === "deny") {
        const reason = decision.permissionDecisionReason;
        return {deny: true, reason: typeof reason === "string" && reason !== "" ? reason : FAILURE_REASON};
    }

    // Output that is not the deny JSON of the hooks is not an answer that may be trusted.
    return {deny: true, reason: FAILURE_REASON};
}

/**
 * The whole gate: the review-commit flag of the run, then the dumped history, the PM gate and, where
 * the flag is `1`, the commit hook. Every path out of it that is not an explicit allow denies.
 *
 * @param {any} event
 * @param {any} history
 * @returns {Decision}
 */
function decide(event, history) {
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
 * Evaluates one shell call. The session history is read synchronously when the API returns it
 * directly, and awaited when it returns a promise; the PHP hooks always run synchronously.
 *
 * @param {any} ctx
 * @param {any} event
 * @returns {any}
 */
function evaluate(ctx, event) {
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