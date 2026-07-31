<?php

defined('PLUGINPATH') or exit('No direct script access allowed');

/*
Plugin Name: Impulso Hub Atendimento
Description: Front nativo de atendimento omnichannel para o Rise CRM, inspirado no ImpulsoHub/Chatwoot.
Version: 1.1.0
Requires at least: 3.9.6
*/

if (defined('CHATWOOT_PLUGIN_LOADED')) {
    return;
}

define('CHATWOOT_PLUGIN_LOADED', true);

if (function_exists('service')) {
    try {
        \Config\Services::autoloader()->addNamespace('Chatwoot_plugin', __DIR__);
    } catch (\Throwable $exception) {
        log_message('error', 'Chatwoot_plugin namespace registration failed ({exception_type}).', [
            'exception_type' => get_class($exception),
        ]);
    }
}

require_once __DIR__ . '/Libraries/Chat_permissions.php';

use Chatwoot_plugin\Libraries\Chat_permissions;

if (!function_exists('chatwoot_plugin_install_or_update')) {
    function chatwoot_plugin_install_or_update($purchase_code = null): void
    {
        unset($purchase_code);

        try {
            $runner_path = __DIR__ . '/Libraries/Migration_runner.php';
            if (!is_file($runner_path)) {
                throw new \RuntimeException('Migration runner is unavailable.');
            }

            require_once $runner_path;

            $runner = new \Chatwoot_plugin\Libraries\Migration_runner();
            if (method_exists($runner, 'run')) {
                if ($runner->run() === false) {
                    throw new \RuntimeException('Migration runner reported failure.');
                }
            } elseif (method_exists($runner, 'migrate')) {
                $runner->migrate();
            } else {
                throw new \RuntimeException('Migration runner has no supported entrypoint.');
            }
        } catch (\Throwable $exception) {
            log_message('error', 'Chatwoot_plugin lifecycle migration failed ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);

            throw new \RuntimeException(
                'Nao foi possivel preparar o banco de dados do Impulso Hub Atendimento.',
                0,
                $exception
            );
        }
    }
}

if (!function_exists('impulso_hub_sections')) {
    function impulso_hub_sections(): array
    {
        return [
            'dashboard' => ['name' => 'Atendimento', 'class' => 'message-circle'],
            'conversations' => ['name' => 'Conversas', 'class' => 'inbox'],
            'contacts' => ['name' => 'Contatos', 'class' => 'users'],
            'instances' => ['name' => 'Instancias', 'class' => 'smartphone'],
            'campaigns' => ['name' => 'Campanhas', 'class' => 'send'],
            'ai' => ['name' => 'IA e Automacoes', 'class' => 'cpu'],
            'reports' => ['name' => 'Relatorios', 'class' => 'bar-chart-2'],
            'settings' => ['name' => 'Configuracoes', 'class' => 'settings'],
        ];
    }
}

if (!function_exists('impulso_hub_left_menu_items')) {
    function impulso_hub_left_menu_items(): array
    {
        $user = Chat_permissions::currentUser();
        if (!$user || !Chat_permissions::can($user, Chat_permissions::ACCESS)) {
            return [];
        }

        $submenu = [];
        $plugin_is_active = false;
        $section_permissions = [
            'contacts' => Chat_permissions::MANAGE_CONTACTS,
            'instances' => Chat_permissions::MANAGE_INSTANCES,
            'campaigns' => Chat_permissions::MANAGE_CAMPAIGNS,
            'ai' => Chat_permissions::MANAGE_AI,
            'reports' => Chat_permissions::VIEW_REPORTS,
            'settings' => Chat_permissions::MANAGE_SETTINGS,
        ];

        if (function_exists('uri_string') && strpos((string) uri_string(), 'chatwoot_plugin') === 0) {
            $plugin_is_active = true;
        }

        foreach (impulso_hub_sections() as $key => $item) {
            if (isset($section_permissions[$key]) && !Chat_permissions::can($user, $section_permissions[$key])) {
                continue;
            }

            $submenu['impulso_hub_' . $key] = [
                'name' => $item['name'],
                'url' => get_uri('chatwoot_plugin?chatwoot_tab=' . $key),
                'is_custom_menu_item' => true,
                'class' => $item['class'],
            ];
        }

        $menu = [
            'name' => 'Impulso Hub',
            'url' => get_uri('chatwoot_plugin'),
            'is_custom_menu_item' => true,
            'class' => 'message-square',
            'position' => 3,
            'submenu' => $submenu,
        ];

        if ($plugin_is_active) {
            $menu['is_active_menu'] = 1;
        }

        return ['impulso_hub' => $menu];
    }
}

app_hooks()->add_filter('app_filter_staff_left_menu', static function ($sidebar_menu) {
    foreach (impulso_hub_left_menu_items() as $key => $item) {
        $sidebar_menu[$key] = $item;
    }

    return $sidebar_menu;
});

app_hooks()->add_action('app_hook_role_permissions_extension', static function (): void {
    echo view('Chatwoot_plugin\Views\permissions', [
        'definitions' => Chat_permissions::definitions(),
        'permissions' => Chat_permissions::currentRolePermissions(),
    ]);
});

app_hooks()->add_filter('app_filter_role_permissions_save_data', static function ($permissions): array {
    return Chat_permissions::applyToSaveData(is_array($permissions) ? $permissions : []);
});

if (function_exists('register_installation_hook')) {
    register_installation_hook('Chatwoot_plugin', 'chatwoot_plugin_install_or_update');
    register_activation_hook('Chatwoot_plugin', 'chatwoot_plugin_install_or_update');
    register_update_hook('Chatwoot_plugin', 'chatwoot_plugin_install_or_update');
}

if (function_exists('service')) {
    require_once __DIR__ . '/Config/Routes.php';
}
