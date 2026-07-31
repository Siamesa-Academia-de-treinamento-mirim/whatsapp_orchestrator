<?php
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$script_config = is_array($app_config ?? null) ? $app_config : [];
$script_instances = is_array($instances ?? null) ? $instances : [];
$script_settings = is_array($settings_public ?? null) ? $settings_public : [];
?>
<script type="application/json" id="impulso-app-config"><?php echo json_encode($script_config, $json_flags); ?></script>
<script type="application/json" id="impulso-instance-data"><?php echo json_encode($script_instances, $json_flags); ?></script>
<script type="application/json" id="impulso-settings-data"><?php echo json_encode($script_settings, $json_flags); ?></script>
<script src="<?php echo base_url('plugins/Chatwoot_plugin/Assets/js/chatwoot.js'); ?>?v=1.1.0"></script>
<script src="<?php echo base_url('plugins/Chatwoot_plugin/Assets/js/hub-workspace.js'); ?>?v=1.1.0"></script>
