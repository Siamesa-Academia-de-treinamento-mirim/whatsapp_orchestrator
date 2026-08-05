<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Contact_repair_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Contact_repairs extends Api_controller
{
    public function preview(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        try { return $this->success((new Contact_repair_service())->preview((string)($this->request->getGet('suspect_name') ?: 'Tiago'), (int)($this->request->getGet('limit') ?: 500))); }
        catch (Throwable $e) { return $this->error($e->getMessage(), 422); }
    }
    public function apply(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        $input=$this->input();
        try { return $this->success((new Contact_repair_service())->apply((string)($input['suspect_name']??'Tiago'), is_array($input['contact_ids']??null)?$input['contact_ids']:[], $this->actorId())); }
        catch (Throwable $e) { return $this->error($e->getMessage(), 422); }
    }
}
