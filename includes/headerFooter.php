<?php
if (!isset($pageTitle)) {
    $pageTitle = "ModMyCar";
}

$currentUser = null;
$isUserAdmin = false;

if (isLoggedIn()) {
    $currentUser = getUserData($_SESSION['user_id']);
    if ($currentUser) {
        $isUserAdmin = isAdmin($currentUser['email']);
    }
}

function renderHeader() {
    global $pageTitle, $currentUser, $isUserAdmin;
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($pageTitle); ?></title>
        <link rel="stylesheet" href="/theme/style.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body>
        <div class="page-loader">
            <div class="loader-content">
                <div class="loader-spinner"></div>
                <p class="loader-text">Loading</p>
            </div>
        </div>
        <header>
            <div style="display: flex; align-items: center; gap: 1rem; width: 100%;">
                <button id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <a href="/index.php" style="display: flex; align-items: center; text-decoration: none;">
                    <img src="/theme/logo.png" alt="ModMyCar" style="height: 40px; width: auto;">
                </a>
            </div>
        </header>
        <aside id="sidebar" class="sidebar">
            <div class="sidebar-content">
                <button id="sidebarClose" class="sidebar-close" aria-label="Close navigation">
                    <i class="fas fa-times"></i>
                </button>
                <nav class="sidebar-nav">
                    <?php if ($currentUser): ?>
                        <a href="/index.php" class="sidebar-link"><i class="fas fa-home"></i> Home</a>
                        <a href="/community.php" class="sidebar-link"><i class="fas fa-users"></i> Community</a>
                        <?php if ($isUserAdmin): ?>
                            <a href="/admin.php" class="sidebar-link"><i class="fas fa-cog"></i> Admin</a>
                        <?php endif; ?>
                        <a href="/user/profile.php" class="sidebar-link"><i class="fas fa-user"></i> Profile</a>
                        <a href="/user/settings.php" class="sidebar-link"><i class="fas fa-sliders-h"></i> Settings</a>
                        <a href="/user/logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    <?php else: ?>
                        <a href="/index.php" class="sidebar-link"><i class="fas fa-home"></i> Home</a>
                        <a href="/community.php" class="sidebar-link"><i class="fas fa-users"></i> Community</a>
                        <a href="/user/login.php" class="sidebar-link"><i class="fas fa-sign-in-alt"></i> Sign In</a>
                        <a href="/user/register.php" class="sidebar-link"><i class="fas fa-user-plus"></i> Register</a>
                    <?php endif; ?>
                </nav>
            </div>
        </aside>
        <main>
    <?php
}

function renderFooter() {
    ?>
        </main>
        <footer>
            <p>&copy; <?php echo date('Y'); ?> ModMyCar. All rights reserved. | Contact: support@modmycar.com</p>
        </footer>
        <script>
            function toggleTheme() {
                const html = document.documentElement;
                const currentTheme = html.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                html.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
            }

            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);

            const loader = document.querySelector('.page-loader');
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarClose = document.getElementById('sidebarClose');
            const navLinks = document.querySelectorAll('.sidebar-nav a');

            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });

            sidebarClose.addEventListener('click', function() {
                sidebar.classList.remove('active');
            });

            sidebar.addEventListener('click', function(e) {
                if (e.target === sidebar) {
                    sidebar.classList.remove('active');
                }
            });

            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.href && !this.target) {
                        e.preventDefault();
                        sidebar.classList.remove('active');
                        loader.classList.add('active');
                        document.body.classList.add('fade-out');
                        setTimeout(() => {
                            window.location.href = this.href;
                        }, 300);
                    }
                });
            });

            const logoLink = document.querySelector('header a[href="/index.php"]');
            if (logoLink) {
                logoLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    loader.classList.add('active');
                    document.body.classList.add('fade-out');
                    setTimeout(() => {
                        window.location.href = this.href;
                    }, 300);
                });
            }

            window.addEventListener('beforeunload', function() {
                loader.classList.add('active');
            });
        </script>
    </body>
    </html>
    <?php
}
?>
