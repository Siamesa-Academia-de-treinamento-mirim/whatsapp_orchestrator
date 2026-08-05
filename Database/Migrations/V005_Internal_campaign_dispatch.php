<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds retry-safe, provider-neutral internal campaign dispatch. */
class V005_Internal_campaign_dispatch extends Migration
{
    public const VERSION = 5;

    public function up(): void
    {
        $recipientColumns = [
            'variables_json' => 'LONGTEXT NULL',
            'attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'max_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 5',
            'available_at' => 'DATETIME NULL',
            'last_attempt_at' => 'DATETIME NULL',
            'delivered_at' => 'DATETIME NULL',
            'read_at' => 'DATETIME NULL',
            'replied_at' => 'DATETIME NULL',
        ];
        foreach ($recipientColumns as $name => $definition) $this->addColumn('chat_campaign_recipients', $name, $definition);
        $this->addIndex('chat_campaign_recipients', 'idx_chat_campaign_recipient_dispatch', ['status','available_at','campaign_id','deleted']);
        $this->addIndex('chat_campaign_recipients', 'idx_chat_campaign_recipient_external', ['external_message_id','deleted']);

        foreach ([
            'started_at' => 'DATETIME NULL',
            'finished_at' => 'DATETIME NULL',
        ] as $name => $definition) $this->addColumn('chat_campaigns', $name, $definition);

        $this->insertDefault('campaign_default_rate_limit_per_minute', '20');
        $this->insertDefault('campaign_recipient_max_attempts', '5');
        $this->insertDefault('campaign_retry_delay_seconds', '120');
        $this->insertDefault('campaign_worker_batch', '100');
    }

    public function down(): void {}

    private function addColumn(string $logicalTable, string $column, string $definition): void
    {
        $raw = $this->db->prefixTable($logicalTable);
        if (!$this->db->fieldExists($column, $raw)) {
            $table = (string) $this->db->escapeIdentifiers($raw);
            $this->db->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}");
        }
    }

    /** @param array<int,string> $columns */
    private function addIndex(string $logicalTable, string $name, array $columns): void
    {
        $raw = $this->db->prefixTable($logicalTable);
        $indexes = $this->db->getIndexData($raw);
        if (isset($indexes[$name])) return;
        $escaped = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $table = (string) $this->db->escapeIdentifiers($raw);
        $this->db->query("ALTER TABLE {$table} ADD KEY `{$name}` ({$escaped})");
    }

    private function insertDefault(string $key, string $value): void
    {
        $table = $this->db->prefixTable('chat_settings');
        if ($this->db->table($table)->where('setting_key', $key)->where('deleted', 0)->countAllResults() > 0) return;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table($table)->insert(['setting_key'=>$key,'setting_value'=>$value,'is_encrypted'=>0,'created_at'=>$now,'updated_at'=>$now,'deleted'=>0]);
    }
}
