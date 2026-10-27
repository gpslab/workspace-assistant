<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\TelegramClient;

/**
 * Pulls fresh bot code (git pull in its own repository) and ends the polling loop. The systemd unit
 * (Restart=always) brings the process back up - the same stop path as on SIGTERM, including the final
 * getUpdates flush in `Bot::run()` (otherwise Telegram would redeliver this same /restart
 * after the restart - see gotchas.md).
 */
final readonly class RestartCommand implements Command
{
    public function __construct(
        private Config $config,
        private TelegramClient $tg,
        private Locale $locale,
        private RunControl $run,
        private string $selfRepoPath,
    ) {
    }

    public function name(): string
    {
        return 'restart';
    }

    public function emoji(): string
    {
        return '🔄';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $selfGit = GitManager::forPath($this->selfRepoPath, $this->config->gitTimeout);
        $pullNote = '';

        if ($selfGit === null) {
            $pullNote = "\n".$this->locale->t('restart.no_repo');
        } else {
            try {
                $dirty = $selfGit->hasChanges();
            } catch (GitStatusException $e) {
                $dirty = null;
                $pullNote = "\n".$this->locale->t('restart.status_failed', ['error' => $e->getMessage()]);
            }
            if ($dirty === true) {
                $pullNote = "\n".$this->locale->t('restart.dirty');
            } elseif ($dirty === false) {
                $error = '';
                $pullNote = $selfGit->pullRebase($error)
                    ? "\n".$this->locale->t('restart.updated', ['hash' => $selfGit->headHash()])
                    : "\n".$this->locale->t('restart.pull_failed', ['error' => $error]);
            }
        }

        $this->tg->sendText($request->chatId, $this->locale->t('restart.restarting').$pullNote, null, $request->threadId);
        Log::line('restart requested via /restart (chat '.$request->chatId.', thread '.$request->threadId.')');
        $this->run->requestStop();
    }
}
