<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $offset) : substr($value, $offset, $length); }
}

require_once dirname(__DIR__, 2) . '/Services/Message_send_exception.php';
require_once dirname(__DIR__, 2) . '/Services/Service_window_policy.php';
require_once dirname(__DIR__, 2) . '/Services/Template_parser_service.php';
require_once dirname(__DIR__, 2) . '/Services/Message_projection_service.php';
require_once dirname(__DIR__, 2) . '/Services/Media_engine_exception.php';
require_once dirname(__DIR__, 2) . '/Providers/Provider_capabilities.php';
require_once dirname(__DIR__, 2) . '/Services/Payload_sanitizer.php';
require_once dirname(__DIR__, 2) . '/Libraries/Meta_cloud_client.php';
require_once dirname(__DIR__, 2) . '/Services/Meta_webhook_normalizer.php';

use Chatwoot_plugin\Providers\Provider_capabilities;
use Chatwoot_plugin\Services\Message_send_exception;
use Chatwoot_plugin\Services\Service_window_policy;
use Chatwoot_plugin\Services\Template_parser_service;
use Chatwoot_plugin\Services\Message_projection_service;
use Chatwoot_plugin\Libraries\Meta_cloud_client;
use Chatwoot_plugin\Services\Meta_webhook_normalizer;

$passed = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$passed, &$failures): void {
    if ($condition) { ++$passed; echo "[OK] {$message}\n"; return; }
    $failures[] = $message; echo "[FAIL] {$message}\n";
};

