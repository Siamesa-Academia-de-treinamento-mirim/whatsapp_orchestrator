<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Services\Automation_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Automations extends Api_controller
{
    private Automation_service $service;public function __construct(){parent::__construct();$this->service=new Automation_service();}
    public function index():ResponseInterface{$this->requireManageAiPermission();$r=$this->service->list(['instance_id'=>(int)$this->request->getGet('instance_id')?:null,'active'=>$this->request->getGet('active')!==null?(filter_var($this->request->getGet('active'),FILTER_VALIDATE_BOOLEAN)?1:0):null,'trigger_event'=>trim((string)$this->request->getGet('trigger_event'))?:null],max(1,(int)$this->request->getGet('page')),min(100,max(1,(int)($this->request->getGet('limit')?:30))));return$this->success($r['data'],['meta'=>$r['meta']]);}
    public function show(int $id):ResponseInterface{$this->requireManageAiPermission();$r=$this->service->get($id);return$r?$this->success($r):$this->error('Automacao nao encontrada.',404);}
    public function create():ResponseInterface{$this->requireManageAiPermission();return$this->save(null);}
    public function update(int $id):ResponseInterface{$this->requireManageAiPermission();return$this->save($id);}
    public function toggle(int $id):ResponseInterface{$this->requireManageAiPermission();try{return$this->success($this->service->toggle($id,$this->actorId()));}catch(Throwable $e){return$this->failure($e);}}
    public function test(int $id):ResponseInterface{$this->requireManageAiPermission();try{return$this->success($this->service->test($id,$this->actorId()));}catch(Throwable $e){return$this->failure($e);}}
    public function delete(int $id):ResponseInterface{$this->requireManageAiPermission();try{$this->service->delete($id,$this->actorId());return$this->success(['id'=>$id,'deleted'=>true]);}catch(Throwable $e){return$this->failure($e);}}
    private function save(?int $id):ResponseInterface{try{return$this->success($this->service->save($this->input(),$this->actorId(),$id),[],$id?200:201);}catch(Throwable $e){return$this->failure($e);}}
    private function failure(Throwable $e):ResponseInterface{$c=(int)$e->getCode();return$this->error($e->getMessage(),in_array($c,[404,409,422,502,503],true)?$c:422);}
}
