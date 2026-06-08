<?php
declare(strict_types=1);

// 1. Security: Mask errors in production, but log them internally
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// 2. Security: Harden session cookies *before* starting the session
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,  // Prevents JavaScript from reading the session ID cookie
        'cookie_secure'   => true,  // Requires HTTPS (turn off in purely HTTP local testing if needed)
        'cookie_samesite' => 'Strict' // Protects against Cross-Site Request Forgery (CSRF)
    ]);
}

date_default_timezone_set('America/Los_Angeles');

// 3. Environment Variables (Excellent practice)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'modmycar');

// Using persistent connections ('p:') is an advanced touch—keep it!
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("A secure database error occurred. Please try again later.");
}

$conn->set_charset("utf8mb4");

// ==========================================
// CORE GLOBAL HELPERS (Keep these light)
// ==========================================

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isAdmin(string $email): bool {
    global $conn;
    $stmt = $conn->prepare("SELECT email FROM admin_whitelist WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function getUserData(int $user_id): ?array {
    global $conn;
    $stmt = $conn->prepare("SELECT uid, email, username, profileImage FROM users WHERE uid = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

// NOTE: You have a duplicate concept between this function and your api_similar_builds.php. 
// Consider routing frontend components exclusively to the API endpoint instead of keeping this global function!
function getSimilarBuilds(int $car_id, int $limit = 5): array {
    global $conn;
    $stmt = $conn->prepare("
        SELECT b.build_id, b.build_title, b.total_price, u.username, 
               (SELECT COUNT(*) FROM build_parts WHERE build_id = b.build_id) as parts_count
        FROM builds b
        JOIN users u ON b.user_id = u.uid
        WHERE b.car_id = ? AND b.is_community_shared = 1
        ORDER BY b.build_id DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $car_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// --- NOTIFICATION UTILITIES ---

function createNotification(int $user_id, string $type, string $message, ?string $link = null, ?int $actor_id = null): bool {
    global $conn;
    if ($actor_id) {
        $dup = $conn->prepare("SELECT id FROM notifications WHERE user_id=? AND actor_id=? AND type=? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
        $dup->bind_param("iis", $user_id, $actor_id, $type);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) return false;
    }
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, actor_id, type, message, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $user_id, $actor_id, $type, $message, $link);
    return $stmt->execute();
}

function getUnreadNotificationCount(int $user_id): int {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
}

function getRecentNotifications(int $user_id, int $limit = 5): array {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}