<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Services/Message_projection_service.php';
require_once dirname(__DIR__, 2) . '/Services/Webhook_normalizer.php';
require_once dirname(__DIR__, 2) . '/Services/Meta_webhook_normalizer.php';

use Chatwoot_plugin\Services\Message_projection_service;
use Chatwoot_plugin\Services\Meta_webhook_normalizer;
use Chatwoot_plugin\Services\Webhook_normalizer;

$passed = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$passed, &$failures): void {
    if ($condition) {
        ++$passed;
        echo "[OK] {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};
$same = static function ($expected, $actual, string $message) use ($assert): void {
    $assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
};

$projector = new Message_projection_service();
$base = static function (string $type, array $extra = []): array {
    return array_merge([
        'id' => 10,
        'conversation_id' => 20,
        'instance_id' => 30,
        'external_message_id' => 'provider-10',
        'client_message_id' => 'client-10',
        'remote_jid' => '5511999999999@s.whatsapp.net',
        'direction' => 'incoming',
        'message_type' => $type,
        'text_content' => '',
        'media_url' => '/media/message/10',
        'mime_type' => null,
        'caption' => '',
        'file_name' => '',
        'file_size' => 0,
        'sender_jid' => '5511999999999@s.whatsapp.net',
        'sender_phone' => '5511999999999',
        'sender_name' => 'Maria',
        'sender_contact_id' => 88,
        'provider_name' => 'evolution',
        'status' => 'received',
        'created_at' => '2026-08-16 12:00:00',
        'sent_at' => '2026-08-16 12:00:00',
        'raw_payload' => json_encode(['_normalized' => ['message_type' => $type]], JSON_THROW_ON_ERROR),
    ], $extra);
};

$text = $projector->project($base('text', ['text_content' => 'Olá inbox']));
$same(2, $text['contract_version'], 'text projection has V2 contract version');
$same('text', $text['type'], 'text message projects as text');
$same('Olá inbox', $text['content']['text'], 'text content is preserved');

foreach ([
    ['image', 'image/jpeg'],
    ['audio', 'audio/ogg'],
    ['video', 'video/mp4'],
    ['document', 'application/pdf'],
] as [$type, $mime]) {
    $message = $projector->project($base($type, [
        'mime_type' => $mime,
        'file_name' => $type . '.bin',
        'file_size' => 123,
        'caption' => 'Legenda',
        'raw_payload' => json_encode(['_normalized' => [
            'message_type' => $type,
            'structured_content' => ['attachment' => ['kind' => $type, 'mime_type' => $mime, 'file_name' => $type . '.bin']],
        ]], JSON_THROW_ON_ERROR),
    ]));
    $same($type, $message['type'], $type . ' projection keeps its explicit type');
    $same($mime, $message['content']['attachments'][0]['mime_type'], $type . ' attachment keeps MIME');
    $same('Legenda', $message['content']['caption'], $type . ' caption is projected');
}

$voice = $projector->project($base('audio', [
    'raw_payload' => json_encode(['_normalized' => [
        'message_type' => 'audio',
        'structured_content' => ['attachment' => ['kind' => 'audio', 'is_voice_note' => true]],
    ]], JSON_THROW_ON_ERROR),
]));
$same('voice', $voice['type'], 'voice-note flag produces the distinct voice type');
$assert($voice['content']['attachments'][0]['is_voice_note'] === true, 'voice-note flag is retained on attachment');

$structuredCases = [
    ['sticker', ['attachment' => ['kind' => 'sticker', 'mime_type' => 'image/webp']], 'content.attachments.0.kind', 'sticker'],
    ['location', ['location' => ['latitude' => -23.55, 'longitude' => -46.63, 'name' => 'Loja', 'address' => 'Rua A']], 'content.location.name', 'Loja'],
    ['contact', ['contact' => ['display_name' => 'João', 'phones' => ['+5511'], 'emails' => ['joao@example.test']]], 'content.contact.display_name', 'João'],
    ['reaction', ['reaction' => ['emoji' => '❤️', 'message_id' => 'provider-target']], 'content.reaction.emoji', '❤️'],
    ['template', ['template' => ['name' => 'boas_vindas', 'language' => 'pt_BR', 'resolved_parameters' => [['text' => 'Maria']]]], 'content.template.name', 'boas_vindas'],
    ['interactive', ['interactive' => ['kind' => 'button', 'id' => 'yes', 'label' => 'Sim']], 'content.interactive.id', 'yes'],
];
foreach ($structuredCases as [$type, $content, $path, $expected]) {
    $message = $projector->project($base($type, [
        'raw_payload' => json_encode(['_normalized' => ['message_type' => $type, 'structured_content' => $content]], JSON_THROW_ON_ERROR),
    ]));
    $parts = explode('.', $path);
    $actual = $message['content'];
    foreach (array_slice($parts, 1) as $part) $actual = $actual[$part];
    $same($type, $message['type'], $type . ' remains explicit instead of flattening to text');
    $same($expected, $actual, $type . ' structured fields are projected');
}

$unsupported = $projector->project($base('provider_new_type', [
    'text_content' => 'preview seguro',
    'raw_payload' => json_encode(['_normalized' => [
        'message_type' => 'unsupported',
        'raw_provider_type' => '<new/provider:type>',
    ]], JSON_THROW_ON_ERROR),
]));
$same('unsupported', $unsupported['type'], 'unknown message type becomes unsupported');
$same('new_provider:type', $unsupported['metadata']['safe_type_hint'], 'unsupported type hint is sanitized');
$same('preview seguro', $unsupported['content']['text'], 'unsupported message keeps a safe preview');

$legacyKeys = ['message_type', 'text_content', 'media_url', 'mime_type', 'caption', 'file_name', 'file_size', 'external_message_id', 'sender_name', 'sender_phone', 'is_internal_note'];
foreach ($legacyKeys as $key) $assert(array_key_exists($key, $text), "legacy key remains available: {$key}");

$replyTarget = $base('text', ['id' => 99, 'external_message_id' => 'provider-target', 'text_content' => 'Você abre sábado?', 'sender_name' => 'Maria']);
$replied = $projector->project($base('text', ['reply_to_external_message_id' => 'provider-target']), $replyTarget);
$same(99, $replied['reply_to']['message_id'], 'resolvable reply reference contains local message id');
$same('Você abre sábado?', $replied['reply_to']['preview'], 'resolvable reply reference contains preview');
$assert($replied['reply_to']['resolved'] === true, 'resolvable reply reference is marked resolved');
$unresolved = $projector->project($base('text', ['reply_to_external_message_id' => 'provider-missing']));
$same(null, $unresolved['reply_to']['message_id'], 'unresolved reply keeps no invented local id');
$assert($unresolved['reply_to']['resolved'] === false, 'unresolved reply is explicitly marked unresolved');

$meta = new Meta_webhook_normalizer();
$metaEvents = $meta->expand([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => '12345'],
                'messages' => [
                    ['from' => '5511', 'id' => 'm-interactive', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes', 'title' => 'Sim']]],
                    ['from' => '5511', 'id' => 'm-unknown', 'type' => 'future_provider_type', 'future_provider_type' => ['value' => 'x']],
                ],
            ],
        ]],
    ]],
], 'instance');
$same('interactive', $metaEvents[0]['message_type'], 'Meta interactive normalization stays structured');
$same('yes', $metaEvents[0]['structured_content']['interactive']['id'], 'Meta interactive id is retained');
$same('unsupported', $metaEvents[1]['message_type'], 'Meta unknown type normalizes to unsupported');

