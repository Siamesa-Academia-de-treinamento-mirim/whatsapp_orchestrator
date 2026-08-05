<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_campaign_recipients_model;
use Chatwoot_plugin\Models\Chat_campaign_run_recipients_model;
use Chatwoot_plugin\Models\Chat_campaign_runs_model;
use Chatwoot_plugin\Models\Chat_campaign_templates_model;
use Chatwoot_plugin\Models\Chat_campaigns_model;
use Chatwoot_plugin\Models\Chat_contacts_model;
use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Retry-safe, provider-neutral campaign dispatcher.
 *
 * Campaign recipients are the reusable audience. Every campaign occurrence
 * receives an immutable snapshot in chat_campaign_run_recipients, preventing a
 * recurring campaign from erasing prior delivery receipts or recipient history.
 */
class Campaign_dispatch_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_campaigns_model $campaigns = null,
        private ?Chat_campaign_recipients_model $audienceRecipients = null,
        private ?Chat_campaign_run_recipients_model $runRecipients = null,
        private ?Chat_campaign_runs_model $runs = null,
        private ?Chat_campaign_templates_model $templates = null,
        private ?Chat_instances_model $instances = null,
        private ?Chat_conversations_model $conversations = null,
        private ?Chat_contacts_model $contacts = null,
        private ?Chat_settings_model $settings = null,
        ?BaseConnection $db = null
    ) {
        $this->campaigns ??= new Chat_campaigns_model();
        $this->audienceRecipients ??= new Chat_campaign_recipients_model();
        $this->runRecipients ??= new Chat_campaign_run_recipients_model();
        $this->runs ??= new Chat_campaign_runs_model();
        $this->templates ??= new Chat_campaign_templates_model();
        $this->instances ??= new Chat_instances_model();
        $this->conversations ??= new Chat_conversations_model();
        $this->contacts ??= new Chat_contacts_model();
        $this->settings ??= new Chat_settings_model();
        $this->db = $db ?? db_connect('default');
    }

    /** Moves due campaigns to running and enqueues one rate-limited minute batch. */
    public function scheduleDue(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = $this->db->table('chat_campaigns')
            ->where('deleted', 0)
            ->where('dispatch_mode', 'internal_queue')
            ->whereIn('status', ['scheduled', 'running'])
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
            ->getResultArray();

        $queued = 0;
        foreach ($rows as $campaign) {
            $campaignId = (int) $campaign['id'];
            $schedule = $this->decode((string) ($campaign['schedule_json'] ?? ''));
            if (($campaign['status'] ?? '') === 'scheduled' && !$this->isDue($schedule)) {
                continue;
            }

            $run = $this->ensureActiveRun($campaign, $schedule);
            $runId = (int) ($run['id'] ?? 0);
            if ($runId < 1) {
                continue;
            }

            $campaign = $this->campaigns->get_by_id($campaignId) ?: $campaign;
            $limit = min(1000, max(1, (int) (
                $campaign['rate_limit_per_minute']
                ?? $this->settings->get_value('campaign_default_rate_limit_per_minute', 20)
            )));

            $recipientIds = $this->reserveQueueBatch($campaignId, $runId, $limit, $now);
            foreach ($recipientIds as $runRecipientId) {
                $correlation = sprintf(
                    'campaign-%d-run-%d-recipient-%d',
                    $campaignId,
                    $runId,
                    $runRecipientId
                );
                if ($this->hasActiveJob($correlation)) {
                    continue;
                }

                try {
                    (new Integration_job_service())->enqueue('campaign_recipient', [
                        'campaign_id' => $campaignId,
                        'run_id' => $runId,
                        'recipient_id' => $runRecipientId,
                    ], 5, $correlation);
                    $queued++;
                } catch (Throwable $exception) {
                    // Let a later scheduler pass reserve the row again.
                    $this->runRecipients->update_record($runRecipientId, ['queued_at' => null]);
                    log_message('error', 'Could not enqueue campaign recipient: {message}', [
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $this->finishIfComplete($campaignId);
        }

        return $queued;
    }

    /** @return array<string,mixed> */
    public function dispatchRecipient(int $campaignId, int $runRecipientId, ?int $expectedRunId = null): array
    {
        $campaign = $this->campaigns->get_by_id($campaignId);
        $recipient = $this->runRecipients->get_by_id($runRecipientId);
        if (!$campaign || !$recipient || (int) $recipient['campaign_id'] !== $campaignId) {
            throw new InvalidArgumentException('Campanha ou destinatario da execucao invalido.');
        }
        if (($campaign['dispatch_mode'] ?? '') !== 'internal_queue') {
            return ['processed' => false, 'reason' => 'external_dispatch'];
        }
        if (($campaign['status'] ?? '') !== 'running') {
            return ['processed' => false, 'reason' => 'campaign_not_running'];
        }

        $runId = (int) ($recipient['run_id'] ?? 0);
        if ($runId < 1 || ($expectedRunId !== null && $expectedRunId !== $runId)) {
            return ['processed' => false, 'reason' => 'campaign_run_mismatch'];
        }
        $run = $this->runs->get_by_id($runId);
        if (!$run || ($run['status'] ?? '') !== 'running') {
            return ['processed' => false, 'reason' => 'campaign_run_not_running'];
        }
        if ($this->isTerminalRecipientStatus((string) ($recipient['status'] ?? ''))) {
            return ['processed' => false, 'duplicate' => true];
        }
        if (!empty($recipient['available_at']) && strtotime((string) $recipient['available_at']) > time()) {
            return ['processed' => false, 'reason' => 'recipient_not_available'];
        }

        $lock = 'chat_campaign_run_recipient_' . $runRecipientId;
        $this->acquireLock($lock, 2, 'Destinatario ocupado; tente novamente.');
        try {
            $recipient = $this->runRecipients->get_by_id($runRecipientId) ?: $recipient;
            if ($this->isTerminalRecipientStatus((string) ($recipient['status'] ?? ''))) {
                return ['processed' => false, 'duplicate' => true];
            }
            if (!empty($recipient['available_at']) && strtotime((string) $recipient['available_at']) > time()) {
                return ['processed' => false, 'reason' => 'recipient_not_available'];
            }

            $attempts = (int) ($recipient['attempts'] ?? 0) + 1;
            $maxAttempts = min(20, max(1, (int) (
                $recipient['max_attempts']
                ?? $this->settings->get_value('campaign_recipient_max_attempts', 5)
            )));
            $this->runRecipients->update_record($runRecipientId, [
                'status' => 'sending',
                'attempts' => $attempts,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'error_message' => null,
            ]);

            try {
                $instance = $this->instances->get_by_id((int) $campaign['instance_id']);
                if (!$instance || empty($instance['active']) || ($instance['connection_status'] ?? '') !== 'connected') {
                    throw new RuntimeException('Instancia da campanha desconectada.');
                }

                $phone = preg_replace('/\D+/', '', (string) ($recipient['phone_normalized'] ?? '')) ?: '';
                if ($phone === '') {
                    throw new RuntimeException('Destinatario sem telefone valido.');
                }

                $contact = !empty($recipient['contact_id'])
                    ? $this->contacts->get_by_id((int) $recipient['contact_id'])
                    : $this->contacts->find_by_phone($phone, (int) $instance['id']);
                if ($contact && !empty($contact['opt_out'])) {
                    $this->runRecipients->update_record($runRecipientId, [
                        'status' => 'opt_out',
                        'error_message' => 'Contato opt-out antes do disparo.',
                    ]);
                    $this->refreshMetrics($campaignId, $runId);
                    $this->finishIfComplete($campaignId);
                    return ['processed' => true, 'status' => 'opt_out'];
                }

                $remoteJid = $phone . '@s.whatsapp.net';
                $conversationId = $this->conversations->upsert_conversation((int) $instance['id'], $remoteJid, [
                    'phone_number' => $phone,
                    'contact_name' => trim((string) ($contact['name'] ?? '')) ?: $phone,
                    'contact_id' => $contact ? (int) $contact['id'] : null,
                    'conversation_type' => 'individual',
                ]);
                $variables = $this->variables($recipient, $contact, $phone);
                $clientId = sprintf('campaign-%d-run-%d-recipient-%d', $campaignId, $runId, $runRecipientId);
                $type = strtolower((string) ($campaign['campaign_type'] ?? 'unofficial'));

                if ($type === 'official') {
                    if (($instance['provider_type'] ?? '') !== 'meta_cloud') {
                        throw new RuntimeException('Campanha oficial exige instancia Meta Cloud API.');
                    }
                    $template = !empty($campaign['template_id'])
                        ? $this->templates->get_by_id((int) $campaign['template_id'])
                        : null;
                    if (
                        !$template
                        || (int) ($template['instance_id'] ?? 0) !== (int) $instance['id']
                        || strtolower((string) ($template['provider_status'] ?? '')) !== 'approved'
                    ) {
                        throw new RuntimeException('Template oficial aprovado nao encontrado para esta instancia.');
                    }
                    $components = $this->renderStructured(
                        $this->decode((string) ($campaign['template_parameters_json'] ?? '')),
                        $variables
                    );
                    $sent = (new Chat_service())->send_template(
                        $conversationId,
                        (string) $template['name'],
                        (string) ($template['language_code'] ?? 'pt_BR'),
                        $components,
                        $clientId,
                        0
                    );
                } else {
                    if (($instance['provider_type'] ?? '') !== 'evolution') {
                        throw new RuntimeException('Campanha nao oficial exige instancia Evolution.');
                    }
                    $text = $this->renderText((string) $campaign['message_content'], $variables);
                    $sent = (new Chat_service())->send_text($conversationId, $text, $clientId, 0);
                }

                $externalId = trim((string) ($sent['external_message_id'] ?? ''));
                $this->runRecipients->update_record($runRecipientId, [
                    'status' => 'sent',
                    'external_message_id' => $externalId !== '' ? $externalId : null,
                    'sent_at' => gmdate('Y-m-d H:i:s'),
                    'available_at' => null,
                    'error_message' => null,
                ]);
                $this->refreshMetrics($campaignId, $runId);
                $this->finishIfComplete($campaignId);

                return [
                    'processed' => true,
                    'status' => 'sent',
                    'message_id' => $sent['id'] ?? null,
                    'external_message_id' => $externalId ?: null,
                ];
            } catch (Throwable $exception) {
                $final = $attempts >= $maxAttempts;
                $delay = min(
                    3600,
                    max(30, (int) $this->settings->get_value('campaign_retry_delay_seconds', 120))
                    * max(1, $attempts)
                );
                $error = mb_substr($exception->getMessage(), 0, 1000);
                $this->runRecipients->update_record($runRecipientId, [
                    'status' => $final ? 'failed' : 'retry',
                    'error_message' => $error,
                    'available_at' => $final ? null : gmdate('Y-m-d H:i:s', time() + $delay),
                ]);
                $this->campaigns->update_record($campaignId, ['last_error' => $error]);
                $this->refreshMetrics($campaignId, $runId);
                $this->finishIfComplete($campaignId);

                // Recipient retries are controlled by available_at and the
                // campaign scheduler, not by a second independent job retry loop.
                return [
                    'processed' => true,
                    'status' => $final ? 'failed' : 'retry',
                    'error' => $error,
                ];
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** Marks the most recent campaign delivery for this phone as replied. */
    public function markLatestRecipientReplied(int $instanceId, string $phone): void
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if ($instanceId < 1 || $phone === '') {
            return;
        }

        $campaigns = $this->db->prefixTable('chat_campaigns');
        $recipients = $this->db->prefixTable('chat_campaign_run_recipients');
        $row = $this->db->table($recipients)
            ->select($recipients . '.id, ' . $recipients . '.campaign_id, ' . $recipients . '.run_id')
            ->join($campaigns, $campaigns . '.id=' . $recipients . '.campaign_id AND ' . $campaigns . '.deleted=0')
            ->where($campaigns . '.instance_id', $instanceId)
            ->where($recipients . '.phone_normalized', $phone)
            ->where($recipients . '.deleted', 0)
            ->whereIn($recipients . '.status', ['sent', 'delivered', 'read'])
            ->where($recipients . '.sent_at >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
            ->orderBy($recipients . '.sent_at', 'DESC')
            ->get(1)
            ->getRowArray();
        if (!$row) {
            return;
        }

        $this->runRecipients->update_record((int) $row['id'], [
            'status' => 'replied',
            'replied_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->refreshMetrics((int) $row['campaign_id'], (int) $row['run_id']);
        $this->finishIfComplete((int) $row['campaign_id']);
    }

    public function updateDeliveryStatus(string $externalMessageId, string $status, ?string $error = null): void
    {
        $externalMessageId = trim($externalMessageId);
        $status = strtolower(trim($status));
        if ($externalMessageId === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $row = $this->db->table('chat_campaign_run_recipients')
            ->where('external_message_id', $externalMessageId)
            ->where('deleted', 0)
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();
        if (!$row) {
            return;
        }

        $rank = [
            'pending' => 0,
            'retry' => 0,
            'sending' => 0,
            'sent' => 10,
            'delivered' => 20,
            'read' => 30,
            'replied' => 40,
            'failed' => -1,
            'opt_out' => -1,
        ];
        $current = (string) ($row['status'] ?? 'pending');
        if ($status !== 'failed' && ($rank[$status] ?? 0) <= ($rank[$current] ?? 0)) {
            return;
        }
        if ($current === 'replied') {
            return;
        }

        $payload = ['status' => $status];
        if ($status === 'delivered') {
            $payload['delivered_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($status === 'read') {
            $payload['read_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($status === 'failed') {
            $payload['error_message'] = mb_substr(
                trim((string) $error) ?: 'Falha reportada pelo provedor.',
                0,
                1000
            );
        }

        $this->runRecipients->update_record((int) $row['id'], $payload);
        $this->refreshMetrics((int) $row['campaign_id'], (int) $row['run_id']);
        $this->finishIfComplete((int) $row['campaign_id']);
    }

    private function finishIfComplete(int $campaignId): void
    {
        $lock = 'chat_campaign_finish_' . $campaignId;
        if (!$this->tryAcquireLock($lock, 1)) {
            return;
        }

        try {
            $run = $this->db->table('chat_campaign_runs')
                ->where('campaign_id', $campaignId)
                ->where('status', 'running')
                ->where('deleted', 0)
                ->orderBy('id', 'DESC')
                ->get(1)
                ->getRowArray();
            $runId = (int) ($run['id'] ?? 0);
            if ($runId < 1) {
                return;
            }

            $builder = $this->db->table('chat_campaign_run_recipients')
                ->where('run_id', $runId)
                ->where('deleted', 0);
            $remaining = (clone $builder)
                ->whereIn('status', ['pending', 'retry', 'sending'])
                ->countAllResults();
            if ($remaining > 0) {
                return;
            }

            $total = (clone $builder)->countAllResults();
            if ($total < 1) {
                return;
            }

            $metrics = $this->collectMetrics($campaignId, $runId);
            $failed = (int) ($metrics['failed'] ?? 0);
            $finalStatus = $failed === $total ? 'failed' : 'completed';
            $finishedAt = gmdate('Y-m-d H:i:s');
            $this->runs->update_record($runId, [
                'status' => $finalStatus,
                'metrics_json' => $this->encode($metrics),
                'finished_at' => $finishedAt,
                'error_message' => $finalStatus === 'failed'
                    ? 'Todos os destinatarios falharam nesta ocorrencia.'
                    : null,
            ]);

            $campaign = $this->campaigns->get_by_id($campaignId);
            if (!$campaign) {
                return;
            }
            $schedule = $this->decode((string) ($campaign['schedule_json'] ?? ''));
            if (($schedule['type'] ?? '') === 'recurring') {
                $schedule['next_at'] = $this->nextOccurrence($schedule);
                $this->campaigns->update_record($campaignId, [
                    'status' => 'scheduled',
                    'schedule_json' => $this->encode($schedule),
                    'metrics_json' => $this->encode($metrics),
                    'finished_at' => $finishedAt,
                    'last_error' => $finalStatus === 'failed'
                        ? 'A ultima ocorrencia falhou para todos os destinatarios.'
                        : null,
                ]);
                return;
            }

            $this->campaigns->update_record($campaignId, [
                'status' => $finalStatus,
                'metrics_json' => $this->encode($metrics),
                'finished_at' => $finishedAt,
            ]);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function refreshMetrics(int $campaignId, ?int $runId = null): void
    {
        if ($runId === null) {
            $run = $this->db->table('chat_campaign_runs')
                ->select('id')
                ->where('campaign_id', $campaignId)
                ->where('deleted', 0)
                ->orderBy('id', 'DESC')
                ->get(1)
                ->getRowArray();
            $runId = !empty($run['id']) ? (int) $run['id'] : null;
        }
        if (!$runId) {
            return;
        }

        $metrics = $this->collectMetrics($campaignId, $runId);
        $encoded = $this->encode($metrics);
        $this->campaigns->update_record($campaignId, [
            'metrics_json' => $encoded,
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->runs->update_record($runId, ['metrics_json' => $encoded]);
    }

    /** @return array<string,int> */
    private function collectMetrics(int $campaignId, int $runId): array
    {
        $rows = $this->db->table('chat_campaign_run_recipients')
            ->select('status, COUNT(id) total', false)
            ->where('campaign_id', $campaignId)
            ->where('run_id', $runId)
            ->where('deleted', 0)
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $metrics = [
            'audience' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'replied' => 0,
            'failed' => 0,
            'opt_out' => 0,
            'pending' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $count = (int) $row['total'];
            $metrics['audience'] += $count;
            if (in_array($status, ['sent', 'delivered', 'read', 'replied'], true)) {
                $metrics['sent'] += $count;
            }
            if (in_array($status, ['delivered', 'read', 'replied'], true)) {
                $metrics['delivered'] += $count;
            }
            if (in_array($status, ['read', 'replied'], true)) {
                $metrics['read'] += $count;
            }
            if ($status === 'replied') {
                $metrics['replied'] += $count;
            }
            if ($status === 'failed') {
                $metrics['failed'] += $count;
            }
            if ($status === 'opt_out') {
                $metrics['opt_out'] += $count;
            }
            if (in_array($status, ['pending', 'retry', 'sending'], true)) {
                $metrics['pending'] += $count;
            }
        }

        return $metrics;
    }

    /** @return array<string,string> */
    private function variables(array $recipient, ?array $contact, string $phone): array
    {
        $custom = $this->decode((string) ($recipient['variables_json'] ?? ''));
        $base = [
            'phone' => $phone,
            'telefone' => $phone,
            'name' => (string) ($contact['name'] ?? $custom['name'] ?? $phone),
            'nome' => (string) ($contact['name'] ?? $custom['name'] ?? $phone),
            'company' => (string) ($contact['company'] ?? $custom['company'] ?? ''),
            'empresa' => (string) ($contact['company'] ?? $custom['company'] ?? ''),
            'city' => (string) ($contact['city'] ?? $custom['city'] ?? ''),
            'cidade' => (string) ($contact['city'] ?? $custom['city'] ?? ''),
        ];
        $merged = array_merge($base, $custom);
        if (!isset($merged['1'])) {
            $merged['1'] = $merged['name'];
        }

        return array_map(
            static fn ($value): string => is_scalar($value) ? trim((string) $value) : '',
            $merged
        );
    }

    private function renderText(string $template, array $variables): string
    {
        $missing = [];
        $rendered = preg_replace_callback(
            '/\{([A-Za-z0-9_.-]+)\}/u',
            static function (array $match) use ($variables, &$missing): string {
                $key = $match[1];
                if (!array_key_exists($key, $variables) || $variables[$key] === '') {
                    $missing[$key] = true;
                    return $match[0];
                }
                return (string) $variables[$key];
            },
            $template
        );
        if ($missing) {
            throw new RuntimeException(
                'Variaveis ausentes para o destinatario: ' . implode(', ', array_keys($missing)) . '.'
            );
        }

        $rendered = trim((string) $rendered);
        if ($rendered === '' || mb_strlen($rendered) > 4096) {
            throw new RuntimeException('Mensagem renderizada invalida ou acima de 4096 caracteres.');
        }

        return $rendered;
    }

    /** @return mixed */
    private function renderStructured($value, array $variables)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->renderStructured($item, $variables);
            }
            return $result;
        }
        if (is_string($value)) {
            return $this->renderTextFragment($value, $variables);
        }
        return $value;
    }

    private function renderTextFragment(string $value, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z0-9_.-]+)\}/u',
            static function (array $match) use ($variables): string {
                $key = $match[1];
                if (!array_key_exists($key, $variables) || $variables[$key] === '') {
                    throw new RuntimeException('Variavel ausente no template oficial: ' . $key . '.');
                }
                return (string) $variables[$key];
            },
            $value
        );
    }

    /** @return array<string,mixed> */
    private function ensureActiveRun(array $campaign, array $schedule): array
    {
        $campaignId = (int) $campaign['id'];
        $lock = 'chat_campaign_start_' . $campaignId;
        $this->acquireLock($lock, 3, 'Campanha ocupada; tente novamente.');

        try {
            $active = $this->db->table('chat_campaign_runs')
                ->where('campaign_id', $campaignId)
                ->where('status', 'running')
                ->where('deleted', 0)
                ->orderBy('id', 'DESC')
                ->get(1)
                ->getRowArray();
            if ($active) {
                $count = $this->snapshotAudience((int) $active['id'], $campaignId);
                if ($count < 1) {
                    throw new RuntimeException('Campanha sem destinatarios ativos.');
                }
                $this->runs->update_record((int) $active['id'], ['recipient_count' => $count]);
                if (($campaign['status'] ?? '') !== 'running') {
                    $this->campaigns->update_record($campaignId, ['status' => 'running']);
                }
                return $this->runs->get_by_id((int) $active['id']) ?: $active;
            }

            $scheduledAt = $this->scheduledAt($schedule);
            $occurrenceKey = $this->occurrenceKey($campaignId, $schedule, $scheduledAt);
            $existing = $this->db->table('chat_campaign_runs')
                ->where('campaign_id', $campaignId)
                ->where('occurrence_key', $occurrenceKey)
                ->where('deleted', 0)
                ->get(1)
                ->getRowArray();

            if ($existing && in_array((string) ($existing['status'] ?? ''), ['completed', 'failed'], true)) {
                $this->reconcileTerminalOccurrence($campaign, $schedule, $existing);
                return [];
            }

            $audienceCount = $this->db->table('chat_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->where('deleted', 0)
                ->countAllResults();
            if ($audienceCount < 1) {
                throw new RuntimeException('Campanha sem destinatarios ativos.');
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($existing) {
                $runId = (int) $existing['id'];
                $this->runs->update_record($runId, [
                    'status' => 'running',
                    'started_at' => $now,
                    'finished_at' => null,
                    'error_message' => null,
                    'recipient_count' => $audienceCount,
                ]);
            } else {
                $runId = $this->runs->create_record([
                    'campaign_id' => $campaignId,
                    'external_run_id' => 'local-' . $campaignId . '-' . $occurrenceKey,
                    'occurrence_key' => $occurrenceKey,
                    'status' => 'running',
                    'metrics_json' => $this->encode($this->emptyMetrics($audienceCount)),
                    'scheduled_at' => $scheduledAt,
                    'recipient_count' => $audienceCount,
                    'started_at' => $now,
                ]);
            }

            $snapshotCount = $this->snapshotAudience($runId, $campaignId);
            if ($snapshotCount < 1) {
                $this->runs->update_record($runId, [
                    'status' => 'failed',
                    'finished_at' => $now,
                    'error_message' => 'Campanha sem destinatarios ativos.',
                ]);
                throw new RuntimeException('Campanha sem destinatarios ativos.');
            }

            $metrics = $this->emptyMetrics($snapshotCount);
            $this->runs->update_record($runId, [
                'recipient_count' => $snapshotCount,
                'metrics_json' => $this->encode($metrics),
            ]);
            $this->campaigns->update_record($campaignId, [
                'status' => 'running',
                'started_at' => $now,
                'finished_at' => null,
                'last_error' => null,
                'metrics_json' => $this->encode($metrics),
            ]);

            return $this->runs->get_by_id($runId) ?: [];
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function snapshotAudience(int $runId, int $campaignId): int
    {
        $maxAttempts = min(20, max(1, (int) $this->settings->get_value(
            'campaign_recipient_max_attempts',
            5
        )));
        $now = gmdate('Y-m-d H:i:s');
        $runTable = (string) $this->db->escapeIdentifiers(
            $this->db->prefixTable('chat_campaign_run_recipients')
        );
        $audienceTable = (string) $this->db->escapeIdentifiers(
            $this->db->prefixTable('chat_campaign_recipients')
        );

        $sql = "INSERT IGNORE INTO {$runTable} (
            `run_id`, `campaign_id`, `audience_recipient_id`, `contact_id`,
            `phone_hash`, `phone_normalized`, `variables_json`, `status`,
            `attempts`, `max_attempts`, `available_at`, `queued_at`,
            `last_attempt_at`, `external_message_id`, `error_message`, `sent_at`,
            `delivered_at`, `read_at`, `replied_at`, `created_at`, `updated_at`, `deleted`
        )
        SELECT ?, audience.`campaign_id`, audience.`id`, audience.`contact_id`,
            audience.`phone_hash`, audience.`phone_normalized`, audience.`variables_json`, 'pending',
            0, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, 0
        FROM {$audienceTable} audience
        WHERE audience.`campaign_id` = ? AND audience.`deleted` = 0";
        $this->db->query($sql, [$runId, $maxAttempts, $now, $now, $campaignId]);

        return $this->db->table('chat_campaign_run_recipients')
            ->where('run_id', $runId)
            ->where('deleted', 0)
            ->countAllResults();
    }

    /** @return array<int,int> */
    private function reserveQueueBatch(int $campaignId, int $runId, int $limit, string $now): array
    {
        $lock = 'chat_campaign_queue_' . $runId;
        if (!$this->tryAcquireLock($lock, 1)) {
            return [];
        }

        try {
            $minuteStart = gmdate('Y-m-d H:i:00');
            $alreadyReserved = $this->db->table('chat_campaign_run_recipients')
                ->where('run_id', $runId)
                ->where('deleted', 0)
                ->where('queued_at >=', $minuteStart)
                ->countAllResults();
            $slots = max(0, $limit - $alreadyReserved);
            if ($slots < 1) {
                return [];
            }

            $rows = $this->db->table('chat_campaign_run_recipients')
                ->select('id')
                ->where('campaign_id', $campaignId)
                ->where('run_id', $runId)
                ->where('deleted', 0)
                ->whereIn('status', ['pending', 'retry'])
                ->groupStart()
                    ->where('available_at IS NULL', null, false)
                    ->orWhere('available_at <=', $now)
                ->groupEnd()
                ->groupStart()
                    ->where('queued_at IS NULL', null, false)
                    ->orWhere('queued_at <', $minuteStart)
                ->groupEnd()
                ->orderBy('id', 'ASC')
                ->limit($slots)
                ->get()
                ->getResultArray();
            $ids = array_values(array_filter(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows),
                static fn (int $id): bool => $id > 0
            ));
            if ($ids) {
                $this->db->table('chat_campaign_run_recipients')
                    ->whereIn('id', $ids)
                    ->whereIn('status', ['pending', 'retry'])
                    ->update(['queued_at' => $now, 'updated_at' => $now]);
            }

            return $ids;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @param array<string,mixed> $campaign @param array<string,mixed> $schedule @param array<string,mixed> $run */
    private function reconcileTerminalOccurrence(array $campaign, array $schedule, array $run): void
    {
        $campaignId = (int) $campaign['id'];
        $status = in_array((string) ($run['status'] ?? ''), ['completed', 'failed'], true)
            ? (string) $run['status']
            : 'completed';
        $metrics = $this->decode((string) ($run['metrics_json'] ?? ''));
        if (($schedule['type'] ?? '') === 'recurring') {
            $schedule['next_at'] = $this->nextOccurrence($schedule);
            $this->campaigns->update_record($campaignId, [
                'status' => 'scheduled',
                'schedule_json' => $this->encode($schedule),
                'metrics_json' => $this->encode($metrics ?: $this->emptyMetrics((int) ($run['recipient_count'] ?? 0))),
                'finished_at' => $run['finished_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);
            return;
        }

        $this->campaigns->update_record($campaignId, [
            'status' => $status,
            'metrics_json' => $this->encode($metrics ?: $this->emptyMetrics((int) ($run['recipient_count'] ?? 0))),
            'finished_at' => $run['finished_at'] ?? gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,int> */
    private function emptyMetrics(int $audience): array
    {
        return [
            'audience' => max(0, $audience),
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'replied' => 0,
            'failed' => 0,
            'opt_out' => 0,
            'pending' => max(0, $audience),
        ];
    }

    private function isDue(array $schedule): bool
    {
        $at = $this->scheduledAt($schedule);
        return $at === null || strtotime($at) === false || strtotime($at) <= time();
    }

    private function scheduledAt(array $schedule): ?string
    {
        $value = trim((string) ($schedule['next_at'] ?? $schedule['at'] ?? ''));
        if ($value === '' || strtotime($value) === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', strtotime($value));
    }

    private function occurrenceKey(int $campaignId, array $schedule, ?string $scheduledAt): string
    {
        if ($scheduledAt) {
            return substr(hash('sha256', $campaignId . '|' . $scheduledAt), 0, 40);
        }
        return substr(hash('sha256', $campaignId . '|manual|' . gmdate('Y-m-d H:i')), 0, 40);
    }

    private function nextOccurrence(array $schedule): string
    {
        $timezoneName = trim((string) (
            $schedule['timezone']
            ?? $this->settings->get_value('campaign_recurring_timezone', 'America/Sao_Paulo')
        ));
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable $exception) {
            $timezone = new DateTimeZone('America/Sao_Paulo');
        }

        $source = trim((string) ($schedule['next_at'] ?? $schedule['at'] ?? 'now'));
        try {
            $base = new DateTimeImmutable($source, $timezone);
        } catch (Throwable $exception) {
            $base = new DateTimeImmutable('now', $timezone);
        }
        $base = $base->setTimezone($timezone);
        $hour = (int) $base->format('H');
        $minute = (int) $base->format('i');
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($schedule['days_of_week'] ?? [])),
            static fn (int $day): bool => $day >= 0 && $day <= 6
        )));
        if ($days === []) {
            $days = [0, 1, 2, 3, 4, 5, 6];
        }

        for ($offset = 1; $offset <= 14; $offset++) {
            $candidate = $base->modify('+' . $offset . ' day')->setTime($hour, $minute, 0);
            if (in_array((int) $candidate->format('w'), $days, true)) {
                return $candidate->format(DATE_ATOM);
            }
        }
        throw new RuntimeException('Nao foi possivel calcular a proxima ocorrencia da campanha.');
    }

    private function isTerminalRecipientStatus(string $status): bool
    {
        return in_array($status, ['sent', 'delivered', 'read', 'replied', 'failed', 'opt_out'], true);
    }

    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function hasActiveJob(string $correlation): bool
    {
        return $this->db->table('chat_integration_jobs')
            ->where('correlation_id', $correlation)
            ->where('deleted', 0)
            ->whereIn('status', ['pending', 'retry', 'running'])
            ->countAllResults() > 0;
    }

    private function acquireLock(string $name, int $timeout, string $error): void
    {
        if (!$this->tryAcquireLock($name, $timeout)) {
            throw new RuntimeException($error);
        }
    }

    private function tryAcquireLock(string $name, int $timeout): bool
    {
        $row = $this->db->query('SELECT GET_LOCK(?, ?) acquired', [$name, $timeout])->getRowArray();
        return (int) ($row['acquired'] ?? 0) === 1;
    }

    private function releaseLock(string $name): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (Throwable $exception) {
            log_message('error', 'Could not release campaign lock: {message}', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
