<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Chat_service;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use JsonException;
use Throwable;

class Webhooks extends Controller
{
    private const MAX_BODY_BYTES = 2097152;
    private const RATE_LIMIT_PER_MINUTE = 120;

    public function evolution(): ResponseInterface
    {
        if ($this->isRateLimited()) {
            return $this->response
                ->setHeader('Retry-After', '60')
                ->setStatusCode(429)
                ->setJSON([
                    'success' => false,
                    'message' => 'Limite temporario de requisicoes excedido.',
                ]);
        }

        $rawBody = (string) $this->request->getBody();
        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->json(false, 'Payload vazio ou acima do limite permitido.', 413);
        }

        try {
            $chat = new Chat_service();
            $secret = $chat->webhook_secret();
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin webhook initialization failed ({exception_type}).', [
                'exception_type' => get_class($exception),
            ]);

            return $this->json(false, 'Webhook temporariamente indisponivel.', 503);
        }

        if ($secret === '' || !$this->isAuthorized($secret, $rawBody)) {
            return $this->json(false, 'Credencial do webhook invalida.', 401);
        }

        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->json(false, 'JSON invalido.', 400);
        }
        if (!is_array($payload) || $payload === []) {
            return $this->json(false, 'Payload JSON invalido.', 400);
        }

        $events = $this->expandEvents($payload);
        if (!$events) {
            return $this->json(false, 'Nenhum evento valido foi encontrado.', 400);
        }

        $results = [];
        foreach ($events as $event) {
            try {
                $results[] = $chat->process_webhook_event($event);
            } catch (Throwable $exception) {
                log_message('error', 'Chatwoot_plugin webhook event failed ({exception_type}).', [
                    'exception_type' => get_class($exception),
                ]);
                $results[] = [
                    'processed' => false,
                    'duplicate' => false,
                    'pending' => true,
                    'retryable' => true,
                    'error' => 'Evento aceito, mas nao processado.',
                ];
            }
        }

        $hasRetryableFailure = false;
        $hasPermanentFailure = false;
        foreach ($results as $result) {
            if (!empty($result['processed']) || !empty($result['duplicate'])) {
                continue;
            }
            if (!empty($result['retryable']) || !empty($result['pending'])) {
                $hasRetryableFailure = true;
            } else {
                $hasPermanentFailure = true;
            }
        }
        $status = $hasRetryableFailure ? 503 : ($hasPermanentFailure ? 422 : 200);
        if ($status === 503) {
            $this->response->setHeader('Retry-After', '5');
        }

        return $this->response->setStatusCode($status)->setJSON([
            'success' => $status === 200,
            'accepted' => count($events),
            'retryable' => $hasRetryableFailure,
            'results' => $results,
        ]);
    }

    private function isRateLimited(): bool
    {
        try {
            $ip = trim((string) $this->request->getIPAddress()) ?: 'unknown';
            $key = 'impulso_webhook_rate_' . hash('sha256', $ip . '|' . gmdate('YmdHi'));
            $cache = service('cache');
            $count = (int) ($cache->get($key) ?? 0) + 1;
            $cache->save($key, $count, 70);

            return $count > self::RATE_LIMIT_PER_MINUTE;
        } catch (Throwable $exception) {
            // Cache outages must not make the provider webhook unavailable.
            return false;
        }
    }

    private function isAuthorized(string $secret, string $rawBody): bool
    {
        $direct = trim($this->request->getHeaderLine('X-Chatwoot-Webhook-Secret'));
        if ($direct !== '' && hash_equals($secret, $direct)) {
            return true;
        }

        $authorization = trim($this->request->getHeaderLine('Authorization'));
        if ($authorization === '') {
            $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? ''));
        }
        if ($authorization === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
            && hash_equals($secret, trim((string) $matches[1]))) {
            return true;
        }

        $providedSignature = trim($this->request->getHeaderLine('X-Chatwoot-Webhook-Signature'));
        if (str_starts_with(strtolower($providedSignature), 'sha256=')) {
            $providedSignature = substr($providedSignature, 7);
        }
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        return $providedSignature !== ''
            && strlen($providedSignature) === strlen($expectedSignature)
            && ctype_xdigit($providedSignature)
            && hash_equals($expectedSignature, strtolower($providedSignature));
    }

    /** @return array<int,array<string,mixed>> */
    private function expandEvents(array $payload): array
    {
        if ($this->isList($payload)) {
            return array_values(array_filter($payload, static fn ($item): bool => is_array($item) && $item !== []));
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && $this->isList($data)) {
            $events = [];
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $event = $payload;
                $event['data'] = $item;
                $events[] = $event;
            }

            return $events;
        }

        return [$payload];
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function json(bool $success, string $message, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => $success,
            'message' => $message,
        ]);
    }
}
