<?php
require_once 'config.php';
if (!isLoggedIn()) {
    header('Location: /user/login.php');
    exit;
}
$user = getUserData($_SESSION['user_id']);
if (!isAdmin($user['email'])) {
    header('Location: /index.php');
    exit;
}

$section = $_GET['section'] ?? 'dashboard';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CAR MANAGEMENT ---
    if (isset($_POST['add_car'])) {
        $brand = trim($_POST['brand']);
        $model = trim($_POST['model']);
        $year = (int)$_POST['year'];
        $name = "$year $brand $model";
        $image = trim($_POST['image'] ?? '');
        $engine_code = trim($_POST['engine_code'] ?? '');
        $chassis_code = trim($_POST['chassis_code'] ?? '');

        $stmt = $conn->prepare("INSERT INTO cars (brand, model, year, name, image, engine_code, chassis_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssissss", $brand, $model, $year, $name, $image, $engine_code, $chassis_code);
        if ($stmt->execute()) { $success = "Car added!"; }
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
    /* Admin Specific Styling to fix visibility issues */
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

    /* FIX FOR THE "INVISIBLE" BOX */
    .smart-rules-box { 
        background: #0f3460; 
        padding: 20px; 
        border-radius: 8px; 
        margin: 20px 0; 
        border: 1px dashed #00d4ff; 
    }
    .smart-rules-box h5 { color: #00d4ff; margin-top: 0; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; }

    .btn { background: #00d4ff; color: #000; border: none; padding: 12px 25px; font-weight: bold; cursor: pointer; border-radius: 4px; width: 100%; }
    .btn:hover { background: #008fb3; }

    .compat-list { background: #0f3460; max-height: 200px; overflow-y: auto; padding: 10px; border-radius: 4px; }
    .compat-item { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 0.85rem; }
</style>

<div class="container admin-container">
    <aside class="admin-sidebar">
        <h3>Navigation</h3>
        <nav class="admin-nav">
            <a href="?section=dashboard" class="<?= $section === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <a href="?section=cars" class="<?= $section === 'cars' ? 'active' : '' ?>">Car Management</a>
            <a href="?section=parts" class="<?= $section === 'parts' ? 'active' : '' ?>">Part Management</a>
            <a href="?section=sources" class="<?= $section === 'sources' ? 'active' : '' ?>">Affiliate Sources</a>
        </nav>
    </aside>

    <main class="admin-main">
        <?php if ($success): ?> <div style="background: #28a745; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?= $success ?></div> <?php endif; ?>

        <?php if ($section === 'parts'): ?>
            <div class="card">
                <h2>Add New Part</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Part Name</label>
                        <input type="text" name="name" placeholder="e.g. Borla S-Type Exhaust" required>
                    </div>

                    <div style="display: flex; gap: 15px;">
                        <div class="form-group" style="flex: 1;"><label>Price ($)</label><input 
                            type="number" 
                            step="0.01" 
                            min="0" 
                            name="price" 
                            required
                        >
</div>
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