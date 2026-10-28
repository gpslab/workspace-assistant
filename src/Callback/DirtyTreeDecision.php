<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Keyboards;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\Queue\QueueTexts;
use GpsLab\WorkspaceAssistant\TelegramClient;
use GpsLab\WorkspaceAssistant\TelegramMessage;

/**
 * The commit/discard decision on a dirty tree: offering the buttons when a job ends or is cancelled,
 * and handling the button taps. It is the stateful, last callback handler: it takes whatever the
 * stateless handlers (`mode:`, `lang:`, `epic:`) before it did not (see .claude-docs/gotchas.md).
 */
final readonly class DirtyTreeDecision implements Handler
{
    public function __construct(
        private Config $config,
        private Database $db,
        private TelegramClient $tg,
        private GitManager $git,
        private Delivery $delivery,
        private Locale $locale,
        private QueueTexts $queueTexts,
        private DecisionHost $host,
    ) {
    }

    /** The decision handler is the fall-through of the list: unknown data is answered with a bare toast. */
    public function supports(string $data): bool
    {
        return true;
    }

    public function handle(string $callbackId, int $userId, int $chatId, array $message, string $data): void
    {
        // Under AUTO_COMMIT=0 the bot never commits or resets the tree, so a button issued earlier is refused.
        if (!$this->config->autoCommit && ($data === 'commit' || $data === 'discard')) {
            $this->tg->answerCallback($callbackId, $this->t('decision.auto_commit_off'));

            return;
        }

        $threadId = TelegramMessage::threadIdOf($message);

        // A decision belongs to a specific topic (chat_id, thread_id): in a forum
        // all topics share chat_id, so the topic is checked too, otherwise a button
        // from another topic would commit/discard someone else's tree. The check is
        // against the SQLite record (survives /restart), not an in-memory field.
        $decision = $this->db->getDecision();
        $callbackMessageId = (int) ($message['message_id'] ?? 0);
        if ($decision === null
            || $decision['chat_id'] !== $chatId
            || $decision['thread_id'] !== $threadId
            || ($decision['message_id'] > 0 && $callbackMessageId > 0 && $decision['message_id'] !== $callbackMessageId)) {
            $this->tg->answerCallback($callbackId, $this->t('decision.stale'));

            return;
        }

        // The tree was cleaned manually while the buttons were hanging.
        try {
            $dirty = $this->git->hasChanges();
        } catch (GitStatusException $e) {
            // Unknown state: the decision stays.
            $this->tg->answerCallback($callbackId, $this->t('decision.status_failed_toast', ['error' => $e->getMessage()]));

            return;
        }
        if (!$dirty) {
            $this->db->clearDecision();
            $this->db->clearDirtyOwner();
            $this->tg->answerCallback($callbackId, $this->t('decision.stale'));

            return;
        }

        $external = $this->db->getDirtyOwner() === null;
        $this->db->clearDecision();

        if ($data === 'commit') {
            $this->host->deliverPromptFilesWarning($chatId, $threadId);
            try {
                $hash = $this->git->commitAndPush($external
                    ? $this->t('commit.external')
                    : $this->t('commit.interrupted'));
                $this->db->clearDirtyOwner();
                $this->tg->answerCallback($callbackId, $this->t('decision.committed_toast'));
                $this->delivery->deliver($chatId, $threadId, $this->t('decision.saved', ['url' => $this->host->commitUrl($hash)]));
            } catch (\RuntimeException $e) {
                $this->tg->answerCallback($callbackId, $this->t('decision.git_error_toast'));
                $this->delivery->deliver($chatId, $threadId, $this->t('decision.git_failed', ['error' => $e->getMessage()]));
                // A failed commit is a new entry into the pause: buttons are issued again.
                $this->ensureDecision($chatId, $threadId);
            }

            return;
        }

        if ($data === 'discard') {
            $this->git->discardChanges();
            $this->db->clearDirtyOwner();
            $this->tg->answerCallback($callbackId, $this->t('decision.discarded_toast'));
            $this->delivery->deliver($chatId, $threadId, $this->t('decision.discarded'));

            return;
        }

        $this->tg->answerCallback($callbackId);
    }

    /**
     * Entering the pause when a job ends/is cancelled: if the tree is dirty, the
     * commit/discard buttons go to the owner topic (the topic of this job), only with
     * AUTO_COMMIT=1. The decision record is stored in SQLite; there is no
     * auto-commit or auto-rollback.
     */
    public function offerDecisionIfDirty(int $chatId, int $threadId): void
    {
        if (!$this->config->autoCommit) {
            return;
        }
        try {
            $dirty = $this->git->hasChanges();
        } catch (GitStatusException $e) {
            $this->reportDecisionStatusFailure($chatId, $threadId, $e);

            return;
        }
        if (!$dirty) {
            // The run left a clean tree — there is no owner of the dirt;
            // otherwise later external dirt would be attributed to this topic.
            $this->db->clearDirtyOwner();

            return;
        }

        $this->db->clearDecision();
        $this->ensureDecision($chatId, $threadId);
    }

    /**
     * Issues the commit/discard buttons if no decision is pending yet (only with
     * AUTO_COMMIT=1). The owner
     * is the topic of the last job that touched the tree (stored in the DB). If
     * the owner is unknown ("external" dirt), the buttons go to the head topic
     * ($fallback*) with a note "changes not from this request". Topics with
     * pending items get a "waiting for a decision in topic X" notification.
     */
    public function ensureDecision(int $fallbackChatId, int $fallbackThreadId): void
    {
        if (!$this->config->autoCommit) {
            return;
        }
        if ($this->db->getDecision() !== null) {
            return;
        }

        $owner = $this->db->getDirtyOwner();
        $chatId = $owner['chat_id'] ?? $fallbackChatId;
        $threadId = $owner['thread_id'] ?? $fallbackThreadId;

        $markup = Keyboards::decision($this->t('decision.button_commit'), $this->t('decision.button_discard'))->markup;

        // The message with buttons must go out as TEXT: a long one goes as a file
        // without reply_markup, the decision would be left without buttons and the
        // queue on an eternal pause. So the summary in it is truncated (with a
        // margin for HTML escaping), and the full list goes as a separate message below.
        try {
            $summary = $this->git->changesSummary();
        } catch (GitStatusException $e) {
            $this->reportDecisionStatusFailure($chatId, $threadId, $e);

            return;
        }
        $short = $this->delivery->truncateSummary($summary);
        $text = ($owner === null ? $this->t('decision.external')."\n\n" : '')
            .$this->t('decision.uncommitted')."\n\n".$short."\n\n".$this->t('decision.question');

        // Key the decision by the topic where the buttons ACTUALLY landed: with a
        // deleted topic deliver() falls back to General (0), and the callback will
        // come with thread_id=0. Keeping the original (deleted) topic would make a
        // click yield "decision is no longer relevant" and the dirty tree could not
        // be resolved interactively.
        $landed = $this->delivery->deliverMessage($chatId, $threadId, $text, $markup);
        $this->db->setDecision($chatId, $landed['thread_id'], $landed['message_id']);
        if ($short !== $summary) {
            $this->delivery->safeDeliver($chatId, $landed['thread_id'], $this->t('decision.full_list')."\n\n".$summary);
        }

        $notified = [];
        foreach ($this->db->queueWaiting() as $item) {
            $key = $item['chat_id'].':'.$item['thread_id'];
            if (isset($notified[$key]) || ($item['chat_id'] === $chatId && $item['thread_id'] === $landed['thread_id'])) {
                continue;
            }
            $notified[$key] = true;
            $this->delivery->safeDeliver($item['chat_id'], $item['thread_id'], $this->t('decision.queue_waits', ['topic' => $this->queueTexts->topicLabel($chatId, $landed['thread_id'])]));
        }
    }

    /** The tree state is unknown, so no buttons: the failure goes to the topic instead. */
    private function reportDecisionStatusFailure(int $chatId, int $threadId, GitStatusException $e): void
    {
        Log::line('git status failed, no decision offered: '.$e->getMessage());
        $this->delivery->safeDeliver($chatId, $threadId, $this->t('decision.status_failed', ['error' => $e->getMessage()]));
    }

    /**
     * @param array<string, scalar> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->locale->t($key, $params);
    }
}
