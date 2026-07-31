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

$db = db_connect('default');
$settings = new Chatwoot_plugin\Models\Chat_settings_model();
$instances = new Chatwoot_plugin\Models\Chat_instances_model();
$conversations = new Chatwoot_plugin\Models\Chat_conversations_model();
$messages = new Chatwoot_plugin\Models\Chat_messages_model();
$secret = (string) $settings->get_value(Chatwoot_plugin\Models\Chat_settings_model::WEBHOOK_SECRET, '');
if ($secret === '') {
    fwrite(STDERR, "Webhook secret is not configured.\n");
    exit(1);
}

$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$instanceName = 'http_webhook_' . $suffix;
$instanceId = 0;
$failures = [];
$url = 'http://localhost/rise/index.php/chatwoot_plugin/webhooks/evolution';

$post = static function (string $body, array $headers) use ($url): array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 15,
    ]);
    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_errno($curl);
    curl_close($curl);

    return [$status, is_string($responseBody) ? json_decode($responseBody, true) : null, $error];
};

try {
    $instanceId = $instances->upsert_instance($instanceName, [
        'name' => 'HTTP Webhook Test',
        'evolution_instance_name' => $instanceName,
        'base_url' => 'https://evolution.invalid',
        'connection_status' => 'connected',
        'active' => 1,
    ]);

    $authHeaders = [
        static fn (string $body): array => ['X-Chatwoot-Webhook-Secret: ' . $secret],
        static fn (string $body): array => ['Authorization: Bearer ' . $secret],
        static fn (string $body): array => [
            'X-Chatwoot-Webhook-Signature: sha256=' . hash_hmac('sha256', $body, $secret),
        ],
    ];

    foreach ($authHeaders as $index => $headersForBody) {
        $payload = [
            'event' => 'messages.upsert',
            'instance' => $instanceName,
            'data' => [
                'key' => [
                    'id' => 'HTTP-' . $suffix . '-' . $index,
                    'remoteJid' => '5511777777777@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'pushName' => 'HTTP Test',
                'messageTimestamp' => time() + $index,
                'message' => ['conversation' => 'Webhook HTTP ' . $index],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $response, $curlError] = $post((string) $body, $headersForBody((string) $body));
        if ($curlError !== 0 || $status !== 200 || !is_array($response) || empty($response['success'])) {
            $failures[] = 'valid authentication method ' . ($index + 1) . ' failed (HTTP ' . $status . ', curl ' . $curlError . ')';
        }
    }

    [$invalidStatus] = $post('{}', ['X-Chatwoot-Webhook-Secret: invalid']);
    if ($invalidStatus !== 401) {
        $failures[] = 'invalid webhook credential was not rejected';
    }

    $conversation = $conversations->get_by_remote_jid($instanceId, '5511777777777@s.whatsapp.net');
    if (!$conversation || $messages->count_by_conversation((int) $conversation['id']) !== 3) {
        $failures[] = 'HTTP webhook messages were not persisted exactly once';
    }
    if ((int) ($conversation['unread_count'] ?? 0) !== 3) {
        $failures[] = 'HTTP webhook unread counter differs';
    }
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

echo "Webhook HTTP test passed; secret, bearer, HMAC, rejection and persistence verified.\n";
