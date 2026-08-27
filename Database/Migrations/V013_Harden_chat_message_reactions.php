<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds reaction status correlation, rollback authority and a monotonic change cursor. */
class V013_Harden_chat_message_reactions extends Migration
{
    public const VERSION = 13;

    public function up(): void
    {
        $reactionsTable = $this->db->prefixTable('chat_message_reactions');
        $attemptsTable = $this->db->prefixTable('chat_message_reaction_attempts');

        $this->addColumn($reactionsTable, 'source_attempt_id', 'BIGINT UNSIGNED NULL AFTER `provider_timestamp`');
        $this->addColumn($reactionsTable, 'state_order_at', 'DATETIME(6) NULL AFTER `source_attempt_id`');
        $this->addColumn($reactionsTable, 'state_order_kind', 'VARCHAR(16) NULL AFTER `state_order_at`');
        $this->addColumn($reactionsTable, 'state_order_key', 'VARCHAR(191) NULL AFTER `state_order_kind`');
        $this->addIndex($reactionsTable, 'idx_chat_reaction_source_attempt', '`source_attempt_id`, `message_id`');

        $this->addColumn($attemptsTable, 'previous_emoji', 'VARCHAR(32) NULL AFTER `requested_emoji`');
        $this->addColumn($attemptsTable, 'previous_active', 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `previous_emoji`');
        $this->addColumn($attemptsTable, 'previous_from_me', 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER `previous_active`');
        $this->addColumn($attemptsTable, 'previous_source_attempt_id', 'BIGINT UNSIGNED NULL AFTER `previous_from_me`');
        $this->addColumn($attemptsTable, 'provider_status', 'VARCHAR(32) NULL AFTER `provider_event_id`');
        $this->addColumn($attemptsTable, 'provider_error_code', 'VARCHAR(64) NULL AFTER `provider_status`');
        $this->addColumn($attemptsTable, 'provider_error_message', 'VARCHAR(1000) NULL AFTER `provider_error_code`');
        $this->addColumn($attemptsTable, 'provider_status_at', 'DATETIME(6) NULL AFTER `provider_error_message`');
        $this->addIndex($attemptsTable, 'idx_chat_reaction_attempt_provider_event', '`instance_id`, `provider_event_id`, `deleted`');

        $changesTable = (string) $this->db->escapeIdentifiers($this->db->prefixTable('chat_message_reaction_changes'));
        $this->db->query("CREATE TABLE IF NOT EXISTS {$changesTable} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reaction_id` BIGINT UNSIGNED NOT NULL,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (`id`),
            KEY `idx_chat_reaction_change_message` (`message_id`, `id`),
            KEY `idx_chat_reaction_change_instance` (`instance_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    public function down(): void
    {
        // Forward-only: reaction status and ordering history must not be destroyed.
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        if ($this->db->fieldExists($column, $table)) {
            return;
        }

        $escapedTable = (string) $this->db->escapeIdentifiers($table);
        $this->db->query("ALTER TABLE {$escapedTable} ADD COLUMN `{$column}` {$definition}");
    }

    private function addIndex(string $table, string $index, string $columns): void
    {
        if ($this->hasIndex($table, $index)) {
            return;
        }

        $escapedTable = (string) $this->db->escapeIdentifiers($table);
        $this->db->query("ALTER TABLE {$escapedTable} ADD KEY `{$index}` ({$columns})");
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
