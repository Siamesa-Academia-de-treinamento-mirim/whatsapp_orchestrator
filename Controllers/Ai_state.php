<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Services\Ai_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Ai_state extends Api_controller
{
    private Ai_service $service;public function __construct(){parent::__construct();$this->service=new Ai_service();}
    public function show(int $conversationId):ResponseInterface{$this->requireManageAiPermission();try{return$this->success($this->service->state($conversationId));}catch(Throwable $e){return$this->failure($e);}}
    public function update(int $conversationId):ResponseInterface{$this->requireManageAiPermission();try{return$this->success($this->service->set_state($conversationId,$this->input(),$this->actorId()));}catch(Throwable $e){return$this->failure($e);}}
    public function instance(int $instanceId):ResponseInterface{$this->requireManageAiPermission();try{return$this->success($this->service->set_instance_state($instanceId,$this->input(),$this->actorId()));}catch(Throwable $e){return$this->failure($e);}}
    public function health():ResponseInterface{$this->requireManageAiPermission();return$this->success($this->service->health());}
    private function failure(Throwable $e):ResponseInterface{$c=(int)$e->getCode();return$this->error($e->getMessage(),in_array($c,[404,409,422,502,503],true)?$c:422);}
}
