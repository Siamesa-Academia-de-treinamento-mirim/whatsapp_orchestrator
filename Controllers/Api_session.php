<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Api_session extends Api_controller
{
    public function csrf(): ResponseInterface
    {
        return $this->success([
            'csrf_header' => csrf_header(),
            'csrf_token_name' => csrf_token(),
            'csrf_hash' => csrf_hash(),
        ]);
    }
}
