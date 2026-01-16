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
        SELECT COUNT(*) / (SELECT COUNT(*) FROM cars) as avg 
        FROM part_compatibility
    ");
    $row = $result->fetch_assoc();
    $metrics['avg_parts_per_car'] = round($row['avg'], 1);

    // Total compatibility records
    $result = $conn->query("SELECT COUNT(*) as count FROM part_compatibility");
    $row = $result->fetch_assoc();
    $metrics['total_compatibilities'] = $row['count'];

    // Users with garages
    $result = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM user_saved_builds");
    $row = $result->fetch_assoc();
    $metrics['users_with_garages'] = $row['count'];

    // Parts with clicks
    $result = $conn->query("SELECT COUNT(DISTINCT part_id) as count FROM click_logs");
    $row = $result->fetch_assoc();
    $metrics['parts_with_clicks'] = $row['count'];

    // Average part price
    $result = $conn->query("SELECT AVG(price) as avg FROM parts");
    $row = $result->fetch_assoc();
    $metrics['avg_price'] = '$' . number_format($row['avg'], 2);

    echo json_encode($metrics);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>