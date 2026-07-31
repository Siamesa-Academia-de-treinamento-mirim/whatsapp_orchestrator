<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Services\Ai_service;
use CodeIgniter\HTTP\ResponseInterface;
class Ai_logs extends Api_controller
{
    private Ai_service $service;public function __construct(){parent::__construct();$this->service=new Ai_service();}
    public function index():ResponseInterface{$this->requireManageAiPermission();$filters=[];foreach(['conversation_id','instance_id','agent_id']as$f){$v=(int)$this->request->getGet($f);if($v>0)$filters[$f]=$v;}$status=trim((string)$this->request->getGet('status'));if($status!=='')$filters['status']=$status;$correlation=trim((string)$this->request->getGet('correlation_id'));if($correlation!=='')$filters['correlation_id']=mb_substr($correlation,0,191);$r=$this->service->logs($filters,max(1,(int)$this->request->getGet('page')),min(100,max(1,(int)($this->request->getGet('limit')?:30))));return$this->success($r['data'],['meta'=>$r['meta']]);}
}
