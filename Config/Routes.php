<?php

if (defined('CHATWOOT_PLUGIN_ROUTES_LOADED')) {
    return;
}

define('CHATWOOT_PLUGIN_ROUTES_LOADED', true);

if (!isset($routes)) {
    $routes = \Config\Services::routes(true);
}

$route_options = [
    'namespace' => 'Chatwoot_plugin\Controllers',
    'filter' => 'csrf',
];

$routes->group('chatwoot_plugin', $route_options, static function ($routes): void {
    $routes->get('/', 'Chatwoot::index');
    $routes->get('index', 'Chatwoot::index');

    $routes->get('api/instances', 'Instances::index');
    $routes->post('api/instances', 'Instances::create');
    $routes->post('api/instances/refresh-status', 'Instances::refresh_all');
    $routes->get('api/instances/(:num)', 'Instances::show/$1');
    $routes->post('api/instances/(:num)', 'Instances::update/$1');
    $routes->delete('api/instances/(:num)', 'Instances::delete/$1');
    $routes->post('api/instances/(:num)/status', 'Instances::status/$1');

    $routes->get('api/conversations', 'Conversations::index');
    $routes->post('api/conversations', 'Conversations::create');
    $routes->post('api/conversations/sync', 'Conversations::sync');
    $routes->get('api/conversations/(:num)/messages', 'Conversations::messages/$1');
    $routes->post('api/conversations/(:num)/messages/sync', 'Conversations::sync_messages/$1');
    $routes->post('api/conversations/(:num)/messages', 'Conversations::send/$1');
    $routes->post('api/conversations/(:num)/read', 'Conversations::mark_read/$1');
    $routes->post('api/conversations/(:num)/attachments', 'Media::send/$1');
    $routes->post('api/conversations/(:num)/notes', 'Conversations::note/$1');
    $routes->post('api/conversations/(:num)/priority', 'Conversations::priority/$1');
    $routes->post('api/conversations/(:num)/resolve', 'Conversations::resolve/$1');
    $routes->post('api/conversations/(:num)/reopen', 'Conversations::reopen/$1');
    $routes->post('api/conversations/(:num)/tags', 'Conversations::tags/$1');
    $routes->post('api/conversations/(:num)/assignment', 'Conversations::assignment/$1');

    $routes->get('api/media/message/(:num)', 'Media::message/$1');
    $routes->post('api/media', 'Media::upload');
    $routes->get('api/media/(:num)', 'Media::show/$1');

    $routes->get('api/contacts/export', 'Contacts::export');
    $routes->post('api/contacts/import', 'Contacts::import');
    $routes->post('api/contacts/bulk-tags', 'Contacts::bulk_tags');
    $routes->get('api/contacts', 'Contacts::index');
    $routes->post('api/contacts', 'Contacts::create');
    $routes->get('api/contacts/(:num)', 'Contacts::show/$1');
    $routes->put('api/contacts/(:num)', 'Contacts::update/$1');
    $routes->delete('api/contacts/(:num)', 'Contacts::delete/$1');
    $routes->post('api/contacts/(:num)/opt-out', 'Contacts::opt_out/$1');

    $routes->get('api/quick-replies', 'Quick_replies::index');
    $routes->post('api/quick-replies', 'Quick_replies::create');
    $routes->put('api/quick-replies/(:num)', 'Quick_replies::update/$1');
    $routes->delete('api/quick-replies/(:num)', 'Quick_replies::delete/$1');

    $routes->post('api/campaigns/audience-preview', 'Campaigns::audience_preview');
    $routes->get('api/campaigns/health', 'Campaigns::health');
    $routes->get('api/campaigns', 'Campaigns::index');
    $routes->post('api/campaigns', 'Campaigns::create');
    $routes->get('api/campaigns/(:num)', 'Campaigns::show/$1');
    $routes->put('api/campaigns/(:num)', 'Campaigns::update/$1');
    $routes->delete('api/campaigns/(:num)', 'Campaigns::delete/$1');
    $routes->post('api/campaigns/(:num)/duplicate', 'Campaigns::duplicate/$1');
    $routes->post('api/campaigns/(:num)/toggle', 'Campaigns::toggle/$1');
    $routes->get('api/campaign-templates', 'Campaign_templates::index');
    $routes->post('api/campaign-templates', 'Campaign_templates::create');
    $routes->put('api/campaign-templates/(:num)', 'Campaign_templates::update/$1');
    $routes->delete('api/campaign-templates/(:num)', 'Campaign_templates::delete/$1');

    $routes->get('api/ai/agents', 'Ai_agents::index');
    $routes->post('api/ai/agents', 'Ai_agents::create');
    $routes->get('api/ai/agents/(:num)', 'Ai_agents::show/$1');
    $routes->put('api/ai/agents/(:num)', 'Ai_agents::update/$1');
    $routes->delete('api/ai/agents/(:num)', 'Ai_agents::delete/$1');
    $routes->post('api/ai/agents/(:num)/toggle', 'Ai_agents::toggle/$1');
    $routes->get('api/ai/state/health', 'Ai_state::health');
    $routes->get('api/ai/state/(:num)', 'Ai_state::show/$1');
    $routes->post('api/ai/state/(:num)', 'Ai_state::update/$1');
    $routes->post('api/ai/state/(:num)/instance', 'Ai_state::instance/$1');
    $routes->get('api/ai/logs', 'Ai_logs::index');

    $routes->get('api/automations', 'Automations::index');
    $routes->post('api/automations', 'Automations::create');
    $routes->get('api/automations/(:num)', 'Automations::show/$1');
    $routes->put('api/automations/(:num)', 'Automations::update/$1');
    $routes->delete('api/automations/(:num)', 'Automations::delete/$1');
    $routes->post('api/automations/(:num)/toggle', 'Automations::toggle/$1');
    $routes->post('api/automations/(:num)/test', 'Automations::test/$1');

    $routes->post('api/integrations/n8n/test', 'Integrations::n8n_test');
    $routes->get('api/reports/export', 'Reports::export');
    $routes->get('api/reports', 'Reports::index');
    $routes->get('api/notifications', 'Notifications::index');
    $routes->post('api/notifications/read-all', 'Notifications::read_all');
    $routes->post('api/notifications/(:num)/read', 'Notifications::read/$1');
    $routes->get('api/search', 'Search::index');
    $routes->get('api/audit-logs', 'Audit_logs::index');

    $routes->get('api/session/csrf', 'Api_session::csrf');

    $routes->get('api/settings', 'Settings::show');
    $routes->post('api/settings', 'Settings::update');
    $routes->post('api/settings/test', 'Settings::test');
});

// Evolution authenticates this endpoint with the plugin-owned webhook secret.
// It must stay outside the authenticated CSRF route group.
$routes->post('chatwoot_plugin/webhooks/evolution', 'Webhooks::evolution', [
    'namespace' => 'Chatwoot_plugin\Controllers',
]);
$routes->get('chatwoot_plugin/media/(:num)', 'Media_public::show/$1', [
    'namespace' => 'Chatwoot_plugin\Controllers',
]);
