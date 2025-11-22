<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

define('DB_HOST', 'srv941.hstgr.io');
define('DB_USER', 'u237055794_car');
define('DB_PASS', 'k>=fVIqH1');
define('DB_NAME', 'u237055794_car');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

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
?>
