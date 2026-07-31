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
    public string $lastSendNumber = '';
    public array $lastPostPayload = [];
    public string $lastPostPath = '';
    private string $instanceName;

    public function __construct(string $instanceName)
    {
        $this->instanceName = $instanceName;
    }

    public function find_chats(array $filters = [], $instance = null): array
    {
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

        return [
            'success' => true,
            'message_id' => $this->nextMessageId,
            'data' => ['key' => ['id' => $this->nextMessageId], 'status' => 'PENDING'],
        ];
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

$db = db_connect('default');
$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$instanceName = 'codex_test_' . $suffix;
$instanceId = 0;
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

    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(is_array($message), 'message was not created');
    $assert(($message['text_content'] ?? '') === 'Mensagem de integracao', 'message text differs');
    $assert(empty($message['media_url']), 'unsafe media URL was persisted');
    $assert($messages->count_by_conversation((int) ($conversation['id'] ?? 0)) === 1, 'message was duplicated');

    $deliveredPayload = [
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'MSG-' . $suffix],
            'status' => 'DELIVERY_ACK',
        ],
    ];
    $statusResult = $chat->process_webhook_event($deliveredPayload);
    $statusDuplicate = $chat->process_webhook_event($deliveredPayload);
    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(($statusResult['kind'] ?? '') === 'message_status', 'status event was not recognized');
    $assert(($message['status'] ?? '') === 'delivered', 'message status was not updated');
    $assert(!empty($statusDuplicate['duplicate']), 'same message status was not deduplicated');

    $readResult = $chat->process_webhook_event([
        'event' => 'messages.update',
        'instance' => $instanceName,
        'data' => [
            'key' => ['id' => 'MSG-' . $suffix],
            'status' => 'READ',
        ],
    ]);
    $message = $messages->find_by_external_id($instanceId, 'MSG-' . $suffix);
    $assert(!empty($readResult['processed']), 'read progression collided with delivered dedupe');
    $assert(($message['status'] ?? '') === 'read', 'read progression was not persisted');

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
    $assert(!empty($futureRetried['processed']), 'pending status could not be reprocessed');
    $assert(($futureMessage['status'] ?? '') === 'delivered', 'reprocessed status was not applied');

    $sync = $chat->sync_chats($instanceId);
    $instance = $instances->get_by_id($instanceId);
    $conversation = $conversations->get_by_remote_jid($instanceId, '5511888888888@s.whatsapp.net');
    $assert(($sync['chats'] ?? 0) === 1, 'Evolution chat list was not synchronized');
    $assert(($instance['connection_status'] ?? '') === 'disconnected', 'findChats falsely marked the instance connected');
    $assert(!empty($instance['last_sync_at']), 'successful findChats did not update last_sync_at');
    $assert($fakeClient->statusCalls >= 1, 'connectionState was not consulted after sync');
    $assert(($conversation['contact_name'] ?? '') === 'Contato sincronizado', 'synchronized contact name differs');
    $assert((int) ($conversation['unread_count'] ?? 0) === 2, 'remote unread counter was not applied');
    $assert($messages->count_by_conversation((int) $conversation['id']) === 3, 'last chat message was not persisted once');

    $connectionResult = $chat->process_webhook_event([
        'event' => 'connection.update',
        'instance' => $instanceName,
        'data' => ['state' => 'open'],
    ]);
    $instance = $instances->get_by_id($instanceId);
    $assert(($connectionResult['status'] ?? '') === 'connected', 'connection event was not mapped');
    $assert(($instance['connection_status'] ?? '') === 'connected', 'instance status was not persisted');

    $history = $chat->get_messages((int) $conversation['id'], 50, null, null, true);
    $historyExternalIds = array_column($history['data'], 'external_message_id');
    $assert(in_array('HISTORY-TEST-1', $historyExternalIds, true), 'Evolution history was not normalized and persisted');
    $assert(($history['meta']['sync_error'] ?? null) === null, 'history sync reported an unexpected error');

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
    if ($instanceId > 0) {
        $db->table($db->prefixTable('chat_webhook_logs'))->where('instance_id', $instanceId)->delete();
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

echo "Service integration test passed; monotonic status, retry, locks, transactions, sync state, group send and optimistic merge verified.\n";
