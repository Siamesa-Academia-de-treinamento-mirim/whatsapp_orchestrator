<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_integration_jobs_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

class Integration_job_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_integration_jobs_model $jobs = null,
        private ?Chat_settings_model $settings = null,
        ?BaseConnection $db = null
    ) {
        $this->jobs ??= new Chat_integration_jobs_model();
        $this->settings ??= new Chat_settings_model();
        $this->db = $db ?? db_connect('default');
    }

    public function enqueue(string $type, array $payload = [], int $maxAttempts = 5, ?string $correlation = null): int
    {
        return $this->jobs->create_record([
            'job_type' => mb_substr(trim($type), 0, 100),
            'status' => 'pending',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'max_attempts' => min(20, max(1, $maxAttempts)),
            'available_at' => gmdate('Y-m-d H:i:s'),
            'correlation_id' => $correlation,
        ]);
    }

    /** @return array<string,mixed> */
    public function run(string $worker = 'cli', int $limit = 50): array
    {
        $row = $this->db->query('SELECT GET_LOCK(?, 0) acquired', ['chatwoot_plugin_jobs'])->getRowArray();
        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Outro worker do Impulso Hub ja esta em execucao.', 409);
        }

        $result = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'scheduled' => 0, 'maintenance' => []];
        try {
            $result['scheduled'] = $this->schedulePendingWork();
            $rows = $this->db->table('chat_integration_jobs')
                ->where('deleted', 0)
                ->whereIn('status', ['pending', 'retry'])
                ->where('available_at <=', gmdate('Y-m-d H:i:s'))
                ->orderBy('id', 'ASC')
                ->limit(min(200, max(1, $limit)))
                ->get()
                ->getResultArray();

            foreach ($rows as $job) {
                $result['processed']++;
                $id = (int) $job['id'];
                $attempt = (int) $job['attempts'] + 1;
                $this->jobs->update_record($id, [
                    'status' => 'running',
                    'attempts' => $attempt,
                    'locked_at' => gmdate('Y-m-d H:i:s'),
                    'locked_by' => mb_substr($worker, 0, 191),
                ]);
                try {
                    $this->process((string) $job['job_type'], $this->json((string) ($job['payload_json'] ?? '')));
                    $this->jobs->update_record($id, ['status' => 'completed', 'locked_at' => null, 'locked_by' => null, 'last_error' => null]);
                    $result['completed']++;
                } catch (Throwable $exception) {
                    $final = $attempt >= (int) $job['max_attempts'];
                    $safeError = mb_substr($exception->getMessage(), 0, 1000);
                    $this->jobs->update_record($id, [
                        'status' => $final ? 'failed' : 'retry',
                        'available_at' => gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(6, $attempt)))),
                        'locked_at' => null,
                        'locked_by' => null,
                        'last_error' => $safeError,
                    ]);
                    if ($final) {
                        $this->notifyPersistentFailure($job, $safeError);
                    }
                    $result['failed']++;
                }
            }
            $result['maintenance'] = $this->maintenance();
            return $result;
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', ['chatwoot_plugin_jobs']);
        }
    }

    private function schedulePendingWork(): int
    {
        $scheduled = 0;
        $pendingLogs = $this->db->table('chat_webhook_logs')
            ->select('id,payload')
            ->where('deleted', 0)
            ->where('success', 0)
            ->where('processed_at IS NULL', null, false)
            ->orderBy('id', 'ASC')
            ->limit(25)
            ->get()
            ->getResultArray();
        foreach ($pendingLogs as $log) {
            $correlation = 'webhook-log-' . (int) $log['id'];
            if ($this->hasActiveJob($correlation)) {
                continue;
            }
            $event = $this->json((string) ($log['payload'] ?? ''));
            if (!$event || !empty($event['_truncated']) || !empty($event['logging_disabled'])) {
                continue;
            }
            $this->enqueue('webhook_retry', ['event' => $event], 5, $correlation);
            $scheduled++;
        }

        if ($this->due('instance-status-periodic', 300)) {
            $this->enqueue('instance_status', [], 3, 'instance-status-periodic');
            $scheduled++;
        }
        if ($this->due('campaign-internal-schedule-periodic', 60)) {
            $this->enqueue('campaign_schedule', [], 3, 'campaign-internal-schedule-periodic');
            $scheduled++;
        }
        return $scheduled;
    }

    private function process(string $type, array $payload): void
    {
        if ($type === 'instance_status') {
            (new Chat_service())->refresh_all_instance_statuses();
            return;
        }
        if ($type === 'webhook_retry') {
            if (empty($payload['event']) || !is_array($payload['event'])) {
                throw new RuntimeException('Job de webhook sem evento.');
            }
            $result = (new Chat_service())->process_webhook_event($payload['event']);
            if (empty($result['processed']) && empty($result['duplicate'])) {
                throw new RuntimeException('Webhook continua pendente.');
            }
            return;
        }
        if ($type === 'bot_process') {
            $messageId = (int) ($payload['message_id'] ?? 0);
            if ($messageId < 1) throw new RuntimeException('Job do bot sem mensagem valida.');
            (new Bot_service())->process_message($messageId);
            return;
        }
        if ($type === 'campaign_schedule') {
            (new Campaign_dispatch_service())->scheduleDue();
            return;
        }
        if ($type === 'campaign_recipient') {
            $campaignId = (int) ($payload['campaign_id'] ?? 0);
            $runId = (int) ($payload['run_id'] ?? 0);
            $recipientId = (int) ($payload['recipient_id'] ?? 0);
            if ($campaignId < 1 || $runId < 1 || $recipientId < 1) throw new RuntimeException('Job de campanha sem execucao ou destinatario valido.');
            (new Campaign_dispatch_service())->dispatchRecipient($campaignId, $recipientId, $runId);
            return;
        }
        if ($type !== 'maintenance') {
            throw new RuntimeException('Tipo de job desconhecido: ' . $type . '.');
        }
    }

    /** @return array<string,int> */
    private function maintenance(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $webhookDays = max(1, (int) $this->settings->get_value('webhook_retention_days', 30));
        $auditDays = max(1, (int) $this->settings->get_value('audit_retention_days', 180));
        $conversationDays = max(0, (int) $this->settings->get_value('conversation_retention_days', 0));
        $mediaDays = max(0, (int) $this->settings->get_value('media_retention_days', 30));

        $this->db->table('chat_webhook_logs')->where('created_at <', gmdate('Y-m-d H:i:s', time() - $webhookDays * 86400))->where('deleted', 0)->update(['deleted' => 1, 'updated_at' => $now]);
        $webhookCount = $this->db->affectedRows();
        $this->db->table('chat_audit_logs')->where('created_at <', gmdate('Y-m-d H:i:s', time() - $auditDays * 86400))->where('deleted', 0)->update(['deleted' => 1, 'updated_at' => $now]);
        $auditCount = $this->db->affectedRows();

        $staleMessages = $this->db->table('chat_messages')->select('id')->where('status', 'sending')->where('updated_at <', gmdate('Y-m-d H:i:s', time() - 900))->where('deleted', 0)->get()->getResultArray();
        $this->db->table('chat_messages')->where('status', 'sending')->where('updated_at <', gmdate('Y-m-d H:i:s', time() - 900))->where('deleted', 0)->update(['status' => 'failed', 'delivery_error' => 'Envio otimista expirado sem confirmacao.', 'failed_at' => $now, 'updated_at' => $now]);
        $optimistic = $this->db->affectedRows();
        if ($staleMessages) {
            (new Notification_service())->create('message_failed', 'Mensagens sem confirmacao', count($staleMessages) . ' envio(s) otimista(s) expiraram sem confirmacao da Evolution.', 'message', null, null, 'danger', 'optimistic-expired|' . gmdate('YmdHi'));
        }

        $conversations = 0;
        if ($conversationDays > 0) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - $conversationDays * 86400);
            $ids = array_column($this->db->table('chat_conversations')->select('id')->where('deleted', 0)->where('status', 'resolved')->where('resolved_at <', $cutoff)->get()->getResultArray(), 'id');
            if ($ids) {
                $this->db->table('chat_messages')->whereIn('conversation_id', $ids)->update(['deleted' => 1, 'updated_at' => $now]);
                $this->db->table('chat_conversations')->whereIn('id', $ids)->update(['deleted' => 1, 'updated_at' => $now]);
                $conversations = count($ids);
            }
        }

        $mediaCount = 0;
        if ($mediaDays > 0) {
            $rows = $this->db->table('chat_media')->select('id,storage_path')->where('deleted', 0)->where('created_at <', gmdate('Y-m-d H:i:s', time() - $mediaDays * 86400))->get()->getResultArray();
            $root = realpath(rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads');
            foreach ($rows as $row) {
                $path = realpath(rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['storage_path']));
                if ($root && $path && str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) && is_file($path)) {
                    @unlink($path);
                }
                $this->db->table('chat_media')->where('id', (int) $row['id'])->update(['deleted' => 1, 'updated_at' => $now]);
                $mediaCount++;
            }
        }
        $this->db->table('chat_integration_jobs')->where('deleted', 0)->whereIn('status', ['completed', 'failed'])->where('updated_at <', gmdate('Y-m-d H:i:s', time() - 30 * 86400))->update(['deleted' => 1, 'updated_at' => $now]);

        return ['webhooks' => $webhookCount, 'audit' => $auditCount, 'optimistic_messages' => $optimistic, 'conversations' => $conversations, 'media' => $mediaCount];
    }

    private function hasActiveJob(string $correlation): bool
    {
        return $this->db->table('chat_integration_jobs')->where('correlation_id', $correlation)->where('deleted', 0)->whereIn('status', ['pending', 'retry', 'running'])->countAllResults() > 0;
    }

    private function due(string $correlation, int $seconds): bool
    {
        $row = $this->db->table('chat_integration_jobs')->select('created_at')->where('correlation_id', $correlation)->where('deleted', 0)->orderBy('id', 'DESC')->get(1)->getRowArray();
        return !$row || strtotime((string) $row['created_at'] . ' UTC') <= time() - $seconds;
    }

    private function notifyPersistentFailure(array $job, string $error): void
    {
        try {
            (new Notification_service())->create('webhook', 'Falha persistente de integracao', (string) $job['job_type'] . ': ' . $error, 'integration_job', (int) $job['id'], null, 'danger', 'job-failed|' . $job['id']);
        } catch (Throwable $exception) {
            // The failed job remains the source of truth if notification fails.
        }
    }

    private function json(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

}
