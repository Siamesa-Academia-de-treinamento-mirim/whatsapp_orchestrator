<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Services\Report_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Reports extends Api_controller
{
    private Report_service $service;public function __construct(){parent::__construct();$this->service=new Report_service();}
    public function index():ResponseInterface{$this->requireViewReportsPermission();try{return$this->success($this->service->generate($this->filters()));}catch(Throwable $e){return$this->error($e->getMessage(),422);}}
    public function export():ResponseInterface{$this->requireExportReportsPermission();try{$csv=$this->service->csv($this->filters(),$this->actorId());return$this->response->setHeader('Content-Type','text/csv; charset=UTF-8')->setHeader('Content-Disposition','attachment; filename="impulso-relatorio-'.gmdate('Ymd-His').'.csv"')->setBody($csv);}catch(Throwable $e){return$this->error($e->getMessage(),422);}}
    private function filters():array{return['period'=>(string)$this->request->getGet('period'),'instance_id'=>(string)$this->request->getGet('instance_id'),'from'=>(string)$this->request->getGet('from'),'to'=>(string)$this->request->getGet('to'),'timezone'=>(string)($this->request->getGet('timezone')?:'America/Sao_Paulo')];}
}
