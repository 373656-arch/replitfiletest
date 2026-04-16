<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$car_id = isset($input['car_id']) ? (int)$input['car_id'] : 0;

if ($car_id <= 0) {
    echo json_encode(['error' => 'Invalid car ID']);
    exit;
}

$stmt = $conn->prepare("SELECT name, brand, model, year, engine_code, stock_hp FROM cars WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();

if (!$car) {
    echo json_encode(['error' => 'Car not found']);
    exit;
}

if (!is_null($car['stock_hp']) && $car['stock_hp'] > 0) {
    echo json_encode(['stock_hp' => (int)$car['stock_hp']]);
    exit;
}

$apiKey = $_ENV['GROQ_LLM'] ?? getenv('GROQ_LLM');

if (empty($apiKey)) {
    echo json_encode(['error' => 'API key not configured']);
    exit;
}

$car_name = $car['name'];
$engine_hint = !empty($car['engine_code']) ? " (Engine: {$car['engine_code']})" : '';

$prompt = "You are an automotive performance database. Provide the exact factory stock horsepower (at the crank) for this specific car in its completely stock, unmodified configuration.\n\nCar: {$car_name}{$engine_hint}\n\nReply with ONLY a single integer number representing the factory horsepower. No text, no units, no explanation — just the number.";

$payload = json_encode([
    'model' => 'llama-3.1-8b-instant',
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 10,
    'temperature' => 0.1
]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || $response === false) {
    echo json_encode(['error' => 'Failed to reach AI service']);
    exit;
}

$data = json_decode($response, true);
$text = trim($data['choices'][0]['message']['content'] ?? '');
$stock_hp = (int) preg_replace('/[^0-9]/', '', $text);

if ($stock_hp <= 0) {
    echo json_encode(['error' => 'Could not determine stock horsepower']);
    exit;
}

$update = $conn->prepare("UPDATE cars SET stock_hp = ? WHERE car_id = ?");
$update->bind_param("ii", $stock_hp, $car_id);
$update->execute();

echo json_encode(['stock_hp' => $stock_hp]);
