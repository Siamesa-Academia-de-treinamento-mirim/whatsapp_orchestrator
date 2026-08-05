<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Campaign_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Campaigns extends Api_controller
{
    private Campaign_service $service;

    public function __construct() { parent::__construct(); $this->service = new Campaign_service(); }

    public function index(): ResponseInterface
    {
        $this->requireManageCampaignsPermission();
        $result = $this->service->list(['instance_id' => (int) $this->request->getGet('instance_id') ?: null, 'status' => trim((string) $this->request->getGet('status')) ?: null], max(1, (int) $this->request->getGet('page')), min(100, max(1, (int) ($this->request->getGet('limit') ?: 30))));
        return $this->success($result['data'], ['meta' => $result['meta']]);
    }

    public function show(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); $row = $this->service->get($id); return $row ? $this->success($row) : $this->error('Campanha nao encontrada.', 404); }
    public function create(): ResponseInterface { $this->requireManageCampaignsPermission(); return $this->save(null); }
    public function update(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); return $this->save($id); }
    public function delete(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); try { $this->service->delete($id, $this->actorId()); return $this->success(['id' => $id, 'deleted' => true]); } catch (Throwable $e) { return $this->failure($e); } }
    public function duplicate(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); try { return $this->success($this->service->duplicate($id, $this->actorId()), [], 201); } catch (Throwable $e) { return $this->failure($e); } }
    public function toggle(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); try { return $this->success($this->service->toggle($id, $this->actorId())); } catch (Throwable $e) { return $this->failure($e); } }
    public function runs(int $id): ResponseInterface
    {
        $this->requireManageCampaignsPermission();
        try {
            $result = $this->service->runs($id, max(1, (int) $this->request->getGet('page')), min(100, max(1, (int) ($this->request->getGet('limit') ?: 20))));
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function run_recipients(int $id, int $runId): ResponseInterface
    {
        $this->requireManageCampaignsPermission();
        try {
            $result = $this->service->run_recipients($id, $runId, [
                'status' => $this->request->getGet('status'),
                'search' => $this->request->getGet('search'),
            ], max(1, (int) $this->request->getGet('page')), min(200, max(1, (int) ($this->request->getGet('limit') ?: 50))));
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function audience_preview(): ResponseInterface { $this->requireManageCampaignsPermission(); try { $data = $this->service->audience_preview($this->input()); unset($data['recipients']); return $this->success($data); } catch (Throwable $e) { return $this->failure($e); } }
    public function health(): ResponseInterface { $this->requireManageCampaignsPermission(); return $this->success($this->service->health()); }

    private function save(?int $id): ResponseInterface { try { return $this->success($this->service->save($this->input(), $this->actorId(), $id), [], $id ? 200 : 201); } catch (Throwable $e) { return $this->failure($e); } }
    private function failure(Throwable $e): ResponseInterface { $code = (int) $e->getCode(); $status = in_array($code, [404, 409, 422, 502, 503], true) ? $code : 422; log_message('error', 'Chatwoot_plugin campaigns API failed ({exception_type}).', ['exception_type' => get_class($e)]); return $this->error($e->getMessage(), $status); }
}
