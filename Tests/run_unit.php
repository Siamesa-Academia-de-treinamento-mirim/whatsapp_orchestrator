<?php

declare(strict_types=1);

// The production host (CodeIgniter/Rise) requires mbstring. These small
// fallbacks keep the dependency-free unit suite executable in minimal CI images.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}

use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Libraries\Meta_cloud_client;
use Chatwoot_plugin\Providers\Provider_capabilities;
use Chatwoot_plugin\Services\Bot_flow_validator;
use Chatwoot_plugin\Services\Meta_webhook_normalizer;
use Chatwoot_plugin\Services\Payload_sanitizer;
use Chatwoot_plugin\Services\Webhook_normalizer;

require_once dirname(__DIR__) . '/Services/Payload_sanitizer.php';
require_once dirname(__DIR__) . '/Services/Webhook_normalizer.php';
require_once dirname(__DIR__) . '/Libraries/Evolution_client.php';
require_once dirname(__DIR__) . '/Libraries/Meta_cloud_client.php';
require_once dirname(__DIR__) . '/Providers/Provider_capabilities.php';
require_once dirname(__DIR__) . '/Services/Bot_flow_validator.php';
require_once dirname(__DIR__) . '/Services/Meta_webhook_normalizer.php';

$tests = [];
$failures = [];

$test = static function (string $name, callable $callback) use (&$tests, &$failures): void {
    try {
        $callback();
        $tests[] = $name;
        echo "[OK] {$name}\n";
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
        echo "[FAIL] {$name}\n";
    }
};

$assertTrue = static function ($condition, string $message = 'assertTrue failed'): void {
    if ($condition !== true) {
        throw new RuntimeException($message);
    }
};

$assertSame = static function ($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        $suffix = $message !== '' ? $message : 'Values are not identical.';
        throw new RuntimeException($suffix . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$assertNotContains = static function (string $needle, string $haystack, string $message = ''): void {
    if ($needle !== '' && str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : 'Sensitive value was exposed.');
    }
};

$assertThrows = static function (callable $callback, string $expectedClass = Throwable::class): Throwable {
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $expectedClass) {
            throw new RuntimeException('Unexpected exception class: ' . get_class($exception));
        }
        return $exception;
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$test('Evolution endpoints, header, body, status mapping and message id', static function () use ($assertTrue, $assertSame): void {
    $calls = [];
    $secret = 'unit-secret-api-key';
    $transport = static function ($method, $url, $headers, $payload, $options) use (&$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'options');

        if (str_contains($url, '/connectionState/')) {
            return ['status_code' => 200, 'body' => json_encode(['instance' => ['state' => 'open']])];
        }
        if (str_contains($url, '/sendText/')) {
            return ['status_code' => 201, 'body' => json_encode(['key' => ['id' => 'message-real-id']])];
        }
        if (str_contains($url, '/sendReaction/')) {
            return ['status_code' => 201, 'body' => json_encode(['key' => ['id' => 'reaction-real-id']])];
        }

        return ['status_code' => 200, 'body' => json_encode(['records' => []])];
    };
    $cipher = new class($secret) {
        private string $secret;
        public function __construct(string $secret) { $this->secret = $secret; }
        public function decrypt(string $value): string { return $value === 'encrypted-value' ? $this->secret : ''; }
    };
    $client = new Evolution_client([
        'instance' => [
            'evolution_instance_name' => 'Loja Principal',
            'base_url' => 'https://evolution.example.test/',
            'api_key_encrypted' => 'encrypted-value',
        ],
    ], $transport, null, $cipher);

    $status = $client->status();
    $assertTrue($status['success']);
    $assertSame('connected', $status['connection_status']);
    $assertSame('GET', $calls[0]['method']);
    $assertSame('https://evolution.example.test/instance/connectionState/Loja%20Principal', $calls[0]['url']);
    $assertSame($secret, $calls[0]['headers']['apikey']);
    $assertSame(null, $calls[0]['payload']);
    $assertSame(false, $calls[0]['options']['follow_redirects']);
    $assertSame(true, $calls[0]['options']['verify_tls']);

    $client->find_chats();
    $assertSame('POST', $calls[1]['method']);
    $assertSame('https://evolution.example.test/chat/findChats/Loja%20Principal', $calls[1]['url']);
    $assertSame([], $calls[1]['payload']);

    $client->find_messages('5511999999999@s.whatsapp.net', ['page' => 2]);
    $assertSame('https://evolution.example.test/chat/findMessages/Loja%20Principal', $calls[2]['url']);
    $assertSame('5511999999999@s.whatsapp.net', $calls[2]['payload']['where']['key']['remoteJid']);
    $assertSame(2, $calls[2]['payload']['page']);

    $sent = $client->send_text('+55 (11) 99999-9999', 'Mensagem de teste');
    $assertTrue($sent['success']);
    $assertSame('message-real-id', $sent['message_id']);
    $assertSame('https://evolution.example.test/message/sendText/Loja%20Principal', $calls[3]['url']);
    $assertSame(['number' => '5511999999999', 'text' => 'Mensagem de teste'], $calls[3]['payload']);
    $client->send_text_with_context('5511999999999', 'Resposta', null, [
        'reply_to_external_message_id' => 'message-incoming-id',
        'reply_to_remote_jid' => '5511999999999@s.whatsapp.net',
        'reply_to_from_me' => false,
    ]);
    $assertSame('message-incoming-id', $calls[4]['payload']['quoted']['key']['id']);
    $assertSame('5511999999999@s.whatsapp.net', $calls[4]['payload']['quoted']['key']['remoteJid']);
    $assertSame(false, $calls[4]['payload']['quoted']['key']['fromMe']);
    $reaction = $client->sendReaction('120363012345678901', 'message-incoming-id', '👍', null, ['remote_jid' => '120363012345678901@g.us', 'participant' => '5511987654321@s.whatsapp.net']);
    $assertTrue($reaction['success']);
    $assertSame('https://evolution.example.test/message/sendReaction/Loja%20Principal', $calls[5]['url']);
    $assertSame('message-incoming-id', $calls[5]['payload']['reactionKey']['id']);
    $assertSame('120363012345678901@g.us', $calls[5]['payload']['reactionKey']['remoteJid']);
    $assertSame('5511987654321@s.whatsapp.net', $calls[5]['payload']['reactionKey']['participant']);
    $assertSame('👍', $calls[5]['payload']['reactionMessage']);
});

