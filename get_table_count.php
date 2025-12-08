

<?php require_once 'config.php'; if (!isLoggedIn()) { header('Location: /user/login.php'); exit; } $user = getUserData($_SESSION['user_id']); if (!isAdmin($user['email'])) { header('Location: /index.php'); exit; } $section = $_GET['section'] ?? 'dashboard'; $success = ''; $error =

pasted

Using the code attached create a visual respentation of the SQL database that is kept up to date automatically at all times. Should be a seperate section. Send full file

I'll create a comprehensive database visualization section for your admin panel that automatically updates in real-time.

admin.php with Database Visualization
Code 
Continue

I need to continue with the rest of the file. Let me update it:

admin.php with Database Visualization
Code 
Now I need to create the two supporting PHP files that will handle the AJAX requests for real-time updates:

get_table_count.php
Code 
get_db_metrics.php
Code 
Perfect! I've created a complete database visualization system for your admin panel. Here's what I've added:

New Features:
1. Database Visualization Section (new navigation item)
Entity Relationship Diagram (ERD): Interactive SVG diagram showing all 7 database tables and their relationships with color-coded nodes
Real-time Table Statistics: Live-updating cards showing record counts for all tables
Detailed Table Views: Expandable accordion sections for each table showing the latest 100 records
Relationship Metrics: Key database statistics like average parts per car, total compatibility records, etc.
2. Auto-Refresh System
Updates every 10 seconds automatically
No page reload required
Smooth animations on data changes
3. Three Files Total:
admin.php (updated) - Main admin panel with new database section
get_table_count.php (new) - AJAX endpoint for real-time table counts
get_db_metrics.php (new) - AJAX endpoint for relationship metrics
Key Features:
✅ Visual ER diagram with relationship arrows
✅ Color-coded tables for e







<?php
require_once 'config.php';

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = getUserData($_SESSION['user_id']);
if (!isAdmin($user['email'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

$table = $_GET['table'] ?? '';

// Whitelist of allowed tables for security
$allowed_tables = ['users', 'cars', 'parts', 'builds', 'build_parts', 'comments', 'user_likes', 'user_saved_builds', 'part_compatibility', 'affiliate_sources'];

if (!in_array($table, $allowed_tables)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid table']);
    exit;
}

try {
    $result = $conn->query("SELECT COUNT(*) as count FROM $table");
    $count = $result->fetch_assoc()['count'];

    echo json_encode(['count' => $count]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>