<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Libraries;

use Chatwoot_plugin\Database\Migrations\V001_Create_chat_tables;
use Chatwoot_plugin\Database\Migrations\V002_Create_operational_domain;
use Chatwoot_plugin\Database\Migrations\V003_Backfill_conversation_contacts;
use Chatwoot_plugin\Database\Migrations\V004_Add_channels_groups_and_bots;
use Chatwoot_plugin\Database\Migrations\V005_Internal_campaign_dispatch;
use Chatwoot_plugin\Database\Migrations\V006_Bot_versions_and_campaign_runs;
use Chatwoot_plugin\Database\Migrations\V007_Campaign_run_recipients;
use Chatwoot_plugin\Database\Migrations\V008_Migrate_legacy_campaign_dispatch;
use Chatwoot_plugin\Database\Migrations\V009_Retire_legacy_ai_reports_and_n8n;
use Chatwoot_plugin\Database\Migrations\V010_Add_message_delivery_timestamps;
use Chatwoot_plugin\Database\Migrations\V011_Create_chat_message_reactions;
use Chatwoot_plugin\Database\Migrations\V012_Create_chat_message_reaction_attempts;
use Chatwoot_plugin\Database\Migrations\V013_Harden_chat_message_reactions;
use Chatwoot_plugin\Database\Migrations\V014_Add_conversation_workflow_snooze;
use Chatwoot_plugin\Database\Migrations\V015_Collaboration_productivity;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Database/Migrations/V001_Create_chat_tables.php';
require_once dirname(__DIR__) . '/Database/Migrations/V002_Create_operational_domain.php';
require_once dirname(__DIR__) . '/Database/Migrations/V003_Backfill_conversation_contacts.php';
require_once dirname(__DIR__) . '/Database/Migrations/V004_Add_channels_groups_and_bots.php';
require_once dirname(__DIR__) . '/Database/Migrations/V005_Internal_campaign_dispatch.php';
require_once dirname(__DIR__) . '/Database/Migrations/V006_Bot_versions_and_campaign_runs.php';
require_once dirname(__DIR__) . '/Database/Migrations/V007_Campaign_run_recipients.php';
require_once dirname(__DIR__) . '/Database/Migrations/V008_Migrate_legacy_campaign_dispatch.php';
require_once dirname(__DIR__) . '/Database/Migrations/V009_Retire_legacy_ai_reports_and_n8n.php';
require_once dirname(__DIR__) . '/Database/Migrations/V010_Add_message_delivery_timestamps.php';
require_once dirname(__DIR__) . '/Database/Migrations/V011_Create_chat_message_reactions.php';
require_once dirname(__DIR__) . '/Database/Migrations/V012_Create_chat_message_reaction_attempts.php';
require_once dirname(__DIR__) . '/Database/Migrations/V013_Harden_chat_message_reactions.php';
require_once dirname(__DIR__) . '/Database/Migrations/V014_Add_conversation_workflow_snooze.php';
require_once dirname(__DIR__) . '/Database/Migrations/V015_Collaboration_productivity.php';

/**
 * Small plugin-owned migration runner.
 *
 * It avoids relying on plugin namespace discovery during first installation,
 * serializes concurrent installers with a MySQL advisory lock and records the
 * applied version inside chat_settings (no sixth plugin table is introduced).
 */
class Migration_runner
{
    private const VERSION_SETTING = 'schema_version';

    private BaseConnection $db;
    private int $lockTimeout;

    public function __construct(?BaseConnection $db = null, int $lockTimeout = 15)
    {
        $this->db = $db ?? db_connect('default');
        $this->lockTimeout = max(1, $lockTimeout);
    }

    public function run(): bool
    {
        $this->migrate();

        return true;
    }

    public function migrate(): void
    {
        $locked = false;

        try {
            $this->acquireLock();
            $locked = true;

            $currentVersion = $this->currentVersion();
            $migrations = [
                V001_Create_chat_tables::VERSION => V001_Create_chat_tables::class,
                V002_Create_operational_domain::VERSION => V002_Create_operational_domain::class,
                V003_Backfill_conversation_contacts::VERSION => V003_Backfill_conversation_contacts::class,
                V004_Add_channels_groups_and_bots::VERSION => V004_Add_channels_groups_and_bots::class,
                V005_Internal_campaign_dispatch::VERSION => V005_Internal_campaign_dispatch::class,
                V006_Bot_versions_and_campaign_runs::VERSION => V006_Bot_versions_and_campaign_runs::class,
                V007_Campaign_run_recipients::VERSION => V007_Campaign_run_recipients::class,
                V008_Migrate_legacy_campaign_dispatch::VERSION => V008_Migrate_legacy_campaign_dispatch::class,
                V009_Retire_legacy_ai_reports_and_n8n::VERSION => V009_Retire_legacy_ai_reports_and_n8n::class,
                V010_Add_message_delivery_timestamps::VERSION => V010_Add_message_delivery_timestamps::class,
                V011_Create_chat_message_reactions::VERSION => V011_Create_chat_message_reactions::class,
                V012_Create_chat_message_reaction_attempts::VERSION => V012_Create_chat_message_reaction_attempts::class,
                V013_Harden_chat_message_reactions::VERSION => V013_Harden_chat_message_reactions::class,
                V014_Add_conversation_workflow_snooze::VERSION => V014_Add_conversation_workflow_snooze::class,
                V015_Collaboration_productivity::VERSION => V015_Collaboration_productivity::class,
            ];

            ksort($migrations, SORT_NUMERIC);
            foreach ($migrations as $version => $migrationClass) {
                if ((int) $version <= $currentVersion) {
                    continue;
                }

                $forge = Database::forge($this->db);
                $migration = new $migrationClass($forge);
                $migration->up();
                $this->recordVersion((int) $version);
                $currentVersion = (int) $version;
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Chatwoot plugin database migration failed.', 0, $exception);
        } finally {
            if ($locked) {
                $this->releaseLock();
            }
        }
    }

    public function currentVersion(): int
    {
        $table = $this->db->prefixTable('chat_settings');
        if (!$this->db->tableExists($table, false)) {
            return 0;
        }

        $row = $this->db->table($table)
            ->select('setting_value')
            ->where('setting_key', self::VERSION_SETTING)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return isset($row['setting_value']) ? max(0, (int) $row['setting_value']) : 0;
    }

    private function recordVersion(int $version): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $table = $this->db->prefixTable('chat_settings');
        $builder = $this->db->table($table);
        $existing = $builder
            ->select('id')
            ->where('setting_key', self::VERSION_SETTING)
            ->get(1)
            ->getRowArray();

        $data = [
            'setting_key' => self::VERSION_SETTING,
            'setting_value' => (string) $version,
            'is_encrypted' => 0,
            'updated_at' => $now,
            'deleted' => 0,
        ];

        if ($existing) {
            $this->db->table($table)->where('id', (int) $existing['id'])->update($data);
            return;
        }

        $data['created_at'] = $now;
        $this->db->table($table)->insert($data);
    }

    private function acquireLock(): void
    {
        $row = $this->db->query(
            'SELECT GET_LOCK(?, ?) AS migration_lock',
            [$this->lockName(), $this->lockTimeout]
        )->getRowArray();

        if ((int) ($row['migration_lock'] ?? 0) !== 1) {
            throw new RuntimeException('Could not acquire the Chatwoot migration lock.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        } catch (Throwable $exception) {
            log_message('error', 'Could not release Chatwoot migration lock: {message}', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function lockName(): string
    {
        return 'chatwoot_migration_' . substr(sha1($this->db->getPrefix()), 0, 32);
    }
}
