<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use App\Controllers\Security_Controller;
use Chatwoot_plugin\Libraries\Chat_permissions;
use Chatwoot_plugin\Libraries\Migration_runner;
use Chatwoot_plugin\Services\Chat_service;
use Chatwoot_plugin\Services\Contact_service;
use Chatwoot_plugin\Services\Campaign_service;
use Chatwoot_plugin\Services\Ai_service;
use Chatwoot_plugin\Services\Automation_service;
use Chatwoot_plugin\Services\Report_service;
use Chatwoot_plugin\Services\Notification_service;
use Throwable;

class Chatwoot extends Security_Controller
{
    private Chat_service $chat;

    public function __construct()
    {
        parent::__construct();
        $this->access_only_team_members();

        if (!Chat_permissions::can($this->login_user, Chat_permissions::ACCESS)) {
            app_redirect('forbidden');
        }

        // Also covers upgrades where the plugin was already active before the
        // new lifecycle hook was introduced. Version checks are idempotent.
        (new Migration_runner())->migrate();
        $this->chat = new Chat_service();
    }

    public function index()
    {
        $activeTab = (string) $this->request->getGet('chatwoot_tab');
        $allowedTabs = ['dashboard', 'conversations', 'contacts', 'instances', 'campaigns', 'ai', 'reports', 'settings'];
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'dashboard';
        }

