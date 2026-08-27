<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__, 3);
define('FCPATH', $root . DIRECTORY_SEPARATOR);
$_SERVER['CI_ENVIRONMENT'] = 'development';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/rise/index.php';
$_SERVER['REQUEST_URI'] = '/rise/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = '80';
defined('ENVIRONMENT') || define('ENVIRONMENT', (string) $_SERVER['CI_ENVIRONMENT']);
defined('CI_DEBUG') || define('CI_DEBUG', true);
chdir($root);

require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

Config\Services::autoloader()->addNamespace('Chatwoot_plugin', dirname(__DIR__));

$runner = new Chatwoot_plugin\Libraries\Migration_runner();
$runner->migrate();

$db = db_connect('default');
$tables = [
    'chat_instances',
    'chat_conversations',
    'chat_messages',
    'chat_message_reactions',
    'chat_message_reaction_attempts',
    'chat_message_reaction_changes',
    'chat_webhook_logs',
    'chat_settings',
    'chat_contacts',
    'chat_contact_identifiers',
    'chat_groups',
    'chat_group_participants',
    'chat_tags',
    'chat_contact_tags',
    'chat_conversation_tags',
    'chat_internal_notes',
    'chat_quick_replies',
    'chat_media',
    'chat_campaigns',
    'chat_campaign_runs',
    'chat_campaign_recipients',
    'chat_campaign_run_recipients',
    'chat_campaign_templates',
    'chat_bot_flows',
    'chat_bot_flow_versions',
    'chat_bot_sessions',
    'chat_bot_events',
    'chat_ai_agents',
    'chat_automations',
    'chat_ai_states',
    'chat_ai_logs',
    'chat_notifications',
    'chat_audit_logs',
    'chat_integration_jobs',
    'chat_internal_note_mentions',
    'chat_saved_views',
    'chat_conversation_presence',
];

foreach ($tables as $logicalName) {
    $table = $db->prefixTable($logicalName);
    if (!$db->tableExists($table, false)) {
        fwrite(STDERR, "Missing table: {$table}\n");
        exit(1);
    }

    $status = $db->query('SHOW TABLE STATUS LIKE ?', [$table])->getRowArray();
    if (!$status || strtolower((string) ($status['Engine'] ?? '')) !== 'innodb') {
        fwrite(STDERR, "Unexpected storage engine on {$table}\n");
        exit(1);
    }
    if (strtolower((string) ($status['Collation'] ?? '')) !== 'utf8mb4_unicode_ci') {
        fwrite(STDERR, "Unexpected collation on {$table}\n");
        exit(1);
    }
}

