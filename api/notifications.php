<?php
declare(strict_types=1);

// Route backwards to hit your clean configuration foundation
require_once '../config.php';

header('Content-Type: application/json');

// Ensure only authenticated users can access notification endpoints
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// --- HANDLE READ/POLLING FETCHES (GET) ---
if ($method === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'poll') {
        $since_id = (int)($_GET['since_id'] ?? 0);

        $count = getUnreadNotificationCount($uid);

        $new_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND id > ? ORDER BY created_at DESC LIMIT 10");
        $new_stmt->bind_param("ii", $uid, $since_id);
        $new_stmt->execute();
        $new_notifs = $new_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['count' => $count, 'new_notifications' => $new_notifs]);
        exit;
    }
}

// --- HANDLE STATUS DELETIONS/UPDATES (POST) ---
// --- HANDLE STATUS DELETIONS/UPDATES (POST) ---
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    $success = false;

    try {
        if ($action === 'mark_all_read') {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->bind_param("i", $uid);
            $success = $stmt->execute();
            $stmt->close();
        }

        elseif ($action === 'mark_single_read') {
            $notif_id = (int)($_POST['notif_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notif_id, $uid);
            $success = $stmt->execute();
            $stmt->close();
        }

        elseif ($action === 'clear_all') {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->bind_param("i", $uid);
            $success = $stmt->execute();
            $stmt->close();
        }

        if ($success) {
            echo json_encode(['success' => true]);
            exit;
        }

    } catch (Exception $e) {
        // Log the actual system crash details privately on the server log
        error_log("Notification API Error: " . $e->getMessage());

        http_response_code(500);
        echo json_encode(['error' => 'An internal database error occurred processing your update.']);
        exit;
    }
}