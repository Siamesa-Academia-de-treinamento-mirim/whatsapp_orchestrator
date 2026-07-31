<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Services\Notification_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Notifications extends Api_controller
{
    private Notification_service $service;public function __construct(){parent::__construct();$this->service=new Notification_service();}
    public function index():ResponseInterface{$rows=$this->service->list($this->actorId(),min(100,max(1,(int)($this->request->getGet('limit')?:30))),$this->request->getGet('unread')==='1');return$this->success($rows,['unread_count'=>$this->service->unread_count($this->actorId())]);}
    public function read(int $id):ResponseInterface{try{return$this->success($this->service->read($id,$this->actorId()));}catch(Throwable $e){return$this->error($e->getMessage(),(int)$e->getCode()===404?404:422);}}
    public function read_all():ResponseInterface{return$this->success(['updated'=>$this->service->read_all($this->actorId()),'unread_count'=>0]);}
}
