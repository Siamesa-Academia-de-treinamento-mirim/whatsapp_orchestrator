<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Services\Campaign_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Campaign_templates extends Api_controller
{
    private Campaign_service $service;
    public function __construct(){ parent::__construct(); $this->service = new Campaign_service(); }
    public function index(): ResponseInterface { $this->requireManageCampaignsPermission(); return $this->success($this->service->list_templates()); }
    public function create(): ResponseInterface { $this->requireManageCampaignsPermission(); return $this->save(null); }
    public function update(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); return $this->save($id); }
    public function delete(int $id): ResponseInterface { $this->requireManageCampaignsPermission(); try { $this->service->delete_template($id); return $this->success(['id'=>$id,'deleted'=>true]); } catch(Throwable $e){ return $this->error($e->getMessage(), (int)$e->getCode()===404?404:422); } }
    private function save(?int $id): ResponseInterface { try { return $this->success($this->service->save_template($this->input(), $this->actorId(), $id), [], $id?200:201); } catch(Throwable $e){ return $this->error($e->getMessage(), (int)$e->getCode()===404?404:422); } }
}
