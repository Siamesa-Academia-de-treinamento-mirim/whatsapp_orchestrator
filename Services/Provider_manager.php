<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Contracts\WhatsAppProviderInterface;
use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Libraries\Meta_cloud_client;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Providers\Evolution_provider;
use Chatwoot_plugin\Providers\Meta_cloud_provider;
use RuntimeException;

class Provider_manager
{
    /** @var callable|null */
    private $evolutionFactory;
    /** @var callable|null */
    private $metaTransport;

    public function __construct(
        private ?Chat_instances_model $instances = null,
        private ?Chat_settings_model $settings = null,
        ?callable $evolutionFactory = null,
        ?callable $metaTransport = null
    ) {
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->evolutionFactory = $evolutionFactory;
        $this->metaTransport = $metaTransport;
    }

    public function forInstance(array $instance): WhatsAppProviderInterface
    {
        $provider = strtolower(trim((string) ($instance['provider_type'] ?? 'evolution')));
        if ($provider === 'meta_cloud') {
            $credentials = $this->instances->get_decrypted_meta_credentials((int) $instance['id']);
            return new Meta_cloud_provider(new Meta_cloud_client([
                'phone_number_id' => $instance['meta_phone_number_id'] ?? '',
                'waba_id' => $instance['meta_waba_id'] ?? '',
                'access_token' => $credentials['access_token'] ?? '',
                'app_secret' => $credentials['app_secret'] ?? '',
                'graph_version' => $instance['meta_graph_version'] ?? $this->settings->get_value('meta_graph_version', 'v25.0'),
                'timeout' => $this->settings->get_value('meta_timeout_seconds', 30),
            ], $this->metaTransport));
        }
        $client = $this->evolutionFactory
            ? call_user_func($this->evolutionFactory, $instance, $this->settings)
            : new Evolution_client(['instance' => $instance], null, $this->settings);
        if (!$client instanceof Evolution_client && !is_object($client)) throw new RuntimeException('Factory Evolution invalida.');
        return new Evolution_provider($client, $instance, $this->settings);
    }
}
