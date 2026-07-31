<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Libraries\N8n_client;
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
        private ?N8n_client $n8n = null,
        private ?Audit_service $audit = null,
        ?BaseConnection $db = null
    ) {
        $this->campaigns ??= new Chat_campaigns_model();
        $this->templates ??= new Chat_campaign_templates_model();
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->contacts ??= new Contact_service();
        $this->n8n ??= new N8n_client($this->settings);
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
        if (!in_array($source, ['contacts', 'manual', 'csv', 'n8n'], true)) {
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
        if ($source === 'n8n') {
            $manualNumbers = $this->n8nAudienceNumbers($input, $instanceId);
        }
        foreach ($manualNumbers as $number) {
            try {
                $phone = $this->contacts->normalize_phone((string) $number);
                $optOut = $this->db->table('chat_contacts')->select('id, name, company, city, opt_out')->where('phone_normalized', $phone)->where('deleted', 0)->get(1)->getRowArray();
                if ($optOut && !empty($optOut['opt_out'])) {
                    $excludedOptOut++;
                    continue;
                }
                $recipients[$phone] = ['contact_id' => $optOut ? (int) $optOut['id'] : null, 'phone' => $phone, 'name' => (string) ($optOut['name'] ?? $phone), 'company' => (string) ($optOut['company'] ?? ''), 'city' => (string) ($optOut['city'] ?? '')];
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
        $message = trim((string) ($input['message'] ?? $input['message_content'] ?? ''));
        $instanceId = (int) ($input['instance_id'] ?? 0);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('Informe um nome de campanha valido.');
        }
        if ($message === '' || mb_strlen($message) > 10000) {
            throw new InvalidArgumentException('Informe uma mensagem de ate 10000 caracteres.');
        }
        $this->validateTemplateVariables($message);
        $instance = $this->instances->get_by_id($instanceId);
        if (!$instance || empty($instance['active'])) {
            throw new InvalidArgumentException('Selecione uma instancia ativa.');
        }
        $before = $id ? $this->campaigns->get_by_id($id) : null;
        if ($id && !$before) {
            throw new RuntimeException('Campanha nao encontrada.', 404);
        }
        $preview = $this->audience_preview($input);
        if ($preview['count'] < 1) {
            throw new InvalidArgumentException('O publico da campanha ficou vazio apos filtros e opt-outs.');
        }
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
        ];
        $audience = [
            'source' => (string) ($input['audience_source'] ?? 'contacts'),
            'include_tags' => $this->normalizeTags($input['include_tags'] ?? []),
            'exclude_tags' => $this->normalizeTags($input['exclude_tags'] ?? []),
            'manual_numbers_count' => count(is_array($input['manual_numbers'] ?? null) ? $input['manual_numbers'] : (is_array($input['numbers'] ?? null) ? $input['numbers'] : [])),
            'recipient_count' => $preview['count'],
            'excluded_opt_out' => $preview['excluded_opt_out'],
        ];
        $payload = [
            'instance_id' => $instanceId,
            'external_id' => $before['external_id'] ?? null,
            'name' => $name,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000) ?: null,
            'status' => $before['status'] ?? ($schedule['start_immediately'] ? 'running' : ($schedule['type'] === 'scheduled' ? 'scheduled' : 'draft')),
            'audience_json' => json_encode($audience, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'message_content' => $message,
            'media_id' => !empty($input['media_id']) ? (int) $input['media_id'] : null,
            'schedule_json' => json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metrics_json' => $before['metrics_json'] ?? json_encode(['audience' => $preview['count']], JSON_UNESCAPED_UNICODE),
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'last_error' => null,
            'created_by' => $actorId,
        ];
        if ($id) {
            $this->campaigns->update_record($id, $payload);
        } else {
            $id = $this->campaigns->create_record($payload);
        }
        $this->storeRecipients($id, $preview['recipients']);
        $externalId = trim((string) ($before['external_id'] ?? ''));
        $legacy = $this->legacyPayload($input, $instance, $preview['recipients'], $schedule);
        $method = $externalId === '' ? 'POST' : 'PUT';
        $path = $this->campaignPath($externalId ?: null);
        try {
            $response = $this->n8n->request($method, $path, $legacy, ['correlation_id' => $correlationId, 'idempotency_key' => $idempotencyKey, 'idempotent' => $method === 'PUT']);
        } catch (\Throwable $exception) {
            $this->campaigns->update_record($id, ['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $this->notifyCampaign($id, $name, 'Falha ao sincronizar campanha', 'A campanha nao foi confirmada pelo n8n.', 'danger', 'campaign-failed|' . $id . '|' . hash('sha256', $exception->getMessage()));
            throw $exception;
        }
        if (!$response['success']) {
            $this->campaigns->update_record($id, ['status' => 'failed', 'last_error' => $response['error']]);
            $this->notifyCampaign($id, $name, 'Falha ao sincronizar campanha', (string) $response['error'], 'danger', 'campaign-failed|' . $id . '|' . hash('sha256', (string) $response['error']));
            throw new RuntimeException((string) $response['error'], $response['status_code'] >= 500 || $response['status_code'] === 0 ? 502 : 422);
        }
        $responseData = is_array($response['data']) ? $response['data'] : [];
        $externalId = $externalId ?: trim((string) ($responseData['id'] ?? $responseData['campaign_id'] ?? $responseData['data']['id'] ?? ''));
        $status = $this->normalizeStatus((string) ($responseData['status'] ?? $payload['status']));
        $this->campaigns->update_record($id, ['external_id' => $externalId ?: null, 'status' => $status, 'last_sync_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null]);
        $saved = $this->get($id) ?: [];
        $this->audit->record($actorId, $before ? 'campaign.updated' : 'campaign.created', 'campaign', $id, $instanceId, $before ?: [], $saved, $correlationId);
        return $saved;
    }

    public function duplicate(int $id, int $actorId): array
    {
        $row = $this->campaigns->get_by_id($id);
        if (!$row) {
            throw new RuntimeException('Campanha nao encontrada.', 404);
        }
        $newId = $this->campaigns->create_record([
            'instance_id' => $row['instance_id'], 'name' => 'Copia de ' . $row['name'], 'description' => $row['description'], 'status' => 'draft',
            'audience_json' => $row['audience_json'], 'message_content' => $row['message_content'], 'media_id' => $row['media_id'], 'schedule_json' => $row['schedule_json'],
            'metrics_json' => json_encode([], JSON_UNESCAPED_UNICODE), 'correlation_id' => $this->uuid(), 'idempotency_key' => hash('sha256', random_bytes(32)), 'created_by' => $actorId,
        ]);
        $this->audit->record($actorId, 'campaign.duplicated', 'campaign', $newId, (int) $row['instance_id'], ['source_id' => $id], ['status' => 'draft']);
        return $this->get($newId) ?: [];
    }

    public function toggle(int $id, int $actorId): array
    {
        $row = $this->campaigns->get_by_id($id);
        if (!$row) {
            throw new RuntimeException('Campanha nao encontrada.', 404);
        }
        $external = trim((string) ($row['external_id'] ?? ''));
        if ($external === '') {
            throw new RuntimeException('A campanha ainda nao possui identificador no n8n.', 409);
        }
        $pausing = !in_array((string) $row['status'], ['paused', 'draft', 'failed'], true);
        $path = $pausing ? $this->campaignStopPath($external) : $this->campaignPath($external);
        $response = $this->n8n->request($pausing ? 'POST' : 'PUT', $path, $pausing ? ['id' => $external] : ['fl_ativo' => true, 'status' => 'running'], ['correlation_id' => (string) $row['correlation_id'], 'idempotent' => true]);
        if (!$response['success']) {
            throw new RuntimeException((string) $response['error'], 502);
        }
        $status = $pausing ? 'paused' : 'running';
        $this->campaigns->update_record($id, ['status' => $status, 'last_sync_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null]);
        $this->audit->record($actorId, $pausing ? 'campaign.paused' : 'campaign.resumed', 'campaign', $id, (int) $row['instance_id'], ['status' => $row['status']], ['status' => $status], (string) $row['correlation_id']);
        if ($pausing) {
            $this->notifyCampaign($id, (string) $row['name'], 'Campanha pausada', 'O disparo foi pausado no n8n.', 'warning', 'campaign-paused|' . $id . '|' . gmdate('YmdHi'));
        }
        return $this->get($id) ?: [];
    }

    public function delete(int $id, int $actorId): void
    {
        $row = $this->campaigns->get_by_id($id);
        if (!$row) {
            throw new RuntimeException('Campanha nao encontrada.', 404);
        }
        $external = trim((string) ($row['external_id'] ?? ''));
        if ($external !== '') {
            $response = $this->n8n->request('DELETE', $this->campaignPath($external), null, ['correlation_id' => (string) $row['correlation_id'], 'idempotent' => true]);
            if (!$response['success'] && $response['status_code'] !== 404) {
                throw new RuntimeException((string) $response['error'], 502);
            }
        }
        $this->campaigns->soft_delete($id);
        $this->audit->record($actorId, 'campaign.deleted', 'campaign', $id, (int) $row['instance_id'], $row);
    }

    public function health(): array
    {
        return $this->n8n->health();
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
        foreach ($recipients as $recipient) {
            $phone = (string) $recipient['phone'];
            $this->db->table('chat_campaign_recipients')->upsert([
                'campaign_id' => $campaignId, 'contact_id' => $recipient['contact_id'], 'phone_hash' => hash('sha256', $phone), 'phone_normalized' => $phone,
                'status' => 'pending', 'updated_at' => gmdate('Y-m-d H:i:s'), 'deleted' => 0,
            ]);
        }
    }

    /** @return array<int,string> */
    private function n8nAudienceNumbers(array $input, int $instanceId): array
    {
        $response = $this->n8n->request('POST', rtrim($this->campaignPath(), '/') . '/audience-preview', [
            'instance_id' => $instanceId,
            'source' => 'n8n',
            'include_tags' => $this->normalizeTags($input['include_tags'] ?? []),
            'exclude_tags' => $this->normalizeTags($input['exclude_tags'] ?? []),
            'filters' => is_array($input['filters'] ?? null) ? $input['filters'] : [],
            'contract_version' => '1.1.0',
        ], ['idempotent' => true]);
        if (!$response['success']) {
            throw new RuntimeException((string) $response['error'], 502);
        }
        $data = is_array($response['data']) ? $response['data'] : [];
        $rows = $data['recipients'] ?? $data['lista_contato'] ?? $data['data'] ?? $data;
        if (!is_array($rows) || isset($rows['_truncated'])) {
            throw new RuntimeException('O n8n nao retornou uma lista de publico valida.', 502);
        }
        $numbers = [];
        foreach ($rows as $row) {
            $value = is_array($row) ? ($row['phone'] ?? $row['numero'] ?? $row['number'] ?? '') : $row;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $numbers[] = (string) $value;
            }
        }
        return $numbers;
    }

    private function notifyCampaign(int $id, string $name, string $title, string $message, string $level, string $dedupe): void
    {
        try {
            (new Notification_service())->create('campaign', $title, $name . ': ' . mb_substr($message, 0, 1500), 'campaign', $id, null, $level, $dedupe);
        } catch (\Throwable $exception) {
            // Notification failures cannot change campaign provider semantics.
        }
    }

    private function legacyPayload(array $input, array $instance, array $recipients, array $schedule): array
    {
        $media = null;
        if (!empty($input['media_id'])) {
            $mediaRow = $this->db->table('chat_media')->where('id', (int) $input['media_id'])->where('deleted', 0)->get(1)->getRowArray();
            if (!$mediaRow) throw new InvalidArgumentException('Midia da campanha nao encontrada.');
            $media = ['id'=>(int)$mediaRow['id'],'url'=>(new Media_service())->signedUrl((int)$mediaRow['id'],3600),'mime_type'=>(string)$mediaRow['mime_type'],'name'=>(string)($mediaRow['original_name']??'arquivo')];
        }
        return [
            'nome' => trim((string) $input['name']),
            'descricao' => trim((string) ($input['description'] ?? '')),
            'instance_id' => (int) $instance['id'],
            'instancia' => (string) $instance['evolution_instance_name'],
            'lista_contato' => array_map(static fn (array $row): array => ['numero' => $row['phone'], 'nome' => $row['name'], 'empresa' => $row['company'], 'cidade' => $row['city']], $recipients),
            'dias_semana' => $schedule['days_of_week'],
            'horario_disparo' => $schedule['at'],
            'dt_inicio' => $schedule['at'],
            'dt_fim' => null,
            'fl_ativo' => $schedule['start_immediately'] || $schedule['type'] === 'scheduled',
            'mensagem' => trim((string) ($input['message'] ?? $input['message_content'] ?? '')),
            'media_id' => !empty($input['media_id']) ? (int) $input['media_id'] : null,
            'midia' => $media,
            'contract_version' => '1.1.0',
        ];
    }

    private function map(array $row): array
    {
        $audience = $this->json((string) ($row['audience_json'] ?? ''));
        $schedule = $this->json((string) ($row['schedule_json'] ?? ''));
        $metrics = $this->json((string) ($row['metrics_json'] ?? ''));
        return [
            'id' => (int) $row['id'], 'external_id' => $row['external_id'] ?: null, 'instance_id' => (int) $row['instance_id'], 'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''), 'status' => $this->normalizeStatus((string) ($row['status'] ?? 'draft')), 'message' => (string) $row['message_content'],
            'media_id' => isset($row['media_id']) ? (int) $row['media_id'] : null, 'audience' => $audience, 'schedule' => $schedule, 'metrics' => $metrics,
            'audience_count' => (int) ($audience['recipient_count'] ?? $metrics['audience'] ?? 0), 'last_error' => $row['last_error'] ?: null,
            'audience_source' => (string) ($audience['source'] ?? 'contacts'), 'include_tags' => is_array($audience['include_tags'] ?? null) ? $audience['include_tags'] : [],
            'exclude_tags' => is_array($audience['exclude_tags'] ?? null) ? $audience['exclude_tags'] : [], 'numbers' => [],
            'type' => (string) ($schedule['type'] ?? 'one_time'), 'start_date' => !empty($schedule['at']) ? date('Y-m-d', strtotime((string) $schedule['at'])) : '',
            'start_time' => !empty($schedule['at']) ? date('H:i', strtotime((string) $schedule['at'])) : '', 'end_time' => (string) $this->settings->get_value('campaign_window_end', '20:00'),
            'interval_seconds' => (int) $this->settings->get_value('campaign_min_interval_seconds', 8), 'weekdays' => is_array($schedule['days_of_week'] ?? null) ? $schedule['days_of_week'] : [],
            'scheduled' => !empty($schedule['at']) ? date('d/m/Y H:i', strtotime((string) $schedule['at'])) : 'Sem agendamento',
            'sent' => (int) ($metrics['sent'] ?? 0), 'delivered' => (int) ($metrics['delivered'] ?? 0), 'read' => (int) ($metrics['read'] ?? 0), 'replied' => (int) ($metrics['replied'] ?? 0),
            'last_sync_at' => $row['last_sync_at'] ?? null, 'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function mapTemplate(array $row): array
    {
        return ['id' => (int) ($row['id'] ?? 0), 'name' => (string) ($row['name'] ?? ''), 'message' => (string) ($row['message_content'] ?? ''), 'media_id' => isset($row['media_id']) ? (int) $row['media_id'] : null, 'active' => !empty($row['active'])];
    }

    private function campaignPath(?string $id = null): string
    {
        $base = '/' . ltrim(trim((string) $this->settings->get_value('n8n_campaigns_path', '/webhook/campanha')), '/');
        return $id ? rtrim($base, '/') . '/' . rawurlencode($id) : $base;
    }

    private function campaignStopPath(string $id): string
    {
        $base = $this->campaignPath();
        $stop = preg_replace('#/campanha/?$#', '/campanha-stop', $base) ?: rtrim($base, '/') . '-stop';
        return rtrim($stop, '/') . '/' . rawurlencode($id);
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

    private function validateTemplateVariables(string $message): void
    {
        preg_match_all('/\{([^{}]+)\}/u', $message, $matches);
        $unknown = array_diff(array_unique($matches[1] ?? []), ['nome', 'telefone', 'empresa', 'cidade']);
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