        $canSend = Chat_permissions::can($this->login_user, Chat_permissions::SEND);
        $canManageConversations = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_CONVERSATIONS);
        $canManageContacts = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_CONTACTS);
        $canManageInstances = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_INSTANCES);
        $canManageCampaigns = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_CAMPAIGNS);
        $canManageAi = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_AI);
        $canViewReports = Chat_permissions::can($this->login_user, Chat_permissions::VIEW_REPORTS);
        $canExportReports = Chat_permissions::can($this->login_user, Chat_permissions::EXPORT_REPORTS);
        $canManageSettings = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_SETTINGS);
        if (($activeTab === 'instances' && !$canManageInstances)
            || ($activeTab === 'contacts' && !$canManageContacts)
            || ($activeTab === 'campaigns' && !$canManageCampaigns)
            || ($activeTab === 'ai' && !$canManageAi)
            || ($activeTab === 'reports' && !$canViewReports)
            || ($activeTab === 'settings' && !$canManageSettings)) {
            app_redirect('forbidden');
        }

        $integrationError = null;
        try {
            $instanceResult = $this->chat->list_instances([], 1, 100);
            $conversationResult = $this->chat->list_conversations(['archived' => false], 1, 30);
            $instances = $instanceResult['data'];
            if (!$canManageInstances) {
                $instances = array_map(fn (array $instance): array => $this->channelInstance($instance), $instances);
            }
            $conversations = array_map(
                fn (array $row): array => $this->conversationForView($row),
                $conversationResult['data']
            );
            $summary = $this->chat->dashboard_summary();
            $allPublicSettings = $this->chat->public_settings();
            $runtimePreferences = [
                'soundEnabled' => !empty($allPublicSettings['sound_enabled']),
                'browserNotificationsEnabled' => !empty($allPublicSettings['browser_notifications_enabled']),
            ];
            $settings = $canManageSettings ? $allPublicSettings : [];
            $webhookLogs = $canManageInstances ? $this->chat->recent_webhook_logs(20) : [];
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin page data failed ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);
            $integrationError = 'Os dados de atendimento nao puderam ser carregados. Tente novamente em instantes.';
            $instances = [];
            $conversations = [];
            $settings = [];
            $runtimePreferences = ['soundEnabled' => false, 'browserNotificationsEnabled' => false];
            $webhookLogs = [];
            $summary = $this->emptySummary();
        }

        $moduleErrors = [];
        $contacts = [];
        $contactSummary = ['total' => 0, 'with_conversation' => 0, 'unidentified' => 0, 'opt_out' => 0];
        if ($canManageContacts) {
            try {
                $contactService = new Contact_service();
                $contactResult = $contactService->list([], 1, 50);
                $contactSummary = $contactService->summary();
                $instanceNames = [];
                foreach ($instances as $instance) $instanceNames[(int) $instance['id']] = (string) $instance['name'];
                $contacts = array_map(static function (array $contact) use ($instanceNames): array {
                    $contact['instance'] = $instanceNames[(int) ($contact['instance_id'] ?? 0)] ?? '—';
                    $contact['conversations'] = (int) ($contact['conversation_count'] ?? 0);
                    $contact['last_seen'] = $contact['last_activity_at'] ?? '—';
                    return $contact;
                }, $contactResult['data']);
            } catch (Throwable $exception) { $moduleErrors['contacts'] = 'Nao foi possivel carregar os contatos.'; }
        }

        $campaigns = [];
        $campaignSummary = ['month' => 0, 'sent' => 0, 'delivery_rate' => '0%', 'reply_rate' => '0%'];
        if ($canManageCampaigns) {
            try {
                $campaignService = new Campaign_service();
                $campaigns = $campaignService->list([], 1, 50)['data'];
                $campaignSummary = $campaignService->summary();
                $instanceNames = [];
                foreach ($instances as $instance) $instanceNames[(int) $instance['id']] = (string) $instance['name'];
                foreach ($campaigns as &$campaign) {
                    $campaign['instance'] = $instanceNames[(int) ($campaign['instance_id'] ?? 0)] ?? '';
                    $campaign['audience'] = (int) ($campaign['audience_count'] ?? 0);
                }
                unset($campaign);
            } catch (Throwable $exception) { $moduleErrors['campaigns'] = 'Nao foi possivel carregar as campanhas.'; }
        }

        $agents = $automations = [];
        if ($canManageAi) {
            try { $agents = (new Ai_service())->list_agents([], 1, 50)['data']; } catch (Throwable $exception) { $moduleErrors['ai'] = 'Nao foi possivel carregar os agentes.'; }
            try { $automations = (new Automation_service())->list([], 1, 50)['data']; } catch (Throwable $exception) { $moduleErrors['automations'] = 'Nao foi possivel carregar as automacoes.'; }
        }

        $reports = $this->emptyReports();
        if ($canViewReports) {
            try {
                $reports = (new Report_service())->generate(['period' => '7d', 'instance_id' => 'all', 'timezone' => (string) ($settings['timezone'] ?? 'America/Sao_Paulo')]);
                $reports['volume'] = $reports['volume_values'] ?? [];
            } catch (Throwable $exception) { $moduleErrors['reports'] = 'Nao foi possivel calcular os relatorios.'; }
        }
        try { $notificationUnread = (new Notification_service())->unread_count((int) $this->login_user->id); } catch (Throwable $exception) { $notificationUnread = 0; }
        if (isset($moduleErrors[$activeTab])) $integrationError = $moduleErrors[$activeTab];

        $viewData = [
            'active_tab' => $activeTab,
            'summary' => $summary,
            'conversations' => $conversations,
            'contacts' => $contacts,
            'contact_summary' => $contactSummary,
            'instances' => $instances,
            'campaigns' => $campaigns,
            'campaign_summary' => $campaignSummary,
            'agents' => $agents,
            'automations' => $automations,
            'reports' => $reports,
            'notification_unread_count' => $notificationUnread,
            'webhook_logs' => $webhookLogs,
            'settings_public' => $settings,
            'webhook_endpoint' => get_uri('chatwoot_plugin/webhooks/evolution'),
            'can_send_messages' => $canSend,
            'can_manage_conversations' => $canManageConversations,
            'can_manage_contacts' => $canManageContacts,
            'can_manage_instances' => $canManageInstances,
            'can_manage_campaigns' => $canManageCampaigns,
            'can_manage_ai' => $canManageAi,
            'can_view_reports' => $canViewReports,
            'can_export_reports' => $canExportReports,
            'can_manage_settings' => $canManageSettings,
            'integration_error' => $integrationError,
            'app_config' => [
                'endpoints' => [
                    'page' => get_uri('chatwoot_plugin'),
                    'instances' => get_uri('chatwoot_plugin/api/instances'),
                    'instancesRefresh' => get_uri('chatwoot_plugin/api/instances/refresh-status'),
                    'conversations' => get_uri('chatwoot_plugin/api/conversations'),
                    'contacts' => get_uri('chatwoot_plugin/api/contacts'),
                    'campaigns' => get_uri('chatwoot_plugin/api/campaigns'),
                    'campaignTemplates' => get_uri('chatwoot_plugin/api/campaign-templates'),
                    'aiAgents' => get_uri('chatwoot_plugin/api/ai/agents'),
                    'automations' => get_uri('chatwoot_plugin/api/automations'),
                    'aiState' => get_uri('chatwoot_plugin/api/ai/state'),
                    'aiLogs' => get_uri('chatwoot_plugin/api/ai/logs'),
                    'reports' => get_uri('chatwoot_plugin/api/reports'),
                    'notifications' => get_uri('chatwoot_plugin/api/notifications'),
                    'quickReplies' => get_uri('chatwoot_plugin/api/quick-replies'),
                    'search' => get_uri('chatwoot_plugin/api/search'),
                    'mediaUpload' => get_uri('chatwoot_plugin/api/media'),
                    'n8nHealth' => get_uri('chatwoot_plugin/api/integrations/n8n/test'),
                    'notificationsReadAll' => get_uri('chatwoot_plugin/api/notifications/read-all'),
                    'settings' => get_uri('chatwoot_plugin/api/settings'),
                    'settingsTest' => get_uri('chatwoot_plugin/api/settings/test'),
                    'csrf' => get_uri('chatwoot_plugin/api/session/csrf'),
                ],
                'csrfHeader' => csrf_header(),
                'csrfTokenName' => csrf_token(),
                'csrfHash' => csrf_hash(),
                'pollingIntervalMs' => (int) ($settings['polling_interval_ms'] ?? 5000),
                'conversationPageSize' => 30,
                'messagePageSize' => 50,
                'preferences' => $runtimePreferences,
                'permissions' => [
                    'send' => $canSend,
                    'manageConversations' => $canManageConversations,
                    'manageContacts' => $canManageContacts,
                    'manageInstances' => $canManageInstances,
                    'manageCampaigns' => $canManageCampaigns,
                    'manageAi' => $canManageAi,
                    'viewReports' => $canViewReports,
                    'exportReports' => $canExportReports,
                    'manageSettings' => $canManageSettings,
                ],
            ],
        ];

        return $this->template->render('Chatwoot_plugin\Views\index', $viewData);
    }

    /** @return array<string,mixed> */
    private function conversationForView(array $row): array
    {
        $name = (string) ($row['contact_name'] ?? $row['name'] ?? 'Contato');
        $phone = (string) ($row['phone_number'] ?? $row['phone'] ?? '');
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $avatar = strtoupper(substr((string) ($parts[0] ?? '?'), 0, 1)
            . (count($parts) > 1 ? substr((string) end($parts), 0, 1) : ''));
        $lastActivity = $row['last_message_at'] ?? null;

        return array_merge($row, [
            'name' => $name,
            'phone' => $phone,
            'avatar' => $avatar,
            'instance' => (string) ($row['instance_name'] ?? ''),
            'unread' => (int) ($row['unread_count'] ?? 0),
            'last_message' => (string) ($row['last_message_preview'] ?? ''),
            'time' => $lastActivity ? date('H:i', strtotime((string) $lastActivity)) : '',
            'last_activity_at' => $lastActivity,
            'assignee' => 'Nao atribuido',
            'team' => 'Atendimento',
            'email' => '',
            'city' => '',
            'source' => 'WhatsApp',
            'tags' => [],
            'messages' => [],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function contactsFromConversations(array $conversations): array
    {
        $contacts = [];
        foreach ($conversations as $conversation) {
            $key = (string) ($conversation['instance_id'] ?? 0) . ':' . (string) ($conversation['remote_jid'] ?? '');
            $contacts[$key] = [
                'name' => (string) ($conversation['name'] ?? 'Contato'),
                'phone' => (string) ($conversation['phone'] ?? ''),
                'email' => '',
                'company' => '—',
                'tags' => [],
                'last_seen' => (string) ($conversation['time'] ?? ''),
                'conversations' => 1,
            ];
        }

        return array_values($contacts);
    }

    /** @return array<string,mixed> */
    private function channelInstance(array $instance): array
    {
        return [
            'id' => (int) ($instance['id'] ?? 0),
            'name' => (string) ($instance['name'] ?? ''),
            'phone' => (string) ($instance['phone'] ?? ''),
            'phone_number' => (string) ($instance['phone_number'] ?? ''),
            'status' => (string) ($instance['status'] ?? 'disconnected'),
            'connection_status' => (string) ($instance['connection_status'] ?? 'disconnected'),
            'active' => !empty($instance['active']),
            'conversation_count' => (int) ($instance['conversation_count'] ?? 0),
            'open_conversations' => (int) ($instance['open_conversations'] ?? 0),
            'unread_count' => (int) ($instance['unread_count'] ?? 0),
            'last_sync_at' => $instance['last_sync_at'] ?? null,
        ];
    }

    /** @return array<string,int|string> */
    private function emptySummary(): array
    {
        return [
            'open' => 0,
            'pending' => 0,
            'unassigned' => 0,
            'sla_risk' => 0,
            'resolved_today' => 0,
            'avg_first_response' => '—',
            'online_agents' => 0,
            'high_priority' => 0,
            'ai_resolution_rate' => '—',
            'connected_instances' => 0,
        ];
    }

    /** @return array<string,array<int,mixed>> */
    private function emptyReports(): array
    {
        return [
            'volume' => [0, 0, 0, 0, 0, 0, 0],
            'labels' => ['Qui', 'Sex', 'Sab', 'Dom', 'Seg', 'Ter', 'Qua'],
            'channels' => [],
            'agents' => [],
        ];
    }
}
