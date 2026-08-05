<?php
$tabs = [
    'dashboard' => ['label' => 'Visão geral', 'icon' => 'activity'],
    'conversations' => ['label' => 'Conversas', 'icon' => 'message-circle'],
    'contacts' => ['label' => 'Contatos', 'icon' => 'users'],
    'instances' => ['label' => 'Instâncias', 'icon' => 'smartphone'],
    'campaigns' => ['label' => 'Campanhas', 'icon' => 'send'],
    'bots' => ['label' => 'Bots', 'icon' => 'git-branch'],
    'settings' => ['label' => 'Configurações', 'icon' => 'settings']
];

if (empty($can_manage_instances)) {
    unset($tabs['instances']);
}
if (empty($can_manage_settings)) {
    unset($tabs['settings']);
}
if (empty($can_manage_contacts)) { unset($tabs['contacts']); }
if (empty($can_manage_campaigns)) { unset($tabs['campaigns']); }
if (empty($can_manage_bots)) { unset($tabs['bots']); }

$active_tab = $active_tab ?? 'dashboard';
if (!isset($tabs[$active_tab])) {
    $active_tab = 'dashboard';
}
$connected_instances = count(array_filter(($instances ?? []), static fn($instance) => ($instance['status'] ?? '') === 'connected'));
$total_unread = (int) ($notification_unread_count ?? 0);

include __DIR__ . '/partials/styles.php';
?>

<div id="page-content" class="page-wrapper clearfix impulso-page-content<?php echo $active_tab === 'conversations' ? ' impulso-page-content--conversations' : ''; ?>">
    <div id="impulso-hub-app" class="impulso-hub" data-active-tab="<?php echo esc($active_tab); ?>">
        <div class="card impulso-shell-card">
            <?php if (!empty($integration_error)) { ?>
                <div class="alert alert-danger m-3" role="alert"><?php echo esc($integration_error); ?></div>
            <?php } ?>

            <div class="page-title clearfix impulso-topbar">
                <div class="impulso-brand-block">
                    <div class="impulso-brand-mark"><i data-feather="message-square"></i></div>
                    <div>
                        <div class="impulso-eyebrow">Central de atendimento</div>
                        <h1>Impulso Hub</h1>
                    </div>
                    <span class="impulso-live-pill <?php echo $connected_instances ? '' : 'is-idle'; ?>">
                        <span></span>
                        <?php echo $connected_instances ? $connected_instances . ' canal' . ($connected_instances === 1 ? '' : 'is') . ' conectado' . ($connected_instances === 1 ? '' : 's') : 'Nenhum canal conectado'; ?>
                    </span>
                </div>

                <div class="title-button-group skip-dropdown-migration impulso-topbar-actions">
                    <button class="btn btn-default impulso-command-button" type="button" data-impulso-action="global-search" title="Buscar em conversas, contatos e campanhas">
                        <i data-feather="search"></i><span class="impulso-command-label">Buscar</span><kbd>Ctrl K</kbd>
                    </button>
                    <button class="btn btn-default impulso-icon-button impulso-notification-button" type="button" data-impulso-action="notifications" title="Notificações">
                        <i data-feather="bell"></i>
                        <?php if ($total_unread > 0) { ?><span class="impulso-notification-count"><?php echo min(99, $total_unread); ?></span><?php } ?>
                    </button>
                    <?php if (!empty($can_send_messages)) { ?>
                        <button class="btn btn-primary" type="button" data-impulso-action="new-conversation">
                            <i data-feather="plus"></i> Nova conversa
                        </button>
                    <?php } ?>
                </div>
            </div>

            <div class="impulso-section-nav" aria-label="Navegação do Impulso Hub">
                <?php foreach ($tabs as $key => $tab) { ?>
                    <a class="impulso-section-nav-item <?php echo $key === $active_tab ? 'active' : ''; ?>"
                       href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=' . $key); ?>">
                        <i data-feather="<?php echo esc($tab['icon']); ?>"></i>
                        <span><?php echo esc($tab['label']); ?></span>
                    </a>
                <?php } ?>
            </div>

            <div class="impulso-mobile-nav">
                <label for="impulso-mobile-section">Seção</label>
                <select id="impulso-mobile-section" class="form-control">
                    <?php foreach ($tabs as $key => $tab) { ?>
                        <option value="<?php echo esc($key); ?>" <?php echo $key === $active_tab ? 'selected' : ''; ?>><?php echo esc($tab['label']); ?></option>
                    <?php } ?>
                </select>
            </div>

            <div class="impulso-workspace">
                <?php include __DIR__ . '/partials/' . $active_tab . '.php'; ?>
            </div>
        </div>

        <?php include __DIR__ . '/modals/common.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/scripts.php'; ?>
