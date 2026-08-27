<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Media_service;
use Chatwoot_plugin\Services\Media_engine_exception;
use Chatwoot_plugin\Services\Message_send_exception;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

class Media extends Api_controller
{
    private Media_service $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new Media_service();
    }

    public function send(int $conversationId): ResponseInterface
    {
        $this->requireSendPermission();
        $file = $this->request->getFile('file');
        if (!$file) {
            return $this->error('Envie o anexo no campo file.', 422);
        }
        try {
            $result = $this->service->send(
                $conversationId,
                $file,
                (string) $this->request->getPost('caption'),
                (string) $this->request->getPost('client_message_id'),
                $this->actorId(),
                (string) $this->request->getPost('kind') ?: null,
                filter_var($this->request->getPost('voice_note'), FILTER_VALIDATE_BOOLEAN),
                filter_var($this->request->getPost('recording'), FILTER_VALIDATE_BOOLEAN),
                '',
                $this->replyToMessageId($this->request->getPost('reply_to_message_id'))
            );
            return $this->success($result, [], ($result['status'] ?? 'sent') === 'failed' ? 207 : 201);
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = in_array($code, [404, 409, 422, 502], true) ? $code : 422;
            $details = $exception instanceof Media_engine_exception ? $exception->details()
                : ($exception instanceof Message_send_exception ? $exception->details() : []);
            return $this->error($exception->getMessage(), $status, $details);
        }
    }

    public function send_batch(int $conversationId): ResponseInterface
    {
        $this->requireSendPermission();
        $files = [];
        if (method_exists($this->request, 'getFileMultiple')) {
            $files = $this->request->getFileMultiple('files') ?: [];
        }
        if ($files === []) {
            $allFiles = $this->request->getFiles();
            $candidate = is_array($allFiles['files'] ?? null) ? $allFiles['files'] : [];
            $files = $candidate !== [] ? $candidate : (($this->request->getFile('file') !== null) ? [$this->request->getFile('file')] : []);
        }
        if ($files === []) {
            return $this->error('Envie os anexos no campo files[].', 422);
        }

        $items = $this->request->getPost('items');
        if (is_string($items) && trim($items) !== '') {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($items)) {
            $items = [];
        }
        $clientIds = $this->request->getPost('client_message_ids');
        $captions = $this->request->getPost('captions');
        $kinds = $this->request->getPost('kinds');
        $voices = $this->request->getPost('voice_notes');
        $recordings = $this->request->getPost('recordings');
        foreach (array_values($files) as $index => $_file) {
            $entry = is_array($items[$index] ?? null) ? $items[$index] : [];
            if (is_array($clientIds)) $entry['client_message_id'] = $clientIds[$index] ?? '';
            if (is_array($captions)) $entry['caption'] = $captions[$index] ?? '';
            if (is_array($kinds)) $entry['kind'] = $kinds[$index] ?? null;
            if (is_array($voices)) $entry['voice_note'] = !empty($voices[$index]);
            if (is_array($recordings)) $entry['recording'] = !empty($recordings[$index]);
            $items[$index] = $entry;
        }

        try {
            $data = $this->service->sendBatch($conversationId, array_values($files), $items, $this->actorId(), (string) $this->request->getPost('batch_id'), $this->replyToMessageId($this->request->getPost('reply_to_message_id')));
            $status = !empty($data['has_failures']) ? 207 : 201;
            return $this->success($data, [], $status);
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = in_array($code, [404, 409, 422, 502], true) ? $code : 422;
            $details = $exception instanceof Media_engine_exception ? $exception->details()
                : ($exception instanceof Message_send_exception ? $exception->details() : []);
            return $this->error($exception->getMessage(), $status, $details);
        }
    }

    public function upload(): ResponseInterface
    {
        $this->requireManageCampaignsPermission();
        $file = $this->request->getFile('file');
        if (!$file) return $this->error('Envie a midia no campo file.', 422);
        try { return $this->success($this->service->upload($file, $this->actorId(), (int) $this->request->getPost('instance_id') ?: null), [], 201); }
        catch (Throwable $exception) { return $this->error($exception->getMessage(), 422); }
    }

    public function show(int $id): ResponseInterface
    {
        return $this->stream(fn (): array => $this->service->content($id));
    }

    public function message(int $id): ResponseInterface
    {
        return $this->stream(fn (): array => $this->service->messageContent($id));
    }

    private function stream(callable $loader): ResponseInterface
    {
        try {
            $content = $loader();
            return $this->response->setHeader('Content-Type', $content['mime'])->setHeader('Content-Disposition', 'inline; filename="' . addcslashes($content['name'], '"\\') . '"')->setHeader('Cache-Control', 'private, max-age=300')->setBody($content['body']);
        } catch (Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [404, 422, 502], true) ? (int) $exception->getCode() : 500;
            $details = $exception instanceof Media_engine_exception ? $exception->details()
                : ($exception instanceof Message_send_exception ? $exception->details() : []);
            return $this->error($exception->getMessage(), $status, $details);
        }
    }

    private function replyToMessageId($value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Identificador local da mensagem de resposta invalido.', 422);
        }
        return (int) $value;
    }
}
