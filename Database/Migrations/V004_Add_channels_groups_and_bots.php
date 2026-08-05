<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the data model required for reliable WhatsApp identity, groups,
 * multiple providers and deterministic automation flows.
 *
 * This migration is intentionally additive and safe for existing installs.
 */
class V004_Add_channels_groups_and_bots extends Migration
{
    public const VERSION = 4;

    public function up(): void
    {
        $this->extendInstances();
        $this->extendContacts();
        $this->createGroups();
        $this->extendConversations();
        $this->extendMessages();
        $this->extendCampaigns();
        $this->createBotDomain();
        $this->seedDefaults();
    }

    public function down(): void
    {
        // No destructive rollback: production conversations must be preserved.
    }

    private function extendInstances(): void
    {
        $columns = [
            'provider_type' => "VARCHAR(32) NOT NULL DEFAULT 'evolution'",
            'provider_status' => "VARCHAR(32) NOT NULL DEFAULT 'unknown'",
            'provider_config_json' => 'LONGTEXT NULL',
            'meta_phone_number_id' => 'VARCHAR(191) NULL',
            'meta_waba_id' => 'VARCHAR(191) NULL',
            'meta_access_token_encrypted' => 'LONGTEXT NULL',
            'meta_verify_token_encrypted' => 'LONGTEXT NULL',
            'meta_app_secret_encrypted' => 'LONGTEXT NULL',
            'meta_graph_version' => "VARCHAR(20) NOT NULL DEFAULT 'v25.0'",
        ];
        foreach ($columns as $name => $definition) {
            $this->addColumn('chat_instances', $name, $definition);
        }
        $this->addIndex('chat_instances', 'idx_chat_instance_provider', ['provider_type', 'active', 'deleted']);
        $this->addIndex('chat_instances', 'idx_chat_instance_meta_phone', ['meta_phone_number_id', 'deleted']);
    }

    private function extendContacts(): void
    {
        $columns = [
            'name_source' => "VARCHAR(32) NOT NULL DEFAULT 'automatic'",
            'name_updated_at' => 'DATETIME NULL',
            'last_incoming_name' => 'VARCHAR(191) NULL',
            'last_incoming_name_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $name => $definition) {
            $this->addColumn('chat_contacts', $name, $definition);
        }
    }

