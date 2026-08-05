<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Official_template_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Official_templates extends Api_controller
{
    public function index(int $instanceId): ResponseInterface
    {
        $this->requireManageCampaignsPermission();
        try { return $this->success((new Official_template_service())->list($instanceId)); }
        catch (Throwable $e) { return $this->internalFailure($e, 'Nao foi possivel carregar os templates oficiais.'); }
    }

    public function sync(int $instanceId): ResponseInterface
    {
        $this->requireManageCampaignsPermission();
        try { return $this->success((new Official_template_service())->sync($instanceId)); }
        catch (Throwable $e) { return $this->internalFailure($e, 'Nao foi possivel sincronizar os templates oficiais.', 502); }
    }
}
