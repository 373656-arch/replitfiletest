<?php
require_once 'config.php';

// --- PROFANITY FILTER CONFIGURATION ---
// EDIT THIS LIST: Add words you want to block inside the array.
function containsProfanity($text) {
    $banned_words = [
        'badword', 'offensive', 'spam', 'scam', 'hate', 'stupid', 'idiot', 
        'garbage', 'trash', 'fake', 'dummy' 
        // Add more words here as needed
    ]; 

    foreach ($banned_words as $word) {
        // checks case-insensitive
        if (stripos($text, $word) !== false) {
            return true;
        }
    }
    return false;
}

if (!isLoggedIn()) {
    header('Location: /user/login.php');
    exit;
}
$user = getUserData($_SESSION['user_id']);
if (!isAdmin($user['email'])) {
    header('Location: /index.php');
    exit;
}

// --- AJAX ENDPOINT FOR LIVE REFRESH ---
// This handles the background requests so the page doesn't have to reload
if (isset($_GET['ajax_refresh']) && $_GET['ajax_refresh'] === '1') {
    header('Content-Type: application/json');
    $tables = ['users', 'user_saved_builds', 'click_logs', 'cars', 'parts', 'part_compatibility', 'affiliate_sources'];
    $counts = [];
    foreach($tables as $t) {
        $res = $conn->query("SELECT COUNT(*) as c FROM $t");
        $counts[$t] = $res ? $res->fetch_assoc()['c'] : 0;
    }
    echo json_encode($counts);
    exit; // Stop executing the rest of the page layout
}

$section = $_GET['section'] ?? 'dashboard';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- MESSAGE MANAGEMENT ---
    if (isset($_POST['delete_message'])) {
        $msg_id = (int)$_POST['message_id'];
        $stmt = $conn->prepare("DELETE FROM admin_messages WHERE message_id = ?");
        $stmt->bind_param("i", $msg_id);
        if ($stmt->execute()) {
            $success = "Message deleted successfully.";
        } else {
            $error = "Failed to delete message.";
        }
    }

    // --- CAR MANAGEMENT (Updated) ---
    if (isset($_POST['add_car'])) {

        // 1. Collect & Sanitize
        $name = trim($_POST['name']);
        $brand = trim($_POST['brand']);
        $model = trim($_POST['model']);
        $year = (int)$_POST['year'];
        $trim_level = trim($_POST['trim_level'] ?? '');
        $engine_code = trim($_POST['engine_code'] ?? '');
        $chassis_code = trim($_POST['chassis_code'] ?? '');

        // 2. Profanity Check
        if (containsProfanity($name) || containsProfanity($brand) || containsProfanity($model) || containsProfanity($trim_level)) {
            $error = "Error: Submission blocked due to prohibited language.";
        } else {
            // 3. File Upload Handling
            $imagePath = '';

            // Check if a file was actually uploaded without errors
            if (isset($_FILES['car_image']) && $_FILES['car_image']['error'] === 0) {
                $uploadDir = 'uploads/'; // Local folder

                // Create folder if it doesn't exist
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

                // Generate unique name to prevent overwriting
                $fileName = time() . '_' . basename($_FILES['car_image']['name']);
                $targetFile = $uploadDir . $fileName;

                // Validate File Type
                $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array($fileType, $allowedTypes)) {
                    if (move_uploaded_file($_FILES['car_image']['tmp_name'], $targetFile)) {
                        $imagePath = $targetFile; // This path goes into DB
                    } else {
                        $error = "Failed to save the uploaded file.";
                    }
                } else {
                    $error = "Invalid file type. Only JPG, PNG, GIF, WEBP allowed.";
                }
            }

            // 4. Database Insert (Only if no errors so far)
            if (empty($error)) {
                $stmt = $conn->prepare("INSERT INTO cars (brand, model, year, name, trim_level, image, engine_code, chassis_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                // "image" column gets the file path now
                $stmt->bind_param("ssisssss", $brand, $model, $year, $name, $trim_level, $imagePath, $engine_code, $chassis_code);

                if ($stmt->execute()) { 
                    $success = "Car added successfully!"; 
                } else {
                    $error = "Database Error: " . $conn->error;
                }
            }
        }
    }

    // --- PART MANAGEMENT ---
    if (isset($_POST['add_part'])) {
        $name = trim($_POST['name']);
        $price = (float)$_POST['price'];
        $category = $_POST['category'];
        $description = trim($_POST['description']);
        $link = trim($_POST['link']);
        $engine_code = !empty($_POST['engine_code']) ? trim($_POST['engine_code']) : null;
        $chassis_code = !empty($_POST['chassis_code']) ? trim($_POST['chassis_code']) : null;
        $year_start = !empty($_POST['year_start']) ? (int)$_POST['year_start'] : null;
        $year_end = !empty($_POST['year_end']) ? (int)$_POST['year_end'] : null;

        $stmt = $conn->prepare("INSERT INTO parts (name, price, category, description, link, engine_code, chassis_code, year_start, year_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sdsssssii", $name, $price, $category, $description, $link, $engine_code, $chassis_code, $year_start, $year_end);

        if ($stmt->execute()) {
            $success = "Part added with Smart Rules!";
        }
    }
}

