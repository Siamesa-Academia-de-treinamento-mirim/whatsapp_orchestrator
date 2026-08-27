<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Providers;

final class Provider_capabilities
{
    /** @return array<string,mixed> */
    public static function evolution(): array
    {
        $capabilities = self::withAliases(self::document(
            'evolution',
            false,
            [
                'groups' => true,
                'service_window' => false,
                'templates' => false,
            ],
            [
                'send_text' => true,
                'send_media' => true,
                'send_template' => false,
                // Both adapters translate the common reply context into the
                // provider's quoted-message payload.
                'reply' => true,
                // Evolution's sendReaction endpoint is implemented by the
                // provider adapter; incoming aggregation remains separate.
                'react' => true,
                'mark_read' => false,
                'delete_message' => false,
            ],
            self::evolutionMedia(),
            self::receivedEvents()
        ));
        $capabilities['reaction'] = [
            'enabled' => true,
            'groups' => true,
            'max_target_age_seconds' => null,
            'supports_remove' => true,
        ];
        return $capabilities;
    }

    /** @return array<string,mixed> */
    public static function metaCloud(): array
    {
        $capabilities = self::withAliases(self::document(
            'meta_cloud',
            true,
            [
                'groups' => false,
                'service_window' => true,
                'templates' => true,
            ],
            [
                'send_text' => true,
                'send_media' => true,
                'send_template' => true,
                // Meta Cloud accepts the common context.message_id shape for
                // quoted text and media sends.
                'reply' => true,
                // Meta Cloud reaction messages are implemented through the
                // common /PHONE_NUMBER_ID/messages endpoint.
                'react' => true,
                'mark_read' => false,
                'delete_message' => false,
            ],
            self::metaMedia(),
            self::receivedEvents()
        ));
        $capabilities['reaction'] = [
            'enabled' => true,
            'groups' => false,
            'max_target_age_seconds' => 2592000,
            'supports_remove' => true,
        ];
        return $capabilities;
    }

