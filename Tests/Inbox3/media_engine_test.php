<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $offset) : substr($value, $offset, $length); }
}

require_once dirname(__DIR__, 2) . '/Services/Payload_sanitizer.php';
require_once dirname(__DIR__, 2) . '/Services/Media_policy_service.php';
require_once dirname(__DIR__, 2) . '/Services/Media_conversion_service.php';
require_once dirname(__DIR__, 2) . '/Providers/Provider_capabilities.php';
require_once dirname(__DIR__, 2) . '/Contracts/WhatsAppProviderInterface.php';
require_once dirname(__DIR__, 2) . '/Libraries/Evolution_client.php';
require_once dirname(__DIR__, 2) . '/Libraries/Meta_cloud_client.php';
require_once dirname(__DIR__, 2) . '/Services/Webhook_normalizer.php';
require_once dirname(__DIR__, 2) . '/Services/Meta_webhook_normalizer.php';
require_once dirname(__DIR__, 2) . '/Providers/Evolution_provider.php';
require_once dirname(__DIR__, 2) . '/Providers/Meta_cloud_provider.php';

use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Libraries\Meta_cloud_client;
use Chatwoot_plugin\Providers\Evolution_provider;
use Chatwoot_plugin\Providers\Meta_cloud_provider;
use Chatwoot_plugin\Providers\Provider_capabilities;
use Chatwoot_plugin\Services\Media_conversion_service;
use Chatwoot_plugin\Services\Media_engine_exception;
use Chatwoot_plugin\Services\Media_policy_service;

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
$throws = static function (callable $callback): bool {
    try { $callback(); } catch (Throwable $exception) { return true; }
    return false;
};
$tmp = static function (string $contents): string {
    $path = tempnam(sys_get_temp_dir(), 'rise-media-test-');
    if ($path === false || file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('temporary fixture failed');
    return $path;
};

$policy = new Media_policy_service();
$evolution = Provider_capabilities::evolution();
$meta = Provider_capabilities::metaCloud();

foreach ([$evolution, $meta] as $capabilities) {
    foreach (['image', 'audio', 'video', 'document'] as $kind) {
        $row = $capabilities['media'][$kind];
        $assert(!empty($row['enabled']), $capabilities['provider'] . ' enables ' . $kind . ' through policy');
        $assert((int) $row['max_bytes'] > 0, $capabilities['provider'] . ' publishes a positive ' . $kind . ' limit');
        $assert((array) $row['accepted_mime_types'] !== [], $capabilities['provider'] . ' publishes output MIME policy for ' . $kind);
    }
}
$assert(in_array('audio/webm', $meta['media']['audio']['recording_input_mime_types'], true), 'Meta accepts WebM only as declared recording input');
$assert(!in_array('audio/webm', $meta['media']['audio']['accepted_mime_types'], true), 'Meta never advertises audio/webm as an output MIME');
$assert($meta['media']['document']['max_bytes'] === 100 * 1024 * 1024, 'Meta document limit is 100 MB');
$assert($meta['media']['audio']['caption'] === false, 'Meta audio caption support is disabled');
$assert($meta['media']['audio']['recording_target'] === 'audio/ogg; codecs=opus', 'Meta recording target is explicit OGG/Opus');
$assert($meta['media']['audio']['requires_opus_codec'] === true && $meta['media']['audio']['voice_note_requires_mono'] === true, 'Meta advertises codec and mono restrictions as policy');
$assert($meta['media']['audio']['requires_https_link'] === true, 'Meta media transport requires HTTPS');
$assert($meta['media']['video']['requires_video_codec_validation'] === true && $meta['media']['video']['video_codecs'] === ['h264'] && $meta['media']['video']['video_audio_codecs'] === ['aac'], 'Meta advertises H264/AAC video restrictions as policy');
$assert($evolution['media']['audio']['multiple_selection'] === true && $meta['media']['audio']['multiple_selection'] === true, 'both providers expose multiple attachment preparation');

foreach ([$evolution, $meta] as $capabilities) {
    foreach (['image', 'audio', 'video', 'document'] as $kind) {
        $mime = (string) $capabilities['media'][$kind]['accepted_mime_types'][0];
        $link = $capabilities['media'][$kind]['requires_https_link'] ? 'https://media.test/file' : 'http://media.test/file';
        $payload = ['type' => $kind, 'mime_type' => $mime, 'file_size' => 100, 'link' => $link];
        $assert($policy->validatePayload($payload, $capabilities)['type'] === $kind, $capabilities['provider'] . ' validates ' . $kind . ' payload through the common contract');
    }
}

$png = $tmp(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$pdf = $tmp("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
$text = $tmp("plain text fixture\n");
$assert(($policy->validatePath($png, 'spoofed.pdf', $meta, '', 'image')['kind'] ?? '') === 'image', 'server uses detected image MIME instead of filename extension');
$assert($throws(static fn () => $policy->validatePath($png, 'image.png', $meta, '', 'document')), 'requested document kind cannot disguise a real image');
$assert($throws(static fn () => $policy->validatePath($text, 'image.png', $meta, '', 'image')), 'real text MIME cannot pass as image');
$assert(($policy->validatePath($pdf, 'guide.pdf', $meta, 'Legenda', 'document')['mime_type'] ?? '') === 'application/pdf', 'Meta document validates real PDF MIME and caption');
$oversized = $tmp(str_repeat('x', 6 * 1024 * 1024));
$assert($throws(static fn () => $policy->validatePath($oversized, 'large.png', $meta, '', 'image')), 'Meta image over 5 MB is rejected before transport');
$assert($throws(static fn () => $policy->validatePath($pdf, 'guide.pdf', $meta, 'caption on audio', 'document', true)), 'voice flag cannot be applied to document');
$assert($throws(static fn () => $policy->validatePayload(['type' => 'audio', 'mime_type' => 'audio/mpeg', 'file_size' => 100, 'link' => 'https://media.test/a.mp3', 'caption' => 'x'], $meta)), 'Meta rejects audio caption server-side');
$assert($throws(static fn () => $policy->validatePayload(['type' => 'audio', 'mime_type' => 'audio/webm', 'file_size' => 100, 'link' => 'https://media.test/a.webm'], $meta)), 'Meta rejects WebM at provider boundary');
$assert($throws(static fn () => $policy->validatePayload(['type' => 'image', 'mime_type' => 'image/png', 'file_size' => 6 * 1024 * 1024, 'link' => 'https://media.test/a.png'], $meta)), 'Meta rejects oversized image payload at provider boundary');

$metaCalls = 0;
$metaClient = new Meta_cloud_client([
    'phone_number_id' => '123', 'access_token' => 'token', 'graph_version' => 'v25.0',
], static function () use (&$metaCalls): array { ++$metaCalls; return ['status_code' => 500, 'body' => '{"error":{"message":"provider down"}}']; });
$metaProvider = new Meta_cloud_provider($metaClient);
$metaFailure = $metaProvider->sendMedia('5511999999999', ['type' => 'image', 'mime_type' => 'image/png', 'file_size' => 100, 'link' => 'https://media.test/a.png']);
$assert($metaCalls === 1 && empty($metaFailure['success']), 'Meta provider failure is returned without masking the failure');
$beforeMetaReject = $metaCalls;
$assert($throws(static fn () => $metaProvider->sendMedia('5511999999999', ['type' => 'image', 'mime_type' => 'image/webp', 'file_size' => 100, 'link' => 'https://media.test/a.webp'])), 'Meta rejects unsupported image MIME');
$assert($metaCalls === $beforeMetaReject, 'rejected Meta image never reaches external transport');

$metaFinalBodies = [];
$metaPayloadClient = new Meta_cloud_client([
    'phone_number_id' => '123', 'access_token' => 'token', 'graph_version' => 'v25.0',
], static function ($method, $url, $headers, $body) use (&$metaFinalBodies): array {
    $metaFinalBodies[] = json_decode((string) $body, true);
    return ['status_code' => 200, 'body' => '{"messages":[{"id":"META-FINAL-1"}]}'];
});
$metaPayloadClient->sendMedia('5511999999999', ['type' => 'audio', 'link' => 'https://media.test/audio.ogg', 'voice_note' => false]);
$ordinaryAudioPayload = $metaFinalBodies[count($metaFinalBodies) - 1] ?? [];
$assert(empty($ordinaryAudioPayload['audio']['voice']), 'ordinary Meta audio final JSON does not contain voice=true');
$metaPayloadClient->sendMedia('5511999999999', ['type' => 'audio', 'link' => 'https://media.test/voice.ogg', 'voice_note' => true]);
$voiceLinkPayload = $metaFinalBodies[count($metaFinalBodies) - 1] ?? [];
$assert(($voiceLinkPayload['audio']['voice'] ?? false) === true, 'Meta voice note by link has audio.voice=true in final JSON');
$metaPayloadClient->sendMedia('5511999999999', ['type' => 'audio', 'id' => 'MEDIA-ID-1', 'voice_note' => true]);
$voiceIdPayload = $metaFinalBodies[count($metaFinalBodies) - 1] ?? [];
$assert(($voiceIdPayload['audio']['voice'] ?? false) === true && ($voiceIdPayload['audio']['id'] ?? '') === 'MEDIA-ID-1', 'Meta voice note by media id has audio.voice=true in final JSON');
foreach (['image' => 'image/png', 'video' => 'video/mp4', 'document' => 'application/pdf'] as $nonVoiceType => $nonVoiceMime) {
    $metaPayloadClient->sendMedia('5511999999999', ['type' => $nonVoiceType, 'link' => 'https://media.test/file', 'voice_note' => true]);
    $payload = $metaFinalBodies[count($metaFinalBodies) - 1] ?? [];
    $assert(!isset($payload[$nonVoiceType]['voice']), 'Meta ' . $nonVoiceType . ' final JSON never contains voice=true');
}

$evolutionCalls = [];
$evolutionClient = new Evolution_client(['instance' => ['evolution_instance_name' => 'store', 'base_url' => 'https://evolution.test', 'api_key' => 'key']], static function ($method, $url, $headers, $payload) use (&$evolutionCalls): array {
    $evolutionCalls[] = compact('method', 'url', 'headers', 'payload');
    return ['status_code' => 502, 'body' => '{"error":{"message":"provider down"}}'];
});
$evolutionProvider = new Evolution_provider($evolutionClient, ['evolution_instance_name' => 'store', 'base_url' => 'https://evolution.test', 'api_key' => 'key']);
$evolutionFailure = $evolutionProvider->sendMedia('5511999999999', ['type' => 'image', 'mime_type' => 'image/png', 'file_size' => 100, 'data' => base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true))]);
$assert(count($evolutionCalls) === 1 && empty($evolutionFailure['success']), 'Evolution provider failure is represented without throwing a duplicate');
$beforeEvolutionReject = count($evolutionCalls);
$assert($throws(static fn () => $evolutionProvider->sendMedia('5511999999999', ['type' => 'audio', 'mime_type' => 'audio/webm', 'file_size' => 100, 'data' => base64_encode('not-webm')])), 'Evolution rejects WebM unless it is a declared recording input');
$assert(count($evolutionCalls) === $beforeEvolutionReject, 'rejected Evolution media never reaches external transport');
$voice = $evolutionProvider->sendMedia('5511999999999', ['type' => 'audio', 'mime_type' => 'audio/ogg; codecs=opus', 'file_size' => 100, 'link' => 'https://media.test/voice.ogg', 'voice_note' => true]);
$assert(count($evolutionCalls) === $beforeEvolutionReject + 1, 'Evolution voice note uses its provider transport');
$assert(($evolutionCalls[count($evolutionCalls) - 1]['payload']['ptt'] ?? false) === true, 'Evolution voice note carries explicit PTT semantics');

$input = $tmp('not an audio recording');
$badConversion = new Media_conversion_service(static function (string $source, string $output): bool { file_put_contents($output, 'not an ogg file', LOCK_EX); return true; });
$badConversionCode = '';
try { $badConversion->toVoiceCompatible($input); } catch (Media_engine_exception $exception) { $badConversionCode = $exception->errorCode; }
$assert($badConversionCode === 'MEDIA_CONVERSION_OUTPUT_INVALID', 'conversion rejects a fake MIME rename with an output-invalid diagnostic');
$missingFfmpeg = new Media_conversion_service(null, 'rise-ffmpeg-does-not-exist');
$assert($missingFfmpeg->isAvailable() === false, 'conversion diagnostics fail closed when FFmpeg is unavailable');
$missingFfmpegCode = '';
try { $missingFfmpeg->toVoiceCompatible($input); } catch (Media_engine_exception $exception) { $missingFfmpegCode = $exception->errorCode; }
$assert($missingFfmpegCode === 'MEDIA_FFMPEG_MISSING', 'recording send distinguishes missing FFmpeg');
$missingFfprobe = new Media_conversion_service(null, 'rise-ffmpeg-does-not-exist', 'rise-ffprobe-does-not-exist');
$probeCode = '';
try { $missingFfprobe->assertProviderVideoCompatible($input, ['requires_video_codec_validation' => true, 'video_codecs' => ['h264'], 'video_audio_codecs' => ['aac']]); } catch (Media_engine_exception $exception) { $probeCode = $exception->errorCode; }
$assert($probeCode === 'MEDIA_FFPROBE_MISSING', 'video validation distinguishes missing FFprobe');
$assert($throws(static fn () => $missingFfmpeg->assertProviderVideoCompatible($input, ['requires_video_codec_validation' => true, 'video_codecs' => ['h264'], 'video_audio_codecs' => ['aac']])), 'Meta video send is blocked when codec validation is unavailable');

foreach ([$png, $pdf, $text, $oversized, $input] as $path) { @unlink($path); }

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL);
    exit(1);
}
