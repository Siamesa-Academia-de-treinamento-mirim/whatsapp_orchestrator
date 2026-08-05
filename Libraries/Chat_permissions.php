<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Libraries;

use Throwable;

final class Chat_permissions
{
    public const ACCESS = 'chatwoot_plugin_access';
    public const SEND = 'chatwoot_plugin_send';
    public const MANAGE_CONVERSATIONS = 'chatwoot_plugin_manage_conversations';
    public const MANAGE_CONTACTS = 'chatwoot_plugin_manage_contacts';
    public const MANAGE_INSTANCES = 'chatwoot_plugin_manage_instances';
    public const MANAGE_CAMPAIGNS = 'chatwoot_plugin_manage_campaigns';
    /** @deprecated Legacy permission kept only to read old role payloads. */
    public const MANAGE_AI = 'chatwoot_plugin_manage_ai';
    /** @deprecated Legacy permission kept only to read old role payloads. */
    public const VIEW_REPORTS = 'chatwoot_plugin_view_reports';
    /** @deprecated Legacy permission kept only to read old role payloads. */
    public const EXPORT_REPORTS = 'chatwoot_plugin_export_reports';
    public const MANAGE_BOTS = 'chatwoot_plugin_manage_bots';
    public const MANAGE_SETTINGS = 'chatwoot_plugin_manage_settings';
    public const VIEW_AUDIT_LOGS = 'chatwoot_plugin_view_audit_logs';

    public const KEYS = [
        self::ACCESS,
        self::SEND,
        self::MANAGE_CONVERSATIONS,
        self::MANAGE_CONTACTS,
        self::MANAGE_INSTANCES,
        self::MANAGE_CAMPAIGNS,
        self::MANAGE_BOTS,
        self::MANAGE_SETTINGS,
        self::VIEW_AUDIT_LOGS,
    ];

    public static function definitions(): array
    {
        return [
            self::ACCESS => [
                'language_key' => 'chatwoot_permission_access',
                'fallback' => 'Acessar o Impulso Hub Atendimento',
            ],
            self::SEND => [
                'language_key' => 'chatwoot_permission_send',
                'fallback' => 'Enviar mensagens',
            ],
            self::MANAGE_CONVERSATIONS => [
                'language_key' => 'chatwoot_permission_manage_conversations',
                'fallback' => 'Gerenciar conversas, notas, tags e atribuicoes',
            ],
            self::MANAGE_CONTACTS => [
                'language_key' => 'chatwoot_permission_manage_contacts',
                'fallback' => 'Gerenciar contatos e importacoes',
            ],
            self::MANAGE_INSTANCES => [
                'language_key' => 'chatwoot_permission_manage_instances',
                'fallback' => 'Gerenciar canais WhatsApp',
            ],
            self::MANAGE_CAMPAIGNS => [
                'language_key' => 'chatwoot_permission_manage_campaigns',
                'fallback' => 'Gerenciar campanhas',
            ],
            self::MANAGE_BOTS => [
                'language_key' => 'chatwoot_permission_manage_bots',
                'fallback' => 'Gerenciar bots determinísticos',
            ],
            self::MANAGE_SETTINGS => [
                'language_key' => 'chatwoot_permission_manage_settings',
                'fallback' => 'Gerenciar configuracoes e credenciais',
            ],
            self::VIEW_AUDIT_LOGS => [
                'language_key' => 'chatwoot_permission_view_audit_logs',
                'fallback' => 'Visualizar logs tecnicos',
            ],
        ];
    }

    public static function can(?object $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        if (!empty($user->is_admin)) {
            return true;
        }

        if (($user->user_type ?? '') !== 'staff') {
            return false;
        }

        $permissions = is_array($user->permissions ?? null) ? $user->permissions : [];
        if (!empty($permissions[$permission])) {
            return true;
        }
        // Roles created before 2.0 used the settings permission to manage all
        // automations. Preserve that access until the role is saved again.
        if ($permission === self::MANAGE_BOTS && !empty($permissions[self::MANAGE_SETTINGS])) {
            return true;
        }

        if ($permission !== self::ACCESS) {
            return false;
        }

        foreach (self::KEYS as $key) {
            if ($key !== self::ACCESS && !empty($permissions[$key])) {
                return true;
            }
        }

        return false;
    }

    public static function currentUser(): ?object
    {
        static $loaded = false;
        static $user = null;

        if ($loaded) {
            return $user;
        }

        $loaded = true;

        try {
            $users_model = model('App\\Models\\Users_model');
            $user_id = (int) $users_model->login_user_id();
            if (!$user_id) {
                return null;
            }

            $user = $users_model->get_access_info($user_id);
            if (!$user) {
                return null;
            }

            $user->permissions = self::normalizePermissions($user->permissions ?? []);
            return $user;
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not resolve the current user ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);

            return null;
        }
    }

    public static function currentRolePermissions(): array
    {
        try {
            $segments = \Config\Services::request()->getUri()->getSegments();
            $permission_segment = array_search('permissions', $segments, true);
            $role_id = $permission_segment !== false && isset($segments[$permission_segment + 1])
                && ctype_digit((string) $segments[$permission_segment + 1])
                ? (int) $segments[$permission_segment + 1]
                : 0;

            if (!$role_id) {
                return [];
            }

            $role = model('App\\Models\\Roles_model')->get_one($role_id);
            return $role ? self::normalizePermissions($role->permissions ?? []) : [];
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not resolve role permissions ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);

            return [];
        }
    }

    public static function applyToSaveData(array $permissions): array
    {
        $request = \Config\Services::request();

        foreach (self::KEYS as $permission) {
            $permissions[$permission] = $request->getPost($permission) ? '1' : '';
        }

        foreach (self::KEYS as $permission) {
            if ($permission !== self::ACCESS && !empty($permissions[$permission])) {
                $permissions[self::ACCESS] = '1';
                break;
            }
        }

        return $permissions;
    }

    public static function translate(string $language_key, string $fallback): string
    {
        if (!function_exists('app_lang')) {
            return $fallback;
        }

        $translation = (string) app_lang($language_key);
        if ($translation === ''
            || $translation === 'default_lang.' . $language_key
            || $translation === 'custom_lang.' . $language_key) {
            return $fallback;
        }

        return $translation;
    }

    private static function normalizePermissions($permissions): array
    {
        if (is_array($permissions)) {
            return $permissions;
        }

        if (!is_string($permissions) || trim($permissions) === '') {
            return [];
        }

        try {
            $decoded = @unserialize($permissions, ['allowed_classes' => false]);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    private function __construct()
    {
    }
}
