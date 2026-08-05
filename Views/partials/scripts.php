<?php
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$script_config = is_array($app_config ?? null) ? $app_config : [];
$script_instances = is_array($instances ?? null) ? $instances : [];
$script_settings = is_array($settings_public ?? null) ? $settings_public : [];
$plugin_root = dirname(__DIR__, 2);
$plugin_folder = defined('CHATWOOT_PLUGIN_FOLDER') ? CHATWOOT_PLUGIN_FOLDER : basename($plugin_root);
$chatwoot_script = $plugin_root . '/Assets/js/chatwoot.js';
$workspace_script = $plugin_root . '/Assets/js/hub-workspace.js';
$chatwoot_version = is_file($chatwoot_script) ? (string) filemtime($chatwoot_script) : '2.0.0';
$workspace_version = is_file($workspace_script) ? (string) filemtime($workspace_script) : '2.0.0';
$asset_base = 'plugins/' . rawurlencode($plugin_folder) . '/Assets/js/';
?>
<script type="application/json" id="impulso-app-config"><?php echo json_encode($script_config, $json_flags); ?></script>
<script type="application/json" id="impulso-instance-data"><?php echo json_encode($script_instances, $json_flags); ?></script>
<script type="application/json" id="impulso-settings-data"><?php echo json_encode($script_settings, $json_flags); ?></script>
<script src="<?php echo base_url($asset_base . 'chatwoot.js'); ?>?v=<?php echo rawurlencode($chatwoot_version); ?>"></script>
<script src="<?php echo base_url($asset_base . 'hub-workspace.js'); ?>?v=<?php echo rawurlencode($workspace_version); ?>"></script>
