<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Retires modules that are outside the focused WhatsApp product.
 *
 * Historical tables are preserved for rollback/export, but their records are
 * disabled and obsolete settings are hidden. No customer message is deleted.
 */
class V009_Retire_legacy_ai_reports_and_n8n extends Migration
{
    public const VERSION = 9;

    public function up(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $settings = $this->db->prefixTable('chat_settings');
        if ($this->db->tableExists($settings, false)) {
            $keys = [
                'n8n_base_url', 'n8n_token', 'n8n_auth_mode', 'n8n_header_name',
                'n8n_allow_private_networks', 'n8n_timeout_seconds', 'n8n_health_path',
                'n8n_campaigns_path', 'n8n_ai_path', 'n8n_events_path',
                'ai_default_state', 'ai_human_priority', 'ai_show_context',
                'ai_stop_command', 'ai_start_command', 'ai_auto_return_minutes',
                'sla_minutes',
            ];
            $this->db->table($settings)
                ->whereIn('setting_key', $keys)
                ->update(['deleted' => 1, 'updated_at' => $now]);
        }

        foreach (['chat_ai_agents', 'chat_automations'] as $logical) {
            $table = $this->db->prefixTable($logical);
            if ($this->db->tableExists($table, false) && $this->db->fieldExists('active', $table)) {
                $this->db->table($table)->where('deleted', 0)->update(['active' => 0, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Re-enabling retired automation endpoints automatically would be unsafe.
    }
}