$requiredIndexes = [
    $db->prefixTable('chat_instances') => ['uq_chat_instance_identifier', 'uq_chat_instance_evolution_name'],
    $db->prefixTable('chat_conversations') => ['uq_chat_conversation_remote', 'idx_chat_conversation_snooze'],
    $db->prefixTable('chat_messages') => [
        'uq_chat_msg_instance_external',
        'uq_chat_msg_instance_dedupe',
        'uq_chat_msg_conversation_client',
    ],
    $db->prefixTable('chat_message_reactions') => [
        'uq_chat_reaction_target_actor',
        'uq_chat_reaction_client',
        'idx_chat_reaction_target',
        'idx_chat_reaction_provider_time',
        'idx_chat_reaction_source_attempt',
    ],
    $db->prefixTable('chat_message_reaction_attempts') => [
        'uq_chat_reaction_attempt_client',
        'idx_chat_reaction_attempt_target_created',
        'idx_chat_reaction_attempt_provider_event',
    ],
    $db->prefixTable('chat_message_reaction_changes') => ['idx_chat_reaction_change_message', 'idx_chat_reaction_change_instance'],
    $db->prefixTable('chat_webhook_logs') => ['uq_chat_webhook_event_dedupe'],
    $db->prefixTable('chat_contacts') => ['uq_chat_contact_scope', 'idx_chat_contact_phone'],
    $db->prefixTable('chat_contact_identifiers') => ['uq_chat_contact_identifier'],
    $db->prefixTable('chat_groups') => ['uq_chat_group_remote', 'idx_chat_group_activity'],
    $db->prefixTable('chat_group_participants') => ['uq_chat_group_participant', 'idx_chat_group_participant_contact'],
    $db->prefixTable('chat_quick_replies') => ['uq_chat_quick_reply_shortcut'],
    $db->prefixTable('chat_campaigns') => ['uq_chat_campaign_idempotency', 'idx_chat_campaign_status'],
    $db->prefixTable('chat_campaign_recipients') => ['uq_chat_campaign_recipient', 'idx_chat_campaign_recipient_dispatch'],
    $db->prefixTable('chat_campaign_runs') => ['uq_chat_campaign_occurrence', 'idx_chat_campaign_run_schedule'],
    $db->prefixTable('chat_campaign_run_recipients') => ['uq_chat_campaign_run_recipient', 'idx_chat_campaign_run_recipient_queue'],
    $db->prefixTable('chat_bot_flows') => ['idx_chat_bot_flow_match', 'idx_chat_bot_flow_status'],
    $db->prefixTable('chat_bot_flow_versions') => ['uq_chat_bot_flow_version'],
    $db->prefixTable('chat_bot_sessions') => ['uq_chat_bot_session_conversation'],
    $db->prefixTable('chat_ai_states') => ['uq_chat_ai_state_conversation'],
    $db->prefixTable('chat_notifications') => ['uq_chat_notification_dedupe'],
    $db->prefixTable('chat_integration_jobs') => ['idx_chat_job_queue'],
];

foreach ($requiredIndexes as $table => $expected) {
    $rows = $db->query('SHOW INDEX FROM ' . $db->escapeIdentifiers($table))->getResultArray();
    $actual = array_values(array_unique(array_column($rows, 'Key_name')));
    foreach ($expected as $index) {
        if (!in_array($index, $actual, true)) {
            fwrite(STDERR, "Missing index {$index} on {$table}\n");
            exit(1);
        }
    }
}

$requiredColumns = [
    $db->prefixTable('chat_instances') => ['provider_type', 'meta_phone_number_id', 'meta_waba_id', 'meta_access_token_encrypted', 'meta_verify_token_encrypted', 'meta_app_secret_encrypted'],
    $db->prefixTable('chat_contacts') => ['name_source', 'name_updated_at', 'last_incoming_name'],
    $db->prefixTable('chat_conversations') => ['contact_id', 'priority', 'assignee_id', 'team_id', 'resolved_at', 'resolved_by', 'conversation_type', 'group_id', 'service_window_expires_at', 'snoozed_until', 'snoozed_by', 'bot_paused_at', 'bot_handoff_reason', 'last_human_message_at', 'last_bot_message_at', 'first_response_at', 'first_response_seconds'],
    $db->prefixTable('chat_messages') => ['sender_user_id', 'sender_contact_id', 'sender_jid', 'sender_phone', 'sender_name', 'is_group_message', 'reply_to_external_message_id', 'caption', 'file_name', 'file_size', 'media_id', 'is_internal_note', 'delivery_error', 'failed_at', 'delivered_at', 'read_at'],
    $db->prefixTable('chat_message_reactions') => ['provider_timestamp', 'source_attempt_id', 'state_order_at', 'state_order_kind', 'state_order_key'],
    $db->prefixTable('chat_message_reaction_attempts') => ['message_id', 'instance_id', 'provider_name', 'client_message_id', 'requested_emoji', 'previous_emoji', 'previous_active', 'previous_from_me', 'previous_source_attempt_id', 'requested_active', 'send_state', 'provider_event_id', 'provider_status', 'provider_error_code', 'provider_error_message', 'provider_status_at', 'actor_user_id', 'created_at', 'updated_at', 'deleted'],
    $db->prefixTable('chat_campaigns') => ['campaign_type', 'template_id', 'template_parameters_json', 'dispatch_mode', 'rate_limit_per_minute', 'started_at', 'finished_at'],
    $db->prefixTable('chat_campaign_runs') => ['occurrence_key', 'scheduled_at', 'recipient_count'],
];
foreach ($requiredColumns as $table => $expected) {
    $actual = array_map(static fn ($field): string => (string) $field->name, $db->getFieldData($table));
    foreach ($expected as $column) {
        if (!in_array($column, $actual, true)) {
            fwrite(STDERR, "Missing column {$column} on {$table}\n");
            exit(1);
        }
    }
}

