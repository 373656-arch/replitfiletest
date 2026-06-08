<?php
// Delay config loading to protect your Hostinger connection limit!
header('Content-Type: application/json');

// 1. Verify request method before opening a connection
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 2. Parse and validate input
$input = json_decode(file_get_contents('php://input'), true);
$car_id = isset($input['car_id']) ? (int)$input['car_id'] : 0;

if ($car_id <= 0) {
    echo json_encode(['error' => 'Invalid car ID']);
    exit;
}

// =========================================================================
// CRITICAL POINT: Request is valid and verified. Safe to use the database.
// =========================================================================
require_once 'config.php';

$stmt = $conn->prepare("SELECT stock_hp FROM cars WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();

if (!$car) {
    echo json_encode(['error' => 'Car not found']);
    exit;
}

$stock_hp = (int)($car['stock_hp'] ?? 0);

if ($stock_hp <= 0) {
    echo json_encode(['error' => 'Stock HP not available for this car']);
    exit;
}

echo json_encode(['stock_hp' => $stock_hp]);