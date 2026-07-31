<style>
    .impulso-hub {
        --ih-primary: #6d5dfc;
        --ih-primary-strong: #5748e8;
        --ih-primary-soft: rgba(109, 93, 252, 0.11);
        --ih-success: #18a66a;
        --ih-warning: #e59a22;
        --ih-danger: #dd4b5d;
        --ih-info: #3182ce;
        --ih-text: inherit;
        --ih-muted: inherit;
        --ih-border: rgba(127, 127, 127, 0.18);
        --ih-surface: transparent;
        --ih-page-bg: transparent;
        --ih-surface-soft: rgba(127, 127, 127, 0.06);
        --ih-control-bg: transparent;
        --ih-shadow: none;
        background: transparent;
        color: inherit;
        min-height: calc(100vh - 130px);
    }

    .impulso-hub *,
    .impulso-hub *::before,
    .impulso-hub *::after {
        box-sizing: border-box;
    }

    .impulso-hub button,
    .impulso-hub input,
    .impulso-hub select,
    .impulso-hub textarea {
        font: inherit;
    }

    .impulso-shell-card {
        border: 0;
        box-shadow: none;
        min-height: calc(100vh - 140px);
        overflow: hidden;
    }

    .impulso-topbar {
        align-items: center;
        display: flex;
        gap: 18px;
        justify-content: space-between;
        min-height: 76px;
        padding: 14px 20px;
    }

    .impulso-brand-block,
    .impulso-topbar-actions,
    .impulso-inline,
    .impulso-section-heading,
    .impulso-filter-row,
    .impulso-stat-trend,
    .impulso-person-line,
    .impulso-chat-heading,
    .impulso-composer-tools,
    .impulso-contact-item,
    .impulso-instance-heading,
    .impulso-card-actions,
    .impulso-setting-row,
    .impulso-toggle-row,
    .impulso-modal-title {
        align-items: center;
        display: flex;
    }

    .impulso-brand-block {
        gap: 12px;
        min-width: 0;
    }

    .impulso-brand-mark {
        align-items: center;
        background: linear-gradient(145deg, var(--ih-primary), #9c75ff);
        border-radius: 13px;
        box-shadow: 0 8px 18px rgba(109, 93, 252, 0.25);
        color: #fff;
        display: flex;
        height: 44px;
        justify-content: center;
        width: 44px;
    }

    .impulso-brand-mark svg {
        height: 21px;
        width: 21px;
    }

    .impulso-brand-block h1 {
        font-size: 19px;
        font-weight: 750;
        line-height: 1.15;
        margin: 2px 0 0;
    }

    .impulso-eyebrow {
        color: inherit;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .12em;
        text-transform: uppercase;
    }

    .impulso-live-pill {
        align-items: center;
        background: rgba(24, 166, 106, 0.09);
        border: 1px solid rgba(24, 166, 106, 0.18);
        border-radius: 999px;
        color: var(--ih-success);
        display: inline-flex;
        font-size: 11px;
        font-weight: 700;
        gap: 6px;
        margin-left: 6px;
        padding: 5px 9px;
        white-space: nowrap;
    }

    .impulso-live-pill span {
        animation: impulsoPulse 1.8s infinite;
        background: var(--ih-success);
        border-radius: 50%;
        height: 7px;
        width: 7px;
    }

    @keyframes impulsoPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(24, 166, 106, .32); }
        50% { box-shadow: 0 0 0 5px rgba(24, 166, 106, 0); }
    }

    .impulso-topbar-actions {
        gap: 8px;
    }

    .impulso-topbar-actions .btn {
        align-items: center;
        display: inline-flex;
        gap: 7px;
        min-height: 38px;
    }

    .impulso-icon-button {
        align-items: center;
        border-radius: 9px;
        display: inline-flex;
        height: 38px;
        justify-content: center;
        padding: 0;
        position: relative;
        width: 38px;
    }

    .impulso-icon-button svg,
    .impulso-topbar .btn svg,
    .impulso-section-heading svg,
    .impulso-hub .btn svg {
        height: 16px;
        width: 16px;
    }

    .impulso-notification-dot {
        background: var(--ih-danger);
        border: 2px solid transparent;
        border-radius: 50%;
        height: 8px;
        position: absolute;
        right: 7px;
        top: 6px;
        width: 8px;
    }

    .impulso-mobile-nav {
        display: none;
        padding: 12px 15px;
    }

    .impulso-mobile-nav label {
        color: inherit;
        display: block;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 5px;
        text-transform: uppercase;
    }

    .impulso-workspace {
        background: transparent;
        min-height: calc(100vh - 216px);
    }

    .impulso-page {
        padding: 20px;
    }

    .impulso-section-heading {
        gap: 14px;
        justify-content: space-between;
        margin-bottom: 18px;
    }

    .impulso-section-heading h2 {
        font-size: 22px;
        font-weight: 750;
        margin: 0 0 4px;
    }

    .impulso-section-heading p {
        color: inherit;
        margin: 0;
    }

    .impulso-section-actions {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .impulso-grid {
        display: grid;
        gap: 14px;
    }

    .impulso-grid-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .impulso-grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .impulso-grid-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .impulso-card {
        background: transparent;
        border: 1px solid var(--ih-border);
        border-radius: 12px;
        min-width: 0;
    }

    .impulso-card-header {
        align-items: center;
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        gap: 10px;
        justify-content: space-between;
        min-height: 58px;
        padding: 13px 16px;
    }

    .impulso-card-header h3 {
        font-size: 15px;
        font-weight: 730;
        margin: 0;
    }

    .impulso-card-header p {
        color: inherit;
        font-size: 12px;
        margin: 3px 0 0;
    }

    .impulso-card-body {
        padding: 16px;
    }

    .impulso-stat-card {
        overflow: hidden;
        padding: 16px;
        position: relative;
    }

    .impulso-stat-card::after {
        background: var(--ih-primary-soft);
        border-radius: 50%;
        content: '';
        height: 72px;
        position: absolute;
        right: -25px;
        top: -28px;
        width: 72px;
    }

    .impulso-stat-top {
        align-items: flex-start;
        display: flex;
        justify-content: space-between;
        position: relative;
        z-index: 1;
    }

    .impulso-stat-icon {
        align-items: center;
        background: var(--ih-primary-soft);
        border-radius: 10px;
        color: var(--ih-primary);
        display: flex;
        height: 36px;
        justify-content: center;
        width: 36px;
    }

    .impulso-stat-icon.success { background: rgba(24, 166, 106, .1); color: var(--ih-success); }
    .impulso-stat-icon.warning { background: rgba(229, 154, 34, .11); color: var(--ih-warning); }
    .impulso-stat-icon.danger { background: rgba(221, 75, 93, .1); color: var(--ih-danger); }
    .impulso-stat-icon.info { background: rgba(49, 130, 206, .1); color: var(--ih-info); }

    .impulso-stat-icon svg {
        height: 18px;
        width: 18px;
    }

    .impulso-stat-label {
        color: inherit;
        font-size: 12px;
        font-weight: 650;
        margin-bottom: 8px;
    }

    .impulso-stat-value {
        font-size: 25px;
        font-weight: 780;
        letter-spacing: -.03em;
        line-height: 1;
        margin-bottom: 10px;
    }

    .impulso-stat-trend {
        color: inherit;
        font-size: 11px;
        gap: 4px;
    }

    .impulso-stat-trend strong {
        color: var(--ih-success);
        font-weight: 700;
    }

    .impulso-badge {
        align-items: center;
        border-radius: 999px;
        display: inline-flex;
        font-size: 10px;
        font-weight: 700;
        gap: 4px;
        line-height: 1;
        padding: 5px 8px;
        white-space: nowrap;
    }

    .impulso-badge.primary { background: var(--ih-primary-soft); color: var(--ih-primary); }
    .impulso-badge.success { background: rgba(24, 166, 106, .1); color: var(--ih-success); }
    .impulso-badge.warning { background: rgba(229, 154, 34, .12); color: #b66f08; }
    .impulso-badge.danger { background: rgba(221, 75, 93, .1); color: var(--ih-danger); }
    .impulso-badge.info { background: rgba(49, 130, 206, .1); color: var(--ih-info); }
    .impulso-badge.neutral { background: rgba(107, 114, 128, .1); color: inherit; }

    .impulso-dot {
        background: currentColor;
        border-radius: 50%;
        height: 6px;
        width: 6px;
    }

    .impulso-progress {
        background: rgba(107, 114, 128, 0.12);
        border-radius: 999px;
        height: 7px;
        overflow: hidden;
    }

    .impulso-progress > span {
        background: var(--ih-primary);
        border-radius: inherit;
        display: block;
        height: 100%;
    }

    .impulso-progress.success > span { background: var(--ih-success); }
    .impulso-progress.warning > span { background: var(--ih-warning); }
    .impulso-progress.danger > span { background: var(--ih-danger); }

    .impulso-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .impulso-list-item {
        align-items: center;
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        gap: 11px;
        padding: 12px 0;
    }

    .impulso-list-item:first-child { padding-top: 0; }
    .impulso-list-item:last-child { border-bottom: 0; padding-bottom: 0; }

    .impulso-avatar {
        align-items: center;
        background: linear-gradient(145deg, rgba(109,93,252,.18), rgba(156,117,255,.26));
        border: 1px solid rgba(109, 93, 252, .2);
        border-radius: 50%;
        color: var(--ih-primary-strong);
        display: flex;
        flex: 0 0 auto;
        font-size: 11px;
        font-weight: 780;
        height: 38px;
        justify-content: center;
        width: 38px;
    }

    .impulso-avatar.sm { height: 30px; width: 30px; font-size: 9px; }
    .impulso-avatar.lg { height: 52px; width: 52px; font-size: 14px; }

    .impulso-person-line {
        gap: 10px;
        min-width: 0;
    }

    .impulso-person-copy {
        min-width: 0;
    }

    .impulso-person-copy strong,
    .impulso-list-copy strong {
        display: block;
        font-size: 12px;
        font-weight: 720;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-person-copy span,
    .impulso-list-copy span {
        color: inherit;
        display: block;
        font-size: 11px;
        margin-top: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-list-copy {
        flex: 1;
        min-width: 0;
    }

    .impulso-list-side {
        color: inherit;
        flex: 0 0 auto;
        font-size: 10px;
        text-align: right;
    }

    .impulso-mini-value {
        font-size: 13px;
        font-weight: 730;
    }

    .impulso-table-wrap {
        overflow-x: auto;
    }

    .impulso-table {
        border-collapse: collapse;
        margin: 0;
        min-width: 760px;
        width: 100%;
    }

    .impulso-table th {
        border-bottom: 1px solid var(--ih-border);
        color: inherit;
        font-size: 10px;
        font-weight: 750;
        letter-spacing: .04em;
        padding: 11px 14px;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .impulso-table td {
        border-bottom: 1px solid var(--ih-border);
        font-size: 12px;
        padding: 12px 14px;
        vertical-align: middle;
    }

    .impulso-table tr:last-child td {
        border-bottom: 0;
    }

    .impulso-table tbody tr:hover {
        background: rgba(109, 93, 252, .025);
    }

    .impulso-empty {
        align-items: center;
        color: inherit;
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-height: 220px;
        padding: 28px;
        text-align: center;
    }

    .impulso-empty-icon {
        align-items: center;
        background: var(--ih-primary-soft);
        border-radius: 50%;
        color: var(--ih-primary);
        display: flex;
        height: 54px;
        justify-content: center;
        margin-bottom: 12px;
        width: 54px;
    }

    .impulso-empty h4 {
        color: inherit;
        font-size: 15px;
        font-weight: 720;
        margin: 0 0 5px;
    }

    .impulso-empty p {
        font-size: 12px;
        margin: 0;
        max-width: 380px;
    }

    /* Conversations */
    .impulso-conversations-page {
        padding: 0;
    }

    .impulso-chat-layout {
        display: grid;
        grid-template-columns: 330px minmax(430px, 1fr) 300px;
        height: calc(100vh - 216px);
        min-height: 650px;
    }

    .impulso-chat-column {
        background: transparent;
        min-width: 0;
        overflow: hidden;
    }

    .impulso-chat-sidebar {
        border-right: 1px solid var(--ih-border);
        display: flex;
        flex-direction: column;
    }

    .impulso-chat-main {
        display: flex;
        flex-direction: column;
    }

    .impulso-contact-sidebar {
        border-left: 1px solid var(--ih-border);
        overflow-y: auto;
    }

    .impulso-chat-sidebar-header {
        border-bottom: 1px solid var(--ih-border);
        padding: 14px;
    }

    .impulso-chat-heading {
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .impulso-chat-heading h2 {
        font-size: 17px;
        font-weight: 760;
        margin: 0;
    }

    .impulso-count-badge {
        align-items: center;
        background: var(--ih-primary-soft);
        border-radius: 999px;
        color: var(--ih-primary);
        display: inline-flex;
        font-size: 10px;
        font-weight: 760;
        justify-content: center;
        min-width: 23px;
        padding: 4px 7px;
    }

    .impulso-search {
        position: relative;
    }

    .impulso-search svg {
        color: inherit;
        height: 15px;
        left: 11px;
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 15px;
    }

    .impulso-search input {
        background: var(--ih-surface-soft);
        border: 1px solid transparent;
        border-radius: 9px;
        font-size: 12px;
        height: 38px;
        padding: 8px 10px 8px 34px;
        width: 100%;
    }

    .impulso-search input:focus {
        background: transparent;
        border-color: rgba(109, 93, 252, .5);
        box-shadow: 0 0 0 3px rgba(109, 93, 252, .08);
        outline: none;
    }

    .impulso-queue-tabs {
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        gap: 3px;
        padding: 8px 10px;
    }

    .impulso-queue-tab {
        background: transparent;
        border: 0;
        border-radius: 7px;
        color: inherit;
        cursor: pointer;
        flex: 1;
        font-size: 10px;
        font-weight: 720;
        padding: 7px 5px;
    }

    .impulso-queue-tab.active {
        background: var(--ih-primary-soft);
        color: var(--ih-primary);
    }

    .impulso-conversation-list {
        flex: 1;
        overflow-y: auto;
    }

    .impulso-conversation-item {
        background: transparent;
        border: 0;
        border-bottom: 1px solid var(--ih-border);
        cursor: pointer;
        display: block;
        padding: 12px 13px;
        text-align: left;
        transition: background .15s ease, border .15s ease;
        width: 100%;
    }

    .impulso-conversation-item:hover {
        background: rgba(109, 93, 252, .035);
    }

    .impulso-conversation-item.active {
        background: var(--ih-primary-soft);
        box-shadow: inset 3px 0 0 var(--ih-primary);
    }

    .impulso-conversation-line {
        align-items: flex-start;
        display: flex;
        gap: 10px;
    }

    .impulso-conversation-copy {
        flex: 1;
        min-width: 0;
    }

    .impulso-conversation-title {
        align-items: center;
        display: flex;
        gap: 6px;
        justify-content: space-between;
    }

    .impulso-conversation-title strong {
        font-size: 12px;
        font-weight: 750;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-conversation-time {
        color: inherit;
        flex: 0 0 auto;
        font-size: 9px;
    }

    .impulso-conversation-preview {
        color: inherit;
        display: -webkit-box;
        font-size: 11px;
        line-height: 1.4;
        margin: 4px 0 7px;
        overflow: hidden;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .impulso-conversation-meta {
        align-items: center;
        display: flex;
        gap: 5px;
        justify-content: space-between;
    }

    .impulso-instance-mini {
        color: inherit;
        font-size: 9px;
        max-width: 170px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-unread {
        align-items: center;
        background: var(--ih-primary);
        border-radius: 999px;
        color: #fff;
        display: inline-flex;
        font-size: 9px;
        font-weight: 760;
        height: 19px;
        justify-content: center;
        min-width: 19px;
        padding: 0 5px;
    }

    .impulso-chat-header {
        align-items: center;
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        gap: 11px;
        justify-content: space-between;
        min-height: 66px;
        padding: 10px 14px;
    }

    .impulso-chat-header-main {
        align-items: center;
        display: flex;
        gap: 10px;
        min-width: 0;
    }

    .impulso-chat-header-copy {
        min-width: 0;
    }

    .impulso-chat-header-copy h3 {
        font-size: 14px;
        font-weight: 750;
        margin: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-chat-header-copy p {
        color: inherit;
        font-size: 10px;
        margin: 3px 0 0;
    }

    .impulso-chat-header-actions {
        align-items: center;
        display: flex;
        gap: 6px;
    }

    .impulso-chat-body {
        background-image: radial-gradient(rgba(109, 93, 252, .07) 1px, transparent 1px);
        background-size: 18px 18px;
        flex: 1;
        overflow-y: auto;
        padding: 18px 22px;
    }

    .impulso-day-divider {
        align-items: center;
        color: inherit;
        display: flex;
        font-size: 9px;
        gap: 10px;
        justify-content: center;
        margin: 2px 0 18px;
        text-transform: uppercase;
    }

    .impulso-day-divider::before,
    .impulso-day-divider::after {
        background: var(--ih-border);
        content: '';
        height: 1px;
        max-width: 100px;
        width: 100%;
    }

    .impulso-message-row {
        display: flex;
        margin-bottom: 10px;
    }

    .impulso-message-row.outgoing {
        justify-content: flex-end;
    }

    .impulso-message-row.note {
        justify-content: center;
    }

    .impulso-message {
        background: rgba(127, 127, 127, 0.08);
        border: 1px solid var(--ih-border);
        border-radius: 12px 12px 12px 4px;
        box-shadow: 0 4px 14px rgba(31, 41, 55, .04);
        max-width: min(74%, 620px);
        padding: 9px 11px 7px;
    }

    .impulso-message-row.outgoing .impulso-message {
        background: var(--ih-primary);
        border-color: var(--ih-primary);
        border-radius: 12px 12px 4px 12px;
        color: #fff;
    }

    .impulso-message-row.note .impulso-message {
        background: rgba(229, 154, 34, .1);
        border-color: rgba(229, 154, 34, .24);
        border-radius: 9px;
        color: #8a5708;
        max-width: 84%;
    }

    .impulso-message p {
        font-size: 12px;
        line-height: 1.48;
        margin: 0;
        white-space: pre-wrap;
    }

    .impulso-message-footer {
        align-items: center;
        color: inherit;
        display: flex;
        font-size: 8px;
        gap: 4px;
        justify-content: flex-end;
        margin-top: 4px;
    }

    .impulso-message-row.outgoing .impulso-message-footer {
        color: rgba(255, 255, 255, .78);
    }

    .impulso-message-footer svg {
        height: 11px;
        width: 11px;
    }

    .impulso-media-card {
        align-items: center;
        background: rgba(127, 127, 127, .08);
        border-radius: 8px;
        display: flex;
        gap: 9px;
        margin-bottom: 7px;
        padding: 9px;
    }

    .impulso-media-icon {
        align-items: center;
        background: var(--ih-primary-soft);
        border-radius: 8px;
        color: var(--ih-primary);
        display: flex;
        height: 34px;
        justify-content: center;
        width: 34px;
    }

    .impulso-media-card strong {
        display: block;
        font-size: 10px;
    }

    .impulso-media-card span {
        color: inherit;
        display: block;
        font-size: 9px;
        margin-top: 2px;
    }

    .impulso-composer {
        background: transparent;
        border-top: 1px solid var(--ih-border);
        padding: 10px 14px 12px;
    }

    .impulso-composer-mode {
        align-items: center;
        display: flex;
        gap: 5px;
        margin-bottom: 7px;
    }

    .impulso-mode-button {
        background: transparent;
        border: 0;
        border-radius: 6px;
        color: inherit;
        cursor: pointer;
        font-size: 10px;
        font-weight: 710;
        padding: 5px 8px;
    }

    .impulso-mode-button.active {
        background: var(--ih-primary-soft);
        color: var(--ih-primary);
    }

    .impulso-composer-box {
        align-items: flex-end;
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 11px;
        display: flex;
        gap: 7px;
        padding: 7px;
    }

    .impulso-composer-box:focus-within {
        border-color: rgba(109, 93, 252, .45);
        box-shadow: 0 0 0 3px rgba(109, 93, 252, .07);
    }

    .impulso-composer textarea {
        background: transparent;
        border: 0;
        color: inherit;
        flex: 1;
        font-size: 12px;
        max-height: 120px;
        min-height: 35px;
        outline: none;
        padding: 8px 4px;
        resize: none;
    }

    .impulso-composer-tools {
        gap: 3px;
    }

    .impulso-tool-button,
    .impulso-send-button {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 8px;
        color: inherit;
        cursor: pointer;
        display: inline-flex;
        height: 34px;
        justify-content: center;
        width: 34px;
    }

    .impulso-tool-button:hover {
        background: rgba(107, 114, 128, .09);
        color: inherit;
    }

    .impulso-send-button {
        background: var(--ih-primary);
        color: #fff;
    }

    .impulso-tool-button svg,
    .impulso-send-button svg {
        height: 16px;
        width: 16px;
    }

    .impulso-composer-hint {
        color: inherit;
        font-size: 9px;
        margin-top: 6px;
    }

    .impulso-contact-profile {
        border-bottom: 1px solid var(--ih-border);
        padding: 20px 16px 16px;
        text-align: center;
    }

    .impulso-contact-profile .impulso-avatar {
        margin: 0 auto 9px;
    }

    .impulso-contact-profile h3 {
        font-size: 14px;
        font-weight: 760;
        margin: 0 0 3px;
    }

    .impulso-contact-profile p {
        color: inherit;
        font-size: 10px;
        margin: 0;
    }

    .impulso-profile-actions {
        display: grid;
        gap: 6px;
        grid-template-columns: repeat(3, 1fr);
        margin-top: 13px;
    }

    .impulso-profile-action {
        align-items: center;
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 8px;
        color: inherit;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        font-size: 8px;
        gap: 4px;
        padding: 7px 4px;
    }

    .impulso-profile-action svg {
        height: 14px;
        width: 14px;
    }

    .impulso-contact-section {
        border-bottom: 1px solid var(--ih-border);
        padding: 14px 16px;
    }

    .impulso-contact-section:last-child {
        border-bottom: 0;
    }

    .impulso-contact-section-title {
        align-items: center;
        display: flex;
        font-size: 10px;
        font-weight: 760;
        justify-content: space-between;
        letter-spacing: .03em;
        margin-bottom: 11px;
        text-transform: uppercase;
    }

    .impulso-contact-item {
        gap: 9px;
        margin-bottom: 10px;
    }

    .impulso-contact-item:last-child {
        margin-bottom: 0;
    }

    .impulso-contact-item svg {
        color: inherit;
        height: 14px;
        width: 14px;
    }

    .impulso-contact-item-copy {
        min-width: 0;
    }

    .impulso-contact-item-copy span {
        color: inherit;
        display: block;
        font-size: 8px;
        text-transform: uppercase;
    }

    .impulso-contact-item-copy strong {
        display: block;
        font-size: 10px;
        font-weight: 650;
        margin-top: 1px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-tag-list {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .impulso-select-small {
        border: 1px solid var(--ih-border);
        border-radius: 7px;
        font-size: 10px;
        height: 33px;
        padding: 4px 8px;
        width: 100%;
    }

    /* Instances */
    .impulso-instance-card {
        overflow: hidden;
    }

    .impulso-instance-card.connected { border-top: 3px solid var(--ih-success); }
    .impulso-instance-card.attention { border-top: 3px solid var(--ih-warning); }
    .impulso-instance-card.disconnected { border-top: 3px solid var(--ih-danger); }

    .impulso-instance-heading {
        gap: 10px;
        justify-content: space-between;
    }

    .impulso-instance-icon {
        align-items: center;
        background: rgba(24, 166, 106, .1);
        border-radius: 10px;
        color: var(--ih-success);
        display: flex;
        height: 38px;
        justify-content: center;
        width: 38px;
    }

    .impulso-instance-card.attention .impulso-instance-icon { background: rgba(229,154,34,.1); color: var(--ih-warning); }
    .impulso-instance-card.disconnected .impulso-instance-icon { background: rgba(221,75,93,.1); color: var(--ih-danger); }

    .impulso-instance-copy {
        flex: 1;
        min-width: 0;
    }

    .impulso-instance-copy h3 {
        font-size: 13px;
        font-weight: 750;
        margin: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-instance-copy p {
        color: inherit;
        font-size: 10px;
        margin: 3px 0 0;
    }

    .impulso-instance-meta {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(2, 1fr);
        margin-top: 16px;
    }

    .impulso-meta-box {
        background: var(--ih-surface-soft);
        border-radius: 8px;
        padding: 9px;
    }

    .impulso-meta-box span {
        color: inherit;
        display: block;
        font-size: 8px;
        text-transform: uppercase;
    }

    .impulso-meta-box strong {
        display: block;
        font-size: 11px;
        margin-top: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-health-row {
        align-items: center;
        display: flex;
        gap: 10px;
        margin-top: 14px;
    }

    .impulso-health-row .impulso-progress {
        flex: 1;
    }

    .impulso-health-row strong {
        font-size: 10px;
    }

    .impulso-card-actions {
        border-top: 1px solid var(--ih-border);
        gap: 6px;
        justify-content: flex-end;
        margin: 15px -16px -16px;
        padding: 10px 16px;
    }

    /* Campaign builder */
    .impulso-campaign-overview {
        align-items: center;
        display: grid;
        gap: 14px;
        grid-template-columns: minmax(210px, 1.4fr) repeat(5, minmax(70px, .6fr)) 90px;
    }

    .impulso-campaign-name strong {
        display: block;
        font-size: 12px;
    }

    .impulso-campaign-name span {
        color: inherit;
        display: block;
        font-size: 10px;
        margin-top: 3px;
    }

    .impulso-campaign-metric span {
        color: inherit;
        display: block;
        font-size: 8px;
        text-transform: uppercase;
    }

    .impulso-campaign-metric strong {
        display: block;
        font-size: 12px;
        margin-top: 3px;
    }

    .impulso-campaign-row {
        border-bottom: 1px solid var(--ih-border);
        padding: 14px 16px;
    }

    .impulso-campaign-row:last-child {
        border-bottom: 0;
    }

    .impulso-campaign-row:hover {
        background: rgba(109, 93, 252, .025);
    }

    .impulso-builder-steps {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(4, 1fr);
        margin-bottom: 18px;
    }

    .impulso-builder-step {
        align-items: center;
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 9px;
        color: inherit;
        display: flex;
        font-size: 10px;
        font-weight: 700;
        gap: 8px;
        padding: 9px;
    }

    .impulso-builder-step span {
        align-items: center;
        background: rgba(107, 114, 128, .12);
        border-radius: 50%;
        display: flex;
        height: 22px;
        justify-content: center;
        width: 22px;
    }

    .impulso-builder-step.active {
        background: var(--ih-primary-soft);
        border-color: rgba(109, 93, 252, .3);
        color: var(--ih-primary);
    }

    .impulso-builder-step.active span {
        background: var(--ih-primary);
        color: #fff;
    }

    .impulso-phone-preview {
        background: #151721;
        border: 7px solid #1f2230;
        border-radius: 25px;
        box-shadow: 0 16px 36px rgba(0, 0, 0, .16);
        margin: 0 auto;
        max-width: 270px;
        min-height: 470px;
        overflow: hidden;
    }

    .impulso-phone-top {
        background: #075e54;
        color: #fff;
        padding: 14px 12px 10px;
    }

    .impulso-phone-top strong {
        display: block;
        font-size: 11px;
    }

    .impulso-phone-top span {
        font-size: 8px;
        opacity: .76;
    }

    .impulso-phone-screen {
        background: #e8dfd4;
        min-height: 390px;
        padding: 18px 11px;
    }

    .impulso-wa-bubble {
        background: #d9fdd3;
        border-radius: 7px 0 7px 7px;
        box-shadow: 0 1px 2px rgba(0,0,0,.12);
        color: #1f2937;
        font-size: 9px;
        line-height: 1.45;
        margin-left: auto;
        max-width: 85%;
        padding: 8px 8px 5px;
    }

    .impulso-wa-time {
        color: #6b7280;
        display: block;
        font-size: 7px;
        margin-top: 4px;
        text-align: right;
    }

    /* AI */
    .impulso-agent-card {
        overflow: hidden;
    }

    .impulso-agent-head {
        align-items: flex-start;
        display: flex;
        gap: 11px;
    }

    .impulso-agent-icon {
        align-items: center;
        background: linear-gradient(145deg, var(--ih-primary-soft), rgba(156,117,255,.18));
        border-radius: 11px;
        color: var(--ih-primary);
        display: flex;
        height: 42px;
        justify-content: center;
        width: 42px;
    }

    .impulso-agent-icon svg { height: 20px; width: 20px; }

    .impulso-agent-copy {
        flex: 1;
        min-width: 0;
    }

    .impulso-agent-copy h3 {
        font-size: 13px;
        font-weight: 750;
        margin: 0;
    }

    .impulso-agent-copy p {
        color: inherit;
        font-size: 10px;
        margin: 3px 0 0;
    }

    .impulso-agent-stats {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(3, 1fr);
        margin-top: 14px;
    }

    .impulso-agent-stat {
        background: var(--ih-surface-soft);
        border-radius: 8px;
        padding: 8px;
        text-align: center;
    }

    .impulso-agent-stat strong {
        display: block;
        font-size: 13px;
    }

    .impulso-agent-stat span {
        color: inherit;
        display: block;
        font-size: 8px;
        margin-top: 2px;
        text-transform: uppercase;
    }

    .impulso-switch {
        display: inline-block;
        height: 22px;
        position: relative;
        width: 40px;
    }

    .impulso-switch input {
        height: 0;
        opacity: 0;
        width: 0;
    }

    .impulso-slider {
        background: rgba(107,114,128,.25);
        border-radius: 999px;
        bottom: 0;
        cursor: pointer;
        left: 0;
        position: absolute;
        right: 0;
        top: 0;
        transition: .2s;
    }

    .impulso-slider::before {
        background: transparent;
        border: 1px solid var(--ih-border);
        border-radius: 50%;
        box-shadow: 0 1px 3px rgba(0,0,0,.22);
        content: '';
        height: 16px;
        left: 3px;
        position: absolute;
        top: 3px;
        transition: .2s;
        width: 16px;
    }

    .impulso-switch input:checked + .impulso-slider {
        background: var(--ih-success);
    }

    .impulso-switch input:checked + .impulso-slider::before {
        transform: translateX(18px);
    }

    .impulso-automation-flow {
        align-items: center;
        display: grid;
        gap: 8px;
        grid-template-columns: minmax(160px, 1fr) 24px minmax(150px, 1fr) 24px minmax(150px, 1fr) 70px;
    }

    .impulso-flow-box {
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 8px;
        min-width: 0;
        padding: 9px;
    }

    .impulso-flow-box span {
        color: inherit;
        display: block;
        font-size: 8px;
        text-transform: uppercase;
    }

    .impulso-flow-box strong {
        display: block;
        font-size: 10px;
        margin-top: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-flow-arrow {
        color: inherit;
        text-align: center;
    }

    .impulso-flow-arrow svg { height: 14px; width: 14px; }

    /* Reports */
    .impulso-chart {
        align-items: flex-end;
        display: flex;
        gap: 10px;
        height: 210px;
        padding: 18px 5px 0;
    }

    .impulso-chart-column {
        align-items: center;
        display: flex;
        flex: 1;
        flex-direction: column;
        gap: 7px;
        height: 100%;
        justify-content: flex-end;
    }

    .impulso-chart-bar {
        background: linear-gradient(180deg, var(--ih-primary), rgba(109,93,252,.4));
        border-radius: 7px 7px 2px 2px;
        min-height: 10px;
        position: relative;
        transition: transform .15s ease;
        width: min(34px, 70%);
    }

    .impulso-chart-bar:hover {
        transform: translateY(-3px);
    }

    .impulso-chart-bar::before {
        background: #1f2937;
        border-radius: 5px;
        color: #fff;
        content: attr(data-value);
        font-size: 8px;
        left: 50%;
        opacity: 0;
        padding: 3px 5px;
        pointer-events: none;
        position: absolute;
        top: -24px;
        transform: translateX(-50%);
        transition: opacity .15s ease;
    }

    .impulso-chart-bar:hover::before { opacity: 1; }

    .impulso-chart-label {
        color: inherit;
        font-size: 9px;
    }

    .impulso-donut-wrap {
        align-items: center;
        display: flex;
        gap: 24px;
        justify-content: center;
        min-height: 240px;
    }

    .impulso-donut {
        align-items: center;
        background: conic-gradient(var(--ih-primary) 0 36%, var(--ih-success) 36% 64%, var(--ih-warning) 64% 86%, var(--ih-info) 86% 100%);
        border-radius: 50%;
        display: flex;
        height: 145px;
        justify-content: center;
        position: relative;
        width: 145px;
    }

    .impulso-donut::after {
        background: inherit;
        border-radius: 50%;
        content: '';
        height: 88px;
        position: absolute;
        width: 88px;
    }

    .impulso-donut-center {
        position: relative;
        text-align: center;
        z-index: 1;
    }

    .impulso-donut-center strong {
        display: block;
        font-size: 20px;
    }

    .impulso-donut-center span {
        color: inherit;
        font-size: 8px;
        text-transform: uppercase;
    }

    .impulso-legend {
        min-width: 150px;
    }

    .impulso-legend-item {
        align-items: center;
        display: flex;
        font-size: 10px;
        gap: 8px;
        justify-content: space-between;
        margin-bottom: 9px;
    }

    .impulso-legend-label {
        align-items: center;
        display: flex;
        gap: 6px;
    }

    .impulso-legend-color {
        border-radius: 3px;
        height: 9px;
        width: 9px;
    }

    /* Settings */
    .impulso-settings-layout {
        display: grid;
        gap: 16px;
        grid-template-columns: 220px minmax(0, 1fr);
    }

    .impulso-settings-nav {
        align-self: start;
        background: transparent;
        border: 1px solid var(--ih-border);
        border-radius: 11px;
        padding: 7px;
        position: sticky;
        top: 15px;
    }

    .impulso-settings-nav button {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 8px;
        color: inherit;
        cursor: pointer;
        display: flex;
        font-size: 11px;
        font-weight: 650;
        gap: 8px;
        padding: 9px 10px;
        text-align: left;
        width: 100%;
    }

    .impulso-settings-nav button.active {
        background: var(--ih-primary-soft);
        color: var(--ih-primary);
    }

    .impulso-settings-nav svg { height: 14px; width: 14px; }

    .impulso-settings-panel {
        display: none;
    }

    .impulso-settings-panel.active {
        display: block;
    }

    .impulso-setting-row {
        border-bottom: 1px solid var(--ih-border);
        gap: 20px;
        justify-content: space-between;
        padding: 14px 0;
    }

    .impulso-setting-row:first-child { padding-top: 0; }
    .impulso-setting-row:last-child { border-bottom: 0; padding-bottom: 0; }

    .impulso-setting-copy {
        flex: 1;
        min-width: 0;
    }

    .impulso-setting-copy strong {
        display: block;
        font-size: 11px;
    }

    .impulso-setting-copy span {
        color: inherit;
        display: block;
        font-size: 9px;
        line-height: 1.45;
        margin-top: 3px;
    }

    .impulso-field-grid {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .impulso-field {
        min-width: 0;
    }

    .impulso-field.full { grid-column: 1 / -1; }

    .impulso-field label {
        color: inherit;
        display: block;
        font-size: 9px;
        font-weight: 750;
        letter-spacing: .03em;
        margin-bottom: 5px;
        text-transform: uppercase;
    }

    .impulso-field .form-control {
        font-size: 11px;
        min-height: 38px;
    }

    .impulso-field textarea.form-control {
        min-height: 100px;
        resize: vertical;
    }

    .impulso-code {
        background: #191c26;
        border-radius: 8px;
        color: #d1d5db;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 9px;
        line-height: 1.55;
        overflow-x: auto;
        padding: 12px;
        white-space: pre;
    }

    /* Modals */
    .impulso-hub .modal-content {
        border: 0;
        border-radius: 14px;
        box-shadow: 0 26px 80px rgba(15, 23, 42, .22);
        overflow: hidden;
    }

    .impulso-hub .modal-header {
        border-bottom: 1px solid var(--ih-border);
        padding: 15px 18px;
    }

    .impulso-hub .modal-body {
        padding: 18px;
    }

    .impulso-hub .modal-footer {
        border-top: 1px solid var(--ih-border);
        padding: 12px 18px;
    }

    .impulso-modal-title {
        gap: 10px;
    }

    .impulso-modal-title-icon {
        align-items: center;
        background: var(--ih-primary-soft);
        border-radius: 9px;
        color: var(--ih-primary);
        display: flex;
        height: 34px;
        justify-content: center;
        width: 34px;
    }

    .impulso-modal-title h4 {
        font-size: 14px;
        font-weight: 750;
        margin: 0;
    }

    .impulso-modal-title p {
        color: inherit;
        font-size: 9px;
        margin: 2px 0 0;
    }

    .impulso-toast-stack {
        bottom: 20px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-width: 330px;
        position: fixed;
        right: 20px;
        z-index: 1200;
    }

    .impulso-toast {
        align-items: flex-start;
        animation: impulsoToastIn .2s ease-out;
        background: #1f2937;
        border-radius: 10px;
        box-shadow: 0 16px 35px rgba(0,0,0,.18);
        color: #fff;
        display: flex;
        gap: 9px;
        padding: 11px 13px;
    }

    .impulso-toast svg {
        flex: 0 0 auto;
        height: 16px;
        margin-top: 1px;
        width: 16px;
    }

    .impulso-toast strong {
        display: block;
        font-size: 10px;
    }

    .impulso-toast span {
        display: block;
        font-size: 9px;
        margin-top: 2px;
        opacity: .75;
    }

    @keyframes impulsoToastIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .impulso-hidden { display: none !important; }
    .impulso-text-success { color: var(--ih-success); }
    .impulso-text-warning { color: var(--ih-warning); }
    .impulso-text-danger { color: var(--ih-danger); }
    .impulso-text-muted { color: inherit; }
    .impulso-mt-14 { margin-top: 14px; }
    .impulso-mb-14 { margin-bottom: 14px; }
    .impulso-gap-8 { gap: 8px; }

    @media (max-width: 1280px) {
        .impulso-grid-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .impulso-chat-layout { grid-template-columns: 300px minmax(400px, 1fr); }
        .impulso-contact-sidebar {
            bottom: 0;
            box-shadow: -14px 0 35px rgba(31, 41, 55, .12);
            max-width: 330px;
            position: fixed;
            right: -360px;
            top: 0;
            transition: right .2s ease;
            width: 90%;
            z-index: 1050;
        }
        .impulso-contact-sidebar.open { right: 0; }
    }

    @media (max-width: 1024px) {
        .impulso-grid-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .impulso-campaign-overview {
            grid-template-columns: minmax(180px, 1.4fr) repeat(3, minmax(70px, .7fr)) 80px;
        }
        .impulso-campaign-overview .impulso-campaign-metric:nth-of-type(4),
        .impulso-campaign-overview .impulso-campaign-metric:nth-of-type(5) { display: none; }
        .impulso-automation-flow {
            grid-template-columns: minmax(160px, 1fr) 20px minmax(150px, 1fr) 20px minmax(150px, 1fr);
        }
        .impulso-automation-flow > :last-child { grid-column: 1 / -1; justify-self: end; }
    }

    @media (max-width: 840px) {
        .impulso-topbar { padding: 12px 14px; }
        .impulso-brand-block .impulso-live-pill { display: none; }
        .impulso-topbar-actions .impulso-icon-button { display: none; }
        .impulso-mobile-nav { display: block; border-bottom: 1px solid var(--ih-border); }
        .impulso-page { padding: 15px; }
        .impulso-grid-2,
        .impulso-grid-3,
        .impulso-grid-4 { grid-template-columns: 1fr; }
        .impulso-section-heading { align-items: flex-start; flex-direction: column; }
        .impulso-section-actions { width: 100%; }
        .impulso-section-actions .btn { flex: 1; }
        .impulso-chat-layout { grid-template-columns: 1fr; height: calc(100vh - 270px); min-height: 580px; }
        .impulso-chat-sidebar {
            bottom: 0;
            box-shadow: 14px 0 35px rgba(31, 41, 55, .12);
            left: -105%;
            max-width: 360px;
            position: fixed;
            top: 0;
            transition: left .2s ease;
            width: 92%;
            z-index: 1050;
        }
        .impulso-chat-sidebar.open { left: 0; }
        .impulso-chat-header-actions .impulso-mobile-hide { display: none; }
        .impulso-chat-body { padding: 15px 12px; }
        .impulso-message { max-width: 87%; }
        .impulso-settings-layout { grid-template-columns: 1fr; }
        .impulso-settings-nav { display: flex; overflow-x: auto; position: static; }
        .impulso-settings-nav button { flex: 0 0 auto; width: auto; }
        .impulso-field-grid { grid-template-columns: 1fr; }
        .impulso-field.full { grid-column: auto; }
        .impulso-builder-steps { grid-template-columns: repeat(2, 1fr); }
        .impulso-donut-wrap { flex-direction: column; }
    }

    @media (max-width: 560px) {
        .impulso-shell-card { border-radius: 0; min-height: calc(100vh - 110px); }
        .impulso-topbar-actions .btn-primary { font-size: 0; height: 38px; padding: 0; width: 38px; }
        .impulso-topbar-actions .btn-primary svg { margin: 0; }
        .impulso-brand-mark { height: 38px; width: 38px; }
        .impulso-brand-block h1 { font-size: 16px; }
        .impulso-eyebrow { display: none; }
        .impulso-grid-4 { grid-template-columns: 1fr; }
        .impulso-stat-card { padding: 14px; }
        .impulso-campaign-overview { grid-template-columns: 1fr 80px; }
        .impulso-campaign-overview .impulso-campaign-metric { display: none !important; }
        .impulso-builder-steps { grid-template-columns: 1fr; }
        .impulso-automation-flow { display: flex; flex-direction: column; align-items: stretch; }
        .impulso-flow-arrow { transform: rotate(90deg); }
        .impulso-agent-stats { grid-template-columns: 1fr; }
        .impulso-instance-meta { grid-template-columns: 1fr; }
        .impulso-profile-actions { grid-template-columns: 1fr; }
        .impulso-donut-wrap { min-height: auto; padding: 12px 0; }
        .impulso-toast-stack { bottom: 10px; left: 10px; max-width: none; right: 10px; }
    }


    /* Native Rise appearance: surfaces, controls, buttons and modals are inherited. */
    .impulso-hub,
    .impulso-shell-card,
    .impulso-workspace,
    .impulso-chat-column,
    .impulso-channel-sidebar,
    .impulso-composer,
    .impulso-card {
        color: inherit;
    }

    .impulso-text-muted {
        color: inherit;
        opacity: .68;
    }

    /* Multi-instance channel rail */
    .impulso-chat-layout {
        grid-template-columns: 205px 315px minmax(420px, 1fr) 300px;
    }

    .impulso-channel-sidebar {
        background: transparent;
        border-right: 1px solid var(--ih-border);
        display: flex;
        flex-direction: column;
        min-width: 0;
        overflow: hidden;
    }

    .impulso-channel-header {
        align-items: center;
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        justify-content: space-between;
        min-height: 70px;
        padding: 13px 12px;
    }

    .impulso-channel-header h3 {
        font-size: 15px;
        font-weight: 760;
        margin: 2px 0 0;
    }

    .impulso-channel-list {
        flex: 1;
        overflow-y: auto;
        padding: 8px;
    }

    .impulso-channel-item {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 9px;
        color: inherit;
        cursor: pointer;
        display: flex;
        gap: 9px;
        margin-bottom: 3px;
        min-height: 44px;
        padding: 7px 8px;
        text-align: left;
        transition: background .15s ease, color .15s ease;
        width: 100%;
    }

    .impulso-channel-item:hover {
        background: var(--ih-surface-soft);
    }

    .impulso-channel-item.active {
        background: var(--ih-primary-soft);
        box-shadow: inset 3px 0 0 var(--ih-primary);
        color: var(--ih-primary);
    }

    .impulso-channel-icon {
        align-items: center;
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 50%;
        color: inherit;
        display: inline-flex;
        flex: 0 0 auto;
        height: 28px;
        justify-content: center;
        position: relative;
        width: 28px;
    }

    .impulso-channel-icon.all {
        background: var(--ih-primary-soft);
        border-color: transparent;
        color: var(--ih-primary);
    }

    .impulso-channel-icon svg {
        height: 13px;
        width: 13px;
    }

    .impulso-channel-status-dot {
        background: var(--ih-muted);
        border: 2px solid transparent;
        border-radius: 50%;
        bottom: -1px;
        height: 9px;
        position: absolute;
        right: -1px;
        width: 9px;
    }

    .impulso-channel-icon.status-connected .impulso-channel-status-dot { background: var(--ih-success); }
    .impulso-channel-icon.status-attention .impulso-channel-status-dot { background: var(--ih-warning); }
    .impulso-channel-icon.status-disconnected .impulso-channel-status-dot { background: var(--ih-danger); }

    .impulso-channel-copy {
        flex: 1;
        min-width: 0;
    }

    .impulso-channel-copy strong,
    .impulso-channel-copy small {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .impulso-channel-copy strong {
        font-size: 11px;
        font-weight: 690;
    }

    .impulso-channel-copy small {
        color: inherit;
        font-size: 8px;
        margin-top: 2px;
    }

    .impulso-channel-unread {
        align-items: center;
        background: var(--ih-primary);
        border-radius: 999px;
        color: #fff;
        display: inline-flex;
        flex: 0 0 auto;
        font-size: 8px;
        font-weight: 760;
        height: 18px;
        justify-content: center;
        min-width: 18px;
        padding: 0 5px;
    }

    .impulso-channel-manage {
        align-items: center;
        border-top: 1px solid var(--ih-border);
        color: inherit;
        display: flex;
        font-size: 9px;
        font-weight: 680;
        gap: 7px;
        min-height: 46px;
        padding: 10px 13px;
        text-decoration: none;
    }

    .impulso-channel-manage:hover {
        background: var(--ih-surface-soft);
        color: var(--ih-primary);
        text-decoration: none;
    }

    .impulso-channel-manage svg {
        height: 13px;
        width: 13px;
    }

    .impulso-current-channel {
        color: inherit;
        display: block;
        font-size: 9px;
        margin-top: 2px;
    }

    .impulso-mobile-channel-picker {
        display: none;
        margin-bottom: 9px;
    }

    .impulso-mobile-channel-picker label {
        color: inherit;
        display: block;
        font-size: 8px;
        font-weight: 740;
        margin-bottom: 4px;
        text-transform: uppercase;
    }

    .impulso-active-channel-chip {
        align-items: center;
        background: var(--ih-primary-soft);
        border-radius: 999px;
        color: var(--ih-primary);
        display: inline-flex;
        gap: 4px;
        margin-right: 5px;
        padding: 3px 7px;
    }

    .impulso-active-channel-chip svg {
        height: 10px;
        width: 10px;
    }

    .impulso-instance-mini svg {
        height: 10px;
        vertical-align: -2px;
        width: 10px;
    }

    .impulso-conversation-empty {
        align-items: center;
        color: inherit;
        display: flex;
        flex-direction: column;
        min-height: 210px;
        padding: 24px 16px;
        text-align: center;
    }

    .impulso-conversation-empty strong {
        color: inherit;
        font-size: 11px;
        margin-bottom: 3px;
    }

    .impulso-conversation-empty span {
        font-size: 9px;
    }

    @media (max-width: 1480px) {
        .impulso-chat-layout {
            grid-template-columns: 190px 300px minmax(400px, 1fr);
        }

        .impulso-contact-sidebar {
            bottom: 0;
            box-shadow: -14px 0 35px rgba(31, 41, 55, .12);
            max-width: 330px;
            position: fixed;
            right: -360px;
            top: 0;
            transition: right .2s ease;
            width: 90%;
            z-index: 1050;
        }

        .impulso-contact-sidebar.open { right: 0; }
    }

    @media (max-width: 1100px) {
        .impulso-chat-layout {
            grid-template-columns: 68px 290px minmax(380px, 1fr);
        }

        .impulso-channel-header {
            justify-content: center;
            padding: 12px 6px;
        }

        .impulso-channel-header > div,
        .impulso-channel-header .impulso-count-badge,
        .impulso-channel-copy,
        .impulso-channel-unread,
        .impulso-channel-manage span {
            display: none;
        }

        .impulso-channel-list { padding: 7px; }
        .impulso-channel-item { justify-content: center; padding: 7px; }
        .impulso-channel-item.active { box-shadow: inset 3px 0 0 var(--ih-primary); }
        .impulso-channel-manage { justify-content: center; padding: 10px; }
    }

    @media (max-width: 840px) {
        .impulso-chat-layout {
            grid-template-columns: 1fr;
        }

        .impulso-channel-sidebar {
            display: none;
        }

        .impulso-mobile-channel-picker {
            display: block;
        }
    }


    /* Rise/Siamesa Gerencial integration */
    #page-content .impulso-hub {
        background: transparent;
    }

    .impulso-hub .impulso-topbar.page-title {
        margin: 0;
    }

    .impulso-hub .impulso-topbar .title-button-group {
        align-items: center;
        display: flex;
        float: none;
        gap: 8px;
        margin: 0;
    }

    .impulso-hub .impulso-topbar .title-button-group .btn {
        margin-left: 0;
    }

    .impulso-hub .impulso-card,
    .impulso-hub .impulso-chat-column,
    .impulso-hub .impulso-channel-sidebar,
    .impulso-hub .impulso-workspace,
    .impulso-hub .impulso-composer {
        background-color: transparent;
    }

    .impulso-hub .impulso-card,
    .impulso-hub .impulso-chat-column,
    .impulso-hub .impulso-channel-sidebar,
    .impulso-hub .impulso-contact-sidebar,
    .impulso-hub .impulso-chat-sidebar,
    .impulso-hub .impulso-chat-main {
        color: inherit;
    }

    .impulso-hub .impulso-channel-copy small,
    .impulso-hub .impulso-current-channel,
    .impulso-hub .impulso-conversation-time,
    .impulso-hub .impulso-conversation-preview,
    .impulso-hub .impulso-instance-mini,
    .impulso-hub .impulso-chat-header-copy p,
    .impulso-hub .impulso-contact-profile p,
    .impulso-hub .impulso-composer-hint,
    .impulso-hub .impulso-section-heading p,
    .impulso-hub .impulso-card-header p {
        color: inherit;
        opacity: .66;
    }

    .impulso-hub .impulso-search input,
    .impulso-hub .impulso-composer-box,
    .impulso-hub .impulso-profile-action,
    .impulso-hub .impulso-meta-box,
    .impulso-hub .impulso-channel-icon {
        background-color: rgba(127, 127, 127, 0.06);
    }

    .impulso-hub .impulso-conversation-item,
    .impulso-hub .impulso-channel-item,
    .impulso-hub .impulso-queue-tab,
    .impulso-hub .impulso-mode-button,
    .impulso-hub .impulso-tool-button {
        color: inherit;
    }

    .impulso-hub .impulso-message-row.incoming .impulso-message {
        background-color: rgba(127, 127, 127, 0.08);
        color: inherit;
    }

    .impulso-hub .modal-content,
    .impulso-hub .modal-header,
    .impulso-hub .modal-body,
    .impulso-hub .modal-footer,
    .impulso-hub .form-control,
    .impulso-hub .btn-default,
    .impulso-hub .btn-light {
        /* Deliberately no theme colors: Rise controls them. */
    }

    /* Functional states added by the Evolution MVP. Theme colors still come from Rise. */
    .impulso-hub .impulso-message-image {
        border-radius: 8px;
        display: block;
        margin-bottom: 7px;
        max-height: 320px;
        max-width: 100%;
        object-fit: contain;
    }

    .impulso-hub .impulso-message-audio {
        display: block;
        margin-bottom: 7px;
        max-width: 100%;
        width: 280px;
    }

    .impulso-hub .impulso-message-retry {
        background: transparent;
        border: 0;
        color: inherit;
        cursor: pointer;
        font-size: 9px;
        font-weight: 700;
        margin-top: 6px;
        padding: 0;
        text-decoration: underline;
    }

    .impulso-hub .impulso-message-row.is-failed .impulso-message {
        border-color: var(--ih-danger);
    }

    .impulso-hub .impulso-load-older {
        padding: 4px 0 14px;
        text-align: center;
    }

    .impulso-hub .impulso-send-button:disabled,
    .impulso-hub .impulso-tool-button:disabled {
        cursor: not-allowed;
        opacity: .48;
    }

    .impulso-instance-card.error { border-top: 3px solid var(--ih-danger); }
    .impulso-instance-card.error .impulso-instance-icon { background: rgba(221,75,93,.1); color: var(--ih-danger); }
    .impulso-channel-icon.status-error .impulso-channel-status-dot { background: var(--ih-danger); }

    @media (max-width: 767.98px) {
        .impulso-hub .impulso-topbar.page-title {
            align-items: stretch;
            flex-direction: column;
        }

        .impulso-hub .impulso-topbar .title-button-group {
            width: 100%;
        }
    }



    /* Refined workspace */
    .impulso-hub .impulso-section-nav {
        align-items: center;
        border-bottom: 1px solid var(--ih-border);
        border-top: 1px solid var(--ih-border);
        display: flex;
        gap: 3px;
        overflow-x: auto;
        padding: 7px 14px;
        scrollbar-width: none;
    }

    .impulso-hub .impulso-section-nav::-webkit-scrollbar { display: none; }

    .impulso-hub .impulso-section-nav-item {
        align-items: center;
        border-radius: 9px;
        color: inherit;
        display: inline-flex;
        flex: 0 0 auto;
        font-size: 11px;
        font-weight: 650;
        gap: 7px;
        padding: 9px 11px;
        text-decoration: none;
        transition: background .16s ease, color .16s ease, transform .16s ease;
    }

    .impulso-hub .impulso-section-nav-item:hover { background: var(--ih-surface-soft); transform: translateY(-1px); }
    .impulso-hub .impulso-section-nav-item.active { background: var(--ih-primary-soft); color: var(--ih-primary); }
    .impulso-hub .impulso-section-nav-item svg { height: 15px; width: 15px; }
    .impulso-hub .impulso-command-button { align-items: center; display: inline-flex; gap: 7px; }
    .impulso-hub .impulso-command-button kbd,
    .impulso-hub .impulso-command-search kbd {
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 5px;
        box-shadow: none;
        color: inherit;
        font-size: 9px;
        padding: 2px 5px;
    }
    .impulso-hub .impulso-notification-button { position: relative; }
    .impulso-hub .impulso-notification-count {
        align-items: center;
        background: var(--ih-danger);
        border: 2px solid var(--ih-surface);
        border-radius: 999px;
        color: #fff;
        display: flex;
        font-size: 8px;
        font-weight: 800;
        height: 18px;
        justify-content: center;
        min-width: 18px;
        padding: 0 4px;
        position: absolute;
        right: -6px;
        top: -7px;
    }
    .impulso-hub .impulso-live-pill.is-idle { background: var(--ih-surface-soft); border-color: var(--ih-border); color: inherit; opacity: .78; }
    .impulso-hub .impulso-live-pill.is-idle span { animation: none; background: currentColor; }
    .impulso-hub .impulso-card-header-wrap { align-items: flex-start; flex-wrap: wrap; gap: 12px; }
    .impulso-hub .impulso-card-header-wrap > :last-child { margin-left: auto; }
    .impulso-hub .impulso-flex-spacer { flex: 1; }
    .impulso-hub .btn-block { width: 100%; }
    .impulso-hub .impulso-mt-8 { margin-top: 8px; }
    .impulso-hub .impulso-row-menu { align-items: center; display: flex; gap: 5px; justify-content: flex-end; }
    .impulso-hub .impulso-empty.compact { min-height: auto; padding: 18px; }
    .impulso-hub .impulso-empty-row td { color: inherit; opacity: .68; padding: 30px 18px; text-align: center; }

    .impulso-hub .impulso-history-search {
        align-items: center;
        background: var(--ih-surface);
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        gap: 9px;
        padding: 8px 12px;
    }
    .impulso-hub .impulso-history-search > svg { height: 15px; opacity: .62; width: 15px; }
    .impulso-hub .impulso-history-search input { background: transparent; border: 0; color: inherit; flex: 1; min-width: 0; outline: 0; }
    .impulso-hub .impulso-history-search span { font-size: 10px; opacity: .65; white-space: nowrap; }
    .impulso-hub .impulso-message.is-search-match { box-shadow: 0 0 0 2px var(--ih-warning); }
    .impulso-hub .impulso-message-image { cursor: zoom-in; }
    .impulso-hub .impulso-media-button {
        background: transparent;
        border: 0;
        border-radius: 10px;
        display: block;
        max-width: min(360px, 100%);
        overflow: hidden;
        padding: 0;
        width: 100%;
    }
    .impulso-hub .impulso-media-button:focus-visible,
    .impulso-hub .impulso-media-open:focus-visible,
    .impulso-hub .impulso-media-document:focus-visible { outline: 2px solid var(--ih-primary); outline-offset: 2px; }
    .impulso-hub .impulso-media-button .impulso-message-image { display: block; height: auto; max-height: 360px; object-fit: cover; width: 100%; }
    .impulso-hub .impulso-audio-message { align-items: center; display: flex; gap: 7px; min-width: min(310px, 72vw); }
    .impulso-hub .impulso-audio-message audio { flex: 1; min-width: 0; }
    .impulso-hub .impulso-media-open { align-items: center; background: transparent; border: 1px solid var(--ih-border); border-radius: 8px; color: inherit; cursor: pointer; display: inline-flex; height: 34px; justify-content: center; width: 34px; }
    .impulso-hub .impulso-media-open:hover { background: var(--ih-surface-soft); border-color: var(--ih-primary); }
    .impulso-hub .impulso-media-document { align-items: center; color: inherit; display: grid; gap: 9px; grid-template-columns: auto minmax(0,1fr) auto; text-align: left; width: 100%; }
    .impulso-hub .impulso-media-document > span:nth-child(2) { min-width: 0; }
    .impulso-hub .impulso-media-document strong,
    .impulso-hub .impulso-media-document small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .impulso-hub .impulso-media-card { cursor: pointer; }
    .impulso-hub .impulso-media-card:hover { border-color: var(--ih-primary); }
    .impulso-hub .impulso-composer { position: relative; }
    .impulso-hub .impulso-composer-popover {
        background: var(--ih-surface);
        border: 1px solid var(--ih-border);
        border-radius: 12px;
        bottom: calc(100% - 40px);
        box-shadow: 0 16px 38px rgba(0,0,0,.18);
        left: 16px;
        max-height: 260px;
        overflow: auto;
        padding: 10px;
        position: absolute;
        width: 310px;
        z-index: 20;
    }
    .impulso-hub .impulso-emoji-grid { display: grid; gap: 4px; grid-template-columns: repeat(8, 1fr); }
    .impulso-hub .impulso-emoji-grid button { background: transparent; border: 0; border-radius: 7px; cursor: pointer; font-size: 20px; height: 34px; }
    .impulso-hub .impulso-emoji-grid button:hover { background: var(--ih-surface-soft); }
    .impulso-hub .impulso-quick-reply-list { display: grid; gap: 6px; }
    .impulso-hub .impulso-quick-reply-list button { background: transparent; border: 1px solid var(--ih-border); border-radius: 9px; color: inherit; cursor: pointer; padding: 9px; text-align: left; }
    .impulso-hub .impulso-quick-reply-list button:hover { border-color: var(--ih-primary); }
    .impulso-hub .impulso-quick-reply-list strong,
    .impulso-hub .impulso-quick-reply-list span { display: block; }
    .impulso-hub .impulso-quick-reply-list span { font-size: 10px; margin-top: 3px; opacity: .65; }
    .impulso-hub .impulso-attachment-preview {
        align-items: center;
        background: var(--ih-surface-soft);
        border: 1px solid var(--ih-border);
        border-radius: 9px;
        display: flex;
        gap: 10px;
        margin: 0 12px 8px;
        padding: 9px 11px;
    }
    .impulso-hub .impulso-attachment-preview img { border-radius: 7px; height: 42px; object-fit: cover; width: 42px; }
    .impulso-hub .impulso-attachment-preview-copy { flex: 1; min-width: 0; }
    .impulso-hub .impulso-attachment-preview-copy strong,
    .impulso-hub .impulso-attachment-preview-copy span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .impulso-hub .impulso-attachment-preview-copy span { font-size: 10px; opacity: .65; }
    .impulso-hub .impulso-tool-button.is-recording { animation: impulsoPulse 1s infinite; background: rgba(221,75,93,.13); color: var(--ih-danger); }
    .impulso-hub .impulso-composer[data-mode="note"] .impulso-composer-box { background: rgba(229,154,34,.08); border-color: rgba(229,154,34,.35); }
    .impulso-hub .impulso-composer[data-mode="note"] .impulso-send-button { background: var(--ih-warning); }
    .impulso-hub .impulso-ai-runtime { align-items: center; display: flex; gap: 7px; margin-bottom: 8px; }
    .impulso-hub .impulso-ai-runtime .impulso-status-dot { background: var(--ih-muted); border-radius: 50%; height: 8px; width: 8px; }
    .impulso-hub .impulso-ai-runtime.is-running .impulso-status-dot { background: var(--ih-success); }
    .impulso-hub .impulso-ai-runtime.is-human .impulso-status-dot { background: var(--ih-warning); }
    .impulso-hub .impulso-ai-runtime.is-paused .impulso-status-dot { background: var(--ih-danger); }

    .impulso-hub .impulso-person-button { align-items: center; background: transparent; border: 0; color: inherit; cursor: pointer; display: flex; gap: 9px; padding: 0; text-align: left; }
    .impulso-hub .impulso-bulk-bar {
        align-items: center;
        background: var(--ih-primary-soft);
        border-bottom: 1px solid var(--ih-border);
        display: flex;
        gap: 8px;
        padding: 9px 14px;
    }
    .impulso-hub .impulso-bulk-bar strong { margin-right: auto; }
    .impulso-hub .impulso-pagination-bar { align-items: center; border-top: 1px solid var(--ih-border); display: flex; justify-content: space-between; padding: 10px 14px; }
    .impulso-hub .impulso-pagination-bar span { font-size: 10px; opacity: .65; }

    .impulso-hub .impulso-campaign-list { min-height: 110px; }
    .impulso-hub .impulso-campaign-progress-block { min-width: 170px; }
    .impulso-hub .impulso-campaign-progress-block small { display: block; font-size: 9px; margin-top: 4px; opacity: .64; }
    .impulso-hub .impulso-campaign-overview { grid-template-columns: minmax(190px,1.5fr) minmax(170px,1fr) repeat(3,minmax(66px,.45fr)) auto auto; }
    .impulso-hub .impulso-builder-steps { align-items: stretch; display: grid; grid-template-columns: repeat(4,1fr); margin-bottom: 18px; }
    .impulso-hub .impulso-builder-step { background: transparent; border: 0; border-bottom: 2px solid var(--ih-border); color: inherit; cursor: pointer; display: flex; font-size: 10px; gap: 7px; justify-content: center; padding: 10px; }
    .impulso-hub .impulso-builder-step span { align-items: center; background: var(--ih-surface-soft); border-radius: 50%; display: flex; height: 20px; justify-content: center; width: 20px; }
    .impulso-hub .impulso-builder-step.active { border-bottom-color: var(--ih-primary); color: var(--ih-primary); font-weight: 700; }
    .impulso-hub .impulso-builder-step.done span { background: var(--ih-success); color: #fff; }
    .impulso-hub .impulso-audience-preview { background: var(--ih-surface-soft); border: 1px solid var(--ih-border); border-radius: 12px; padding: 14px; }
    .impulso-hub .impulso-audience-count { padding: 18px; text-align: center; }
    .impulso-hub .impulso-audience-count span,
    .impulso-hub .impulso-audience-count strong { display: block; }
    .impulso-hub .impulso-audience-count strong { font-size: 34px; margin-top: 4px; }
    .impulso-hub .impulso-weekdays { display: flex; flex-wrap: wrap; gap: 6px; }
    .impulso-hub .impulso-weekdays input { position: absolute; opacity: 0; }
    .impulso-hub .impulso-weekdays span { border: 1px solid var(--ih-border); border-radius: 8px; cursor: pointer; display: block; font-size: 10px; padding: 7px 10px; }
    .impulso-hub .impulso-weekdays input:checked + span { background: var(--ih-primary-soft); border-color: var(--ih-primary); color: var(--ih-primary); }
    .impulso-hub .impulso-code-input { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: 11px; }

    .impulso-hub .impulso-agent-card { align-items: center; border-bottom: 1px solid var(--ih-border); display: grid; gap: 10px; grid-template-columns: auto 1fr auto auto; padding: 11px 0; }
    .impulso-hub .impulso-agent-card:last-child { border-bottom: 0; }
    .impulso-hub .impulso-agent-copy strong,
    .impulso-hub .impulso-agent-copy span { display: block; }
    .impulso-hub .impulso-agent-copy span { font-size: 10px; margin-top: 3px; opacity: .65; }
    .impulso-hub .impulso-switch { display: inline-flex; position: relative; }
    .impulso-hub .impulso-switch input { height: 1px; opacity: 0; position: absolute; width: 1px; }
    .impulso-hub .impulso-switch span { background: rgba(127,127,127,.22); border-radius: 999px; cursor: pointer; display: block; height: 22px; position: relative; transition: .18s ease; width: 38px; }
    .impulso-hub .impulso-switch span::after { background: #fff; border-radius: 50%; box-shadow: 0 1px 4px rgba(0,0,0,.2); content: ''; height: 16px; left: 3px; position: absolute; top: 3px; transition: .18s ease; width: 16px; }
    .impulso-hub .impulso-switch input:checked + span { background: var(--ih-success); }
    .impulso-hub .impulso-switch input:checked + span::after { transform: translateX(16px); }

    .impulso-hub .impulso-segmented { background: var(--ih-surface-soft); border-radius: 8px; display: inline-flex; padding: 3px; }
    .impulso-hub .impulso-segmented button { background: transparent; border: 0; border-radius: 6px; color: inherit; cursor: pointer; font-size: 9px; padding: 5px 8px; }
    .impulso-hub .impulso-segmented button.active { background: var(--ih-surface); box-shadow: 0 1px 4px rgba(0,0,0,.08); font-weight: 700; }
    .impulso-hub .impulso-chart-bar { position: relative; }
    .impulso-hub .impulso-chart-bar span { font-size: 8px; left: 50%; opacity: 0; position: absolute; top: -17px; transform: translateX(-50%); transition: opacity .15s ease; }
    .impulso-hub .impulso-chart-column:hover .impulso-chart-bar span { opacity: 1; }
    .impulso-hub .impulso-funnel-step { padding: 18px; text-align: center; }
    .impulso-hub .impulso-funnel-step span,
    .impulso-hub .impulso-funnel-step strong { display: block; }
    .impulso-hub .impulso-funnel-step strong { font-size: 22px; margin: 4px 0 12px; }

    .impulso-hub .impulso-media-modal-content { overflow: hidden; }
    .impulso-hub #impulso-media-stage { align-items: center; background: rgba(0,0,0,.86); display: flex; justify-content: center; min-height: 55vh; padding: 20px; }
    .impulso-hub #impulso-media-stage img,
    .impulso-hub #impulso-media-stage video { max-height: 72vh; max-width: 100%; object-fit: contain; }
    .impulso-hub #impulso-media-stage audio { width: min(560px,100%); }
    .impulso-hub #impulso-media-stage iframe { background: #fff; border: 0; height: 72vh; width: 100%; }

    .impulso-hub .impulso-command-palette { padding: 0; }
    .impulso-hub .impulso-command-search { align-items: center; border-bottom: 1px solid var(--ih-border); display: flex; gap: 10px; padding: 14px 16px; }
    .impulso-hub .impulso-command-search input { background: transparent; border: 0; color: inherit; flex: 1; font-size: 15px; outline: 0; }
    .impulso-hub .impulso-command-results { max-height: 62vh; overflow: auto; padding: 10px; }
    .impulso-hub .impulso-command-section > span { display: block; font-size: 9px; font-weight: 800; letter-spacing: .09em; opacity: .55; padding: 8px; text-transform: uppercase; }
    .impulso-hub .impulso-command-section button { align-items: center; background: transparent; border: 0; border-radius: 9px; color: inherit; cursor: pointer; display: flex; gap: 10px; padding: 10px; text-align: left; width: 100%; }
    .impulso-hub .impulso-command-section button:hover,
    .impulso-hub .impulso-command-section button.active { background: var(--ih-primary-soft); }
    .impulso-hub .impulso-command-section button svg { height: 17px; width: 17px; }
    .impulso-hub .impulso-command-section button strong,
    .impulso-hub .impulso-command-section button small { display: block; }
    .impulso-hub .impulso-command-section button small { margin-top: 2px; opacity: .62; }

    .impulso-hub .impulso-notification-drawer { background: var(--ih-surface); border-left: 1px solid var(--ih-border); bottom: 0; box-shadow: -18px 0 45px rgba(0,0,0,.18); display: flex; flex-direction: column; max-width: 92vw; position: fixed; right: 0; top: 0; transform: translateX(105%); transition: transform .2s ease; width: 380px; z-index: 1065; }
    .impulso-hub .impulso-notification-drawer.open { transform: translateX(0); }
    .impulso-hub .impulso-drawer-header { align-items: center; border-bottom: 1px solid var(--ih-border); display: flex; justify-content: space-between; padding: 16px; }
    .impulso-hub .impulso-drawer-header h3 { margin: 2px 0 0; }
    .impulso-hub .impulso-drawer-tabs { border-bottom: 1px solid var(--ih-border); display: flex; gap: 4px; padding: 8px 12px; }
    .impulso-hub .impulso-drawer-tabs button { background: transparent; border: 0; border-radius: 7px; color: inherit; cursor: pointer; font-size: 10px; padding: 6px 8px; }
    .impulso-hub .impulso-drawer-tabs button.active { background: var(--ih-primary-soft); color: var(--ih-primary); }
    .impulso-hub .impulso-drawer-body { flex: 1; overflow: auto; padding: 10px; }
    .impulso-hub .impulso-drawer-footer { border-top: 1px solid var(--ih-border); padding: 12px; }
    .impulso-hub .impulso-drawer-backdrop { background: rgba(0,0,0,.28); inset: 0; opacity: 0; pointer-events: none; position: fixed; transition: opacity .2s ease; z-index: 1060; }
    .impulso-hub .impulso-drawer-backdrop.open { opacity: 1; pointer-events: auto; }
    .impulso-hub .impulso-notification-item { border-bottom: 1px solid var(--ih-border); border-radius: 9px; display: grid; gap: 8px; grid-template-columns: auto 1fr; padding: 11px; }
    .impulso-hub .impulso-notification-item.is-unread { background: var(--ih-primary-soft); }
    .impulso-hub .impulso-notification-item strong,
    .impulso-hub .impulso-notification-item span { display: block; }
    .impulso-hub .impulso-notification-item span { font-size: 10px; margin-top: 3px; opacity: .68; }

    .impulso-hub .impulso-context-menu { background: var(--ih-surface); border: 1px solid var(--ih-border); border-radius: 10px; box-shadow: 0 12px 32px rgba(0,0,0,.18); min-width: 190px; padding: 5px; position: fixed; z-index: 1080; }
    .impulso-hub .impulso-context-menu button { align-items: center; background: transparent; border: 0; border-radius: 7px; color: inherit; cursor: pointer; display: flex; font-size: 10px; gap: 8px; padding: 8px 9px; text-align: left; width: 100%; }
    .impulso-hub .impulso-context-menu button:hover { background: var(--ih-surface-soft); }
    .impulso-hub .impulso-context-menu button.danger { color: var(--ih-danger); }

    .impulso-hub .impulso-search-field { position: relative; }
    .impulso-hub .impulso-search-field > svg { height: 15px; left: 11px; opacity: .55; position: absolute; top: 11px; width: 15px; }
    .impulso-hub .impulso-search-field input { padding-left: 34px; }
    .impulso-hub .impulso-suggestion-list { background: var(--ih-surface); border: 1px solid var(--ih-border); border-radius: 9px; box-shadow: 0 12px 30px rgba(0,0,0,.14); margin-top: 4px; max-height: 220px; overflow: auto; position: absolute; width: calc(100% - 24px); z-index: 30; }
    .impulso-hub .impulso-suggestion-list button { background: transparent; border: 0; color: inherit; cursor: pointer; display: block; padding: 9px 11px; text-align: left; width: 100%; }
    .impulso-hub .impulso-suggestion-list button:hover { background: var(--ih-surface-soft); }

    @media (max-width: 991.98px) {
        .impulso-hub .impulso-section-nav { display: none; }
        .impulso-hub .impulso-command-label,
        .impulso-hub .impulso-command-button kbd { display: none; }
        .impulso-hub .impulso-campaign-overview { grid-template-columns: minmax(160px,1fr) auto auto; }
        .impulso-hub .impulso-campaign-progress-block,
        .impulso-hub .impulso-campaign-metric:nth-of-type(n+4) { display: none; }
    }

    .impulso-hub .impulso-message-row.internal { justify-content: center; }
    .impulso-hub .impulso-message-row.internal .impulso-message { background: var(--ih-warning-soft, #fff7d6); border: 1px dashed var(--ih-warning, #d99b00); color: var(--ih-text); max-width: 82%; }
    .impulso-hub .impulso-message-row.internal .impulso-message::before { content: "Nota interna"; display: block; font-size: 9px; font-weight: 700; letter-spacing: .08em; margin-bottom: 5px; text-transform: uppercase; }
    .impulso-hub .impulso-message-video { border-radius: 9px; display: block; max-height: 360px; max-width: 100%; }

    @media (max-width: 575.98px) {
        .impulso-hub .impulso-builder-steps { grid-template-columns: repeat(4,minmax(52px,1fr)); }
        .impulso-hub .impulso-builder-step { align-items: center; flex-direction: column; font-size: 8px; }
        .impulso-hub .impulso-composer-popover { left: 8px; max-width: calc(100vw - 32px); width: 310px; }
        .impulso-hub .impulso-bulk-bar { align-items: stretch; flex-direction: column; }
        .impulso-hub .impulso-bulk-bar strong { margin: 0; }
    }
</style>
