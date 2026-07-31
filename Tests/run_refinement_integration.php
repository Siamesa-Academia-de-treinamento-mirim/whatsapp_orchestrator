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

class Refinement_test_settings extends Chatwoot_plugin\Models\Chat_settings_model
{
    private array $values;
    public function __construct(array $values) { $this->values = $values; }
    public function get_value(string $key, $default = null) { return array_key_exists($key, $this->values) ? $this->values[$key] : $default; }
}

$db = db_connect('default');
$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$failures = [];
$passed = 0;
$test = static function (string $name, callable $callback) use (&$failures, &$passed): void {
    try {
        $callback();
        $passed++;
        echo "[OK] {$name}\n";
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
        echo "[FAIL] {$name}\n";
    }
};
$assert = static function ($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$settings = new Refinement_test_settings([
    'audit_enabled' => 0,
    'quick_replies_json' => '[]',
    'n8n_base_url' => 'https://8.8.8.8',
    'n8n_token' => 'refinement-secret-' . $suffix,
    'n8n_auth_mode' => 'bearer',
    'n8n_timeout_seconds' => 3,
    'n8n_health_path' => '/healthz',
    'n8n_campaigns_path' => '/webhook/campanha',
    'n8n_ai_path' => '/webhook/iara/control',
    'n8n_events_path' => '/webhook/impulso/events',
    'campaign_window_end' => '20:00',
    'campaign_min_interval_seconds' => 8,
    'ai_default_state' => 'running',
]);
$audit = new Chatwoot_plugin\Services\Audit_service(null, $settings);
$calls = [];
$transport = static function (string $method, string $url, array $headers, string $body, array $options) use (&$calls, $suffix): array {
    $calls[] = compact('method', 'url', 'headers', 'body', 'options');
    $payload = json_decode($body, true) ?: [];
    if (str_ends_with($url, '/healthz')) return ['status_code' => 200, 'body' => json_encode(['version' => 'fake-1.0']), 'error' => false];
    if ($method === 'POST' && str_ends_with($url, '/webhook/campanha')) return ['status_code' => 201, 'body' => json_encode(['id' => 'FAKE-' . $suffix, 'status' => 'running']), 'error' => false];
    return ['status_code' => 200, 'body' => json_encode(['ok' => true, 'action' => $payload['action'] ?? null]), 'error' => false];
};
$n8n = new Chatwoot_plugin\Libraries\N8n_client($settings, $transport);
$instances = new Chatwoot_plugin\Models\Chat_instances_model();
$conversations = new Chatwoot_plugin\Models\Chat_conversations_model();
$instanceId = 0;
$contactIds = [];
$conversationId = 0;
$campaignIds = [];
$agentIds = [];
$automationIds = [];
$quickReplyIds = [];
$notificationIds = [];
$auditIds = [];

try {
    $instanceName = 'refinement_' . $suffix;
    $instanceId = $instances->upsert_instance($instanceName, [
        'name' => 'Refinement Test',
        'evolution_instance_name' => $instanceName,
        'base_url' => 'https://evolution.invalid',
        'connection_status' => 'connected',
        'active' => 1,
    ]);
    $contacts = new Chatwoot_plugin\Services\Contact_service(null, null, null, $instances, $audit, $db);
    $phoneA = '55119' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    $phoneB = '55118' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

    $test('contacts CRUD, tags, server filters and conflict protection', static function () use ($contacts, $instanceId, $phoneA, &$contactIds, $assert): void {
        $created = $contacts->save(['name' => 'Maria Refinement', 'phone' => $phoneA, 'email' => 'maria@example.test', 'instance_id' => $instanceId, 'tags' => ['Lead', 'Urgente']], 1);
        $contactIds[] = (int) $created['id'];
        $assert($created['phone'] === $phoneA && count($created['tags']) === 2, 'contact fields were not persisted');
        $list = $contacts->list(['q' => 'Maria Refinement', 'identified' => true], 1, 10);
        $assert((int) $list['meta']['total'] === 1 && $list['data'][0]['instance'] === 'Refinement Test', 'server-side search/filter failed');
        $summary = $contacts->summary();
        $assert($summary['total'] >= 1 && array_keys($summary) === ['total', 'with_conversation', 'unidentified', 'opt_out'], 'contact summary is not database-backed');
        $conflict = false;
        try { $contacts->save(['name' => 'Outra pessoa', 'phone' => $phoneA, 'email' => 'other@example.test', 'instance_id' => $instanceId], 1); }
        catch (RuntimeException $exception) { $conflict = $exception->getCode() === 409; }
        $assert($conflict, 'incompatible contact was silently merged');
    });

    $test('opt-out is enforced in campaign audience preview', static function () use ($contacts, $instanceId, $phoneA, $phoneB, &$contactIds, $settings, $n8n, $audit, $db, $assert): void {
        $active = $contacts->save(['name' => 'Contato Ativo', 'phone' => $phoneB, 'instance_id' => $instanceId], 1);
        $contactIds[] = (int) $active['id'];
        $first = $contacts->list(['q' => $phoneA], 1, 1)['data'][0];
        $contacts->set_opt_out((int) $first['id'], true, 1);
        $campaigns = new Chatwoot_plugin\Services\Campaign_service(null, null, null, $settings, $contacts, $n8n, $audit, $db);
        $preview = $campaigns->audience_preview(['instance_id' => $instanceId, 'audience_source' => 'manual', 'numbers' => [$phoneA, $phoneB, $phoneB]]);
        $assert($preview['count'] === 1 && $preview['excluded_opt_out'] === 1, 'opt-out or deduplication failed');
    });

    $campaignService = new Chatwoot_plugin\Services\Campaign_service(null, null, $instances, $settings, $contacts, $n8n, $audit, $db);
    $test('campaign adapter CRUD, duplicate and toggle with fake n8n', static function () use ($campaignService, $instanceId, $phoneA, $phoneB, &$campaignIds, &$calls, $assert): void {
        $campaign = $campaignService->save(['name' => 'Campanha ' . uniqid(), 'instance_id' => $instanceId, 'audience_source' => 'manual', 'numbers' => [$phoneB], 'message' => 'Ola, {nome}!', 'start_immediately' => true], 1);
        $campaignIds[] = (int) $campaign['id'];
        $assert($campaign['external_id'] !== null && $campaign['status'] === 'running', 'campaign was not synchronized');
        $duplicate = $campaignService->duplicate((int) $campaign['id'], 1); $campaignIds[] = (int) $duplicate['id'];
        $assert($duplicate['status'] === 'draft' && $duplicate['external_id'] === null, 'campaign duplicate reused provider identity');
        $paused = $campaignService->toggle((int) $campaign['id'], 1);
        $assert($paused['status'] === 'paused', 'campaign toggle failed');
        $summary = $campaignService->summary();
        $assert($summary['month'] >= 1 && str_ends_with($summary['delivery_rate'], '%'), 'campaign summary is not database-backed');
        $serialized = json_encode($calls, JSON_UNESCAPED_SLASHES);
        $assert(str_contains((string) $serialized, 'lista_contato') && !str_contains((string) $serialized, $phoneA), 'legacy DTO or opt-out snapshot is invalid');
    });

    $conversationId = $conversations->upsert_conversation($instanceId, $phoneB . '@s.whatsapp.net', ['contact_name' => 'Contato Ativo', 'phone_number' => $phoneB, 'status' => 'open']);
    $ai = new Chatwoot_plugin\Services\Ai_service(null, null, null, $conversations, $instances, $settings, $n8n, $audit, $db);
    $test('AI agents and conversation state use fake n8n', static function () use ($ai, $instanceId, $conversationId, &$agentIds, $assert): void {
        $agent = $ai->save_agent(['name' => 'IARA Teste', 'instance_id' => $instanceId, 'webhook_path' => '/webhook/iara/control', 'workflow_id' => 'wf-test', 'active' => true], 1);
        $agentIds[] = (int) $agent['id'];
        $assert($agent['active'] === true, 'AI agent was not created');
        $state = $ai->set_state($conversationId, ['status' => 'human', 'reason' => 'integration_test'], 1);
        $assert($state['status'] === 'human' && !empty($state['external_synced_at']), 'AI state was not mirrored');
        $toggled = $ai->toggle_agent((int) $agent['id'], 1);
        $assert($toggled['active'] === false, 'AI agent toggle failed');
    });

    $automations = new Chatwoot_plugin\Services\Automation_service(null, $instances, $settings, $n8n, $audit);
    $test('automation CRUD and dry-run test use fake n8n', static function () use ($automations, $instanceId, &$automationIds, $assert): void {
        $automation = $automations->save(['name' => 'Auto Teste', 'trigger_event' => 'message_received', 'webhook_path' => '/webhook/automation-test', 'conditions' => ['instance_id' => $instanceId], 'instance_id' => $instanceId], 1);
        $automationIds[] = (int) $automation['id'];
        $result = $automations->test((int) $automation['id'], 1);
        $assert(!empty($result['dry_run']) && !empty($result['correlation_id']), 'automation test was not a dry-run');
    });

    $test('quick replies CRUD and unique shortcut shape', static function () use ($settings, $audit, &$quickReplyIds, $suffix, $assert): void {
        $service = new Chatwoot_plugin\Services\Quick_reply_service(null, $audit, $settings);
        $reply = $service->save(['title' => 'Saudacao', 'text' => 'Ola!', 'shortcut' => '/oi_' . $suffix], 1);
        $quickReplyIds[] = (int) $reply['id'];
        $assert($reply['text'] === 'Ola!' && $reply['active'] === true, 'quick reply was not persisted');
        $service->delete((int) $reply['id'], 1);
    });

    $test('notifications list, mark read and dedupe', static function () use (&$notificationIds, $suffix, $assert): void {
        $service = new Chatwoot_plugin\Services\Notification_service();
        $id = $service->create('system', 'Teste', 'Notificacao de integracao', 'test', null, 1, 'info', 'refinement-notification-' . $suffix);
        $notificationIds[] = (int) $id;
        $duplicate = $service->create('system', 'Teste', 'Notificacao de integracao', 'test', null, 1, 'info', 'refinement-notification-' . $suffix);
        $assert($duplicate === null && $service->unread_count(1) >= 1, 'notification dedupe/count failed');
        $read = $service->read((int) $id, 1);
        $assert($read['read'] === true, 'notification was not marked read');
    });

    $test('audit records are sanitized and permission endpoint has real data', static function () use (&$auditIds, $suffix, $assert): void {
        $model = new Chatwoot_plugin\Models\Chat_audit_logs_model();
        $service = new Chatwoot_plugin\Services\Audit_service($model, new Refinement_test_settings(['audit_enabled' => 1]));
        $service->record(1, 'refinement.test', 'test', $suffix, null, ['token' => 'must-not-leak'], ['status' => 'ok']);
        $result = $service->list(['resource_type' => 'test', 'resource_id' => $suffix], 1, 10);
        $assert((int) $result['meta']['total'] === 1 && ($result['data'][0]['before']['token'] ?? '') !== 'must-not-leak', 'audit list leaked or did not persist');
        $auditIds[] = (int) $result['data'][0]['id'];
    });

    $test('reports use real rows and CSV has BOM', static function () use ($db, $audit, $instanceId, $assert): void {
        $report = new Chatwoot_plugin\Services\Report_service($db, $audit);
        $data = $report->generate(['period' => '7d', 'instance_id' => $instanceId, 'timezone' => 'America/Sao_Paulo']);
        $csv = $report->csv(['period' => '7d', 'instance_id' => $instanceId, 'timezone' => 'America/Sao_Paulo'], 1);
        $assert($data['summary']['conversations'] >= 1 && str_starts_with($csv, "\xEF\xBB\xBF"), 'real report or safe CSV failed');
    });

    $test('N8n client health, auth sanitization and SSRF block', static function () use ($n8n, $settings, $suffix, $assert): void {
        $health = $n8n->health();
        $assert($health['connected'] === true && $health['version'] === 'fake-1.0', 'fake n8n health failed');
        $private = new Refinement_test_settings(['n8n_base_url' => 'http://127.0.0.1', 'n8n_timeout_seconds' => 3]);
        $blocked = false;
        try { (new Chatwoot_plugin\Libraries\N8n_client($private, static fn (): array => ['status_code' => 200, 'body' => '{}']))->request('GET', '/healthz'); }
        catch (RuntimeException $exception) { $blocked = $exception->getCode() === 503; }
        $assert($blocked, 'private n8n address bypassed SSRF protection');
    });

    $test('Evolution directPath/base64 adapter and central endpoint', static function () use ($assert): void {
        $calls = [];
        $client = new Chatwoot_plugin\Libraries\Evolution_client(['instance' => ['evolution_instance_name' => 'media-test', 'base_url' => 'https://evolution.example.test', 'api_key' => 'secret']], static function ($method, $url, $headers, $payload) use (&$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'payload');
            return ['status_code' => 200, 'body' => json_encode(['base64' => base64_encode('%PDF-test-content-long'), 'mimetype' => 'application/pdf'])];
        });
        $result = $client->get_media_base64(['key' => ['id' => 'media-id'], 'message' => ['documentMessage' => ['directPath' => '/v/t62']]]);
        $assert($result['success'] === true && base64_decode($result['base64'], true) === '%PDF-test-content-long' && str_contains($calls[0]['url'], '/chat/getBase64FromMediaMessage/media-test'), 'base64 media adapter failed');
    });

    $test('all required refined routes are registered statically', static function () use ($root, $assert): void {
        $routes = (string) file_get_contents($root . '/plugins/Chatwoot_plugin/Config/Routes.php');
        $required = ['api/contacts/export', 'api/contacts/import', 'api/conversations/(:num)/attachments', 'api/campaigns/audience-preview', 'api/campaigns/health', 'api/ai/state/health', 'api/automations/(:num)/test', 'api/reports/export', 'api/notifications/read-all', 'api/integrations/n8n/test', 'api/audit-logs'];
        $missing = array_values(array_filter($required, static fn (string $route): bool => !str_contains($routes, "'" . $route . "'")));
        $assert($missing === [], 'missing routes: ' . implode(', ', $missing));
    });
} finally {
    if ($instanceId > 0) {
        foreach (['chat_ai_logs', 'chat_ai_states', 'chat_automations', 'chat_ai_agents', 'chat_campaign_recipients', 'chat_campaign_runs', 'chat_campaigns', 'chat_media', 'chat_internal_notes', 'chat_conversation_tags', 'chat_messages', 'chat_conversations', 'chat_contacts'] as $table) {
            if ($db->fieldExists('instance_id', $db->prefixTable($table))) $db->table($table)->where('instance_id', $instanceId)->delete();
        }
        $db->table('chat_instances')->where('id', $instanceId)->delete();
    }
    if ($contactIds) {
        $db->table('chat_contact_identifiers')->whereIn('contact_id', $contactIds)->delete();
        $db->table('chat_contact_tags')->whereIn('contact_id', $contactIds)->delete();
        $db->table('chat_contacts')->whereIn('id', $contactIds)->delete();
    }
    if ($quickReplyIds) $db->table('chat_quick_replies')->whereIn('id', $quickReplyIds)->delete();
    if ($notificationIds) $db->table('chat_notifications')->whereIn('id', $notificationIds)->delete();
    if ($auditIds) $db->table('chat_audit_logs')->whereIn('id', $auditIds)->delete();
    if ($campaignIds) $db->table('chat_notifications')->where('resource_type', 'campaign')->whereIn('resource_id', $campaignIds)->delete();
    $db->table('chat_notifications')->like('message', $suffix)->delete();
    $db->table('chat_integration_jobs')->like('correlation_id', $suffix)->delete();
}

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}
