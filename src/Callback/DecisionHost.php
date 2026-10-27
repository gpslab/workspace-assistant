<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

/** What the commit/discard decision needs from the run state owned by `Bot`. */
interface DecisionHost
{
    /** Tells the topic which prompt/locale files the run changed (nothing when none did). */
    public function deliverPromptFilesWarning(int $chatId, int $threadId): void;

    /** Link to a commit of the managed repository, or the bare hash when no GitHub repository is configured. */
    public function commitUrl(string $hash): string;
}
