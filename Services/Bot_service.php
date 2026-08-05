<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_bot_events_model;
use Chatwoot_plugin\Models\Chat_bot_flows_model;
use Chatwoot_plugin\Models\Chat_bot_flow_versions_model;
use Chatwoot_plugin\Models\Chat_bot_sessions_model;
use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_messages_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Deterministic, guarded message bot. It never calls AI or executes user code. */
class Bot_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_bot_flows_model $flows = null,
        private ?Chat_bot_flow_versions_model $versions = null,
        private ?Chat_bot_sessions_model $sessions = null,
        private ?Chat_bot_events_model $events = null,
        private ?Chat_conversations_model $conversations = null,
        private ?Chat_messages_model $messages = null,
        private ?Chat_instances_model $instances = null,
        private ?Chat_settings_model $settings = null,
        private ?Bot_flow_validator $validator = null,
        ?BaseConnection $db = null
    ) {
        $this->flows ??= new Chat_bot_flows_model();
        $this->versions ??= new Chat_bot_flow_versions_model();
        $this->sessions ??= new Chat_bot_sessions_model();
        $this->events ??= new Chat_bot_events_model();
        $this->conversations ??= new Chat_conversations_model();
        $this->messages ??= new Chat_messages_model();
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->validator ??= new Bot_flow_validator();
        $this->db = $db ?? db_connect('default');
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function list(array $filters = [], int $page = 1, int $limit = 30): array
    {
        $result = $this->flows->paginate_records($filters, $page, $limit);
        $result['data'] = array_map(fn (array $row): array => $this->mapFlow($row), $result['data']);
        return $result;
    }

    public function get(int $id): ?array
    {
        $row = $this->flows->get_by_id($id);
        return $row ? $this->mapFlow($row) : null;
    }

    public function save(array $input, int $actorId, ?int $id = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) throw new InvalidArgumentException('Nome do bot obrigatorio.');
        $instanceId = !empty($input['instance_id']) ? (int) $input['instance_id'] : null;
        if ($instanceId && !$this->instances->get_by_id($instanceId)) throw new InvalidArgumentException('Instancia do bot invalida.');
        $definition = $this->validator->validate($input['definition'] ?? $input['definition_json'] ?? []);
        $triggerType = strtolower(trim((string) ($input['trigger_type'] ?? 'first_message')));
        $trigger = $this->validator->validateTrigger($triggerType, $input['trigger_config'] ?? $input['trigger_config_json'] ?? []);
        $businessHours = $this->validator->validateBusinessHours($input['business_hours'] ?? $input['business_hours_json'] ?? []);
        $fallback = trim((string) ($input['fallback_message'] ?? $this->settings->get_value('bot_default_fallback', 'Não consegui identificar sua dúvida com segurança.')));
        $handoff = trim((string) ($input['handoff_message'] ?? $this->settings->get_value('bot_default_handoff', 'Vou encaminhar sua mensagem para um responsável.')));
        if ($fallback === '' || mb_strlen($fallback) > 4096 || $handoff === '' || mb_strlen($handoff) > 4096) {
            throw new InvalidArgumentException('Mensagens de fallback e encaminhamento sao obrigatorias e devem ter ate 4096 caracteres.');
        }
        $existing = $id ? $this->flows->get_by_id($id) : null;
        if ($id && !$existing) throw new RuntimeException('Bot nao encontrado.', 404);
        $version = $existing ? (int) $existing['version'] : 1;
        $status = (string) ($existing['status'] ?? 'draft');
        $active = (int) ($existing['active'] ?? 0);
        if ($existing && $status === 'published') {
            $version++;
            $status = 'draft';
            // Keep the last published snapshot active while this new version is edited.
            $active = (int) ($existing['active'] ?? 1);
        }
        $payload = [
            'instance_id' => $instanceId,
            'name' => $name,
            'description' => $this->nullable((string) ($input['description'] ?? ''), 5000),
            'version' => $version,
            'status' => $status,
            'priority' => min(10000, max(-10000, (int) ($input['priority'] ?? 0))),
            'trigger_type' => $triggerType,
            'trigger_config_json' => $this->json($trigger),
            'definition_json' => $this->json($definition),
            'business_hours_json' => $businessHours ? $this->json($businessHours) : null,
            'fallback_message' => $fallback,
            'handoff_message' => $handoff,
            'max_fallbacks' => min(10, max(1, (int) ($input['max_fallbacks'] ?? 2))),
            'ignore_groups' => !array_key_exists('ignore_groups', $input) || filter_var($input['ignore_groups'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'active' => $active,
            'created_by' => $existing['created_by'] ?? ($actorId ?: null),
        ];
        if ($id) $this->flows->update_record($id, $payload);
        else $id = $this->flows->create_record($payload);
        (new Audit_service())->record($actorId ?: null, $existing ? 'bot.updated' : 'bot.created', 'bot_flow', $id, $instanceId, $existing ?: [], $payload);
        return $this->get($id) ?: [];
    }

    public function publish(int $id, int $actorId): array
    {
        $row = $this->flows->get_by_id($id);
        if (!$row) throw new RuntimeException('Bot nao encontrado.', 404);
        // Re-validate persisted JSON before activation.
        $this->validator->validate((string) ($row['definition_json'] ?? ''));
        $this->validator->validateTrigger((string) ($row['trigger_type'] ?? ''), (string) ($row['trigger_config_json'] ?? ''));
        $this->validator->validateBusinessHours((string) ($row['business_hours_json'] ?? ''));
        $snapshot = $this->versions->publish_snapshot($row, $actorId);
        if (!$snapshot) throw new RuntimeException('Nao foi possivel publicar a versao imutavel do bot.');
        $this->flows->update_record($id, [
            'status' => 'published',
            'active' => 1,
            'published_by' => $actorId ?: null,
            'published_at' => (string) ($snapshot['published_at'] ?? gmdate('Y-m-d H:i:s')),
        ]);
        (new Audit_service())->record($actorId ?: null, 'bot.published', 'bot_flow', $id, isset($row['instance_id']) ? (int) $row['instance_id'] : null, ['status' => $row['status']], ['status' => 'published']);
        return $this->get($id) ?: [];
    }

    public function toggle(int $id, int $actorId): array
    {
        $row = $this->flows->get_by_id($id);
        if (!$row) throw new RuntimeException('Bot nao encontrado.', 404);
        if (!$this->versions->latest_published($id)) throw new RuntimeException('Publique o fluxo antes de ativa-lo.', 409);
        $active = empty($row['active']) ? 1 : 0;
        $this->flows->update_record($id, ['active' => $active]);
        (new Audit_service())->record($actorId ?: null, 'bot.toggled', 'bot_flow', $id, isset($row['instance_id']) ? (int) $row['instance_id'] : null, ['active' => !empty($row['active'])], ['active' => (bool) $active]);
        return $this->get($id) ?: [];
    }

    public function delete(int $id, int $actorId): void
    {
        $row = $this->flows->get_by_id($id);
        if (!$row) throw new RuntimeException('Bot nao encontrado.', 404);
        $activeSessions = $this->db->table('chat_bot_sessions')->where('flow_id', $id)->where('status', 'active')->where('deleted', 0)->countAllResults();
        if ($activeSessions > 0) throw new RuntimeException('O bot possui atendimentos ativos. Desative-o e encerre as sessoes antes de excluir.', 409);
        $this->flows->soft_delete($id);
        (new Audit_service())->record($actorId ?: null, 'bot.deleted', 'bot_flow', $id, isset($row['instance_id']) ? (int) $row['instance_id'] : null, $row, []);
    }

    /** @return array<string,mixed> */
    public function process_message(int $messageId): array
    {
        if ((int) $this->settings->get_value('bot_enabled', 1) !== 1) return ['processed' => false, 'reason' => 'bot_disabled'];
        $message = $this->messages->get_by_id($messageId);
        if (!$message || ($message['direction'] ?? '') !== 'incoming' || !empty($message['is_internal_note'])) return ['processed' => false, 'reason' => 'not_incoming'];
        $conversationId = (int) $message['conversation_id'];
        $lock = 'chat_bot_' . substr(hash('sha256', (string) $conversationId), 0, 40);
        $row = $this->db->query('SELECT GET_LOCK(?, 2) acquired', [$lock])->getRowArray();
        if ((int) ($row['acquired'] ?? 0) !== 1) throw new RuntimeException('Bot ocupado; tente novamente.');
        try {
            $conversation = $this->conversations->get_by_id($conversationId);
            if (!$conversation) return ['processed' => false, 'reason' => 'conversation_missing'];
            if (in_array((string) ($conversation['bot_status'] ?? 'active'), ['paused','handoff','disabled'], true)) return ['processed' => false, 'reason' => 'conversation_bot_paused'];
            $isGroup = !empty($message['is_group_message']) || (string) ($conversation['conversation_type'] ?? '') === 'group';
            $session = $this->sessions->get_by_conversation($conversationId);
            if ($session && (int) ($session['last_incoming_message_id'] ?? 0) === $messageId) return ['processed' => false, 'duplicate' => true];
            if ($session && $this->sessionExpired($session)) {
                $this->sessions->update_record((int) $session['id'], ['status' => 'expired', 'ended_at' => gmdate('Y-m-d H:i:s')]);
                $session['status'] = 'expired';
            }

            if (!$session || ($session['status'] ?? '') !== 'active') {
                $flow = $this->selectFlow((int) $conversation['instance_id'], (string) ($message['text_content'] ?? ''));
                if (!$flow) return ['processed' => false, 'reason' => 'no_matching_flow'];
                if ($isGroup && !empty($flow['ignore_groups'])) return ['processed' => false, 'reason' => 'groups_ignored'];
                $hours = $this->decode((string) ($flow['business_hours_json'] ?? ''));
                if ($hours && !$this->insideBusinessHours($hours)) {
                    $outside = trim((string) ($hours['outside_message'] ?? ''));
                    if ($outside !== '') $this->sendBotMessage($conversationId, $outside, 'outside-' . $messageId);
                    if (!empty($hours['handoff_outside'])) $this->markHandoff($conversation, null, 'outside_business_hours');
                    return ['processed' => true, 'reason' => 'outside_business_hours'];
                }
                $definition = $this->validator->validate((string) $flow['definition_json']);
                $start = (string) $definition['start'];
                $session = $this->resetOrCreateSession($session, $flow, $conversation, $start, $messageId);
                $node = $definition['nodes'][$start];
                $outgoing = $this->sendBotMessage($conversationId, (string) $node['message'], 'start-' . $session['id'] . '-' . $messageId);
                $this->sessions->update_record((int) $session['id'], [
                    'last_incoming_message_id' => $messageId,
                    'last_outgoing_message_id' => (int) ($outgoing['id'] ?? 0) ?: null,
                    'last_activity_at' => gmdate('Y-m-d H:i:s'),
                    'status' => !empty($node['terminal']) ? 'completed' : 'active',
                    'ended_at' => !empty($node['terminal']) ? gmdate('Y-m-d H:i:s') : null,
                ]);
                $this->event($session, $messageId, 'session_started', $start, null, (string) ($message['text_content'] ?? ''), (string) $node['message']);
                if (!empty($node['handoff'])) $this->markHandoff($conversation, $session, 'node_handoff');
                return ['processed' => true, 'session_id' => (int) $session['id'], 'node' => $start];
            }

            $flowRecord = $this->flows->get_by_id((int) $session['flow_id']);
            $flow = $this->versions->get_published_version((int) $session['flow_id'], (int) $session['flow_version']);
            if (!$flowRecord || empty($flowRecord['active']) || !$flow) {
                $this->markHandoff($conversation, $session, 'flow_unavailable');
                return ['processed' => true, 'reason' => 'flow_unavailable'];
            }
            if ($isGroup && !empty($flow['ignore_groups'])) return ['processed' => false, 'reason' => 'groups_ignored'];
            $definition = $this->validator->validate((string) $flow['definition_json']);
            $currentKey = (string) $session['current_node_key'];
            $current = $definition['nodes'][$currentKey] ?? null;
            if (!$current) {
                $this->markHandoff($conversation, $session, 'invalid_session_node');
                return ['processed' => true, 'reason' => 'invalid_session_node'];
            }
            $input = (string) ($message['text_content'] ?? '');
            $transition = $this->validator->matchTransition($current['transitions'], $input);
            if ($transition) {
                if ($transition['target'] === '__handoff__') {
                    $this->sendBotMessage($conversationId, (string) $flow['handoff_message'], 'handoff-' . $session['id'] . '-' . $messageId);
                    $this->markHandoff($conversation, $session, 'transition:' . $transition['id']);
                    $this->event($session, $messageId, 'handoff', $currentKey, (string) $transition['id'], $input, (string) $flow['handoff_message']);
                    return ['processed' => true, 'handoff' => true];
                }
                $targetKey = (string) $transition['target'];
                $target = $definition['nodes'][$targetKey];
                $outgoing = $this->sendBotMessage($conversationId, (string) $target['message'], 'node-' . $session['id'] . '-' . $messageId . '-' . $targetKey);
                $completed = !empty($target['terminal']);
                $this->sessions->update_record((int) $session['id'], [
                    'current_node_key' => $targetKey,
                    'fallback_count' => 0,
                    'last_incoming_message_id' => $messageId,
                    'last_outgoing_message_id' => (int) ($outgoing['id'] ?? 0) ?: null,
                    'last_activity_at' => gmdate('Y-m-d H:i:s'),
                    'status' => $completed ? 'completed' : 'active',
                    'ended_at' => $completed ? gmdate('Y-m-d H:i:s') : null,
                ]);
                $this->event($session, $messageId, 'transition', $targetKey, (string) $transition['id'], $input, (string) $target['message']);
                if (!empty($target['handoff'])) $this->markHandoff($conversation, $session, 'node_handoff');
                return ['processed' => true, 'session_id' => (int) $session['id'], 'node' => $targetKey, 'completed' => $completed];
            }

            $fallbackTarget = (string) ($current['fallback_target'] ?? '');
            if ($fallbackTarget === '__handoff__') {
                $this->sendBotMessage($conversationId, (string) $flow['handoff_message'], 'fallback-handoff-' . $session['id'] . '-' . $messageId);
                $this->markHandoff($conversation, $session, 'node_fallback_handoff');
                return ['processed' => true, 'handoff' => true];
            }
            if ($fallbackTarget !== '' && isset($definition['nodes'][$fallbackTarget])) {
                $target = $definition['nodes'][$fallbackTarget];
                $outgoing = $this->sendBotMessage($conversationId, (string) $target['message'], 'fallback-node-' . $session['id'] . '-' . $messageId);
                $this->sessions->update_record((int) $session['id'], [
                    'current_node_key' => $fallbackTarget,
                    'fallback_count' => 0,
                    'last_incoming_message_id' => $messageId,
                    'last_outgoing_message_id' => (int) ($outgoing['id'] ?? 0) ?: null,
                    'last_activity_at' => gmdate('Y-m-d H:i:s'),
                ]);
                return ['processed' => true, 'node' => $fallbackTarget];
            }

            $fallbackCount = (int) $session['fallback_count'] + 1;
            if ($fallbackCount >= (int) $flow['max_fallbacks']) {
                $this->sendBotMessage($conversationId, (string) $flow['handoff_message'], 'max-fallback-' . $session['id'] . '-' . $messageId);
                $this->sessions->update_record((int) $session['id'], ['fallback_count' => $fallbackCount, 'last_incoming_message_id' => $messageId]);
                $this->markHandoff($conversation, $session, 'max_fallbacks');
                $this->event($session, $messageId, 'handoff', $currentKey, null, $input, (string) $flow['handoff_message']);
                return ['processed' => true, 'handoff' => true];
            }
            $outgoing = $this->sendBotMessage($conversationId, (string) $flow['fallback_message'], 'fallback-' . $session['id'] . '-' . $messageId);
            $this->sessions->update_record((int) $session['id'], [
                'fallback_count' => $fallbackCount,
                'last_incoming_message_id' => $messageId,
                'last_outgoing_message_id' => (int) ($outgoing['id'] ?? 0) ?: null,
                'last_activity_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->event($session, $messageId, 'fallback', $currentKey, null, $input, (string) $flow['fallback_message']);
            return ['processed' => true, 'fallback_count' => $fallbackCount];
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function pauseConversation(int $conversationId, int $actorId, string $reason = 'manual_pause'): array
    {
        return $this->setConversationBotState($conversationId, 'paused', $actorId, $reason);
    }

    public function resumeConversation(int $conversationId, int $actorId): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) throw new RuntimeException('Conversa nao encontrada.', 404);
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], [
            'bot_status' => 'active', 'bot_paused_at' => null, 'bot_paused_by' => null, 'bot_handoff_reason' => null,
        ]);
        $session = $this->sessions->get_by_conversation($conversationId);
        if ($session && in_array((string) $session['status'], ['handoff','paused'], true)) {
            $this->sessions->update_record((int) $session['id'], ['status' => 'active', 'handoff_reason' => null, 'ended_at' => null, 'last_activity_at' => gmdate('Y-m-d H:i:s')]);
        }
        (new Audit_service())->record($actorId ?: null, 'bot.conversation_resumed', 'conversation', $conversationId, (int) $conversation['instance_id']);
        return $this->conversations->get_by_id($conversationId) ?: [];
    }

    /** @return array<string,mixed> */
    public function simulate($definition, array $inputs): array
    {
        return $this->validator->simulate($definition, $inputs);
    }

    /** @return array<string,mixed>|null */
    private function selectFlow(int $instanceId, string $text): ?array
    {
        foreach ([$instanceId, null] as $scope) {
            $builder = $this->db->table('chat_bot_flows')->where('deleted', 0)->where('active', 1);
            $scope === null ? $builder->where('instance_id IS NULL', null, false) : $builder->where('instance_id', $scope);
            $rows = $builder->orderBy('priority', 'DESC')->orderBy('id', 'ASC')->get()->getResultArray();
            foreach ($rows as $row) {
                $snapshot = $this->versions->latest_published((int) $row['id']);
                if (!$snapshot) continue;
                $flow = array_merge($snapshot, [
                    'id' => (int) $row['id'],
                    'name' => (string) ($row['name'] ?? ''),
                    'active' => true,
                ]);
                if ($this->triggerMatches($flow, $text)) return $flow;
            }
        }
        return null;
    }

    private function triggerMatches(array $flow, string $text): bool
    {
        $type = (string) ($flow['trigger_type'] ?? 'first_message');
        if (in_array($type, ['first_message','always'], true)) return true;
        $config = $this->decode((string) ($flow['trigger_config_json'] ?? ''));
        $normalized = $this->validator->normalize($text);
        foreach ((array) ($config['values'] ?? []) as $value) {
            $term = $this->validator->normalize((string) $value);
            if ($term !== '' && str_contains($normalized, $term)) return true;
        }
        return false;
    }

    private function resetOrCreateSession(?array $session, array $flow, array $conversation, string $start, int $messageId): array
    {
        $payload = [
            'flow_id' => (int) $flow['id'], 'flow_version' => (int) $flow['version'],
            'conversation_id' => (int) $conversation['id'], 'instance_id' => (int) $conversation['instance_id'],
            'contact_id' => !empty($conversation['contact_id']) ? (int) $conversation['contact_id'] : null,
            'current_node_key' => $start, 'status' => 'active', 'fallback_count' => 0,
            'context_json' => '{}', 'last_incoming_message_id' => null, 'last_outgoing_message_id' => null,
            'handoff_reason' => null, 'started_at' => gmdate('Y-m-d H:i:s'),
            'last_activity_at' => gmdate('Y-m-d H:i:s'), 'ended_at' => null,
        ];
        if ($session) {
            $this->sessions->update_record((int) $session['id'], $payload);
            return $this->sessions->get_by_id((int) $session['id']) ?: array_merge($payload, ['id' => (int) $session['id']]);
        }
        $id = $this->sessions->create_record($payload);
        return $this->sessions->get_by_id($id) ?: array_merge($payload, ['id' => $id]);
    }

    private function sendBotMessage(int $conversationId, string $message, string $key): array
    {
        return (new Chat_service())->send_text($conversationId, $message, 'bot-' . preg_replace('/[^A-Za-z0-9._:-]+/', '-', $key), 0);
    }

    private function markHandoff(array $conversation, ?array $session, string $reason): void
    {
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], [
            'bot_status' => 'handoff', 'bot_paused_at' => gmdate('Y-m-d H:i:s'),
            'bot_paused_by' => null, 'bot_handoff_reason' => mb_substr($reason, 0, 500),
        ]);
        if ($session) $this->sessions->update_record((int) $session['id'], ['status' => 'handoff', 'handoff_reason' => mb_substr($reason, 0, 500), 'ended_at' => gmdate('Y-m-d H:i:s'), 'last_activity_at' => gmdate('Y-m-d H:i:s')]);
        try { (new Notification_service())->create('bot_handoff', 'Atendimento encaminhado', 'O bot encaminhou a conversa para um responsável.', 'conversation', (int) $conversation['id'], null, 'warning', 'bot-handoff|' . $conversation['id'] . '|' . $reason); } catch (Throwable $e) {}
    }

    private function setConversationBotState(int $conversationId, string $status, int $actorId, string $reason): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) throw new RuntimeException('Conversa nao encontrada.', 404);
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], [
            'bot_status' => $status, 'bot_paused_at' => gmdate('Y-m-d H:i:s'), 'bot_paused_by' => $actorId ?: null, 'bot_handoff_reason' => mb_substr($reason, 0, 500),
        ]);
        $session = $this->sessions->get_by_conversation($conversationId);
        if ($session && ($session['status'] ?? '') === 'active') $this->sessions->update_record((int) $session['id'], ['status' => 'paused', 'handoff_reason' => mb_substr($reason, 0, 500)]);
        (new Audit_service())->record($actorId ?: null, 'bot.conversation_paused', 'conversation', $conversationId, (int) $conversation['instance_id'], [], ['reason' => $reason]);
        return $this->conversations->get_by_id($conversationId) ?: [];
    }

    private function sessionExpired(array $session): bool
    {
        $minutes = min(10080, max(1, (int) $this->settings->get_value('bot_session_timeout_minutes', 1440)));
        $last = strtotime((string) ($session['last_activity_at'] ?? $session['started_at'] ?? ''));
        return $last !== false && $last < time() - ($minutes * 60);
    }

    private function insideBusinessHours(array $hours): bool
    {
        $timezone = new DateTimeZone((string) ($hours['timezone'] ?? 'America/Sao_Paulo'));
        $now = new DateTimeImmutable('now', $timezone);
        $day = strtolower($now->format('D'));
        $time = $now->format('H:i');
        foreach ((array) ($hours['weekdays'][$day] ?? []) as $range) {
            if (is_array($range) && count($range) === 2 && $time >= $range[0] && $time < $range[1]) return true;
        }
        return false;
    }

    private function event(array $session, int $messageId, string $type, ?string $node, ?string $transition, string $input, string $output): void
    {
        try {
            $this->events->create_record([
                'session_id' => (int) $session['id'], 'flow_id' => (int) $session['flow_id'],
                'conversation_id' => (int) $session['conversation_id'], 'message_id' => $messageId,
                'event_type' => $type, 'node_key' => $node, 'matched_transition' => $transition,
                'input_preview' => mb_substr($input, 0, 500), 'output_preview' => mb_substr($output, 0, 500),
                'metadata_json' => '{}',
            ]);
        } catch (Throwable $e) { /* Bot delivery is primary; event telemetry is secondary. */ }
    }

    private function mapFlow(array $row): array
    {
        $published = $this->versions->latest_published((int) $row['id']);
        return [
            'id' => (int) $row['id'], 'instance_id' => isset($row['instance_id']) ? (int) $row['instance_id'] : null,
            'name' => (string) $row['name'], 'description' => (string) ($row['description'] ?? ''),
            'version' => (int) $row['version'], 'published_version' => $published ? (int) $published['version'] : null,
            'status' => (string) $row['status'], 'priority' => (int) $row['priority'],
            'trigger_type' => (string) $row['trigger_type'], 'trigger_config' => $this->decode((string) ($row['trigger_config_json'] ?? '')),
            'definition' => $this->decode((string) $row['definition_json']),
            'business_hours' => $this->decode((string) ($row['business_hours_json'] ?? '')),
            'fallback_message' => (string) $row['fallback_message'], 'handoff_message' => (string) $row['handoff_message'],
            'max_fallbacks' => (int) $row['max_fallbacks'], 'ignore_groups' => !empty($row['ignore_groups']),
            'active' => !empty($row['active']), 'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function decode(string $json): array { $data = json_decode($json, true); return is_array($data) ? $data : []; }
    private function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function nullable(string $value, int $limit): ?string { $value = trim($value); return $value === '' ? null : mb_substr($value, 0, $limit); }
}