    /** @return array<string,mixed> */
    public static function forProvider(string $provider): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'meta_cloud') return self::metaCloud();
        if ($provider === 'evolution') return self::evolution();

        return self::unsupported();
    }

    /** @return array<string,mixed> */
    private static function document(
        string $provider,
        bool $official,
        array $conversation,
        array $actions,
        array $media,
        array $events
    ): array {
        return [
            'contract_version' => 2,
            'provider' => $provider,
            'official' => $official,
            'conversation' => $conversation,
            'actions' => $actions,
            'media' => $media,
            'events' => ['receive' => $events],
        ];
    }

    /** @return array<string,bool> */
    private static function receivedEvents(): array
    {
        return [
            'message_status' => true,
            'read_status' => true,
            'reactions' => true,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function evolutionMedia(): array
    {
        $audio = self::mediaPolicy(16 * 1024 * 1024, ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr'], ['audio/webm', 'video/webm'], true, true, true, 'audio/ogg; codecs=opus', true, 'base64');
        $audio['requires_opus_codec'] = false;
        $audio['voice_note_requires_mono'] = true;
        return [
            'image' => self::mediaPolicy(16 * 1024 * 1024, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], [], true, false, false, null, false, 'base64'),
            'audio' => $audio,
            'video' => self::mediaPolicy(32 * 1024 * 1024, ['video/mp4'], [], true, false, false, null, false, 'base64'),
            'document' => self::mediaPolicy(64 * 1024 * 1024, ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], [], true, false, false, null, false, 'base64'),
            'sticker' => self::mediaPolicy(16 * 1024 * 1024, ['image/webp'], [], false, false, false, null, false, 'base64'),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function metaMedia(): array
    {
        $audio = self::mediaPolicy(16 * 1024 * 1024, ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'], ['audio/webm', 'video/webm'], false, true, true, 'audio/ogg; codecs=opus', true, 'https');
        $audio['requires_opus_codec'] = true;
        $audio['voice_note_requires_mono'] = true;
        $video = array_merge(self::mediaPolicy(16 * 1024 * 1024, ['video/mp4', 'video/3gpp'], [], true, false, false, null, false, 'https'), [
            'requires_video_codec_validation' => true,
            'video_codecs' => ['h264'],
            'video_audio_codecs' => ['aac'],
        ]);
        return [
            'image' => self::mediaPolicy(5 * 1024 * 1024, ['image/jpeg', 'image/png'], [], true, false, false, null, false, 'https'),
            'audio' => $audio,
            'video' => $video,
            'document' => self::mediaPolicy(100 * 1024 * 1024, ['text/plain', 'application/pdf', 'application/vnd.ms-powerpoint', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], [], true, false, false, null, false, 'https'),
            'sticker' => self::mediaPolicy(0, [], [], false, false, false, null, false, 'https', false),
        ];
    }

    /** @return array<string,mixed> */
    private static function mediaPolicy(
        int $maxBytes,
        array $mimeTypes,
        array $recordingInputMimeTypes,
        bool $caption,
        bool $voiceNote,
        bool $requiresConversion,
        ?string $recordingTarget,
        bool $requiresRecordingConversion = false,
        string $transport = 'base64',
        bool $enabled = true
    ): array {
        return [
            'enabled' => $enabled,
            'accepted_mime_types' => $mimeTypes,
            'recording_input_mime_types' => $recordingInputMimeTypes,
            'max_bytes' => $maxBytes,
            'caption' => $caption,
            'caption_max_chars' => $caption ? 1024 : 0,
            'multiple_selection' => true,
            'voice_note' => $voiceNote,
            'requires_conversion' => $requiresConversion,
            'requires_recording_conversion' => $requiresRecordingConversion,
            'recording_target' => $recordingTarget,
            'transport' => $transport,
            'requires_https_link' => $transport === 'https',
            'requires_opus_codec' => false,
            'voice_note_requires_mono' => false,
            'requires_video_codec_validation' => false,
            'video_codecs' => [],
            'video_audio_codecs' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function unsupported(): array
    {
        $disabled = [
            'enabled' => false,
            'accepted_mime_types' => [],
            'recording_input_mime_types' => [],
            'max_bytes' => 0,
            'caption' => false,
            'caption_max_chars' => 0,
            'multiple_selection' => false,
            'voice_note' => false,
            'requires_conversion' => false,
            'requires_recording_conversion' => false,
            'recording_target' => null,
            'transport' => 'none',
            'requires_https_link' => false,
            'requires_opus_codec' => false,
            'voice_note_requires_mono' => false,
            'requires_video_codec_validation' => false,
            'video_codecs' => [],
            'video_audio_codecs' => [],
        ];

        $capabilities = self::withAliases(self::document(
            'unknown',
            false,
            ['groups' => false, 'service_window' => false, 'templates' => false],
            ['send_text' => false, 'send_media' => false, 'send_template' => false, 'reply' => false, 'react' => false, 'mark_read' => false, 'delete_message' => false],
            ['image' => $disabled, 'audio' => $disabled, 'video' => $disabled, 'document' => $disabled, 'sticker' => $disabled],
            ['message_status' => false, 'read_status' => false, 'reactions' => false]
        ));
        $capabilities['reaction'] = [
            'enabled' => false,
            'groups' => false,
            'max_target_age_seconds' => 0,
            'supports_remove' => false,
        ];
        return $capabilities;
    }

    /**
     * Keep short aliases for older UI/API consumers while application services
     * use the explicit supports_* keys.
     *
     * @param array<string,mixed> $capabilities
     * @return array<string,mixed>
     */
    private static function withAliases(array $capabilities): array
    {
        $aliases = [
            'supports_groups' => (bool) ($capabilities['conversation']['groups'] ?? false),
            'supports_templates' => (bool) ($capabilities['conversation']['templates'] ?? false),
            'supports_freeform_messages' => (bool) ($capabilities['actions']['send_text'] ?? false),
            'supports_freeform_outside_window' => ($capabilities['provider'] ?? '') !== 'unknown'
                && !(bool) ($capabilities['conversation']['service_window'] ?? false),
            'supports_media' => (bool) ($capabilities['actions']['send_media'] ?? false),
            'supports_message_status' => (bool) ($capabilities['events']['receive']['message_status'] ?? false),
            'supports_read_status' => (bool) ($capabilities['events']['receive']['read_status'] ?? false),
            'supports_reactions' => (bool) ($capabilities['actions']['react'] ?? false),
        ];
        foreach ($aliases as $key => $value) {
            $capabilities[$key] = $value;
            $short = substr($key, 9);
            // `media` is the canonical V2 policy object, so its historical
            // boolean alias cannot occupy the same top-level key. Keep it in
            // an explicit compatibility namespace while all other aliases
            // remain at their original location.
            if ($short === 'media' && is_array($capabilities['media'] ?? null)) {
                $capabilities['legacy_aliases']['media'] = $value;
                continue;
            }
            $capabilities[$short] = $value;
        }

        return $capabilities;
    }
}
