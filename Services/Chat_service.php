<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_messages_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Models\Chat_webhook_logs_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Application service shared by the Rise UI/API and the public webhook.
 *
 * Views never call Evolution directly. This class is also the single place
 * where Evolution payloads become the plugin's local conversation/message
 * representation.
 */
class Chat_service
{
    public const SETTING_BASE_URL = 'evolution_base_url';
    public const SETTING_GLOBAL_API_KEY = 'evolution_api_key';

    private const MESSAGE_STATUS_RANK = [
        'received' => 0,
        'sending' => 0,
        'sent' => 10,
        'delivered' => 20,
        'read' => 30,
    ];

    private const WEBHOOK_MESSAGE_STATUSES = ['sent', 'delivered', 'read', 'failed'];

    private Chat_instances_model $instances;
    private Chat_conversations_model $conversations;
    private Chat_messages_model $messages;
    private Chat_settings_model $settings;
    private Chat_webhook_logs_model $webhookLogs;
    private Webhook_normalizer $normalizer;
    private Payload_sanitizer $sanitizer;
    private BaseConnection $db;
    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        ?Chat_instances_model $instances = null,
        ?Chat_conversations_model $conversations = null,
        ?Chat_messages_model $messages = null,
        ?Chat_settings_model $settings = null,
        ?Chat_webhook_logs_model $webhookLogs = null,
        ?Webhook_normalizer $normalizer = null,
        ?Payload_sanitizer $sanitizer = null,
        ?BaseConnection $db = null,
        ?callable $clientFactory = null
    ) {
        $this->instances = $instances ?? new Chat_instances_model();
        $this->conversations = $conversations ?? new Chat_conversations_model();
        $this->messages = $messages ?? new Chat_messages_model();
        $this->settings = $settings ?? new Chat_settings_model();
        $this->webhookLogs = $webhookLogs ?? new Chat_webhook_logs_model();
        $this->normalizer = $normalizer ?? new Webhook_normalizer();
        $this->sanitizer = $sanitizer ?? new Payload_sanitizer();
        $this->db = $db ?? db_connect('default');
        $this->clientFactory = $clientFactory;
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function list_instances(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $result = $this->instances->paginate_instances($filters, $page, $perPage);
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $ids = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows
        )));

        $conversationCounters = [];
        foreach ($this->conversations->get_instance_counters($ids) as $counter) {
            $conversationCounters[(int) $counter['instance_id']] = $counter;
        }
        $messageCounters = $this->messagesTodayByInstance($ids);

        $mapped = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $counter = $conversationCounters[$id] ?? [];
            $row['status'] = (string) ($row['connection_status'] ?? 'disconnected');
            $row['phone'] = (string) ($row['phone_number'] ?? '');
            $row['conversation_count'] = (int) ($counter['conversation_count'] ?? 0);
            $row['open_conversations'] = (int) ($counter['open_count'] ?? 0);
            $row['unread_count'] = (int) ($counter['unread_count'] ?? 0);
            $row['messages_today'] = (int) ($messageCounters[$id] ?? 0);
            $row['active'] = (int) ($row['active'] ?? 0) === 1;
            $row['has_api_key'] = !empty($row['has_api_key']);
            $row['last_sync_at'] = $this->toIsoDate($row['last_sync_at'] ?? null);
            $row['created_at'] = $this->toIsoDate($row['created_at'] ?? null);
            $row['updated_at'] = $this->toIsoDate($row['updated_at'] ?? null);
            unset($row['api_key'], $row['api_key_encrypted']);
            $mapped[] = $row;
        }

        return [
            'data' => $mapped,
            'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
        ];
    }

    public function get_instance(int $id): ?array
    {
        $row = $this->instances->get_by_id($id);
        if (!$row) {
            return null;
        }

        $listed = $this->list_instances(['search' => (string) $row['internal_identifier']], 1, 100);
        foreach ($listed['data'] as $candidate) {
            if ((int) $candidate['id'] === $id) {
                return $candidate;
            }
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public function refresh_instance_status(int $id): array
    {
        $instance = $this->instances->get_by_id($id);
        if (!$instance) {
            throw new InvalidArgumentException('Instancia nao encontrada.');
        }

        $response = $this->clientForInstance($instance)->status();
        $status = (string) ($response['connection_status'] ?? 'error');
        if (!in_array($status, ['connected', 'attention', 'disconnected', 'error'], true)) {
            $status = 'error';
        }

        $this->instances->update_connection_status($id, $status, gmdate('Y-m-d H:i:s'));

        return [
            'id' => $id,
            'status' => $status,
            'state' => $response['state'] ?? null,
            'message' => !empty($response['success'])
                ? ($status === 'connected' ? 'Conexao com a Evolution confirmada.' : 'Estado da conexao atualizado.')
                : (string) ($response['error'] ?? 'Nao foi possivel consultar a Evolution API.'),
            'http_status' => (int) ($response['status_code'] ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function refresh_all_instance_statuses(): array
    {
        $page = $this->instances->paginate_instances(['active' => true], 1, 100);
        $results = [];

        foreach (($page['data'] ?? []) as $instance) {
            $id = (int) ($instance['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            try {
                $results[] = $this->refresh_instance_status($id);
            } catch (Throwable $exception) {
                $this->instances->update_connection_status($id, 'error', gmdate('Y-m-d H:i:s'));
                $results[] = [
                    'id' => $id,
                    'status' => 'error',
                    'state' => null,
                    'message' => 'Nao foi possivel consultar esta instancia.',
                    'http_status' => 0,
                ];
            }
        }

        return $results;
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function list_conversations(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        if (($filters['status'] ?? '') === 'all') {
            unset($filters['status']);
        } elseif (($filters['status'] ?? '') === 'unassigned') {
            unset($filters['status']);
            $filters['unassigned'] = true;
        }

        $result = $this->conversations->paginate_conversations($filters, $page, $perPage);
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $instanceMap = $this->instanceMap(array_map(
            static fn (array $row): int => (int) ($row['instance_id'] ?? 0),
            $rows
        ));
        $conversationIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)));
        $tagMap = [];
        if ($conversationIds) {
            $conversationTagsTable = $this->db->prefixTable('chat_conversation_tags');
            $tagsTable = $this->db->prefixTable('chat_tags');
            $tagRows = $this->db->table($conversationTagsTable)
                ->select($conversationTagsTable . '.conversation_id, ' . $tagsTable . '.name')
                ->join($tagsTable, $tagsTable . '.id=' . $conversationTagsTable . '.tag_id AND ' . $tagsTable . '.deleted=0')
                ->whereIn($conversationTagsTable . '.conversation_id', $conversationIds)
                ->where($conversationTagsTable . '.deleted', 0)
                ->orderBy($tagsTable . '.name', 'ASC')->get()->getResultArray();
            foreach ($tagRows as $tagRow) $tagMap[(int) $tagRow['conversation_id']][] = (string) $tagRow['name'];
        }
        $assigneeIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['assignee_id'] ?? 0), $rows))));
        $assigneeMap = [];
        if ($assigneeIds) {
            $userRows = $this->db->table('users')->select('id, first_name, last_name')->whereIn('id', $assigneeIds)->where('deleted', 0)->get()->getResultArray();
            foreach ($userRows as $userRow) $assigneeMap[(int) $userRow['id']] = trim((string) $userRow['first_name'] . ' ' . (string) $userRow['last_name']);
        }

        $mapped = [];
        foreach ($rows as $row) {
            $instance = $instanceMap[(int) ($row['instance_id'] ?? 0)] ?? null;
            $row['_tags'] = $tagMap[(int) ($row['id'] ?? 0)] ?? [];
            $row['_assignee_name'] = $assigneeMap[(int) ($row['assignee_id'] ?? 0)] ?? '';
            $mapped[] = $this->mapConversation($row, $instance);
        }

        return [
            'data' => $mapped,
            'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
        ];
    }

    /**
     * Imports the current Evolution chat list into the local read model. This
     * is intentionally called on demand (first load/channel change), not on
     * every polling cycle.
     *
     * @return array{instances:int,chats:int,errors:int}
     */
    public function sync_chats(?int $instanceId = null): array
    {
        if ($instanceId !== null && $instanceId > 0) {
            $single = $this->instances->get_by_id($instanceId);
            $instanceRows = $single && !empty($single['active']) ? [$single] : [];
        } else {
            $page = $this->instances->paginate_instances(['active' => true], 1, 100);
            $instanceRows = is_array($page['data'] ?? null) ? $page['data'] : [];
        }

        $summary = ['instances' => 0, 'chats' => 0, 'errors' => 0];
        foreach ($instanceRows as $instance) {
            $syncLock = 'chat_sync_instance_' . (int) ($instance['id'] ?? 0);
            if (!$this->acquireNamedLock($syncLock, 0)) {
                $summary['errors']++;
                continue;
            }
            try {
                $client = $this->clientForInstance($instance);
                $response = $client->find_chats([]);
                if (empty($response['success'])) {
                    $summary['errors']++;
                    continue;
                }

                $summary['instances']++;
                foreach ($this->extractEvolutionRecords($response['data'] ?? []) as $chat) {
                    if ($this->persistEvolutionChat($instance, $chat)) {
                        $summary['chats']++;
                    }
                }
                $this->recordSuccessfulSync($instance, $client);
            } catch (Throwable $exception) {
                $summary['errors']++;
                log_message('error', 'Chatwoot_plugin chat synchronization failed ({exception_type}).', [
                    'exception_type' => get_class($exception),
                ]);
            } finally {
                $this->releaseNamedLock($syncLock);
            }
        }

        return $summary;
    }

    /**
     * Returns local history. When sync=true, Evolution history is normalized
     * and persisted first; a remote error does not hide already stored data.
     *
     * @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function get_messages(
        int $conversationId,
        int $limit = 50,
        ?int $beforeId = null,
        ?int $afterId = null,
        bool $sync = false,
        ?int $beforeTimestamp = null
    ): array {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) {
            throw new InvalidArgumentException('Conversa nao encontrada.');
        }

        $syncError = null;
        $synced = 0;
        if ($sync) {
            try {
                $synced = $this->sync_conversation_history($conversation);
            } catch (Throwable $exception) {
                $syncError = 'O historico remoto nao pode ser atualizado agora; exibindo os dados locais.';
                log_message('error', 'Chatwoot_plugin history synchronization failed ({exception_type}).', [
                    'exception_type' => get_class($exception),
                ]);
            }
        }

        $limit = min(100, max(1, $limit));
        if ($afterId !== null && $afterId > 0) {
            $rows = $this->messages->get_after($conversationId, $afterId, $limit);
            $hasMoreBefore = false;
        } else {
            $rows = $this->messages->get_history($conversationId, $limit, $beforeTimestamp, $beforeId);
            $firstId = $rows ? (int) ($rows[0]['id'] ?? 0) : 0;
            $firstTimestamp = $rows ? (int) ($rows[0]['message_timestamp'] ?? 0) : 0;
            $hasMoreBefore = $firstId > 0
                && $this->messages->count_older(
                    $conversationId,
                    $firstTimestamp > 0 ? $firstTimestamp : $beforeTimestamp,
                    $firstId
                ) > 0;
        }

        return [
            'data' => array_map(fn (array $row): array => $this->mapMessage($row), $rows),
            'meta' => [
                'has_more_before' => $hasMoreBefore,
                'synced' => $synced,
                'sync_error' => $syncError,
            ],
        ];
    }

    /** @param array<string,mixed> $conversation */
    public function sync_conversation_history(array $conversation): int
    {
        $conversationId = (int) ($conversation['id'] ?? 0);
        $syncLock = 'chat_sync_history_' . $conversationId;
        if ($conversationId < 1 || !$this->acquireNamedLock($syncLock, 0)) {
            throw new RuntimeException('A sincronizacao desta conversa ja esta em andamento.');
        }

        try {
            return $this->syncConversationHistoryUnlocked($conversation);
        } finally {
            $this->releaseNamedLock($syncLock);
        }
    }

    /** @param array<string,mixed> $conversation */
    private function syncConversationHistoryUnlocked(array $conversation): int
    {
        $instanceId = (int) ($conversation['instance_id'] ?? 0);
        $instance = $this->instances->get_by_id($instanceId);
        if (!$instance || empty($instance['active'])) {
            throw new RuntimeException('A instancia desta conversa esta inativa ou indisponivel.');
        }

        $remoteJid = trim((string) ($conversation['remote_jid'] ?? ''));
        if ($remoteJid === '') {
            throw new RuntimeException('A conversa nao possui remoteJid.');
        }

        $client = $this->clientForInstance($instance);
        $response = $client->find_messages($remoteJid);
        if (empty($response['success'])) {
            throw new RuntimeException((string) ($response['error'] ?? 'Falha ao consultar mensagens na Evolution.'));
        }

        $records = $this->extractEvolutionRecords($response['data'] ?? []);
        $normalizedRecords = [];
        foreach ($records as $position => $record) {
            $envelope = [
                'event' => 'messages.upsert',
                'instance' => (string) $instance['evolution_instance_name'],
                'data' => $record,
            ];
            $normalized = $this->normalizer->normalize($envelope);
            if ((string) ($normalized['remote_jid'] ?? '') !== $remoteJid) {
                continue;
            }
            $normalizedRecords[] = [
                'position' => $position,
                'timestamp' => (int) ($normalized['timestamp'] ?? 0),
                'envelope' => $envelope,
                'normalized' => $normalized,
            ];
        }
        usort($normalizedRecords, static function (array $left, array $right): int {
            $timestampOrder = (int) $left['timestamp'] <=> (int) $right['timestamp'];

            return $timestampOrder !== 0
                ? $timestampOrder
                : (int) $left['position'] <=> (int) $right['position'];
        });

        $persisted = 0;
        foreach ($normalizedRecords as $record) {
            $this->persistNormalizedMessage(
                $instance,
                $record['normalized'],
                $record['envelope'],
                false
            );
            $persisted++;
        }

        $this->recordSuccessfulSync($instance, $client);

        return $persisted;
    }

    /** @return array<string,mixed> */
    public function send_text(int $conversationId, string $text, string $clientMessageId, int $actorId = 0): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) {
            throw new InvalidArgumentException('Conversa nao encontrada.');
        }

        $instance = $this->instances->get_by_id((int) $conversation['instance_id']);
        if (!$instance || empty($instance['active'])) {
            throw new RuntimeException('A instancia desta conversa esta inativa.');
        }
        if ((string) ($instance['connection_status'] ?? '') !== 'connected') {
            throw new RuntimeException('A instancia esta desconectada; o envio foi bloqueado.');
        }

        $text = trim($text);
        $clientMessageId = trim($clientMessageId);
        if ($text === '' || $clientMessageId === '') {
            throw new InvalidArgumentException('Mensagem e identificador idempotente sao obrigatorios.');
        }

        $sendLock = 'chat_send_' . substr(hash('sha256', $conversationId . '|' . $clientMessageId), 0, 40);
        if (!$this->acquireNamedLock($sendLock, 0)) {
            throw new RuntimeException('Um envio com este identificador ja esta em andamento.');
        }

        try {
            $existing = $this->messages->find_by_client_message_id($conversationId, $clientMessageId);
            if ($existing && in_array((string) ($existing['status'] ?? ''), ['sent', 'delivered', 'read'], true)) {
                return $this->mapMessage($existing);
            }

        $ai = new Ai_service();
        $stopCommand = trim((string) $this->settings->get_value('ai_stop_command', '@stop'));
        $startCommand = trim((string) $this->settings->get_value('ai_start_command', '@start'));
        if ($text === $startCommand) {
            try { $ai->set_state($conversationId, ['status' => 'running', 'reason' => 'human_command_start', 'source' => 'rise_plugin'], $actorId, false); } catch (Throwable $exception) { /* Text delivery remains independent. */ }
        } elseif ($text === $stopCommand) {
            try { $ai->set_state($conversationId, ['status' => 'paused', 'reason' => 'human_command_stop', 'source' => 'rise_plugin'], $actorId, false); } catch (Throwable $exception) { /* Text delivery remains independent. */ }
        } else {
            $ai->pause_for_human($conversationId, $actorId);
        }

        $remoteJid = (string) $conversation['remote_jid'];
        $number = (string) ($conversation['phone_number'] ?: $remoteJid);
        if ($this->isLidJid($remoteJid)) {
            $lidDigits = (string) preg_replace('/\D+/', '', explode('@', $remoteJid, 2)[0]);
            $number = (string) preg_replace('/\D+/', '', $number);
            if ($number === '' || $number === $lidDigits) {
                throw new RuntimeException(
                    'O numero real deste contato @lid ainda nao foi resolvido. Sincronize a conversa e tente novamente.'
                );
            }
        }
        $now = time();
        $nowDate = gmdate('Y-m-d H:i:s', $now);
        $dedupeKey = hash('sha256', implode('|', [
            (string) $instance['id'],
            $remoteJid,
            'client',
            $clientMessageId,
        ]));

        if ($existing) {
            $messageId = (int) $existing['id'];
            $this->messages->update_message($messageId, [
                'status' => 'sending',
                'text_content' => $text,
                'sent_at' => $nowDate,
                'message_timestamp' => $now,
            ]);
        } else {
            $messageId = $this->messages->upsert_message($conversationId, (int) $instance['id'], [
                'external_message_id' => null,
                'remote_jid' => $remoteJid,
                'direction' => 'outgoing',
                'message_type' => 'text',
                'text_content' => $text,
                'status' => 'sending',
                'sent_at' => $nowDate,
                'message_timestamp' => $now,
                'dedupe_key' => $dedupeKey,
                'client_message_id' => $clientMessageId,
                'raw_payload' => ['source' => 'rise_ui'],
                'sender_user_id' => $actorId ?: null,
            ]);
        }

        $client = $this->clientForInstance($instance);
        if ($this->isGroupJid($remoteJid)) {
            $endpoint = (string) $this->settings->get_value(
                Chat_settings_model::ENDPOINT_SEND_TEXT,
                '/message/sendText/{instance}'
            );
            $instanceName = trim((string) ($instance['evolution_instance_name'] ?? ''));
            if ($endpoint === '' || $instanceName === '') {
                throw new RuntimeException('Endpoint de envio ou instancia Evolution nao configurado.');
            }
            $path = str_replace('{instance}', rawurlencode($instanceName), $endpoint);
            $response = $client->post($path, ['number' => $remoteJid, 'text' => $text]);
            if (!empty($response['success']) && empty($response['message_id'])) {
                $response['message_id'] = $client->extract_message_id($response['data'] ?? []);
            }
        } else {
            $response = $client->send_text($number, $text);
        }
        if (empty($response['success'])) {
            $error = mb_substr((string) ($response['error'] ?? 'A Evolution API nao confirmou o envio.'), 0, 1000);
            $this->messages->update_message($messageId, ['status' => 'failed', 'delivery_error' => $error, 'failed_at' => gmdate('Y-m-d H:i:s')]);
            try { (new Notification_service())->create('message_failed', 'Falha ao enviar mensagem', $error, 'conversation', $conversationId, $actorId ?: null, 'danger', 'send-failed|' . $conversationId . '|' . $clientMessageId); } catch (Throwable $exception) { /* Do not mask provider error. */ }
            (new Audit_service())->record($actorId ?: null, 'message.send_failed', 'message', $messageId, (int) $instance['id'], [], ['error' => $error]);
            throw new RuntimeException((string) ($response['error'] ?? 'A Evolution API nao confirmou o envio.'));
        }

        $externalId = trim((string) ($response['message_id'] ?? ''));
        $this->finalizeOptimisticMessage(
            $messageId,
            $conversationId,
            (int) $instance['id'],
            $clientMessageId,
            $externalId,
            $remoteJid,
            $response['data'] ?? []
        );

        $this->upsertConversationPreservingActivity((int) $instance['id'], $remoteJid, [
            'last_message_preview' => $text,
            'last_message_at' => $nowDate,
            'last_human_message_at' => $nowDate,
        ], $now);

        if (empty($conversation['first_response_at'])) {
            $firstIncoming = $this->db->table('chat_messages')->select('message_timestamp')->where('conversation_id', $conversationId)->where('direction', 'incoming')->where('deleted', 0)->orderBy('message_timestamp', 'ASC')->get(1)->getRowArray();
            if ($firstIncoming && (int) $firstIncoming['message_timestamp'] > 0) {
                $seconds = max(0, $now - (int) $firstIncoming['message_timestamp']);
                $this->conversations->upsert_conversation((int) $instance['id'], $remoteJid, ['first_response_at' => $nowDate, 'first_response_seconds' => $seconds]);
            }
        }

        $saved = $this->messages->find_by_client_message_id($conversationId, $clientMessageId)
            ?? ($externalId !== '' ? $this->messages->find_by_external_id((int) $instance['id'], $externalId) : null)
            ?? $this->messages->get_by_id($messageId);
        if (!$saved) {
            throw new RuntimeException('A mensagem enviada nao pode ser relida.');
        }

        (new Audit_service())->record($actorId ?: null, 'message.sent', 'message', (int) $saved['id'], (int) $instance['id'], [], ['conversation_id' => $conversationId, 'status' => $saved['status'] ?? 'sent']);

            return $this->mapMessage($saved);
        } finally {
            $this->releaseNamedLock($sendLock);
        }
    }

    /** @return array<string,mixed> */
    public function public_settings(): array
    {
        $globalKey = (string) $this->settings->get_value(self::SETTING_GLOBAL_API_KEY, '');
        $webhookSecret = (string) $this->settings->get_value(Chat_settings_model::WEBHOOK_SECRET, '');
        $n8nToken = (string) $this->settings->get_value('n8n_token', '');

        $result = [
            'evolution_base_url' => (string) $this->settings->get_value(self::SETTING_BASE_URL, ''),
            'global_api_key_masked' => $this->maskSecret($globalKey),
            'has_global_api_key' => $globalKey !== '',
            'request_timeout_seconds' => (int) $this->settings->get_value(
                Chat_settings_model::EVOLUTION_TIMEOUT_SECONDS,
                30
            ),
            'polling_interval_ms' => (int) $this->settings->get_value(
                Chat_settings_model::POLLING_INTERVAL_MS,
                5000
            ),
            'webhook_secret_masked' => $this->maskSecret($webhookSecret),
            'has_webhook_secret' => $webhookSecret !== '',
            'connection_status_path' => (string) $this->settings->get_value(
                Chat_settings_model::ENDPOINT_CONNECTION_STATE,
                '/instance/connectionState/{instance}'
            ),
            'find_chats_path' => (string) $this->settings->get_value(
                Chat_settings_model::ENDPOINT_FIND_CHATS,
                '/chat/findChats/{instance}'
            ),
            'find_messages_path' => (string) $this->settings->get_value(
                Chat_settings_model::ENDPOINT_FIND_MESSAGES,
                '/chat/findMessages/{instance}'
            ),
            'send_text_path' => (string) $this->settings->get_value(
                Chat_settings_model::ENDPOINT_SEND_TEXT,
                '/message/sendText/{instance}'
            ),
            'send_media_path' => (string) $this->settings->get_value(Chat_settings_model::ENDPOINT_SEND_MEDIA, '/message/sendMedia/{instance}'),
            'send_audio_path' => (string) $this->settings->get_value(Chat_settings_model::ENDPOINT_SEND_AUDIO, '/message/sendWhatsAppAudio/{instance}'),
            'get_media_base64_path' => (string) $this->settings->get_value(Chat_settings_model::ENDPOINT_MEDIA_BASE64, '/chat/getBase64FromMediaMessage/{instance}'),
            'n8n_token_masked' => $this->maskSecret($n8nToken),
            'has_n8n_token' => $n8nToken !== '',
        ];

        $defaults = [
            'module_name' => 'Impulso Hub', 'timezone' => 'America/Sao_Paulo', 'conversation_page_size' => 30,
            'sound_enabled' => 1, 'browser_notifications_enabled' => 0, 'auto_mark_read' => 1,
            'default_status' => 'open', 'default_priority' => 'normal', 'sla_minutes' => 30, 'auto_resolve_hours' => 0,
            'evolution_retries' => 2, 'n8n_base_url' => '', 'n8n_auth_mode' => 'bearer', 'n8n_header_name' => 'X-API-Key', 'n8n_allow_private_networks' => 0, 'n8n_timeout_seconds' => 30,
            'n8n_health_path' => '/healthz', 'n8n_campaigns_path' => '/webhook/campanha', 'n8n_ai_path' => '/webhook/iara/control', 'n8n_events_path' => '/webhook/impulso/events',
            'campaign_window_start' => '08:00', 'campaign_window_end' => '20:00', 'campaign_batch_size' => 20,
            'campaign_min_interval_seconds' => 8, 'campaign_pause_after_errors' => 5, 'campaign_optout_text' => '', 'quick_replies_json' => '[]',
            'ai_default_state' => 'running', 'ai_human_priority' => 1, 'ai_show_context' => 1, 'ai_stop_command' => '@stop', 'ai_start_command' => '@start', 'ai_auto_return_minutes' => 0,
            'log_sanitized_webhooks' => 1, 'webhook_retention_days' => 30, 'audit_enabled' => 1, 'audit_retention_days' => 180,
            'conversation_retention_days' => 0, 'media_retention_days' => 30, 'secure_media' => 1,
        ];
        $integerKeys = ['conversation_page_size','sound_enabled','browser_notifications_enabled','auto_mark_read','sla_minutes','auto_resolve_hours','evolution_retries','n8n_allow_private_networks','n8n_timeout_seconds','campaign_batch_size','campaign_min_interval_seconds','campaign_pause_after_errors','ai_human_priority','ai_show_context','ai_auto_return_minutes','log_sanitized_webhooks','webhook_retention_days','audit_enabled','audit_retention_days','conversation_retention_days','media_retention_days','secure_media'];
        foreach ($defaults as $key => $default) {
            $value = $this->settings->get_value($key, $default);
            $result[$key] = in_array($key, $integerKeys, true) ? (int) $value : (string) $value;
        }
        // Backwards-compatible view aliases.
        $result['campaign_start_time'] = $result['campaign_window_start'];
        $result['campaign_end_time'] = $result['campaign_window_end'];
        $result['ai_return_minutes'] = $result['ai_auto_return_minutes'];
        return $result;
    }

    /** @return array<string,int|string> */
    public function dashboard_summary(): array
    {
        $conversationTable = $this->db->prefixTable('chat_conversations');
        $today = gmdate('Y-m-d 00:00:00');
        $base = static function (BaseConnection $db, string $table) {
            return $db->table($table)->where('deleted', 0)->where('archived', 0);
        };

        $connected = (int) ($this->instances->count_by_status(true)['connected'] ?? 0);

        $slaMinutes = max(1, (int) $this->settings->get_value('sla_minutes', 30));
        $slaThreshold = gmdate('Y-m-d H:i:s', time() - $slaMinutes * 60);
        $avgFirst = $this->db->table($conversationTable)->select('COALESCE(AVG(first_response_seconds),0) average', false)->where('deleted', 0)->where('first_response_seconds IS NOT NULL', null, false)->get()->getRowArray();
        $resolved = $this->db->table($conversationTable)->where('deleted', 0)->where('status', 'resolved')->countAllResults();
        $aiResolved = $this->db->table($conversationTable)->where('deleted', 0)->where('status', 'resolved')->where('last_bot_message_at IS NOT NULL', null, false)->groupStart()->where('last_human_message_at IS NULL', null, false)->orWhere('last_bot_message_at > last_human_message_at', null, false)->groupEnd()->countAllResults();
        $avgSeconds = (int) ($avgFirst['average'] ?? 0);

        return [
            'open' => $base($this->db, $conversationTable)->where('status', 'open')->countAllResults(),
            'pending' => $base($this->db, $conversationTable)->where('status', 'pending')->countAllResults(),
            'high_priority' => $base($this->db, $conversationTable)->whereIn('status', ['open', 'pending'])->whereIn('priority', ['high', 'urgent'])->countAllResults(),
            'unassigned' => $base($this->db, $conversationTable)->where('assignee_id IS NULL', null, false)->whereIn('status', ['open', 'pending'])->countAllResults(),
            'sla_risk' => $base($this->db, $conversationTable)->whereIn('status', ['open', 'pending'])->where('first_response_at IS NULL', null, false)->where('created_at <=', $slaThreshold)->countAllResults(),
            'resolved_today' => $this->db->table($conversationTable)
                ->where('deleted', 0)
                ->where('status', 'resolved')
                ->where('updated_at >=', $today)
                ->countAllResults(),
            'avg_first_response' => $avgSeconds > 0 ? ($avgSeconds < 60 ? $avgSeconds . 's' : round($avgSeconds / 60, 1) . ' min') : '—',
            'online_agents' => 0,
            'ai_resolution_rate' => ($resolved > 0 ? round($aiResolved * 100 / $resolved, 1) : 0) . '%',
            'connected_instances' => $connected,
        ];
    }

    /** Validated values only. Blank secret fields preserve existing values. */
    public function update_settings(array $values, int $actorId = 0): array
    {
        $currentBaseUrl = trim((string) $this->settings->get_value(self::SETTING_BASE_URL, ''));
        $nextBaseUrl = trim((string) ($values['evolution_base_url'] ?? ''));
        $nextGlobalKey = trim((string) ($values['global_api_key'] ?? ''));
        $originChanged = $nextBaseUrl !== '' && !$this->sameOrigin($currentBaseUrl, $nextBaseUrl);
        if ($originChanged) {
            $currentGlobalKey = trim((string) $this->settings->get_value(self::SETTING_GLOBAL_API_KEY, ''));
            if ($currentGlobalKey !== '' && $nextGlobalKey === '') {
                throw new InvalidArgumentException(
                    'Ao alterar a origem da Evolution, informe novamente a API key global para evitar reutilizar uma credencial em outro servidor.'
                );
            }

            $inheritedSpecificKeys = $this->db->table($this->db->prefixTable('chat_instances'))
                ->where('deleted', 0)
                ->where('api_key_encrypted IS NOT NULL', null, false)
                ->where('api_key_encrypted !=', '')
                ->groupStart()
                    ->where('base_url IS NULL', null, false)
                    ->orWhere('base_url', '')
                ->groupEnd()
                ->countAllResults();
            if ($inheritedSpecificKeys > 0) {
                throw new InvalidArgumentException(
                    'Existem instancias com chave propria herdando a URL global. Defina uma URL especifica ou remova essas chaves antes de trocar a origem.'
                );
            }
        }

        $currentN8nBase = trim((string) $this->settings->get_value('n8n_base_url', ''));
        $nextN8nBase = trim((string) ($values['n8n_base_url'] ?? ''));
        $nextN8nToken = trim((string) ($values['n8n_token'] ?? ''));
        if ($nextN8nBase !== '' && !$this->sameOrigin($currentN8nBase, $nextN8nBase)
            && trim((string) $this->settings->get_value('n8n_token', '')) !== '' && $nextN8nToken === '') {
            throw new InvalidArgumentException('Ao alterar a origem do n8n, informe novamente o token de integracao.');
        }

        $plain = [
            self::SETTING_BASE_URL => $nextBaseUrl,
            Chat_settings_model::EVOLUTION_TIMEOUT_SECONDS => (string) ($values['request_timeout_seconds'] ?? 30),
            Chat_settings_model::POLLING_INTERVAL_MS => (string) ($values['polling_interval_ms'] ?? 5000),
            Chat_settings_model::ENDPOINT_CONNECTION_STATE => (string) ($values['connection_status_path'] ?? ''),
            Chat_settings_model::ENDPOINT_FIND_CHATS => (string) ($values['find_chats_path'] ?? ''),
            Chat_settings_model::ENDPOINT_FIND_MESSAGES => (string) ($values['find_messages_path'] ?? ''),
            Chat_settings_model::ENDPOINT_SEND_TEXT => (string) ($values['send_text_path'] ?? ''),
            Chat_settings_model::ENDPOINT_SEND_MEDIA => (string) ($values['send_media_path'] ?? ''),
            Chat_settings_model::ENDPOINT_SEND_AUDIO => (string) ($values['send_audio_path'] ?? ''),
            Chat_settings_model::ENDPOINT_MEDIA_BASE64 => (string) ($values['get_media_base64_path'] ?? '/chat/getBase64FromMediaMessage/{instance}'),
        ];
        $plainMap = [
            'module_name','timezone','conversation_page_size','sound_enabled','browser_notifications_enabled','auto_mark_read','default_status','default_priority','sla_minutes','auto_resolve_hours','evolution_retries',
            'n8n_base_url','n8n_auth_mode','n8n_header_name','n8n_allow_private_networks','n8n_timeout_seconds','n8n_health_path','n8n_campaigns_path','n8n_ai_path','n8n_events_path',
            'campaign_window_start','campaign_window_end','campaign_batch_size','campaign_min_interval_seconds','campaign_pause_after_errors','campaign_optout_text','quick_replies_json',
            'ai_default_state','ai_human_priority','ai_show_context','ai_stop_command','ai_start_command','ai_auto_return_minutes',
            'log_sanitized_webhooks','webhook_retention_days','audit_enabled','audit_retention_days','conversation_retention_days','media_retention_days','secure_media',
        ];
        foreach ($plainMap as $key) {
            if (array_key_exists($key, $values)) {
                $plain[$key] = (string) $values[$key];
            }
        }
        foreach ($plain as $key => $value) {
            $this->settings->upsert_setting($key, $value, false);
        }

        $globalKey = $nextGlobalKey;
        if ($globalKey !== '') {
            $this->settings->upsert_setting(self::SETTING_GLOBAL_API_KEY, $globalKey, true);
        } elseif (!empty($values['clear_global_api_key'])) {
            $this->settings->delete_setting(self::SETTING_GLOBAL_API_KEY);
        }
        $webhookSecret = trim((string) ($values['webhook_secret'] ?? ''));
        if ($webhookSecret !== '') {
            $this->settings->upsert_setting(Chat_settings_model::WEBHOOK_SECRET, $webhookSecret, true);
        } elseif (!empty($values['clear_webhook_secret'])) {
            $this->settings->delete_setting(Chat_settings_model::WEBHOOK_SECRET);
        }

        if ($nextN8nToken !== '') {
            $this->settings->upsert_setting('n8n_token', $nextN8nToken, true);
        } elseif (!empty($values['clear_n8n_token'])) {
            $this->settings->delete_setting('n8n_token');
        }

        (new Audit_service())->record($actorId ?: null, 'settings.updated', 'settings', null, null, [], [
            'keys' => array_values(array_diff(array_keys($values), ['global_api_key', 'webhook_secret', 'n8n_token'])),
            'global_api_key_changed' => $globalKey !== '' || !empty($values['clear_global_api_key']),
            'webhook_secret_changed' => $webhookSecret !== '' || !empty($values['clear_webhook_secret']),
            'n8n_token_changed' => $nextN8nToken !== '' || !empty($values['clear_n8n_token']),
        ]);

        return $this->public_settings();
    }

    public function webhook_secret(): string
    {
        return (string) $this->settings->get_value(Chat_settings_model::WEBHOOK_SECRET, '');
    }

    /** @return array<int,array<string,mixed>> */
    public function recent_webhook_logs(int $limit = 20): array
    {
        $result = $this->webhookLogs->paginate_logs([], 1, min(100, max(1, $limit)));
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $instanceMap = $this->instanceMap(array_map(
            static fn (array $row): int => (int) ($row['instance_id'] ?? 0),
            $rows
        ));

        return array_map(function (array $row) use ($instanceMap): array {
            $row['status'] = !empty($row['success']) ? 'processed' : 'error';
            $row['time'] = $this->toIsoDate($row['created_at'] ?? null);
            $row['instance_name'] = (string) ($instanceMap[(int) ($row['instance_id'] ?? 0)]['name'] ?? '—');
            unset($row['payload'], $row['response_payload']);

            return $row;
        }, $rows);
    }

    /**
     * Processes one Evolution/n8n envelope. Authentication and batch splitting
     * are controller responsibilities so this method is easy to unit test.
     *
     * @return array<string,mixed>
     */
    public function process_webhook_event(array $payload): array
    {
        $safePayload = $this->sanitizer->sanitize($payload);
        $persistWebhookPayload = (int) $this->settings->get_value('log_sanitized_webhooks', 1) === 1;
        $payloadForLog = $persistWebhookPayload ? $safePayload : ['logging_disabled' => true];
        $normalized = $this->normalizer->normalize($payload);
        $event = (string) ($normalized['event'] ?? '');
        if ($event === '' && !empty($normalized['remote_jid'])) {
            $event = 'messages.upsert';
            $normalized['event'] = $event;
        }

        $instanceName = trim((string) ($normalized['instance_name'] ?? ''));
        $instance = $this->findInstanceForWebhook($instanceName);
        $externalEventId = trim((string) ($normalized['external_event_id'] ?? ''));
        if ($externalEventId === '') {
            $externalEventId = trim((string) ($normalized['external_message_id']
                ?? $normalized['dedupe_key']
                ?? hash('sha256', json_encode($safePayload) ?: '')));
        }
        if ($this->isMessageStatusEvent($event)) {
            // Evolution commonly reuses key.id for every delivery progression.
            // Including the normalized state keeps sent/delivered/read distinct.
            $externalEventId .= '|status:' . strtolower(trim((string) ($normalized['message_status'] ?? '')));
        }
        $eventKey = Chat_webhook_logs_model::build_event_dedupe_key(
            $instance ? (int) $instance['id'] : null,
            $event !== '' ? $event : 'unknown',
            $externalEventId
        );

        $messageLockId = trim((string) ($normalized['external_message_id'] ?? ''));
        $lockIdentity = $messageLockId !== '' && $instance
            ? 'message|' . (int) $instance['id'] . '|' . $messageLockId
            : 'event|' . $eventKey;
        $lockName = 'chat_webhook_' . substr(hash('sha256', $lockIdentity), 0, 40);
        if (!$this->acquireNamedLock($lockName, 2)) {
            if ($this->webhookLogs->was_processed($eventKey)) {
                return ['processed' => false, 'duplicate' => true, 'event' => $event];
            }

            $this->recordPendingWebhookAttempt(
                $instance ? (int) $instance['id'] : null,
                $event,
                $eventKey,
                $payloadForLog
            );
            if (!$persistWebhookPayload && is_array($safePayload)) {
                $this->enqueueWebhookRetry($eventKey, $safePayload);
            }

            return [
                'processed' => false,
                'duplicate' => false,
                'pending' => true,
                'retryable' => true,
                'http_status' => 202,
                'event' => $event,
                'error' => 'Evento aceito para uma nova tentativa.',
            ];
        }

        try {
            if ($this->webhookLogs->was_processed($eventKey)) {
                return ['processed' => false, 'duplicate' => true, 'event' => $event];
            }

            try {
                if (!$instance) {
                    throw new RuntimeException('Instancia do webhook nao cadastrada.');
                }

                $result = empty($instance['active'])
                    ? ['kind' => 'ignored', 'reason' => 'instance_inactive']
                    : $this->applyWebhookEvent($instance, $normalized, $payload);
                $this->webhookLogs->record_event([
                    'instance_id' => (int) $instance['id'],
                    'event_name' => $event !== '' ? $event : 'unknown',
                    'event_dedupe_key' => $eventKey,
                    'payload' => $payloadForLog,
                    'response_payload' => $result,
                    'http_status' => 200,
                    'success' => 1,
                    'processed_at' => gmdate('Y-m-d H:i:s'),
                ]);

                return array_merge(['processed' => true, 'duplicate' => false, 'event' => $event], $result);
            } catch (Throwable $exception) {
                $retryable = !($exception instanceof InvalidArgumentException);
                $this->webhookLogs->record_event([
                    'instance_id' => $instance ? (int) $instance['id'] : null,
                    'event_name' => $event !== '' ? $event : 'unknown',
                    'event_dedupe_key' => $eventKey,
                    'payload' => $payloadForLog,
                    'response_payload' => [
                        'processed' => false,
                        'pending' => $retryable,
                        'retryable' => $retryable,
                    ],
                    'error_message' => $this->safeTechnicalError($exception),
                    'http_status' => $retryable ? 202 : 422,
                    'success' => 0,
                    'processed_at' => $retryable ? null : gmdate('Y-m-d H:i:s'),
                ]);

                log_message('error', 'Chatwoot_plugin webhook processing failed ({exception_type}).', [
                    'exception_type' => get_class($exception),
                ]);
                if ($retryable && !$persistWebhookPayload && is_array($safePayload)) {
                    $this->enqueueWebhookRetry($eventKey, $safePayload);
                }

                return [
                    'processed' => false,
                    'duplicate' => false,
                    'pending' => $retryable,
                    'retryable' => $retryable,
                    'http_status' => $retryable ? 202 : 422,
                    'event' => $event,
                    'error' => 'Evento aceito, mas nao foi possivel processa-lo.',
                ];
            }
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /** @return array<string,mixed> */
    private function applyWebhookEvent(array $instance, array $normalized, array $raw): array
    {
        $event = (string) ($normalized['event'] ?? '');
        if (str_contains($event, 'connection')) {
            $client = new Evolution_client(['require_api_key' => false]);
            $status = $client->map_connection_state((string) ($normalized['status'] ?? ''));
            $this->instances->update_connection_status((int) $instance['id'], $status, gmdate('Y-m-d H:i:s'));
            if ($status !== 'connected') {
                try { (new Notification_service())->create('instance', 'Instancia desconectada', 'A instancia ' . (string) ($instance['name'] ?? $instance['evolution_instance_name'] ?? '') . ' esta desconectada.', 'instance', (int) $instance['id'], null, 'warning', 'instance-disconnected|' . $instance['id'] . '|' . gmdate('Y-m-d-H')); } catch (Throwable $exception) { /* Integration remains primary. */ }
            }

            return ['kind' => 'connection', 'status' => $status];
        }

        if ($this->isMessageStatusEvent($event)) {
            $externalId = trim((string) ($normalized['external_message_id'] ?? ''));
            $status = strtolower(trim((string) ($normalized['message_status'] ?? '')));
            if (!$this->isValidExternalMessageId($externalId)) {
                throw new InvalidArgumentException('Identificador externo de mensagem invalido.');
            }
            if (!in_array($status, self::WEBHOOK_MESSAGE_STATUSES, true)) {
                throw new InvalidArgumentException('Status de mensagem nao suportado.');
            }

            $message = $this->messages->find_by_external_id((int) $instance['id'], $externalId);
            if (!$message) {
                throw new RuntimeException('Mensagem ainda nao encontrada; atualizacao mantida pendente.');
            }

            $previousStatus = strtolower(trim((string) ($message['status'] ?? '')));
            $updated = false;
            if ($this->shouldAdvanceMessageStatus($previousStatus, $status)) {
                $updatePayload = ['status' => $status];
                if ($status === 'failed') { $updatePayload['failed_at'] = gmdate('Y-m-d H:i:s'); $updatePayload['delivery_error'] = 'Falha reportada pela Evolution.'; }
                $updated = $this->messages->update_message((int) $message['id'], $updatePayload);
                if (!$updated || $this->db->affectedRows() < 1) {
                    throw new RuntimeException('Atualizacao de status nao afetou a mensagem; evento mantido pendente.');
                }
                if ($status === 'failed') {
                    try { (new Notification_service())->create('message_failed', 'Falha de entrega', 'A Evolution reportou falha na entrega de uma mensagem.', 'conversation', (int) $message['conversation_id'], null, 'danger', 'delivery-failed|' . $instance['id'] . '|' . $externalId); } catch (Throwable $exception) { /* Status update remains primary. */ }
                }
            }

            return [
                'kind' => 'message_status',
                'external_message_id' => $externalId,
                'previous_status' => $previousStatus,
                'status' => $status,
                'updated' => $updated,
            ];
        }

        if (empty($normalized['remote_jid'])) {
            return ['kind' => 'ignored', 'reason' => 'Evento sem mensagem ou conexao reconhecida.'];
        }

        $message = $this->persistNormalizedMessage($instance, $normalized, $raw, true);

        return ['kind' => 'message', 'message_id' => (int) $message['id']];
    }

    /** @return array<string,mixed> */
    private function persistNormalizedMessage(
        array $instance,
        array $normalized,
        array $raw,
        bool $incrementUnread
    ): array {
        $instanceId = (int) $instance['id'];
        $remoteJid = trim((string) ($normalized['remote_jid'] ?? ''));
        if ($remoteJid === '' || $remoteJid === 'status@broadcast') {
            throw new InvalidArgumentException('remoteJid ausente ou nao atendivel.');
        }

        $conversationLock = 'chat_conversation_' . substr(hash('sha256', $instanceId . '|' . $remoteJid), 0, 40);
        if (!$this->acquireNamedLock($conversationLock, 2)) {
            throw new RuntimeException('Conversa ocupada; evento mantido pendente para nova tentativa.');
        }

        try {
            [$timestamp, $sentAt] = $this->normalizeTimestamp($normalized['timestamp'] ?? null);
            $externalId = trim((string) ($normalized['external_message_id'] ?? ''));
            $normalizerKey = (string) ($normalized['dedupe_key'] ?? '');
            $dedupeKey = strlen($normalizerKey) === 64 && ctype_xdigit($normalizerKey)
                ? strtolower($normalizerKey)
                : hash('sha256', $normalizerKey !== '' ? $normalizerKey : implode('|', [
                    (string) $instanceId,
                    $remoteJid,
                    $externalId,
                    (string) $timestamp,
                ]));

            $existingMessage = $externalId !== ''
                ? $this->messages->find_by_external_id($instanceId, $externalId)
                : $this->messages->find_by_dedupe_key($instanceId, $dedupeKey);
            $existingConversation = $this->conversations->get_by_remote_jid($instanceId, $remoteJid);

            $conversationData = [];
            $phone = trim((string) ($normalized['phone_number'] ?? ''));
            $name = trim((string) ($normalized['contact_name'] ?? ''));
            if ($phone !== '') {
                $conversationData['phone_number'] = $phone;
            }
            if ($name !== '') {
                $conversationData['contact_name'] = $name;
            }

            $currentActivity = $existingConversation && !empty($existingConversation['last_message_at'])
                ? $this->storedTimestamp($existingConversation['last_message_at'])
                : 0;
            if (!$currentActivity || $timestamp >= $currentActivity) {
                $conversationData['last_message_preview'] = $this->messagePreview($normalized);
                $conversationData['last_message_at'] = $sentAt;
            }

            $messageStatus = strtolower(trim((string) (
                $normalized['message_status'] ?? (!empty($normalized['from_me']) ? 'sent' : 'received')
            )));
            if ($existingMessage) {
                $existingStatus = strtolower(trim((string) ($existingMessage['status'] ?? '')));
                if (!$this->shouldAdvanceMessageStatus($existingStatus, $messageStatus)) {
                    $messageStatus = $existingStatus;
                }
            }

            if ((int) $this->db->transDepth !== 0) {
                throw new RuntimeException('Persistencia de mensagem nao pode reutilizar uma transacao externa.');
            }
            $this->db->resetTransStatus();
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Nao foi possivel iniciar a transacao da mensagem.');
            }
            $transactionOpen = true;
            try {
                $conversationId = $this->conversations->upsert_conversation($instanceId, $remoteJid, $conversationData);
                $messageId = $this->messages->upsert_message($conversationId, $instanceId, [
                    'external_message_id' => $externalId !== '' ? $externalId : null,
                    'remote_jid' => $remoteJid,
                    'direction' => !empty($normalized['from_me']) ? 'outgoing' : 'incoming',
                    'message_type' => $this->allowedMessageType((string) ($normalized['message_type'] ?? 'text')),
                    'text_content' => $this->boundedText((string) ($normalized['text'] ?? '')),
                    'media_url' => $this->safeMediaUrl($normalized['media_url'] ?? null),
                    'mime_type' => $this->safeMimeType($normalized['mime_type'] ?? null),
                    'caption' => in_array($this->allowedMessageType((string) ($normalized['message_type'] ?? 'text')), ['image','audio','video','document'], true) ? $this->boundedText((string) ($normalized['text'] ?? ''), 4096) : null,
                    'file_name' => isset($normalized['file_name']) ? $this->boundedText((string) $normalized['file_name'], 255) : null,
                    'status' => $messageStatus,
                    'sent_at' => $sentAt,
                    'message_timestamp' => $timestamp,
                    'dedupe_key' => $dedupeKey,
                    'raw_payload' => $this->sanitizer->sanitize($raw),
                ]);

                if (!$existingMessage && $incrementUnread && empty($normalized['from_me'])) {
                    if (!$this->conversations->increment_unread($conversationId)) {
                        throw new RuntimeException('Nao foi possivel atualizar o contador da conversa.');
                    }
                }
                if ($this->db->transStatus() === false || !$this->db->transCommit()) {
                    throw new RuntimeException('Falha ao confirmar a transacao da mensagem.');
                }
                $transactionOpen = false;
            } catch (Throwable $exception) {
                if ($transactionOpen) {
                    $this->db->transRollback();
                }
                $this->db->resetTransStatus();
                throw $exception;
            }

            $saved = $this->messages->get_by_id($messageId);
            if (!$saved) {
                throw new RuntimeException('Mensagem persistida nao encontrada.');
            }

            try {
                $contact = (new Contact_service())->resolve_for_message($instanceId, $normalized, $conversationId);
                if (!empty($contact['id'])) {
                    $this->conversations->upsert_conversation($instanceId, $remoteJid, ['contact_id' => (int) $contact['id']]);
                }
            } catch (Throwable $exception) {
                log_message('error', 'Chatwoot_plugin could not link message contact ({exception_type}).', ['exception_type' => get_class($exception)]);
            }
            if (!$existingMessage && $incrementUnread && empty($normalized['from_me'])) {
                try {
                    (new Notification_service())->create('message', 'Nova mensagem', $this->messagePreview($normalized), 'conversation', $conversationId, null, 'info', 'incoming|' . $instanceId . '|' . ($externalId ?: $dedupeKey));
                } catch (Throwable $exception) { /* Notification failure must not break webhook processing. */ }
            }

            return $saved;
        } finally {
            $this->releaseNamedLock($conversationLock);
        }
    }

    private function finalizeOptimisticMessage(
        int $messageId,
        int $conversationId,
        int $instanceId,
        string $clientMessageId,
        string $externalId,
        string $remoteJid,
        $rawResponse
    ): void {
        $payload = [
            'status' => 'sent',
            'raw_payload' => $this->sanitizer->sanitize([
                'source' => 'evolution_send_text',
                'response' => $rawResponse,
            ]),
        ];
        if ($externalId !== '') {
            $payload['external_message_id'] = $externalId;
            $payload['dedupe_key'] = Chat_messages_model::build_dedupe_key(
                $instanceId,
                $remoteJid,
                $externalId,
                time()
            );
        }

        try {
            if ($this->messages->update_message($messageId, $payload)) {
                return;
            }
            if ($externalId === '') {
                throw new RuntimeException('A confirmacao da Evolution nao atualizou a mensagem otimista.');
            }
        } catch (Throwable $exception) {
            if ($externalId === '') {
                throw $exception;
            }
        }

        // A webhook can win the race and insert the Evolution ID first. Merge
        // the optimistic row into that canonical row without exposing a duplicate.
        $canonical = $this->messages->find_by_external_id($instanceId, $externalId);
        if (!$canonical || (int) $canonical['id'] === $messageId) {
            throw new RuntimeException('Nao foi possivel reconciliar a confirmacao da Evolution.');
        }
        if ((int) ($canonical['conversation_id'] ?? 0) !== $conversationId
            || trim((string) ($canonical['remote_jid'] ?? '')) !== trim($remoteJid)
            || strtolower(trim((string) ($canonical['direction'] ?? ''))) !== 'outgoing') {
            throw new RuntimeException('A mensagem canonica nao pertence ao envio otimista.');
        }

        $table = $this->db->prefixTable('chat_messages');
        if ((int) $this->db->transDepth !== 0) {
            throw new RuntimeException('Reconciliacao nao pode reutilizar uma transacao externa.');
        }
        $this->db->resetTransStatus();
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Nao foi possivel iniciar a reconciliacao da mensagem enviada.');
        }
        $transactionOpen = true;
        try {
            $optimisticUpdated = $this->db->table($table)->where('id', $messageId)->where('deleted', 0)->update([
                'client_message_id' => null,
                'dedupe_key' => null,
                'deleted' => 1,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$optimisticUpdated || $this->db->affectedRows() < 1) {
                throw new RuntimeException('A mensagem otimista nao pode ser desativada.');
            }

            $canonicalPayload = [
                'client_message_id' => $clientMessageId,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            if ($this->shouldAdvanceMessageStatus((string) ($canonical['status'] ?? ''), 'sent')) {
                $canonicalPayload['status'] = 'sent';
            }
            $canonicalUpdated = $this->db->table($table)
                ->where('id', (int) $canonical['id'])
                ->where('deleted', 0)
                ->update($canonicalPayload);
            if (!$canonicalUpdated || $this->db->affectedRows() < 1) {
                throw new RuntimeException('A mensagem canonica nao pode receber o identificador local.');
            }

            if ($this->db->transStatus() === false || !$this->db->transCommit()) {
                throw new RuntimeException('Nao foi possivel confirmar a reconciliacao da mensagem enviada.');
            }
            $transactionOpen = false;
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            $this->db->resetTransStatus();
            throw $exception;
        }
    }

    private function clientForInstance(array $instance): Evolution_client
    {
        $apiKey = $this->instances->get_decrypted_api_key((int) $instance['id']);
        if (is_string($apiKey) && $apiKey !== '') {
            $instance['api_key'] = $apiKey;
        }

        if (is_callable($this->clientFactory)) {
            $client = call_user_func($this->clientFactory, $instance, $this->settings);
            if (!$client instanceof Evolution_client) {
                throw new RuntimeException('A fabrica do cliente Evolution retornou um valor invalido.');
            }

            return $client;
        }

        return new Evolution_client([
            'instance' => $instance,
            'timeout' => (int) $this->settings->get_value(
                Chat_settings_model::EVOLUTION_TIMEOUT_SECONDS,
                30
            ),
        ], null, $this->settings);
    }

    private function persistEvolutionChat(array $instance, array $chat): bool
    {
        $remoteJid = $this->firstScalar([
            $chat['remoteJid'] ?? null,
            $chat['remote_jid'] ?? null,
            $chat['jid'] ?? null,
            is_string($chat['id'] ?? null) && str_contains((string) $chat['id'], '@') ? $chat['id'] : null,
            $this->arrayPath($chat, ['key', 'remoteJid']),
            $this->arrayPath($chat, ['lastMessage', 'key', 'remoteJid']),
        ]);
        if ($remoteJid === '' || $remoteJid === 'status@broadcast') {
            return false;
        }

        $data = [];
        $alternateJid = $this->firstScalar([
            $chat['remoteJidAlt'] ?? null,
            $chat['remote_jid_alt'] ?? null,
            $this->arrayPath($chat, ['key', 'remoteJidAlt']),
            $this->arrayPath($chat, ['lastMessage', 'key', 'remoteJidAlt']),
            $this->arrayPath($chat, ['last_message', 'key', 'remoteJidAlt']),
        ]);
        $phone = $this->phoneFromRemoteJids($remoteJid, $alternateJid);
        if ($phone !== '') {
            $data['phone_number'] = $phone;
        }
        $name = $this->firstScalar([
            $chat['name'] ?? null,
            $chat['pushName'] ?? null,
            $chat['contactName'] ?? null,
            $this->arrayPath($chat, ['contact', 'name']),
        ]);
        if ($name !== '') {
            $data['contact_name'] = $this->boundedText($name, 191);
        }
        $picture = $this->safeMediaUrl($this->firstScalar([
            $chat['profilePictureUrl'] ?? null,
            $chat['profilePicUrl'] ?? null,
            $chat['profile_picture_url'] ?? null,
        ]));
        if ($picture !== null) {
            $data['profile_picture_url'] = $picture;
        }
        $unread = $this->firstNumeric([
            $chat['unreadCount'] ?? null,
            $chat['unread_count'] ?? null,
            $chat['unreadMessages'] ?? null,
        ]);
        if ($unread !== null) {
            $data['unread_count'] = min(1000000, max(0, $unread));
        }

        $lastMessage = is_array($chat['lastMessage'] ?? null) ? $chat['lastMessage'] : [];
        if ($lastMessage === [] && is_array($chat['last_message'] ?? null)) {
            $lastMessage = $chat['last_message'];
        }
        if ($lastMessage !== []) {
            $normalized = $this->normalizer->normalize([
                'event' => 'messages.upsert',
                'instance' => (string) $instance['evolution_instance_name'],
                'data' => $lastMessage,
            ]);
            if (empty($normalized['remote_jid'])) {
                $normalized['remote_jid'] = $remoteJid;
                $normalized['phone_number'] = $phone;
                $normalized['dedupe_key'] = $this->normalizer->dedupe_key($normalized);
            }
            if (!empty($normalized['external_message_id']) || !empty($normalized['text']) || !empty($normalized['media_url'])) {
                $this->conversations->upsert_conversation((int) $instance['id'], $remoteJid, $data);
                $this->persistNormalizedMessage(
                    $instance,
                    $normalized,
                    ['source' => 'evolution_find_chats', 'data' => $lastMessage],
                    false
                );

                return true;
            }
        }

        $activity = $this->firstScalar([
            $chat['lastMessageAt'] ?? null,
            $chat['updatedAt'] ?? null,
            $chat['updated_at'] ?? null,
            $chat['timestamp'] ?? null,
        ]);
        if ($activity !== '') {
            [, $data['last_message_at']] = $this->normalizeTimestamp($activity);
        }
        $preview = $this->firstScalar([
            $chat['lastMessagePreview'] ?? null,
            $chat['last_message_preview'] ?? null,
        ]);
        if ($preview !== '') {
            $data['last_message_preview'] = $this->boundedText($preview, 500);
        }

        $activityTimestamp = isset($data['last_message_at'])
            ? $this->storedTimestamp($data['last_message_at'])
            : null;
        $this->upsertConversationPreservingActivity(
            (int) $instance['id'],
            $remoteJid,
            $data,
            $activityTimestamp ?: null
        );

        return true;
    }

    /** @return array<int,array<string,mixed>> */
    private function extractEvolutionRecords($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if ($this->isList($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        $paths = [
            ['messages', 'records'],
            ['messages', 'data'],
            ['messages'],
            ['records'],
            ['data', 'records'],
            ['data', 'messages'],
            ['data'],
        ];
        foreach ($paths as $path) {
            $value = $data;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_array($value) && $this->isList($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return isset($data['key']) || isset($data['message']) ? [$data] : [];
    }

    private function arrayPath(array $data, array $path)
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function firstScalar(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function firstNumeric(array $values): ?int
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private function instanceMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $all = $this->instances->paginate_instances([], 1, 100);
        $map = [];
        foreach (($all['data'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (in_array($id, $ids, true)) {
                $map[$id] = $row;
            }
        }

        return $map;
    }

    /** @return array<string,mixed> */
    private function mapConversation(array $row, ?array $instance): array
    {
        $name = trim((string) ($row['contact_name'] ?? ''));
        $phone = trim((string) ($row['phone_number'] ?? ''));
        if ($name === '') {
            $name = $phone !== '' ? $phone : (string) $row['remote_jid'];
        }

        return [
            'id' => (int) $row['id'],
            'instance_id' => (int) $row['instance_id'],
            'instance_name' => (string) ($instance['name'] ?? ''),
            'instance_status' => (string) ($instance['connection_status'] ?? 'disconnected'),
            'remote_jid' => (string) $row['remote_jid'],
            'contact_name' => $name,
            'name' => $name,
            'phone_number' => $phone,
            'phone' => $phone,
            'profile_picture_url' => $this->safeMediaUrl($row['profile_picture_url'] ?? null),
            'last_message_preview' => (string) ($row['last_message_preview'] ?? ''),
            'last_message_at' => $this->toIsoDate($row['last_message_at'] ?? null),
            'unread_count' => (int) ($row['unread_count'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0) === 1,
            'status' => (string) ($row['status'] ?? 'open'),
            'contact_id' => isset($row['contact_id']) ? (int) $row['contact_id'] : null,
            'priority' => (string) ($row['priority'] ?? 'normal'),
            'assignee_id' => isset($row['assignee_id']) ? (int) $row['assignee_id'] : null,
            'assignee' => (string) ($row['_assignee_name'] ?? ''),
            'team_id' => isset($row['team_id']) ? (int) $row['team_id'] : null,
            'resolved_at' => $this->toIsoDate($row['resolved_at'] ?? null),
            'ai_status' => (string) ($row['ai_status'] ?? 'running'),
            'ai_summary' => (string) ($row['ai_summary'] ?? ''),
            'tags' => is_array($row['_tags'] ?? null) ? $row['_tags'] : [],
            'created_at' => $this->toIsoDate($row['created_at'] ?? null),
            'updated_at' => $this->toIsoDate($row['updated_at'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function mapMessage(array $row): array
    {
        $messageType = (string) ($row['message_type'] ?? 'text');
        $hasMedia = in_array($messageType, ['image', 'audio', 'video', 'document'], true)
            || !empty($row['media_id'])
            || !empty($row['media_url']);
        return [
            'id' => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'instance_id' => (int) $row['instance_id'],
            'external_message_id' => $row['external_message_id'] ?? null,
            'client_message_id' => $row['client_message_id'] ?? null,
            'remote_jid' => (string) $row['remote_jid'],
            'direction' => (string) $row['direction'],
            'message_type' => $messageType,
            'text_content' => (string) ($row['text_content'] ?? ''),
            'media_url' => !empty($row['media_id'])
                ? (function_exists('get_uri') ? get_uri('chatwoot_plugin/api/media/' . (int) $row['media_id']) : '/chatwoot_plugin/api/media/' . (int) $row['media_id'])
                : ($hasMedia ? (function_exists('get_uri') ? get_uri('chatwoot_plugin/api/media/message/' . (int) $row['id']) : '/chatwoot_plugin/api/media/message/' . (int) $row['id']) : null),
            'mime_type' => $this->safeMimeType($row['mime_type'] ?? null),
            'caption' => (string) ($row['caption'] ?? ''),
            'file_name' => (string) ($row['file_name'] ?? ''),
            'file_size' => (int) ($row['file_size'] ?? 0),
            'media_id' => isset($row['media_id']) ? (int) $row['media_id'] : null,
            'sender_user_id' => isset($row['sender_user_id']) ? (int) $row['sender_user_id'] : null,
            'is_internal_note' => !empty($row['is_internal_note']),
            'delivery_error' => $row['delivery_error'] ?? null,
            'status' => (string) ($row['status'] ?? 'received'),
            'sent_at' => $this->toIsoDate($row['sent_at'] ?? $row['created_at'] ?? null),
            'message_timestamp' => isset($row['message_timestamp']) ? (int) $row['message_timestamp'] : null,
            'created_at' => $this->toIsoDate($row['created_at'] ?? null),
            'updated_at' => $this->toIsoDate($row['updated_at'] ?? null),
        ];
    }

    /** @return array<int,int> */
    private function messagesTodayByInstance(array $instanceIds): array
    {
        $instanceIds = array_values(array_unique(array_filter(array_map('intval', $instanceIds))));
        if (!$instanceIds) {
            return [];
        }

        $rows = $this->db->table($this->db->prefixTable('chat_messages'))
            ->select('instance_id, COUNT(id) AS total', false)
            ->whereIn('instance_id', $instanceIds)
            ->where('deleted', 0)
            ->where('created_at >=', gmdate('Y-m-d 00:00:00'))
            ->groupBy('instance_id')
            ->get()
            ->getResultArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['instance_id']] = (int) $row['total'];
        }

        return $map;
    }

    private function findInstanceForWebhook(string $instanceName): ?array
    {
        if ($instanceName === '') {
            return null;
        }

        return $this->instances->get_by_evolution_name($instanceName)
            ?? $this->instances->get_by_identifier($instanceName);
    }

    /** @return array{0:int,1:string} */
    private function normalizeTimestamp($value): array
    {
        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 20000000000) {
                $timestamp = (int) floor($timestamp / 1000);
            }
        } elseif (is_string($value) && trim($value) !== '') {
            $parsed = strtotime($value);
            $timestamp = $parsed !== false ? $parsed : time();
        } else {
            $timestamp = time();
        }
        if ($timestamp < 1 || $timestamp > time() + 315360000) {
            $timestamp = time();
        }

        return [$timestamp, gmdate('Y-m-d H:i:s', $timestamp)];
    }

    private function messagePreview(array $normalized): string
    {
        $text = trim((string) ($normalized['text'] ?? ''));
        if ($text !== '') {
            return $this->boundedText($text, 500);
        }

        return match ($this->allowedMessageType((string) ($normalized['message_type'] ?? ''))) {
            'image' => '[Imagem]',
            'audio' => '[Audio]',
            'document' => '[Documento]',
            default => '[Mensagem]',
        };
    }

    private function boundedText(string $value, int $limit = 10000): string
    {
        $value = str_replace("\0", '', $value);

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    private function allowedMessageType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['text', 'image', 'audio', 'document'], true) ? $type : 'text';
    }

    private function safeMimeType($value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || strlen($value) > 191 || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $value)) {
            return null;
        }

        return $value;
    }

    private function safeMediaUrl($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 4096 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    private function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return 'Nao configurado';
        }

        return str_repeat('•', 8) . substr($secret, -4);
    }

    private function toIsoDate($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }

    private function storedTimestamp($value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $value = trim($value);
        $timestamp = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));

        return $timestamp === false ? 0 : $timestamp;
    }

    private function safeTechnicalError(Throwable $exception): string
    {
        return substr('processing_error:' . get_class($exception), 0, 500);
    }

    /** @param array<string,mixed> $data */
    private function upsertConversationPreservingActivity(
        int $instanceId,
        string $remoteJid,
        array $data,
        ?int $activityTimestamp = null
    ): int {
        $conversationLock = 'chat_conversation_' . substr(hash('sha256', $instanceId . '|' . $remoteJid), 0, 40);
        if (!$this->acquireNamedLock($conversationLock, 2)) {
            throw new RuntimeException('Conversa ocupada; atualizacao mantida para nova tentativa.');
        }

        try {
            if (array_key_exists('last_message_at', $data)) {
                $activityTimestamp ??= $this->storedTimestamp($data['last_message_at']) ?: null;
                $existing = $this->conversations->get_by_remote_jid($instanceId, $remoteJid);
                $currentTimestamp = $existing && !empty($existing['last_message_at'])
                    ? $this->storedTimestamp($existing['last_message_at'])
                    : 0;
                if ($currentTimestamp && (!$activityTimestamp || $activityTimestamp < $currentTimestamp)) {
                    unset($data['last_message_at'], $data['last_message_preview']);
                }
            }

            return $this->conversations->upsert_conversation($instanceId, $remoteJid, $data);
        } finally {
            $this->releaseNamedLock($conversationLock);
        }
    }

    /** @param array<string,mixed> $instance */
    private function recordSuccessfulSync(array $instance, Evolution_client $client): void
    {
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId < 1) {
            throw new RuntimeException('Instancia invalida ao registrar sincronizacao.');
        }

        $syncedAt = gmdate('Y-m-d H:i:s');
        try {
            $statusResponse = $client->status();
        } catch (Throwable $exception) {
            $statusResponse = ['success' => false];
        }

        $connectionStatus = strtolower(trim((string) ($statusResponse['connection_status'] ?? '')));
        if (!empty($statusResponse['success'])
            && in_array($connectionStatus, ['connected', 'attention', 'disconnected'], true)) {
            if (!$this->instances->update_connection_status($instanceId, $connectionStatus, $syncedAt)) {
                throw new RuntimeException('Nao foi possivel registrar o estado da instancia sincronizada.');
            }

            return;
        }

        $updated = $this->db->table($this->db->prefixTable('chat_instances'))
            ->where('id', $instanceId)
            ->where('deleted', 0)
            ->update([
                'last_sync_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ]);
        if (!$updated) {
            throw new RuntimeException('Nao foi possivel registrar a sincronizacao da instancia.');
        }
    }

    /** @param mixed $safePayload */
    private function recordPendingWebhookAttempt(
        ?int $instanceId,
        string $event,
        string $canonicalEventKey,
        $safePayload
    ): void {
        try {
            // This attempt intentionally has no unique dedupe key. It must not
            // overwrite a success committed by the lock owner in a race.
            $this->webhookLogs->record_event([
                'instance_id' => $instanceId,
                'event_name' => $event !== '' ? $event : 'unknown',
                'event_dedupe_key' => null,
                'payload' => is_array($safePayload) ? $safePayload : [],
                'response_payload' => [
                    'processed' => false,
                    'pending' => true,
                    'retryable' => true,
                    'canonical_event_key' => $canonicalEventKey,
                ],
                'error_message' => 'lock_contention',
                'http_status' => 202,
                'success' => 0,
                'processed_at' => null,
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not persist a pending webhook attempt.');
        }
    }

    private function enqueueWebhookRetry(string $eventKey, array $safePayload): void
    {
        try {
            $correlation = 'webhook-event-' . $eventKey;
            $exists = $this->db->table('chat_integration_jobs')
                ->where('correlation_id', $correlation)
                ->where('deleted', 0)
                ->whereIn('status', ['pending', 'retry', 'running'])
                ->countAllResults();
            if ($exists === 0) {
                (new Integration_job_service())->enqueue('webhook_retry', ['event' => $safePayload], 5, $correlation);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not enqueue a webhook retry ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);
        }
    }

    private function isMessageStatusEvent(string $event): bool
    {
        $event = strtolower(trim($event));

        return str_contains($event, 'messages.update') || str_contains($event, 'message.update');
    }

    private function isValidExternalMessageId(string $externalId): bool
    {
        $externalId = trim($externalId);

        return $externalId !== ''
            && strlen($externalId) <= 191
            && preg_match('/[\x00-\x20\x7F]/', $externalId) !== 1;
    }

    private function shouldAdvanceMessageStatus(string $currentStatus, string $nextStatus): bool
    {
        $currentStatus = strtolower(trim($currentStatus));
        $nextStatus = strtolower(trim($nextStatus));
        if ($nextStatus === '' || $currentStatus === $nextStatus) {
            return false;
        }

        if ($nextStatus === 'failed') {
            return !in_array($currentStatus, ['failed', 'delivered', 'read'], true);
        }
        if ($currentStatus === 'failed') {
            return in_array($nextStatus, ['sent', 'delivered', 'read'], true);
        }
        if (!array_key_exists($nextStatus, self::MESSAGE_STATUS_RANK)) {
            return false;
        }

        $currentRank = self::MESSAGE_STATUS_RANK[$currentStatus] ?? -1;

        return self::MESSAGE_STATUS_RANK[$nextStatus] > $currentRank;
    }

    private function isGroupJid(string $remoteJid): bool
    {
        return str_ends_with(strtolower(trim($remoteJid)), '@g.us');
    }

    private function isLidJid(string $remoteJid): bool
    {
        return str_ends_with(strtolower(trim($remoteJid)), '@lid');
    }

    private function phoneFromRemoteJids(string $remoteJid, string $alternateJid = ''): string
    {
        $alternateJid = strtolower(trim($alternateJid));
        if (str_ends_with($alternateJid, '@s.whatsapp.net') || str_ends_with($alternateJid, '@c.us')) {
            return (string) preg_replace('/\D+/', '', explode('@', $alternateJid, 2)[0]);
        }

        if ($this->isLidJid($remoteJid)) {
            return '';
        }

        return (string) preg_replace('/\D+/', '', explode('@', $remoteJid, 2)[0]);
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $leftParts = parse_url($left);
        $rightParts = parse_url($right);
        if (!is_array($leftParts) || !is_array($rightParts)) {
            return false;
        }

        $leftScheme = strtolower((string) ($leftParts['scheme'] ?? ''));
        $rightScheme = strtolower((string) ($rightParts['scheme'] ?? ''));
        $leftHost = strtolower(rtrim((string) ($leftParts['host'] ?? ''), '.'));
        $rightHost = strtolower(rtrim((string) ($rightParts['host'] ?? ''), '.'));
        if ($leftScheme === '' || $rightScheme === '' || $leftHost === '' || $rightHost === '') {
            return false;
        }

        $leftPort = (int) ($leftParts['port'] ?? ($leftScheme === 'https' ? 443 : 80));
        $rightPort = (int) ($rightParts['port'] ?? ($rightScheme === 'https' ? 443 : 80));

        return $leftScheme === $rightScheme
            && $leftHost === $rightHost
            && $leftPort === $rightPort;
    }

    private function acquireNamedLock(string $name, int $timeout): bool
    {
        try {
            $row = $this->db->query('SELECT GET_LOCK(?, ?) AS acquired_lock', [$name, $timeout])
                ->getRowArray();

            return (int) ($row['acquired_lock'] ?? 0) === 1;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function releaseNamedLock(string $name): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not release a named lock.');
        }
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
