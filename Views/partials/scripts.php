<?php
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$script_config = is_array($app_config ?? null) ? $app_config : [];
$script_instances = is_array($instances ?? null) ? $instances : [];
$script_settings = is_array($settings_public ?? null) ? $settings_public : [];
$inbox_active = ($active_tab ?? '') === 'conversations';
$plugin_root = dirname(__DIR__, 2);
$plugin_folder = defined('CHATWOOT_PLUGIN_FOLDER') ? CHATWOOT_PLUGIN_FOLDER : basename($plugin_root);
$message_safe_script = $plugin_root . '/Assets/js/inbox/message_safe_content.js';
$message_renderers_script = $plugin_root . '/Assets/js/inbox/message_renderers.js';
$message_actions_script = $plugin_root . '/Assets/js/inbox/message_actions.js';
$chatwoot_script = $plugin_root . '/Assets/js/chatwoot.js';
$polling_scheduler_script = $plugin_root . '/Assets/js/inbox/polling_scheduler.js';
$workspace_script = $plugin_root . '/Assets/js/hub-workspace.js';
$media_policy_script = $plugin_root . '/Assets/js/inbox/media_policy.js';
$composer_state_script = $plugin_root . '/Assets/js/inbox/composer_state.js';
$composer_quick_replies_script = $plugin_root . '/Assets/js/inbox/composer_quick_replies.js';
$composer_clipboard_script = $plugin_root . '/Assets/js/inbox/composer_clipboard.js';
$composer_script = $plugin_root . '/Assets/js/inbox/composer.js';
$mentions_script = $plugin_root . '/Assets/js/inbox/mentions.js';
$presence_script = $plugin_root . '/Assets/js/inbox/presence.js';
$saved_views_script = $plugin_root . '/Assets/js/inbox/saved_views.js';
$bulk_actions_script = $plugin_root . '/Assets/js/inbox/bulk_actions.js';
$keyboard_navigation_script = $plugin_root . '/Assets/js/inbox/keyboard_navigation.js';
$collaboration_contract_script = $plugin_root . '/Assets/js/inbox/collaboration_contract.js';
$template_picker_script = $plugin_root . '/Assets/js/inbox/template_picker.js';
$conversation_workflow_script = $plugin_root . '/Assets/js/inbox/conversation_workflow.js';
$chatwoot_version = is_file($chatwoot_script) ? (string) filemtime($chatwoot_script) : '2.0.0';
$polling_scheduler_version = is_file($polling_scheduler_script) ? (string) filemtime($polling_scheduler_script) : '2.0.0';
$message_safe_version = is_file($message_safe_script) ? (string) filemtime($message_safe_script) : '2.0.0';
$message_renderers_version = is_file($message_renderers_script) ? (string) filemtime($message_renderers_script) : '2.0.0';
$message_actions_version = is_file($message_actions_script) ? (string) filemtime($message_actions_script) : '2.0.0';
$workspace_version = is_file($workspace_script) ? (string) filemtime($workspace_script) : '2.0.0';
$media_policy_version = is_file($media_policy_script) ? (string) filemtime($media_policy_script) : '2.0.0';
$composer_state_version = is_file($composer_state_script) ? (string) filemtime($composer_state_script) : '2.0.0';
$composer_quick_replies_version = is_file($composer_quick_replies_script) ? (string) filemtime($composer_quick_replies_script) : '2.0.0';
$composer_clipboard_version = is_file($composer_clipboard_script) ? (string) filemtime($composer_clipboard_script) : '2.0.0';
$composer_version = is_file($composer_script) ? (string) filemtime($composer_script) : '2.0.0';
$mentions_version = is_file($mentions_script) ? (string) filemtime($mentions_script) : '2.0.0';
$presence_version = is_file($presence_script) ? (string) filemtime($presence_script) : '2.0.0';
$saved_views_version = is_file($saved_views_script) ? (string) filemtime($saved_views_script) : '2.0.0';
$bulk_actions_version = is_file($bulk_actions_script) ? (string) filemtime($bulk_actions_script) : '2.0.0';
$keyboard_navigation_version = is_file($keyboard_navigation_script) ? (string) filemtime($keyboard_navigation_script) : '2.0.0';
$collaboration_contract_version = is_file($collaboration_contract_script) ? (string) filemtime($collaboration_contract_script) : '2.0.0';
$template_picker_version = is_file($template_picker_script) ? (string) filemtime($template_picker_script) : '2.0.0';
$conversation_workflow_version = is_file($conversation_workflow_script) ? (string) filemtime($conversation_workflow_script) : '2.0.0';
$asset_base = 'plugins/' . rawurlencode($plugin_folder) . '/Assets/js/';
?>
<script type="application/json" id="impulso-app-config"><?php echo json_encode($script_config, $json_flags); ?></script>
<script type="application/json" id="impulso-instance-data"><?php echo json_encode($script_instances, $json_flags); ?></script>
<script type="application/json" id="impulso-settings-data"><?php echo json_encode($script_settings, $json_flags); ?></script>
<?php if ($inbox_active) { ?>
<script src="<?php echo base_url($asset_base . 'inbox/message_safe_content.js'); ?>?v=<?php echo rawurlencode($message_safe_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/message_renderers.js'); ?>?v=<?php echo rawurlencode($message_renderers_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/message_actions.js'); ?>?v=<?php echo rawurlencode($message_actions_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/template_picker.js'); ?>?v=<?php echo rawurlencode($template_picker_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/conversation_workflow.js'); ?>?v=<?php echo rawurlencode($conversation_workflow_version); ?>"></script>
<?php } ?>
<?php if ($inbox_active) { ?><script src="<?php echo base_url($asset_base . 'inbox/polling_scheduler.js'); ?>?v=<?php echo rawurlencode($polling_scheduler_version); ?>"></script><?php } ?>
<script src="<?php echo base_url($asset_base . 'chatwoot.js'); ?>?v=<?php echo rawurlencode($chatwoot_version); ?>"></script>
<?php if ($inbox_active) { ?><script src="<?php echo base_url($asset_base . 'inbox/media_policy.js'); ?>?v=<?php echo rawurlencode($media_policy_version); ?>"></script><?php } ?>
<script src="<?php echo base_url($asset_base . 'hub-workspace.js'); ?>?v=<?php echo rawurlencode($workspace_version); ?>"></script>
<?php if ($inbox_active) { ?>
<script src="<?php echo base_url($asset_base . 'inbox/composer_state.js'); ?>?v=<?php echo rawurlencode($composer_state_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/composer_quick_replies.js'); ?>?v=<?php echo rawurlencode($composer_quick_replies_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/composer_clipboard.js'); ?>?v=<?php echo rawurlencode($composer_clipboard_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/composer.js'); ?>?v=<?php echo rawurlencode($composer_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/collaboration_contract.js'); ?>?v=<?php echo rawurlencode($collaboration_contract_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/mentions.js'); ?>?v=<?php echo rawurlencode($mentions_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/presence.js'); ?>?v=<?php echo rawurlencode($presence_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/saved_views.js'); ?>?v=<?php echo rawurlencode($saved_views_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/bulk_actions.js'); ?>?v=<?php echo rawurlencode($bulk_actions_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'inbox/keyboard_navigation.js'); ?>?v=<?php echo rawurlencode($keyboard_navigation_version); ?>"></script>
<?php } ?>