$policy = new Service_window_policy();
$meta = Provider_capabilities::metaCloud();
$evolution = Provider_capabilities::evolution();
$state = $policy->state(['last_customer_message_at' => '2026-08-17 10:00:00', 'service_window_expires_at' => '2026-08-18 10:00:00'], $meta, strtotime('2026-08-17 11:00:00 UTC'));
$assert($state['required'] === true && $state['open'] === true && $state['seconds_remaining'] === 82800, 'Meta window is fixed at 24 hours and exposes remaining seconds');
$shortLegacy = $policy->state(['last_customer_message_at' => '2026-08-17 10:00:00', 'service_window_expires_at' => '2026-08-17 10:30:00'], $meta, strtotime('2026-08-17 10:31:00 UTC'));
$longLegacy = $policy->state(['last_customer_message_at' => '2026-08-17 10:00:00', 'service_window_expires_at' => '2026-08-19 10:00:00'], $meta, strtotime('2026-08-18 10:01:00 UTC'));
$assert($shortLegacy['open'] === true && $shortLegacy['expires_at'] === '2026-08-18T10:00:00+00:00', 'legacy short expiry cannot close the canonical Meta window');
$assert($longLegacy['open'] === false && $longLegacy['expires_at'] === '2026-08-18T10:00:00+00:00', 'legacy long expiry cannot extend the canonical Meta window');
$assert($policy->state(['service_window_expires_at' => '2099-01-01 00:00:00'], $meta)['open'] === false, 'a persisted expiry without a valid customer event is ignored');
$assert($policy->state([], $evolution)['freeform_allowed'] === true && $policy->state([], $evolution)['template_required'] === false, 'Evolution remains outside service-window enforcement');
$old = $policy->customerWindowData(['last_customer_message_at' => '2026-08-17 12:00:00'], ['message_type' => 'text'], false, strtotime('2026-08-17 11:00:00 UTC'), 'meta_cloud');
$assert($old === [], 'Older valid customer events cannot move the window backward');
$receipt = $policy->customerWindowData(['last_customer_message_at' => null], ['message_type' => 'reaction'], false, strtotime('2026-08-17 11:00:00 UTC'), 'meta_cloud');
$assert($receipt === [], 'Reaction/receipt-like events do not open the window');
$statusReceipt = $policy->customerWindowData(['last_customer_message_at' => null], ['event' => 'messages.update', 'status' => 'delivered'], false, strtotime('2026-08-17 11:00:00 UTC'), 'meta_cloud');
$assert($statusReceipt === [], 'Receipt events without a message type do not open the window');
$orderNormalizer = new Meta_webhook_normalizer();
$orderEvents = $orderNormalizer->expand(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-1'], 'messages' => [['from' => '5511999999999', 'id' => 'order-1', 'timestamp' => '1786960800', 'type' => 'order', 'order' => ['catalog_id' => 'catalog-1', 'product_items' => []]]]]]]]]], 'instance-1');
$orderEvent = $orderEvents[0] ?? [];
$orderData = $policy->customerWindowData([], $orderEvent, false, 1786960800, 'meta_cloud');
$oldOrderData = $policy->customerWindowData(['last_customer_message_at' => '2026-08-17 12:00:00'], $orderEvent, false, strtotime('2026-08-17 11:00:00 UTC'), 'meta_cloud');
$assert(($orderEvent['message_type'] ?? '') === 'unsupported' && ($orderEvent['provider_message_type'] ?? '') === 'order' && ($orderEvent['is_customer_message'] ?? false) === true, 'Meta order remains safely unsupported but explicitly qualifies as customer message');
$assert(($orderData['last_customer_message_at'] ?? '') === '2026-08-17 10:00:00' && $oldOrderData === [], 'Incoming order opens the canonical window and cannot move it backward');
try { $policy->assertFreeformAllowed(['service_window_expires_at' => '2026-08-17 10:00:00'], $meta, 'mensagem'); $assert(false, 'closed Meta window rejects freeform'); }
catch (Message_send_exception $exception) { $assert(($exception->details()['code'] ?? '') === 'SERVICE_WINDOW_CLOSED' && $exception->details()['template_required'] === true, 'closed window rejection is structured'); }

$parser = new Template_parser_service();
$template = $parser->parse([
    'id' => 7, 'provider_template_id' => 'meta-7', 'name' => 'welcome', 'language_code' => 'pt_BR', 'category' => 'UTILITY', 'provider_status' => 'approved', 'active' => 1,
    'components' => [
        ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Olá {{1}}'],
        ['type' => 'BODY', 'text' => 'Pedido {{1}} para {{2}}'],
        ['type' => 'FOOTER', 'text' => 'Impulso'],
        ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Abrir', 'url' => 'https://example.test/{{1}}'], ['type' => 'QUICK_REPLY', 'text' => 'OK']]],
    ],
]);
$assert($template['sendable'] === true && count($template['fields']) === 4, 'parser exposes typed body/header/button fields');
$resolved = $parser->resolve($template, ['header.1' => 'Maria', 'body.1' => '123', 'body.2' => 'Maria', 'button.0.1' => 'abc']);
$serialized = json_encode($resolved['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(str_contains($serialized, '"type":"body"') && str_contains($serialized, '"type":"button"') && str_contains($serialized, 'abc'), 'provider payload is built from stored definitions and validated parameters');
$gap = $parser->parse(['provider_template_id' => 'gap', 'name' => 'gap', 'language_code' => 'pt_BR', 'provider_status' => 'approved', 'active' => 1, 'components' => [['type' => 'BODY', 'text' => 'Oi {{2}}']]]);
$assert($gap['sendable'] === false && str_contains((string) $gap['unsupported_reason'], 'gap_body'), 'variable gaps fail closed');
$special = $parser->parse(['provider_template_id' => 'flow', 'name' => 'flow', 'language_code' => 'pt_BR', 'provider_status' => 'approved', 'active' => 1, 'components' => [['type' => 'BODY', 'text' => 'Oi'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'FLOW', 'text' => 'Abrir']]]]]);
$assert($special['sendable'] === false, 'unsupported interactive button types remain visible but unsendable');
$mediaTemplate = $parser->parse(['provider_template_id' => 'media', 'name' => 'media', 'language_code' => 'pt_BR', 'provider_status' => 'approved', 'active' => 1, 'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Arquivo']]]);
$mediaResolved = $parser->resolve($mediaTemplate, ['header_media' => ['kind' => 'image', 'local_media_id' => 91]]);
$assert(($mediaResolved['resolved']['media_reference']['local_media_id'] ?? 0) === 91 && str_contains(json_encode($mediaResolved['components']), 'local_media_id') && !str_contains(json_encode($mediaResolved['components']), '"id":"provider-id"'), 'template media payload accepts only a local media identity from the browser');
try { $parser->resolve($mediaTemplate, ['header_media' => ['kind' => 'image', 'id' => 'provider-id']]); $assert(false, 'provider media ids are not accepted from template form values'); }
catch (Message_send_exception $exception) { $assert(($exception->details()['code'] ?? '') === 'TEMPLATE_MEDIA_INVALID', 'provider media ids from the browser fail closed'); }

