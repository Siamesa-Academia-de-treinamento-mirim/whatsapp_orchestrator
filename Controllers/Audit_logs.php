<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Audit_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Audit_logs extends Api_controller
{
    public function index(): ResponseInterface
    {
        $this->requireViewAuditLogsPermission();
        try {
            $filters = [
                'actor_user_id' => $this->request->getGet('actor_user_id'),
                'action' => $this->request->getGet('action'),
                'resource_type' => $this->request->getGet('resource_type'),
                'resource_id' => $this->request->getGet('resource_id'),
                'instance_id' => $this->request->getGet('instance_id'),
            ];
            $filters = array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
            $result = (new Audit_service())->list($filters, max(1, (int) ($this->request->getGet('page') ?: 1)), (int) ($this->request->getGet('limit') ?: 50));
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            return $this->error('Nao foi possivel carregar a auditoria.', 500);
        }
    }
}
