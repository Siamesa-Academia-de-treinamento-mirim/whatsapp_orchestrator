<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds durable conversation snooze state for the Inbox workflow. */
class V014_Add_conversation_workflow_snooze extends Migration
{
    public const VERSION = 14;

    public function up(): void
    {
        $raw = $this->db->prefixTable('chat_conversations');
        $table = (string) $this->db->escapeIdentifiers($raw);

        $this->addColumn($raw, $table, 'snoozed_until', 'DATETIME NULL');
        $this->addColumn($raw, $table, 'snoozed_by', 'BIGINT UNSIGNED NULL');
        $this->addIndex($raw, $table, 'idx_chat_conversation_snooze', '`status`, `snoozed_until`, `deleted`');
    }

    public function down(): void
    {
        // Forward-only: snooze history and workflow state must not be removed
        // by an accidental rollback attempt.
    }

    private function addColumn(string $raw, string $table, string $column, string $definition): void
    {
        if ($this->db->fieldExists($column, $raw)) {
            return;
        }

        $this->db->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}");
    }

    private function addIndex(string $raw, string $table, string $index, string $columns): void
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$raw, $index]
        )->getRowArray();
        if ((int) ($row['total'] ?? 0) > 0) {
            return;
        }

        $this->db->query("ALTER TABLE {$table} ADD KEY `{$index}` ({$columns})");
    }
}