$pageTitle = "Admin Panel - ModMyCar";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<style>
    /* Admin Specific Styling */
    .admin-container { display: flex; gap: 30px; margin-top: 20px; color: #fff; }
    .admin-sidebar { width: 250px; background: #1a1a2e; padding: 20px; border-radius: 8px; height: fit-content; }
    .admin-sidebar h3 { margin-bottom: 20px; color: #00d4ff; border-bottom: 1px solid #333; padding-bottom: 10px; }
    .admin-nav { display: flex; flex-direction: column; gap: 10px; }
    .admin-nav a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; transition: 0.3s; }
    .admin-nav a:hover, .admin-nav a.active { background: #00d4ff; color: #000; }

    .admin-main { flex: 1; background: #1a1a2e; padding: 30px; border-radius: 8px; }
    .card { background: #16213e; padding: 20px; border-radius: 8px; margin-bottom: 20px; }

    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; color: #aaa; font-size: 0.9rem; }
    input, select, textarea { width: 100%; padding: 12px; background: #0f3460; border: 1px solid #1a1a2e; color: #fff; border-radius: 4px; }

    /* Layout for Car Management */
    .car-split-view { display: flex; gap: 25px; }

    /* LEFT SIDE: INSTRUCTIONS */
    .car-instructions { 
        flex: 1; 
        background: #0f3460; 
        padding: 20px; 
        border-radius: 8px; 
        border-left: 3px solid #00d4ff; 
        height: fit-content;
    }
    .car-instructions h4 { color: #00d4ff; margin-top: 0; margin-bottom: 15px; }
    .car-instructions p { font-size: 0.9rem; color: #ccc; line-height: 1.6; margin-bottom: 10px; }
    .car-instructions strong { color: #fff; }

    /* RIGHT SIDE: FORM INPUTS */
    .car-form-area { flex: 2; }

    /* Smart Rules Box Styling (reused) */
    .smart-rules-box { background: #0f3460; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px dashed #00d4ff; }
    .smart-rules-box h5 { color: #00d4ff; margin-top: 0; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; }

    .btn { background: #00d4ff; color: #000; border: none; padding: 12px 25px; font-weight: bold; cursor: pointer; border-radius: 4px; width: 100%; }
    .btn:hover { background: #008fb3; }

    .compat-list { background: #0f3460; max-height: 200px; overflow-y: auto; padding: 10px; border-radius: 4px; }
    .compat-item { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 0.85rem; }

    /* Message Cards */
    .message-card { background: #0f3460; padding: 20px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #00d4ff; }
    .message-header { display: flex; justify-content: space-between; border-bottom: 1px solid #1a1a2e; padding-bottom: 10px; margin-bottom: 15px; }
    .btn-danger { background: #dc3545; color: white; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.8rem; }
    .btn-danger:hover { background: #c82333; }
    .message-card.highlight-msg { 
        border-left: 4px solid #22c55e; 
        background: #133a54; 
    }
    .badge-yours {
        background: #22c55e;
        color: #000;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: bold;
        margin-left: 10px;
        vertical-align: middle;
    }

    /* Background Refresh Spinner CSS */
    .refresh-spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 3px solid rgba(0, 212, 255, 0.2);
        border-radius: 50%;
        border-top-color: #00d4ff;
        animation: spin 1s ease-in-out infinite;
        vertical-align: middle;
        margin-left: 12px;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    .refresh-spinner.active {
        opacity: 1;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>

<div class="container admin-container">
    <aside class="admin-sidebar">
        <h3>Navigation</h3>
        <nav class="admin-nav">
            <a href="?section=dashboard" class="<?= $section === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <a href="?section=messages" class="<?= $section === 'messages' ? 'active' : '' ?>">Messages</a>
            <a href="?section=cars" class="<?= $section === 'cars' ? 'active' : '' ?>">Car Management</a>
            <a href="?section=parts" class="<?= $section === 'parts' ? 'active' : '' ?>">Part Management</a>
            <a href="?section=sources" class="<?= $section === 'sources' ? 'active' : '' ?>">Affiliate Sources</a>
            <a href="?section=database_visualization" class="<?= $section === 'database_visualization' ? 'active' : '' ?>">Database Visualization</a>
            <a href="?section=monetization" class="<?= $section === 'monetization' ? 'active' : '' ?>">Monetization</a>
        </nav>
    </aside>

    <main class="admin-main">
        <?php if ($success): ?> <div style="background: #28a745; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?= $success ?></div> <?php endif; ?>
        <?php if ($error): ?> <div style="background: #dc3545; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?= $error ?></div> <?php endif; ?>

        <?php if ($section === 'monetization'): ?>
            <div class="card">
                <h2>Monetization Tracking</h2>
                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

                <?php
                // Fetch stats
                $click_count = $conn->query("SELECT COUNT(*) as total FROM click_logs")->fetch_assoc()['total'];
                $conv_res = $conn->query("SELECT COUNT(*) as total, SUM(commission_amount) as revenue FROM conversions")->fetch_assoc();
                $conv_count = $conv_res['total'];
                $total_revenue = $conv_res['revenue'] ?? 0;
                $conv_rate = $click_count > 0 ? ($conv_count / $click_count) * 100 : 0;

                // Daily data for chart
                $daily_data = $conn->query("
                    SELECT DATE(conversion_date) as day, SUM(commission_amount) as amount 
                    FROM conversions 
                    GROUP BY day 
                    ORDER BY day ASC 
                    LIMIT 30
                ")->fetch_all(MYSQLI_ASSOC);

                $labels = json_encode(array_column($daily_data, 'day'));
                $values = json_encode(array_column($daily_data, 'amount'));
                ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    <div class="card" style="background: #0f3460; text-align: center;">
                        <h4 style="color: #00d4ff;">Total Clicks</h4>
                        <p style="font-size: 2rem; margin: 10px 0;"><?= $click_count ?></p>
                    </div>
                    <div class="card" style="background: #0f3460; text-align: center;">
                        <h4 style="color: #00d4ff;">Conversions</h4>
                        <p style="font-size: 2rem; margin: 10px 0;"><?= $conv_count ?></p>
                    </div>
                    <div class="card" style="background: #0f3460; text-align: center;">
                        <h4 style="color: #00d4ff;">Revenue</h4>
                        <p style="font-size: 2rem; margin: 10px 0;">$<?= number_format($total_revenue, 2) ?></p>
                    </div>
                    <div class="card" style="background: #0f3460; text-align: center;">
                        <h4 style="color: #00d4ff;">Conv. Rate</h4>
                        <p style="font-size: 2rem; margin: 10px 0;"><?= number_format($conv_rate, 1) ?>%</p>
                    </div>
                </div>

                <div class="card" style="background: #0f3460;">
                    <h3>Revenue Over Time (Last 30 Days)</h3>
                    <canvas id="revenueChart" style="max-height: 400px;"></canvas>
                </div>

                <script>
                    const ctx = document.getElementById('revenueChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?= $labels ?>,
                            datasets: [{
                                label: 'Commission Revenue ($)',
                                data: <?= $values ?>,
                                borderColor: '#00d4ff',
                                backgroundColor: 'rgba(0, 212, 255, 0.1)',
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { labels: { color: '#fff' } }
                            },
                            scales: {
                                y: { grid: { color: '#333' }, ticks: { color: '#aaa' } },
                                x: { grid: { color: '#333' }, ticks: { color: '#aaa' } }
                            }
                        }
                    });
                </script>
            </div>

        <?php elseif ($section === 'database_visualization'): ?>
            <div class="card">
                <h2>
                    Database Visualization 
                    <div id="loading-spinner" class="refresh-spinner"></div>
                </h2>
                <p style="color: #22c55e; font-size: 0.85rem; margin-top: -10px; margin-bottom: 20px;">
                    ● Live Updating smoothly in background (10s)
                </p>

                <?php
                // Initial page load data
                $tables = ['users', 'user_saved_builds', 'click_logs', 'cars', 'parts', 'part_compatibility', 'affiliate_sources'];
                $counts = [];

                foreach($tables as $t) {
                    $res = $conn->query("SELECT COUNT(*) as c FROM $t");
                    $counts[$t] = $res ? $res->fetch_assoc()['c'] : 0;
                }
                ?>

                <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 30px;">
                    <?php foreach($counts as $table => $count): ?>
                        <div style="background: #0f3460; border-left: 4px solid #00d4ff; padding: 20px; border-radius: 8px; flex: 1 1 calc(25% - 15px); min-width: 150px;">
                            <h4 style="margin: 0; color: #aaa; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px;"><?= htmlspecialchars($table) ?></h4>
                            <p id="count-<?= htmlspecialchars($table) ?>" style="font-size: 2rem; margin: 10px 0 0 0; color: #fff; font-weight: bold; transition: color 0.3s;"><?= $count ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="background: #132a4a; padding: 20px; border-radius: 8px; border: 1px solid #1a1a2e;">
                    <h3 style="color: #00d4ff; margin-top: 0;">Entity Relationships</h3>
                    <ul style="color: #ccc; line-height: 2; list-style-type: none; padding: 0;">
                        <li>🔑 <strong>users</strong> ➔ <em>Links to:</em> user_saved_builds, click_logs</li>
                        <li>🔑 <strong>cars</strong> ➔ <em>Links to:</em> user_saved_builds, part_compatibility</li>
                        <li>🔑 <strong>parts</strong> ➔ <em>Links to:</em> click_logs, part_compatibility</li>
                        <li>🔑 <strong>affiliate_sources</strong> ➔ <em>Links to:</em> parts</li>
                    </ul>
                </div>
            </div>

            <script>
                setInterval(function() {
                    const spinner = document.getElementById('loading-spinner');

                    // Show the spinner
                    spinner.classList.add('active');

                    // Fetch the updated data silently
                    fetch('?section=database_visualization&ajax_refresh=1')
                        .then(response => response.json())
                        .then(data => {
                            // Update the numbers on the screen
                            for (const [table, count] of Object.entries(data)) {
                                const countElement = document.getElementById('count-' + table);
                                if (countElement) {
                                    // Make the text flash green slightly if the number changed
                                    if (countElement.innerText !== String(count)) {
                                        countElement.style.color = '#22c55e';
                                        setTimeout(() => countElement.style.color = '#fff', 1000);
                                    }
                                    countElement.innerText = count;
                                }
                            }

                            // Hide the spinner after a brief moment
                            setTimeout(() => {
                                spinner.classList.remove('active');
                            }, 500);
                        })
                        .catch(error => {
                            console.error('Error fetching live data:', error);
                            spinner.classList.remove('active');
                        });

                }, 10000); // 10000 ms = 10 seconds
            </script>

        <?php elseif ($section === 'messages'): ?>
            <div class="card">
                <h2>Admin Messages</h2>
                <p style="color: #aaa; margin-bottom: 20px;">Contact form submissions from users.</p>

                <?php
                $messages = $conn->query("SELECT * FROM admin_messages ORDER BY date_sent DESC");

                if ($messages && $messages->num_rows > 0) {
                    while ($msg = $messages->fetch_assoc()) {

                        // Check if this message is for the logged-in admin
                        $isForMe = ($msg['target_admin_email'] === $user['email']);

                        // Apply the highlight class if true
                        $cardClass = $isForMe ? "message-card highlight-msg" : "message-card";
                        ?>

                        <div class="<?= $cardClass ?>">
                            <div class="message-header">
                                <div>
                                    <h4 style="margin: 0; color: #fff;">From: <?= htmlspecialchars($msg['sender_name']) ?> <span style="font-weight: normal; color: #aaa;">(<?= htmlspecialchars($msg['sender_email']) ?>)</span></h4>

                                    <p style="margin: 5px 0 0 0; font-size: 0.9rem; color: #00d4ff;">
                                        <strong>Addressed to:</strong> <?= htmlspecialchars($msg['target_admin_email']) ?>

                                        <?php if ($isForMe): ?>
                                            <span class="badge-yours">For You</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div style="text-align: right;">
                                    <small style="color: #888; display: block; margin-bottom: 10px;"><?= date('M j, Y, g:i a', strtotime($msg['date_sent'])) ?></small>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this message?');">
                                        <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                                        <button type="submit" name="delete_message" class="btn-danger">Delete</button>
                                    </form>
                                </div>
                            </div>
                            <p style="color: #ccc; line-height: 1.6; margin: 0;">
                                <?= nl2br(htmlspecialchars($msg['message'])) ?>
                            </p>
                        </div>
                        <?php
                    }
                } else {
                    echo "<div style='background: #0f3460; padding: 20px; border-radius: 8px; text-align: center; color: #aaa;'>Your inbox is empty. No messages yet!</div>";
                }
                ?>
            </div>

        <?php elseif ($section === 'cars'): ?>

            <div class="card">
                <h2>Add New Car</h2>

                <div class="car-split-view">
                    <div class="car-instructions">
                        <h4>Instructions</h4>
                        <p><strong>Step 1:</strong> Type the full car name in the top box using the format: <br><em>"Year Brand Model"</em>.</p>
                        <p><strong>Example:</strong> <br><span style="color:#00d4ff">2021 BMW M340i</span></p>
                        <p>The system will try to auto-fill the Year, Brand, and Model boxes below.</p>
                        <p><strong>Step 2:</strong> Verify the auto-filled data. You can edit the boxes if they are incorrect.</p>
                        <p><strong>Step 3:</strong> Manually enter the Chassis Code, Engine Code, and Trim Level.</p>
                        <p><strong>Step 4:</strong> Upload a valid image file (JPG, PNG).</p>
                        <p style="font-size: 0.8rem; margin-top: 15px; color: #888;">* Offensive language will be blocked automatically.</p>
                    </div>

                    <div class="car-form-area">
                        <form method="POST" enctype="multipart/form-data">

                            <div class="form-group">
                                <label style="color: #00d4ff; font-weight: bold;">New Car Model Here</label>
                                <input 
                                    type="text" 
                                    id="mainInput" 
                                    name="name" 
                                    placeholder="e.g. 2021 BMW M340i" 
                                    required 
                                    onkeyup="attemptAutoFill()"
                                >
                            </div>

                            <div style="display: flex; gap: 15px;">
                                <div class="form-group" style="flex: 1;">
                                    <label>Year</label>
                                    <input type="number" id="year" name="year" placeholder="YYYY" required>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label>Brand</label>
                                    <input type="text" id="brand" name="brand" placeholder="Brand">
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label>Model</label>
                                    <input type="text" id="model" name="model" placeholder="Model">
                                </div>
                            </div>

                            <div class="smart-rules-box">
                                <h5>Technical Specs</h5>
                                <div style="display: flex; gap: 15px;">
                                    <div class="form-group" style="flex: 1;">
                                        <label>Engine Code</label>
                                        <input type="text" name="engine_code" placeholder="e.g. B58">
                                    </div>
                                    <div class="form-group" style="flex: 1;">
                                        <label>Chassis Code</label>
                                        <input type="text" name="chassis_code" placeholder="e.g. G20">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Trim Level</label>
                                    <input type="text" name="trim_level" placeholder="e.g. M-Sport, Premium">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Car Image (Upload File)</label>
                                <input type="file" name="car_image" accept="image/*" required>
                            </div>

                            <button type="submit" name="add_car" class="btn">Add Car</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                function attemptAutoFill() {
                    const input = document.getElementById('mainInput').value;
                    const yearBox = document.getElementById('year');
                    const brandBox = document.getElementById('brand');
                    const modelBox = document.getElementById('model');

                    // Regex looks for: Start -> 4 digits (Year) -> Space -> One Word (Brand) -> Space -> Rest (Model)
                    // Example: "2021 BMW M340i"
                    const regex = /^(\d{4})\s+([A-Za-z0-9]+)\s+(.*)$/;
                    const match = input.match(regex);

                    if (match) {
                        yearBox.value = match[1];  // 2021
                        brandBox.value = match[2]; // BMW
                        modelBox.value = match[3]; // M340i
                    }
                    // We do NOT clear the boxes if regex fails, so the user can manually type without fighting the script.
                }
            </script>

        <?php elseif ($section === 'parts'): ?>
            <div class="card">
                <h2>Add New Part</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Part Name</label>
                        <input type="text" name="name" placeholder="e.g. Borla S-Type Exhaust" required>
                    </div>

                    <div style="display: flex; gap: 15px;">
                        <div class="form-group" style="flex: 1;"><label>Price ($)</label><input type="number" step="0.01" min="0" name="price" required></div>
                        <div class="form-group" style="flex: 1;">
                            <label>Category</label>
                            <select name="category">
                                <option>Exhaust</option><option>Intake</option><option>Suspension</option><option>Brakes</option>
                            </select>
                        </div>
                    </div>

                    <div class="smart-rules-box">
                        <h5>Smart Compatibility Rules</h5>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;"><label>Engine Code</label><input type="text" name="engine_code" placeholder="B58"></div>
                            <div class="form-group" style="flex: 1;"><label>Chassis Code</label><input type="text" name="chassis_code" placeholder="G20"></div>
                        </div>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;"><label>Year Start</label><input type="number" name="year_start" placeholder="2019"></div>
                            <div class="form-group" style="flex: 1;"><label>Year End</label><input type="number" name="year_end" placeholder="2024"></div>
                        </div>
                    </div>

                    <div class="form-group"><label>Affiliate Link</label><input type="text" name="link" placeholder="/dp/XXXXX"></div>
                    <div class="form-group"><label>Description</label><textarea name="description" rows="3"></textarea></div>

                    <div class="form-group">
                        <label>Manual Compatibility Override</label>
                        <div class="compat-list">
                            <?php
                            $cars = $conn->query("SELECT car_id, name FROM cars ORDER BY name ASC");
                            while($car = $cars->fetch_assoc()): ?>
                                <div class="compat-item">
                                    <input type="checkbox" name="compatible_cars[]" value="<?= $car['car_id'] ?>" style="width: auto;">
                                    <span><?= htmlspecialchars($car['name']) ?></span>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <button type="submit" name="add_part" class="btn">Save Part</button>
                </form>
            </div>
        <?php else: ?>
            <h2>Welcome to Admin</h2>
            <p>Select a section from the sidebar to begin managing your database.</p>
        <?php endif; ?>
    </main>
</div>

<?php renderFooter(); ?>