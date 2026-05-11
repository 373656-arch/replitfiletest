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
        $recent_notifications = getRecentNotifications($_SESSION['user_id'], 5); // Fetch top 5 recent
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
                background: rgba(255, 255, 255, 0.05) !important;
                border: 1px solid rgba(255, 255, 255, 0.1) !important;
                border-radius: 50% !important;
                width: 40px !important; 
                height: 40px !important;
                display: flex !important; 
                align-items: center !important; 
                justify-content: center !important;
                cursor: pointer !important; 
                color: inherit !important;
                transition: all 0.3s ease !important;
                padding: 0 !important;
                margin: 0 5px !important;
            }

            .notif-bell:hover { 
                background: rgba(255, 255, 255, 0.15) !important; 
                transform: translateY(-2px) !important; 
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
                padding: 18px 20px !important; 
                font-size: 16px !important; 
                font-weight: 700 !important; 
                border-bottom: 1px solid rgba(255,255,255,0.1) !important; 
                letter-spacing: 0.5px !important;
                color: #fff !important;
                text-align: left !important;
            }

            .notif-body { 
                max-height: 400px !important; 
                overflow-y: auto !important; 
                padding: 12px !important; 
            }

            .notif-item {
                display: block !important; 
                height: auto !important; /* Forces container to expand with wrapped text */
                min-height: 64px !important; /* Keeps a nice baseline size */
                padding: 16px 45px 16px 20px !important; 
                border-radius: 12px !important;
                text-decoration: none !important; 
                color: #fff !important; 
                position: relative !important;
                transition: all 0.2s ease !important; 
                margin-bottom: 6px !important;
                text-align: left !important;
                clear: both !important; /* Ensures the whole block clears any floats */
            }

            .notif-item:hover { 
                background: rgba(255,255,255,0.08) !important; 
            }

            .notif-item.unread { 
                background: rgba(200, 136, 58, 0.15) !important; 
            }
            .notif-item.unread::after {
                content: '' !important; 
                position: absolute !important; 
                top: 50% !important; 
                right: 18px !important; 
                transform: translateY(-50%) !important;
                width: 12px !important; 
                height: 12px !important; 
                background: var(--accent-1, #c8883a) !important; 
                border-radius: 50% !important;
                box-shadow: 0 0 10px var(--accent-1, #c8883a) !important;
            }

            .notif-msg { 
                display: block !important;
                float: none !important; /* Overrides rogue floats causing indentation */
                font-size: 14px !important; 
                line-height: 1.6 !important; 
                margin: 0 0 8px 0 !important; 
                white-space: normal !important; 
                word-wrap: break-word !important; 
                overflow-wrap: anywhere !important; 
                color: #eaeaea !important;
            }

            .notif-time { 
                display: block !important;
                float: none !important; /* Overrides rogue floats */
                font-size: 12px !important; 
                color: #aaa !important;
                font-weight: 500 !important;
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
            html[data-theme="light"] .notif-bell { border-color: #ddd !important; background: #f9f9f9 !important; color: #111 !important; }
            html[data-theme="light"] .notif-bell:hover { background: #eee !important; }
            html[data-theme="light"] .notif-item:hover { background: #f5f5f5 !important; }
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
                            <div class="notif-header">Activity</div>
                            <div class="notif-body">
                                <?php if (empty($recent_notifications)): ?>
                                    <div class="notif-empty">You're all caught up.</div>
                                <?php else: ?>
                                    <?php foreach ($recent_notifications as $notif): ?>
                                        <a href="<?= htmlspecialchars($notif['link'] ?? '#') ?>" class="notif-item <?= $notif['is_read'] ? '' : 'unread' ?>">
                                            <span class="notif-msg"><?= htmlspecialchars($notif['message']) ?></span>
                                            <span class="notif-time"><?= date('M j, g:i A', strtotime($notif['created_at'])) ?></span>
                                        </a>
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

            // --- REDESIGNED NOTIFICATION JS ---
            const notifToggle = document.getElementById('notifToggle');
            const notifPanel = document.getElementById('notifPanel');
            const notifBadge = document.getElementById('notifBadge');

            if (notifToggle && notifPanel) {
                // Toggle dropdown
                notifToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notifPanel.classList.toggle('show');

                    // Clear unread badge
                    if (notifPanel.classList.contains('show') && notifBadge) {
                        notifBadge.style.display = 'none'; 

                        // AJAX call
                        const formData = new FormData();
                        formData.append('ajax_mark_notifications_read', '1');
                        fetch('/config.php', { method: 'POST', body: formData }).catch(() => {});

                        // Remove glowing dots
                        document.querySelectorAll('.notif-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                    }
                });

                // Close when clicking outside
                document.addEventListener('click', function(e) {
                    if (!notifToggle.contains(e.target) && !notifPanel.contains(e.target)) {
                        notifPanel.classList.remove('show');
                    }
                });
            }
        </script>
    </body>
    </html>
    <?php
}
?>