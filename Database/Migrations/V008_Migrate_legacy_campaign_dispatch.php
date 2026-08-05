<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Moves campaigns created by the removed n8n dispatcher to the internal queue.
 *
 * Running legacy campaigns are paused deliberately: silently resuming them after
 * an upgrade could duplicate a broadcast. Their audience and history remain
 * untouched and an administrator can review and resume them from the UI.
 */
class V008_Migrate_legacy_campaign_dispatch extends Migration
{
    public const VERSION = 8;

    public function up(): void
    {
        $raw = $this->db->prefixTable('chat_campaigns');
        if (!$this->db->tableExists($raw, false) || !$this->db->fieldExists('dispatch_mode', $raw)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $table = (string) $this->db->escapeIdentifiers($raw);

        // Pause only records that were actively controlled by the removed
        // external dispatcher. Scheduled/paused/draft records preserve state.
        $this->db->query(
            "UPDATE {$table}
             SET `last_error` = CASE
                     WHEN `status` = 'running' THEN 'Campanha pausada durante a migracao do n8n para a fila interna. Revise e retome manualmente.'
                     ELSE `last_error`
                 END,
                 `status` = CASE WHEN `status` = 'running' THEN 'paused' ELSE `status` END,
                 `dispatch_mode` = 'internal_queue',
                 `external_id` = CASE
                     WHEN `external_id` IS NULL OR `external_id` = '' THEN CONCAT('local-', `id`)
                     ELSE `external_id`
                 END,
                 `updated_at` = ?
             WHERE `deleted` = 0
               AND (`dispatch_mode` IS NULL OR `dispatch_mode` = '' OR `dispatch_mode` <> 'internal_queue')",
            [$now]
        );
    }

    public function down(): void
    {
        // This migration is intentionally irreversible. Restoring an external
        // dispatcher automatically would be unsafe and could duplicate sends.
    }
}
