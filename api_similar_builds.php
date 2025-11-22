<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$car_id = isset($_POST['car_id']) ? (int)$_POST['car_id'] : 0;
$part_ids = isset($_POST['part_ids']) ? $_POST['part_ids'] : [];

if (!$car_id || !is_array($part_ids) || count($part_ids) === 0) {
    echo json_encode(['builds' => []]);
    exit;
}

$part_ids = array_map('intval', $part_ids);
$placeholders = implode(',', array_fill(0, count($part_ids), '?'));

$stmt = $conn->prepare("
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
");

$params = array_merge([$car_id], $part_ids);
$types = 'i' . str_repeat('i', count($part_ids));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$builds = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode(['builds' => $builds]);
?>