// V003 must link a legacy conversation without overwriting external data and
// must remain idempotent when its repair pass is executed again.
$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$instanceName = 'migration_backfill_' . $suffix;
$instanceId = 0;
$conversationId = 0;
$contactId = 0;
$backfillOk = false;
try {
    $instances = new Chatwoot_plugin\Models\Chat_instances_model();
    $conversations = new Chatwoot_plugin\Models\Chat_conversations_model();
    $instanceId = $instances->upsert_instance($instanceName, ['name' => 'Migration Backfill', 'evolution_instance_name' => $instanceName, 'base_url' => 'https://evolution.invalid', 'connection_status' => 'connected', 'active' => 1]);
    $phone = '55119' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    $conversationId = $conversations->upsert_conversation($instanceId, $phone . '@s.whatsapp.net', ['phone_number' => $phone, 'contact_name' => 'Contato Legado', 'last_message_at' => gmdate('Y-m-d H:i:s')]);
    $forge = Config\Database::forge($db);
    $migration = new Chatwoot_plugin\Database\Migrations\V003_Backfill_conversation_contacts($forge);
    $migration->up();
    $linked = $db->table('chat_conversations')->select('contact_id')->where('id', $conversationId)->get(1)->getRowArray();
    $contactId = (int) ($linked['contact_id'] ?? 0);
    $identifierCount = $contactId > 0 ? $db->table('chat_contact_identifiers')->where('contact_id', $contactId)->where('deleted', 0)->countAllResults() : 0;
    $migration->up();
    $identifierCountAfter = $contactId > 0 ? $db->table('chat_contact_identifiers')->where('contact_id', $contactId)->where('deleted', 0)->countAllResults() : 0;
    $backfillOk = $contactId > 0 && $identifierCount === 2 && $identifierCountAfter === 2;
} finally {
    if ($contactId > 0) {
        $db->table('chat_contact_identifiers')->where('contact_id', $contactId)->delete();
        $db->table('chat_contacts')->where('id', $contactId)->delete();
    }
    if ($conversationId > 0) $db->table('chat_conversations')->where('id', $conversationId)->delete();
    if ($instanceId > 0) $db->table('chat_instances')->where('id', $instanceId)->delete();
}
if (!$backfillOk) {
    fwrite(STDERR, "V003 legacy contact backfill or idempotency failed.\n");
    exit(1);
}

// A second upgrade must be idempotent and preserve pre-existing data.
$sentinel = 'migration_smoke_' . bin2hex(random_bytes(5));
$settingsTable = $db->prefixTable('chat_settings');
$db->table($settingsTable)->insert(['setting_key' => $sentinel, 'setting_value' => 'preserved', 'is_encrypted' => 0, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'deleted' => 0]);
$runner->migrate();
$preserved = $db->table($settingsTable)->where('setting_key', $sentinel)->where('setting_value', 'preserved')->countAllResults() === 1;
$db->table($settingsTable)->where('setting_key', $sentinel)->delete();
if (!$preserved || $runner->currentVersion() !== 15) {
    fwrite(STDERR, "Migration rerun was not idempotent or did not preserve data.\n");
    exit(1);
}

echo 'Migration smoke test passed; schema version=' . $runner->currentVersion() . PHP_EOL;
