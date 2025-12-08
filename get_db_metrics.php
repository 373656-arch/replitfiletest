<?php
require_once 'config.php';

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = getUserData($_SESSION['user_id']);
if (!isAdmin($user['email'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

try {
    $metrics = [];

    // Average parts per car
    $result = $conn->query("
        SELECT COALESCE(COUNT(*) / NULLIF((SELECT COUNT(*) FROM cars), 0), 0) as avg 
        FROM part_compatibility
    ");
    $row = $result->fetch_assoc();
    $metrics['avg_parts_per_car'] = round($row['avg'], 1);

    // Total compatibility records
    $result = $conn->query("SELECT COUNT(*) as count FROM part_compatibility");
    $row = $result->fetch_assoc();
    $metrics['total_compatibilities'] = $row['count'];

    // Total builds shared
    $result = $conn->query("SELECT COUNT(*) as count FROM builds WHERE is_community_shared = 1");
    $row = $result->fetch_assoc();
    $metrics['community_builds'] = $row['count'];

    // Total comments
    $result = $conn->query("SELECT COUNT(*) as count FROM comments");
    $row = $result->fetch_assoc();
    $metrics['total_comments'] = $row['count'];

    // Average build price
    $result = $conn->query("SELECT AVG(total_price) as avg FROM builds");
    $row = $result->fetch_assoc();
    $metrics['avg_build_price'] = '$' . number_format($row['avg'] ?? 0, 2);

    // Most liked build
    $result = $conn->query("SELECT MAX(likes_count) as max_likes FROM builds");
    $row = $result->fetch_assoc();
    $metrics['max_likes_on_build'] = $row['max_likes'] ?? 0;

    // Average part price
    $result = $conn->query("SELECT AVG(price) as avg FROM parts");
    $row = $result->fetch_assoc();
    $metrics['avg_part_price'] = '$' . number_format($row['avg'] ?? 0, 2);

    echo json_encode($metrics);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>