$test('Settings fallback and configurable endpoint', static function () use ($assertTrue, $assertSame): void {
    $calls = [];
    $settings = new class {
        public function get_value(string $key, $default = null)
        {
            $values = [
                'base_url' => 'https://global.example.test',
                'evolution_api_key' => 'global-key',
                'evolution_timeout_seconds' => '17',
                'evolution_endpoint_connection_state' => '/v2/state/{instance}',
            ];

            return $values[$key] ?? $default;
        }
    };
    $transport = static function ($method, $url, $headers, $payload, $options) use (&$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'options');
        return ['status_code' => 200, 'body' => '{"state":"connecting"}'];
    };
    $client = new Evolution_client([
        'instance' => ['evolution_instance_name' => 'fallback'],
    ], $transport, $settings);

    $response = $client->status();
    $assertTrue($response['success']);
    $assertSame('attention', $response['connection_status']);
    $assertSame('https://global.example.test/v2/state/fallback', $calls[0]['url']);
    $assertSame('global-key', $calls[0]['headers']['apikey']);
    $assertSame(17, $calls[0]['options']['timeout']);
});

$test('Global API key never crosses to an instance-specific origin', static function () use ($assertTrue, $assertSame, $assertNotContains): void {
    $calls = [];
    $settings = new class {
        public function get_value(string $key, $default = null)
        {
            $values = [
                'base_url' => 'https://trusted.example.test/evolution',
                'evolution_api_key' => 'global-origin-bound-key',
            ];

            return $values[$key] ?? $default;
        }
    };
    $transport = static function ($method, $url, $headers) use (&$calls): array {
        $calls[] = compact('method', 'url', 'headers');
        return ['status_code' => 200, 'body' => '{"state":"open"}'];
    };

    $blocked = new Evolution_client([
        'instance' => [
            'evolution_instance_name' => 'blocked',
            'base_url' => 'https://untrusted.example.test',
        ],
    ], $transport, $settings);
    $blockedResponse = $blocked->status();
    $assertSame(false, $blockedResponse['success']);
    $assertSame('configuration_error', $blockedResponse['error_code']);
    $assertSame(0, count($calls), 'Cross-origin transport should never be called.');
    $assertNotContains('global-origin-bound-key', (string) json_encode($blockedResponse));

    $allowed = new Evolution_client([
        'instance' => [
            'evolution_instance_name' => 'allowed',
            'base_url' => 'https://trusted.example.test/other-path',
        ],
    ], $transport, $settings);
    $allowedResponse = $allowed->status();
    $assertTrue($allowedResponse['success']);
    $assertSame(1, count($calls));
    $assertSame('global-origin-bound-key', $calls[0]['headers']['apikey']);
});

