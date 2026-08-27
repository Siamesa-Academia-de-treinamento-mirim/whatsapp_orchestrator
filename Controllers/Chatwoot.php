<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use App\Controllers\Security_Controller;
use Chatwoot_plugin\Libraries\Chat_permissions;
use Chatwoot_plugin\Services\Chat_service;
use Chatwoot_plugin\Services\Contact_service;
use Chatwoot_plugin\Services\Campaign_service;
use Chatwoot_plugin\Services\Bot_service;
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

        $this->chat = new Chat_service();
    }

    public function index()
    {
        $activeTab = (string) $this->request->getGet('chatwoot_tab');
        $allowedTabs = ['dashboard', 'conversations', 'contacts', 'instances', 'campaigns', 'bots', 'settings'];
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'dashboard';
        }

        $canSend = Chat_permissions::can($this->login_user, Chat_permissions::SEND);
        $canManageConversations = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_CONVERSATIONS);
        $canManageContacts = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_CONTACTS);
        $canManageInstances = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_INSTANCES);
        $canManageCampaigns = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_CAMPAIGNS);
        $canManageBots = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_BOTS);
        $canManageSettings = Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_SETTINGS);
        if (($activeTab === 'instances' && !$canManageInstances)
            || ($activeTab === 'contacts' && !$canManageContacts)
            || ($activeTab === 'campaigns' && !$canManageCampaigns)
            || ($activeTab === 'bots' && !$canManageBots)
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
            $summary = $activeTab === 'dashboard'
                ? $this->chat->dashboard_summary()
                : $this->emptySummary();
            $allPublicSettings = $this->chat->public_settings();
            $runtimePreferences = [
                'soundEnabled' => !empty($allPublicSettings['sound_enabled']),
                'browserNotificationsEnabled' => !empty($allPublicSettings['browser_notifications_enabled']),
            ];
            $settings = $canManageSettings ? $allPublicSettings : [];
            $webhookLogs = $canManageInstances && $activeTab === 'instances'
                ? $this->chat->recent_webhook_logs(20)
                : [];
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin page data failed ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);
            $integrationError = 'Os dados de atendimento nao puderam ser carregados. Tente novamente em instantes.';
            $instances = [];
            $conversations = [];
            $settings = [];
            $allPublicSettings = [];
            $runtimePreferences = ['soundEnabled' => false, 'browserNotificationsEnabled' => false];
            $webhookLogs = [];
            $summary = $this->emptySummary();
        }

        $moduleErrors = [];
        $contacts = [];
        $contactSummary = ['total' => 0, 'with_conversation' => 0, 'unidentified' => 0, 'opt_out' => 0];
        if ($canManageContacts && $activeTab === 'contacts') {
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
        if ($canManageCampaigns && in_array($activeTab, ['dashboard', 'campaigns'], true)) {
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

        $bots = [];
        if ($canManageBots && in_array($activeTab, ['dashboard', 'bots'], true)) {
            try { $bots = (new Bot_service())->list([], 1, 100)['data']; }
            catch (Throwable $exception) { $moduleErrors['bots'] = 'Nao foi possivel carregar os bots.'; }
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
            'bots' => $bots,
            'notification_unread_count' => $notificationUnread,
            'webhook_logs' => $webhookLogs,
            'settings_public' => $settings,
            'webhook_endpoint' => get_uri('chatwoot_plugin/webhooks/evolution'),
            'can_send_messages' => $canSend,
            'can_manage_conversations' => $canManageConversations,
            'can_manage_contacts' => $canManageContacts,
            'can_manage_instances' => $canManageInstances,
            'can_manage_campaigns' => $canManageCampaigns,
            'can_manage_bots' => $canManageBots,
            'can_manage_settings' => $canManageSettings,
            'integration_error' => $integrationError,
            'app_config' => [
                'actorId' => (int) $this->login_user->id,
                'actorName' => (string) ($this->login_user->first_name ?? $this->login_user->name ?? ''),
                'endpoints' => [
                    'page' => get_uri('chatwoot_plugin'),
                    'instances' => get_uri('chatwoot_plugin/api/instances'),
                    'instancesRefresh' => get_uri('chatwoot_plugin/api/instances/refresh-status'),
                    'conversations' => get_uri('chatwoot_plugin/api/conversations'),
                    'conversationAssignmentOptions' => get_uri('chatwoot_plugin/api/conversations/assignment-options'),
                    'contacts' => get_uri('chatwoot_plugin/api/contacts'),
                    'contactRepairs' => get_uri('chatwoot_plugin/api/contact-repairs'),
                    'campaigns' => get_uri('chatwoot_plugin/api/campaigns'),
                    'campaignTemplates' => get_uri('chatwoot_plugin/api/campaign-templates'),
                    'bots' => get_uri('chatwoot_plugin/api/bots'),
                    'notifications' => get_uri('chatwoot_plugin/api/notifications'),
                    'quickReplies' => get_uri('chatwoot_plugin/api/quick-replies'),
                    'search' => get_uri('chatwoot_plugin/api/search'),
                    'mediaUpload' => get_uri('chatwoot_plugin/api/media'),
                    'notificationsReadAll' => get_uri('chatwoot_plugin/api/notifications/read-all'),
                    'savedViews' => get_uri('chatwoot_plugin/api/saved-views'),
                    'bulkAction' => get_uri('chatwoot_plugin/api/conversations/bulk-action'),
                    'settings' => get_uri('chatwoot_plugin/api/settings'),
                    'settingsTest' => get_uri('chatwoot_plugin/api/settings/test'),
                    'csrf' => get_uri('chatwoot_plugin/api/session/csrf'),
                ],
                'csrfHeader' => csrf_header(),
                'csrfTokenName' => csrf_token(),
                'csrfHash' => csrf_hash(),
                'pollingIntervalMs' => (int) ($allPublicSettings['polling_interval_ms'] ?? 5000),
                'localPollingIntervalMs' => max(3000, min(5000, (int) ($allPublicSettings['polling_interval_ms'] ?? 5000))),
                'remoteSyncIntervalMs' => max(30000, (int) ($allPublicSettings['polling_interval_ms'] ?? 5000) * 6),
                'readTimeoutMs' => 10000,
                'remoteSyncTimeoutMs' => 10000,
                'instanceRefreshIntervalMs' => 60000,
                'writeTimeoutMs' => max(5000, min(120000, (int) ($allPublicSettings['request_timeout_seconds'] ?? 30) * 1000)),
                'conversationPageSize' => (int) ($allPublicSettings['conversation_page_size'] ?? 30),
                'messagePageSize' => 50,
                'remoteConversationSyncLimit' => 100,
                'preferences' => $runtimePreferences,
                'permissions' => [
                    'send' => $canSend,
                    'manageConversations' => $canManageConversations,
                    'manageContacts' => $canManageContacts,
                    'manageInstances' => $canManageInstances,
                    'manageCampaigns' => $canManageCampaigns,
                    'manageBots' => $canManageBots,
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
            'assignee' => (string) ($row['assignee'] ?? ''),
            'team' => is_array($row['team'] ?? null) ? (string) ($row['team']['name'] ?? '') : (string) ($row['team'] ?? ''),
            'email' => '',
            'city' => '',
            'source' => 'WhatsApp',
            'tags' => is_array($row['tags'] ?? null) ? $row['tags'] : [],
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
            'resolved_today' => 0,
            'avg_first_response' => '—',
            'online_agents' => 0,
            'high_priority' => 0,
            'connected_instances' => 0,
        ];
    }

}
