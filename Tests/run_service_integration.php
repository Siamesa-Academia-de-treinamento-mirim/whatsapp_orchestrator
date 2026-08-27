<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__, 3);
define('FCPATH', $root . DIRECTORY_SEPARATOR);
$_SERVER = array_replace($_SERVER, [
    'CI_ENVIRONMENT' => 'development',
    'HTTP_HOST' => 'localhost',
    'SCRIPT_NAME' => '/rise/index.php',
    'REQUEST_URI' => '/rise/',
    'REQUEST_METHOD' => 'GET',
    'SERVER_PORT' => '80',
]);
defined('ENVIRONMENT') || define('ENVIRONMENT', (string) $_SERVER['CI_ENVIRONMENT']);
defined('CI_DEBUG') || define('CI_DEBUG', true);
chdir($root);

require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
Config\Services::autoloader()->addNamespace('Chatwoot_plugin', dirname(__DIR__));

(new Chatwoot_plugin\Libraries\Migration_runner())->migrate();

class Chat_service_test_evolution_client extends Chatwoot_plugin\Libraries\Evolution_client
{
    public int $sendCalls = 0;
    public int $postCalls = 0;
    public int $statusCalls = 0;
    public string $nextMessageId = 'SIMULATED-SEND-1';
    public int $textFailureAfter = PHP_INT_MAX;
    public ?int $textFailureStatus = null;
    public string $lastSendNumber = '';
    public array $lastChatFilters = [];
    public array $lastMessageOptions = [];
    public array $lastPostPayload = [];
    public string $lastPostPath = '';
    public int $mediaSendCalls = 0;
    public int $mediaFailureAfter = PHP_INT_MAX;
    public ?int $mediaFailureStatus = null;
    public int $reactionSendCalls = 0;
    public string $reactionMode = 'success';
    public ?int $reactionStatus = null;
    public array $lastReactionOptions = [];
    private string $instanceName;

    public function __construct(string $instanceName)
    {
        $this->instanceName = $instanceName;
    }

    public function find_chats(array $filters = [], $instance = null): array
    {
        $this->lastChatFilters = $filters;

        return [
            'success' => true,
            'data' => [[
                'remoteJid' => '5511888888888@s.whatsapp.net',
                'name' => 'Contato sincronizado',
                'unreadCount' => 2,
                'lastMessage' => [
                    'key' => [
                        'id' => 'CHAT-LAST-TEST',
                        'remoteJid' => '5511888888888@s.whatsapp.net',
                        'fromMe' => false,
                    ],
                    'messageTimestamp' => time(),
                    'message' => ['conversation' => 'Ultima mensagem sincronizada'],
                ],
            ]],
        ];
    }

    public function send_text(string $number, string $text, $instance = null): array
    {
        $this->sendCalls++;
        $this->lastSendNumber = $number;
        if ($this->sendCalls > $this->textFailureAfter) {
            return ['success' => false, 'status_code' => $this->textFailureStatus ?? 0, 'error' => 'simulated text provider failure'];
        }

        return [
            'success' => true,
            'message_id' => $this->nextMessageId,
            'data' => ['key' => ['id' => $this->nextMessageId], 'status' => 'PENDING'],
        ];
    }

    public function send_media(string $number, string $media, string $mimeType, string $mediaType, string $fileName = '', string $caption = '', $instance = null, array $options = []): array
    {
        $this->mediaSendCalls++;
        if ($this->mediaSendCalls > $this->mediaFailureAfter) {
            return ['success' => false, 'status_code' => $this->mediaFailureStatus ?? 0, 'error' => 'simulated media provider failure', 'message_id' => null];
        }

        return ['success' => true, 'message_id' => 'MEDIA-SEND-' . $this->mediaSendCalls, 'data' => ['key' => ['id' => 'MEDIA-SEND-' . $this->mediaSendCalls]]];
    }

    public function post(string $path, array $payload = [], $instance = null): array
    {
        $this->postCalls++;
        $this->lastPostPath = $path;
        $this->lastPostPayload = $payload;

        return [
            'success' => true,
            'data' => ['key' => ['id' => $this->nextMessageId], 'status' => 'PENDING'],
        ];
    }

    public function send_reaction(string $number, string $messageId, string $emoji, $instance = null, array $options = []): array
    {
        $this->reactionSendCalls++;
        $this->lastReactionOptions = ['number' => $number, 'message_id' => $messageId, 'emoji' => $emoji, 'options' => $options];
        if ($this->reactionMode === 'throw') throw new RuntimeException('simulated reaction transport timeout');
        if ($this->reactionMode !== 'success') return ['success' => false, 'status_code' => $this->reactionStatus ?? 422, 'error' => 'simulated reaction rejection'];
        return ['success' => true, 'message_id' => 'REACTION-SEND-' . $this->reactionSendCalls, 'data' => ['key' => ['id' => 'REACTION-SEND-' . $this->reactionSendCalls]]];
    }

    public function status($instance = null): array
    {
        $this->statusCalls++;

        return [
            'success' => false,
            'connection_status' => 'error',
            'error' => 'simulated connectionState failure',
        ];
    }

    public function find_messages(string $remoteJid, array $options = [], $instance = null): array
    {
        $this->lastMessageOptions = $options;

        return [
            'success' => true,
            'data' => [
                'messages' => [
                    'records' => [[
                        'key' => [
                            'id' => 'HISTORY-TEST-1',
                            'remoteJid' => $remoteJid,
                            'fromMe' => true,
                        ],
                        'messageTimestamp' => time() - 30,
                        'message' => ['conversation' => 'Historico remoto'],
                        'status' => 'READ',
                    ]],
                ],
            ],
        ];
    }
}

class Media_engine_test_uploaded_file extends CodeIgniter\HTTP\Files\UploadedFile
{
    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK && !$this->hasMoved();
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $targetPath = rtrim($targetPath, '\\/') . DIRECTORY_SEPARATOR;
        if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
            throw new RuntimeException('test upload target could not be created');
        }
        $name = $name ?: $this->getName();
        $destination = $targetPath . $name;
        if (!$overwrite && is_file($destination)) {
            throw new RuntimeException('test upload target already exists');
        }
        if (!copy($this->getTempName(), $destination)) {
            throw new RuntimeException('test upload fixture could not be copied');
        }
        $this->hasMoved = true;
        $this->path = $destination;
        $this->name = basename($destination);
        return true;
    }
}

class Chat_service_test_messages_model extends Chatwoot_plugin\Models\Chat_messages_model
{
    public ?string $returnFalseForExternalId = null;
    public ?string $throwForRemoteJid = null;

    public function update_message(int $id, array $data): bool
    {
        if ($this->returnFalseForExternalId !== null
            && (string) ($data['external_message_id'] ?? '') === $this->returnFalseForExternalId) {
            $this->returnFalseForExternalId = null;

            return false;
        }

        return parent::update_message($id, $data);
    }

    public function upsert_message(int $conversationId, int $instanceId, array $data): int
    {
        if ($this->throwForRemoteJid !== null
            && (string) ($data['remote_jid'] ?? '') === $this->throwForRemoteJid) {
            $this->throwForRemoteJid = null;
            throw new RuntimeException('simulated message persistence failure');
        }

        return parent::upsert_message($conversationId, $instanceId, $data);
    }
}

class Chat_service_test_cursor_reaction_service extends Chatwoot_plugin\Services\Message_reaction_service
{
    public array $calls = [];
    public bool $injectDuringAggregate = true;
    public int $injectedChangeId = 0;
    public int $injectionMessageId = 0;

    public function __construct(private CodeIgniter\Database\BaseConnection $testDb, ?Chatwoot_plugin\Models\Chat_messages_model $messages = null)
    {
        parent::__construct($testDb, null, $messages);
    }

    public function changesAfter(int $conversationId, ?int $cursor): array
    {
        $this->calls[] = 'cursor';
        return parent::changesAfter($conversationId, $cursor);
    }

    public function aggregates(array $messageIds): array
    {
        $this->calls[] = 'aggregate';
        if ($this->injectDuringAggregate && $messageIds) {
            $this->injectDuringAggregate = false;
            $targetId = $this->injectionMessageId > 0 ? $this->injectionMessageId : (int) $messageIds[0];
            $reaction = (new Chatwoot_plugin\Models\Chat_message_reactions_model($this->testDb))->find_by_target_actor($targetId, 'self');
            if ($reaction) {
                $this->testDb->table($this->testDb->prefixTable('chat_message_reaction_changes'))->insert([
                    'reaction_id' => (int) $reaction['id'],
                    'message_id' => $targetId,
                    'instance_id' => (int) ($reaction['instance_id'] ?? 0),
                    'created_at' => gmdate('Y-m-d H:i:s.u'),
                ]);
                $this->injectedChangeId = (int) $this->testDb->insertID();
            }
        }
        return parent::aggregates($messageIds);
    }
}

