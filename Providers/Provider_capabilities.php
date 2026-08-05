<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Providers;

final class Provider_capabilities
{
    /** @return array<string,bool> */
    public static function evolution(): array
    {
        return self::withAliases([
            'supports_groups' => true,
            'supports_templates' => false,
            'supports_freeform_messages' => true,
            'supports_freeform_outside_window' => true,
            'supports_media' => true,
            'supports_message_status' => true,
            'supports_read_status' => true,
            'supports_reactions' => true,
            'official' => false,
        ]);
    }

    /** @return array<string,bool> */
    public static function metaCloud(): array
    {
        return self::withAliases([
            'supports_groups' => false,
            'supports_templates' => true,
            'supports_freeform_messages' => true,
            'supports_freeform_outside_window' => false,
            'supports_media' => true,
            'supports_message_status' => true,
            'supports_read_status' => true,
            'supports_reactions' => false,
            'official' => true,
        ]);
    }

    /**
     * Keep short aliases for older UI/API consumers while application services
     * use the explicit supports_* keys.
     *
     * @param array<string,bool> $capabilities
     * @return array<string,bool>
     */
    private static function withAliases(array $capabilities): array
    {
        foreach ($capabilities as $key => $value) {
            if (str_starts_with($key, 'supports_')) {
                $capabilities[substr($key, 9)] = $value;
            }
        }

        return $capabilities;
    }
}
