<?php
// Enforce strict typing for better performance and fewer bugs
declare(strict_types=1);

require_once 'config.php';

header('Content-Type: application/json');

// 1. Change to GET since we are fetching data, not creating it.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

// 2. Fetch from $_GET instead of $_POST
$car_id = isset($_GET['car_id']) ? (int)$_GET['car_id'] : 0;
$part_ids = isset($_GET['part_ids']) ? $_GET['part_ids'] : [];

if (!$car_id || !is_array($part_ids) || empty($part_ids)) {
    echo json_encode(['builds' => []]);
    exit;
}

$part_ids = array_map('intval', $part_ids);
$placeholders = implode(',', array_fill(0, count($part_ids), '?'));

// 3. Add proper Error Handling (Try/Catch block)
try {
    $sql = "
        SELECT DISTINCT b.build_id, b.build_title, b.total_price, u.username, 
               (SELECT COUNT(*) FROM build_parts WHERE build_id = b.build_id) as parts_count,
               COUNT(DISTINCT bp.part_id) as matching_parts
        FROM builds b
        JOIN build_parts bp ON b.build_id = bp.build_id
        JOIN users u ON b.user_id = u.uid
        WHERE b.car_id = ? 
          AND b.is_community_shared = 1
          AND bp.part_id IN ($placeholders)
        GROUP BY b.build_id
        ORDER BY matching_parts DESC, b.build_id DESC
        LIMIT 5
    ";

    $stmt = $conn->prepare($sql);

    // Check if prepare() failed
    if (!$stmt) {
        throw new Exception("Database prepare statement failed.");
    }

    $params = array_merge([$car_id], $part_ids);
    $types = 'i' . str_repeat('i', count($part_ids));

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $result = $stmt->get_result();
    $builds = $result->fetch_all(MYSQLI_ASSOC);

    // Return success response (200 OK is default)
    echo json_encode(['builds' => $builds]);

} catch (Exception $e) {
    // 4. Fail gracefully. Log the actual error, but send a generic message to the user for security.
    error_log($e->getMessage()); 
    http_response_code(500);
    echo json_encode(['error' => 'An internal server error occurred while fetching builds.']);
} finally {
    // 5. Clean up resources
    if (isset($stmt) && $stmt !== false) {
        $stmt->close();
    }
}
?>