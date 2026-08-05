<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Services\Chat_service;
use Chatwoot_plugin\Services\Meta_webhook_normalizer;
use Chatwoot_plugin\Services\Provider_manager;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use JsonException;
use Throwable;

class Meta_webhooks extends Controller
{
    private const MAX_BODY_BYTES = 2097152;

    public function verify(string $identifier): ResponseInterface
    {
        $instance = (new Chat_instances_model())->get_by_identifier($identifier);
        if (!$instance || ($instance['provider_type'] ?? '') !== 'meta_cloud' || empty($instance['active'])) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }
        $mode = trim((string) $this->request->getGet('hub_mode')) ?: trim((string) $this->request->getGet('hub.mode'));
        $token = trim((string) $this->request->getGet('hub_verify_token')) ?: trim((string) $this->request->getGet('hub.verify_token'));
        $challenge = (string) ($this->request->getGet('hub_challenge') ?? $this->request->getGet('hub.challenge') ?? '');
        $credentials = (new Chat_instances_model())->get_decrypted_meta_credentials((int) $instance['id']);
        $expected = (string) ($credentials['verify_token'] ?? '');
        if ($mode !== 'subscribe' || $expected === '' || !hash_equals($expected, $token) || $challenge === '') {
            return $this->response->setStatusCode(403)->setBody('Verification failed');
        }
        return $this->response->setStatusCode(200)->setContentType('text/plain')->setBody($challenge);
    }

    public function receive(string $identifier): ResponseInterface
    {
        $raw = (string) $this->request->getBody();
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->response->setStatusCode(413)->setJSON(['success' => false]);
        }
        $instances = new Chat_instances_model();
        $instance = $instances->get_by_identifier($identifier);
        if (!$instance || ($instance['provider_type'] ?? '') !== 'meta_cloud' || empty($instance['active'])) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false]);
        }
        try {
            $provider = (new Provider_manager($instances))->forInstance($instance);
            $signature = trim($this->request->getHeaderLine('X-Hub-Signature-256'));
            if (!$provider->verifySignature($raw, $signature)) {
                return $this->response->setStatusCode(401)->setJSON(['success' => false]);
            }
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new JsonException('Invalid JSON object.');
            $normalizer = new Meta_webhook_normalizer();
            $receivedPhoneId = $normalizer->phoneNumberId($payload);
            if ($receivedPhoneId === '' || !hash_equals((string) ($instance['meta_phone_number_id'] ?? ''), $receivedPhoneId)) {
                return $this->response->setStatusCode(403)->setJSON(['success' => false]);
            }
            $events = $provider->normalizeWebhook($payload, [
                'instance_identifier' => (string) $instance['internal_identifier'],
            ]);
            $chat = new Chat_service();
            $results = [];
            foreach ($events as $event) $results[] = $chat->process_webhook_event($event);
            $retry = false;
            foreach ($results as $result) {
                if (empty($result['processed']) && empty($result['duplicate'])) $retry = true;
            }
            return $this->response->setStatusCode($retry ? 503 : 200)->setJSON([
                'success' => !$retry,
                'accepted' => count($events),
            ]);
        } catch (JsonException $exception) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false]);
        } catch (Throwable $exception) {
            log_message('error', 'Meta webhook failed ({exception_type}).', ['exception_type' => get_class($exception)]);
            return $this->response->setStatusCode(503)->setJSON(['success' => false]);
        }
    }
}
