<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds additive collaboration/productivity storage for Inbox 3 Phase 7. */
class V015_Collaboration_productivity extends Migration
{
    public const VERSION = 15;

    public function up(): void
    {
        $this->createMentions();
        $this->createSavedViews();
        $this->createPresence();
    }

    public function down(): void
    {
        // Forward-only. Mentions, private views and presence state are not
        // destructive rollback targets.
    }

    private function createMentions(): void
    {
        $table = $this->table('chat_internal_note_mentions');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `note_id` BIGINT UNSIGNED NOT NULL,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `mentioned_user_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_note_mention` (`note_id`, `mentioned_user_id`),
            KEY `idx_chat_note_mention_message` (`message_id`, `deleted`),
            KEY `idx_chat_note_mention_conversation` (`conversation_id`, `deleted`),
            KEY `idx_chat_note_mention_user` (`mentioned_user_id`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function createSavedViews(): void
    {
        $table = $this->table('chat_saved_views');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `filters_json` LONGTEXT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_chat_saved_view_owner` (`user_id`, `deleted`, `sort_order`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function createPresence(): void
    {
        $table = $this->table('chat_conversation_presence');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `viewing` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `typing_until` DATETIME NULL,
            `last_seen_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_conversation_presence` (`conversation_id`, `user_id`),
            KEY `idx_chat_presence_expiry` (`conversation_id`, `expires_at`, `deleted`),
            KEY `idx_chat_presence_user` (`user_id`, `expires_at`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    private function table(string $logicalName): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logicalName));
    }
}