$test('API key never appears in normalized response, error or log', static function () use ($assertNotContains): void {
    $secret = 'never-print-this-key';
    $logs = [];
    $logger = static function ($level, $message, $context) use (&$logs): void {
        $logs[] = compact('level', 'message', 'context');
    };
    $transport = static function () use ($secret): array {
        return [
            'status_code' => 401,
            'body' => json_encode([
                'error' => ['message' => 'Rejected credential ' . $secret],
                'apikey' => $secret,
            ]),
        ];
    };
    $client = new Evolution_client([
        'instance' => [
            'evolution_instance_name' => 'secure',
            'base_url' => 'https://secure.example.test',
            'api_key' => $secret,
        ],
    ], $transport, null, null, $logger);
    $response = $client->status();
    $serialized = json_encode(['response' => $response, 'logs' => $logs]);
    $assertNotContains($secret, (string) $serialized);
});

$test('Transport exception is generic and sanitized', static function () use ($assertNotContains): void {
    $secret = 'transport-secret';
    $transport = static function () use ($secret): array {
        throw new RuntimeException('cURL failed with ' . $secret);
    };
    $client = new Evolution_client([
        'instance' => [
            'evolution_instance_name' => 'secure',
            'base_url' => 'https://secure.example.test',
            'api_key' => $secret,
        ],
    ], $transport);
    $response = $client->find_chats();
    $assertNotContains($secret, (string) json_encode($response));
});

$test('Payload sanitizer redacts recursively and truncates', static function () use ($assertSame, $assertTrue, $assertNotContains): void {
    $sanitizer = new Payload_sanitizer(400, 80, 20, 6);
    $payload = [
        'headers' => ['apikey' => 'secret-a', 'Authorization' => 'Bearer secret-b'],
        'nested' => ['password' => 'secret-c', 'auth' => 'secret-d', 'safe' => str_repeat('x', 1000)],
        'message' => 'credential=literal-secret',
    ];
    $result = $sanitizer->sanitize($payload, ['literal-secret']);
    $serialized = (string) json_encode($result);
    $assertNotContains('secret-a', $serialized);
    $assertNotContains('secret-b', $serialized);
    $assertNotContains('secret-c', $serialized);
    $assertNotContains('secret-d', $serialized);
    $assertNotContains('literal-secret', $serialized);
    $assertTrue(strlen($serialized) < 1000, 'Sanitized payload was not bounded.');

    $redacted = $sanitizer->redact(['token' => 'abc', 'key' => ['id' => 'message-id']]);
    $assertSame('[REDACTED]', $redacted['token']);
    $assertSame('message-id', $redacted['key']['id']);
});