$evolution = (new Webhook_normalizer())->normalize([
    'event' => 'messages.upsert',
    'instance' => 'store',
    'data' => [
        'key' => ['id' => 'm-location', 'remoteJid' => '5511@s.whatsapp.net', 'fromMe' => false],
        'message' => ['locationMessage' => ['degreesLatitude' => -23.5, 'degreesLongitude' => -46.6, 'name' => 'Loja', 'address' => 'Rua A']],
    ],
]);
$same('location', $evolution['message_type'], 'Evolution location normalization stays structured');
$same(-23.5, $evolution['structured_content']['location']['latitude'], 'Evolution location latitude is retained');

// Provider-shaped Evolution fixtures exercise normalization and projection
// together. This prevents a row that was already manually classified as
// video from masking a provider-node regression.
$evolutionFixture = static function (string $id, array $message): array {
    return [
        'event' => 'messages.upsert',
        'instance' => 'store',
        'data' => [
            'key' => ['id' => $id, 'remoteJid' => '5511999999999@s.whatsapp.net', 'fromMe' => false],
            'pushName' => 'Maria',
            'message' => $message,
            'messageTimestamp' => 1776254400,
        ],
    ];
};
$evolutionMatrix = [
    'text' => ['message' => ['conversation' => 'Olá']],
    'image' => ['message' => ['imageMessage' => ['url' => 'https://cdn.example.test/image.jpg', 'mimetype' => 'image/jpeg', 'caption' => 'Foto']]],
    'audio' => ['message' => ['audioMessage' => ['url' => 'https://cdn.example.test/audio.ogg', 'mimetype' => 'audio/ogg', 'seconds' => 4]]],
    'voice' => ['message' => ['audioMessage' => ['url' => 'https://cdn.example.test/voice.ogg', 'mimetype' => 'audio/ogg', 'ptt' => true]]],
    'video' => ['message' => ['videoMessage' => ['url' => 'https://cdn.example.test/video.mp4', 'mimetype' => 'video/mp4', 'caption' => 'Vídeo', 'seconds' => 8]]],
    'document' => ['message' => ['documentMessage' => ['url' => 'https://cdn.example.test/guide.pdf', 'mimetype' => 'application/pdf', 'fileName' => 'guide.pdf', 'caption' => 'Guia']]],
    'sticker' => ['message' => ['stickerMessage' => ['url' => 'https://cdn.example.test/sticker.webp', 'mimetype' => 'image/webp']]],
    'location' => ['message' => ['locationMessage' => ['degreesLatitude' => -23.55, 'degreesLongitude' => -46.63, 'name' => 'Loja', 'address' => 'Rua A', 'contextInfo' => ['stanzaId' => 'quoted-location']]]],
    'contact' => ['message' => ['contactMessage' => ['displayName' => 'João', 'vcard' => "BEGIN:VCARD\nFN:João\nTEL:+5511999999999\nEND:VCARD", 'contextInfo' => ['stanzaId' => 'quoted-contact']]]],
    'reaction' => ['message' => ['reactionMessage' => ['text' => '❤️', 'key' => ['id' => 'target-message']]]],
    'interactive' => ['message' => ['buttonsResponseMessage' => ['selectedButtonId' => 'yes', 'selectedDisplayText' => 'Sim', 'contextInfo' => ['stanzaId' => 'quoted-interactive']]]],
    'unknown' => ['message' => ['futureMessage' => ['opaque' => 'provider extension']]],
];
$expectedEvolutionTypes = [
    'text' => 'text', 'image' => 'image', 'audio' => 'audio', 'voice' => 'audio',
    'video' => 'video', 'document' => 'document', 'sticker' => 'sticker',
    'location' => 'location', 'contact' => 'contact', 'reaction' => 'reaction',
    'interactive' => 'interactive', 'unknown' => 'unknown',
];
$matrixId = 100;
foreach ($evolutionMatrix as $label => $fixture) {
    $normalized = (new Webhook_normalizer())->normalize($evolutionFixture('matrix-' . $label, $fixture['message']));
    $same($expectedEvolutionTypes[$label], $normalized['message_type'], "Evolution {$label} realistic payload normalizes to its provider type");
    $row = [
        'id' => ++$matrixId,
        'conversation_id' => 20,
        'instance_id' => 30,
        'external_message_id' => 'matrix-' . $label,
        'direction' => 'incoming',
        'message_type' => $normalized['message_type'],
        'text_content' => $normalized['text'],
        'media_url' => $normalized['media_url'],
        'mime_type' => $normalized['mime_type'],
        'file_name' => $normalized['file_name'],
        'provider_name' => 'evolution',
        'status' => 'received',
        'created_at' => '2026-08-16 12:00:00',
        'sent_at' => '2026-08-16 12:00:00',
        'raw_payload' => json_encode(['_normalized' => $normalized], JSON_THROW_ON_ERROR),
    ];
    $projected = $projector->project($row);
    $same($label === 'voice' ? 'voice' : ($label === 'unknown' ? 'unsupported' : $expectedEvolutionTypes[$label]), $projected['type'], "Evolution {$label} projects to the canonical V2 type");
    if (in_array($label, ['image', 'audio', 'voice', 'video', 'document', 'sticker'], true)) {
        $assert(($projected['content']['attachments'][0]['mime_type'] ?? null) === $normalized['mime_type'], "Evolution {$label} preserves normalized media MIME");
        $assert(($projected['content']['attachments'][0]['url'] ?? null) === $normalized['media_url'], "Evolution {$label} preserves normalized media URL");
    }
    if ($label === 'video') {
        $same('video/mp4', $normalized['mime_type'], 'Evolution video provider payload keeps video/mp4 MIME');
        $same('video', $normalized['structured_content']['attachment']['kind'], 'Evolution video provider payload keeps structured video attachment');
        $same('Vídeo', $projected['content']['caption'], 'Evolution video provider payload keeps caption');
    }
    if ($label === 'location') $same('quoted-location', $normalized['reply_to_external_message_id'], 'Evolution location reply context is normalized');
    if ($label === 'contact') $same('quoted-contact', $normalized['reply_to_external_message_id'], 'Evolution contact reply context is normalized');
    if ($label === 'interactive') $same('quoted-interactive', $normalized['reply_to_external_message_id'], 'Evolution interactive reply context is normalized');
    if ($label === 'reaction') $same('target-message', $projected['content']['reaction']['target_provider_message_id'], 'Evolution reaction target is preserved');
}

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL);
    exit(1);
}
