<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

/**
 * The `tools` -> OpenCode `permissions` mapping, as an allowlist.
 *
 * An agent's Claude tools are the whole of what it may do: each tool in the list maps to one
 * allowed OpenCode action, and every action with no such tool stays denied. The mapping is
 * fixed and fail-closed — a tool name it does not know grants nothing, and the deny-all base
 * means there is no "everything else allowed" rule to fall through to.
 */
final class ToolPermissions
{
    /**
     * Claude Code tool name -> OpenCode V2 permission action. Read/Grep/Glob are read-only;
     * Bash is the shell; Write and Edit both map to `edit` (OpenCode has no separate write).
     */
    private const array TOOL_ACTION = [
        'Read' => 'read',
        'Grep' => 'grep',
        'Glob' => 'glob',
        'Bash' => 'shell',
        'Write' => 'edit',
        'Edit' => 'edit',
    ];

    /**
     * The permission rules an agent's tools grant: a deny-all base first, then one allow per
     * mapped action (in the order of {@see TOOL_ACTION}, deduplicated, so Write and Edit yield
     * a single `edit` allow). A tool absent from $tools — and a tool the mapping does not know —
     * produces no allow, so it stays denied by the base rule.
     *
     * @param list<string> $tools
     *
     * @return list<array{action: string, resource: string, effect: string}>
     */
    public static function rules(array $tools): array
    {
        $allowed = [];
        foreach (self::TOOL_ACTION as $tool => $action) {
            if (in_array($tool, $tools, true) && !in_array($action, $allowed, true)) {
                $allowed[] = $action;
            }
        }

        $rules = [self::deny('*')];
        foreach ($allowed as $action) {
            $rules[] = self::allow($action);
        }

        return $rules;
    }

    /** @return array{action: string, resource: string, effect: string} */
    private static function deny(string $action): array
    {
        return ['action' => $action, 'resource' => '*', 'effect' => 'deny'];
    }

    /** @return array{action: string, resource: string, effect: string} */
    private static function allow(string $action): array
    {
        return ['action' => $action, 'resource' => '*', 'effect' => 'allow'];
    }
}