$test('Evolution v2 image webhook normalization', static function () use ($assertSame): void {
    $normalizer = new Webhook_normalizer();
    $normalized = $normalizer->normalize([
        'event' => 'MESSAGES_UPSERT',
        'instance' => 'loja-1',
        'data' => [
            'key' => [
                'id' => 'ABC123',
                'remoteJid' => '5511988887777@s.whatsapp.net',
                'fromMe' => false,
            ],
            'pushName' => 'Maria',
            'messageTimestamp' => 1784123456,
            'message' => [
                'imageMessage' => [
                    'url' => 'https://media.example.test/image',
                    'mimetype' => 'image/jpeg',
                    'caption' => 'Comprovante',
                ],
            ],
            'status' => 'DELIVERY_ACK',
        ],
    ]);

    $assertSame('messages.upsert', $normalized['event']);
    $assertSame('loja-1', $normalized['instance_name']);
    $assertSame('ABC123', $normalized['external_message_id']);
    $assertSame('5511988887777@s.whatsapp.net', $normalized['remote_jid']);
    $assertSame('5511988887777', $normalized['phone_number']);
    $assertSame(false, $normalized['from_me']);
    $assertSame('incoming', $normalized['direction']);
    $assertSame('Maria', $normalized['contact_name']);
    $assertSame(1784123456, $normalized['timestamp']);
    $assertSame('image', $normalized['message_type']);
    $assertSame('Comprovante', $normalized['text']);
    $assertSame('https://media.example.test/image', $normalized['media_url']);
    $assertSame('image/jpeg', $normalized['mime_type']);
    $assertSame('delivery_ack', $normalized['status']);
    $assertSame('delivered', $normalized['message_status']);
});

