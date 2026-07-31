<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Libraries\N8n_client;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Integrations extends Api_controller
{
    public function n8n_test(): ResponseInterface
    {
        $this->requireManageSettingsPermission();
        try { $result=(new N8n_client())->health(); return $this->success($result, [], $result['connected']?200:503); }
        catch(Throwable $e){ return $this->error($e->getMessage(), 503); }
    }
}