    private function createGroups(): void
    {
        $groups = $this->table('chat_groups');
        $participants = $this->table('chat_group_participants');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$groups} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `remote_jid` VARCHAR(191) NOT NULL,
            `subject` VARCHAR(255) NULL,
            `description` LONGTEXT NULL,
            `owner_jid` VARCHAR(191) NULL,
            `profile_picture_url` TEXT NULL,
            `participant_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `metadata_json` LONGTEXT NULL,
            `last_synced_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_group_remote` (`instance_id`, `remote_jid`),
            KEY `idx_chat_group_activity` (`instance_id`, `updated_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$participants} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED NULL,
            `participant_jid` VARCHAR(191) NOT NULL,
            `phone_normalized` VARCHAR(32) NULL,
            `display_name` VARCHAR(191) NULL,
            `role` VARCHAR(24) NOT NULL DEFAULT 'member',
            `is_self` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `last_message_at` DATETIME NULL,
            `metadata_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_group_participant` (`group_id`, `participant_jid`),
            KEY `idx_chat_group_participant_contact` (`contact_id`, `deleted`),
            KEY `idx_chat_group_participant_phone` (`instance_id`, `phone_normalized`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function extendConversations(): void
    {
        $columns = [
            'conversation_type' => "VARCHAR(24) NOT NULL DEFAULT 'individual'",
            'group_id' => 'BIGINT UNSIGNED NULL',
            'last_customer_message_at' => 'DATETIME NULL',
            'service_window_expires_at' => 'DATETIME NULL',
            'bot_status' => "VARCHAR(24) NOT NULL DEFAULT 'active'",
            'bot_paused_at' => 'DATETIME NULL',
            'bot_paused_by' => 'BIGINT UNSIGNED NULL',
            'bot_handoff_reason' => 'VARCHAR(500) NULL',
        ];
        foreach ($columns as $name => $definition) {
            $this->addColumn('chat_conversations', $name, $definition);
        }
        $this->addIndex('chat_conversations', 'idx_chat_conversation_type', ['conversation_type', 'instance_id', 'deleted']);
        $this->addIndex('chat_conversations', 'idx_chat_conversation_group', ['group_id', 'deleted']);
        $this->addIndex('chat_conversations', 'idx_chat_conversation_window', ['service_window_expires_at', 'deleted']);
    }

    private function extendMessages(): void
    {
        $columns = [
            'sender_jid' => 'VARCHAR(191) NULL',
            'sender_phone' => 'VARCHAR(32) NULL',
            'sender_name' => 'VARCHAR(191) NULL',
            'sender_contact_id' => 'BIGINT UNSIGNED NULL',
            'is_group_message' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'provider_name' => "VARCHAR(32) NOT NULL DEFAULT 'evolution'",
            'provider_payload_id' => 'VARCHAR(191) NULL',
        ];
        foreach ($columns as $name => $definition) {
            $this->addColumn('chat_messages', $name, $definition);
        }
        $this->addIndex('chat_messages', 'idx_chat_message_sender', ['sender_contact_id', 'sender_jid', 'deleted']);
        $this->addIndex('chat_messages', 'idx_chat_message_group', ['is_group_message', 'conversation_id', 'message_timestamp']);
    }

    private function extendCampaigns(): void
    {
        $campaignColumns = [
            'campaign_type' => "VARCHAR(24) NOT NULL DEFAULT 'unofficial'",
            'template_id' => 'BIGINT UNSIGNED NULL',
            'template_parameters_json' => 'LONGTEXT NULL',
            'dispatch_mode' => "VARCHAR(24) NOT NULL DEFAULT 'internal_queue'",
            'rate_limit_per_minute' => 'INT UNSIGNED NOT NULL DEFAULT 20',
        ];
        foreach ($campaignColumns as $name => $definition) {
            $this->addColumn('chat_campaigns', $name, $definition);
        }
        $this->addIndex('chat_campaigns', 'idx_chat_campaign_type', ['campaign_type', 'status', 'instance_id', 'deleted']);

        $templateColumns = [
            'instance_id' => 'BIGINT UNSIGNED NULL',
            'provider_template_id' => 'VARCHAR(191) NULL',
            'language_code' => "VARCHAR(20) NOT NULL DEFAULT 'pt_BR'",
            'category' => 'VARCHAR(32) NULL',
            'provider_status' => "VARCHAR(32) NOT NULL DEFAULT 'local'",
            'components_json' => 'LONGTEXT NULL',
            'last_synced_at' => 'DATETIME NULL',
        ];
        foreach ($templateColumns as $name => $definition) {
            $this->addColumn('chat_campaign_templates', $name, $definition);
        }
        $this->addIndex('chat_campaign_templates', 'idx_chat_template_provider', ['instance_id', 'provider_status', 'deleted']);
    }

    private function createBotDomain(): void
    {
        $flows = $this->table('chat_bot_flows');
        $sessions = $this->table('chat_bot_sessions');
        $events = $this->table('chat_bot_events');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$flows} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance_id` BIGINT UNSIGNED NULL,
            `name` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
            `priority` INT NOT NULL DEFAULT 0,
            `trigger_type` VARCHAR(32) NOT NULL DEFAULT 'first_message',
            `trigger_config_json` LONGTEXT NULL,
            `definition_json` LONGTEXT NOT NULL,
            `business_hours_json` LONGTEXT NULL,
            `fallback_message` TEXT NOT NULL,
            `handoff_message` TEXT NOT NULL,
            `max_fallbacks` INT UNSIGNED NOT NULL DEFAULT 2,
            `ignore_groups` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NULL,
            `published_by` BIGINT UNSIGNED NULL,
            `published_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_bot_flow_match` (`instance_id`, `active`, `priority`, `deleted`),
            KEY `idx_chat_bot_flow_status` (`status`, `updated_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$sessions} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `flow_id` BIGINT UNSIGNED NOT NULL,
            `flow_version` INT UNSIGNED NOT NULL,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED NULL,
            `current_node_key` VARCHAR(100) NOT NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'active',
            `fallback_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `context_json` LONGTEXT NULL,
            `last_incoming_message_id` BIGINT UNSIGNED NULL,
            `last_outgoing_message_id` BIGINT UNSIGNED NULL,
            `handoff_reason` VARCHAR(500) NULL,
            `started_at` DATETIME NOT NULL,
            `last_activity_at` DATETIME NOT NULL,
            `ended_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_bot_session_conversation` (`conversation_id`),
            KEY `idx_chat_bot_session_state` (`flow_id`, `status`, `last_activity_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$events} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id` BIGINT UNSIGNED NULL,
            `flow_id` BIGINT UNSIGNED NULL,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `message_id` BIGINT UNSIGNED NULL,
            `event_type` VARCHAR(64) NOT NULL,
            `node_key` VARCHAR(100) NULL,
            `matched_transition` VARCHAR(100) NULL,
            `input_preview` VARCHAR(500) NULL,
            `output_preview` VARCHAR(500) NULL,
            `metadata_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_bot_event_conversation` (`conversation_id`, `created_at`, `deleted`),
            KEY `idx_chat_bot_event_session` (`session_id`, `created_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function seedDefaults(): void
    {
        $defaults = [
            'meta_graph_version' => 'v25.0',
            'meta_timeout_seconds' => '30',
            'meta_service_window_hours' => '24',
            'bot_enabled' => '1',
            'bot_pause_on_human_message' => '1',
            'bot_default_fallback' => 'Não consegui identificar sua dúvida com segurança. Vou encaminhar sua mensagem para um responsável.',
            'bot_default_handoff' => 'Sua mensagem foi encaminhada para um responsável, que continuará o atendimento.',
            'bot_session_timeout_minutes' => '1440',
            'product_mode' => 'whatsapp_specialized',
        ];
        foreach ($defaults as $key => $value) {
            $this->insertDefault($key, $value);
        }
    }

    private function addColumn(string $logicalTable, string $column, string $definition): void
    {
        $rawTable = $this->db->prefixTable($logicalTable);
        if (!$this->db->fieldExists($column, $rawTable)) {
            $this->db->query('ALTER TABLE ' . $this->table($logicalTable) . " ADD COLUMN `{$column}` {$definition}");
        }
    }

    /** @param array<int,string> $columns */
    private function addIndex(string $logicalTable, string $name, array $columns): void
    {
        $rawTable = $this->db->prefixTable($logicalTable);
        $indexes = $this->db->getIndexData($rawTable);
        if (isset($indexes[$name])) {
            return;
        }
        $escaped = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $this->db->query('ALTER TABLE ' . $this->table($logicalTable) . " ADD KEY `{$name}` ({$escaped})");
    }

    private function insertDefault(string $key, string $value): void
    {
        $table = $this->db->prefixTable('chat_settings');
        if ($this->db->table($table)->where('setting_key', $key)->countAllResults() > 0) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table($table)->insert([
            'setting_key' => $key,
            'setting_value' => $value,
            'is_encrypted' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted' => 0,
        ]);
    }

    private function table(string $logicalName): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logicalName));
    }
}