$test('Evolution LID keeps conversation identity and uses remoteJidAlt as phone', static function () use ($assertSame): void {
    $normalizer = new Webhook_normalizer();
    $normalized = $normalizer->normalize([
        'event' => 'messages.upsert',
        'instance' => 'loja-lid',
        'data' => [
            'key' => [
                'id' => 'LID-1',
                'remoteJid' => '12000000000000@lid',
                'remoteJidAlt' => '5511988887777@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => ['conversation' => 'Mensagem LID'],
        ],
    ]);

    $assertSame('12000000000000@lid', $normalized['remote_jid']);
    $assertSame('5511988887777', $normalized['phone_number']);

    $unresolved = $normalizer->normalize([
        'event' => 'messages.upsert',
        'instance' => 'loja-lid',
        'data' => [
            'key' => [
                'id' => 'LID-2',
                'remoteJid' => '12000000000001@lid',
                'fromMe' => false,
            ],
        ],
    ]);
    $assertSame('', $unresolved['phone_number']);
});

$test('Legacy normalized audio/document fields and stable fallback dedupe', static function () use ($assertSame, $assertTrue): void {
    $normalizer = new Webhook_normalizer();
    $payload = [
        'event_name' => 'messages.upsert',
        'instance_name' => 'loja-2',
        'remote_jid' => '5511977776666@s.whatsapp.net',
        'from_me' => 'true',
        'contact_name' => 'Joao',
        'timestamp' => 1784000000000,
        'message_type' => 'audio',
        'media_url' => 'https://media.example.test/audio',
        'mime_type' => 'audio/ogg',
        'status' => 'READ',
    ];
    $first = $normalizer->normalize($payload);
    $second = $normalizer->normalize(array_reverse($payload, true));

    $assertSame('outgoing', $first['direction']);
    $assertSame(1784000000, $first['timestamp']);
    $assertSame('audio', $first['message_type']);
    $assertSame('audio/ogg', $first['mime_type']);
    $assertSame('read', $first['message_status']);
    $assertTrue(str_starts_with($first['dedupe_key'], 'fallback:'));
    $assertSame($first['dedupe_key'], $second['dedupe_key']);

    $document = $normalizer->normalize([
        'event' => 'messages.upsert',
        'instance' => 'loja-2',
        'external_message_id' => 'DOC-1',
        'remoteJid' => '5511977776666@s.whatsapp.net',
        'message_type' => 'document',
        'media' => 'https://media.example.test/file.pdf',
        'mimetype' => 'application/pdf',
        'fileName' => 'arquivo.pdf',
    ]);
    $assertSame('document', $document['message_type']);
    $assertSame('arquivo.pdf', $document['file_name']);
    $assertTrue(str_starts_with($document['dedupe_key'], 'message:'));
});

$test('Legacy body envelope and nested media are normalized', static function () use ($assertSame): void {
    $normalizer = new Webhook_normalizer();
    $normalized = $normalizer->normalize([
        'body' => json_encode([
            'event_name' => 'MESSAGES_UPSERT',
            'instance' => ['instanceName' => 'loja-body'],
            'messageId' => 'BODY-1',
            'remoteJid' => '5511966665555@s.whatsapp.net',
            'message' => 'Texto do n8n',
            'media' => [
                'url' => 'https://media.example.test/body.pdf',
                'mimetype' => 'application/pdf',
                'fileName' => 'body.pdf',
            ],
            'message_type' => 'document',
        ]),
    ]);

    $assertSame('messages.upsert', $normalized['event']);
    $assertSame('loja-body', $normalized['instance_name']);
    $assertSame('BODY-1', $normalized['external_message_id']);
    $assertSame('Texto do n8n', $normalized['text']);
    $assertSame('document', $normalized['message_type']);
    $assertSame('https://media.example.test/body.pdf', $normalized['media_url']);
    $assertSame('application/pdf', $normalized['mime_type']);
    $assertSame('body.pdf', $normalized['file_name']);
});


$test('Outgoing Evolution events never rename the customer from pushName or contact_name', static function () use ($assertSame): void {
    $normalizer = new Webhook_normalizer();
    $normalized = $normalizer->normalize([
        'event' => 'messages.upsert',
        'instance' => 'principal',
        'data' => [
            'key' => [
                'id' => 'OUT-1',
                'remoteJid' => '5511999990000@s.whatsapp.net',
                'fromMe' => true,
            ],
            'pushName' => 'Tiago',
            'contactName' => 'Tiago',
            'message' => ['conversation' => 'Mensagem enviada'],
        ],
    ]);

    $assertSame(true, $normalized['from_me']);
    $assertSame('outgoing', $normalized['direction']);
    $assertSame('', $normalized['contact_name']);
    $assertSame('', $normalized['sender_name']);
});

$test('Evolution group events preserve group and participant identities independently', static function () use ($assertSame, $assertTrue): void {
    $normalizer = new Webhook_normalizer();
    $normalized = $normalizer->normalize([
        'event' => 'messages.upsert',
        'instance' => 'principal',
        'data' => [
            'key' => [
                'id' => 'GROUP-1',
                'remoteJid' => '120363012345678901@g.us',
                'participant' => '5511987654321@s.whatsapp.net',
                'fromMe' => false,
            ],
            'pushName' => 'Maria Silva',
            'subject' => 'Pais Bombeiro Mirim',
            'message' => ['conversation' => 'Bom dia, pessoal'],
        ],
    ]);

    $assertSame(true, $normalized['is_group']);
    $assertSame('group', $normalized['conversation_type']);
    $assertSame('120363012345678901@g.us', $normalized['remote_jid']);
    $assertSame('Pais Bombeiro Mirim', $normalized['group_name']);
    $assertSame('Pais Bombeiro Mirim', $normalized['contact_name']);
    $assertSame('5511987654321@s.whatsapp.net', $normalized['participant_jid']);
    $assertSame('5511987654321@s.whatsapp.net', $normalized['sender_jid']);
    $assertSame('5511987654321', $normalized['sender_phone']);
    $assertSame('Maria Silva', $normalized['sender_name']);
    $assertTrue(str_starts_with($normalized['dedupe_key'], 'message:'));

    $selfReaction = $normalizer->normalize([
        'event' => 'messages.upsert',
        'instance' => 'principal',
        'data' => [
            'key' => ['id' => 'GROUP-REACTION-SELF', 'remoteJid' => '120363012345678901@g.us', 'fromMe' => true, 'participant' => '5511987654321@s.whatsapp.net'],
            'messageTimestamp' => 1784123456,
            'message' => ['reactionMessage' => ['key' => ['remoteJid' => '120363012345678901@g.us', 'fromMe' => false, 'id' => 'GROUP-1', 'participant' => '5511987654321@s.whatsapp.net'], 'text' => '👍']],
        ],
    ]);
    $assertSame('self', $selfReaction['structured_content']['reaction']['reactor_key']);
    $assertSame('GROUP-1', $selfReaction['structured_content']['reaction']['message_id']);
});

$test('Meta Cloud client builds official payloads, validates signature and hides credentials', static function () use ($assertTrue, $assertSame, $assertNotContains): void {
    $calls = [];
    $token = 'meta-token-super-secret';
    $appSecret = 'meta-app-secret';
    $transport = static function ($method, $url, $headers, $body, $options) use (&$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'body', 'options');
        if ($method === 'POST') {
            return ['status_code' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.TEST-1']]])];
        }
        return ['status_code' => 200, 'body' => json_encode(['data' => [['name' => 'saudacao', 'status' => 'APPROVED']]])];
    };
    $client = new Meta_cloud_client([
        'phone_number_id' => '123456789',
        'waba_id' => '987654321',
        'access_token' => $token,
        'app_secret' => $appSecret,
        'graph_version' => 'v25.0',
    ], $transport);

    $raw = '{"object":"whatsapp_business_account"}';
    $signature = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);
    $assertTrue($client->verifySignature($raw, $signature));
    $assertSame(false, $client->verifySignature($raw . 'x', $signature));

    $response = $client->sendText('+55 (11) 99999-0000', 'Bom dia');
    $assertTrue($response['success']);
    $assertSame('wamid.TEST-1', $response['message_id']);
    $assertSame('POST', $calls[0]['method']);
    $assertSame('https://graph.facebook.com/v25.0/123456789/messages', $calls[0]['url']);
    $payload = json_decode($calls[0]['body'], true);
    $assertSame('5511999990000', $payload['to']);
    $assertSame('individual', $payload['recipient_type']);
    $assertSame('Bom dia', $payload['text']['body']);
    $headers = implode("\n", $calls[0]['headers']);
    $assertTrue(str_contains($headers, 'Authorization: Bearer ' . $token));
    $assertNotContains($token, (string) json_encode($response));

    $client->sendText('5511999990000', 'Resposta contextual', [
        'reply_to_external_message_id' => 'wamid.IN-1',
    ]);
    $replyPayload = json_decode($calls[1]['body'], true);
    $assertSame('wamid.IN-1', $replyPayload['context']['message_id']);

    $templates = $client->listTemplates(500);
    $assertTrue($templates['success']);
    $assertTrue(str_contains($calls[2]['url'], '/987654321/message_templates?'));
    $assertTrue(str_contains($calls[2]['url'], 'limit=250'));

    $reaction = $client->sendReaction('5511999990000', 'wamid.IN-1', '❤️');
    $assertTrue($reaction['success']);
    $reactionPayload = json_decode($calls[3]['body'], true);
    $assertSame('reaction', $reactionPayload['type']);
    $assertSame('wamid.IN-1', $reactionPayload['reaction']['message_id']);
    $assertSame('❤️', $reactionPayload['reaction']['emoji']);
    $client->sendReaction('5511999990000', 'wamid.IN-1', '');
    $removePayload = json_decode($calls[4]['body'], true);
    $assertSame('', $removePayload['reaction']['emoji']);
});

