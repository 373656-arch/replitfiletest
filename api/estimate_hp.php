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
    $prompt = "You are an automotive performance expert with precise knowledge of dyno-proven horsepower gains from aftermarket modifications.\n\nCar: {$car_name} (stock: {$stock_hp} HP)\nModifications installed: {$parts_list}\n\nEstimate the TOTAL ADDITIONAL horsepower GAINED from these modifications combined (not the total output). Use realistic dyno-proven gains, for example: cold air intake +5-15hp, cat-back exhaust +5-20hp, headers +10-25hp, ECU tune +20-50hp, downpipe +15-40hp, turbo upgrade +50-150hp, supercharger +80-200hp, nitrous +50-200hp, larger injectors alone +0hp, etc. Account for synergy between supporting mods.\n\nReply with ONLY a single positive integer — the horsepower GAIN to add on top of stock. No words, no units, no explanation. Just the number.";
} else {
    $prompt = "You are an automotive performance expert. Estimate the realistic total horsepower output for this modified car.\n\nCar: {$car_name}\nModifications: {$parts_list}\n\nReply with ONLY a single integer number representing the total estimated horsepower. Nothing else.";
}

$payload = json_encode([
    'model' => 'llama-3.3-70b-versatile',
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 10,
    'temperature' => 0.3
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
$num = (int) preg_replace('/[^0-9]/', '', $text);

if ($num <= 0) {
    echo json_encode(['error' => 'Could not estimate horsepower']);
    exit;
}

if ($stock_hp > 0) {
    if ($num < $stock_hp) {
        $hp = $stock_hp + $num;
    } else {
        $gain = $num - $stock_hp;
        $hp = $stock_hp + max($gain, 5);
    }
} else {
    $hp = $num;
}

echo json_encode(['hp' => $hp]);
