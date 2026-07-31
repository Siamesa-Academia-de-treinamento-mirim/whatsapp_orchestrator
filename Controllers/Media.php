<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Media_service;
use CodeIgniter\HTTP\ResponseInterface;
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
            return $this->success($this->service->send($conversationId, $file, (string) $this->request->getPost('caption'), (string) $this->request->getPost('client_message_id'), $this->actorId()), [], 201);
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = in_array($code, [404, 409, 422, 502], true) ? $code : 422;
            return $this->error($exception->getMessage(), $status);
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
            return $this->error($exception->getMessage(), $status);
        }
    }
}
