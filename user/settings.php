<?php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: /user/login.php');
    exit;
}

$user = getUserData($_SESSION['user_id']);
$pageTitle = "Settings";
require_once '../includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <h2>Settings</h2>
    
    <div class="card">
        <h3>Theme Preference</h3>
        <p>Choose your preferred theme for the application.</p>
        <div class="mt-1">
            <button class="btn" onclick="setTheme('light')">Light Theme</button>
            <button class="btn ml-1" onclick="setTheme('dark')">Dark Theme</button>
            <button class="btn btn-secondary ml-1" onclick="toggleTheme()">Toggle Theme</button>
        </div>
        <p class="theme-status">Current theme: <strong id="current-theme">Dark</strong></p>
    </div>
</div>

<script>
    function setTheme(theme) {
        const html = document.documentElement;
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        updateThemeDisplay();
    }

    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
    }

    function updateThemeDisplay() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        document.getElementById('current-theme').textContent = currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1);
    }

    // Initialize theme display
    updateThemeDisplay();
</script>

<?php renderFooter(); ?>
