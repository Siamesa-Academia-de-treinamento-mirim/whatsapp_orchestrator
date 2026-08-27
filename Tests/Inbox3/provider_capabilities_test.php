<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Providers/Provider_capabilities.php';

use Chatwoot_plugin\Providers\Provider_capabilities;

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

$evolution = Provider_capabilities::evolution();
$meta = Provider_capabilities::metaCloud();
$unknown = Provider_capabilities::forProvider('future_provider');
$same(2, $evolution['contract_version'], 'Evolution capability document is versioned');
$same(2, $meta['contract_version'], 'Meta capability document is versioned');
$same(array_keys($evolution), array_keys($meta), 'Evolution and Meta have the same top-level capability schema');
$same(array_keys($evolution['conversation']), array_keys($meta['conversation']), 'conversation capability schema is shared');
$same(array_keys($evolution['actions']), array_keys($meta['actions']), 'action capability schema is shared');
$same(array_keys($evolution['events']), array_keys($meta['events']), 'received-event capability schema is shared');
$same(array_keys($evolution['events']['receive']), array_keys($meta['events']['receive']), 'received-event names are provider-neutral');
$same(array_keys($evolution['reaction']), array_keys($meta['reaction']), 'reaction policy schema is provider-neutral');
$same(array_keys($evolution['media']), array_keys($meta['media']), 'media kind schema is shared');
foreach (array_keys($evolution['media']) as $kind) {
    $same(array_keys($evolution['media'][$kind]), array_keys($meta['media'][$kind]), "{$kind} media policy schema is shared");
}

$assert($evolution['conversation']['groups'] === true && $meta['conversation']['groups'] === false, 'group support differs by provider value');
$assert($evolution['actions']['send_template'] === false && $meta['actions']['send_template'] === true, 'template send action differs by provider value');
$assert($evolution['actions']['react'] === true && $meta['actions']['react'] === true, 'reaction send action is enabled only with both provider adapters implemented');
$assert($evolution['reaction']['enabled'] === true && $evolution['reaction']['groups'] === true, 'Evolution reaction policy enables groups');
$assert($meta['reaction']['enabled'] === true && $meta['reaction']['groups'] === false && $meta['reaction']['max_target_age_seconds'] === 2592000, 'Meta reaction policy blocks groups and enforces 30-day target age');
$assert($evolution['actions']['mark_read'] === false && $meta['actions']['mark_read'] === false, 'provider mark-read action is disabled without an outbound implementation');
$assert($evolution['events']['receive']['reactions'] === true && $meta['events']['receive']['reactions'] === true, 'incoming reaction recognition is separate from reaction sending');
$assert($evolution['events']['receive']['read_status'] === true && $meta['events']['receive']['read_status'] === true, 'incoming read receipts are separate from mark-read sending');
$assert($evolution['media']['audio']['caption'] === true && $meta['media']['audio']['caption'] === false, 'audio caption policy differs by provider value');
$assert($evolution['media']['audio']['accepted_mime_types'] !== $meta['media']['audio']['accepted_mime_types'], 'audio MIME policy differs by provider value');
$assert($meta['media']['audio']['requires_recording_conversion'] === true && $meta['media']['audio']['recording_target'] !== null, 'Meta recording conversion is scoped to audio');
foreach (['image', 'video', 'document', 'sticker'] as $kind) {
    $assert($meta['media'][$kind]['requires_recording_conversion'] === false && $meta['media'][$kind]['recording_target'] === null, "Meta {$kind} policy does not advertise audio conversion");
}
$assert(count(array_unique(array_column($evolution['media'], 'max_bytes'))) > 1, 'Evolution media limits are category-specific');
$assert(count(array_unique(array_column($meta['media'], 'max_bytes'))) > 1, 'Meta media limits are category-specific');

foreach (['supports_groups', 'supports_templates', 'supports_freeform_messages', 'supports_freeform_outside_window', 'supports_media', 'supports_message_status', 'supports_read_status', 'supports_reactions', 'groups', 'templates', 'reactions', 'official'] as $alias) {
    $assert(array_key_exists($alias, $evolution) && array_key_exists($alias, $meta), "legacy capability alias remains: {$alias}");
}
$assert(array_key_exists('media', $evolution['legacy_aliases']) && is_bool($evolution['legacy_aliases']['media']), 'conflicting legacy media alias is retained under legacy_aliases');

$serialized = strtolower((string) json_encode([$evolution, $meta, $unknown], JSON_THROW_ON_ERROR));
foreach (['api_key', 'access_token', 'app_secret', 'webhook_secret', 'credential_ciphertext'] as $secretField) {
    $assert(!str_contains($serialized, $secretField), "capability payload has no secret field: {$secretField}");
}
$assert($meta['media']['sticker']['enabled'] === false && $meta['media']['sticker']['accepted_mime_types'] === [], 'disabled Meta sticker policy fails closed');
$assert($unknown['actions']['send_text'] === false && $unknown['events']['receive']['read_status'] === false && $unknown['media']['image']['enabled'] === false && $unknown['freeform_outside_window'] === false, 'unknown provider capability fails closed');
$same('unknown', $unknown['provider'], 'unknown provider is not masqueraded as Evolution');

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL);
    exit(1);
}
