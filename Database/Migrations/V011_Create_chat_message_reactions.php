<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Stores provider-scoped reaction state without rewriting message history. */
class V011_Create_chat_message_reactions extends Migration
{
    public const VERSION = 11;

    public function up(): void
    {
        $table = (string) $this->db->escapeIdentifiers($this->db->prefixTable('chat_message_reactions'));
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `provider_name` VARCHAR(32) NOT NULL,
            `reactor_key` VARCHAR(191) NOT NULL,
            `emoji` VARCHAR(32) NULL,
            `from_me` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `send_state` VARCHAR(32) NOT NULL DEFAULT 'sent',
            `client_message_id` VARCHAR(191) NULL,
            `provider_event_id` VARCHAR(191) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_reaction_target_actor` (`message_id`, `reactor_key`),
            UNIQUE KEY `uq_chat_reaction_client` (`instance_id`, `client_message_id`),
            KEY `idx_chat_reaction_target` (`message_id`, `active`, `deleted`),
            KEY `idx_chat_reaction_provider_event` (`instance_id`, `provider_event_id`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    public function down(): void
    {
        // Forward-only: reaction history must not be destroyed by rollback.
    }
}
