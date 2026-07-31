<?php

use Chatwoot_plugin\Libraries\Chat_permissions;

$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$definitions = isset($definitions) && is_array($definitions)
    ? $definitions
    : Chat_permissions::definitions();
$title = Chat_permissions::translate('chatwoot_permissions_title', 'Impulso Hub Atendimento');
?>
<li>
    <span data-feather="message-circle" class="icon-14 ml-20"></span>
    <h5><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>:</h5>
    <?php foreach ($definitions as $permission => $definition) {
        $permission = (string) $permission;
        $language_key = (string) ($definition['language_key'] ?? '');
        $fallback = (string) ($definition['fallback'] ?? $permission);
        $label = Chat_permissions::translate($language_key, $fallback);
        ?>
        <div>
            <?php
            echo form_checkbox(
                $permission,
                '1',
                !empty($permissions[$permission]),
                "id='" . htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') . "' class='form-check-input'"
            );
            ?>
            <label for="<?php echo htmlspecialchars($permission, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </label>
        </div>
    <?php } ?>
</li>
