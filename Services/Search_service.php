<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Services;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
class Search_service
{
    private BaseConnection $db;public function __construct(?BaseConnection $db=null){$this->db=$db??db_connect('default');}
    public function search(string $query,array $types,int $limit=20,?int $conversationId=null):array
    {
        $query=trim($query);if(mb_strlen($query)<2||mb_strlen($query)>191)throw new InvalidArgumentException('Digite entre 2 e 191 caracteres para buscar.');$limit=min(50,max(1,$limit));$items=[];
        if($conversationId){$rows=$this->db->table('chat_messages')->select('id, conversation_id, message_type, text_content, sent_at, direction')->where('conversation_id',$conversationId)->where('message_type !=','reaction')->where('deleted',0)->like('text_content',$query)->orderBy('message_timestamp','DESC')->limit($limit)->get()->getResultArray();foreach($rows as$r)$items[]=['type'=>'message','id'=>(int)$r['id'],'conversation_id'=>(int)$r['conversation_id'],'title'=>mb_substr((string)$r['text_content'],0,120),'subtitle'=>(string)$r['direction'].' · '.(string)$r['sent_at']];return['items'=>$items,'total'=>count($items)];}
        if(in_array('conversation',$types,true)){$rows=$this->db->table('chat_conversations')->select('id, contact_name, phone_number, last_message_preview')->where('deleted',0)->groupStart()->like('contact_name',$query)->orLike('phone_number',preg_replace('/\D+/','',$query)?:$query)->orLike('last_message_preview',$query)->groupEnd()->orderBy('last_message_at','DESC')->limit($limit)->get()->getResultArray();foreach($rows as$r)$items[]=['type'=>'conversation','id'=>(int)$r['id'],'title'=>(string)($r['contact_name']?:$r['phone_number']),'subtitle'=>(string)$r['last_message_preview'],'tab'=>'conversations'];}
        if(in_array('contact',$types,true)){$rows=$this->db->table('chat_contacts')->select('id,name,phone_normalized,email,company')->where('deleted',0)->groupStart()->like('name',$query)->orLike('phone_normalized',preg_replace('/\D+/','',$query)?:$query)->orLike('email',$query)->orLike('company',$query)->groupEnd()->orderBy('last_activity_at','DESC')->limit($limit)->get()->getResultArray();foreach($rows as$r)$items[]=['type'=>'contact','id'=>(int)$r['id'],'title'=>(string)$r['name'],'subtitle'=>(string)$r['phone_normalized'].($r['company']?' · '.$r['company']:''),'tab'=>'contacts'];}
        if(in_array('campaign',$types,true)){$rows=$this->db->table('chat_campaigns')->select('id,name,status,description')->where('deleted',0)->groupStart()->like('name',$query)->orLike('description',$query)->groupEnd()->orderBy('updated_at','DESC')->limit($limit)->get()->getResultArray();foreach($rows as$r)$items[]=['type'=>'campaign','id'=>(int)$r['id'],'title'=>(string)$r['name'],'subtitle'=>(string)$r['status'],'tab'=>'campaigns'];}
        return['items'=>array_slice($items,0,$limit),'total'=>count($items)];
    }
}
