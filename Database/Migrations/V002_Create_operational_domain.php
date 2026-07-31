<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

class V002_Create_operational_domain extends Migration
{
    public const VERSION = 2;

    public function up(): void
    {
        $this->createContacts();
        $this->createConversationDomain();
        $this->createCampaignDomain();
        $this->createAiDomain();
        $this->createOperationsDomain();
        $this->extendCoreTables();
        $this->seedDefaults();
    }

    public function down(): void
    {
        // Upgrade data is intentionally preserved. Destructive rollback is not safe.
    }

    private function createContacts(): void
    {
        $contacts = $this->table('chat_contacts');
        $identifiers = $this->table('chat_contact_identifiers');
        $tags = $this->table('chat_tags');
        $contactTags = $this->table('chat_contact_tags');
        $conversationTags = $this->table('chat_conversation_tags');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$contacts} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance_id` BIGINT UNSIGNED NULL,
            `name` VARCHAR(191) NOT NULL,
            `phone_normalized` VARCHAR(32) NOT NULL,
            `email` VARCHAR(191) NULL,
            `company` VARCHAR(191) NULL,
            `city` VARCHAR(191) NULL,
            `source` VARCHAR(64) NOT NULL DEFAULT 'whatsapp',
            `notes` LONGTEXT NULL,
            `profile_picture_url` TEXT NULL,
            `opt_out` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `manually_edited` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `scope_key` CHAR(64) NOT NULL,
            `last_activity_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_contact_scope` (`scope_key`),
            KEY `idx_chat_contact_phone` (`phone_normalized`, `deleted`),
            KEY `idx_chat_contact_activity` (`last_activity_at`, `id`),
            KEY `idx_chat_contact_instance` (`instance_id`, `opt_out`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$identifiers} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `contact_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `identifier_type` VARCHAR(32) NOT NULL,
            `identifier_value` VARCHAR(191) NOT NULL,
            `is_primary` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_contact_identifier` (`instance_id`, `identifier_type`, `identifier_value`),
            KEY `idx_chat_identifier_contact` (`contact_id`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$tags} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `normalized_name` VARCHAR(100) NOT NULL,
            `color` VARCHAR(16) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_tag_name` (`normalized_name`),
            KEY `idx_chat_tag_deleted` (`deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->createTagLinkTable($contactTags, 'contact_id', 'uq_chat_contact_tag');
        $this->createTagLinkTable($conversationTags, 'conversation_id', 'uq_chat_conversation_tag');
    }

    private function createTagLinkTable(string $table, string $ownerColumn, string $uniqueName): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `{$ownerColumn}` BIGINT UNSIGNED NOT NULL,
            `tag_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `{$uniqueName}` (`{$ownerColumn}`, `tag_id`),
            KEY `idx_{$uniqueName}_tag` (`tag_id`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function createConversationDomain(): void
    {
        $notes = $this->table('chat_internal_notes');
        $quickReplies = $this->table('chat_quick_replies');
        $media = $this->table('chat_media');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$notes} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `message_id` BIGINT UNSIGNED NULL,
            `author_user_id` BIGINT UNSIGNED NOT NULL,
            `content` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_note_conversation` (`conversation_id`, `created_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$quickReplies} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(150) NOT NULL,
            `text_content` TEXT NOT NULL,
            `shortcut` VARCHAR(80) NULL,
            `scope_type` VARCHAR(24) NOT NULL DEFAULT 'global',
            `scope_id` BIGINT UNSIGNED NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_quick_reply_shortcut` (`scope_type`, `scope_id`, `shortcut`),
            KEY `idx_chat_quick_reply_active` (`active`, `sort_order`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$media} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NULL,
            `message_id` BIGINT UNSIGNED NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `storage_driver` VARCHAR(32) NOT NULL DEFAULT 'local',
            `storage_path` TEXT NULL,
            `remote_url` TEXT NULL,
            `original_name` VARCHAR(255) NULL,
            `mime_type` VARCHAR(191) NOT NULL,
            `media_type` VARCHAR(32) NOT NULL,
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `sha256` CHAR(64) NOT NULL,
            `external_media_id` VARCHAR(191) NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `expires_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_media_message` (`message_id`, `deleted`),
            KEY `idx_chat_media_expiry` (`expires_at`, `deleted`),
            KEY `idx_chat_media_hash` (`sha256`, `file_size`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function createCampaignDomain(): void
    {
        $campaigns = $this->table('chat_campaigns');
        $runs = $this->table('chat_campaign_runs');
        $recipients = $this->table('chat_campaign_recipients');
        $templates = $this->table('chat_campaign_templates');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$campaigns} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `external_id` VARCHAR(191) NULL,
            `name` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
            `audience_json` LONGTEXT NULL,
            `message_content` LONGTEXT NOT NULL,
            `media_id` BIGINT UNSIGNED NULL,
            `schedule_json` LONGTEXT NULL,
            `metrics_json` LONGTEXT NULL,
            `correlation_id` VARCHAR(191) NOT NULL,
            `idempotency_key` CHAR(64) NOT NULL,
            `last_error` TEXT NULL,
            `last_sync_at` DATETIME NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_campaign_idempotency` (`idempotency_key`),
            KEY `idx_chat_campaign_status` (`status`, `instance_id`, `deleted`, `created_at`),
            KEY `idx_chat_campaign_external` (`external_id`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$runs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `campaign_id` BIGINT UNSIGNED NOT NULL,
            `external_run_id` VARCHAR(191) NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'scheduled',
            `metrics_json` LONGTEXT NULL,
            `started_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            `error_message` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_campaign_run` (`campaign_id`, `status`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$recipients} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `campaign_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED NULL,
            `phone_hash` CHAR(64) NOT NULL,
            `phone_normalized` VARCHAR(32) NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `external_message_id` VARCHAR(191) NULL,
            `error_message` TEXT NULL,
            `sent_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_campaign_recipient` (`campaign_id`, `phone_hash`),
            KEY `idx_chat_campaign_recipient_status` (`campaign_id`, `status`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$templates} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `message_content` LONGTEXT NOT NULL,
            `media_id` BIGINT UNSIGNED NULL,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_campaign_template` (`active`, `deleted`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function createAiDomain(): void
    {
        $agents = $this->table('chat_ai_agents');
        $automations = $this->table('chat_automations');
        $states = $this->table('chat_ai_states');
        $logs = $this->table('chat_ai_logs');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$agents} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `workflow_id` VARCHAR(191) NULL,
            `webhook_path` VARCHAR(500) NOT NULL,
            `default_mode` VARCHAR(32) NOT NULL DEFAULT 'running',
            `priority` INT NOT NULL DEFAULT 0,
            `handoff_policy_json` LONGTEXT NULL,
            `schedule_json` LONGTEXT NULL,
            `metadata_json` LONGTEXT NULL,
            `config_hash` CHAR(64) NOT NULL,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_ai_agent` (`instance_id`, `active`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$automations} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `trigger_event` VARCHAR(100) NOT NULL,
            `conditions_json` LONGTEXT NULL,
            `webhook_path` VARCHAR(500) NOT NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `delay_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `last_run_at` DATETIME NULL,
            `last_status` VARCHAR(32) NULL,
            `last_error` TEXT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_automation` (`active`, `trigger_event`, `instance_id`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$states} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'running',
            `reason` VARCHAR(191) NULL,
            `source` VARCHAR(64) NOT NULL DEFAULT 'rise_plugin',
            `summary` LONGTEXT NULL,
            `last_intent` VARCHAR(100) NULL,
            `stage` VARCHAR(100) NULL,
            `handoff_required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `changed_by` BIGINT UNSIGNED NULL,
            `correlation_id` VARCHAR(191) NULL,
            `external_synced_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_ai_state_conversation` (`conversation_id`),
            KEY `idx_chat_ai_state_instance` (`instance_id`, `status`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$logs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `agent_id` BIGINT UNSIGNED NULL,
            `status` VARCHAR(32) NOT NULL,
            `event_name` VARCHAR(120) NOT NULL,
            `correlation_id` VARCHAR(191) NULL,
            `request_payload` LONGTEXT NULL,
            `response_payload` LONGTEXT NULL,
            `error_message` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_ai_log_filter` (`conversation_id`, `instance_id`, `agent_id`, `status`, `created_at`),
            KEY `idx_chat_ai_log_correlation` (`correlation_id`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function createOperationsDomain(): void
    {
        $notifications = $this->table('chat_notifications');
        $audit = $this->table('chat_audit_logs');
        $jobs = $this->table('chat_integration_jobs');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$notifications} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NULL,
            `kind` VARCHAR(64) NOT NULL,
            `level` VARCHAR(24) NOT NULL DEFAULT 'info',
            `title` VARCHAR(191) NOT NULL,
            `message` TEXT NOT NULL,
            `resource_type` VARCHAR(64) NULL,
            `resource_id` BIGINT UNSIGNED NULL,
            `dedupe_key` CHAR(64) NULL,
            `read_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_notification_dedupe` (`dedupe_key`),
            KEY `idx_chat_notification_user` (`user_id`, `read_at`, `deleted`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$audit} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_user_id` BIGINT UNSIGNED NULL,
            `action` VARCHAR(120) NOT NULL,
            `resource_type` VARCHAR(64) NOT NULL,
            `resource_id` VARCHAR(191) NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `correlation_id` VARCHAR(191) NULL,
            `ip_address` VARCHAR(64) NULL,
            `user_agent` VARCHAR(500) NULL,
            `before_json` LONGTEXT NULL,
            `after_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_audit_resource` (`resource_type`, `resource_id`, `created_at`),
            KEY `idx_chat_audit_actor` (`actor_user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$jobs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_type` VARCHAR(100) NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `payload_json` LONGTEXT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
            `available_at` DATETIME NOT NULL,
            `locked_at` DATETIME NULL,
            `locked_by` VARCHAR(191) NULL,
            `last_error` TEXT NULL,
            `correlation_id` VARCHAR(191) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_job_queue` (`status`, `available_at`, `deleted`),
            KEY `idx_chat_job_lock` (`locked_at`, `locked_by`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function extendCoreTables(): void
    {
        $conversationColumns = [
            'contact_id' => 'BIGINT UNSIGNED NULL',
            'priority' => "VARCHAR(16) NOT NULL DEFAULT 'normal'",
            'assignee_id' => 'BIGINT UNSIGNED NULL',
            'team_id' => 'BIGINT UNSIGNED NULL',
            'resolved_at' => 'DATETIME NULL',
            'resolved_by' => 'BIGINT UNSIGNED NULL',
            'ai_status' => "VARCHAR(32) NOT NULL DEFAULT 'running'",
            'ai_summary' => 'LONGTEXT NULL',
            'last_human_message_at' => 'DATETIME NULL',
            'last_bot_message_at' => 'DATETIME NULL',
            'first_response_at' => 'DATETIME NULL',
            'first_response_seconds' => 'INT UNSIGNED NULL',
        ];
        foreach ($conversationColumns as $column => $definition) {
            $this->addColumn('chat_conversations', $column, $definition);
        }

        $messageColumns = [
            'sender_user_id' => 'BIGINT UNSIGNED NULL',
            'reply_to_external_message_id' => 'VARCHAR(191) NULL',
            'caption' => 'TEXT NULL',
            'file_name' => 'VARCHAR(255) NULL',
            'file_size' => 'BIGINT UNSIGNED NULL',
            'media_id' => 'BIGINT UNSIGNED NULL',
            'is_internal_note' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'delivery_error' => 'TEXT NULL',
            'failed_at' => 'DATETIME NULL',
        ];
        foreach ($messageColumns as $column => $definition) {
            $this->addColumn('chat_messages', $column, $definition);
        }

        $this->addIndex('chat_conversations', 'idx_chat_conversation_contact', ['contact_id', 'deleted']);
        $this->addIndex('chat_conversations', 'idx_chat_conversation_assignment', ['assignee_id', 'team_id', 'status', 'deleted']);
        $this->addIndex('chat_messages', 'idx_chat_message_media', ['media_id', 'deleted']);
    }

    private function addColumn(string $logicalTable, string $column, string $definition): void
    {
        $rawTable = $this->db->prefixTable($logicalTable);
        if (!$this->db->fieldExists($column, $rawTable)) {
            $table = $this->table($logicalTable);
            $this->db->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function addIndex(string $logicalTable, string $name, array $columns): void
    {
        $rawTable = $this->db->prefixTable($logicalTable);
        $indexes = $this->db->getIndexData($rawTable);
        if (!isset($indexes[$name])) {
            $table = $this->table($logicalTable);
            $escapedColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            $this->db->query("ALTER TABLE {$table} ADD KEY `{$name}` ({$escapedColumns})");
        }
    }

    private function seedDefaults(): void
    {
        $defaults = [
            'module_name' => 'Impulso Hub',
            'timezone' => 'America/Sao_Paulo',
            'conversation_page_size' => '30',
            'sound_enabled' => '1',
            'browser_notifications_enabled' => '0',
            'auto_mark_read' => '1',
            'default_status' => 'open',
            'default_priority' => 'normal',
            'sla_minutes' => '30',
            'auto_resolve_hours' => '0',
            'evolution_retries' => '2',
            'evolution_endpoint_send_media' => '/message/sendMedia/{instance}',
            'evolution_endpoint_send_audio' => '/message/sendWhatsAppAudio/{instance}',
            'evolution_endpoint_media_base64' => '/chat/getBase64FromMediaMessage/{instance}',
            'n8n_auth_mode' => 'bearer',
            'n8n_header_name' => 'X-API-Key',
            'n8n_allow_private_networks' => '0',
            'n8n_timeout_seconds' => '30',
            'n8n_health_path' => '/healthz',
            'n8n_campaigns_path' => '/webhook/campanha',
            'n8n_ai_path' => '/webhook/iara/control',
            'n8n_events_path' => '/webhook/impulso/events',
            'campaign_window_start' => '08:00',
            'campaign_window_end' => '20:00',
            'campaign_batch_size' => '20',
            'campaign_min_interval_seconds' => '8',
            'campaign_pause_after_errors' => '5',
            'campaign_optout_text' => '',
            'quick_replies_json' => '[]',
            'ai_default_state' => 'running',
            'ai_human_priority' => '1',
            'ai_show_context' => '1',
            'ai_stop_command' => '@stop',
            'ai_start_command' => '@start',
            'ai_auto_return_minutes' => '0',
            'log_sanitized_webhooks' => '1',
            'webhook_retention_days' => '30',
            'audit_enabled' => '1',
            'audit_retention_days' => '180',
            'conversation_retention_days' => '0',
            'media_retention_days' => '30',
            'secure_media' => '1',
        ];
        foreach ($defaults as $key => $value) {
            $this->insertDefault($key, $value, false);
        }
    }

    private function insertDefault(string $key, string $value, bool $encrypted): void
    {
        if ($this->settingExists($key)) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table($this->db->prefixTable('chat_settings'))->insert([
            'setting_key' => $key,
            'setting_value' => $value,
            'is_encrypted' => $encrypted ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted' => 0,
        ]);
    }

    private function settingExists(string $key): bool
    {
        return $this->db->table($this->db->prefixTable('chat_settings'))
            ->where('setting_key', $key)
            ->where('deleted', 0)
            ->countAllResults() > 0;
    }

    private function table(string $logicalName): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logicalName));
    }
}
