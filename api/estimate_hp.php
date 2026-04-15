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

if (empty($car_name)) {
    echo json_encode(['error' => 'No car selected']);
    exit;
}

$apiKey = $_ENV['GROQ_LLM'] ?? getenv('GROQ_LLM');

if (empty($apiKey)) {
    echo json_encode(['error' => 'API key not configured']);
    exit;
}

$parts_list = empty($parts)
    ? 'No modifications yet (stock)'
    : implode(', ', array_map(fn($p) => $p['name'], $parts));

$prompt = "You are an automotive performance expert. Given the car and modifications listed, estimate the total horsepower output as a single integer number. Reply with ONLY the number, nothing else.\n\nCar: {$car_name}\nModifications: {$parts_list}";

$payload = json_encode([
    'model' => 'llama-3.1-8b-instant',
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
$hp = (int) preg_replace('/[^0-9]/', '', $text);

if ($hp <= 0) {
    echo json_encode(['error' => 'Could not estimate horsepower']);
    exit;
}

echo json_encode(['hp' => $hp]);
