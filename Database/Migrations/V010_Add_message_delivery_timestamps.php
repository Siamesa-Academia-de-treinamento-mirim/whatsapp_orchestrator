<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds provider receipt timestamps without rewriting historical messages. */
class V010_Add_message_delivery_timestamps extends Migration
{
    public const VERSION = 10;

    public function up(): void
    {
        $this->addColumn('delivered_at', 'DATETIME NULL');
        $this->addColumn('read_at', 'DATETIME NULL');
    }

    public function down(): void
    {
        // Forward-only migration: receipt history must not be destroyed by an
        // accidental rollback attempt.
    }

    private function addColumn(string $column, string $definition): void
    {
        $table = $this->table('chat_messages');
        $rawTable = $this->db->prefixTable('chat_messages');
        if (!$this->db->fieldExists($column, $rawTable)) {
            $this->db->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function table(string $logicalName): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logicalName));
    }
}
