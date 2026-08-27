<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_message_reactions_model;
use Chatwoot_plugin\Models\Chat_message_reaction_attempts_model;
use Chatwoot_plugin\Models\Chat_messages_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

class Message_reaction_service
{
    private Chat_message_reactions_model $reactions;
    private Chat_message_reaction_attempts_model $attempts;
    private Chat_messages_model $messages;
    private Send_lock_service $locks;

    public function __construct(?BaseConnection $db = null, ?Chat_message_reactions_model $reactions = null, ?Chat_messages_model $messages = null, ?Chat_message_reaction_attempts_model $attempts = null)
    {
        $this->reactions = $reactions ?? new Chat_message_reactions_model($db);
        $this->messages = $messages ?? new Chat_messages_model();
        $this->attempts = $attempts ?? new Chat_message_reaction_attempts_model($db);
        $this->locks = new Send_lock_service($db);
    }

    public function validateEmoji(?string $emoji): string
    {
        $emoji = trim((string) $emoji);
        if ($emoji === '') return '';
        preg_match_all('/\X/u', $emoji, $graphemes);
        $graphemeCount = count($graphemes[0] ?? []);
        $pictographicCount = preg_match_all('/\p{Extended_Pictographic}/u', $emoji, $pictographicMatches);
        $regionalCount = preg_match_all('/\p{Regional_Indicator}/u', $emoji, $regionalMatches);
        $singleEmojiSequence = ($pictographicCount === 1 && $regionalCount === 0)
            || ($pictographicCount === 0 && $regionalCount === 2);
        if ($graphemeCount !== 1 || !$singleEmojiSequence || mb_strlen($emoji) > 16 || preg_match('/[\x00-\x1F\x7F]/u', $emoji)) {
            throw new InvalidArgumentException('Emoji de reacao invalido.');
        }
        // Reaction input must contain an emoji/symbol grapheme, not arbitrary
        // text such as "hello". This accepts composed emoji, skin tones and
        // regional-indicator flags without evaluating or normalizing code.
        if (!preg_match('/[\p{Extended_Pictographic}\p{So}\p{Sk}\p{Regional_Indicator}]/u', $emoji)) {
            throw new InvalidArgumentException('Emoji de reacao invalido.');
        }
        return $emoji;
    }

    /** @return array{emoji:string,active:bool} */
    public function normalizeRequest(?string $emoji, bool $remove = false): array
    {
        $raw = trim((string) $emoji);
        if ($remove) return ['emoji' => '', 'active' => false];
        if ($raw === '') throw new InvalidArgumentException('A remocao de reacao precisa usar a semantica explicita de remove.');
        $emoji = $this->validateEmoji($raw);
        return ['emoji' => $emoji, 'active' => true];
    }

    public function applyIncoming(array $instance, array $normalized, int $conversationId): bool
    {
        $content = is_array($normalized['structured_content'] ?? null) ? $normalized['structured_content'] : [];
        $reaction = is_array($content['reaction'] ?? null) ? $content['reaction'] : [];
        $targetExternalId = trim((string) ($reaction['message_id'] ?? ''));
        if ($targetExternalId === '') return false;
        $target = $this->messages->find_by_external_id((int) ($instance['id'] ?? 0), $targetExternalId);
        if (!$target || (int) ($target['conversation_id'] ?? 0) !== $conversationId) return false;
        $emoji = $this->validateEmoji($reaction['emoji'] ?? '');
        $actor = trim((string) ($reaction['reactor_key'] ?? $normalized['sender_jid'] ?? $normalized['sender_phone'] ?? $normalized['remote_jid'] ?? ''));
        if ($actor === '') $actor = 'provider-event:' . trim((string) ($normalized['external_event_id'] ?? $normalized['external_message_id'] ?? 'unknown'));
        $instanceId = (int) ($instance['id'] ?? 0);
        $messageId = (int) $target['id'];
        if (!$this->locks->acquireReaction($instanceId, $messageId, $actor, 2)) {
            throw new RuntimeException('A reacao desta identidade ja esta sendo atualizada.');
        }
        try {
            $this->reactions->upsert_confirmed_state(
                $messageId,
                $instanceId,
                (string) ($instance['provider_type'] ?? $normalized['provider_name'] ?? 'evolution'),
                $actor,
                $emoji,
                !empty($normalized['from_me']),
                $emoji !== '',
                (string) ($reaction['provider_event_id'] ?? $normalized['external_event_id'] ?? $normalized['external_message_id'] ?? ''),
                (string) ($reaction['provider_timestamp'] ?? $normalized['provider_timestamp'] ?? $normalized['timestamp'] ?? ''),
                null,
                (string) ($reaction['provider_timestamp'] ?? $normalized['provider_timestamp'] ?? $normalized['timestamp'] ?? ''),
                'provider',
                (string) ($reaction['provider_event_id'] ?? $normalized['external_event_id'] ?? $normalized['external_message_id'] ?? '')
            );
        } finally {
            $this->locks->releaseReaction($instanceId, $messageId, $actor);
        }
        return true;
    }

