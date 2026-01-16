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
    if (isset($_POST['add_car'])) {
        $brand = trim($_POST['brand']);
        $model = trim($_POST['model']);
        $year = (int)$_POST['year'];
        $name = "$year $brand $model";
        $image = trim($_POST['image'] ?? '');

        $stmt = $conn->prepare("INSERT INTO cars (brand, model, year, name, image) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $brand, $model, $year, $name, $image);
        if ($stmt->execute()) {
            $success = "Car added successfully!";
        } else {
            $error = "Failed to add car.";
        }
    }

    if (isset($_POST['delete_car'])) {
        $car_id = (int)$_POST['car_id'];
        $stmt = $conn->prepare("DELETE FROM cars WHERE car_id = ?");
        $stmt->bind_param("i", $car_id);
        if ($stmt->execute()) {
            $success = "Car deleted successfully!";
        }
    }

    if (isset($_POST['add_part'])) {
        $name = trim($_POST['name']);
        $price = (float)$_POST['price'];
        $color = trim($_POST['color'] ?? '');
        $description = trim($_POST['description']);
        $image = trim($_POST['image'] ?? '');
        $link = trim($_POST['link']);
        $category = $_POST['category'];
        $source_id = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;

        $stmt = $conn->prepare("INSERT INTO parts (name, price, color, description, image, link, category, source_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sdsssssi", $name, $price, $color, $description, $image, $link, $category, $source_id);
        if ($stmt->execute()) {
            $part_id = $conn->insert_id;

            if (!empty($_POST['compatible_cars'])) {
                foreach ($_POST['compatible_cars'] as $car_id) {
                    $stmt2 = $conn->prepare("INSERT INTO part_compatibility (part_id, car_id) VALUES (?, ?)");
                    $stmt2->bind_param("ii", $part_id, $car_id);
                    $stmt2->execute();
                }
            }

            $success = "Part added successfully!";
        } else {
            $error = "Failed to add part.";
        }
    }

    if (isset($_POST['delete_part'])) {
        $part_id = (int)$_POST['part_id'];
        $stmt = $conn->prepare("DELETE FROM parts WHERE part_id = ?");
        $stmt->bind_param("i", $part_id);
        if ($stmt->execute()) {
            $success = "Part deleted successfully!";
        }
    }

    if (isset($_POST['add_source'])) {
        $source_name = trim($_POST['source_name']);
        $base_url = trim($_POST['base_url']);

        $stmt = $conn->prepare("INSERT INTO affiliate_sources (source_name, base_url) VALUES (?, ?)");
        $stmt->bind_param("ss", $source_name, $base_url);
        if ($stmt->execute()) {
            $success = "Affiliate source added successfully!";
        } else {
            $error = "Failed to add source.";
        }
    }

    if (isset($_POST['delete_source'])) {
        $source_id = (int)$_POST['source_id'];
        $stmt = $conn->prepare("UPDATE parts SET source_id = NULL WHERE source_id = ?");
        $stmt->bind_param("i", $source_id);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM affiliate_sources WHERE source_id = ?");
        $stmt->bind_param("i", $source_id);
        if ($stmt->execute()) {
            $success = "Source deleted successfully!";
        }
    }
}
$pageTitle = "Admin Panel - ModMyCar";
require_once 'includes/headerFooter.php';
renderHeader();
?>
<div class="container">
    <h2>Admin Panel</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <div class="admin-dashboard">
        <div class="admin-sidebar">
            <h3>Navigation</h3>
            <nav>
                <a href="?section=dashboard" class="<?php echo ($section === 'dashboard') ? 'active' : ''; ?>">Dashboard</a>
                <a href="?section=database" class="<?php echo ($section === 'database') ? 'active' : ''; ?>">Database Visualization</a>
                <a href="?section=cars" class="<?php echo ($section === 'cars') ? 'active' : ''; ?>">Car Management</a>
                <a href="?section=parts" class="<?php echo ($section === 'parts') ? 'active' : ''; ?>">Part Management</a>
                <a href="?section=sources" class="<?php echo ($section === 'sources') ? 'active' : ''; ?>">Affiliate Sources</a>
                <a href="?section=car_parts" class="<?php echo ($section === 'car_parts') ? 'active' : ''; ?>">Car Parts Visualization</a>
            </nav>
        </div>
        <div>
            <?php if ($section === 'database'): ?>
                <h3>Database Visualization</h3>
                <p style="margin-bottom: 20px; color: var(--text-secondary);">Real-time view of all database tables and their relationships. Auto-refreshes every 10 seconds.</p>
                <div class="db-viz-container">
                    <!-- Entity Relationship Diagram -->
                    <div class="card" style="margin-bottom: 30px;">
                        <h4>Entity Relationship Diagram</h4>
                        <div id="erDiagram" style="min-height: 500px; overflow: auto;">
                            <svg id="erSvg" width="100%" height="600"></svg>
                        </div>
                    </div>
                    <!-- Table Statistics -->
                    <div class="db-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                        <?php
                        $tables = ['users', 'cars', 'parts', 'builds', 'build_parts', 'comments', 'user_likes', 'user_saved_builds', 'part_compatibility', 'affiliate_sources'];
                        foreach ($tables as $table):
                            $count_query = $conn->query("SELECT COUNT(*) as count FROM $table");
                            $count = $count_query ? $count_query->fetch_assoc()['count'] : 0;
                        ?>
                            <div class="card" style="text-align: center;">
                                <h4><?php echo ucwords(str_replace('_', ' ', $table)); ?></h4>
                                <div style="font-size: 2.5em; font-weight: bold; color: #DC2626; margin: 10px 0;" id="count-<?php echo $table; ?>">
                                    <?php echo number_format($count); ?>
                                </div>
                                <div style="color: var(--text-secondary); font-size: 0.9em;">Total Records</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Detailed Table Views -->
                    <div class="card">
                        <h4>Table Details</h4>
                        <div id="tableAccordion">
                            <?php
                            $detailed_tables = [
                                'users' => ['uid', 'username', 'email', 'profileImage'],
                                'cars' => ['car_id', 'brand', 'model', 'year', 'name'],
                                'parts' => ['part_id', 'name', 'category', 'price', 'source_id'],
                                'builds' => ['build_id', 'user_id', 'car_id', 'build_title', 'total_price', 'is_community_shared', 'likes_count'],
                                'build_parts' => ['build_id', 'part_id', 'position_data'],
                                'comments' => ['comment_id', 'build_id', 'user_id', 'content', 'date_posted'],
                                'user_likes' => ['user_id', 'build_id'],
                                'user_saved_builds' => ['user_id', 'build_id', 'date_saved'],
                                'part_compatibility' => ['part_id', 'car_id'],
                                'affiliate_sources' => ['source_id', 'source_name', 'base_url']
                            ];
                            foreach ($detailed_tables as $table => $columns):
                                $result = $conn->query("SELECT * FROM $table ORDER BY " . $columns[0] . " DESC LIMIT 100");
                            ?>
                                <div class="table-accordion-item">
                                    <button class="accordion-header" onclick="toggleAccordion('<?php echo $table; ?>')">
                                        <span><?php echo ucwords(str_replace('_', ' ', $table)); ?></span>
                                        <span class="accordion-icon">▼</span>
                                    </button>
                                    <div id="accordion-<?php echo $table; ?>" class="accordion-content">
                                        <div style="overflow-x: auto;">
                                            <table style="width: 100%; font-size: 0.9em;">
                                                <thead>
                                                    <tr>
                                                        <?php foreach ($columns as $col): ?>
                                                            <th><?php echo htmlspecialchars($col); ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody id="tbody-<?php echo $table; ?>">
                                                    <?php
                                                    if ($result):
                                                        while ($row = $result->fetch_assoc()):
                                                    ?>
                                                        <tr>
                                                            <?php foreach ($columns as $col): ?>
                                                                <td><?php echo htmlspecialchars($row[$col] ?? 'NULL'); ?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php
                                                        endwhile;
                                                    endif;
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Relationship Metrics -->
                    <div class="card" style="margin-top: 30px;">
                        <h4>Database Relationships & Metrics</h4>
                        <div id="relationshipMetrics"></div>
                    </div>
                </div>
                <style>
                    .db-viz-container {
                        animation: fadeIn 0.5s;
                    }
                    @keyframes fadeIn {
                        from { opacity: 0; }
                        to { opacity: 1; }
                    }
                    .table-accordion-item {
                        border: 1px solid var(--border-color);
                        border-radius: 8px;
                        margin-bottom: 10px;
                        overflow: hidden;
                    }
                    .accordion-header {
                        width: 100%;
                        padding: 15px 20px;
                        background: var(--card-bg);
                        border: none;
                        cursor: pointer;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        font-weight: 600;
                        color: var(--text-primary);
                        transition: background 0.3s;
                    }
                    .accordion-header:hover {
                        background: var(--hover-bg);
                    }
                    .accordion-icon {
                        transition: transform 0.3s;
                        font-size: 0.8em;
                    }
                    .accordion-header.active .accordion-icon {
                        transform: rotate(-180deg);
                    }
                    .accordion-content {
                        max-height: 0;
                        overflow: hidden;
                        transition: max-height 0.3s ease-out;
                        background: var(--bg-primary);
                    }
                    .accordion-content.open {
                        max-height: 2000px;
                        padding: 20px;
                    }
                    .db-node {
                        cursor: pointer;
                        transition: all 0.3s;
                    }
                    .db-node:hover rect {
                        fill: #B91C1C;
                    }
                    .db-relationship {
                        stroke: #F97316;
                        stroke-width: 2;
                        fill: none;
                        marker-end: url(#arrowhead);
                    }
                    .metric-item {
                        padding: 15px;
                        border-left: 4px solid #DC2626;
                        margin-bottom: 15px;
                        background: var(--card-bg);
                        border-radius: 4px;
                    }
                    .metric-item strong {
                        color: #DC2626;
                        font-size: 1.2em;
                    }
                </style>
                <script>
                    function toggleAccordion(tableId) {
                        const content = document.getElementById('accordion-' + tableId);
                        const header = content.previousElementSibling;

                        content.classList.toggle('open');
                        header.classList.toggle('active');
                    }
                    // Draw ER Diagram
                    function drawERDiagram() {
                        const svg = document.getElementById('erSvg');
                        const width = svg.clientWidth;
                        const height = 600;
                        // Define nodes (tables)
                        const nodes = [
                            { id: 'users', x: 150, y: 50, width: 120, height: 80, label: 'Users', color: '#DC2626' },
                            { id: 'cars', x: 450, y: 50, width: 120, height: 80, label: 'Cars', color: '#F97316' },
                            { id: 'parts', x: 450, y: 250, width: 120, height: 80, label: 'Parts', color: '#10B981' },
                            { id: 'affiliate_sources', x: 750, y: 250, width: 140, height: 80, label: 'Affiliate Sources', color: '#3B82F6' },
                            { id: 'part_compatibility', x: 450, y: 450, width: 150, height: 80, label: 'Part Compatibility', color: '#8B5CF6' },
                            { id: 'user_garages', x: 150, y: 250, width: 120, height: 80, label: 'User Garages', color: '#EC4899' },
                            { id: 'click_logs', x: 150, y: 450, width: 120, height: 80, label: 'Click Logs', color: '#F59E0B' }
                        ];
                        // Define relationships
                        const relationships = [
                            { from: 'user_garages', to: 'users', label: 'user_id' },
                            { from: 'user_garages', to: 'cars', label: 'car_id' },
                            { from: 'part_compatibility', to: 'parts', label: 'part_id' },
                            { from: 'part_compatibility', to: 'cars', label: 'car_id' },
                            { from: 'parts', to: 'affiliate_sources', label: 'source_id' },
                            { from: 'click_logs', to: 'parts', label: 'part_id' },
                            { from: 'click_logs', to: 'users', label: 'user_id' }
                        ];
                        let svgContent = '<defs><marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto"><polygon points="0 0, 10 3.5, 0 7" fill="#F97316" /></marker></defs>';
                        // Draw relationships first (so they appear behind nodes)
                        relationships.forEach(rel => {
                            const fromNode = nodes.find(n => n.id === rel.from);
                            const toNode = nodes.find(n => n.id === rel.to);

                            const x1 = fromNode.x + fromNode.width / 2;
                            const y1 = fromNode.y + fromNode.height / 2;
                            const x2 = toNode.x + toNode.width / 2;
                            const y2 = toNode.y + toNode.height / 2;
                            svgContent += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" class="db-relationship" />`;

                            const midX = (x1 + x2) / 2;
                            const midY = (y1 + y2) / 2;
                            svgContent += `<text x="${midX}" y="${midY - 5}" fill="#F97316" font-size="11" text-anchor="middle">${rel.label}</text>`;
                        });
                        // Draw nodes
                        nodes.forEach(node => {
                            svgContent += `
                                <g class="db-node">
                                    <rect x="${node.x}" y="${node.y}" width="${node.width}" height="${node.height}" 
                                          fill="${node.color}" rx="8" opacity="0.9" />
                                    <text x="${node.x + node.width / 2}" y="${node.y + node.height / 2}" 
                                          fill="white" font-size="14" font-weight="bold" text-anchor="middle" 
                                          dominant-baseline="middle">${node.label}</text>
                                </g>
                            `;
                        });
                        svg.innerHTML = svgContent;
                    }
                    // Update metrics
                    function updateMetrics() {
                        fetch('get_db_metrics.php')
                            .then(response => response.json())
                            .then(data => {
                                let html = '';
                                html += `<div class="metric-item"><strong>${data.avg_parts_per_car}</strong> average parts per car</div>`;
                                html += `<div class="metric-item"><strong>${data.total_compatibilities}</strong> total part-car compatibility records</div>`;
                                html += `<div class="metric-item"><strong>${data.users_with_garages}</strong> users have cars in their garage</div>`;
                                html += `<div class="metric-item"><strong>${data.parts_with_clicks}</strong> parts have been clicked at least once</div>`;
                                html += `<div class="metric-item"><strong>${data.avg_price}</strong> average part price</div>`;

                                document.getElementById('relationshipMetrics').innerHTML = html;
                            })
                            .catch(err => console.error('Error loading metrics:', err));
                    }
                    // Auto-refresh data
                    function refreshData() {
                        // Refresh counts
                        <?php foreach ($tables as $table): ?>
                        fetch('get_table_count.php?table=<?php echo $table; ?>')
                            .then(response => response.json())
                            .then(data => {
                                document.getElementById('count-<?php echo $table; ?>').textContent = 
                                    new Intl.NumberFormat().format(data.count);
                            });
                        <?php endforeach; ?>
                        updateMetrics();
                    }
                    // Initialize
                    drawERDiagram();
                    updateMetrics();
                    setInterval(refreshData, 10000); // Refresh every 10 seconds
                    // Redraw diagram on window resize
                    window.addEventListener('resize', drawERDiagram);
                </script>
            <?php elseif ($section === 'dashboard'): ?>
                <h3>Analytics Dashboard</h3>
                <div class="chart-container">
                    <h4>Total Clicks Over Time</h4>
                    <canvas id="clicksOverTimeChart"></canvas>
                </div>
                <div class="chart-container">
                    <h4>Clicks by Part Category</h4>
                    <canvas id="categoryPieChart"></canvas>
                </div>
                <div class="chart-container">
                    <h4>Top Clicked Parts</h4>
                    <canvas id="partsBarChart"></canvas>
                </div>
                <?php
                $total_clicks = $conn->query("SELECT COUNT(*) as count FROM click_logs")->fetch_assoc()['count'];
                $top_part = $conn->query("
                    SELECT p.name, COUNT(*) as clicks 
                    FROM click_logs cl 
                    JOIN parts p ON cl.part_id = p.part_id 
                    GROUP BY cl.part_id 
                    ORDER BY clicks DESC 
                    LIMIT 1
                ")->fetch_assoc();
                ?>
                <div class="card">
                    <h4>Quick Stats</h4>
                    <p><strong>Total Clicks:</strong> <?php echo number_format($total_clicks); ?></p>
                    <?php if ($top_part): ?>
                        <p><strong>Top Clicked Part:</strong> <?php echo htmlspecialchars($top_part['name']); ?> (<?php echo $top_part['clicks']; ?> clicks)</p>
                    <?php endif; ?>
                </div>
            <?php elseif ($section === 'cars'): ?>
                <h3>Car Management</h3>
                <div class="card">
                    <h4>Add New Car</h4>
                    <form method="POST">
                        <div class="form-group">
                            <label>Brand</label>
                            <input type="text" name="brand" required>
                        </div>
                        <div class="form-group">
                            <label>Model</label>
                            <input type="text" name="model" required>
                        </div>
                        <div class="form-group">
                            <label>Year</label>
                            <input type="number" name="year" required min="1900" max="2030">
                        </div>
                        <div class="form-group">
                            <label>Image URL</label>
                            <input type="url" name="image">
                        </div>
                        <button type="submit" name="add_car" class="btn">Add Car</button>
                    </form>
                </div>
                <div class="card">
                    <h4>Existing Cars</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Brand</th>
                                <th>Model</th>
                                <th>Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $cars = $conn->query("SELECT * FROM cars ORDER BY brand, model, year");
                            while ($car = $cars->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?php echo $car['car_id']; ?></td>
                                    <td><?php echo htmlspecialchars($car['name']); ?></td>
                                    <td><?php echo htmlspecialchars($car['brand']); ?></td>
                                    <td><?php echo htmlspecialchars($car['model']); ?></td>
                                    <td><?php echo $car['year']; ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this car?');">
                                            <input type="hidden" name="car_id" value="<?php echo $car['car_id']; ?>">
                                            <button type="submit" name="delete_car" class="btn" style="background: #ef4444;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($section === 'parts'): ?>
                <h3>Part Management</h3>
                <div class="card">
                    <h4>Add New Part</h4>
                    <form method="POST">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Price</label>
                            <input type="number" step="0.01" name="price" required>
                        </div>
                        <div class="form-group">
                            <label>Color</label>
                            <input type="text" name="color">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Image URL</label>
                            <input type="url" name="image">
                        </div>
                        <div class="form-group">
                            <label>Affiliate Link Path</label>
                            <input type="text" name="link" required placeholder="/dp/XXXX">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" required>
                                <option value="Exhaust">Exhaust</option>
                                <option value="Intake">Intake</option>
                                <option value="Suspension">Suspension</option>
                                <option value="Wheels">Wheels</option>
                                <option value="Tires">Tires</option>
                                <option value="Brakes">Brakes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Affiliate Source</label>
                            <select name="source_id">
                                <option value="">None</option>
                                <?php
                                $sources = $conn->query("SELECT * FROM affiliate_sources");
                                while ($source = $sources->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $source['source_id']; ?>"><?php echo htmlspecialchars($source['source_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Compatible Cars</label>
                            <?php
                            $cars = $conn->query("SELECT * FROM cars ORDER BY name");
                            while ($car = $cars->fetch_assoc()):
                            ?>
                                <label style="display: block;">
                                    <input type="checkbox" name="compatible_cars[]" value="<?php echo $car['car_id']; ?>">
                                    <?php echo htmlspecialchars($car['name']); ?>
                                </label>
                            <?php endwhile; ?>
                        </div>
                        <button type="submit" name="add_part" class="btn">Add Part</button>
                    </form>
                </div>
                <div class="card">
                    <h4>Existing Parts</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Source</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $parts = $conn->query("
                                SELECT p.*, a.source_name 
                                FROM parts p 
                                LEFT JOIN affiliate_sources a ON p.source_id = a.source_id 
                                ORDER BY p.category, p.name
                            ");
                            while ($part = $parts->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?php echo $part['part_id']; ?></td>
                                    <td><?php echo htmlspecialchars($part['name']); ?></td>
                                    <td><?php echo htmlspecialchars($part['category']); ?></td>
                                    <td>$<?php echo number_format($part['price'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($part['source_name'] ?? 'None'); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this part?');">
                                            <input type="hidden" name="part_id" value="<?php echo $part['part_id']; ?>">
                                            <button type="submit" name="delete_part" class="btn" style="background: #ef4444;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($section === 'sources'): ?>
                <h3>Affiliate Source Management</h3>
                <div class="card">
                    <h4>Add New Affiliate Source</h4>
                    <form method="POST">
                        <div class="form-group">
                            <label>Source Name</label>
                            <input type="text" name="source_name" required placeholder="Amazon Auto">
                        </div>
                        <div class="form-group">
                            <label>Base URL</label>
                            <input type="url" name="base_url" required placeholder="https://www.example.com">
                        </div>
                        <button type="submit" name="add_source" class="btn">Add Source</button>
                    </form>
                </div>
                <div class="card">
                    <h4>Existing Sources</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Base URL</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sources = $conn->query("SELECT * FROM affiliate_sources");
                            while ($source = $sources->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?php echo $source['source_id']; ?></td>
                                    <td><?php echo htmlspecialchars($source['source_name']); ?></td>
                                    <td><?php echo htmlspecialchars($source['base_url']); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this source? Parts will have their source set to NULL.');">
                                            <input type="hidden" name="source_id" value="<?php echo $source['source_id']; ?>">
                                            <button type="submit" name="delete_source" class="btn" style="background: #ef4444;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($section === 'car_parts'): ?>
                <h3>Car Parts Visualization</h3>
                <div class="card">
                    <h4>Select a Car</h4>
                    <select id="carSelect" onchange="loadCarParts()">
                        <option value="">Select a car</option>
                        <?php
                        $cars = $conn->query("SELECT * FROM cars ORDER BY brand, model, year");
                        while ($car = $cars->fetch_assoc()):
                        ?>
                            <option value="<?php echo $car['car_id']; ?>"><?php echo htmlspecialchars($car['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div id="carPartsVisualization" style="margin-top: 20px;">
                    <!-- Visualization will be loaded here via JavaScript -->
                </div>
                <script>
                function loadCarParts() {
                    const carId = document.getElementById('carSelect').value;
                    if (!carId) return;

                    fetch(`?section=car_parts&car_id=${carId}`)
                        .then(response => response.json())
                        .then(data => {
                            // Display the car and its parts
                            let html = `<h4>${data.car.name}</h4>`;
                            html += `<div style="display: flex; flex-wrap: wrap; gap: 10px;">`;
                            data.parts.forEach(part => {
                                html += `<div style="border: 1px solid #ccc; padding: 10px; border-radius: 5px;">${part.name}</div>`;
                            });
                            html += `</div>`;
                            document.getElementById('carPartsVisualization').innerHTML = html;
                        });
                }
                </script>
                <?php
                if (isset($_GET['car_id'])) {
                    $car_id = (int)$_GET['car_id'];
                    $car = $conn->query("SELECT * FROM cars WHERE car_id = $car_id")->fetch_assoc();
                    $parts = $conn->query("
                        SELECT p.*
                        FROM parts p
                        JOIN part_compatibility pc ON p.part_id = pc.part_id
                        WHERE pc.car_id = $car_id
                    ")->fetch_all(MYSQLI_ASSOC);
                    header('Content-Type: application/json');
                    echo json_encode(['car' => $car, 'parts' => $parts]);
                    exit;
                }
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($section === 'dashboard'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php
$clicks_over_time = $conn->query("
    SELECT DATE(timestamp) as date, COUNT(*) as count 
    FROM click_logs 
    GROUP BY DATE(timestamp) 
    ORDER BY date DESC 
    LIMIT 30
");
$dates = [];
$counts = [];
while ($row = $clicks_over_time->fetch_assoc()) {
    $dates[] = $row['date'];
    $counts[] = $row['count'];
}
$dates = array_reverse($dates);
$counts = array_reverse($counts);
$category_clicks = $conn->query("
    SELECT p.category, COUNT(*) as count 
    FROM click_logs cl 
    JOIN parts p ON cl.part_id = p.part_id 
    GROUP BY p.category
");
$categories = [];
$category_counts = [];
while ($row = $category_clicks->fetch_assoc()) {
    $categories[] = $row['category'];
    $category_counts[] = $row['count'];
}
$top_parts = $conn->query("
    SELECT p.name, COUNT(*) as count 
    FROM click_logs cl 
    JOIN parts p ON cl.part_id = p.part_id 
    GROUP BY cl.part_id 
    ORDER BY count DESC 
    LIMIT 10
");
$part_names = [];
$part_counts = [];
while ($row = $top_parts->fetch_assoc()) {
    $part_names[] = $row['name'];
    $part_counts[] = $row['count'];
}
?>
const ctx1 = document.getElementById('clicksOverTimeChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($dates); ?>,
        datasets: [{
            label: 'Clicks',
            data: <?php echo json_encode($counts); ?>,
            borderColor: '#DC2626',
            backgroundColor: 'rgba(220, 38, 38, 0.1)',
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            },
            x: {
                ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            }
        }
    }
});
const ctx2 = document.getElementById('categoryPieChart').getContext('2d');
new Chart(ctx2, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode($categories); ?>,
        datasets: [{
            data: <?php echo json_encode($category_counts); ?>,
            backgroundColor: ['#DC2626', '#F97316', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            }
        }
    }
});
const ctx3 = document.getElementById('partsBarChart').getContext('2d');
new Chart(ctx3, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($part_names); ?>,
        datasets: [{
            label: 'Clicks',
            data: <?php echo json_encode($part_counts); ?>,
            backgroundColor: '#F97316'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            },
            x: {
                ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') }
            }
        }
    }
});
</script>
<?php endif; ?>
<?php renderFooter(); ?>