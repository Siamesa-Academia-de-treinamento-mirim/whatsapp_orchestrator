<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Quick_reply_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Quick_replies extends Api_controller
{
    private Quick_reply_service $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new Quick_reply_service();
    }

    public function index(): ResponseInterface
    {
        return $this->success($this->service->list($this->request->getGet('all') !== '1'));
    }

    public function create(): ResponseInterface
    {
        $this->requireManageSettingsPermission();
        return $this->save(null);
    }

    public function update(int $id): ResponseInterface
    {
        $this->requireManageSettingsPermission();
        return $this->save($id);
    }

    public function delete(int $id): ResponseInterface
    {
        $this->requireManageSettingsPermission();
        try {
            $this->service->delete($id, $this->actorId());
            return $this->success(['id' => $id, 'deleted' => true]);
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), (int) $exception->getCode() === 404 ? 404 : 422);
        }
    }

    private function save(?int $id): ResponseInterface
    {
        try {
            return $this->success($this->service->save($this->input(), $this->actorId(), $id), [], $id ? 200 : 201);
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), (int) $exception->getCode() === 404 ? 404 : 422);
        }
    }
}