$projector = new Message_projection_service();
$projected = $projector->project([
    'id' => 22, 'conversation_id' => 3, 'instance_id' => 4, 'direction' => 'outgoing', 'message_type' => 'template', 'status' => 'sent',
    'text_content' => 'Pedido para Maria', 'raw_payload' => ['structured_content' => ['template' => [
        'name' => 'pedido', 'language' => 'pt_BR', 'header' => ['type' => 'text', 'text' => 'Oi'], 'body' => ['type' => 'text', 'text' => 'Pedido {{1}}'],
        'resolved_body' => 'Pedido para Maria', 'resolved_parameters' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Maria']]]],
        'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'OK']], 'media_reference' => ['local_media_id' => 5],
    ]]],
]);
$serializedProjected = json_encode($projected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(!str_contains($serializedProjected, '[object Object]') && ($projected['content']['template']['body'] ?? '') === 'Pedido para Maria', 'template projection exposes stable display strings without object coercion');
$assert(($projected['content']['template']['buttons'][0]['text'] ?? '') === 'OK' && ($projected['content']['template']['definitions']['body'] ?? '') === 'Pedido {{1}}', 'template definitions and display-safe buttons remain separate');

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/Libraries/Meta_cloud_client.php');
$controller = file_get_contents($root . '/Controllers/Conversations.php');
$route = file_get_contents($root . '/Config/Routes.php');
$js = file_get_contents($root . '/Assets/js/chatwoot.js');
$pickerJs = file_get_contents($root . '/Assets/js/inbox/template_picker.js');
$mediaController = file_get_contents($root . '/Controllers/Media.php');
$mediaService = file_get_contents($root . '/Services/Media_service.php');
$chatService = file_get_contents($root . '/Services/Chat_service.php');
$assert(str_contains($client, 'listTemplatesPage') && str_contains($client, "'after'") && str_contains($client, 'message_templates'), 'Meta template client supports bounded cursor pagination');
$assert(str_contains($controller, 'send_template_by_id') && !str_contains($controller, "input['template_name']") && !str_contains($controller, "input['components']"), 'conversation template endpoint never trusts browser template definitions');
$assert(str_contains($route, "api/conversations/(:num)/templates', 'Conversations::templates") && str_contains($route, "api/conversations/(:num)/templates/sync"), 'conversation template listing and deliberate refresh routes are additive');
$assert(str_contains($js, 'service_window') && str_contains($pickerJs, 'data-template-send') && str_contains($pickerJs, 'template_id'), 'frontend uses capability/window DTO and server-side template selection');
$assert(!str_contains($js, 'Legacy inline template picker') && !str_contains($js, 'legacy_loadConversationTemplates') && !str_contains($js, 'data-template-media-link'), 'legacy inline picker block and raw media inputs are removed from the workspace');
$assert(str_contains($js, 'scheduleServiceWindowTimer') && str_contains($js, 'reconcileServiceWindowError') && substr_count($js, 'state.activeConversationId =') === 1, 'frontend owns one canonical active-conversation transition and one expiry timer');
$assert(str_contains($pickerJs, 'clientMessageId') && str_contains($pickerJs, 'fingerprint') && str_contains($pickerJs, 'TEMPLATE_MEDIA') === false && str_contains($pickerJs, 'local_media_id') && str_contains($pickerJs, 'data-template-media-clear'), 'template picker keeps logical idempotency and local media isolation');
$assert(str_contains($pickerJs, 'attemptsByFingerprint') && str_contains($pickerJs, 'formsByTemplateId') && str_contains($pickerJs, 'formRevision') && !str_contains($pickerJs, 'session.generation = Number(session.generation || 0) + 1'), 'template picker finalizes per-fingerprint attempts, per-template forms and stable session generations');
$assert(str_contains($pickerJs, 'manageInstances') && str_contains($pickerJs, 'Última sincronização') && str_contains($pickerJs, "event.key === 'Escape'"), 'template refresh permission, sync metadata and keyboard dismissal are explicit');
$assert(str_contains($chatService, "'request_hash' => \$requestHash") && str_contains($chatService, 'find_by_client_message_id($conversationId, $clientMessageId)') && str_contains($chatService, 'TEMPLATE_DEFINITION_CHANGED'), 'template idempotency lookup precedes current gates and retries pin the definition snapshot');
$assert(str_contains($mediaController, 'Message_send_exception') && str_contains($mediaService, "'details' => \$details"), 'single and batch media errors preserve structured details');
$assert(str_contains($route, '/templates/media') && str_contains($mediaService, 'findOwnedTemplateMedia'), 'template media upload is additive and revalidates conversation ownership');

$capturedPayloads = [];
$metaClient = new Meta_cloud_client([
    'phone_number_id' => 'phone-1', 'waba_id' => 'waba-1', 'access_token' => 'access-token', 'app_secret' => 'app-secret', 'graph_version' => 'v25.0',
], static function (string $method, string $url, array $headers, string $body) use (&$capturedPayloads): array {
    $capturedPayloads[] = json_decode($body, true);
    return ['status_code' => 200, 'body' => '{"messages":[{"id":"provider-message"}]}', 'error' => false];
});
foreach ([
    'image' => ['link' => 'https://cdn.example.test/image.jpg'],
    'video' => ['id' => 'meta-video-id'],
    'document' => ['link' => 'https://cdn.example.test/document.pdf'],
] as $kind => $reference) {
    $metaClient->sendTemplate('5511999999999', 'pedido', 'pt_BR', [[
        'type' => 'header',
        'parameters' => [['type' => $kind, $kind => $reference + ['local_media_id' => 91, 'mime_type' => 'application/octet-stream', 'storage_path' => 'private/91', 'preview_url' => 'http://internal.test/media/91']]],
    ]]);
    $final = json_encode($capturedPayloads[array_key_last($capturedPayloads)], JSON_UNESCAPED_SLASHES);
    $node = $capturedPayloads[array_key_last($capturedPayloads)]['template']['components'][0]['parameters'][0][$kind] ?? [];
    $assert(isset($node['link']) || isset($node['id']), 'Meta final template payload contains a link or id for ' . $kind);
    $assert(!str_contains($final, 'local_media_id') && !str_contains($final, 'mime_type') && !str_contains($final, 'storage_path') && !str_contains($final, 'preview_url') && !str_contains($final, 'internal.test') && !str_contains($final, 'access-token'), 'Meta final template payload excludes local metadata and secrets for ' . $kind);
}

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL); exit(1); }