    public function applyOutbound(int $messageId, int $instanceId, string $provider, string $reactorKey, string $emoji, bool $fromMe, string $sendState, ?string $clientMessageId, ?string $providerEventId, bool $active = true, ?int $sourceAttemptId = null, ?string $stateOrderAt = null, string $stateOrderKind = 'outbound'): int
    {
        // Compatibility entry point for older callers. It is intentionally
        // confirmation-only; outbound attempts must use V012 methods below.
        $stateOrderAt ??= $this->nowOrderTimestamp();
        return $this->reactions->upsert_confirmed_state($messageId, $instanceId, $provider, $reactorKey, $emoji, $fromMe, $active && $emoji !== '', $providerEventId, null, $sourceAttemptId, $stateOrderAt, $stateOrderKind, (string) ($sourceAttemptId ?? $providerEventId ?? ''));
    }

    public function findByClient(int $instanceId, string $clientMessageId): ?array { return $this->attempts->find_by_client_message_id($instanceId, $clientMessageId); }

    public function findByProviderEventId(int $instanceId, string $providerEventId): ?array { return $this->attempts->find_by_provider_event_id($instanceId, $providerEventId); }

    public function findAttempt(int $attemptId): ?array { return $this->attempts->find_by_id($attemptId); }

    public function createAttempt(int $messageId, int $instanceId, string $provider, string $clientMessageId, ?string $emoji, bool $active, string $state = 'awaiting_provider', ?int $actorUserId = null): int
    {
        return $this->attempts->create($messageId, $instanceId, $provider, $clientMessageId, $emoji, $active, $state, $actorUserId);
    }

    public function updateAttempt(int $attemptId, string $state, ?string $providerEventId = null): bool
    {
        return $this->attempts->update_state($attemptId, $state, $providerEventId);
    }

    public function setAttemptPreviousState(int $attemptId, ?array $previous): bool
    {
        $previous = is_array($previous) ? $previous : [];
        return $this->attempts->set_previous_state(
            $attemptId,
            $previous['emoji'] ?? null,
            !empty($previous['active']),
            !array_key_exists('from_me', $previous) || !empty($previous['from_me']),
            !empty($previous['source_attempt_id']) ? (int) $previous['source_attempt_id'] : null
        );
    }

    public function updateAttemptProviderStatus(int $attemptId, string $status, ?string $errorCode, ?string $errorMessage, ?string $providerTimestamp, string $sendState, ?string $providerEventId = null): bool
    {
        return $this->attempts->update_provider_status($attemptId, $status, $errorCode, $errorMessage, $providerTimestamp, $sendState, $providerEventId);
    }

    public function currentState(int $messageId, string $reactorKey = 'self'): ?array
    {
        return $this->reactions->find_by_target_actor($messageId, $reactorKey);
    }

    public function reconcileFailedAttempt(array $attempt): bool
    {
        $attemptId = (int) ($attempt['id'] ?? 0);
        $messageId = (int) ($attempt['message_id'] ?? 0);
        $instanceId = (int) ($attempt['instance_id'] ?? 0);
        if ($attemptId < 1 || $messageId < 1 || $instanceId < 1) return false;
        if (!$this->locks->acquireReaction($instanceId, $messageId, 'self', 2)) {
            throw new RuntimeException('A reacao desta identidade ja esta sendo atualizada.');
        }
        try {
            $freshAttempt = $this->attempts->find_by_id($attemptId) ?: $attempt;
            $current = $this->reactions->find_by_target_actor($messageId, 'self');
            if (!$current || (int) ($current['source_attempt_id'] ?? 0) !== $attemptId) return false;
            return $this->reactions->upsert_confirmed_state(
                $messageId,
                $instanceId,
                (string) ($freshAttempt['provider_name'] ?? 'evolution'),
                'self',
                $freshAttempt['previous_emoji'] ?? null,
                !empty($freshAttempt['previous_from_me']),
                !empty($freshAttempt['previous_active']),
                null,
                null,
                !empty($freshAttempt['previous_source_attempt_id']) ? (int) $freshAttempt['previous_source_attempt_id'] : null,
                $this->operationOrderAt($freshAttempt),
                'rollback',
                (string) $attemptId
            ) > 0;
        } finally {
            $this->locks->releaseReaction($instanceId, $messageId, 'self');
        }
    }

