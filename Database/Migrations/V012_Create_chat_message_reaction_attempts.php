<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Separates outbound reaction attempts from the confirmed reaction state. */
class V012_Create_chat_message_reaction_attempts extends Migration
{
    public const VERSION = 12;

    public function up(): void
    {
        $table = (string) $this->db->escapeIdentifiers($this->db->prefixTable('chat_message_reaction_attempts'));
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `provider_name` VARCHAR(32) NOT NULL,
            `client_message_id` VARCHAR(191) NOT NULL,
            `requested_emoji` VARCHAR(32) NULL,
            `requested_active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `send_state` VARCHAR(32) NOT NULL DEFAULT 'awaiting_provider',
            `provider_event_id` VARCHAR(191) NULL,
            `actor_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_reaction_attempt_client` (`instance_id`, `client_message_id`),
            KEY `idx_chat_reaction_attempt_target_created` (`message_id`, `created_at`),
            KEY `idx_chat_reaction_attempt_state` (`instance_id`, `send_state`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        // V011 is historical and remains untouched. This nullable ordering
        // field is additive so late provider events cannot regress state.
        $reactionsTable = $this->db->prefixTable('chat_message_reactions');
        if ($this->db->fieldExists('provider_timestamp', $reactionsTable) === false) {
            $escaped = (string) $this->db->escapeIdentifiers($reactionsTable);
            $this->db->query("ALTER TABLE {$escaped} ADD COLUMN `provider_timestamp` DATETIME NULL AFTER `provider_event_id`");
        }
        if (!$this->hasIndex($reactionsTable, 'idx_chat_reaction_provider_time')) {
            $escaped = (string) $this->db->escapeIdentifiers($reactionsTable);
            $this->db->query("ALTER TABLE {$escaped} ADD KEY `idx_chat_reaction_provider_time` (`message_id`, `provider_timestamp`)");
        }
    }

    public function down(): void
    {
        // Forward-only: attempts and confirmed reaction history are retained.
    }

    private function hasIndex(string $table, string $index): bool
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->getRowArray();

        return (int) ($row['total'] ?? 0) > 0;
    }
}
