<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$parts = $input['parts'] ?? [];
$stock_hp = isset($input['stock_hp']) ? (int)$input['stock_hp'] : 0;

if (empty($parts)) {
    if ($stock_hp > 0) {
        echo json_encode(['hp' => $stock_hp]);
        exit;
    }
    echo json_encode(['error' => 'No parts added']);
    exit;
}

$part_ids = array_map(fn($p) => (int)$p['id'], $parts);
$part_ids = array_filter($part_ids, fn($id) => $id > 0);

if (empty($part_ids)) {
    echo json_encode(['hp' => $stock_hp > 0 ? $stock_hp : 0]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($part_ids), '?'));
$types = str_repeat('i', count($part_ids));

$stmt = $conn->prepare("SELECT SUM(hp_gain) as total_gain FROM parts WHERE part_id IN ($placeholders)");
$stmt->bind_param($types, ...$part_ids);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

$total_gain = (int)($result['total_gain'] ?? 0);
$hp = $stock_hp > 0 ? $stock_hp + $total_gain : $total_gain;

if ($hp <= 0) {
    echo json_encode(['error' => 'Could not estimate horsepower']);
    exit;
}

echo json_encode(['hp' => $hp]);
