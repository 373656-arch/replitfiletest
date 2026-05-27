<?php
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$user_message  = trim($input['message'] ?? '');
$car           = $input['car'] ?? [];
$available_parts = $input['available_parts'] ?? [];
$current_build = $input['current_build'] ?? [];

if (empty($user_message)) {
    echo json_encode(['error' => 'Message is required']);
    exit;
}

$groq_api_key = getenv('GROQ_LLM');
if (!$groq_api_key) {
    echo json_encode(['error' => 'AI service is not configured']);
    exit;
}

// Build a compact parts list for the prompt
$parts_summary = [];
foreach ($available_parts as $p) {
    $parts_summary[] = [
        'id'       => (int)$p['id'],
        'name'     => $p['name'],
        'category' => $p['category'],
        'price'    => (float)$p['price'],
        'hp_gain'  => (int)($p['hp_gain'] ?? 0),
        'compatible' => (bool)($p['compatible'] ?? true),
    ];
}

$current_ids = array_map(fn($p) => (int)$p['part_id'], $current_build);
$current_names = array_map(fn($p) => $p['name'], $current_build);

$system_prompt = <<<PROMPT
You are an AI car build assistant for ModMyCar, a car modification platform.
Your job is to help users build their car by selecting specific parts from a predefined list.

You will receive:
- The user's car details
- A list of available parts (with IDs, names, categories, prices, and HP gains)
- The user's current build parts
- The user's request

You MUST respond with a JSON object ONLY — no markdown, no code fences, no extra text.

The JSON format is:
{
  "message": "A friendly, concise explanation of what you're doing and why (1-3 sentences). Mention the specific parts you chose.",
  "actions": [
    {"action": "add", "part_id": 123},
    {"action": "remove", "part_id": 456}
  ]
}

Rules:
- Only reference part IDs from the provided available_parts list.
- Only one part per category is allowed in a build. If the user asks to add a category that already exists in the build, include a "remove" action for the old part before the "add".
- If the user asks to remove a part, only include "remove" actions.
- If the user says something like "clear", "start over", or "reset", remove all current build parts.
- Prefer compatible parts when possible. If a part is marked compatible: false, only add it if explicitly asked.
- If you cannot fulfil the request (e.g., no matching parts available), set "actions" to [] and explain in "message".
- Keep your message friendly and enthusiastic but brief.
PROMPT;

$user_content = json_encode([
    'car'             => $car,
    'available_parts' => $parts_summary,
    'current_build'   => ['part_ids' => $current_ids, 'part_names' => $current_names],
    'user_request'    => $user_message,
], JSON_PRETTY_PRINT);

$payload = [
    'model' => 'llama-3.3-70b-versatile',
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $user_content],
    ],
    'temperature'     => 0.4,
    'max_tokens'      => 512,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $groq_api_key,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $http_code !== 200) {
    echo json_encode(['error' => 'AI service unavailable. Please try again.']);
    exit;
}

$data = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? null;

if (!$content) {
    echo json_encode(['error' => 'No response from AI']);
    exit;
}

$result = json_decode($content, true);
if (!$result || !isset($result['message'])) {
    echo json_encode(['error' => 'Could not parse AI response']);
    exit;
}

echo json_encode([
    'message' => $result['message'],
    'actions' => $result['actions'] ?? [],
]);
