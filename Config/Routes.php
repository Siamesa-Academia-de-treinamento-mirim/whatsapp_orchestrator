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
    $routes->post('api/instances/sync-evolution', 'Instances::sync_evolution');
    $routes->get('api/instances/(:num)', 'Instances::show/$1');
    $routes->post('api/instances/(:num)', 'Instances::update/$1');
    $routes->delete('api/instances/(:num)', 'Instances::delete/$1');
    $routes->post('api/instances/(:num)/status', 'Instances::status/$1');
    $routes->get('api/instances/(:num)/evolution/connect', 'Instances::connect/$1');
    $routes->post('api/instances/(:num)/evolution/restart', 'Instances::restart/$1');
    $routes->post('api/instances/(:num)/evolution/logout', 'Instances::logout/$1');
    $routes->delete('api/instances/(:num)/evolution', 'Instances::delete_evolution/$1');

    $routes->get('api/conversations', 'Conversations::index');
    $routes->get('api/conversations/assignment-options', 'Conversations::assignment_options');
    $routes->post('api/conversations/bulk-action', 'Conversations::bulk_action');
    $routes->post('api/conversations', 'Conversations::create');
    $routes->post('api/conversations/sync', 'Conversations::sync');
    $routes->get('api/conversations/(:num)', 'Conversations::show/$1');
    $routes->get('api/conversations/(:num)/messages', 'Conversations::messages/$1');
    $routes->get('api/conversations/(:num)/group', 'Conversations::group/$1');
    $routes->post('api/conversations/(:num)/messages/sync', 'Conversations::sync_messages/$1');
    $routes->post('api/conversations/(:num)/messages', 'Conversations::send/$1');
    $routes->post('api/conversations/(:num)/messages/(:num)/reaction', 'Conversations::reaction/$1/$2');
    $routes->get('api/conversations/(:num)/templates', 'Conversations::templates/$1');
    $routes->post('api/conversations/(:num)/templates/sync', 'Conversations::sync_templates/$1');
    $routes->post('api/conversations/(:num)/templates', 'Conversations::send_template/$1');
    $routes->post('api/conversations/(:num)/templates/media', 'Conversations::template_media/$1');
    $routes->post('api/conversations/(:num)/read', 'Conversations::mark_read/$1');
    $routes->post('api/conversations/(:num)/unread', 'Conversations::mark_unread/$1');
    $routes->post('api/conversations/(:num)/status', 'Conversations::status/$1');
    $routes->post('api/conversations/(:num)/snooze', 'Conversations::snooze/$1');
    $routes->post('api/conversations/(:num)/unsnooze', 'Conversations::unsnooze/$1');
    $routes->get('api/conversations/(:num)/previous', 'Conversations::previous/$1');
    $routes->get('api/conversations/(:num)/activity', 'Conversations::activity/$1');
    $routes->post('api/conversations/(:num)/presence', 'Conversations::presence/$1');
    $routes->get('api/conversations/(:num)/presence', 'Conversations::presence_show/$1');
    $routes->post('api/conversations/(:num)/attachments/batch', 'Media::send_batch/$1');
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
    $routes->get('api/contact-repairs/preview', 'Contact_repairs::preview');
    $routes->post('api/contact-repairs/apply', 'Contact_repairs::apply');

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
    $routes->get('api/campaigns/(:num)/runs', 'Campaigns::runs/$1');
    $routes->get('api/campaigns/(:num)/runs/(:num)/recipients', 'Campaigns::run_recipients/$1/$2');
    $routes->get('api/campaign-templates', 'Campaign_templates::index');
    $routes->post('api/campaign-templates', 'Campaign_templates::create');
    $routes->put('api/campaign-templates/(:num)', 'Campaign_templates::update/$1');
    $routes->delete('api/campaign-templates/(:num)', 'Campaign_templates::delete/$1');
    $routes->get('api/instances/(:num)/official-templates', 'Official_templates::index/$1');
    $routes->post('api/instances/(:num)/official-templates/sync', 'Official_templates::sync/$1');

    $routes->get('api/bots', 'Bots::index');
    $routes->post('api/bots', 'Bots::create');
    $routes->post('api/bots/simulate', 'Bots::simulate');
    $routes->get('api/bots/(:num)', 'Bots::show/$1');
    $routes->put('api/bots/(:num)', 'Bots::update/$1');
    $routes->delete('api/bots/(:num)', 'Bots::delete/$1');
    $routes->post('api/bots/(:num)/publish', 'Bots::publish/$1');
    $routes->post('api/bots/(:num)/toggle', 'Bots::toggle/$1');
    $routes->post('api/conversations/(:num)/bot/pause', 'Bots::pause_conversation/$1');
    $routes->post('api/conversations/(:num)/bot/resume', 'Bots::resume_conversation/$1');

    $routes->get('api/notifications', 'Notifications::index');
    $routes->post('api/notifications/read-all', 'Notifications::read_all');
    $routes->post('api/notifications/(:num)/read', 'Notifications::read/$1');
    $routes->get('api/saved-views', 'Saved_views::index');
    $routes->post('api/saved-views', 'Saved_views::create');
    $routes->put('api/saved-views/(:num)', 'Saved_views::update/$1');
    $routes->delete('api/saved-views/(:num)', 'Saved_views::delete/$1');
    $routes->get('api/search', 'Search::index');

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

$routes->get('chatwoot_plugin/webhooks/meta/(:segment)', 'Meta_webhooks::verify/$1', ['namespace' => 'Chatwoot_plugin\Controllers']);
$routes->post('chatwoot_plugin/webhooks/meta/(:segment)', 'Meta_webhooks::receive/$1', ['namespace' => 'Chatwoot_plugin\Controllers']);
