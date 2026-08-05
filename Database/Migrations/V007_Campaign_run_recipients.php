<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Separates the reusable campaign audience from immutable per-occurrence
 * deliveries. This preserves recipient history across recurring campaigns and
 * lets delayed provider receipts resolve to the correct occurrence.
 */
class V007_Campaign_run_recipients extends Migration
{
    public const VERSION = 7;

    public function up(): void
    {
        $table = $this->table('chat_campaign_run_recipients');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `run_id` BIGINT UNSIGNED NOT NULL,
            `campaign_id` BIGINT UNSIGNED NOT NULL,
            `audience_recipient_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED NULL,
            `phone_hash` CHAR(64) NOT NULL,
            `phone_normalized` VARCHAR(32) NOT NULL,
            `variables_json` LONGTEXT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
            `available_at` DATETIME NULL,
            `queued_at` DATETIME NULL,
            `last_attempt_at` DATETIME NULL,
            `external_message_id` VARCHAR(191) NULL,
            `error_message` TEXT NULL,
            `sent_at` DATETIME NULL,
            `delivered_at` DATETIME NULL,
            `read_at` DATETIME NULL,
            `replied_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_campaign_run_recipient` (`run_id`, `audience_recipient_id`),
            KEY `idx_chat_campaign_run_recipient_queue` (`run_id`, `status`, `available_at`, `deleted`),
            KEY `idx_chat_campaign_run_recipient_campaign` (`campaign_id`, `run_id`, `deleted`),
            KEY `idx_chat_campaign_run_recipient_external` (`external_message_id`, `deleted`),
            KEY `idx_chat_campaign_run_recipient_phone` (`campaign_id`, `phone_hash`, `sent_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    public function down(): void
    {
        // Production-safe migrations are intentionally non-destructive.
    }

    private function table(string $logical): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logical));
    }
}
