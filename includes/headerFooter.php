<?php
if (!isset($pageTitle)) {
    $pageTitle = "ModMyCar";
}
if (!isset($pageDescription)) {
    $pageDescription = "ModMyCar — Build your perfect car setup, discover compatible parts, and share your builds with a growing community of enthusiasts.";
}
$currentUser = null;
$isUserAdmin = false;
$unread_notifications = 0;
$recent_notifications = [];

if (isLoggedIn()) {
    $currentUser = getUserData($_SESSION['user_id']);
    if ($currentUser) {
        $isUserAdmin = isAdmin($currentUser['email']);
        $unread_notifications = getUnreadNotificationCount($_SESSION['user_id']);
        $recent_notifications = getRecentNotifications($_SESSION['user_id'], 10);
    }
}
function renderHeader() {
    global $pageTitle, $pageDescription, $currentUser, $isUserAdmin, $unread_notifications, $recent_notifications;
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($pageTitle); ?></title>
        <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
        <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
        <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription); ?>">
        <meta property="og:type" content="website">
        <meta name="theme-color" content="#c8883a">
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="/theme/style.css">
        <style>
            /* --- BULLETPROOF NOTIFICATION STYLING --- */
            .notif-wrap { 
                position: relative !important; 
                display: inline-flex !important; 
                align-items: center !important; 
            }

            .notif-bell {
                background: transparent !important;
                border: none !important;
                border-radius: 4px !important;
                width: 36px !important; 
                height: 36px !important;
                display: flex !important; 
                align-items: center !important; 
                justify-content: center !important;
                cursor: pointer !important; 
                color: inherit !important;
                transition: all 0.2s ease !important;
                padding: 0 !important;
                margin: 0 3px !important;
                opacity: 0.75 !important;
            }

            .notif-bell:hover { 
                opacity: 1 !important;
                transform: translateY(-1px) !important; 
            }

            .notif-badge {
                position: absolute !important; 
                top: -4px !important; 
                right: -4px !important;
                background: #ef4444 !important; 
                border: 2px solid var(--bg-color, #1a1a1a) !important;
                color: #fff !important; 
                font-size: 11px !important; 
                font-weight: 800 !important;
                width: 22px !important; 
                height: 22px !important; 
                border-radius: 50% !important;
                display: flex !important; 
                align-items: center !important; 
                justify-content: center !important;
                box-shadow: 0 2px 5px rgba(239, 68, 68, 0.5) !important;
                pointer-events: none !important;
                z-index: 2 !important;
            }

            .notif-panel {
                position: absolute !important; 
                top: calc(100% + 15px) !important; 
                right: -5px !important; 
                width: 380px !important; /* Force wider width */
                max-width: 90vw !important; 
                border-radius: 16px !important;
                background: rgba(30, 30, 35, 0.98) !important;
                backdrop-filter: blur(12px) !important; 
                -webkit-backdrop-filter: blur(12px) !important;
                border: 1px solid rgba(255, 255, 255, 0.1) !important;
                box-shadow: 0 20px 40px rgba(0,0,0,0.8) !important;
                z-index: 99999 !important;

                opacity: 0; 
                visibility: hidden;
                transform: translateY(-10px) scale(0.95);
                transform-origin: top right;
                transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            .notif-panel::before {
                content: '' !important; 
                position: absolute !important; 
                top: -7px !important; 
                right: 20px !important; 
                width: 14px !important; 
                height: 14px !important;
                background: rgba(30, 30, 35, 0.98) !important;
                border-left: 1px solid rgba(255, 255, 255, 0.1) !important;
                border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
                transform: rotate(45deg) !important;
            }

            .notif-panel.show {
                opacity: 1 !important; 
                visibility: visible !important; 
                transform: translateY(0) scale(1) !important;
            }

            .notif-header { 
                padding: 14px 18px !important; 
                font-size: 15px !important; 
                font-weight: 700 !important; 
                border-bottom: 1px solid rgba(255,255,255,0.1) !important; 
                letter-spacing: 0.5px !important;
                color: #fff !important;
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
            }

            .notif-clear-btn {
                background: transparent !important;
                border: 1px solid rgba(255,255,255,0.2) !important;
                color: #aaa !important;
                font-size: 11px !important;
                font-weight: 600 !important;
                padding: 3px 10px !important;
                border-radius: 20px !important;
                cursor: pointer !important;
                transition: all 0.2s !important;
                letter-spacing: 0.3px !important;
            }
            .notif-clear-btn:hover {
                background: rgba(239,68,68,0.15) !important;
                border-color: #ef4444 !important;
                color: #ef4444 !important;
            }

            .notif-body { 
                max-height: 400px !important; 
                overflow-y: auto !important; 
                padding: 12px !important; 
            }

            .notif-item {
                display: block !important; 
                height: auto !important;
                min-height: 56px !important;
                padding: 14px 16px !important; 
                border-radius: 12px !important;
                color: #fff !important; 
                position: relative !important;
                transition: all 0.2s ease !important; 
                margin-bottom: 6px !important;
                text-align: left !important;
                cursor: default !important;
            }

            .notif-item:hover { 
                background: rgba(255,255,255,0.06) !important; 
            }

            .notif-item.unread { 
                background: rgba(200, 136, 58, 0.12) !important;
                border-left: 3px solid var(--accent-1, #c8883a) !important;
            }

            .notif-item.admin-notif {
                background: rgba(239, 68, 68, 0.1) !important;
                border-left: 3px solid #ef4444 !important;
            }
            .notif-item.admin-notif .notif-view-link {
                border-color: rgba(239, 68, 68, 0.4) !important;
                color: #ef4444 !important;
            }
            .notif-item.admin-notif .notif-view-link:hover {
                background: rgba(239, 68, 68, 0.12) !important;
            }

            .notif-msg { 
                display: block !important;
                font-size: 13.5px !important; 
                line-height: 1.55 !important; 
                margin: 0 0 6px 0 !important; 
                white-space: normal !important; 
                word-wrap: break-word !important; 
                overflow-wrap: anywhere !important; 
                color: #e8e8e8 !important;
            }

            .notif-full-msg {
                display: none;
                font-size: 13px !important;
                line-height: 1.55 !important;
                margin: 4px 0 6px 0 !important;
                color: #ccc !important;
                word-wrap: break-word !important;
                overflow-wrap: anywhere !important;
                background: rgba(255,255,255,0.05) !important;
                border-radius: 6px !important;
                padding: 8px !important;
            }

            .notif-actions {
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
                margin-top: 6px !important;
                flex-wrap: wrap !important;
            }

            .notif-expand-btn {
                background: transparent !important;
                border: 1px solid rgba(255,255,255,0.2) !important;
                color: #bbb !important;
                font-size: 11px !important;
                font-weight: 600 !important;
                padding: 2px 9px !important;
                border-radius: 20px !important;
                cursor: pointer !important;
                transition: all 0.2s !important;
            }
            .notif-expand-btn:hover {
                background: rgba(255,255,255,0.1) !important;
                color: #fff !important;
            }

            .notif-view-link {
                font-size: 11px !important;
                font-weight: 600 !important;
                color: var(--accent-1, #c8883a) !important;
                text-decoration: none !important;
                padding: 2px 9px !important;
                border: 1px solid rgba(200,136,58,0.4) !important;
                border-radius: 20px !important;
                transition: all 0.2s !important;
            }
            .notif-view-link:hover {
                background: rgba(200,136,58,0.15) !important;
            }

            .notif-time { 
                display: block !important;
                font-size: 11px !important; 
                color: #888 !important;
                font-weight: 500 !important;
                margin-top: 4px !important;
            }

            .notif-empty { 
                padding: 40px 20px !important; 
                text-align: center !important; 
                color: #888 !important; 
                font-size: 15px !important; 
            }

            .notif-body::-webkit-scrollbar { width: 6px; }
            .notif-body::-webkit-scrollbar-track { background: transparent; }
            .notif-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
            .notif-body::-webkit-scrollbar-thumb:hover { background: var(--accent-1, #c8883a); }

            html[data-theme="light"] .notif-panel { background: rgba(255, 255, 255, 0.98) !important; box-shadow: 0 20px 40px rgba(0,0,0,0.15) !important; border-color: #e5e5e5 !important; }
            html[data-theme="light"] .notif-panel::before { background: rgba(255, 255, 255, 0.98) !important; border-color: #e5e5e5 !important; }
            html[data-theme="light"] .notif-header { border-color: #eee !important; color: #111 !important; }
            html[data-theme="light"] .notif-item { color: #222 !important; }
            html[data-theme="light"] .notif-msg { color: #333 !important; }
            html[data-theme="light"] .notif-full-msg { background: rgba(0,0,0,0.04) !important; color: #444 !important; }
            html[data-theme="light"] .notif-expand-btn { border-color: #ccc !important; color: #555 !important; }
            html[data-theme="light"] .notif-clear-btn { border-color: #ccc !important; color: #777 !important; }
            html[data-theme="light"] .notif-clear-btn:hover { background: rgba(239,68,68,0.08) !important; border-color: #ef4444 !important; color: #ef4444 !important; }
            html[data-theme="light"] .notif-bell { border-color: #ddd !important; background: #f9f9f9 !important; color: #111 !important; }
            html[data-theme="light"] .notif-bell:hover { background: #eee !important; }
            html[data-theme="light"] .notif-item:hover { background: rgba(0,0,0,0.04) !important; }
            html[data-theme="light"] .notif-item.unread { background: rgba(200,136,58,0.08) !important; }
            html[data-theme="light"] .notif-time { color: #999 !important; }
        </style>
    </head>
    <body>
        <div class="page-loader">
            <div class="loader-content">
                <div class="loader-spinner"></div>
                <p class="loader-text">Starting Engine</p>
            </div>
        </div>
        <header>
            <a href="/index.php" class="logo">
                modmycar<span class="logo-dot"></span>
            </a>
            <nav>
                <?php if ($currentUser): ?>
                    <a href="/index.php" title="Build">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M2.52 3.515A2.5 2.5 0 0 1 4.82 2h6.362c1 0 1.904.596 2.298 1.515l.792 1.848c.075.175.21.319.38.404.5.25.855.715.965 1.262l.335 1.679q.05.242.049.49v.413c0 .814-.39 1.543-1 1.997V13.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-1.338c-1.292.048-2.745.088-4 .088s-2.708-.04-4-.088V13.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-1.892c-.61-.454-1-1.183-1-1.997v-.413a2.5 2.5 0 0 1 .049-.49l.335-1.68c.11-.546.465-1.012.964-1.261a.8.8 0 0 0 .381-.404l.792-1.848ZM3 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2m10 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2M6 8a1 1 0 0 0 0 2h4a1 1 0 1 0 0-2zM2.906 5.189a.51.51 0 0 0 .497.731c.91-.073 3.35-.17 4.597-.17s3.688.097 4.597.17a.51.51 0 0 0 .497-.731l-.956-1.913A.5.5 0 0 0 11.691 3H4.309a.5.5 0 0 0-.447.276L2.906 5.19Z"/></svg>
                    </a>
                    <a href="/community.php" title="Community">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/></svg>
                    </a>
                    <?php if ($isUserAdmin): ?>
                        <a href="/admin.php" title="Admin">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z"/><path d="M8 5a3 3 0 1 1 0 6 3 3 0 0 1 0-6"/></svg>
                        </a>
                    <?php endif; ?>

                    <div class="notif-wrap">
                        <button class="notif-bell" id="notifToggle" title="Notifications">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5.002 5.002 0 0 1 13 6c0 .88.32 4.2 1.22 6z"/>
                            </svg>
                            <?php if ($unread_notifications > 0): ?>
                                <span id="notifBadge" class="notif-badge">
                                    <?= $unread_notifications ?>
                               </span>
                            <?php endif; ?>
                        </button>

                        <div class="notif-panel" id="notifPanel">
                            <div class="notif-header">
                                <span>Activity</span>
                                <button id="clearNotifsBtn" class="notif-clear-btn">Clear All</button>
                            </div>
                            <div class="notif-body">
                                <?php if (empty($recent_notifications)): ?>
                                    <div class="notif-empty">You're all caught up.</div>
                                <?php else: ?>
                                    <?php foreach ($recent_notifications as $notif): 
                                        $fullMsg     = $notif['message'];
                                        $isLong      = mb_strlen($fullMsg) > 70;
                                        $preview     = $isLong ? mb_substr($fullMsg, 0, 70) . '…' : $fullMsg;
                                        $isAdminType = ($notif['type'] === 'admin_reply');
                                        $isAdminCtx  = ($notif['type'] === 'admin_contact');
                                        $hasLink     = !empty($notif['link']) && $notif['link'] !== '#';
                                        $needsExpand = $isLong;
                                        $itemClasses = trim(($notif['is_read'] ? '' : 'unread') . ($isAdminCtx ? ' admin-notif' : ''));
                                    ?>
                                        <div class="notif-item <?= $itemClasses ?>"
                                             data-notif-id="<?= (int)$notif['id'] ?>"
                                             data-ts="<?= htmlspecialchars($notif['created_at']) ?>"
                                             data-full="<?= htmlspecialchars($fullMsg) ?>"
                                             data-preview="<?= htmlspecialchars($preview) ?>"
                                             data-type="<?= htmlspecialchars($notif['type']) ?>">
                                            <span class="notif-msg"><?= htmlspecialchars($preview) ?></span>
                                            <div class="notif-actions">
                                                <?php if ($needsExpand): ?>
                                                    <button class="notif-expand-btn" onclick="toggleNotifExpand(this)">Expand</button>
                                                <?php endif; ?>
                                                <?php if ($isAdminCtx && $isUserAdmin): ?>
                                                    <a href="/admin.php?section=messages" class="notif-view-link">View Messages →</a>
                                                <?php elseif (!$isAdminType && !$isAdminCtx && $hasLink): ?>
                                                    <a href="<?= htmlspecialchars($notif['link']) ?>" class="notif-view-link">View →</a>
                                                <?php endif; ?>
                                            </div>
                                            <span class="notif-time" data-ts="<?= htmlspecialchars($notif['created_at']) ?>"></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <a href="/user/profile.php" title="Profile">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/></svg>
                    </a>
                    <a href="/user/logout.php" title="Sign Out">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M6 12.5a.5.5 0 0 0 .5.5h8a.5.5 0 0 0 .5-.5v-9a.5.5 0 0 0-.5-.5h-8a.5.5 0 0 0-.5.5v2a.5.5 0 0 1-1 0v-2A1.5 1.5 0 0 1 6.5 2h8A1.5 1.5 0 0 1 16 3.5v9a1.5 1.5 0 0 1-1.5 1.5h-8A1.5 1.5 0 0 1 5 12.5v-2a.5.5 0 0 1 1 0z"/><path fill-rule="evenodd" d="M.146 8.354a.5.5 0 0 1 0-.708l3-3a.5.5 0 1 1 .708.708L1.707 7.5H10.5a.5.5 0 0 1 0 1H1.707l2.147 2.146a.5.5 0 0 1-.708.708z"/></svg>
                    </a>
                <?php else: ?>
                    <a href="/index.php" title="Home"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4.5v-5h3v5H14a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293z"/></svg></a>
                    <a href="/community.php" title="Community"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/></svg></a>
                    <a href="/user/login.php" title="Sign In"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/></svg></a>
                    <a href="/user/register.php" title="Register"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H1s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C9.516 10.68 8.289 10 6 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664z"/><path fill-rule="evenodd" d="M13.5 5a.5.5 0 0 1 .5.5V7h1.5a.5.5 0 0 1 0 1H14v1.5a.5.5 0 0 1-1 0V8h-1.5a.5.5 0 0 1 0-1H13V5.5a.5.5 0 0 1 .5-.5"/></svg></a>
                <?php endif; ?>
                <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme" aria-label="Toggle dark/light theme">
                    <span id="theme-icon">🌙</span>
                </button>
            </nav>
        </header>
        <main>
    <?php
}
function renderFooter() {
    ?>
        </main>
        <footer>
            <div class="footer-inner">
                <div>
                    <div class="footer-brand">modmycar<span style="color: var(--accent-1); margin-left: 2px;">.</span></div>
                    <div class="footer-tagline">Build smarter. Drive better.</div>
                </div>
                <nav class="footer-nav">
                    <a href="/index.php">Builder</a>
                    <a href="/community.php">Community</a>
                    <a href="/faq.php">FAQ</a>
                    <a href="/contact.php">Contact</a>
                </nav>
                <div class="footer-copy">&copy; <?php echo date('Y'); ?> ModMyCar &mdash; All rights reserved.</div>
            </div>
        </footer>
        <script>
            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);
            updateThemeIcon(savedTheme);

            function updateThemeIcon(theme) {
                const icon = document.getElementById('theme-icon');
                if (icon) icon.textContent = theme === 'dark' ? '🌙' : '☀️';
            }

            function toggleTheme() {
                const html = document.documentElement;
                const currentTheme = html.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                html.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
            }

            const loader = document.querySelector('.page-loader');

            const navLinks = document.querySelectorAll('header nav > a');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.href && !this.target && !this.href.includes('#')) {
                        e.preventDefault();
                        loader.classList.add('active');
                        document.body.classList.add('fade-out');
                        setTimeout(() => { window.location.href = this.href; }, 280);
                    }
                });
            });

            // --- NOTIFICATION SYSTEM ---
            const IS_ADMIN    = <?= $isUserAdmin ? 'true' : 'false' ?>;
            const notifToggle = document.getElementById('notifToggle');
            const notifPanel  = document.getElementById('notifPanel');
            let   notifBadge  = document.getElementById('notifBadge');
            const clearBtn    = document.getElementById('clearNotifsBtn');

            // Format stored UTC timestamp into local time
            function fmtTime(ts) {
                if (!ts) return '';
                const d = new Date(ts.replace(' ', 'T') + 'Z');
                return d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            }

            // Apply local time to all .notif-time elements
            function applyLocalTimes() {
                document.querySelectorAll('.notif-time[data-ts]').forEach(el => {
                    el.textContent = fmtTime(el.dataset.ts);
                });
            }
            applyLocalTimes();

            // Mark one notification as read visually + via AJAX
            function markItemRead(item) {
                if (!item.classList.contains('unread')) return;
                item.classList.remove('unread');
                const fd = new FormData();
                fd.append('ajax_mark_single_notif_read', '1');
                fd.append('notif_id', item.dataset.notifId || '0');
                fetch('/config.php', { method: 'POST', body: fd }).catch(() => {});
                // Update badge count
                const remaining = document.querySelectorAll('.notif-item.unread').length;
                if (notifBadge) {
                    if (remaining === 0) notifBadge.style.display = 'none';
                    else notifBadge.textContent = remaining;
                }
            }

            // Clicking anywhere on a notification marks it read
            document.addEventListener('click', function(e) {
                const item = e.target.closest('.notif-item');
                if (item) markItemRead(item);
            });

            // Expand / collapse message text in-place (single message, no duplicate)
            function toggleNotifExpand(btn) {
                const item    = btn.closest('.notif-item');
                const msgSpan = item.querySelector('.notif-msg');
                const expanded = item.dataset.expanded === '1';
                if (expanded) {
                    msgSpan.textContent = item.dataset.preview;
                    btn.textContent     = 'Expand';
                    item.dataset.expanded = '0';
                } else {
                    msgSpan.textContent = item.dataset.full;
                    btn.textContent     = 'Collapse';
                    item.dataset.expanded = '1';
                }
            }

            // Toggle notification panel
            if (notifToggle && notifPanel) {
                notifToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notifPanel.classList.toggle('show');
                });

                document.addEventListener('click', function(e) {
                    if (!notifToggle.contains(e.target) && !notifPanel.contains(e.target)) {
                        notifPanel.classList.remove('show');
                    }
                });
            }

            // Clear All
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const fd = new FormData();
                    fd.append('ajax_clear_all_notifications', '1');
                    fetch('/config.php', { method: 'POST', body: fd })
                        .then(() => {
                            const body = document.querySelector('.notif-body');
                            if (body) body.innerHTML = '<div class="notif-empty">You\'re all caught up.</div>';
                            if (notifBadge) notifBadge.style.display = 'none';
                        }).catch(() => {});
                });
            }

            // Build a notification item element from a raw notification object
            function buildNotifEl(n) {
                const full    = n.message || '';
                const isLong  = full.length > 70;
                const preview = isLong ? full.substring(0, 70) + '…' : full;
                const isACtx  = n.type === 'admin_contact';
                const isAType = n.type === 'admin_reply';
                const hasLink = n.link && n.link !== '#';

                const div = document.createElement('div');
                let classes = 'notif-item unread';
                if (isACtx) classes += ' admin-notif';
                div.className = classes;
                div.dataset.notifId  = n.id;
                div.dataset.ts       = n.created_at;
                div.dataset.full     = full;
                div.dataset.preview  = preview;
                div.dataset.type     = n.type;
                div.dataset.expanded = '0';

                let actionsHtml = '';
                if (isLong) actionsHtml += `<button class="notif-expand-btn" onclick="toggleNotifExpand(this)">Expand</button>`;
                if (isACtx && IS_ADMIN) {
                    actionsHtml += `<a href="/admin.php?section=messages" class="notif-view-link">View Messages →</a>`;
                } else if (!isAType && !isACtx && hasLink) {
                    actionsHtml += `<a href="${n.link}" class="notif-view-link">View →</a>`;
                }

                div.innerHTML =
                    `<span class="notif-msg">${preview.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>` +
                    (actionsHtml ? `<div class="notif-actions">${actionsHtml}</div>` : '') +
                    `<span class="notif-time" data-ts="${n.created_at}"></span>`;
                return div;
            }

            // Real-time polling: check for new notifications every 30s
            let lastKnownId = <?php
                $first = !empty($recent_notifications) ? (int)$recent_notifications[0]['id'] : 0;
                echo $first;
            ?>;

            function pollNotifications() {
                fetch('/config.php?ajax_poll_notifications=1&since_id=' + lastKnownId)
                    .then(r => r.json())
                    .then(data => {
                        const newOnes = data.new_notifications || [];

                        // Prepend new items to panel
                        if (newOnes.length > 0) {
                            const body = document.querySelector('.notif-body');
                            const empty = body ? body.querySelector('.notif-empty') : null;
                            if (empty) empty.remove();
                            newOnes.forEach(n => {
                                const el = buildNotifEl(n);
                                if (body) body.insertBefore(el, body.firstChild);
                                if (n.id > lastKnownId) lastKnownId = n.id;
                            });
                            applyLocalTimes();
                        }

                        // Update badge
                        const count = data.count || 0;
                        if (count > 0) {
                            if (!notifBadge || notifBadge.style.display === 'none') {
                                if (!notifBadge) {
                                    notifBadge = document.createElement('span');
                                    notifBadge.id        = 'notifBadge';
                                    notifBadge.className = 'notif-badge';
                                    if (notifToggle) notifToggle.appendChild(notifBadge);
                                } else {
                                    notifBadge.style.display = 'flex';
                                }
                            }
                            notifBadge.textContent = count;
                        }
                    }).catch(() => {});
            }

            setInterval(pollNotifications, 30000);
        </script>
    </body>
    </html>
    <?php
}
?>