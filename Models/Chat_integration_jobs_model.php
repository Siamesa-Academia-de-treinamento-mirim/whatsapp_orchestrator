<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_integration_jobs_model extends Chat_domain_model { protected string $logicalTable = 'chat_integration_jobs'; protected array $writableFields = ['job_type','status','payload_json','attempts','max_attempts','available_at','locked_at','locked_by','last_error','correlation_id']; protected array $filterableFields = ['job_type','status','locked_by','correlation_id']; }
