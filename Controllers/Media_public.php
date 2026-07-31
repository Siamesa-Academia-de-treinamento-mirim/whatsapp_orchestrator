<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use CodeIgniter\Controller;
use Chatwoot_plugin\Services\Media_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Media_public extends Controller
{
    public function show(int $id): ResponseInterface
    {
        $expires=(int)$this->request->getGet('expires');$signature=strtolower(trim((string)$this->request->getGet('signature')));$service=new Media_service();
        if(!$service->verifySignature($id,$expires,$signature))return$this->response->setStatusCode(403)->setJSON(['success'=>false,'message'=>'Assinatura de midia invalida ou expirada.']);
        try{$content=$service->content($id);return$this->response->setHeader('Content-Type',$content['mime'])->setHeader('Content-Disposition','inline; filename="'.addcslashes($content['name'],'"\\').'"')->setHeader('Cache-Control','private, max-age=60')->setBody($content['body']);}
        catch(Throwable $e){return$this->response->setStatusCode((int)$e->getCode()===404?404:500)->setJSON(['success'=>false,'message'=>'Midia indisponivel.']);}
    }
}
