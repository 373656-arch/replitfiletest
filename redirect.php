<?php
require_once 'config.php';

if (!isset($_GET['part_id'])) {
    header('Location: /index.php');
    exit;
}

$part_id = (int)$_GET['part_id'];

$stmt = $conn->prepare("
    SELECT p.link, a.base_url 
    FROM parts p 
    LEFT JOIN affiliate_sources a ON p.source_id = a.source_id 
    WHERE p.part_id = ?
");
$stmt->bind_param("i", $part_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: /index.php');
    exit;
}

$part = $result->fetch_assoc();
$affiliate_url = $part['base_url'] . $part['link'];

$user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$stmt = $conn->prepare("INSERT INTO click_logs (part_id, user_id, ip_address) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $part_id, $user_id, $ip_address);
$stmt->execute();

header('Location: ' . $affiliate_url);
exit;
?>