$db = db_connect('default');
$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$instanceName = 'codex_test_' . $suffix;
$instanceId = 0;
$jobCorrelationIds = [];
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $instances = new Chatwoot_plugin\Models\Chat_instances_model();
    $conversations = new Chatwoot_plugin\Models\Chat_conversations_model();
    $messages = new Chat_service_test_messages_model();
    $fakeClient = new Chat_service_test_evolution_client($instanceName);
    $chat = new Chatwoot_plugin\Services\Chat_service(
        $instances,
        $conversations,
        $messages,
        null,
        null,
        null,
        null,
        null,
        static fn (array $instance, $settings): Chatwoot_plugin\Libraries\Evolution_client => $fakeClient
    );

    $instanceId = $instances->upsert_instance($instanceName, [
        'name' => 'Codex Integration Test',
        'evolution_instance_name' => $instanceName,
        'base_url' => 'https://evolution.invalid',
        'api_key' => 'test-key-never-logged',
        'phone_number' => '5511999999999',
        'connection_status' => 'disconnected',
        'active' => 1,
    ]);

    $payload = [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'id' => 'MSG-' . $suffix,
                'remoteJid' => '5511888888888@s.whatsapp.net',
                'fromMe' => false,
            ],
            'pushName' => 'Contato de Teste',
            'messageTimestamp' => time(),
            'message' => ['conversation' => 'Mensagem de integracao'],
            'mediaUrl' => 'javascript:alert(1)',
        ],
    ];

    $first = $chat->process_webhook_event($payload);
    $duplicate = $chat->process_webhook_event($payload);
    $assert(!empty($first['processed']), 'first webhook was not processed');
    $assert(!empty($duplicate['duplicate']), 'duplicate webhook was not detected');

    $conversation = $conversations->get_by_remote_jid($instanceId, '5511888888888@s.whatsapp.net');
    $assert(is_array($conversation), 'conversation was not created');
    $assert((int) ($conversation['unread_count'] ?? 0) === 1, 'unread counter is not idempotent');

    // Collaboration presence uses an injectable clock so a viewing heartbeat
    // cannot accidentally clear an unexpired typing signal.
    $presenceClock = time();
    $presenceService = new Chatwoot_plugin\Services\Conversation_presence_service(
        null,
        $conversations,
        $db,
        static function () use (&$presenceClock): int { return $presenceClock; }
    );
    $presenceService->touch((int) $conversation['id'], 7, 'leave');
    $presenceService->touch((int) $conversation['id'], 7, 'viewing');
    $presenceService->touch((int) $conversation['id'], 7, 'typing');
    $presenceService->touch((int) $conversation['id'], 7, 'viewing');
    $firstOrderRow = $db->table('chat_conversation_presence')->where('conversation_id', (int) $conversation['id'])->where('user_id', 7)->where('deleted', 0)->get(1)->getRowArray();
    $assert(!empty($firstOrderRow['typing_until']) && !empty($firstOrderRow['viewing']), 'viewing then typing then viewing preserves typing atomically');
    $presenceService->touch((int) $conversation['id'], 7, 'leave');
    $presenceService->touch((int) $conversation['id'], 7, 'typing');
    $typingRow = $db->table('chat_conversation_presence')->where('conversation_id', (int) $conversation['id'])->where('user_id', 7)->where('deleted', 0)->get(1)->getRowArray();
    $typingUntil = (string) ($typingRow['typing_until'] ?? '');
    $presenceClock += 5;
    $presenceService->touch((int) $conversation['id'], 7, 'viewing');
    $viewingRow = $db->table('chat_conversation_presence')->where('conversation_id', (int) $conversation['id'])->where('user_id', 7)->where('deleted', 0)->get(1)->getRowArray();
    $assert($typingUntil !== '' && (string) ($viewingRow['typing_until'] ?? '') === $typingUntil, 'viewing heartbeat preserves an unexpired typing TTL');
    $presenceClock += 4;
    $presenceService->list((int) $conversation['id']);
    $expiredTypingRow = $db->table('chat_conversation_presence')->where('conversation_id', (int) $conversation['id'])->where('user_id', 7)->where('deleted', 0)->get(1)->getRowArray();
    $assert(empty($expiredTypingRow['typing_until']) && !empty($expiredTypingRow['viewing']), 'typing expires independently while viewing remains active');

    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(is_array($message), 'message was not created');
    $assert(($message['text_content'] ?? '') === 'Mensagem de integracao', 'message text differs');
    $assert(empty($message['media_url']), 'unsafe media URL was persisted');
    $assert($messages->count_by_conversation((int) ($conversation['id'] ?? 0)) === 1, 'message was duplicated');

    $deliveredAt = time() - 30;
    $deliveredPayload = [
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'MSG-' . $suffix],
            'status' => 'DELIVERY_ACK',
            'timestamp' => $deliveredAt,
        ],
    ];
    $statusResult = $chat->process_webhook_event($deliveredPayload);
    $statusDuplicate = $chat->process_webhook_event($deliveredPayload);
    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(($statusResult['kind'] ?? '') === 'message_status', 'status event was not recognized');
    $assert(($message['status'] ?? '') === 'delivered', 'message status was not updated');
    $assert(($message['delivered_at'] ?? '') === gmdate('Y-m-d H:i:s', $deliveredAt), 'provider delivered timestamp was persisted');
    $assert(!empty($statusDuplicate['duplicate']), 'same message status was not deduplicated');

    $readAt = time() - 15;
    $readResult = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'MSG-' . $suffix],
            'status' => 'READ',
            'timestamp' => $readAt,
        ],
    ]);
    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(!empty($readResult['processed']), 'read progression collided with delivered dedupe');
    $assert(($message['status'] ?? '') === 'read', 'read progression was not persisted');
    $assert(($message['read_at'] ?? '') === gmdate('Y-m-d H:i:s', $readAt), 'provider read timestamp was persisted');

    $staleStatus = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'MSG-' . $suffix],
            'status' => 'SERVER_ACK',
        ],
    ]);
    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(!empty($staleStatus['processed']) && empty($staleStatus['updated']), 'stale status was not a safe no-op');
    $assert(($message['status'] ?? '') === 'read', 'stale status regressed a read message');
    $assert(($message['read_at'] ?? '') === gmdate('Y-m-d H:i:s', $readAt), 'stale status did not regress read timestamp');

    $invalidId = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => ['status' => 'READ'],
    ]);
    $invalidStatus = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'MSG-' . $suffix],
            'status' => 'UNSUPPORTED_STATE',
        ],
    ]);
    $assert(empty($invalidId['processed']) && empty($invalidId['retryable']), 'missing external id was marked successful');
    $assert(empty($invalidStatus['processed']) && empty($invalidStatus['retryable']), 'invalid status was marked successful');

    $futureExternalId = 'FUTURE-' . $suffix;
    $futureStatusPayload = [
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => $futureExternalId],
            'status' => 'DELIVERY_ACK',
        ],
    ];
    $futurePending = $chat->process_webhook_event($futureStatusPayload);
    $assert(empty($futurePending['processed']) && !empty($futurePending['pending']), 'status for an absent message was not pending');
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'id' => $futureExternalId,
                'remoteJid' => '5511888888888@s.whatsapp.net',
                'fromMe' => true,
            ],
            'messageTimestamp' => time(),
            'message' => ['conversation' => 'Mensagem que chegou depois do status'],
        ],
    ]);
    $futureRetried = $chat->process_webhook_event($futureStatusPayload);
    $futureMessage = $messages->find_by_external_id($instanceId, $futureExternalId);
    $futureRaw = json_decode((string) ($futureMessage['raw_payload'] ?? ''), true);
    $assert(!empty($futureRetried['processed']), 'pending status could not be reprocessed');
    $assert(($futureMessage['status'] ?? '') === 'delivered', 'reprocessed status was not applied');
    $assert(($futureRaw['source'] ?? '') === 'provider_event_compact', 'text webhook retained the complete provider envelope');
    $assert(strlen((string) ($futureMessage['raw_payload'] ?? '')) < 1024, 'compact text payload exceeded its storage budget');

    $failedExternalId = 'FAILED-' . $suffix;
    $failedAt = time() - 10;
    $failedResult = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => $failedExternalId, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time(),
            'message' => ['conversation' => 'Mensagem que falhou'],
        ],
    ]);
    $failedResult = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => $failedExternalId],
            'status' => 'ERROR',
            'timestamp' => $failedAt,
        ],
    ]);
    $failedMessage = $messages->find_by_external_id($instanceId, $failedExternalId);
    $assert(!empty($failedResult['processed']) && ($failedMessage['status'] ?? '') === 'failed', 'failed status event was persisted');
    $assert(($failedMessage['failed_at'] ?? '') === gmdate('Y-m-d H:i:s', $failedAt), 'failed_at prefers the reliable provider timestamp');

    $sync = $chat->sync_chats($instanceId);
    $instance = $instances->get_by_id($instanceId);
    $conversation = $conversations->get_by_remote_jid($instanceId, '5511888888888@s.whatsapp.net');
    $assert(($sync['chats'] ?? 0) === 1, 'Evolution chat list was not synchronized');
    $assert($fakeClient->lastChatFilters === ['page' => 1, 'offset' => 100], 'chat synchronization was not page-bounded');
    $assert(($instance['connection_status'] ?? '') === 'disconnected', 'findChats falsely marked the instance connected');
    $assert(!empty($instance['last_sync_at']), 'successful findChats did not update last_sync_at');
    $assert($fakeClient->statusCalls >= 1, 'connectionState was not consulted after sync');
    $assert(($conversation['contact_name'] ?? '') === 'Contato sincronizado', 'synchronized contact name differs');
    $assert((int) ($conversation['unread_count'] ?? 0) === 2, 'remote unread counter was not applied');
    $assert($messages->count_by_conversation((int) $conversation['id']) === 4, 'last chat message was not persisted once');

    $noteActions = new Chatwoot_plugin\Services\Conversation_action_service(
        $conversations,
        $messages,
        null,
        null,
        null,
        null,
        $chat,
        null,
        $db
    );
    $textCollisionId = 'note-collision-text-' . $suffix;
    $textCollisionMessageId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'remote_jid' => (string) $conversation['remote_jid'],
        'direction' => 'outgoing',
        'message_type' => 'text',
        'text_content' => 'Mesmo payload visual',
        'status' => 'sent',
        'client_message_id' => $textCollisionId,
        'dedupe_key' => hash('sha256', $textCollisionId),
        'message_timestamp' => time(),
    ]);
    $textCollisionBefore = $messages->find_by_client_message_id((int) $conversation['id'], $textCollisionId);
    $textCollision = null;
    try { $noteActions->add_note((int) $conversation['id'], 'Mesmo payload visual', 7, $textCollisionId); }
    catch (Throwable $exception) { $textCollision = $exception; }
    $textCollisionAfter = $messages->find_by_client_message_id((int) $conversation['id'], $textCollisionId);
    $assert($textCollision instanceof Chatwoot_plugin\Services\Message_send_exception
        && $textCollision->getCode() === 409
        && ($textCollision->details()['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH'
        && (int) ($textCollisionAfter['id'] ?? 0) === $textCollisionMessageId
        && ($textCollisionBefore['updated_at'] ?? '') === ($textCollisionAfter['updated_at'] ?? ''), 'note endpoint rejects a same-text collision with an existing outbound message without writes');

    $templateCollisionId = 'note-collision-template-' . $suffix;
    $templateCollisionMessageId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'remote_jid' => (string) $conversation['remote_jid'],
        'direction' => 'outgoing',
        'message_type' => 'template',
        'text_content' => 'Template preview',
        'status' => 'sent',
        'client_message_id' => $templateCollisionId,
        'dedupe_key' => hash('sha256', $templateCollisionId),
        'message_timestamp' => time(),
    ]);
    $templateCollision = null;
    try { $noteActions->add_note((int) $conversation['id'], 'Template preview', 7, $templateCollisionId); }
    catch (Throwable $exception) { $templateCollision = $exception; }
    $templateCollisionAfter = $messages->find_by_client_message_id((int) $conversation['id'], $templateCollisionId);
    $assert($templateCollision instanceof Chatwoot_plugin\Services\Message_send_exception
        && ($templateCollision->details()['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH'
        && (int) ($templateCollisionAfter['id'] ?? 0) === $templateCollisionMessageId, 'note endpoint rejects a same-preview collision with an existing template');

    $realNoteId = 'note-replay-' . $suffix;
    $realNote = $noteActions->add_note((int) $conversation['id'], 'Nota replayavel', 7, $realNoteId);
    $realNoteReplay = $noteActions->add_note((int) $conversation['id'], 'Nota replayavel', 7, $realNoteId);
    $assert(($realNoteReplay['idempotency_state'] ?? '') === 'idempotent_success'
        && (int) ($realNoteReplay['id'] ?? 0) === (int) ($realNote['id'] ?? 0), 'real internal note replay remains idempotent');

    $connectionResult = $chat->process_webhook_event([
        'event' => 'connection.update',
        'instance' => $instanceName,
        'data' => ['state' => 'open'],
    ]);
    $instance = $instances->get_by_id($instanceId);
    $assert(($connectionResult['status'] ?? '') === 'connected', 'connection event was not mapped');
    $assert(($instance['connection_status'] ?? '') === 'connected', 'instance status was not persisted');

    // Reaction state is confirmed only after the provider accepts the
    // attempt; V012 carries client idempotency and failure state separately.
    $reactionTarget = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $reactionTargetId = (int) ($reactionTarget['id'] ?? 0);
    $reactions = new Chatwoot_plugin\Models\Chat_message_reactions_model();
    $reactionAttempts = new Chatwoot_plugin\Models\Chat_message_reaction_attempts_model();
    $beforeReactionCalls = $fakeClient->reactionSendCalls;
    $reactionSent = $chat->send_reaction((int) $conversation['id'], $reactionTargetId, '👍', 'reaction-a-' . $suffix, 7);
    $assert($fakeClient->reactionSendCalls === $beforeReactionCalls + 1, 'Evolution reaction was sent exactly once');
    $assert(($reactionSent['reactions'][0]['reacted_by_me'] ?? false) === true, 'confirmed Evolution reaction updates self aggregate');
    $attempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-a-' . $suffix);
    $assert(($attempt['send_state'] ?? '') === 'sent' && ($attempt['requested_emoji'] ?? '') === '👍', 'V012 stores successful reaction payload and state');
    $replay = $chat->send_reaction((int) $conversation['id'], $reactionTargetId, '👍', 'reaction-a-' . $suffix, 7);
    $assert($fakeClient->reactionSendCalls === $beforeReactionCalls + 1, 'historical reaction replay made zero provider calls');
    $assert(($replay['reactions'][0]['reacted_by_me'] ?? false) === true, 'historical replay did not regress confirmed state');
    $instances->update_instance($instanceId, ['connection_status' => 'disconnected']);
    $disconnectedReplay = $chat->send_reaction((int) $conversation['id'], $reactionTargetId, "\u{1F44D}", 'reaction-a-' . $suffix, 7);
    $assert($fakeClient->reactionSendCalls === $beforeReactionCalls + 1, 'sent reaction replay bypasses disconnected volatile gates');
    $assert(($disconnectedReplay['reactions'][0]['reacted_by_me'] ?? false) === true, 'disconnected idempotent replay did not return confirmed state');
    $instances->update_instance($instanceId, ['connection_status' => 'connected']);
    $mismatch = false;
    try { $chat->send_reaction((int) $conversation['id'], $reactionTargetId, '❤️', 'reaction-a-' . $suffix, 7); } catch (Chatwoot_plugin\Services\Message_send_exception $exception) { $mismatch = ($exception->details()['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH'; }
    $assert($mismatch && $fakeClient->reactionSendCalls === $beforeReactionCalls + 1, 'reaction payload mismatch is rejected without a provider call');
    $fakeClient->reactionMode = 'failure';
    $fakeClient->reactionStatus = 422;
    $rejected = false;
    try { $chat->send_reaction((int) $conversation['id'], $reactionTargetId, '😂', 'reaction-rejected-' . $suffix, 7); } catch (Chatwoot_plugin\Services\Message_send_exception $exception) { $rejected = $exception->sendState() === 'rejected'; }
    $assert($rejected, 'permanent provider reaction validation failure is rejected');
    $rejectedAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-rejected-' . $suffix);
    $assert(($rejectedAttempt['send_state'] ?? '') === 'rejected', 'rejected reaction attempt is retained in V012');
    $assert($reactions->find_by_target_actor($reactionTargetId, 'self')['emoji'] === '👍', 'failed reaction did not change confirmed self state');
    $fakeClient->reactionMode = 'throw';
    $ambiguous = false;
    try { $chat->send_reaction((int) $conversation['id'], $reactionTargetId, '🙏', 'reaction-ambiguous-' . $suffix, 7); } catch (Chatwoot_plugin\Services\Message_send_exception $exception) { $ambiguous = $exception->sendState() === 'ambiguous_failure'; }
    $assert($ambiguous, 'reaction transport failure is classified as ambiguous');
    $assert(($reactionAttempts->find_by_client_message_id($instanceId, 'reaction-ambiguous-' . $suffix)['send_state'] ?? '') === 'ambiguous_failure', 'ambiguous reaction attempt remains retry-protected');
    $fakeClient->reactionMode = 'success';

    // A provider status belongs to the reaction attempt when its WAMID is
    // absent from chat_messages. A permanent Meta error is retained and the
    // confirmed state rolls back only when that attempt still owns it.
    $chat->send_reaction((int) $conversation['id'], $reactionTargetId, "\u{2764}\u{FE0F}", 'reaction-previous-' . $suffix, 7);
    $chat->send_reaction((int) $conversation['id'], $reactionTargetId, "\u{1F44D}", 'reaction-rollback-a-' . $suffix, 7);
    $rollbackAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-rollback-a-' . $suffix);
    $rollbackProviderId = (string) ($rollbackAttempt['provider_event_id'] ?? '');
    $rollbackStatus = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => $rollbackProviderId],
            'status' => 'ERROR',
            'timestamp' => time(),
            'error' => ['code' => '131009', 'message' => 'Reaction target expired'],
        ],
    ]);
    $rollbackAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-rollback-a-' . $suffix);
    $rollbackState = $reactions->find_by_target_actor($reactionTargetId, 'self');
    $assert(($rollbackStatus['kind'] ?? '') === 'reaction_status' && ($rollbackStatus['provider_error_code'] ?? '') === '131009', 'reaction status correlates by V012 provider event id and preserves Meta error code');
    $assert(($rollbackAttempt['send_state'] ?? '') === 'rejected' && ($rollbackAttempt['provider_error_code'] ?? '') === '131009', 'async permanent reaction failure is classified and stored');
    $assert(($rollbackState['emoji'] ?? '') === "\u{2764}\u{FE0F}", 'async reaction failure restores the known previous confirmed state');

    $chat->send_reaction((int) $conversation['id'], $reactionTargetId, "\u{1F44D}", 'reaction-order-a-' . $suffix, 7);
    $orderAAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-order-a-' . $suffix);
    $orderStateBeforeReceipt = $reactions->find_by_target_actor($reactionTargetId, 'self');
    $orderStateOrderBeforeReceipt = (string) ($orderStateBeforeReceipt['state_order_at'] ?? '');
    $orderStatus = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($orderAAttempt['provider_event_id'] ?? '')],
            'status' => 'DELIVERY_ACK',
            'timestamp' => time(),
        ],
    ]);
    $orderAAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-order-a-' . $suffix);
    $orderStateAfterReceipt = $reactions->find_by_target_actor($reactionTargetId, 'self');
    $assert(($orderStatus['kind'] ?? '') === 'reaction_status' && ($orderAAttempt['provider_status'] ?? '') === 'delivered' && ($orderAAttempt['send_state'] ?? '') === 'sent', 'successful reaction status keeps V012 attempt consistent');
    $assert(($orderStateAfterReceipt['state_order_at'] ?? '') === $orderStateOrderBeforeReceipt
        && (int) ($orderStateAfterReceipt['source_attempt_id'] ?? 0) === (int) ($orderStateBeforeReceipt['source_attempt_id'] ?? 0), 'delivered receipt updates V012 without advancing V011 reaction order');
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($orderAAttempt['provider_event_id'] ?? '')],
            'status' => 'SERVER_ACK',
            'timestamp' => time() - 120,
        ],
    ]);
    $orderAfterOutOfOrder = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-order-a-' . $suffix);
    $assert(($orderAfterOutOfOrder['provider_status'] ?? '') === 'delivered'
        && ($orderAfterOutOfOrder['provider_status_at'] ?? '') === ($orderAAttempt['provider_status_at'] ?? ''), 'out-of-order reaction receipt cannot erase V012 provider status time');
    $chat->send_reaction((int) $conversation['id'], $reactionTargetId, "\u{1F602}", 'reaction-order-b-' . $suffix, 7);
    $lateFailure = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($orderAAttempt['provider_event_id'] ?? '')],
            'status' => 'ERROR',
            'timestamp' => time() - 120,
            'error' => ['code' => '131009', 'message' => 'Late old failure'],
        ],
    ]);
    $lateState = $reactions->find_by_target_actor($reactionTargetId, 'self');
    $assert(($lateFailure['kind'] ?? '') === 'reaction_status' && ($lateState['emoji'] ?? '') === "\u{1F602}", 'late failure of an older attempt cannot roll back a later confirmed attempt');

    $rejectedReceiptId = 'REACTION-REJECTED-RECEIPT-' . $suffix;
    $reactionAttempts->update_state((int) ($rejectedAttempt['id'] ?? 0), 'rejected', $rejectedReceiptId);
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => ['key' => ['id' => $rejectedReceiptId], 'status' => 'DELIVERY_ACK', 'timestamp' => time()],
    ]);
    $rejectedAfterReceipt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-rejected-' . $suffix);
    $assert(($rejectedAfterReceipt['send_state'] ?? '') === 'rejected', 'late success receipt cannot promote a terminal rejected reaction');
    $ambiguousReceiptId = 'REACTION-AMBIGUOUS-RECEIPT-' . $suffix;
    $ambiguousAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-ambiguous-' . $suffix);
    $reactionAttempts->update_state((int) ($ambiguousAttempt['id'] ?? 0), 'ambiguous_failure', $ambiguousReceiptId);
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => ['key' => ['id' => $ambiguousReceiptId], 'status' => 'READ', 'timestamp' => time()],
    ]);
    $ambiguousAfterReceipt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-ambiguous-' . $suffix);
    $assert(($ambiguousAfterReceipt['send_state'] ?? '') === 'sent' && ($ambiguousAfterReceipt['provider_status'] ?? '') === 'read', 'delivered/read receipt reconciles an ambiguous reaction attempt as success');

    $chat->send_reaction((int) $conversation['id'], $reactionTargetId, "\u{1F44D}", 'reaction-order-outbound-' . $suffix, 7);
    $oldWebhook = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-ECHO-OLD-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time() - 300,
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true, 'id' => 'MSG-' . $suffix], 'text' => "\u{2764}\u{FE0F}"]],
        ],
    ]);
    $orderedReactionState = $reactions->find_by_target_actor($reactionTargetId, 'self');
    $assert(($oldWebhook['kind'] ?? '') === 'reaction' && ($orderedReactionState['emoji'] ?? '') === "\u{1F44D}", 'older provider reaction cannot regress a newer outbound confirmation');
    $assert((int) ($orderedReactionState['source_attempt_id'] ?? 0) > 0, 'provider reaction echo does not destroy outbound attempt identity');
    $echoSourceAttempt = (int) ($orderedReactionState['source_attempt_id'] ?? 0);
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-ECHO-SAME-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time() + 20,
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true, 'id' => 'MSG-' . $suffix], 'text' => "\u{1F44D}"]],
        ],
    ]);
    $echoState = $reactions->find_by_target_actor($reactionTargetId, 'self');
    $assert((int) ($echoState['source_attempt_id'] ?? 0) === $echoSourceAttempt, 'same-state provider echo preserves source attempt authority');

    $raceTargetId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => 'REACTION-RACE-TARGET-' . $suffix,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'text',
        'text_content' => 'Reaction race target',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'reaction-race-target-' . $suffix,
    ]);
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-RACE-INITIAL-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time() - 10,
            'message' => ['reactionMessage' => ['key' => ['id' => 'REACTION-RACE-TARGET-' . $suffix], 'text' => "\u{2764}\u{FE0F}"]],
        ],
    ]);
    $chat->send_reaction((int) $conversation['id'], $raceTargetId, "\u{1F44D}", 'reaction-race-a-' . $suffix, 7);
    $chat->send_reaction((int) $conversation['id'], $raceTargetId, "\u{1F602}", 'reaction-race-b-' . $suffix, 7);
    $raceA = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-race-a-' . $suffix);
    $raceB = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-race-b-' . $suffix);
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($raceB['provider_event_id'] ?? '')],
            'status' => 'ERROR',
            'timestamp' => time(),
            'error' => ['code' => '131009', 'message' => 'Second tab reaction rejected'],
        ],
    ]);
    $raceState = $reactions->find_by_target_actor($raceTargetId, 'self');
    $raceBAfter = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-race-b-' . $suffix);
    $assert(($raceState['emoji'] ?? '') === "\u{1F44D}" && (int) ($raceBAfter['previous_source_attempt_id'] ?? 0) === (int) ($raceA['id'] ?? 0), 'different client ids serialize the same self reaction and preserve previous source authority');

    // The reverse completion order is also serialized: B reaches the provider
    // first, A reaches it later, and a delayed failure for B cannot erase A.
    $reverseRaceTargetId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => 'REACTION-REVERSE-RACE-TARGET-' . $suffix,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'text',
        'text_content' => 'Reverse reaction race target',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'reaction-reverse-race-target-' . $suffix,
    ]);
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-REVERSE-RACE-INITIAL-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time() - 10,
            'message' => ['reactionMessage' => ['key' => ['id' => 'REACTION-REVERSE-RACE-TARGET-' . $suffix], 'text' => "\u{2764}\u{FE0F}"]],
        ],
    ]);
    $chat->send_reaction((int) $conversation['id'], $reverseRaceTargetId, "\u{1F602}", 'reaction-reverse-race-b-' . $suffix, 7);
    $chat->send_reaction((int) $conversation['id'], $reverseRaceTargetId, "\u{1F44D}", 'reaction-reverse-race-a-' . $suffix, 7);
    $reverseRaceA = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-reverse-race-a-' . $suffix);
    $reverseRaceB = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-reverse-race-b-' . $suffix);
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($reverseRaceB['provider_event_id'] ?? '')],
            'status' => 'ERROR',
            'timestamp' => time(),
            'error' => ['code' => '131009', 'message' => 'First provider reaction rejected asynchronously'],
        ],
    ]);
    $reverseRaceState = $reactions->find_by_target_actor($reverseRaceTargetId, 'self');
    $assert(($reverseRaceState['emoji'] ?? '') === "\u{1F44D}" && (int) ($reverseRaceA['previous_source_attempt_id'] ?? 0) === (int) ($reverseRaceB['id'] ?? 0), 'reverse provider completion order preserves the later serialized reaction after B failure');

    // Rollback is ordered at the original outbound operation, not at failure
    // discovery time. A provider event after the operation but delivered after
    // the rollback must win; an older event must remain stale.
    $rollbackOrderTargetId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => 'REACTION-ROLLBACK-ORDER-TARGET-' . $suffix,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'text',
        'text_content' => 'Rollback ordering target',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'reaction-rollback-order-target-' . $suffix,
    ]);
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-ROLLBACK-ORDER-INITIAL-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time() - 120,
            'message' => ['reactionMessage' => ['key' => ['id' => 'REACTION-ROLLBACK-ORDER-TARGET-' . $suffix], 'text' => "\u{2764}\u{FE0F}"]],
        ],
    ]);
    $chat->send_reaction((int) $conversation['id'], $rollbackOrderTargetId, "\u{1F44D}", 'reaction-rollback-order-a-' . $suffix, 7);
    $rollbackOrderAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-rollback-order-a-' . $suffix);
    $operationUnix = (new DateTimeImmutable((string) ($rollbackOrderAttempt['created_at'] ?? ''), new DateTimeZone('UTC')))->getTimestamp();
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($rollbackOrderAttempt['provider_event_id'] ?? '')],
            'status' => 'ERROR',
            'timestamp' => $operationUnix + 120,
            'error' => ['code' => '131009', 'message' => 'Failure discovered after the operation'],
        ],
    ]);
    $rollbackLateResult = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-ROLLBACK-ORDER-LATE-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => $operationUnix + 60,
            'message' => ['reactionMessage' => ['key' => ['id' => 'REACTION-ROLLBACK-ORDER-TARGET-' . $suffix], 'text' => "\u{1F602}"]],
        ],
    ]);
    $rollbackOrderState = $reactions->find_by_target_actor($rollbackOrderTargetId, 'self');
    $assert(($rollbackLateResult['kind'] ?? '') === 'reaction' && ($rollbackOrderState['emoji'] ?? '') === "\u{1F602}", 'provider event after the outbound operation wins a delayed rollback');
    $rollbackOldResult = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-ROLLBACK-ORDER-OLD-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => $operationUnix - 60,
            'message' => ['reactionMessage' => ['key' => ['id' => 'REACTION-ROLLBACK-ORDER-TARGET-' . $suffix], 'text' => "\u{2764}\u{FE0F}"]],
        ],
    ]);
    $rollbackOrderAfterOld = $reactions->find_by_target_actor($rollbackOrderTargetId, 'self');
    $assert(($rollbackOldResult['kind'] ?? '') === 'reaction' && ($rollbackOrderAfterOld['emoji'] ?? '') === "\u{1F602}", 'provider event older than rollback cannot restore the previous reaction');

    $receiptTargetId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => 'REACTION-RECEIPT-TARGET-' . $suffix,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'text',
        'text_content' => 'Reaction receipt target',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'reaction-receipt-target-' . $suffix,
    ]);
    $chat->send_reaction((int) $conversation['id'], $receiptTargetId, "\u{1F44D}", 'reaction-receipt-a-' . $suffix, 7);
    $receiptAttempt = $reactionAttempts->find_by_client_message_id($instanceId, 'reaction-receipt-a-' . $suffix);
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-RECEIPT-SELF-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => true],
            'messageTimestamp' => time() + 7200 + 10,
            'message' => ['reactionMessage' => ['key' => ['id' => 'REACTION-RECEIPT-TARGET-' . $suffix], 'text' => "\u{2764}\u{FE0F}"]],
        ],
    ]);
    $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => (string) ($receiptAttempt['provider_event_id'] ?? '')],
            'status' => 'DELIVERY_ACK',
            'timestamp' => time() + 7200 + 20,
        ],
    ]);
    $receiptState = $reactions->find_by_target_actor($receiptTargetId, 'self');
    $assert(($receiptState['emoji'] ?? '') === "\u{2764}\u{FE0F}", 'a newer self provider reaction remains authoritative over a later delivery receipt');

    $reactionService = new Chatwoot_plugin\Services\Message_reaction_service();
    foreach (["\u{1F44D}", "\u{2764}\u{FE0F}", "\u{1F44D}\u{1F3FD}", "\u{1F1E7}\u{1F1F7}"] as $validEmoji) {
        $assert($reactionService->validateEmoji($validEmoji) === $validEmoji, 'one-grapheme emoji remains valid: ' . bin2hex($validEmoji));
    }
    foreach (["\u{1F44D}\u{2764}\u{FE0F}", 'hello'] as $invalidEmoji) {
        $invalid = false;
        try { $reactionService->validateEmoji($invalidEmoji); } catch (InvalidArgumentException $exception) { $invalid = true; }
        $assert($invalid, 'invalid multi-grapheme/arbitrary reaction is rejected: ' . bin2hex($invalidEmoji));
    }
    $explicitRemove = $reactionService->normalizeRequest('', true);
    $assert($explicitRemove === ['emoji' => '', 'active' => false], 'empty reaction is accepted only through explicit remove semantics');

    $unknownTargetPayload = [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-BEFORE-TARGET-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
            'messageTimestamp' => time(),
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false, 'id' => 'TARGET-LATER-' . $suffix], 'text' => "\u{1F44D}"]],
        ],
    ];
    $unknownPending = $chat->process_webhook_event($unknownTargetPayload);
    $assert(empty($unknownPending['processed']) && !empty($unknownPending['pending']), 'reaction before its target remains pending and retryable');
    $pendingReactionLogs = $db->table('chat_webhook_logs')->where('error_message', 'reaction_target_pending')->where('success', 0)->where('processed_at IS NULL', null, false)->countAllResults();
    $assert($pendingReactionLogs > 0, 'unresolved reaction is not recorded as a definitive webhook success');
    $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => 'TARGET-LATER-' . $suffix,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'text',
        'text_content' => 'Target persisted after reaction',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'target-later-' . $suffix,
    ]);
    $unknownRetried = $chat->process_webhook_event($unknownTargetPayload);
    $unknownDuplicate = $chat->process_webhook_event($unknownTargetPayload);
    $unknownTarget = $messages->find_by_external_id($instanceId, 'TARGET-LATER-' . $suffix);
    $unknownReaction = $reactions->find_by_target_actor((int) ($unknownTarget['id'] ?? 0), '5511888888888@s.whatsapp.net');
    $assert(!empty($unknownRetried['processed']) && !empty($unknownRetried['resolved']), 'pending reaction is applied after its target is persisted');
    $assert(!empty($unknownDuplicate['duplicate']) && ($unknownReaction['emoji'] ?? '') === "\u{1F44D}", 'reconciled reaction is applied exactly once');

    $incomingReaction = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-EVENT-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
            'messageTimestamp' => time(),
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-' . $suffix], 'text' => '❤️']],
        ],
    ]);
    $assert(($incomingReaction['kind'] ?? '') === 'reaction' && !empty($incomingReaction['resolved']), 'incoming reaction updates its target without creating a bubble');
    $reactionConversationBefore = $conversations->get_by_id((int) $conversation['id']);
    $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-EVENT-OLD-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
            'messageTimestamp' => time() - 120,
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-' . $suffix], 'text' => '😂']],
        ],
    ]);
    $contactReaction = $reactions->find_by_target_actor($reactionTargetId, '5511888888888@s.whatsapp.net');
    $assert(($contactReaction['emoji'] ?? '') === '❤️', 'older reaction event cannot regress confirmed actor state');
    $removedReaction = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'REACTION-EVENT-REMOVE-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
            'messageTimestamp' => time() + 1,
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-' . $suffix], 'text' => '']],
        ],
    ]);
    $contactReaction = $reactions->find_by_target_actor($reactionTargetId, '5511888888888@s.whatsapp.net');
    $reactionConversationAfter = $conversations->get_by_id((int) $conversation['id']);
    $assert(($removedReaction['kind'] ?? '') === 'reaction' && empty($contactReaction['active']), 'newer empty reaction removes the confirmed actor state');
    $assert(($reactionConversationBefore['last_message_preview'] ?? '') === ($reactionConversationAfter['last_message_preview'] ?? '') && (int) ($reactionConversationBefore['unread_count'] ?? 0) === (int) ($reactionConversationAfter['unread_count'] ?? 0), 'reaction add/change/remove has no chat preview or unread side effects');
    $assert((int) ($conversation['unread_count'] ?? 0) === (int) ($conversations->get_by_id((int) $conversation['id'])['unread_count'] ?? 0), 'reaction webhook does not increment unread state');
    $oldReactionId = $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => 'LEGACY-REACTION-' . $suffix,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'reaction',
        'text_content' => '❤️',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'legacy-reaction-' . $suffix,
    ]);
    $reactionBaseline = $chat->get_messages((int) $conversation['id'], 100);
    $historyIds = array_column($reactionBaseline['data'], 'id');
    $assert(!in_array($oldReactionId, $historyIds, true), 'legacy reaction rows are excluded from visible pagination');
    $assert(empty($reactionBaseline['meta']['reaction_updates']), 'reset reaction cursor does not return historical targets');
    $baselineCursor = (int) ($reactionBaseline['meta']['reaction_cursor'] ?? 0);
    foreach ([['emoji' => "\u{2764}\u{FE0F}", 'offset' => 10], ['emoji' => "\u{1F602}", 'offset' => 11]] as $rapidReaction) {
        $chat->process_webhook_event([
            'event' => 'messages.upsert',
            'instance' => $instanceName,
            'data' => [
                'key' => ['id' => 'REACTION-CURSOR-' . bin2hex(random_bytes(3)), 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
                'messageTimestamp' => time() + $rapidReaction['offset'],
                'message' => ['reactionMessage' => ['key' => ['remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-' . $suffix], 'text' => $rapidReaction['emoji']]],
            ],
        ]);
    }
    $reactionPoll = $chat->get_messages((int) $conversation['id'], 100, null, null, false, null, $baselineCursor);
    $assert(count($reactionPoll['meta']['reaction_updates'] ?? []) === 1, 'reaction cursor returns a changed target once after rapid updates');
    $assert(($reactionPoll['meta']['reaction_updates'][0]['reactions'][0]['emoji'] ?? '') === "\u{1F602}", 'rapid reaction updates reconcile the final aggregate');
    $reactionPollAgain = $chat->get_messages((int) $conversation['id'], 100, null, null, false, null, (int) ($reactionPoll['meta']['reaction_cursor'] ?? 0));
    $assert(empty($reactionPollAgain['meta']['reaction_updates']), 'reaction cursor does not repeat an already consumed update');

    $cursorReactionService = new Chat_service_test_cursor_reaction_service($db, $messages);
    $cursorReactionService->injectionMessageId = $reactionTargetId;
    $cursorChat = new Chatwoot_plugin\Services\Chat_service(
        null,
        $conversations,
        $messages,
        null,
        null,
        null,
        null,
        $db,
        null,
        null,
        null,
        null,
        $cursorReactionService
    );
    $cursorReset = $cursorChat->get_messages((int) $conversation['id'], 100);
    $cursorBaseline = (int) ($cursorReset['meta']['reaction_cursor'] ?? 0);
    $cursorNext = $cursorChat->get_messages((int) $conversation['id'], 100, null, null, false, null, $cursorBaseline);
    $assert(($cursorReactionService->calls[0] ?? '') === 'cursor' && ($cursorReactionService->calls[1] ?? '') === 'aggregate', 'reaction reset captures baseline before aggregate loading');
    $assert($cursorReactionService->injectedChangeId > $cursorBaseline
        && in_array($reactionTargetId, array_column($cursorNext['meta']['reaction_updates'] ?? [], 'id'), true), 'a reaction change interleaved during aggregate is delivered after the captured baseline');
    if ($cursorReactionService->injectedChangeId > 0) {
        $db->table($db->prefixTable('chat_message_reaction_changes'))->where('id', $cursorReactionService->injectedChangeId)->delete();
    }

    $history = $chat->get_messages((int) $conversation['id'], 50, null, null, true);
    $historyExternalIds = array_column($history['data'], 'external_message_id');
    $assert(in_array('HISTORY-TEST-1', $historyExternalIds, true), 'Evolution history was not normalized and persisted');
    $assert(($history['meta']['sync_error'] ?? null) === null, 'history sync reported an unexpected error');
    $assert($fakeClient->lastMessageOptions === ['page' => 1, 'offset' => 50], 'history synchronization was not page-bounded');

    $jobs = new Chatwoot_plugin\Services\Integration_job_service();
    $jobTable = $db->prefixTable('chat_integration_jobs');
    $webhookTable = $db->prefixTable('chat_webhook_logs');
    $permanentTargetExternalId = 'RETRY-PERMANENT-TARGET-' . $suffix;
    $permanentWebhook = [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'RETRY-PERMANENT-EVENT-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
            'messageTimestamp' => time(),
            'message' => ['reactionMessage' => ['key' => ['id' => $permanentTargetExternalId, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false], 'text' => "\u{1F44D}"]],
        ],
    ];
    $permanentPending = $chat->process_webhook_event($permanentWebhook);
    $permanentLog = $db->table($webhookTable)->like('payload', $permanentTargetExternalId)->orderBy('id', 'DESC')->get(1)->getRowArray();
    $permanentCorrelation = 'webhook-log-' . (int) ($permanentLog['id'] ?? 0);
    $jobCorrelationIds[] = $permanentCorrelation;
    for ($workerAttempt = 1; $workerAttempt <= 5; $workerAttempt++) {
        $jobs->run('reaction-retry-test', 50);
        $job = $db->table($jobTable)->where('correlation_id', $permanentCorrelation)->orderBy('id', 'DESC')->get(1)->getRowArray();
        if ($workerAttempt < 5 && $job) {
            $db->table($jobTable)->where('id', (int) $job['id'])->update(['available_at' => gmdate('Y-m-d H:i:s')]);
        }
    }
    $permanentJob = $db->table($jobTable)->where('correlation_id', $permanentCorrelation)->orderBy('id', 'DESC')->get(1)->getRowArray();
    $permanentLogAfter = $db->table($webhookTable)->where('id', (int) ($permanentLog['id'] ?? 0))->get(1)->getRowArray();
    $permanentJobCount = $db->table($jobTable)->where('correlation_id', $permanentCorrelation)->where('deleted', 0)->countAllResults();
    $jobs->run('reaction-retry-test-after-terminal', 50);
    $permanentJobCountAfter = $db->table($jobTable)->where('correlation_id', $permanentCorrelation)->where('deleted', 0)->countAllResults();
    $permanentResponse = json_decode((string) ($permanentLogAfter['response_payload'] ?? ''), true);
    $assert(!empty($permanentPending['pending']) && ($permanentJob['status'] ?? '') === 'failed' && $permanentJobCount === 1 && $permanentJobCountAfter === 1, 'permanently unresolved webhook retry reaches one terminal failed job without infinite rescheduling (status=' . (string) ($permanentJob['status'] ?? '') . ', count=' . $permanentJobCount . '/' . $permanentJobCountAfter . ', log=' . (int) ($permanentLog['id'] ?? 0) . ')');
    $assert(($permanentLogAfter['error_message'] ?? '') === 'retry_exhausted' && !empty($permanentLogAfter['processed_at']) && !empty($permanentResponse['retry_exhausted']), 'webhook retry exhaustion remains persistently observable on the webhook log (error=' . (string) ($permanentLogAfter['error_message'] ?? '') . ', processed=' . (string) ($permanentLogAfter['processed_at'] ?? '') . ')');
    $terminalProcessedAt = (string) ($permanentLogAfter['processed_at'] ?? '');
    $terminalRedeliveryStable = true;
    for ($redelivery = 1; $redelivery <= 3; $redelivery++) {
        $terminalRedelivery = $chat->process_webhook_event($permanentWebhook);
        $terminalRedeliveryStable = $terminalRedeliveryStable
            && !empty($terminalRedelivery['duplicate'])
            && !empty($terminalRedelivery['terminal'])
            && !empty($terminalRedelivery['retry_exhausted'])
            && empty($terminalRedelivery['pending']);
        $jobs->run('reaction-retry-terminal-redelivery-' . $redelivery, 50);
    }
    $permanentLogAfterRedelivery = $db->table($webhookTable)->where('id', (int) ($permanentLog['id'] ?? 0))->get(1)->getRowArray();
    $permanentJobCountAfterRedelivery = $db->table($jobTable)->where('correlation_id', $permanentCorrelation)->where('deleted', 0)->countAllResults();
    $assert($terminalRedeliveryStable && ($permanentLogAfterRedelivery['processed_at'] ?? '') === $terminalProcessedAt && $permanentJobCountAfterRedelivery === 1, 'terminal webhook redelivery remains idempotent without clearing processed_at or scheduling another retry');

    $appearingTargetExternalId = 'RETRY-APPEARING-TARGET-' . $suffix;
    $appearingWebhook = [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'RETRY-APPEARING-EVENT-' . $suffix, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false],
            'messageTimestamp' => time(),
            'message' => ['reactionMessage' => ['key' => ['id' => $appearingTargetExternalId, 'remoteJid' => '5511888888888@s.whatsapp.net', 'fromMe' => false], 'text' => "\u{1F44D}"]],
        ],
    ];
    $appearingPending = $chat->process_webhook_event($appearingWebhook);
    $appearingLog = $db->table($webhookTable)->like('payload', $appearingTargetExternalId)->orderBy('id', 'DESC')->get(1)->getRowArray();
    $appearingCorrelation = 'webhook-log-' . (int) ($appearingLog['id'] ?? 0);
    $jobCorrelationIds[] = $appearingCorrelation;
    $jobs->run('reaction-retry-appearing-test', 50);
    $appearingJob = $db->table($jobTable)->where('correlation_id', $appearingCorrelation)->orderBy('id', 'DESC')->get(1)->getRowArray();
    $messages->upsert_message((int) $conversation['id'], $instanceId, [
        'external_message_id' => $appearingTargetExternalId,
        'remote_jid' => '5511888888888@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => 'text',
        'text_content' => 'Target arrived before retry limit',
        'status' => 'received',
        'message_timestamp' => time(),
        'dedupe_key' => 'retry-appearing-target-' . $suffix,
    ]);
    if ($appearingJob) {
        $db->table($jobTable)->where('id', (int) $appearingJob['id'])->update(['available_at' => gmdate('Y-m-d H:i:s')]);
    }
    $jobs->run('reaction-retry-appearing-test', 50);
    $appearingJobAfter = $db->table($jobTable)->where('correlation_id', $appearingCorrelation)->orderBy('id', 'DESC')->get(1)->getRowArray();
    $appearingLogAfter = $db->table($webhookTable)->where('id', (int) ($appearingLog['id'] ?? 0))->get(1)->getRowArray();
    $appearingTarget = $messages->find_by_external_id($instanceId, $appearingTargetExternalId);
    $appearingReaction = $reactions->find_by_target_actor((int) ($appearingTarget['id'] ?? 0), '5511888888888@s.whatsapp.net');
    $appearingJobCount = $db->table($jobTable)->where('correlation_id', $appearingCorrelation)->where('deleted', 0)->countAllResults();
    $assert(!empty($appearingPending['pending']) && ($appearingJobAfter['status'] ?? '') === 'completed' && !empty($appearingLogAfter['success']) && ($appearingReaction['emoji'] ?? '') === "\u{1F44D}" && $appearingJobCount === 1, 'webhook retry applies exactly once when the target appears before exhaustion (status=' . (string) ($appearingJobAfter['status'] ?? '') . ', success=' . (string) ($appearingLogAfter['success'] ?? '') . ', count=' . $appearingJobCount . ')');
    $instances->update_instance($instanceId, ['connection_status' => 'connected']);

    $orderedRemoteJid = '551177770001@s.whatsapp.net';
    $orderedBase = time() - 600;
    foreach ([
        ['id' => 'ORDER-NEW-' . $suffix, 'timestamp' => $orderedBase + 60, 'text' => 'Preview mais nova'],
        ['id' => 'ORDER-OLD-' . $suffix, 'timestamp' => $orderedBase, 'text' => 'Preview antiga'],
    ] as $orderedMessage) {
        $orderedResult = $chat->process_webhook_event([
            'event' => 'messages.upsert',
            'instance' => $instanceName,
            'data' => [
                'key' => [
                    'id' => $orderedMessage['id'],
                    'remoteJid' => $orderedRemoteJid,
                    'fromMe' => false,
                ],
                'messageTimestamp' => $orderedMessage['timestamp'],
                'message' => ['conversation' => $orderedMessage['text']],
            ],
        ]);
        $assert(!empty($orderedResult['processed']), 'out-of-order webhook was not processed');
    }
    $orderedConversation = $conversations->get_by_remote_jid($instanceId, $orderedRemoteJid);
    $assert(($orderedConversation['last_message_preview'] ?? '') === 'Preview mais nova', 'older webhook regressed the conversation preview');
    $assert(($orderedConversation['last_message_at'] ?? '') === gmdate('Y-m-d H:i:s', $orderedBase + 60), 'older webhook regressed last_message_at');

    $rollbackRemoteJid = '551177770002@s.whatsapp.net';
    $messages->throwForRemoteJid = $rollbackRemoteJid;
    $rollbackPayload = [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'id' => 'ROLLBACK-' . $suffix,
                'remoteJid' => $rollbackRemoteJid,
                'fromMe' => false,
            ],
            'messageTimestamp' => time(),
            'message' => ['conversation' => 'Nao deve deixar conversa orfa'],
        ],
    ];
    $rollbackResult = $chat->process_webhook_event($rollbackPayload);
    $assert(empty($rollbackResult['processed']) && !empty($rollbackResult['pending']), 'transaction failure was not retryable');
    $assert($conversations->get_by_remote_jid($instanceId, $rollbackRemoteJid) === null, 'message failure left a partial conversation transaction');
    $assert((int) $db->transDepth === 0, 'failed persistence left a database transaction open');
    $assert($db->transStatus() === true, 'failed persistence left the transaction status poisoned');
    $rollbackRetry = $chat->process_webhook_event($rollbackPayload);
    $assert(!empty($rollbackRetry['processed']), 'rolled-back webhook could not be retried');
    $assert(is_array($conversations->get_by_remote_jid($instanceId, $rollbackRemoteJid)), 'retried rollback webhook did not persist');

    $inactiveRemoteJid = '551177770003@s.whatsapp.net';
    $instances->update_instance($instanceId, ['active' => 0]);
    $inactiveResult = $chat->process_webhook_event([
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'id' => 'INACTIVE-' . $suffix,
                'remoteJid' => $inactiveRemoteJid,
                'fromMe' => false,
            ],
            'messageTimestamp' => time(),
            'message' => ['conversation' => 'Integracao desativada'],
        ],
    ]);
    $assert(!empty($inactiveResult['processed']) && ($inactiveResult['reason'] ?? '') === 'instance_inactive', 'inactive instance webhook was not controlled');
    $assert($conversations->get_by_remote_jid($instanceId, $inactiveRemoteJid) === null, 'inactive instance created a conversation');
    $instances->update_instance($instanceId, ['active' => 1]);

    $contendedExternalId = 'CONTENDED-' . $suffix;
    $contendedRemoteJid = '551177770004@s.whatsapp.net';
    $contendedPayload = [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'id' => $contendedExternalId,
                'remoteJid' => $contendedRemoteJid,
                'fromMe' => false,
            ],
            'messageTimestamp' => time(),
            'message' => ['conversation' => 'Evento sob contencao'],
        ],
    ];
    $contendedLock = 'chat_webhook_' . substr(hash(
        'sha256',
        'message|' . $instanceId . '|' . $contendedExternalId
    ), 0, 40);
    $lockDb = db_connect('default', false);
    $lockHeld = false;
    try {
        $lockRow = $lockDb->query('SELECT GET_LOCK(?, 0) AS acquired_lock', [$contendedLock])->getRowArray();
        $lockHeld = (int) ($lockRow['acquired_lock'] ?? 0) === 1;
        $assert($lockHeld, 'test could not acquire the contention lock');
        $contendedResult = $chat->process_webhook_event($contendedPayload);
        $assert(empty($contendedResult['processed']) && !empty($contendedResult['pending']), 'lock contention was not returned as pending');
        $assert((int) ($contendedResult['http_status'] ?? 0) === 202, 'lock contention did not expose retryable status');
    } finally {
        if ($lockHeld) {
            $lockDb->query('SELECT RELEASE_LOCK(?)', [$contendedLock]);
        }
        $lockDb->close();
    }
    $contendedRetry = $chat->process_webhook_event($contendedPayload);
    $assert(!empty($contendedRetry['processed']), 'pending lock event was not reprocessable');
    $assert(is_array($messages->find_by_external_id($instanceId, $contendedExternalId)), 'retried lock event did not persist its message');

    $cursorBase = time() + 3600;
    $cursorIds = [];
    foreach ([100, 300, 200] as $offset) {
        $externalId = 'CURSOR-' . $offset . '-' . $suffix;
        $cursorIds[$offset] = $messages->upsert_message((int) $conversation['id'], $instanceId, [
            'external_message_id' => $externalId,
            'remote_jid' => (string) $conversation['remote_jid'],
            'direction' => 'incoming',
            'message_type' => 'text',
            'text_content' => 'Cursor ' . $offset,
            'status' => 'received',
            'sent_at' => gmdate('Y-m-d H:i:s', $cursorBase + $offset),
            'message_timestamp' => $cursorBase + $offset,
            'dedupe_key' => hash('sha256', $externalId),
        ]);
    }
    $cursorPage = $chat->get_messages((int) $conversation['id'], 2);
    $assert(!empty($cursorPage['meta']['has_more_before']), 'timestamp cursor did not report older messages');
    $olderCursorPage = $chat->get_messages(
        (int) $conversation['id'],
        20,
        (int) $cursorIds[200],
        null,
        false,
        $cursorBase + 200
    );
    $olderCursorExternalIds = array_column($olderCursorPage['data'], 'external_message_id');
    $assert(in_array('CURSOR-100-' . $suffix, $olderCursorExternalIds, true), 'timestamp cursor skipped an older out-of-order message');
    $assert(!in_array('CURSOR-300-' . $suffix, $olderCursorExternalIds, true), 'timestamp cursor included a newer message');

    $afterCursorPage = $chat->get_messages(
        (int) $conversation['id'],
        20,
        null,
        (int) $cursorIds[100]
    );
    $afterCursorExternalIds = array_values(array_filter(
        array_column($afterCursorPage['data'], 'external_message_id'),
        static fn ($externalId): bool => is_string($externalId) && str_starts_with($externalId, 'CURSOR-')
    ));
    $assert($afterCursorExternalIds === [
        'CURSOR-200-' . $suffix,
        'CURSOR-300-' . $suffix,
    ], 'local polling cursor did not return late inserts in provider chronological order');

    $sent = $chat->send_text((int) $conversation['id'], 'Resposta simulada', 'client-' . $suffix);
    $sentAgain = $chat->send_text((int) $conversation['id'], 'Resposta simulada', 'client-' . $suffix);
    $assert(($sent['external_message_id'] ?? '') === 'SIMULATED-SEND-1', 'send response external id was not reconciled');
    $assert(($sent['status'] ?? '') === 'sent', 'sent message status differs');
    $assert((int) ($sentAgain['id'] ?? 0) === (int) ($sent['id'] ?? 0), 'idempotent send returned another row');
    $assert($fakeClient->sendCalls === 1, 'idempotent send called Evolution twice');
    $textRetryId = 'text-retryable-' . $suffix;
    $fakeClient->textFailureAfter = $fakeClient->sendCalls;
    $fakeClient->textFailureStatus = 429;
    $textRetryFailure = false;
    try {
        $chat->send_text((int) $conversation['id'], 'Texto original retryable', $textRetryId);
    } catch (Throwable $exception) {
        $textRetryFailure = $exception instanceof Chatwoot_plugin\Services\Message_send_exception
            && $exception->details()['send_state'] === 'retryable_failure';
    }
    $textRetryRowBeforeMismatch = $messages->find_by_client_message_id((int) $conversation['id'], $textRetryId);
    $textRetryCallsBeforeMismatch = $fakeClient->sendCalls;
    $textRetryMismatch = false;
    try {
        $chat->send_text((int) $conversation['id'], 'Texto alterado retryable', $textRetryId);
    } catch (Throwable $exception) {
        $textRetryMismatch = $exception instanceof Chatwoot_plugin\Services\Message_send_exception
            && $exception->getCode() === 409
            && (($exception->details()['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }
    $textRetryRowAfterMismatch = $messages->find_by_client_message_id((int) $conversation['id'], $textRetryId);
    $assert($textRetryFailure, 'text retryable failure is persisted as retryable');
    $assert($textRetryMismatch, 'retryable text id rejects a changed payload');
    $assert($fakeClient->sendCalls === $textRetryCallsBeforeMismatch, 'changed retryable text made zero provider calls (' . $textRetryCallsBeforeMismatch . ' -> ' . $fakeClient->sendCalls . '; stored=' . (string) ($textRetryRowBeforeMismatch['text_content'] ?? '') . ')');
    $assert(($textRetryRowBeforeMismatch['text_content'] ?? '') === ($textRetryRowAfterMismatch['text_content'] ?? ''), 'changed retryable text did not update its existing row');
    $fakeClient->textFailureAfter = PHP_INT_MAX;
    $fakeClient->textFailureStatus = null;
    $textCallsBeforePayloadMismatch = $fakeClient->sendCalls;
    $textRowBeforeMismatch = $messages->find_by_client_message_id((int) $conversation['id'], 'client-' . $suffix);
    $textMismatch = false;
    try {
        $chat->send_text((int) $conversation['id'], 'Texto alterado no retry', 'client-' . $suffix);
    } catch (Throwable $exception) {
        $textMismatch = $exception instanceof Chatwoot_plugin\Services\Message_send_exception
            && $exception->getCode() === 409
            && (($exception->details()['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }
    $textRowAfterMismatch = $messages->find_by_client_message_id((int) $conversation['id'], 'client-' . $suffix);
    $assert($textMismatch, 'text idempotency rejects a changed payload with structured mismatch');
    $assert($fakeClient->sendCalls === $textCallsBeforePayloadMismatch, 'changed text retry made zero provider calls');
    $assert(($textRowBeforeMismatch['text_content'] ?? '') === ($textRowAfterMismatch['text_content'] ?? '')
        && ($textRowBeforeMismatch['status'] ?? '') === ($textRowAfterMismatch['status'] ?? ''), 'changed text retry did not update the existing row');

    $mediaProviders = new Chatwoot_plugin\Services\Provider_manager(
        $instances,
        new Chatwoot_plugin\Models\Chat_settings_model(),
        static fn (array $instance, $settings): Chatwoot_plugin\Libraries\Evolution_client => $fakeClient
    );
    $mediaService = new Chatwoot_plugin\Services\Media_service(null, null, null, null, null, null, $mediaProviders);
    $fixture = static function (string $contents, string $name, string $mime): CodeIgniter\HTTP\Files\UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'rise-media-integration-');
        if ($path === false || file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('media fixture could not be created');
        }
        return new Media_engine_test_uploaded_file($path, $name, $mime, strlen($contents), UPLOAD_ERR_OK);
    };
    $pngBytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    $pdfBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n";
    $fakeClient->mediaSendCalls = 0;
    $fakeClient->mediaFailureAfter = 1;
    $mediaBatch = $mediaService->sendBatch(
        (int) $conversation['id'],
        [$fixture($pngBytes, 'one.png', 'image/png'), $fixture($pdfBytes, 'two.pdf', 'application/pdf')],
        [
            ['client_message_id' => 'media-batch-' . $suffix . '-0', 'kind' => 'image'],
            ['client_message_id' => 'media-batch-' . $suffix . '-1', 'kind' => 'document'],
        ],
        1,
        'media-batch-' . $suffix
    );
    $assert(($mediaBatch['items'][0]['status'] ?? '') === 'sent', 'media batch preserves the first successful item');
    $assert(($mediaBatch['items'][1]['status'] ?? '') === 'failed', 'media batch represents provider failure on the second item');
    $assert($fakeClient->mediaSendCalls === 2, 'media batch calls the provider once per ordered item');
    $failedRetry = $mediaService->send(
        (int) $conversation['id'],
        $fixture($pdfBytes, 'retry-failed.pdf', 'application/pdf'),
        '',
        'media-batch-' . $suffix . '-1',
        1,
        'document'
    );
    $assert(($failedRetry['status'] ?? '') === 'failed', 'a failed media identity remains a deterministic terminal retry result');
    $assert($fakeClient->mediaSendCalls === 2, 'retrying a failed media identity cannot duplicate the provider call');
    $retry = $mediaService->send(
        (int) $conversation['id'],
        $fixture($pngBytes, 'retry.png', 'image/png'),
        '',
        'media-batch-' . $suffix . '-0',
        1,
        'image'
    );
    $assert(($retry['client_message_id'] ?? '') === 'media-batch-' . $suffix . '-0', 'media retry with the same client id returns the existing message');
    $assert($fakeClient->mediaSendCalls === 2, 'media idempotency prevents a duplicate external send');
    $successfulMediaRow = $messages->find_by_client_message_id((int) $conversation['id'], 'media-batch-' . $suffix . '-0');
    $successfulMediaRaw = json_decode((string) ($successfulMediaRow['raw_payload'] ?? ''), true);
    $assert(isset($successfulMediaRaw['media_engine']['source_sha256'], $successfulMediaRaw['media_engine']['source_size'], $successfulMediaRaw['media_engine']['source_detected_mime']), 'media success persists immutable source identity');
    $alteredPngBytes = $pngBytes . 'different-source';
    $mediaRowBeforeMismatch = $successfulMediaRow;
    $mediaMismatch = false;
    try {
        $mediaService->send((int) $conversation['id'], $fixture($alteredPngBytes, 'changed.png', 'image/png'), '', 'media-batch-' . $suffix . '-0', 1, 'image');
    } catch (Throwable $exception) {
        $mediaMismatch = $exception instanceof Chatwoot_plugin\Services\Media_engine_exception
            && $exception->getCode() === 409
            && (($exception->details()['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }
    $mediaRowAfterMismatch = $messages->find_by_client_message_id((int) $conversation['id'], 'media-batch-' . $suffix . '-0');
    $assert($mediaMismatch, 'single media retry rejects a changed binary with structured mismatch');
    $assert($fakeClient->mediaSendCalls === 2, 'changed single media retry made zero provider calls');
    $assert(($mediaRowBeforeMismatch['raw_payload'] ?? '') === ($mediaRowAfterMismatch['raw_payload'] ?? '')
        && ($mediaRowBeforeMismatch['media_id'] ?? '') === ($mediaRowAfterMismatch['media_id'] ?? ''), 'changed single media retry did not update storage metadata or message row');
    $beforeBatchIdentityMismatch = $fakeClient->mediaSendCalls;
    $identityMismatchBatch = $mediaService->sendBatch(
        (int) $conversation['id'],
        [$fixture($alteredPngBytes, 'changed-batch.png', 'image/png'), $fixture($pdfBytes, 'new-batch.pdf', 'application/pdf')],
        [
            ['client_message_id' => 'media-batch-' . $suffix . '-0', 'kind' => 'image'],
            ['client_message_id' => 'media-identity-sibling-' . $suffix, 'kind' => 'document'],
        ],
        1,
        'media-identity-mismatch-' . $suffix
    );
    $assert(($identityMismatchBatch['items'][0]['details']['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH', 'batch media retry preserves binary mismatch details per item');
    $assert(($identityMismatchBatch['items'][1]['status'] ?? '') === 'not_attempted', 'batch media mismatch leaves valid siblings not attempted');
    $assert($fakeClient->mediaSendCalls === $beforeBatchIdentityMismatch, 'batch binary mismatch made zero provider calls');
    $beforeRejectedBatch = $fakeClient->mediaSendCalls;
    $rejectedBatch = $mediaService->sendBatch(
        (int) $conversation['id'],
        [$fixture($pngBytes, 'valid.png', 'image/png'), $fixture('plain text', 'spoofed.png', 'image/png')],
        [
            ['client_message_id' => 'media-rejected-' . $suffix . '-0', 'kind' => 'image'],
            ['client_message_id' => 'media-rejected-' . $suffix . '-1', 'kind' => 'image'],
        ],
        1,
        'media-rejected-' . $suffix
    );
    $assert(($rejectedBatch['items'][1]['status'] ?? '') === 'rejected', 'spoofed MIME is rejected in batch preflight');
    $assert(($rejectedBatch['items'][0]['status'] ?? '') === 'not_attempted', 'valid sibling is not sent when a batch item is rejected');
    $assert($fakeClient->mediaSendCalls === $beforeRejectedBatch, 'a rejected batch never reaches the external provider');

    $retryableId = 'media-retryable-' . $suffix;
    $fakeClient->mediaFailureAfter = $fakeClient->mediaSendCalls;
    $fakeClient->mediaFailureStatus = 429;
    $retryableFailed = false;
    try {
        $mediaService->send((int) $conversation['id'], $fixture($pngBytes, 'retryable.png', 'image/png'), '', $retryableId, 1, 'image');
    } catch (Throwable $exception) {
        $retryableFailed = true;
    }
    $retryableRow = $messages->find_by_client_message_id((int) $conversation['id'], $retryableId);
    $retryableMessageId = (int) ($retryableRow['id'] ?? 0);
    $assert($retryableFailed && ($retryableRow['status'] ?? '') === 'failed', 'definitive pre-acceptance media failure remains failed');
    $fakeClient->mediaFailureAfter = PHP_INT_MAX;
    $retryableSuccess = $mediaService->send((int) $conversation['id'], $fixture($pngBytes, 'retryable-again.png', 'image/png'), '', $retryableId, 1, 'image');
    $retryableRowAfter = $messages->find_by_client_message_id((int) $conversation['id'], $retryableId);
    $assert(($retryableSuccess['status'] ?? '') === 'sent', 'retryable media failure can be retried safely');
    $assert((int) ($retryableRowAfter['id'] ?? 0) === $retryableMessageId, 'retryable media does not create a duplicate message row');

    $concurrentId = 'media-concurrent-' . $suffix;
    $contentionDb = db_connect('default', false);
    $contentionLock = (new Chatwoot_plugin\Services\Send_lock_service())->nameFor((int) $conversation['id'], $concurrentId);
    $contentionHeld = false;
    try {
        $contentionRow = $contentionDb->query('SELECT GET_LOCK(?, 0) AS acquired_lock', [$contentionLock])->getRowArray();
        $contentionHeld = (int) ($contentionRow['acquired_lock'] ?? 0) === 1;
        $beforeConcurrent = $fakeClient->mediaSendCalls;
        $contentionBlocked = false;
        try {
            $mediaService->send((int) $conversation['id'], $fixture($pngBytes, 'concurrent.png', 'image/png'), '', $concurrentId, 1, 'image');
        } catch (Throwable $exception) {
            $contentionBlocked = (int) $exception->getCode() === 409;
        }
        $assert($contentionHeld && $contentionBlocked, 'media send is blocked while the same client id lock is held');
        $assert($fakeClient->mediaSendCalls === $beforeConcurrent, 'lock contention cannot create a duplicate provider call');
    } finally {
        if ($contentionHeld) $contentionDb->query('SELECT RELEASE_LOCK(?)', [$contentionLock]);
        $contentionDb->close();
    }

    $lidRemoteJid = '12000000000000@lid';
    $lidPhone = '5511777700999';
    $lidConversationId = $conversations->upsert_conversation($instanceId, $lidRemoteJid, [
        'contact_name' => 'Contato LID',
        'phone_number' => $lidPhone,
    ]);
    $fakeClient->nextMessageId = 'LID-SEND-' . $suffix;
    $lidSent = $chat->send_text($lidConversationId, 'Mensagem para LID', 'lid-client-' . $suffix);
    $assert($fakeClient->lastSendNumber === $lidPhone, 'LID send used the opaque LID instead of the alternate phone');
    $assert(($lidSent['external_message_id'] ?? '') === 'LID-SEND-' . $suffix, 'LID send response was not reconciled');

    $groupRemoteJid = '1203630' . substr($suffix, 0, 6) . '@g.us';
    $groupConversationId = $conversations->upsert_conversation($instanceId, $groupRemoteJid, [
        'contact_name' => 'Grupo de integracao',
        'phone_number' => '1203630' . substr($suffix, 0, 6),
    ]);
    $fakeClient->nextMessageId = 'GROUP-SEND-' . $suffix;
    $groupSent = $chat->send_text($groupConversationId, 'Mensagem para o grupo', 'group-client-' . $suffix);
    $assert($fakeClient->postCalls === 1, 'group message did not use the JID-preserving request');
    $assert(($fakeClient->lastPostPayload['number'] ?? '') === $groupRemoteJid, 'group JID was normalized as an individual phone');
    $assert(($groupSent['external_message_id'] ?? '') === 'GROUP-SEND-' . $suffix, 'group send response was not reconciled');

    $collisionRemoteJid = '551177770005@s.whatsapp.net';
    $collisionConversationId = $conversations->upsert_conversation($instanceId, $collisionRemoteJid, [
        'contact_name' => 'Colisao otimista',
        'phone_number' => '551177770005',
    ]);
    $collisionExternalId = 'COLLISION-' . $suffix;
    $canonicalId = $messages->upsert_message($collisionConversationId, $instanceId, [
        'external_message_id' => $collisionExternalId,
        'remote_jid' => $collisionRemoteJid,
        'direction' => 'outgoing',
        'message_type' => 'text',
        'text_content' => 'Mensagem canonica do webhook',
        'status' => 'read',
        'sent_at' => gmdate('Y-m-d H:i:s'),
        'message_timestamp' => time(),
        'dedupe_key' => hash('sha256', $collisionExternalId),
    ]);
    $messages->returnFalseForExternalId = $collisionExternalId;
    $fakeClient->nextMessageId = $collisionExternalId;
    $collisionSent = $chat->send_text(
        $collisionConversationId,
        'Mensagem otimista em corrida',
        'collision-client-' . $suffix
    );
    $assert((int) ($collisionSent['id'] ?? 0) === $canonicalId, 'false update did not merge into the canonical message');
    $assert(($collisionSent['status'] ?? '') === 'read', 'optimistic merge regressed the canonical delivery status');
    $assert(($collisionSent['client_message_id'] ?? '') === 'collision-client-' . $suffix, 'canonical message did not receive client_message_id');
    $assert($messages->count_by_conversation($collisionConversationId) === 1, 'optimistic collision left two active rows');

    $instances->update_instance($instanceId, ['clear_api_key' => true]);
    $instanceWithoutKey = $instances->get_by_id($instanceId);
    $assert(empty($instanceWithoutKey['has_api_key']), 'instance-specific API key was not cleared atomically');
} catch (Throwable $exception) {
    $failures[] = get_class($exception) . ': ' . $exception->getMessage();
} finally {
    if ($jobCorrelationIds) {
        $db->table($db->prefixTable('chat_integration_jobs'))->whereIn('correlation_id', array_values(array_unique($jobCorrelationIds)))->delete();
    }
    if ($instanceId > 0) {
        $db->table($db->prefixTable('chat_webhook_logs'))->where('instance_id', $instanceId)->delete();
        $cleanupConversationIds = array_column($db->table($db->prefixTable('chat_conversations'))->select('id')->where('instance_id', $instanceId)->get()->getResultArray(), 'id');
        if ($cleanupConversationIds) {
            $db->table($db->prefixTable('chat_conversation_presence'))->whereIn('conversation_id', $cleanupConversationIds)->delete();
            $db->table($db->prefixTable('chat_internal_note_mentions'))->whereIn('conversation_id', $cleanupConversationIds)->delete();
            $db->table($db->prefixTable('chat_internal_notes'))->whereIn('conversation_id', $cleanupConversationIds)->delete();
        }
        $db->table($db->prefixTable('chat_messages'))->where('instance_id', $instanceId)->delete();
        $db->table($db->prefixTable('chat_conversations'))->where('instance_id', $instanceId)->delete();
        $db->table($db->prefixTable('chat_instances'))->where('id', $instanceId)->delete();
    }
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Service integration test passed; monotonic status, retry, locks, transactions, sync state, collaboration presence TTL, group send and optimistic merge verified.\n";
