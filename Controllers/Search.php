<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Controllers;
use Chatwoot_plugin\Libraries\Chat_permissions;
use Chatwoot_plugin\Services\Search_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
class Search extends Api_controller
{
    public function index():ResponseInterface
    {
        $types=['conversation'];if(Chat_permissions::can($this->login_user,Chat_permissions::MANAGE_CONTACTS))$types[]='contact';if(Chat_permissions::can($this->login_user,Chat_permissions::MANAGE_CAMPAIGNS))$types[]='campaign';
        try{return$this->success((new Search_service())->search((string)$this->request->getGet('q'),$types,min(50,max(1,(int)($this->request->getGet('limit')?:20))),(int)$this->request->getGet('conversation_id')?:null));}catch(Throwable $e){return$this->error($e->getMessage(),422);}
    }
}
