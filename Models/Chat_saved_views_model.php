<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_saved_views_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_saved_views';
    protected array $writableFields = [
        'user_id', 'name', 'schema_version', 'filters_json', 'sort_order',
    ];
    protected array $filterableFields = ['user_id', 'schema_version'];
}