    /** Confirms V011 only after the provider has accepted the V012 attempt. */
    public function confirmAttemptState(int $attemptId, ?string $providerEventId = null, bool $stateLockAlreadyHeld = false): bool
    {
        $attempt = $this->attempts->find_by_id($attemptId);
        if (!$attempt) return false;
        $instanceId = (int) ($attempt['instance_id'] ?? 0);
        $messageId = (int) ($attempt['message_id'] ?? 0);
        if ($instanceId < 1 || $messageId < 1) return false;
        $lockAcquired = false;
        if (!$stateLockAlreadyHeld) {
            if (!$this->locks->acquireReaction($instanceId, $messageId, 'self', 2)) {
                throw new RuntimeException('A reacao desta identidade ja esta sendo atualizada.');
            }
            $lockAcquired = true;
        }
        try {
            $attempt = $this->attempts->find_by_id($attemptId) ?: $attempt;
            $current = $this->reactions->find_by_target_actor($messageId, 'self');
            if ($current && (int) ($current['source_attempt_id'] ?? 0) === $attemptId) return true;
            if ($current && !$this->attemptCanRecoverState($current, $attempt)) return false;

            $previous = $current ?: [
                'emoji' => null,
                'active' => false,
                'from_me' => true,
                'source_attempt_id' => null,
            ];
            if (!$this->setAttemptPreviousState($attemptId, $previous)) {
                throw new RuntimeException('Nao foi possivel registrar o estado anterior da reacao.');
            }
            return $this->reactions->upsert_confirmed_state(
                $messageId,
                $instanceId,
                (string) ($attempt['provider_name'] ?? 'evolution'),
                'self',
                $attempt['requested_emoji'] ?? null,
                true,
                !empty($attempt['requested_active']),
                $providerEventId !== null && trim($providerEventId) !== '' ? $providerEventId : ($attempt['provider_event_id'] ?? null),
                null,
                $attemptId,
                $this->operationOrderAt($attempt),
                'outbound',
                (string) $attemptId
            ) > 0;
        } finally {
            if ($lockAcquired) {
                $this->locks->releaseReaction($instanceId, $messageId, 'self');
            }
        }
    }

    public function operationOrderAt(array $attempt): string
    {
        $raw = trim((string) ($attempt['created_at'] ?? ''));
        if ($raw !== '') {
            try {
                return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u');
            } catch (\Throwable $exception) {
                // Fall through to a monotonic local value when legacy data is malformed.
            }
        }
        return $this->nowOrderTimestamp();
    }

    private function attemptCanRecoverState(array $current, array $attempt): bool
    {
        $attemptId = (int) ($attempt['id'] ?? 0);
        $currentSource = (int) ($current['source_attempt_id'] ?? 0);
        if ($currentSource > 0 && $currentSource >= $attemptId) return false;
        if ($currentSource > 0 && in_array((string) ($current['state_order_kind'] ?? ''), ['outbound', 'rollback'], true)) {
            // Attempt ids define the order for local operations created in the
            // same database second; a rollback is still the confirmed state
            // immediately preceding this newer attempt.
            return true;
        }
        $currentAt = trim((string) ($current['state_order_at'] ?? ''));
        if ($currentAt === '') return false;
        $operationAt = $this->operationOrderAt($attempt);
        if ($currentAt < $operationAt) return true;
        if ($currentAt > $operationAt) return false;
        return $currentSource > 0 && $currentSource < $attemptId;
    }

    /** @return array{target_ids:array<int>,cursor:int} */
    public function changesAfter(int $conversationId, ?int $cursor): array
    {
        return $this->reactions->changes_after($conversationId, $cursor);
    }

    private function nowOrderTimestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /** @return array<int> */
    public function targetIdsUpdatedAfter(int $conversationId, ?int $cursor): array
    {
        return $this->reactions->target_ids_updated_after($conversationId, $cursor);
    }

    public function latestUpdateCursor(int $conversationId): int
    {
        return $this->reactions->latest_update_cursor($conversationId);
    }

    /** @return array<int,array<string,mixed>> */
    public function aggregates(array $messageIds): array { return $this->reactions->aggregates_for_messages($messageIds); }
}
