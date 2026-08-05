<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Makes bot publications immutable and enriches campaign runs without
 * destroying any existing production data.
 */
class V006_Bot_versions_and_campaign_runs extends Migration
{
    public const VERSION = 6;

    public function up(): void
    {
        $versions = $this->table('chat_bot_flow_versions');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$versions} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `flow_id` BIGINT UNSIGNED NOT NULL,
            `version` INT UNSIGNED NOT NULL,
            `instance_id` BIGINT UNSIGNED NULL,
            `priority` INT NOT NULL DEFAULT 0,
            `trigger_type` VARCHAR(32) NOT NULL DEFAULT 'first_message',
            `trigger_config_json` LONGTEXT NULL,
            `definition_json` LONGTEXT NOT NULL,
            `business_hours_json` LONGTEXT NULL,
            `fallback_message` LONGTEXT NOT NULL,
            `handoff_message` LONGTEXT NOT NULL,
            `max_fallbacks` INT UNSIGNED NOT NULL DEFAULT 2,
            `ignore_groups` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `published_by` BIGINT UNSIGNED NULL,
            `published_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_bot_flow_version` (`flow_id`, `version`),
            KEY `idx_chat_bot_flow_version_lookup` (`flow_id`, `published_at`, `deleted`),
            KEY `idx_chat_bot_flow_version_trigger` (`instance_id`, `trigger_type`, `priority`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        // A run now has an occurrence key and scheduling metadata. Existing rows
        // remain valid and are treated as legacy occurrences.
        $this->addColumn('chat_campaign_runs', 'occurrence_key', 'VARCHAR(191) NULL');
        $this->addColumn('chat_campaign_runs', 'scheduled_at', 'DATETIME NULL');
        $this->addColumn('chat_campaign_runs', 'recipient_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $this->addIndex('chat_campaign_runs', 'uq_chat_campaign_occurrence', ['campaign_id', 'occurrence_key'], true);
        $this->addIndex('chat_campaign_runs', 'idx_chat_campaign_run_schedule', ['status', 'scheduled_at', 'deleted']);

        $this->addColumn('chat_campaign_recipients', 'run_id', 'BIGINT UNSIGNED NULL');
        $this->addIndex('chat_campaign_recipients', 'idx_chat_campaign_recipient_run', ['run_id', 'status', 'deleted']);

        $this->insertDefault('campaign_recurring_timezone', 'America/Sao_Paulo');
    }

    public function down(): void
    {
        // Production-safe migrations are intentionally non-destructive.
    }

    private function table(string $logical): string
    {
        return (string) $this->db->escapeIdentifiers($this->db->prefixTable($logical));
    }

    private function addColumn(string $logicalTable, string $column, string $definition): void
    {
        $raw = $this->db->prefixTable($logicalTable);
        if (!$this->db->fieldExists($column, $raw)) {
            $table = (string) $this->db->escapeIdentifiers($raw);
            $this->db->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}");
        }
    }

    /** @param array<int,string> $columns */
    private function addIndex(string $logicalTable, string $name, array $columns, bool $unique = false): void
    {
        $raw = $this->db->prefixTable($logicalTable);
        $indexes = $this->db->getIndexData($raw);
        if (isset($indexes[$name])) return;
        $escaped = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $table = (string) $this->db->escapeIdentifiers($raw);
        $kind = $unique ? 'UNIQUE KEY' : 'KEY';
        $this->db->query("ALTER TABLE {$table} ADD {$kind} `{$name}` ({$escaped})");
    }

    private function insertDefault(string $key, string $value): void
    {
        $table = $this->db->prefixTable('chat_settings');
        if ($this->db->table($table)->where('setting_key', $key)->where('deleted', 0)->countAllResults() > 0) return;
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
}
