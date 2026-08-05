<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_campaign_templates_model;
use Chatwoot_plugin\Models\Chat_campaigns_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

class Campaign_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_campaigns_model $campaigns = null,
        private ?Chat_campaign_templates_model $templates = null,
        private ?Chat_instances_model $instances = null,
        private ?Chat_settings_model $settings = null,
        private ?Contact_service $contacts = null,
        private ?Audit_service $audit = null,
        ?BaseConnection $db = null
    ) {
        $this->campaigns ??= new Chat_campaigns_model();
        $this->templates ??= new Chat_campaign_templates_model();
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->contacts ??= new Contact_service();
        $this->audit ??= new Audit_service();
        $this->db = $db ?? db_connect('default');
    }

    public function list(array $filters, int $page, int $limit): array
    {
        $result = $this->campaigns->paginate_records($filters, $page, $limit);
        $result['data'] = array_map([$this, 'map'], $result['data']);
        return $result;
    }

    /** @return array{month:int,sent:int,delivery_rate:string,reply_rate:string} */
    public function summary(): array
    {
        $table = $this->db->prefixTable('chat_campaigns');
        $month = $this->db->table($table)->where($table . '.deleted', 0)->where($table . '.created_at >=', gmdate('Y-m-01 00:00:00'))->countAllResults();
        $rows = $this->db->table($table)->select($table . '.metrics_json')->where($table . '.deleted', 0)->get()->getResultArray();
        $sent = $delivered = $replied = 0;
        foreach ($rows as $row) {
            $metrics = $this->json((string) ($row['metrics_json'] ?? ''));
            $sent += max(0, (int) ($metrics['sent'] ?? 0));
            $delivered += max(0, (int) ($metrics['delivered'] ?? 0));
            $replied += max(0, (int) ($metrics['replied'] ?? 0));
        }
        return [
            'month' => $month,
            'sent' => $sent,
            'delivery_rate' => ($sent > 0 ? round($delivered * 100 / $sent, 1) : 0) . '%',
            'reply_rate' => ($sent > 0 ? round($replied * 100 / $sent, 1) : 0) . '%',
        ];
    }

    public function get(int $id): ?array
    {
        $row = $this->campaigns->get_by_id($id);
        return $row ? $this->map($row) : null;
    }

    public function audience_preview(array $input): array
    {
        $instanceId = (int) ($input['instance_id'] ?? 0);
        if (!$this->instances->get_by_id($instanceId)) {
            throw new InvalidArgumentException('Instancia da campanha invalida.');
        }
        $include = $this->normalizeTags($input['include_tags'] ?? []);
        $exclude = $this->normalizeTags($input['exclude_tags'] ?? []);
        $source = strtolower(trim((string) ($input['audience_source'] ?? 'contacts')));
        if (!in_array($source, ['contacts', 'manual', 'csv'], true)) {
            throw new InvalidArgumentException('Fonte de publico invalida.');
        }
        $contactsTable = $this->db->prefixTable('chat_contacts');
        $contactTagsTable = $this->db->prefixTable('chat_contact_tags');
        $tagsTable = $this->db->prefixTable('chat_tags');
        $rows = $source === 'contacts' ? $this->db->table($contactsTable)
            ->select($contactsTable . '.id, ' . $contactsTable . '.name, ' . $contactsTable . '.phone_normalized, ' . $contactsTable . '.company, ' . $contactsTable . '.city, ' . $contactsTable . '.opt_out')
            ->select('GROUP_CONCAT(DISTINCT LOWER(' . $tagsTable . '.normalized_name) SEPARATOR \',\') AS tag_names', false)
            ->join($contactTagsTable, $contactTagsTable . '.contact_id = ' . $contactsTable . '.id AND ' . $contactTagsTable . '.deleted = 0', 'left')
            ->join($tagsTable, $tagsTable . '.id = ' . $contactTagsTable . '.tag_id AND ' . $tagsTable . '.deleted = 0', 'left')
            ->where($contactsTable . '.deleted', 0)
            ->groupStart()->where($contactsTable . '.instance_id', $instanceId)->orWhere($contactsTable . '.instance_id IS NULL', null, false)->groupEnd()
            ->groupBy($contactsTable . '.id')
            ->limit(10000)
            ->get()->getResultArray() : [];
        $recipients = [];
        $excludedOptOut = 0;
        $excludedFilter = 0;
        foreach ($rows as $row) {
            $tags = array_filter(explode(',', (string) ($row['tag_names'] ?? '')));
            if ($include && array_diff($include, $tags)) {
                $excludedFilter++;
                continue;
            }
            if ($exclude && array_intersect($exclude, $tags)) {
                $excludedFilter++;
                continue;
            }
            if (!empty($row['opt_out'])) {
                $excludedOptOut++;
                continue;
            }
            $phone = (string) $row['phone_normalized'];
            $recipients[$phone] = ['contact_id' => (int) $row['id'], 'phone' => $phone, 'name' => (string) $row['name'], 'company' => (string) ($row['company'] ?? ''), 'city' => (string) ($row['city'] ?? '')];
        }
        $invalid = 0;
        $manualNumbers = is_array($input['manual_numbers'] ?? null) ? $input['manual_numbers'] : (is_array($input['numbers'] ?? null) ? $input['numbers'] : []);
        foreach ($manualNumbers as $entry) {
            $number = is_array($entry) ? ($entry['phone'] ?? $entry['numero'] ?? $entry['number'] ?? '') : $entry;
            $customVariables = is_array($entry) ? ($entry['variables'] ?? $entry['variaveis'] ?? []) : [];
            if (!is_array($customVariables)) $customVariables = [];
            foreach (['name' => 'name', 'nome' => 'name', 'company' => 'company', 'empresa' => 'company', 'city' => 'city', 'cidade' => 'city'] as $sourceKey => $targetKey) {
                if (is_array($entry) && isset($entry[$sourceKey]) && is_scalar($entry[$sourceKey])) $customVariables[$targetKey] = trim((string) $entry[$sourceKey]);
            }
            try {
                $phone = $this->contacts->normalize_phone((string) $number);
                $optOut = $this->db->table('chat_contacts')->select('id, name, company, city, opt_out')->where('phone_normalized', $phone)->where('deleted', 0)->get(1)->getRowArray();
                if ($optOut && !empty($optOut['opt_out'])) {
                    $excludedOptOut++;
                    continue;
                }
                $recipients[$phone] = [
                    'contact_id' => $optOut ? (int) $optOut['id'] : null,
                    'phone' => $phone,
                    'name' => trim((string) ($customVariables['name'] ?? $optOut['name'] ?? $phone)) ?: $phone,
                    'company' => trim((string) ($customVariables['company'] ?? $optOut['company'] ?? '')),
                    'city' => trim((string) ($customVariables['city'] ?? $optOut['city'] ?? '')),
                    'variables' => array_slice($customVariables, 0, 100, true),
                ];
            } catch (InvalidArgumentException $exception) {
                $invalid++;
            }
        }
        $items = array_values($recipients);
        return [
            'count' => count($items),
            'excluded_opt_out' => $excludedOptOut,
            'excluded_by_filter' => $excludedFilter,
            'invalid' => $invalid,
            'sample' => array_slice($items, 0, 20),
            'recipients' => $items,
        ];
    }

    public function save(array $input, int $actorId, ?int $id = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $instanceId = (int) ($input['instance_id'] ?? 0);
        if ($name === '' || mb_strlen($name) > 191) throw new InvalidArgumentException('Informe um nome de campanha valido.');
        $instance = $this->instances->get_by_id($instanceId);
        if (!$instance || empty($instance['active'])) throw new InvalidArgumentException('Selecione uma instancia ativa.');

        $defaultCampaignType = (($instance['provider_type'] ?? 'evolution') === 'meta_cloud') ? 'official' : 'unofficial';
        $campaignType = strtolower(trim((string) ($input['campaign_type'] ?? $defaultCampaignType)));
        if (!in_array($campaignType, ['official','unofficial'], true)) throw new InvalidArgumentException('Tipo de campanha invalido.');
        $dispatchMode = 'internal_queue';
        if ($campaignType === 'official' && ($instance['provider_type'] ?? '') !== 'meta_cloud') throw new InvalidArgumentException('Campanha oficial exige uma instancia Meta Cloud API.');
        if ($campaignType === 'unofficial' && ($instance['provider_type'] ?? '') !== 'evolution') throw new InvalidArgumentException('Campanha nao oficial exige uma instancia Evolution.');

        $templateId = !empty($input['template_id']) ? (int) $input['template_id'] : null;
        $template = $templateId ? $this->templates->get_by_id($templateId) : null;
        $message = trim((string) ($input['message'] ?? $input['message_content'] ?? ''));
        $templateParameters = $input['template_parameters'] ?? $input['template_parameters_json'] ?? [];
        if (is_string($templateParameters)) {
            $decoded = json_decode($templateParameters, true);
            if (!is_array($decoded)) throw new InvalidArgumentException('Parametros do template oficial invalidos.');
            $templateParameters = $decoded;
        }
        if (!is_array($templateParameters)) $templateParameters = [];
        if ($campaignType === 'official') {
            if (!$template || (int) ($template['instance_id'] ?? 0) !== $instanceId || strtolower((string) ($template['provider_status'] ?? '')) !== 'approved') {
                throw new InvalidArgumentException('Selecione um template oficial aprovado desta instancia.');
            }
            $message = $message !== '' ? $message : (string) ($template['message_content'] ?? ('[Template] ' . $template['name']));
            $templateParameters = $this->validateOfficialTemplateComponents(
                $templateParameters,
                $this->json((string) ($template['components_json'] ?? ''))
            );
        }
        if ($message === '' || mb_strlen($message) > 10000) throw new InvalidArgumentException('Informe uma mensagem de ate 10000 caracteres.');
        $this->validateTemplateVariables($message);

        $before = $id ? $this->campaigns->get_by_id($id) : null;
        if ($id && !$before) throw new RuntimeException('Campanha nao encontrada.', 404);
        if ($before && in_array((string) $before['status'], ['running','completed'], true)) {
            throw new RuntimeException('Pause ou duplique a campanha antes de alterar seu conteudo.', 409);
        }
        $preview = $this->audience_preview($input);
        if ($preview['count'] < 1) throw new InvalidArgumentException('O publico da campanha ficou vazio apos filtros e opt-outs.');

        $correlationId = $before['correlation_id'] ?? $this->uuid();
        $idempotencyKey = $before['idempotency_key'] ?? hash('sha256', trim((string) ($input['idempotency_key'] ?? '')) ?: $correlationId);
        $requestedType = (string) ($input['schedule_type'] ?? $input['type'] ?? 'draft');
        if ($requestedType === 'one_time') $requestedType = !empty($input['start_date']) ? 'scheduled' : 'draft';
        if ($requestedType === 'triggered') $requestedType = 'draft';
        $schedule = [
            'type' => $this->scheduleType($requestedType),
            'at' => $this->dateValue($input['schedule_at'] ?? ((string) ($input['start_date'] ?? '') !== '' ? trim((string) $input['start_date']) . ' ' . trim((string) ($input['start_time'] ?? '00:00')) : null)),
            'days_of_week' => $this->normalizeWeekdays(is_array($input['days_of_week'] ?? null) ? $input['days_of_week'] : (is_array($input['weekdays'] ?? null) ? $input['weekdays'] : [])),
            'start_immediately' => filter_var($input['start_immediately'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'timezone' => trim((string) ($input['timezone'] ?? $this->settings->get_value('campaign_recurring_timezone', 'America/Sao_Paulo'))),
        ];
        if ($schedule['type'] === 'recurring') {
            if (!$schedule['at'] || $schedule['days_of_week'] === []) throw new InvalidArgumentException('Campanha recorrente exige data inicial, horario e pelo menos um dia da semana.');
            try { new \DateTimeZone($schedule['timezone']); } catch (\Throwable $e) { throw new InvalidArgumentException('Fuso horario da campanha invalido.'); }
            $schedule['next_at'] = $schedule['at'];
        }
        $scheduledType = in_array($schedule['type'], ['scheduled','recurring'], true);
        $status = $before['status'] ?? ($schedule['start_immediately'] ? 'running' : ($scheduledType ? 'scheduled' : 'draft'));
        if ($schedule['start_immediately']) $status = 'running';
        $audience = [
            'source' => (string) ($input['audience_source'] ?? 'contacts'),
            'include_tags' => $this->normalizeTags($input['include_tags'] ?? []),
            'exclude_tags' => $this->normalizeTags($input['exclude_tags'] ?? []),
            'manual_numbers_count' => count(is_array($input['manual_numbers'] ?? null) ? $input['manual_numbers'] : (is_array($input['numbers'] ?? null) ? $input['numbers'] : [])),
            'recipient_count' => $preview['count'],
            'excluded_opt_out' => $preview['excluded_opt_out'],
        ];
        $payload = [
            'instance_id' => $instanceId, 'external_id' => $before['external_id'] ?? null,
            'name' => $name, 'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000) ?: null,
            'status' => $status, 'audience_json' => json_encode($audience, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'message_content' => $message, 'media_id' => !empty($input['media_id']) ? (int) $input['media_id'] : null,
            'schedule_json' => json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metrics_json' => json_encode(['audience'=>$preview['count'],'sent'=>0,'delivered'=>0,'read'=>0,'replied'=>0,'failed'=>0,'pending'=>$preview['count']], JSON_UNESCAPED_UNICODE),
            'correlation_id' => $correlationId, 'idempotency_key' => $idempotencyKey, 'last_error' => null,
            'created_by' => $before['created_by'] ?? $actorId,
            'campaign_type' => $campaignType, 'template_id' => $templateId,
            'template_parameters_json' => json_encode($templateParameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dispatch_mode' => $dispatchMode,
            'rate_limit_per_minute' => min(1000, max(1, (int) ($input['rate_limit_per_minute'] ?? $this->settings->get_value('campaign_default_rate_limit_per_minute', 20)))),
            'started_at' => $status === 'running' ? ($before['started_at'] ?? gmdate('Y-m-d H:i:s')) : null,
            'finished_at' => null,
        ];
        if ($id) $this->campaigns->update_record($id, $payload); else $id = $this->campaigns->create_record($payload);
        $this->storeRecipients($id, $preview['recipients']);

        $this->campaigns->update_record($id, [
            'external_id' => 'local-' . $id,
            'dispatch_mode' => 'internal_queue',
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($status === 'running') {
            (new Campaign_dispatch_service())->scheduleDue();
        }
        $saved = $this->get($id) ?: [];
        $this->audit->record(
            $actorId,
            $before ? 'campaign.updated' : 'campaign.created',
            'campaign',
            $id,
            $instanceId,
            $before ?: [],
            $saved,
            $correlationId
        );
        return $saved;
    }

    public function duplicate(int $id, int $actorId): array
    {
        $row = $this->campaigns->get_by_id($id);
        if (!$row) throw new RuntimeException('Campanha nao encontrada.', 404);
        $newId = $this->campaigns->create_record([
            'instance_id' => $row['instance_id'], 'name' => 'Copia de ' . $row['name'], 'description' => $row['description'], 'status' => 'draft',
            'audience_json' => $row['audience_json'], 'message_content' => $row['message_content'], 'media_id' => $row['media_id'], 'schedule_json' => $row['schedule_json'],
            'metrics_json' => json_encode(['audience'=>0,'sent'=>0,'delivered'=>0,'read'=>0,'replied'=>0,'failed'=>0,'pending'=>0], JSON_UNESCAPED_UNICODE),
            'correlation_id' => $this->uuid(), 'idempotency_key' => hash('sha256', random_bytes(32)), 'created_by' => $actorId,
            'campaign_type' => $row['campaign_type'] ?? 'unofficial', 'template_id' => $row['template_id'] ?? null,
            'template_parameters_json' => $row['template_parameters_json'] ?? null, 'dispatch_mode' => $row['dispatch_mode'] ?? 'internal_queue',
            'rate_limit_per_minute' => $row['rate_limit_per_minute'] ?? 20, 'started_at' => null, 'finished_at' => null,
        ]);
        $recipients = $this->db->table('chat_campaign_recipients')->where('campaign_id', $id)->where('deleted', 0)->get()->getResultArray();
        $this->storeRecipients($newId, array_map(static fn (array $recipient): array => [
            'contact_id' => !empty($recipient['contact_id']) ? (int) $recipient['contact_id'] : null,
            'phone' => (string) $recipient['phone_normalized'],
            'variables' => json_decode((string) ($recipient['variables_json'] ?? ''), true) ?: [],
        ], $recipients));
        $this->audit->record($actorId, 'campaign.duplicated', 'campaign', $newId, (int) $row['instance_id'], ['source_id' => $id], ['status' => 'draft']);
        return $this->get($newId) ?: [];
    }

    public function toggle(int $id, int $actorId): array
    {
        $row = $this->campaigns->get_by_id($id);
        if (!$row) throw new RuntimeException('Campanha nao encontrada.', 404);
        $pausing = !in_array((string) $row['status'], ['paused', 'draft', 'failed'], true);
        $status = $pausing ? 'paused' : 'running';
        $payload = [
            'status' => $status,
            'dispatch_mode' => 'internal_queue',
            'external_id' => 'local-' . $id,
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => null,
        ];
        if (!$pausing && empty($row['started_at'])) {
            $payload['started_at'] = gmdate('Y-m-d H:i:s');
        }
        $this->campaigns->update_record($id, $payload);
        if (!$pausing) {
            (new Campaign_dispatch_service())->scheduleDue();
        }
        $this->audit->record($actorId, $pausing ? 'campaign.paused' : 'campaign.resumed', 'campaign', $id, (int) $row['instance_id'], ['status' => $row['status']], ['status' => $status], (string) $row['correlation_id']);
        if ($pausing) $this->notifyCampaign($id, (string) $row['name'], 'Campanha pausada', 'O disparo foi pausado.', 'warning', 'campaign-paused|' . $id . '|' . gmdate('YmdHi'));
        return $this->get($id) ?: [];
    }

    public function delete(int $id, int $actorId): void
    {
        $row = $this->campaigns->get_by_id($id);
        if (!$row) throw new RuntimeException('Campanha nao encontrada.', 404);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('chat_campaign_recipients')->where('campaign_id', $id)->update(['deleted'=>1,'updated_at'=>$now]);
        $this->db->table('chat_campaign_run_recipients')->where('campaign_id', $id)->update(['deleted'=>1,'updated_at'=>$now]);
        $this->db->table('chat_campaign_runs')->where('campaign_id', $id)->update(['deleted'=>1,'updated_at'=>$now]);
        $this->campaigns->soft_delete($id);
        $this->audit->record($actorId, 'campaign.deleted', 'campaign', $id, (int) $row['instance_id'], $row);
    }

    public function health(): array
    {
        $pending = $this->db->table('chat_campaign_run_recipients')
            ->where('deleted', 0)
            ->whereIn('status', ['pending', 'retry', 'sending'])
            ->countAllResults();
        $running = $this->db->table('chat_campaign_runs')
            ->where('deleted', 0)
            ->where('status', 'running')
            ->countAllResults();
        $failedLastHour = $this->db->table('chat_campaign_run_recipients')
            ->where('deleted', 0)
            ->where('status', 'failed')
            ->where('updated_at >=', gmdate('Y-m-d H:i:s', time() - 3600))
            ->countAllResults();

        return [
            'success' => true,
            'provider' => 'internal_queue',
            'pending_recipients' => $pending,
            'running_occurrences' => $running,
            'failed_last_hour' => $failedLastHour,
            'checked_at' => gmdate(DATE_ATOM),
        ];
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function runs(int $campaignId, int $page = 1, int $limit = 20): array
    {
        if (!$this->campaigns->get_by_id($campaignId)) {
            throw new InvalidArgumentException('Campanha nao encontrada.', 404);
        }
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $builder = $this->db->table('chat_campaign_runs')
            ->where('campaign_id', $campaignId)
            ->where('deleted', 0);
        $total = (clone $builder)->countAllResults();
        $rows = $builder->orderBy('id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()->getResultArray();

        return [
            'data' => array_map(function (array $row): array {
                $metrics = $this->json((string) ($row['metrics_json'] ?? ''));
                return [
                    'id' => (int) $row['id'],
                    'campaign_id' => (int) $row['campaign_id'],
                    'occurrence_key' => $row['occurrence_key'] ?: null,
                    'status' => (string) ($row['status'] ?? 'pending'),
                    'scheduled_at' => $row['scheduled_at'] ?: null,
                    'started_at' => $row['started_at'] ?: null,
                    'finished_at' => $row['finished_at'] ?: null,
                    'recipient_count' => (int) ($row['recipient_count'] ?? 0),
                    'metrics' => $metrics,
                    'error_message' => $row['error_message'] ?: null,
                ];
            }, $rows),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => $page * $limit < $total,
            ],
        ];
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function run_recipients(int $campaignId, int $runId, array $filters = [], int $page = 1, int $limit = 50): array
    {
        $run = $this->db->table('chat_campaign_runs')
            ->where('id', $runId)
            ->where('campaign_id', $campaignId)
            ->where('deleted', 0)
            ->get(1)->getRowArray();
        if (!$run) {
            throw new InvalidArgumentException('Execucao de campanha nao encontrada.', 404);
        }

        $page = max(1, $page);
        $limit = min(200, max(1, $limit));
        $recipients = $this->db->prefixTable('chat_campaign_run_recipients');
        $contacts = $this->db->prefixTable('chat_contacts');
        $builder = $this->db->table($recipients)
            ->select($recipients . '.*, ' . $contacts . '.name AS contact_name')
            ->join($contacts, $contacts . '.id=' . $recipients . '.contact_id AND ' . $contacts . '.deleted=0', 'left')
            ->where($recipients . '.campaign_id', $campaignId)
            ->where($recipients . '.run_id', $runId)
            ->where($recipients . '.deleted', 0);
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $builder->where($recipients . '.status', $status);
        }
        $search = preg_replace('/\D+/', '', (string) ($filters['search'] ?? '')) ?: '';
        if ($search !== '') {
            $builder->like($recipients . '.phone_normalized', $search, 'both');
        }
        $total = (clone $builder)->countAllResults();
        $rows = $builder->orderBy($recipients . '.id', 'ASC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()->getResultArray();

        return [
            'data' => array_map(function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'run_id' => (int) $row['run_id'],
                    'contact_id' => !empty($row['contact_id']) ? (int) $row['contact_id'] : null,
                    'contact_name' => trim((string) ($row['contact_name'] ?? '')) ?: null,
                    'phone' => (string) ($row['phone_normalized'] ?? ''),
                    'status' => (string) ($row['status'] ?? 'pending'),
                    'attempts' => (int) ($row['attempts'] ?? 0),
                    'max_attempts' => (int) ($row['max_attempts'] ?? 0),
                    'external_message_id' => $row['external_message_id'] ?: null,
                    'error_message' => $row['error_message'] ?: null,
                    'queued_at' => $row['queued_at'] ?: null,
                    'last_attempt_at' => $row['last_attempt_at'] ?: null,
                    'sent_at' => $row['sent_at'] ?: null,
                    'delivered_at' => $row['delivered_at'] ?: null,
                    'read_at' => $row['read_at'] ?: null,
                    'replied_at' => $row['replied_at'] ?: null,
                ];
            }, $rows),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => $page * $limit < $total,
                'run' => [
                    'id' => (int) $run['id'],
                    'status' => (string) ($run['status'] ?? ''),
                    'recipient_count' => (int) ($run['recipient_count'] ?? 0),
                ],
            ],
        ];
    }

    public function list_templates(): array
    {
        $result = $this->templates->paginate_records(['active' => 1], 1, 200);
        return array_map([$this, 'mapTemplate'], $result['data']);
    }

    public function save_template(array $input, int $actorId, ?int $id = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $message = trim((string) ($input['message'] ?? $input['message_content'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191 || $message === '' || mb_strlen($message) > 10000) {
            throw new InvalidArgumentException('Nome e mensagem do template sao obrigatorios.');
        }
        $this->validateTemplateVariables($message);
        if ($id && !$this->templates->get_by_id($id)) {
            throw new RuntimeException('Template nao encontrado.', 404);
        }
        $payload = ['name' => $name, 'message_content' => $message, 'media_id' => !empty($input['media_id']) ? (int) $input['media_id'] : null, 'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, 'created_by' => $actorId];
        if ($id) $this->templates->update_record($id, $payload); else $id = $this->templates->create_record($payload);
        return $this->mapTemplate($this->templates->get_by_id($id) ?: []);
    }

    public function delete_template(int $id): void
    {
        if (!$this->templates->get_by_id($id)) throw new RuntimeException('Template nao encontrado.', 404);
        $this->templates->soft_delete($id);
    }

    private function storeRecipients(int $campaignId, array $recipients): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('chat_campaign_recipients')->where('campaign_id', $campaignId)->where('deleted', 0)->update(['deleted'=>1,'updated_at'=>$now]);
        $maxAttempts = min(20, max(1, (int) $this->settings->get_value('campaign_recipient_max_attempts', 5)));
        foreach ($recipients as $recipient) {
            $phone = (string) $recipient['phone'];
            $variables = is_array($recipient['variables'] ?? null) ? $recipient['variables'] : [];
            foreach (['name','company','city'] as $key) if (isset($recipient[$key]) && is_scalar($recipient[$key])) $variables[$key] = trim((string) $recipient[$key]);
            $existing = $this->db->table('chat_campaign_recipients')->where('campaign_id', $campaignId)->where('phone_hash', hash('sha256', $phone))->get(1)->getRowArray();
            $payload = [
                'campaign_id'=>$campaignId, 'run_id'=>null, 'contact_id'=>$recipient['contact_id'] ?? null, 'phone_hash'=>hash('sha256', $phone), 'phone_normalized'=>$phone,
                'variables_json'=>json_encode(array_slice($variables, 0, 100, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'=>'pending','external_message_id'=>null,'error_message'=>null,'sent_at'=>null,'delivered_at'=>null,'read_at'=>null,'replied_at'=>null,
                'attempts'=>0,'max_attempts'=>$maxAttempts,'available_at'=>null,'last_attempt_at'=>null,'updated_at'=>$now,'deleted'=>0,
            ];
            if ($existing) $this->db->table('chat_campaign_recipients')->where('id', (int) $existing['id'])->update($payload);
            else { $payload['created_at']=$now; $this->db->table('chat_campaign_recipients')->insert($payload); }
        }
    }

    /** @return array<int,string> */
    private function notifyCampaign(int $id, string $name, string $title, string $message, string $level, string $dedupe): void
    {
        try {
            (new Notification_service())->create('campaign', $title, $name . ': ' . mb_substr($message, 0, 1500), 'campaign', $id, null, $level, $dedupe);
        } catch (\Throwable $exception) {
            // Notification failures cannot change campaign provider semantics.
        }
    }

    private function map(array $row): array
    {
        $audience = $this->json((string) ($row['audience_json'] ?? ''));
        $schedule = $this->json((string) ($row['schedule_json'] ?? ''));
        $metrics = $this->json((string) ($row['metrics_json'] ?? ''));
        $templateParameters = $this->json((string) ($row['template_parameters_json'] ?? ''));
        return [
            'id'=>(int)$row['id'], 'external_id'=>$row['external_id'] ?: null, 'instance_id'=>(int)$row['instance_id'], 'name'=>(string)$row['name'],
            'description'=>(string)($row['description']??''), 'status'=>$this->normalizeStatus((string)($row['status']??'draft')), 'message'=>(string)$row['message_content'],
            'media_id'=>isset($row['media_id'])?(int)$row['media_id']:null, 'audience'=>$audience, 'schedule'=>$schedule, 'metrics'=>$metrics,
            'campaign_type'=>(string)($row['campaign_type']??'unofficial'), 'dispatch_mode'=>(string)($row['dispatch_mode']??'internal_queue'),
            'template_id'=>!empty($row['template_id'])?(int)$row['template_id']:null, 'template_parameters'=>$templateParameters,
            'rate_limit_per_minute'=>(int)($row['rate_limit_per_minute']??20),
            'audience_count'=>(int)($audience['recipient_count']??$metrics['audience']??0), 'last_error'=>$row['last_error']?:null,
            'audience_source'=>(string)($audience['source']??'contacts'), 'include_tags'=>is_array($audience['include_tags']??null)?$audience['include_tags']:[],
            'exclude_tags'=>is_array($audience['exclude_tags']??null)?$audience['exclude_tags']:[], 'numbers'=>[],
            'type'=>(($schedule['type']??'')==='recurring'?'recurring':'one_time'), 'start_date'=>!empty($schedule['at'])?date('Y-m-d',strtotime((string)$schedule['at'])):'',
            'start_time'=>!empty($schedule['at'])?date('H:i',strtotime((string)$schedule['at'])):'',
            'timezone'=>(string)($schedule['timezone']??'America/Sao_Paulo'), 'weekdays'=>is_array($schedule['days_of_week']??null)?$schedule['days_of_week']:[],
            'next_at'=>$schedule['next_at']??null,
            'scheduled'=>!empty($schedule['next_at']??$schedule['at']??null)?date('d/m/Y H:i',strtotime((string)($schedule['next_at']??$schedule['at']))):'Sem agendamento',
            'sent'=>(int)($metrics['sent']??0), 'delivered'=>(int)($metrics['delivered']??0), 'read'=>(int)($metrics['read']??0), 'replied'=>(int)($metrics['replied']??0),
            'failed'=>(int)($metrics['failed']??0), 'pending'=>(int)($metrics['pending']??0),
            'started_at'=>$row['started_at']??null, 'finished_at'=>$row['finished_at']??null,
            'last_sync_at'=>$row['last_sync_at']??null, 'created_at'=>$row['created_at']??null, 'updated_at'=>$row['updated_at']??null,
        ];
    }

    private function mapTemplate(array $row): array
    {
        return [
            'id'=>(int)($row['id']??0), 'instance_id'=>!empty($row['instance_id'])?(int)$row['instance_id']:null,
            'name'=>(string)($row['name']??''), 'message'=>(string)($row['message_content']??''),
            'media_id'=>isset($row['media_id'])?(int)$row['media_id']:null, 'active'=>!empty($row['active']),
            'provider_template_id'=>$row['provider_template_id']??null, 'language_code'=>(string)($row['language_code']??''),
            'category'=>(string)($row['category']??''), 'provider_status'=>(string)($row['provider_status']??''),
            'components'=>$this->json((string)($row['components_json']??'')), 'last_synced_at'=>$row['last_synced_at']??null,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $map = ['active' => 'running', 'started' => 'running', 'stopped' => 'paused', 'done' => 'completed', 'error' => 'failed', 'inactive' => 'paused'];
        $status = $map[$status] ?? $status;
        return in_array($status, ['draft', 'scheduled', 'running', 'paused', 'completed', 'failed', 'cancelled'], true) ? $status : 'draft';
    }

    private function scheduleType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['draft', 'scheduled', 'recurring'], true) ? $value : 'draft';
    }

    private function normalizeWeekdays(array $values): array
    {
        $map = ['dom'=>0,'seg'=>1,'ter'=>2,'qua'=>3,'qui'=>4,'sex'=>5,'sab'=>6];
        $result = [];
        foreach ($values as $value) {
            $key = strtolower(trim((string) $value));
            $day = array_key_exists($key, $map) ? $map[$key] : (is_numeric($value) ? (int) $value : -1);
            if ($day >= 0 && $day <= 6) $result[$day] = $day;
        }
        sort($result);
        return array_values($result);
    }

    private function dateValue($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $time = strtotime($value);
        if ($time === false) throw new InvalidArgumentException('Data de agendamento invalida.');
        return date(DATE_ATOM, $time);
    }

    private function normalizeTags($tags): array
    {
        if (!is_array($tags)) return [];
        $result = [];
        foreach ($tags as $tag) { $tag = mb_strtolower(trim((string) $tag)); if ($tag !== '' && mb_strlen($tag) <= 100) $result[$tag] = $tag; }
        return array_values(array_slice($result, 0, 50));
    }

    /** @return array<int,array<string,mixed>> */
    private function validateOfficialTemplateComponents(array $components, array $templateDefinition): array
    {
        if (array_keys($components) !== range(0, count($components) - 1) && $components !== []) {
            throw new InvalidArgumentException('Os componentes do template oficial precisam ser uma lista JSON.');
        }
        $clean = [];
        foreach ($components as $component) {
            if (!is_array($component)) throw new InvalidArgumentException('Componente de template oficial invalido.');
            $type = strtolower(trim((string) ($component['type'] ?? '')));
            if (!in_array($type, ['header','body','button'], true)) throw new InvalidArgumentException('Tipo de componente oficial nao suportado: ' . ($type ?: 'vazio') . '.');
            $parameters = $component['parameters'] ?? [];
            if (!is_array($parameters) || count($parameters) > 100) throw new InvalidArgumentException('Parametros de componente oficial invalidos.');
            $cleanParameters = [];
            foreach ($parameters as $parameter) {
                if (!is_array($parameter)) throw new InvalidArgumentException('Parametro de template oficial invalido.');
                $parameterType = strtolower(trim((string) ($parameter['type'] ?? '')));
                if (!in_array($parameterType, ['text','currency','date_time','image','video','document','payload'], true)) {
                    throw new InvalidArgumentException('Tipo de parametro oficial nao suportado: ' . ($parameterType ?: 'vazio') . '.');
                }
                $parameter['type'] = $parameterType;
                $cleanParameters[] = $parameter;
            }
            $entry = ['type' => $type, 'parameters' => $cleanParameters];
            if ($type === 'button') {
                $subType = strtolower(trim((string) ($component['sub_type'] ?? '')));
                $index = trim((string) ($component['index'] ?? ''));
                if (!in_array($subType, ['url','quick_reply'], true) || !preg_match('/^\d{1,2}$/', $index)) {
                    throw new InvalidArgumentException('Botao de template oficial exige sub_type e index validos.');
                }
                $entry['sub_type'] = $subType;
                $entry['index'] = $index;
            }
            $clean[] = $entry;
        }

        // Validate the generated BODY/HEADER parameter count against approved
        // template markers. This catches incomplete campaigns before queueing.
        foreach ($templateDefinition as $definition) {
            if (!is_array($definition)) continue;
            $type = strtolower((string) ($definition['type'] ?? ''));
            if (!in_array($type, ['header','body'], true)) continue;
            preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) ($definition['text'] ?? ''), $matches);
            $required = count($matches[0] ?? []);
            if ($required < 1) continue;
            $provided = null;
            foreach ($clean as $component) if ($component['type'] === $type) { $provided = count($component['parameters']); break; }
            if ($provided !== $required) throw new InvalidArgumentException("O componente {$type} exige {$required} parametro(s); foram informados " . (int) $provided . '.');
        }
        return $clean;
    }

    private function validateTemplateVariables(string $message): void
    {
        preg_match_all('/\{([^{}]+)\}/u', $message, $matches);
        $allowed = ['nome','name','telefone','phone','empresa','company','cidade','city'];
        $unknown = array_filter(array_unique($matches[1] ?? []), static fn (string $key): bool => !in_array($key, $allowed, true) && !preg_match('/^[1-9][0-9]{0,2}$/', $key));
        if ($unknown) throw new InvalidArgumentException('Variaveis de template desconhecidas: ' . implode(', ', $unknown) . '.');
    }

    private function json(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        return bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(8));
    }
}
