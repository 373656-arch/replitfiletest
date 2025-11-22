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
    </head>
    <body>
        <header>
            <a href="/index.php" style="display: flex; align-items: center; text-decoration: none;">
                <img src="/theme/logo.png" alt="ModMyCar" style="height: 40px; width: auto;">
            </a>
            <nav>
                <?php if ($currentUser): ?>
                    <a href="/index.php">Home</a>
                    <a href="/community.php">Community</a>
                    <?php if ($isUserAdmin): ?>
                        <a href="/admin.php">Admin</a>
                    <?php endif; ?>
                    <a href="/user/profile.php">Profile</a>
                    <a href="/user/settings.php">Settings</a>
                    <a href="/user/logout.php">Logout</a>
                <?php else: ?>
                    <a href="/index.php">Home</a>
                    <a href="/community.php">Community</a>
                    <a href="/user/login.php">Sign In</a>
                    <a href="/user/register.php">Register</a>
                <?php endif; ?>
            </nav>
        </header>
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
        </script>
    </body>
    </html>
    <?php
}
?>