$test('Meta webhook expands incoming message and delivery receipt into neutral events', static function () use ($assertSame): void {
    $normalizer = new Meta_webhook_normalizer();
    $events = $normalizer->expand([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '123456789'],
                    'contacts' => [['wa_id' => '5511987654321', 'profile' => ['name' => 'Maria']]],
                    'messages' => [[
                        'from' => '5511987654321',
                        'id' => 'wamid.IN-1',
                        'timestamp' => '1785440000',
                        'type' => 'text',
                        'text' => ['body' => 'Quero conhecer o projeto'],
                    ]],
                    'statuses' => [[
                        'id' => 'wamid.OUT-1',
                        'recipient_id' => '5511987654321',
                        'timestamp' => '1785440001',
                        'status' => 'delivered',
                    ], [
                        'id' => 'wamid.OUT-FAIL',
                        'recipient_id' => '5511987654321',
                        'timestamp' => '1785440002',
                        'status' => 'failed',
                        'errors' => [['code' => 131009, 'message' => 'Reaction too old']],
                    ]],
                ],
            ]],
        ]],
    ], 'meta-principal');

    $assertSame(3, count($events));
    $assertSame('messages.upsert', $events[0]['event']);
    $assertSame('meta-principal', $events[0]['instance_name']);
    $assertSame('Maria', $events[0]['contact_name']);
    $assertSame('Quero conhecer o projeto', $events[0]['text']);
    $assertSame('meta_cloud', $events[0]['provider_name']);
    $assertSame('messages.update', $events[1]['event']);
    $assertSame('delivered', $events[1]['message_status']);
    $assertSame('wamid.OUT-1|delivered', $events[1]['external_event_id']);
    $assertSame('131009', $events[2]['delivery_error_code']);
    $reactionEvents = $normalizer->expand([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '123456789'],
                    'messages' => [[
                        'from' => '5511987654321',
                        'id' => 'wamid.REACTION-1',
                        'type' => 'reaction',
                        'reaction' => ['message_id' => 'wamid.TARGET-1', 'emoji' => '👍'],
                    ]],
                ],
            ]],
        ]],
    ], 'meta-principal');
    $assertSame(null, $reactionEvents[0]['timestamp']);
    $assertSame(null, $reactionEvents[0]['structured_content']['reaction']['provider_timestamp']);
});

