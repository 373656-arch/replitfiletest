<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$car_name = trim($input['car'] ?? '');
$parts = $input['parts'] ?? [];
$stock_hp = isset($input['stock_hp']) ? (int)$input['stock_hp'] : 0;

if (empty($car_name)) {
    echo json_encode(['error' => 'No car selected']);
    exit;
}

$apiKey = $_ENV['GROQ_LLM'] ?? getenv('GROQ_LLM');

if (empty($apiKey)) {
    echo json_encode(['error' => 'API key not configured']);
    exit;
}

if (empty($parts)) {
    if ($stock_hp > 0) {
        echo json_encode(['hp' => $stock_hp]);
        exit;
    }
    echo json_encode(['error' => 'No parts added']);
    exit;
}

$parts_list = implode(', ', array_map(fn($p) => $p['name'], $parts));

if ($stock_hp > 0) {
    $prompt = "You are an automotive performance expert with precise knowledge of horsepower gains from aftermarket modifications.\n\nCar: {$car_name}\nFactory stock horsepower: {$stock_hp} HP\nModifications installed: {$parts_list}\n\nBased on the factory stock horsepower of {$stock_hp} HP, calculate the realistic total horsepower after all listed modifications. Consider realistic dyno-proven gains for each mod type (e.g., intake +5-15hp, exhaust +5-20hp, tune +20-50hp, turbo upgrade +50-150hp, etc.) and account for synergistic effects between mods.\n\nReply with ONLY a single integer number — the total estimated horsepower after modifications. Nothing else.";
} else {
    $prompt = "You are an automotive performance expert. Given the car and modifications listed, estimate the realistic total horsepower output.\n\nCar: {$car_name}\nModifications: {$parts_list}\n\nReply with ONLY a single integer number representing the total estimated horsepower. Nothing else.";
}

$payload = json_encode([
    'model' => 'llama-3.1-8b-instant',
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 10,
    'temperature' => 0.2
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
$hp = (int) preg_replace('/[^0-9]/', '', $text);

if ($hp <= 0) {
    echo json_encode(['error' => 'Could not estimate horsepower']);
    exit;
}

if ($stock_hp > 0 && $hp < $stock_hp) {
    $hp = $stock_hp;
}

echo json_encode(['hp' => $hp]);
