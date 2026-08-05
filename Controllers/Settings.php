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
        if ($validation['errors']) return $this->error('Revise as configuracoes informadas.', 422, $validation['errors']);

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
        $instanceId = (int) ($this->input()['instance_id'] ?? 0);
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
        $baseUrl = rtrim(trim((string) ($input['evolution_base_url'] ?? '')), '/');
        if ($baseUrl !== '' && !$this->isSafeBaseUrl($baseUrl)) {
            $errors['evolution_base_url'] = 'Informe uma URL HTTP ou HTTPS valida, sem credenciais ou fragmento.';
        }

        $timeout = $this->boundedInteger($input, 'request_timeout_seconds', 3, 120, 30, $errors);
        $polling = $this->boundedInteger($input, 'polling_interval_ms', 3000, 60000, 5000, $errors);
        $pageSize = $this->boundedInteger($input, 'conversation_page_size', 10, 100, 30, $errors);
        $autoResolve = $this->boundedInteger($input, 'auto_resolve_hours', 0, 8760, 0, $errors);
        $evolutionRetries = $this->boundedInteger($input, 'evolution_retries', 0, 5, 2, $errors);
        $campaignRate = $this->boundedInteger($input, 'campaign_default_rate_limit_per_minute', 1, 1000, 20, $errors);
        $campaignAttempts = $this->boundedInteger($input, 'campaign_recipient_max_attempts', 1, 20, 5, $errors);
        $campaignRetry = $this->boundedInteger($input, 'campaign_retry_delay_seconds', 30, 3600, 120, $errors);
        $campaignPause = $this->boundedInteger($input, 'campaign_pause_after_errors', 0, 1000, 5, $errors);
        $botTimeout = $this->boundedInteger($input, 'bot_session_timeout_minutes', 1, 10080, 1440, $errors);
        $webhookRetention = $this->boundedInteger($input, 'webhook_retention_days', 1, 3650, 30, $errors);
        $conversationRetention = $this->boundedInteger($input, 'conversation_retention_days', 0, 3650, 0, $errors);
        $mediaRetention = $this->boundedInteger($input, 'media_retention_days', 0, 3650, 30, $errors);

        $endpointFields = [
            'connection_status_path', 'find_chats_path', 'find_messages_path',
            'send_text_path', 'send_media_path', 'send_audio_path', 'get_media_base64_path',
        ];
        foreach ($endpointFields as $field) {
            $path = trim((string) ($input[$field] ?? ''));
            if (!$this->isSafeEndpointTemplate($path)) $errors[$field] = 'Use um caminho relativo iniciado por / e contendo {instance}.';
        }

        foreach (['global_api_key', 'webhook_secret'] as $field) {
            $secret = trim((string) ($input[$field] ?? ''));
            if (strlen($secret) > 4096 || preg_match('/[\r\n]/', $secret)) $errors[$field] = 'Credencial invalida.';
            if ($field === 'webhook_secret' && $secret !== '' && strlen($secret) < 16) {
                $errors[$field] = 'O segredo do webhook deve ter pelo menos 16 caracteres.';
            }
        }

        $moduleName = trim((string) ($input['module_name'] ?? 'Impulso Hub WhatsApp'));
        if ($moduleName === '' || mb_strlen($moduleName) > 100) $errors['module_name'] = 'Nome do modulo invalido.';
        $timezone = trim((string) ($input['timezone'] ?? 'America/Sao_Paulo'));
        try { new \DateTimeZone($timezone); } catch (Throwable $exception) { $errors['timezone'] = 'Fuso horario invalido.'; }

        $defaultStatus = strtolower(trim((string) ($input['default_status'] ?? 'open')));
        if (!in_array($defaultStatus, ['open', 'pending'], true)) $errors['default_status'] = 'Status inicial invalido.';
        $defaultPriority = strtolower(trim((string) ($input['default_priority'] ?? 'normal')));
        if (!in_array($defaultPriority, ['low', 'normal', 'high', 'urgent'], true)) $errors['default_priority'] = 'Prioridade inicial invalida.';

        $campaignStart = trim((string) ($input['campaign_window_start'] ?? '08:00'));
        $campaignEnd = trim((string) ($input['campaign_window_end'] ?? '20:00'));
        foreach (['campaign_window_start' => $campaignStart, 'campaign_window_end' => $campaignEnd] as $field => $value) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) $errors[$field] = 'Horario invalido.';
        }

        $quickReplies = trim((string) ($input['quick_replies_json'] ?? '[]'));
        $decodedReplies = json_decode($quickReplies, true);
        if (!is_array($decodedReplies) || json_last_error() !== JSON_ERROR_NONE || count($decodedReplies) > 200) {
            $errors['quick_replies_json'] = 'O JSON de respostas rapidas e invalido.';
        } else {
            foreach ($decodedReplies as $index => $reply) {
                if (!is_array($reply)) { $errors['quick_replies_json'] = 'Cada resposta rapida precisa ser um objeto.'; break; }
                $title = trim((string) ($reply['title'] ?? ''));
                $text = trim((string) ($reply['text'] ?? ''));
                if ($title === '' || mb_strlen($title) > 100 || $text === '' || mb_strlen($text) > 4096) {
                    $errors['quick_replies_json'] = 'Resposta rapida invalida na posicao ' . ($index + 1) . '.';
                    break;
                }
            }
        }

        $fallback = trim((string) ($input['bot_default_fallback'] ?? ''));
        $handoff = trim((string) ($input['bot_default_handoff'] ?? ''));
        if ($fallback === '' || mb_strlen($fallback) > 4096) $errors['bot_default_fallback'] = 'Mensagem de fallback invalida.';
        if ($handoff === '' || mb_strlen($handoff) > 4096) $errors['bot_default_handoff'] = 'Mensagem de encaminhamento invalida.';

        $bool = static fn (string $field): int => filter_var($input[$field] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $data = [
            'evolution_base_url' => $baseUrl,
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
            'module_name' => $moduleName,
            'timezone' => $timezone,
            'conversation_page_size' => $pageSize,
            'sound_enabled' => $bool('sound_enabled'),
            'browser_notifications_enabled' => $bool('browser_notifications_enabled'),
            'auto_mark_read' => $bool('auto_mark_read'),
            'default_status' => $defaultStatus,
            'default_priority' => $defaultPriority,
            'auto_resolve_hours' => $autoResolve,
            'evolution_retries' => $evolutionRetries,
            'campaign_window_start' => $campaignStart,
            'campaign_window_end' => $campaignEnd,
            'campaign_default_rate_limit_per_minute' => $campaignRate,
            'campaign_recipient_max_attempts' => $campaignAttempts,
            'campaign_retry_delay_seconds' => $campaignRetry,
            'campaign_pause_after_errors' => $campaignPause,
            'quick_replies_json' => $quickReplies,
            'bot_enabled' => $bool('bot_enabled'),
            'bot_session_timeout_minutes' => $botTimeout,
            'bot_default_fallback' => mb_substr($fallback, 0, 4096),
            'bot_default_handoff' => mb_substr($handoff, 0, 4096),
            'log_sanitized_webhooks' => $bool('log_sanitized_webhooks'),
            'webhook_retention_days' => $webhookRetention,
            'conversation_retention_days' => $conversationRetention,
            'media_retention_days' => $mediaRetention,
            'secure_media' => $bool('secure_media'),
            'clear_global_api_key' => $bool('clear_global_api_key'),
            'clear_webhook_secret' => $bool('clear_webhook_secret'),
        ];

        return ['data' => $data, 'errors' => $errors];
    }

    /** @param array<string,mixed> $input @param array<string,string> $errors */
    private function boundedInteger(array $input, string $field, int $min, int $max, int $default, array &$errors): int
    {
        $value = isset($input[$field]) && is_numeric($input[$field]) ? (int) $input[$field] : $default;
        if ($value < $min || $value > $max) $errors[$field] = "Use um valor entre {$min} e {$max}.";
        return $value;
    }

    private function isSafeEndpointTemplate(string $path): bool
    {
        return $path !== '' && strlen($path) <= 300 && str_starts_with($path, '/')
            && str_contains($path, '{instance}') && !str_contains($path, '://')
            && !preg_match('/[\r\n?#]/', $path);
    }

    private function isSafeBaseUrl(string $url): bool
    {
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private function internalFailure(Throwable $exception, string $message, int $status = 500): ResponseInterface
    {
        log_message('error', 'Chatwoot_plugin settings API failed ({exception_type}).', ['exception_type' => get_class($exception)]);
        return $this->error($message, $status);
    }
}