$test('Deterministic bot validates, matches accents and simulates completion without AI', static function () use ($assertSame, $assertTrue): void {
    $validator = new Bot_flow_validator();
    $definition = [
        'start' => 'inicio',
        'nodes' => [
            'inicio' => [
                'message' => 'Como posso ajudar?',
                'transitions' => [[
                    'id' => 'horarios',
                    'target' => 'fim',
                    'match' => ['type' => 'contains', 'values' => ['horário']],
                ]],
                'fallback_target' => '__handoff__',
            ],
            'fim' => [
                'message' => 'Temos turmas de manhã e à tarde.',
                'transitions' => [],
                'terminal' => true,
            ],
        ],
    ];

    $validated = $validator->validate($definition);
    $match = $validator->matchTransition($validated['nodes']['inicio']['transitions'], 'Quais sao os HORARIOS?');
    $assertSame('horarios', $match['id']);
    $result = $validator->simulate($definition, ['Quais são os horários?']);
    $assertTrue($result['valid']);
    $assertSame('completed', $result['result']);
    $assertSame('fim', $result['current_node']);
});

$test('Deterministic bot rejects ambiguous rules and hands off unknown input', static function () use ($assertThrows, $assertSame): void {
    $validator = new Bot_flow_validator();
    $ambiguous = [
        'start' => 'inicio',
        'nodes' => [
            'inicio' => [
                'message' => 'Escolha uma opção',
                'transitions' => [
                    ['id' => 'a', 'target' => '__handoff__', 'match' => ['type' => 'exact', 'values' => ['sim']]],
                    ['id' => 'b', 'target' => '__handoff__', 'match' => ['type' => 'contains', 'values' => ['SIM']]],
                ],
            ],
        ],
    ];
    $assertThrows(static fn () => $validator->validate($ambiguous), InvalidArgumentException::class);

    $safe = [
        'start' => 'inicio',
        'nodes' => [
            'inicio' => [
                'message' => 'Escolha uma opção',
                'transitions' => [['id' => 'sim', 'target' => '__handoff__', 'match' => ['type' => 'exact', 'values' => ['sim']]]],
                'fallback_target' => '__handoff__',
            ],
        ],
    ];
    $result = $validator->simulate($safe, ['pergunta completamente fora do escopo']);
    $assertSame('handoff', $result['result']);
    $assertSame(1, $result['fallbacks']);
});

$test('Provider capability matrix prevents official group/template confusion', static function () use ($assertSame): void {
    $evolution = Provider_capabilities::evolution();
    $meta = Provider_capabilities::metaCloud();
    $assertSame(true, $evolution['groups']);
    $assertSame(true, $evolution['supports_groups']);
    $assertSame(false, $evolution['templates']);
    $assertSame(false, $evolution['supports_templates']);
    $assertSame(false, $evolution['official']);
    $assertSame(false, $meta['groups']);
    $assertSame(false, $meta['supports_groups']);
    $assertSame(true, $meta['templates']);
    $assertSame(true, $meta['supports_templates']);
    $assertSame(true, $meta['official']);
    $assertSame(false, $meta['freeform_outside_window']);
    $assertSame(false, $meta['reaction']['groups']);
    $assertSame(2592000, $meta['reaction']['max_target_age_seconds']);
    $assertSame(true, $meta['reaction']['supports_remove']);
    $assertSame(true, $evolution['reaction']['groups']);
});

echo "\n" . count($tests) . " passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

exit(0);
