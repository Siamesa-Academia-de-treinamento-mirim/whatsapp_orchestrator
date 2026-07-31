<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use Chatwoot_plugin\Libraries\Credential_cipher;
use CodeIgniter\Database\Migration;

require_once dirname(__DIR__, 2) . '/Libraries/Credential_cipher.php';

class V001_Create_chat_tables extends Migration
{
    public const VERSION = 1;

    public function up(): void
    {
        $settings = $this->table('chat_settings');
        $instances = $this->table('chat_instances');
        $conversations = $this->table('chat_conversations');
        $messages = $this->table('chat_messages');
        $webhookLogs = $this->table('chat_webhook_logs');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$settings} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(120) NOT NULL,
            `setting_value` LONGTEXT NULL,
            `is_encrypted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_settings_key` (`setting_key`),
            KEY `idx_chat_settings_deleted` (`deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$instances} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `evolution_instance_name` VARCHAR(191) NOT NULL,
            `internal_identifier` VARCHAR(191) NOT NULL,
            `base_url` VARCHAR(500) NULL,
            `api_key_encrypted` LONGTEXT NULL,
            `phone_number` VARCHAR(32) NULL,
            `connection_status` VARCHAR(32) NOT NULL DEFAULT 'disconnected',
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `last_sync_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_instance_identifier` (`internal_identifier`),
            UNIQUE KEY `uq_chat_instance_evolution_name` (`evolution_instance_name`),
            KEY `idx_chat_instance_state` (`active`, `connection_status`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$conversations} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `remote_jid` VARCHAR(191) NOT NULL,
            `phone_number` VARCHAR(32) NULL,
            `contact_name` VARCHAR(191) NULL,
            `profile_picture_url` TEXT NULL,
            `last_message_preview` TEXT NULL,
            `last_message_at` DATETIME NULL,
            `unread_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `archived` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'open',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_conversation_remote` (`instance_id`, `remote_jid`),
            KEY `idx_chat_conversation_queue` (`instance_id`, `status`, `archived`, `deleted`, `last_message_at`),
            KEY `idx_chat_conversation_activity` (`last_message_at`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$messages} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `external_message_id` VARCHAR(191) NULL,
            `remote_jid` VARCHAR(191) NOT NULL,
            `direction` VARCHAR(16) NOT NULL,
            `message_type` VARCHAR(32) NOT NULL DEFAULT 'text',
            `text_content` LONGTEXT NULL,
            `media_url` TEXT NULL,
            `mime_type` VARCHAR(191) NULL,
            `status` VARCHAR(32) NULL,
            `sent_at` DATETIME NULL,
            `message_timestamp` BIGINT UNSIGNED NULL,
            `dedupe_key` CHAR(64) NULL,
            `client_message_id` VARCHAR(191) NULL,
            `raw_payload` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_msg_instance_external` (`instance_id`, `external_message_id`),
            UNIQUE KEY `uq_chat_msg_instance_dedupe` (`instance_id`, `dedupe_key`),
            UNIQUE KEY `uq_chat_msg_conversation_client` (`conversation_id`, `client_message_id`),
            KEY `idx_chat_msg_conversation_time` (`conversation_id`, `message_timestamp`, `id`),
            KEY `idx_chat_msg_instance_remote` (`instance_id`, `remote_jid`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$webhookLogs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance_id` BIGINT UNSIGNED NULL,
            `event_name` VARCHAR(120) NOT NULL,
            `event_dedupe_key` CHAR(64) NULL,
            `payload` LONGTEXT NULL,
            `response_payload` LONGTEXT NULL,
            `error_message` TEXT NULL,
            `http_status` SMALLINT UNSIGNED NULL,
            `success` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `processed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_webhook_event_dedupe` (`event_dedupe_key`),
            KEY `idx_chat_webhook_instance_event` (`instance_id`, `event_name`, `created_at`),
            KEY `idx_chat_webhook_result` (`success`, `http_status`, `deleted`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $this->seedSafeDefaults();
    }

    public function down(): void
    {
        foreach (['chat_webhook_logs', 'chat_messages', 'chat_conversations', 'chat_instances', 'chat_settings'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function seedSafeDefaults(): void
    {
        $defaults = [
            'polling_interval_ms' => '5000',
            'evolution_timeout_seconds' => '30',
            'evolution_endpoint_connection_state' => '/instance/connectionState/{instance}',
            'evolution_endpoint_find_chats' => '/chat/findChats/{instance}',
            'evolution_endpoint_find_messages' => '/chat/findMessages/{instance}',
            'evolution_endpoint_send_text' => '/message/sendText/{instance}',
        ];

        foreach ($defaults as $key => $value) {
            $this->insertDefault($key, $value, false);
        }

        if (!$this->settingExists('webhook_secret')) {
            $cipher = new Credential_cipher();
            $this->insertDefault(
                'webhook_secret',
                $cipher->encrypt(Credential_cipher::generateSecret()),
                true
            );
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
            ->countAllResults() > 0;
    }

    private function table(string $logicalName): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logicalName));
    }
}
