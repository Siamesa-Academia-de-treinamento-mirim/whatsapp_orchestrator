<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Chat_service;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

class Settings extends Api_controller
{
    private Chat_service $chat;

    public function __construct()
    {
        parent::__construct();
        $this->chat = new Chat_service();
    }

    public function show(): ResponseInterface
    {
        $this->requireManageSettingsPermission();

        try {
            return $this->success($this->chat->public_settings());
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar as configuracoes.');
        }
    }

    public function update(): ResponseInterface
    {
        $this->requireManageSettingsPermission();
        $validation = $this->validatePayload($this->input());
        if ($validation['errors']) {
            return $this->error('Revise as configuracoes informadas.', 422, $validation['errors']);
        }

        try {
            return $this->success($this->chat->update_settings($validation['data'], $this->actorId()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel salvar as configuracoes.');
        }
    }

    public function test(): ResponseInterface
    {
        $this->requireManageSettingsPermission();
        $input = $this->input();
        $instanceId = (int) ($input['instance_id'] ?? 0);

        try {
            $result = $instanceId > 0
                ? [$this->chat->refresh_instance_status($instanceId)]
                : $this->chat->refresh_all_instance_statuses();

            return $this->success($result);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel testar a conexao.', 502);
        }
    }

    /** @return array{data:array<string,mixed>,errors:array<string,string>} */
    private function validatePayload(array $input): array
    {
        $errors = [];
        $baseUrl = trim((string) ($input['evolution_base_url'] ?? ''));
        if ($baseUrl !== '' && !$this->isSafeBaseUrl($baseUrl)) {
            $errors['evolution_base_url'] = 'Informe uma URL HTTP ou HTTPS valida, sem credenciais ou fragmento.';
        }

        $timeout = (int) ($input['request_timeout_seconds'] ?? 30);
        if ($timeout < 3 || $timeout > 120) {
            $errors['request_timeout_seconds'] = 'Use um timeout entre 3 e 120 segundos.';
        }
        $polling = (int) ($input['polling_interval_ms'] ?? 5000);
        if ($polling < 3000 || $polling > 60000) {
            $errors['polling_interval_ms'] = 'Use um intervalo entre 3000 e 60000 ms.';
        }

        $endpointFields = [
            'connection_status_path',
            'find_chats_path',
            'find_messages_path',
            'send_text_path',
            'send_media_path',
            'send_audio_path',
            'get_media_base64_path',
        ];
        foreach ($endpointFields as $field) {
            $path = trim((string) ($input[$field] ?? ''));
            if (!$this->isSafeEndpointTemplate($path)) {
                $errors[$field] = 'Use um caminho relativo iniciado por / e contendo {instance}.';
            }
        }

        foreach (['global_api_key', 'webhook_secret', 'n8n_token'] as $field) {
            $secret = trim((string) ($input[$field] ?? ''));
            if (strlen($secret) > 4096 || preg_match('/[\r\n]/', $secret)) {
                $errors[$field] = 'Credencial invalida.';
            }
            if ($field === 'webhook_secret' && $secret !== '' && strlen($secret) < 16) {
                $errors[$field] = 'O segredo do webhook deve ter pelo menos 16 caracteres.';
            }
        }

        $n8nBase = rtrim(trim((string) ($input['n8n_base_url'] ?? '')), '/');
        if ($n8nBase !== '' && !$this->isSafeBaseUrl($n8nBase)) {
            $errors['n8n_base_url'] = 'Informe uma URL HTTP ou HTTPS valida para o n8n.';
        }
        $n8nPaths = ['n8n_health_path', 'n8n_campaigns_path', 'n8n_ai_path', 'n8n_events_path'];
        foreach ($n8nPaths as $field) {
            $path = trim((string) ($input[$field] ?? ''));
            if (!$this->isSafeRelativePath($path)) {
                $errors[$field] = 'Use um caminho relativo iniciado por /, sem query string.';
            }
        }
        $authMode = strtolower(trim((string) ($input['n8n_auth_mode'] ?? 'bearer')));
        if (!in_array($authMode, ['bearer', 'header', 'hmac'], true)) {
            $errors['n8n_auth_mode'] = 'Modo de autenticacao n8n invalido.';
        }
        $n8nHeaderName = trim((string) ($input['n8n_header_name'] ?? 'X-API-Key'));
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $n8nHeaderName)) {
            $errors['n8n_header_name'] = 'Nome de header n8n invalido.';
        }
        $n8nTimeout = (int) ($input['n8n_timeout_seconds'] ?? 30);
        if ($n8nTimeout < 3 || $n8nTimeout > 120) $errors['n8n_timeout_seconds'] = 'Use um timeout entre 3 e 120 segundos.';

        $moduleName = trim((string) ($input['module_name'] ?? 'Impulso Hub'));
        if ($moduleName === '' || mb_strlen($moduleName) > 100) $errors['module_name'] = 'Nome do modulo invalido.';
        $timezone = trim((string) ($input['timezone'] ?? 'America/Sao_Paulo'));
        try { new \DateTimeZone($timezone); } catch (Throwable $exception) { $errors['timezone'] = 'Fuso horario invalido.'; }
        $pageSize = (int) ($input['conversation_page_size'] ?? 30);
        if ($pageSize < 10 || $pageSize > 100) $errors['conversation_page_size'] = 'Use de 10 a 100 conversas por pagina.';
        $defaultStatus = strtolower(trim((string) ($input['default_status'] ?? 'open')));
        if (!in_array($defaultStatus, ['open', 'pending'], true)) $errors['default_status'] = 'Status inicial invalido.';
        $defaultPriority = strtolower(trim((string) ($input['default_priority'] ?? 'normal')));
        if (!in_array($defaultPriority, ['low', 'normal', 'high', 'urgent'], true)) $errors['default_priority'] = 'Prioridade inicial invalida.';

        $integerRules = [
            'sla_minutes' => [1, 10080, 30], 'auto_resolve_hours' => [0, 8760, 0], 'evolution_retries' => [0, 5, 2],
            'campaign_batch_size' => [1, 1000, 20], 'campaign_min_interval_seconds' => [1, 86400, 8], 'campaign_pause_after_errors' => [0, 1000, 5],
            'ai_auto_return_minutes' => [0, 10080, 0], 'webhook_retention_days' => [1, 3650, 30], 'audit_retention_days' => [1, 3650, 180],
            'conversation_retention_days' => [0, 3650, 0], 'media_retention_days' => [0, 3650, 30],
        ];
        $integers = [];
        foreach ($integerRules as $field => [$min, $max, $default]) {
            $value = isset($input[$field]) && is_numeric($input[$field]) ? (int) $input[$field] : $default;
            if ($value < $min || $value > $max) $errors[$field] = "Use um valor entre {$min} e {$max}.";
            $integers[$field] = $value;
        }
        foreach (['campaign_window_start', 'campaign_window_end'] as $field) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim((string) ($input[$field] ?? '')))) $errors[$field] = 'Horario invalido.';
        }
        $aiState = strtolower(trim((string) ($input['ai_default_state'] ?? 'running')));
        if (!in_array($aiState, ['running', 'paused', 'human'], true)) $errors['ai_default_state'] = 'Estado padrao da IA invalido.';
        foreach (['ai_stop_command', 'ai_start_command'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '' || mb_strlen($value) > 50 || preg_match('/\s/', $value)) $errors[$field] = 'Comando de IA invalido.';
        }
        $quickReplies = trim((string) ($input['quick_replies_json'] ?? '[]'));
        $decodedReplies = json_decode($quickReplies, true);
        if (!is_array($decodedReplies) || json_last_error() !== JSON_ERROR_NONE) $errors['quick_replies_json'] = 'O JSON de respostas rapidas e invalido.';

        $booleans = [];
        foreach (['sound_enabled','browser_notifications_enabled','auto_mark_read','n8n_allow_private_networks','ai_human_priority','ai_show_context','log_sanitized_webhooks','audit_enabled','secure_media','clear_global_api_key','clear_webhook_secret','clear_n8n_token'] as $field) {
            $booleans[$field] = filter_var($input[$field] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        $data = [
                'evolution_base_url' => rtrim($baseUrl, '/'),
                'global_api_key' => trim((string) ($input['global_api_key'] ?? '')),
                'request_timeout_seconds' => $timeout,
                'polling_interval_ms' => $polling,
                'webhook_secret' => trim((string) ($input['webhook_secret'] ?? '')),
                'connection_status_path' => trim((string) ($input['connection_status_path'] ?? '')),
                'find_chats_path' => trim((string) ($input['find_chats_path'] ?? '')),
                'find_messages_path' => trim((string) ($input['find_messages_path'] ?? '')),
                'send_text_path' => trim((string) ($input['send_text_path'] ?? '')),
                'send_media_path' => trim((string) ($input['send_media_path'] ?? '')),
                'send_audio_path' => trim((string) ($input['send_audio_path'] ?? '')),
                'get_media_base64_path' => trim((string) ($input['get_media_base64_path'] ?? '')),
                'module_name' => $moduleName, 'timezone' => $timezone, 'conversation_page_size' => $pageSize,
                'default_status' => $defaultStatus, 'default_priority' => $defaultPriority,
                'n8n_base_url' => $n8nBase, 'n8n_token' => trim((string) ($input['n8n_token'] ?? '')), 'n8n_auth_mode' => $authMode, 'n8n_header_name' => $n8nHeaderName, 'n8n_timeout_seconds' => $n8nTimeout,
                'n8n_health_path' => trim((string) ($input['n8n_health_path'] ?? '')), 'n8n_campaigns_path' => trim((string) ($input['n8n_campaigns_path'] ?? '')),
                'n8n_ai_path' => trim((string) ($input['n8n_ai_path'] ?? '')), 'n8n_events_path' => trim((string) ($input['n8n_events_path'] ?? '')),
                'campaign_window_start' => trim((string) ($input['campaign_window_start'] ?? '08:00')), 'campaign_window_end' => trim((string) ($input['campaign_window_end'] ?? '20:00')),
                'campaign_optout_text' => mb_substr(trim((string) ($input['campaign_optout_text'] ?? '')), 0, 1000), 'quick_replies_json' => $quickReplies,
                'ai_default_state' => $aiState, 'ai_stop_command' => trim((string) ($input['ai_stop_command'] ?? '@stop')), 'ai_start_command' => trim((string) ($input['ai_start_command'] ?? '@start')),
            ];
        $data = array_replace($data, $integers, $booleans);
        return [
            'data' => $data,
            'errors' => $errors,
        ];
    }

    private function isSafeEndpointTemplate(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 300
            && str_starts_with($path, '/')
            && str_contains($path, '{instance}')
            && !str_contains($path, '://')
            && !preg_match('/[\r\n?#]/', $path);
    }

    private function isSafeRelativePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 500 && str_starts_with($path, '/') && !str_contains($path, '://') && !preg_match('/[\r\n?#]/', $path);
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

    private function internalFailure(
        Throwable $exception,
        string $message,
        int $status = 500
    ): ResponseInterface {
        log_message('error', 'Chatwoot_plugin settings API failed ({exception_type}).', [
            'exception_type' => get_class($exception),
        ]);

        return $this->error($message, $status);
    }
}
