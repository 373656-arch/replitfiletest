<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('America/Los_Angeles');
define('DB_HOST', getenv('DB_HOST'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_NAME', getenv('DB_NAME'));

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// --- AJAX: Poll for new notifications (GET) ---
if (isset($_GET['ajax_poll_notifications']) && isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    $since_id = (int)($_GET['since_id'] ?? 0);
    $uid      = (int)$_SESSION['user_id'];
    $count    = 0;
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    $cnt_stmt->bind_param("i", $uid);
    $cnt_stmt->execute();
    $count = $cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0;
    $new_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND id > ? ORDER BY created_at DESC LIMIT 10");
    $new_stmt->bind_param("ii", $uid, $since_id);
    $new_stmt->execute();
    $new_notifs = $new_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['count' => (int)$count, 'new_notifications' => $new_notifs]);
    exit;
}

// --- AJAX LISTENER: Mark notifications as read ---
if (isset($_POST['ajax_mark_notifications_read']) && isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    exit;
}

// --- AJAX LISTENER: Mark single notification as read ---
if (isset($_POST['ajax_mark_single_notif_read']) && isset($_SESSION['user_id'])) {
    $notif_id = (int)($_POST['notif_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, (int)$_SESSION['user_id']);
    $stmt->execute();
    exit;
}

// --- AJAX LISTENER: Clear all notifications ---
if (isset($_POST['ajax_clear_all_notifications']) && isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin($email) {
    global $conn;
    $stmt = $conn->prepare("SELECT email FROM admin_whitelist WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

function getUserData($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT uid, email, username, profileImage FROM users WHERE uid = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getSimilarBuilds($car_id, $limit = 5) {
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
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// --- NOTIFICATION FUNCTIONS ---

function createNotification($user_id, $type, $message, $link = null, $actor_id = null) {
    global $conn;
    // Deduplicate: skip if same actor already sent this type of notification to this user in the last 60s
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

function getUnreadNotificationCount($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['count'] ?? 0;
}

// NEW: Fetch recent notifications for the dropdown
function getRecentNotifications($user_id, $limit = 5) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>