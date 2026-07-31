<?php

declare(strict_types=1);

use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Services\Payload_sanitizer;
use Chatwoot_plugin\Services\Webhook_normalizer;

require_once dirname(__DIR__) . '/Services/Payload_sanitizer.php';
require_once dirname(__DIR__) . '/Services/Webhook_normalizer.php';
require_once dirname(__DIR__) . '/Libraries/Evolution_client.php';

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

$test('n8n normalized audio/document fields and stable fallback dedupe', static function () use ($assertSame, $assertTrue): void {
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

$test('n8n body envelope and nested media are normalized', static function () use ($assertSame): void {
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

echo "\n" . count($tests) . " passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

exit(0);
