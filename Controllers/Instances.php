<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Libraries\Chat_permissions;
use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Services\Chat_service;
use Chatwoot_plugin\Services\Audit_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Instances extends Api_controller
{
    private Chat_instances_model $instances;
    private Chat_service $chat;

    public function __construct()
    {
        parent::__construct();
        $this->instances = new Chat_instances_model();
        $this->chat = new Chat_service($this->instances);
    }

    public function index(): ResponseInterface
    {
        $filters = [];
        $search = trim((string) $this->request->getGet('search'));
        if ($search !== '') {
            $filters['search'] = substr($search, 0, 150);
        }
        if ($this->request->getGet('active') !== null) {
            $filters['active'] = filter_var($this->request->getGet('active'), FILTER_VALIDATE_BOOLEAN);
        }

        try {
            $result = $this->chat->list_instances(
                $filters,
                max(1, (int) $this->request->getGet('page')),
                min(100, max(1, (int) ($this->request->getGet('limit') ?: 100)))
            );
            $data = $result['data'];
            if (!Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_INSTANCES)) {
                $data = array_map(fn (array $instance): array => $this->channelProjection($instance), $data);
            }

            return $this->success($data, ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar as instancias.');
        }
    }

    public function show(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $instance = $this->chat->get_instance($id);
        if (!$instance) {
            return $this->error('Instancia nao encontrada.', 404);
        }

        return $this->success($instance);
    }

    public function create(): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $validation = $this->validatePayload($this->input(), true);
        if ($validation['errors']) {
            return $this->error('Revise os dados da instancia.', 422, $validation['errors']);
        }

        try {
            $payload = $validation['data'];

            // Evolution instances are created in the provider first. The API
            // key stays server-side; the browser only receives the local row.
            $evolutionClient = null;
            if (($payload['provider_type'] ?? 'evolution') === 'evolution') {
                $evolutionClient = $this->evolutionClient($payload);
                $providerPayload = [
                    'instanceName' => (string) $payload['evolution_instance_name'],
                    'integration' => 'WHATSAPP-BAILEYS',
                    'qrcode' => true,
                ];
                if (!empty($payload['phone_number'])) {
                    $providerPayload['number'] = (string) $payload['phone_number'];
                }
                $created = $evolutionClient->create_instance($providerPayload, $payload);
                if (empty($created['success'])) {
                    return $this->providerFailure($created, 'Nao foi possivel criar a instancia na Evolution API.');
                }
                $payload['connection_status'] = 'attention';
                $payload['provider_status'] = 'connecting';
            }

            $id = $this->instances->upsert_instance((string) $payload['internal_identifier'], $payload);
            $saved = $this->chat->get_instance($id);
            $warnings = [];
            if ($evolutionClient instanceof Evolution_client && is_array($saved)) {
                $webhook = $evolutionClient->set_webhook($saved, $this->riseWebhookPayload());
                if (empty($webhook['success'])) {
                    $warnings[] = 'A instancia foi criada, mas o webhook do Rise precisa ser conferido.';
                }
            }
            (new Audit_service())->record(
                $this->actorId(),
                'instance.created',
                'instance',
                $id,
                $id,
                [],
                $this->auditProjection($saved ?? [])
            );

            return $this->success($saved, $warnings ? ['warnings' => $warnings] : [], 201);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel salvar a instancia.', 422);
        }
    }

    public function update(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $existing = $this->instances->get_by_id($id);
        if (!$existing) {
            return $this->error('Instancia nao encontrada.', 404);
        }

        $validation = $this->validatePayload($this->input(), false, $existing);
        $next = $validation['data'];
        if (array_key_exists('base_url', $next)) {
            $oldBaseUrl = trim((string) ($existing['base_url'] ?? ''));
            $nextBaseUrl = trim((string) ($next['base_url'] ?? ''));
            $baseChanged = rtrim($oldBaseUrl, '/') !== rtrim($nextBaseUrl, '/');
            $originChanged = $baseChanged && !$this->sameOrigin($oldBaseUrl, $nextBaseUrl);
            if ($originChanged
                && !empty($existing['has_api_key'])
                && empty($next['api_key'])
                && empty($next['clear_api_key'])) {
                $validation['errors']['api_key'] = 'Ao alterar a origem, informe novamente a chave da instancia ou marque a remocao da chave atual.';
            }
        }
        if ($validation['errors']) {
            return $this->error('Revise os dados da instancia.', 422, $validation['errors']);
        }

        try {
            $this->instances->update_instance($id, $validation['data']);
            $saved = $this->chat->get_instance($id);
            (new Audit_service())->record(
                $this->actorId(),
                'instance.updated',
                'instance',
                $id,
                $id,
                $this->auditProjection($existing),
                $this->auditProjection($saved ?? [])
            );

            return $this->success($saved);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel atualizar a instancia.', 422);
        }
    }

    public function status(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();

        try {
            return $this->success($this->chat->refresh_instance_status($id));
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Falha ao consultar a conexao da instancia.', 502);
        }
    }

    public function delete(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $existing = $this->instances->get_by_id($id);
        if (!$existing) return $this->error('Instancia nao encontrada.', 404);
        try {
            $this->instances->soft_delete_instance($id);
            (new Audit_service())->record($this->actorId(), 'instance.deleted', 'instance', $id, $id, $existing);
            return $this->success(['id' => $id, 'deleted' => true]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel remover a instancia.');
        }
    }

    public function refresh_all(): ResponseInterface
    {
        $this->requireManageInstancesPermission();

        try {
            return $this->success($this->chat->refresh_all_instance_statuses());
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Falha ao consultar as instancias.', 502);
        }
    }

    /** Synchronizes the Rise catalogue with Evolution Manager's instance list. */
    public function sync_evolution(): ResponseInterface
    {
        $this->requireManageInstancesPermission();

        try {
            $response = (new Evolution_client())->fetch_instances();
            if (empty($response['success'])) {
                return $this->providerFailure($response, 'Nao foi possivel consultar as instancias da Evolution API.');
            }

            $remoteInstances = $this->remoteInstanceList($response['data'] ?? null);
            $synced = [];
            $webhookWarnings = 0;
            foreach ($remoteInstances as $remote) {
                if (!is_array($remote)) {
                    continue;
                }

                $remoteName = $this->remoteInstanceName($remote);
                if ($remoteName === '') {
                    continue;
                }

                $existing = $this->instances->get_by_evolution_name($remoteName);
                if (!$existing) {
                    $candidateIdentifier = $this->safeInternalIdentifier($remoteName);
                    $candidate = $this->instances->get_by_identifier($candidateIdentifier);
                    if ($candidate && (string) ($candidate['evolution_instance_name'] ?? '') !== $remoteName) {
                        $candidateIdentifier = 'evolution_' . $candidateIdentifier;
                    }
                    $existing = $this->instances->get_by_identifier($candidateIdentifier);
                }

                $identifier = (string) ($existing['internal_identifier'] ?? $this->safeInternalIdentifier($remoteName));
                $status = $this->remoteConnectionStatus($remote);
                $payload = [
                    'provider_type' => 'evolution',
                    'name' => (string) ($existing['name'] ?? $remote['profileName'] ?? $remoteName),
                    'internal_identifier' => $identifier,
                    'evolution_instance_name' => $remoteName,
                    'connection_status' => $status,
                    'provider_status' => $this->remoteProviderStatus($remote, $status),
                    'active' => 1,
                ];
                $phone = $this->remotePhone($remote);
                if ($phone !== '') {
                    $payload['phone_number'] = $phone;
                }

                $id = $this->instances->upsert_instance($identifier, $payload);
                $local = $this->instances->get_by_id($id);
                if (is_array($local)) {
                    $webhook = $this->evolutionClient($local)->set_webhook($local, $this->riseWebhookPayload());
                    if (empty($webhook['success'])) {
                        $webhookWarnings++;
                    }
                }
                $synced[] = ['id' => $id, 'name' => $remoteName, 'status' => $status];
            }

            return $this->success([
                'count' => count($synced),
                'instances' => $synced,
            ], $webhookWarnings ? ['warnings' => $webhookWarnings . ' webhook(s) nao puderam ser atualizados.'] : []);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Falha ao sincronizar as instancias da Evolution API.', 502);
        }
    }

    /** Returns a QR code or pairing code without exposing provider credentials. */
    public function connect(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $instance = $this->getEvolutionInstance($id);
        if ($instance instanceof ResponseInterface) {
            return $instance;
        }

        try {
            $number = trim((string) $this->request->getGet('number'));
            if ($number !== '') {
                $number = (string) preg_replace('/\D+/', '', $number);
                if (strlen($number) > 32) {
                    return $this->error('Numero de pareamento invalido.', 422);
                }
            }

            $response = $this->evolutionClient($instance)->connect_instance($instance, $number !== '' ? $number : null);
            if (empty($response['success'])) {
                return $this->providerFailure($response, 'Nao foi possivel gerar o QR Code da Evolution.');
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $qr = $this->firstScalar($data, ['base64', 'qrCode', 'qr_code']);
            $pairingCode = $this->firstScalar($data, ['pairingCode', 'pairing_code']);
            $this->instances->update_connection_status($id, $qr !== '' || $pairingCode !== '' ? 'attention' : 'disconnected', gmdate('Y-m-d H:i:s'));

            return $this->success([
                'instance_id' => $id,
                'instance_name' => (string) ($instance['evolution_instance_name'] ?? ''),
                'base64' => $qr,
                'pairing_code' => $pairingCode,
                'provider' => $this->safeProviderData($data),
            ]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Falha ao conectar a instancia Evolution.', 502);
        }
    }

    public function restart(int $id): ResponseInterface
    {
        return $this->runEvolutionAction($id, 'restart');
    }

    public function logout(int $id): ResponseInterface
    {
        return $this->runEvolutionAction($id, 'logout');
    }

    /** Removes the instance from Evolution and then archives the local channel. */
    public function delete_evolution(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $instance = $this->getEvolutionInstance($id);
        if ($instance instanceof ResponseInterface) {
            return $instance;
        }

        try {
            $response = $this->evolutionClient($instance)->delete_instance($instance);
            if (empty($response['success'])) {
                return $this->providerFailure($response, 'Nao foi possivel remover a instancia da Evolution API.');
            }

            $this->instances->soft_delete_instance($id);
            (new Audit_service())->record(
                $this->actorId(),
                'instance.evolution_deleted',
                'instance',
                $id,
                $id,
                $this->auditProjection($instance),
                ['deleted_from_evolution' => true]
            );

            return $this->success(['id' => $id, 'deleted' => true, 'provider' => 'evolution']);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel remover a instancia da Evolution.', 502);
        }
    }

    /** @return ResponseInterface|array<string,mixed> */
    private function getEvolutionInstance(int $id)
    {
        $instance = $this->instances->get_by_id($id);
        if (!$instance) {
            return $this->error('Instancia nao encontrada.', 404);
        }
        if (strtolower((string) ($instance['provider_type'] ?? 'evolution')) !== 'evolution') {
            return $this->error('Esta acao esta disponivel apenas para instancias Evolution.', 422);
        }

        return $instance;
    }

    private function runEvolutionAction(int $id, string $action): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        $instance = $this->getEvolutionInstance($id);
        if ($instance instanceof ResponseInterface) {
            return $instance;
        }

        try {
            $client = $this->evolutionClient($instance);
            $response = $action === 'restart'
                ? $client->restart_instance($instance)
                : $client->logout_instance($instance);
            if (empty($response['success'])) {
                return $this->providerFailure($response, $action === 'restart'
                    ? 'Nao foi possivel reiniciar a instancia Evolution.'
                    : 'Nao foi possivel desconectar a instancia Evolution.');
            }

            $status = $action === 'restart' ? 'attention' : 'disconnected';
            $this->instances->update_connection_status($id, $status, gmdate('Y-m-d H:i:s'));

            return $this->success(['id' => $id, 'action' => $action, 'status' => $status]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Falha ao alterar a instancia Evolution.', 502);
        }
    }

    /** @return array{data:array<string,mixed>,errors:array<string,string>} */
    private function validatePayload(array $input, bool $creating, array $existing = []): array
    {
        $data = [];
        $errors = [];
        $provider = strtolower(trim((string) ($input['provider_type'] ?? $existing['provider_type'] ?? 'evolution')));
        if (!in_array($provider, ['evolution', 'meta_cloud'], true)) {
            $errors['provider_type'] = 'Selecione Evolution ou WhatsApp Cloud API.';
            $provider = 'evolution';
        }
        $data['provider_type'] = $provider;

        foreach (['name', 'internal_identifier'] as $field) {
            $value = trim((string) ($input[$field] ?? ($existing[$field] ?? '')));
            if ($value === '') { $errors[$field] = 'Campo obrigatorio.'; continue; }
            $limit = $field === 'name' ? 150 : 191;
            if (strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) { $errors[$field] = 'Valor invalido ou muito longo.'; continue; }
            if ($field === 'internal_identifier' && !preg_match('/^[A-Za-z0-9._-]+$/', $value)) { $errors[$field] = 'Use apenas letras, numeros, ponto, hifen e sublinhado.'; continue; }
            $data[$field] = $value;
        }

        $evolutionName = trim((string) ($input['evolution_instance_name'] ?? ($existing['evolution_instance_name'] ?? '')));
        if ($provider === 'evolution') {
            if ($evolutionName === '') $errors['evolution_instance_name'] = 'Informe o nome exato da instancia Evolution.';
            elseif (strlen($evolutionName) > 191 || preg_match('/[\x00-\x1F\x7F]/', $evolutionName)) $errors['evolution_instance_name'] = 'Nome tecnico invalido.';
            else $data['evolution_instance_name'] = $evolutionName;
        } elseif ($evolutionName !== '') {
            $data['evolution_instance_name'] = $evolutionName;
        }

        if (Chat_permissions::can($this->login_user, Chat_permissions::MANAGE_SETTINGS)) {
            $baseUrl = trim((string) ($input['base_url'] ?? ($existing['base_url'] ?? '')));
            if ($baseUrl !== '' && !$this->isSafeBaseUrl($baseUrl)) $errors['base_url'] = 'Informe uma URL HTTP ou HTTPS valida, sem usuario, senha ou fragmento.';
            else $data['base_url'] = $baseUrl;
        }

        $phoneInput = trim((string) ($input['phone_number'] ?? ($existing['phone_number'] ?? '')));
        if ($phoneInput !== '' && !preg_match('/^[0-9+() .-]+$/', $phoneInput)) $errors['phone_number'] = 'Numero de telefone invalido.';
        else {
            $phone = (string) preg_replace('/\D+/', '', $phoneInput);
            if (strlen($phone) > 32) $errors['phone_number'] = 'Numero de telefone muito longo.';
            else $data['phone_number'] = $phone;
        }

        if ($provider === 'meta_cloud') {
            foreach (['meta_phone_number_id' => 'Phone Number ID', 'meta_waba_id' => 'WhatsApp Business Account ID'] as $field => $label) {
                $value = trim((string) ($input[$field] ?? ($existing[$field] ?? '')));
                if ($value === '' || !preg_match('/^[0-9]{5,64}$/', $value)) $errors[$field] = $label . ' invalido.';
                else $data[$field] = $value;
            }
            $version = strtolower(trim((string) ($input['meta_graph_version'] ?? ($existing['meta_graph_version'] ?? 'v25.0'))));
            if (!preg_match('/^v\d{1,2}\.0$/', $version)) $errors['meta_graph_version'] = 'Versao Graph invalida. Exemplo: v25.0.';
            else $data['meta_graph_version'] = $version;
            foreach (['meta_access_token'=>'token de acesso','meta_verify_token'=>'token de verificacao','meta_app_secret'=>'App Secret'] as $field=>$label) {
                $value = trim((string) ($input[$field] ?? ''));
                $hasKey = 'has_' . $field;
                $clearKey = 'clear_' . $field;
                $clear = filter_var($input[$clearKey] ?? false, FILTER_VALIDATE_BOOLEAN);
                if (strlen($value) > 8192 || preg_match('/[\r\n]/', $value)) $errors[$field] = 'Credencial invalida.';
                elseif ($value !== '') $data[$field] = $value;
                if (!$creating) $data[$clearKey] = $clear;
                if (($creating || empty($existing[$hasKey])) && $value === '' && !$clear) $errors[$field] = 'Informe o ' . $label . '.';
                if ($clear && $value !== '') $errors[$field] = 'Informe uma nova credencial ou remova a atual; nao use as duas opcoes.';
            }
        }

        $apiKey = trim((string) ($input['api_key'] ?? ''));
        if (strlen($apiKey) > 4096 || preg_match('/[\r\n]/', $apiKey)) $errors['api_key'] = 'Credencial invalida.';
        elseif ($apiKey !== '') $data['api_key'] = $apiKey;
        $clearApiKey = filter_var($input['clear_api_key'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($clearApiKey && $apiKey !== '') $errors['api_key'] = 'Informe uma nova chave ou remova a atual; nao use as duas opcoes.';
        if (!$creating) $data['clear_api_key'] = $clearApiKey;

        $data['active'] = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($creating) { $data['connection_status'] = 'disconnected'; $data['provider_status'] = 'disconnected'; }
        return ['data'=>$data,'errors'=>$errors];
    }

    /** @param array<string,mixed> $instance */
    private function evolutionClient(array $instance): Evolution_client
    {
        $id = (int) ($instance['id'] ?? 0);
        if ($id > 0) {
            $apiKey = $this->instances->get_decrypted_api_key($id);
            if (is_string($apiKey) && trim($apiKey) !== '') {
                $instance['api_key'] = $apiKey;
            }
        }

        return new Evolution_client(['instance' => $instance]);
    }

    /** @param array<string,mixed> $response */
    private function providerFailure(array $response, string $message): ResponseInterface
    {
        $status = (int) ($response['status_code'] ?? $response['http_status'] ?? 0);
        $details = ['code' => (string) ($response['error_code'] ?? 'evolution_api_error')];
        if ($status >= 400 && $status <= 599) {
            $details['provider_status'] = $status;
        }

        return $this->error($message, 502, $details);
    }

    /** @return array<int,array<string,mixed>> */
    private function remoteInstanceList($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        foreach (['data', 'instances', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->remoteInstanceList($data[$key]);
            }
        }

        $list = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /** @param array<string,mixed> $remote */
    private function remoteInstanceName(array $remote): string
    {
        return $this->firstScalar($remote, ['instanceName', 'instance_name', 'name', 'instance']);
    }

    /** @param array<string,mixed> $remote */
    private function remoteConnectionStatus(array $remote): string
    {
        $state = strtolower($this->firstScalar($remote, ['connectionStatus', 'connection_status', 'state', 'status']));
        if (in_array($state, ['open', 'connected', 'online'], true)) {
            return 'connected';
        }
        if (in_array($state, ['connecting', 'qr', 'qrcode', 'pairing'], true)) {
            return 'attention';
        }
        if ($state === 'error') {
            return 'error';
        }

        return 'disconnected';
    }

    /** @param array<string,mixed> $remote */
    private function remoteProviderStatus(array $remote, string $fallback): string
    {
        $status = trim($this->firstScalar($remote, ['connectionStatus', 'connection_status', 'state', 'status']));

        return substr($status !== '' ? $status : $fallback, 0, 32);
    }

    /** @param array<string,mixed> $remote */
    private function remotePhone(array $remote): string
    {
        $phone = $this->firstScalar($remote, ['number', 'phone', 'phoneNumber', 'owner']);
        $phone = (string) preg_replace('/\D+/', '', $phone);

        return strlen($phone) <= 32 ? $phone : '';
    }

    private function safeInternalIdentifier(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?: 'evolution_instance';
        $value = trim($value, '._-');
        if ($value === '') {
            $value = 'evolution_instance';
        }

        return substr($value, 0, 180);
    }

    /** @param array<string,mixed> $source @param array<int,string> $keys */
    private function firstScalar(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /** @param mixed $value */
    private function safeProviderData($value)
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $child) {
                if (preg_match('/api[_-]?key|token|hash|authorization|password|secret/i', (string) $key)) {
                    continue;
                }
                $safe[$key] = $this->safeProviderData($child);
            }

            return $safe;
        }
        if (is_object($value)) {
            return $this->safeProviderData(get_object_vars($value));
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function riseWebhookPayload(): array
    {
        $secret = trim((string) (new Chat_settings_model())->get_value(Chat_settings_model::WEBHOOK_SECRET, ''));
        $payload = [
            'enabled' => true,
            'url' => function_exists('get_uri') ? get_uri('chatwoot_plugin/webhooks/evolution') : '',
            'webhookByEvents' => false,
            'webhookBase64' => false,
            'events' => [
                'APPLICATION_STARTUP',
                'QRCODE_UPDATED',
                'MESSAGES_SET',
                'MESSAGES_UPSERT',
                'MESSAGES_UPDATE',
                'MESSAGES_DELETE',
                'SEND_MESSAGE',
                'CONTACTS_SET',
                'CONTACTS_UPSERT',
                'CONTACTS_UPDATE',
                'PRESENCE_UPDATE',
                'CHATS_SET',
                'CHATS_UPSERT',
                'CHATS_UPDATE',
                'CHATS_DELETE',
                'GROUPS_UPSERT',
                'GROUP_UPDATE',
                'GROUP_PARTICIPANTS_UPDATE',
                'CONNECTION_UPDATE',
                'REMOVE_INSTANCE',
                'LOGOUT_INSTANCE',
                'LABELS_EDIT',
                'LABELS_ASSOCIATION',
                'CALL',
            ],
        ];
        if ($secret !== '') {
            $payload['headers'] = [
                'X-Chatwoot-Webhook-Secret' => $secret,
            ];
        }

        return $payload;
    }

    private function isSafeBaseUrl(string $url): bool
    {
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $leftParts = parse_url($left);
        $rightParts = parse_url($right);
        if (!is_array($leftParts) || !is_array($rightParts)) {
            return false;
        }

        $leftScheme = strtolower((string) ($leftParts['scheme'] ?? ''));
        $rightScheme = strtolower((string) ($rightParts['scheme'] ?? ''));
        $leftHost = strtolower(rtrim((string) ($leftParts['host'] ?? ''), '.'));
        $rightHost = strtolower(rtrim((string) ($rightParts['host'] ?? ''), '.'));
        if ($leftScheme === '' || $rightScheme === '' || $leftHost === '' || $rightHost === '') {
            return false;
        }

        $leftPort = (int) ($leftParts['port'] ?? ($leftScheme === 'https' ? 443 : 80));
        $rightPort = (int) ($rightParts['port'] ?? ($rightScheme === 'https' ? 443 : 80));

        return $leftScheme === $rightScheme
            && $leftHost === $rightHost
            && $leftPort === $rightPort;
    }

    /** @return array<string,mixed> */
    private function channelProjection(array $instance): array
    {
        return [
            'id' => (int) ($instance['id'] ?? 0),
            'name' => (string) ($instance['name'] ?? ''),
            'phone' => (string) ($instance['phone'] ?? ''),
            'phone_number' => (string) ($instance['phone_number'] ?? ''),
            'status' => (string) ($instance['status'] ?? 'disconnected'),
            'connection_status' => (string) ($instance['connection_status'] ?? 'disconnected'),
            'active' => !empty($instance['active']),
            'conversation_count' => (int) ($instance['conversation_count'] ?? 0),
            'open_conversations' => (int) ($instance['open_conversations'] ?? 0),
            'unread_count' => (int) ($instance['unread_count'] ?? 0),
            'last_sync_at' => $instance['last_sync_at'] ?? null,
        ];
    }

    /** Never persist encrypted or plain credentials in audit JSON. */
    private function auditProjection(array $instance): array
    {
        return array_intersect_key($instance, array_flip([
            'id',
            'name',
            'evolution_instance_name',
            'internal_identifier',
            'base_url',
            'phone_number',
            'connection_status',
            'active',
        ]));
    }

    private function internalFailure(
        Throwable $exception,
        string $message,
        int $status = 500
    ): ResponseInterface {
        log_message('error', 'Chatwoot_plugin instances API failed ({exception_type}).', [
            'exception_type' => get_class($exception),
        ]);

        return $this->error($message, $status);
    }
}
