<?php
require_once 'config.php';

// Handle "Edit" button from profile — load an existing build into prefill
if (!empty($_GET['load_build']) && isLoggedIn()) {
    $load_id = (int)$_GET['load_build'];
    $stmt = $conn->prepare("SELECT b.*, GROUP_CONCAT(bp.part_id) as part_ids FROM builds b LEFT JOIN build_parts bp ON b.build_id = bp.build_id WHERE b.build_id = ? AND b.user_id = ? GROUP BY b.build_id");
    $stmt->bind_param("ii", $load_id, $_SESSION['user_id']);
    $stmt->execute();
    $lb = $stmt->get_result()->fetch_assoc();
    if ($lb) {
        // Load full part details for prefill
        $pstmt = $conn->prepare("SELECT p.part_id, p.name, p.price, p.link, bp.position_data FROM build_parts bp JOIN parts p ON bp.part_id = p.part_id WHERE bp.build_id = ?");
        $pstmt->bind_param("i", $load_id);
        $pstmt->execute();
        $pres = $pstmt->get_result();
        $prefill_parts_load = [];
        while ($pr = $pres->fetch_assoc()) {
            $prefill_parts_load[] = [
                'part_id'  => (int)$pr['part_id'],
                'name'     => $pr['name'],
                'price'    => (float)$pr['price'],
                'link'     => $pr['link'] ?? '',
                'position' => $pr['position_data'] ?? 'general',
            ];
        }
        $_SESSION['prefill_build'] = [
            'car_id'       => (int)$lb['car_id'],
            'parts'        => $prefill_parts_load,
            'build_title'  => $lb['build_title'],
            'is_edit'      => true,
            'build_id'     => $load_id,
        ];
        header('Location: /index.php');
        exit;
    }
}

// Prefill logic (fork or edit redirect)
$prefill_build = null;
if (!empty($_SESSION['prefill_build'])) {
    $prefill_build = $_SESSION['prefill_build'];
    $selected_car_id = $prefill_build['car_id'];
    unset($_SESSION['prefill_build']);
}

if (!empty($_GET['share_build'])) {
    $decoded_build = json_decode(base64_decode($_GET['share_build'], true), true);
    if ($decoded_build && !empty($decoded_build['car_id'])) {
        $prefill_build = $decoded_build;
        $selected_car_id = $decoded_build['car_id'];
    }
}

$prefill_parts_json = $prefill_build ? json_encode($prefill_build['parts'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';
$prefill_title = $prefill_build['build_title'] ?? '';

$cars = $conn->query("SELECT * FROM cars ORDER BY brand, model, year");
// Only read GET/session car_id if prefill hasn't already set one
if (empty($selected_car_id)) {
    $selected_car_id = $_GET['car_id'] ?? ($_SESSION['selected_car_id'] ?? null);
}

if ($selected_car_id) {
    $_SESSION['selected_car_id'] = $selected_car_id;
}

$parts = [];
$selected_car = null;

if ($selected_car_id) {
    $stmt = $conn->prepare("SELECT * FROM cars WHERE car_id = ?");
    $stmt->bind_param("i", $selected_car_id);
    $stmt->execute();
    $selected_car = $stmt->get_result()->fetch_assoc();

    // We still grab p.* so $part['link'] will be included automatically
    $query = "SELECT p.*, a.base_url FROM parts p LEFT JOIN affiliate_sources a ON p.source_id = a.source_id ORDER BY p.category, p.name";
    $parts_result = $conn->query($query);
    $parts = $parts_result ? $parts_result->fetch_all(MYSQLI_ASSOC) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_build'])) {
    if (!isLoggedIn()) { header('Location: /user/login.php'); exit; }
    $build_title = trim($_POST['build_title'] ?? '');
    $total_price = (float)($_POST['total_price'] ?? 0);
    $is_shared = isset($_POST['share_community']) ? 1 : 0;
    $build_data = json_decode($_POST['build_data'] ?? '[]', true);
    $estimated_hp = isset($_POST['estimated_hp']) && is_numeric($_POST['estimated_hp']) ? (int)$_POST['estimated_hp'] : null;

    $stmt = $conn->prepare("INSERT INTO builds (user_id, car_id, build_title, total_price, is_community_shared, estimated_hp) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisdii", $_SESSION['user_id'], $selected_car_id, $build_title, $total_price, $is_shared, $estimated_hp);
    if ($stmt->execute()) {
        $build_id = $conn->insert_id;
        foreach ($build_data as $item) {
            $stmt2 = $conn->prepare("INSERT INTO build_parts (build_id, part_id, position_data) VALUES (?, ?, ?)");
            $stmt2->bind_param("iis", $build_id, $item['part_id'], $item['position']);
            $stmt2->execute();
        }

        // Handle optional image upload
        if (!empty($_FILES['build_image']['name']) && $_FILES['build_image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['build_image']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, $allowed_types)) {
                $ext = pathinfo($_FILES['build_image']['name'], PATHINFO_EXTENSION);
                $filename = 'build_' . $build_id . '_' . time() . '.' . strtolower($ext);
                $dest = __DIR__ . '/uploads/' . $filename;
                if (move_uploaded_file($_FILES['build_image']['tmp_name'], $dest)) {
                    $img_path = '/uploads/' . $filename;
                    $upd = $conn->prepare("UPDATE builds SET featured_image = ? WHERE build_id = ?");
                    $upd->bind_param("si", $img_path, $build_id);
                    $upd->execute();
                }
            }
        }

        $_SESSION['build_saved_success'] = true;
        header('Location: /user/profile.php');
        exit;
    }
}

// Fetch community highlights for landing page
$community_highlights = [];
if (!$selected_car) {
    $ch = $conn->query("SELECT b.build_id, b.build_title, b.total_price, b.likes_count, b.featured_image, c.name as car_name, u.username FROM builds b JOIN cars c ON b.car_id = c.car_id JOIN users u ON b.user_id = u.uid WHERE b.is_community_shared = 1 ORDER BY b.likes_count DESC, b.date_created DESC LIMIT 3");
    if ($ch) $community_highlights = $ch->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = $selected_car ? "Build Your Car - ModMyCar" : "ModMyCar — Mod Your Ride";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<style>
    /* Status Styles */
    .incompatible-badge { color: #ff4d4d; font-size: 0.8rem; font-weight: bold; margin-left: 10px; }
    .summary-warning { color: #ff4d4d; font-weight: bold; margin-top: 5px; display: none; }
    .build-item-row.is-incompatible { border-left: 3px solid #ff4d4d; background: rgba(255, 77, 77, 0.1); }

    /* Button Styles */
    .btn:disabled { background-color: #444 !important; color: #888 !important; cursor: not-allowed; opacity: 0.6; }
    .btn-outline-danger { background: transparent; border: 1px solid #ff4d4d; color: #ff4d4d; margin-top: 10px; }
    .btn-outline-danger:hover { background: #ff4d4d; color: #fff; }

    /* Car Search Styles */
    .car-search-wrapper { display: flex; flex-direction: column; gap: 10px; }
    .car-search-filters { display: flex; gap: 10px; flex-wrap: wrap; }
    .car-search-filters input { flex: 1; min-width: 160px; margin: 0; }
    .car-results-list {
        max-height: 260px;
        overflow-y: auto;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-tertiary);
    }
    .car-result-item {
        padding: 12px 16px;
        cursor: pointer;
        border-bottom: 1px solid var(--border-color);
        transition: background 0.15s;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: var(--text-primary);
    }
    .car-result-item:last-child { border-bottom: none; }
    .car-result-item:hover { background: var(--accent-1); color: #fff; }
    .car-result-item .car-year-badge {
        font-size: 0.8rem;
        padding: 2px 8px;
        border-radius: 20px;
        background: rgba(255,255,255,0.12);
        color: inherit;
    }
    .car-result-item:hover .car-year-badge { background: rgba(255,255,255,0.25); }
    .car-no-results { padding: 16px; text-align: center; color: var(--text-secondary); }
    .car-current-selection {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        background: rgba(200,136,58,0.08);
        border: 1px solid var(--accent-1);
        border-radius: 8px;
        color: var(--text-primary);
    }
    .car-clear-btn {
        color: var(--accent-1);
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: bold;
    }
    .car-clear-btn:hover { color: #ff4d4d; }

    /* Filter Toolbar Styles */
    .filters-toolbar { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; }
    .search-input { flex: 1; margin-bottom: 0; } /* Overwrite default mb */

    /* Toggle Switch Style */
    .toggle-container { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer; user-select: none; }
    .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #555; transition: .4s; border-radius: 20px; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: var(--accent-1); }
    input:checked + .slider:before { transform: translateX(20px); }

    /* Dropdown Styles */
    .category-dropdown { position: relative; }
    .filter-icon-btn { background: #333; border: 1px solid #555; color: #fff; padding: 8px 12px; cursor: pointer; border-radius: 4px; }
    .dropdown-menu {
        position: absolute; right: 0; top: 100%; z-index: 10;
        background: #222; border: 1px solid #444; border-radius: 4px;
        min-width: 150px; box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    }
    .dropdown-item { padding: 10px; cursor: pointer; color: #ddd; border-bottom: 1px solid #333; }
    .dropdown-item:hover { background: #333; color: #fff; }
    .dropdown-item:last-child { border-bottom: none; }

    .part-item { position: relative; }
    .affiliate-link {
        position: absolute;
        top: 10px;
        right: 10px;
        color: var(--accent-1);
        background: rgba(0,0,0,0.5);
        padding: 5px;
        border-radius: 4px;
        line-height: 0;
        transition: color 0.2s;
        z-index: 2;
    }
    .affiliate-link:hover { color: #fff; background: var(--accent-1); }
    .affiliate-link-mini { color: var(--accent-1); line-height: 0; display: flex; align-items: center; }
    .affiliate-link-mini:hover { color: var(--text-primary); }

    /* Ensure icon visibility in the card */
    .part-item h4 { margin-right: 35px; } /* Make room for the top-right icon */

    /* Landing Page Styles */
    .landing-hero {
        text-align: center;
        padding: 4rem 1rem 3rem;
    }
    .landing-hero h1 {
        font-size: 3rem;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }
    .landing-hero p {
        font-size: 1.15rem;
        color: var(--text-secondary);
        max-width: 600px;
        margin: 0 auto 2rem;
    }
    .landing-features {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin: 2.5rem 0;
    }
    .feature-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        transition: border-color 0.2s, transform 0.2s;
    }
    .feature-card:hover { border-color: var(--accent-1); transform: translateY(-3px); }
    .feature-card .feature-icon { font-size: 2rem; margin-bottom: 0.75rem; }
    .feature-card h4 { margin-bottom: 0.5rem; }
    .feature-card p { font-size: 0.9rem; color: var(--text-secondary); margin: 0; }
    .landing-highlights { margin: 2.5rem 0; }
    .landing-highlights h3 { margin-bottom: 1.5rem; text-align: center; }
    .highlights-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1.25rem;
    }
    .highlight-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        overflow: hidden;
        transition: border-color 0.2s;
    }
    .highlight-card:hover { border-color: var(--accent-1); }
    .highlight-card-img {
        height: 140px;
        background: var(--bg-tertiary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-secondary);
        font-size: 0.85rem;
    }
    .highlight-card-img img { width: 100%; height: 100%; object-fit: cover; }
    .highlight-card-body { padding: 1rem; }
    .highlight-card-body h4 { font-size: 1rem; margin-bottom: 0.25rem; }
    .highlight-card-body p { font-size: 0.85rem; color: var(--text-secondary); margin: 0; }
    .highlight-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem; }
    .divider { border: none; border-top: 1px solid var(--border-color); margin: 2.5rem 0; }
</style>

<?php if (!$selected_car): ?>
<div class="container">
    <div class="landing-hero">
        <h1>Mod Your Ride</h1>
        <p>ModMyCar is the ultimate tool for car enthusiasts. Build your perfect setup, discover compatible parts, and share your builds with a growing community.</p>
        <a href="#car-builder" class="btn" style="font-size:1.1rem; padding: 0.85rem 2.2rem;">Start Building</a>
        <a href="/community.php" class="btn btn-secondary" style="font-size:1.1rem; padding: 0.85rem 2.2rem; margin-left: 0.75rem;">Browse Community</a>
    </div>

    <div class="landing-features">
        <div class="feature-card">
            <div class="feature-icon">🔧</div>
            <h4>Drag & Drop Builder</h4>
            <p>Assemble your build visually. Add compatible parts with a simple drag and drop interface.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">⚡</div>
            <h4>HP Estimator</h4>
            <p>See how each modification affects your estimated horsepower in real time.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">🤝</div>
            <h4>Community Builds</h4>
            <p>Share your setups, like others' builds, and fork any community build as your starting point.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">✅</div>
            <h4>Compatibility Check</h4>
            <p>Parts are filtered by engine and chassis code so you only see what fits your car.</p>
        </div>
    </div>

    <?php if (!empty($community_highlights)): ?>
    <div class="landing-highlights">
        <h3>🔥 Trending Community Builds</h3>
        <div class="highlights-grid">
            <?php foreach ($community_highlights as $hl): ?>
            <a href="/community.php?build=<?= (int)$hl['build_id']; ?>" class="highlight-card" style="text-decoration:none; color:inherit;">
                <div class="highlight-card-img">
                    <?php if (!empty($hl['featured_image'])): ?>
                        <img src="<?= htmlspecialchars($hl['featured_image']); ?>" alt="Build">
                    <?php else: ?>
                        <span>No Image</span>
                    <?php endif; ?>
                </div>
                <div class="highlight-card-body">
                    <h4><?= htmlspecialchars($hl['build_title']); ?></h4>
                    <p><?= htmlspecialchars($hl['car_name']); ?> &bull; by <?= htmlspecialchars($hl['username']); ?></p>
                    <div class="highlight-meta">
                        <span style="color:var(--accent-1); font-weight:bold;">$<?= number_format((float)$hl['total_price'], 0); ?></span>
                        <span style="color:var(--text-secondary); font-size:0.85rem;">👍 <?= (int)$hl['likes_count']; ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <hr class="divider" id="car-builder">
</div>
<?php endif; ?>

<div class="container">
    <?php if ($selected_car): ?>
    <h2>Build Your Dream Car</h2>
    <?php else: ?>
    <h2>Select Your Car to Start</h2>
    <?php endif; ?>

    <div class="card">
        <h3>Select Your Car</h3>

        <?php
        $cars->data_seek(0);
        $all_cars = $cars->fetch_all(MYSQLI_ASSOC);
        $cars_json = json_encode(array_map(fn($c) => [
            'id'    => $c['car_id'],
            'brand' => $c['brand'],
            'model' => $c['model'],
            'year'  => $c['year'],
            'name'  => $c['name'],
        ], $all_cars), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $selected_car_name = $selected_car ? htmlspecialchars($selected_car['name'], ENT_QUOTES) : '';
        ?>

        <form method="GET" id="carSelectForm">
            <input type="hidden" name="car_id" id="carIdInput" value="<?= (int)($selected_car_id ?? 0); ?>">
        </form>

        <div class="car-search-wrapper">
            <div class="car-search-filters">
                <input type="text" id="carBrandSearch" placeholder="Search brand (e.g. Honda, Toyota...)" oninput="filterCars()" autocomplete="off">
                <input type="text" id="carYearSearch" placeholder="Year (e.g. 2020)" oninput="filterCars()" autocomplete="off" style="max-width: 140px;">
            </div>
            <?php if ($selected_car): ?>
                <div class="car-current-selection">
                    <span>Selected: <strong><?= $selected_car_name; ?></strong></span>
                    <a href="/" class="car-clear-btn">✕ Change</a>
                </div>
            <?php endif; ?>
            <div id="carResultsList" class="car-results-list" style="display:none;"></div>
        </div>
    </div>

    <?php if ($selected_car): ?>
        <div class="build-area">
            <div class="parts-panel">
                <h3>Available Parts</h3>

                <div class="filters-toolbar">
                    <input type="text" id="searchParts" class="search-input" placeholder="Search parts..." onkeyup="filterParts()">

                    <div class="category-dropdown">
                        <button id="categoryBtn" class="filter-icon-btn" onclick="toggleCategoryDropdown()" title="Filter by category">☰</button>
                        <div id="categoryDropdown" class="dropdown-menu" style="display: none;">
                            <div class="dropdown-item" onclick="selectCategory('all')">All Categories</div>
                            <div class="dropdown-item" onclick="selectCategory('Exhaust')">Exhaust</div>
                            <div class="dropdown-item" onclick="selectCategory('Intake')">Intake</div>
                            <div class="dropdown-item" onclick="selectCategory('Suspension')">Suspension</div>
                            <div class="dropdown-item" onclick="selectCategory('Wheels')">Wheels</div>
                            <div class="dropdown-item" onclick="selectCategory('Tires')">Tires</div>
                            <div class="dropdown-item" onclick="selectCategory('Brakes')">Brakes</div>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label class="toggle-container">
                        <div class="toggle-switch">
                            <input type="checkbox" id="compatibleToggle" onchange="toggleCompatibilityFilter()">
                            <span class="slider"></span>
                        </div>
                        <span>Show Compatible Only</span>
                    </label>
                </div>

                <div id="partsList">
                    <?php foreach ($parts as $part): ?>
                        <?php 
                            // 1. Grab the link directly from the database column
                            $raw_url = $part['link'] ?? '';

                            // 2. Force https:// if it's missing (just to be safe)
                            if (!empty($raw_url) && !preg_match("~^(?:f|ht)tps?://~i", $raw_url)) {
                                $raw_url = "https://" . $raw_url;
                            }

                            // 3. Make it safe for HTML output
                            $full_link = htmlspecialchars($raw_url);
                        ?>
                        <div class="part-item"
                             data-part-id="<?= $part['part_id']; ?>"
                             data-category="<?= htmlspecialchars($part['category']); ?>"
                             data-name="<?= htmlspecialchars($part['name']); ?>"
                             data-price="<?= $part['price']; ?>"
                             data-engine="<?= htmlspecialchars($part['engine_code'] ?? ''); ?>"
                             data-chassis="<?= htmlspecialchars($part['chassis_code'] ?? ''); ?>"
                             data-year-start="<?= $part['year_start'] ?? 0; ?>"
                             data-year-end="<?= $part['year_end'] ?? 9999; ?>"
                             data-link="<?= $full_link; ?>" 
                             draggable="true"
                             ondragstart="drag(event)">

                            <?php if (!empty($part['image'])): ?>
                                <img src="<?= htmlspecialchars($part['image']); ?>">
                            <?php endif; ?>

                            <h4><?= htmlspecialchars($part['name']); ?></h4>
                            <p class="price">$<?= number_format($part['price'], 2); ?></p>

                            <div style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
                                <a href="/redirect.php?part_id=<?= (int)$part['part_id']; ?>" target="_blank" class="btn btn-sm" style="width: 80px; text-align: center; text-decoration: none; background: var(--accent-1); color: #fff; padding: 5px; border-radius: 4px; font-size: 0.8rem;">View</a>
                            </div>

                           
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="build-canvas">
                <h3>Your Build</h3>
                <div class="drop-zone" id="dropZone" ondrop="drop(event)" ondragover="allowDrop(event)" ondragleave="dragLeave(event)">
                    <p>Drop parts here</p>
                    <div id="buildParts"></div>
                </div>
                <div class="build-summary">
                    <h4>Build Summary</h4>
                    <p><strong>Car:</strong> <?= htmlspecialchars($selected_car['name']); ?></p>
                    <p><strong>Total Price:</strong> $<span id="totalPrice">0.00</span></p>
                    <p><strong>Stock Horsepower:</strong> <span id="stockHP">—</span> <span id="stockHpSpinner" style="display:none; font-size:0.8rem; color:#888;">loading...</span></p>
                    <p><strong>Estimated Horsepower:</strong> <span id="estimatedHP">—</span> <span id="hpSpinner" style="display:none; font-size:0.8rem; color:#888;">calculating...</span> <span id="hpNote" style="display:none; font-size:0.8rem; color:#888; font-style:italic;"></span></p>
                    <div id="incompatibleCount" class="summary-warning">⚠️ 0 parts incompatible</div>

                    <div class="flex-wrap-gap" style="display: flex; gap: 10px; flex-direction: column;">
                        <button id="saveBuildBtn" class="btn" style="margin-top:10px;" onclick="showSaveModal()">Save Build</button>
                        <button id="clearBuildBtn" class="btn btn-outline-danger" style="display:none;" onclick="clearAllParts()">Clear All</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="saveModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('saveModal').classList.remove('active')">&times;</span>
        <h3>Save Your Build</h3>
        <form method="POST" enctype="multipart/form-data" onsubmit="prepareBuildData()">
            <input type="text" name="build_title" id="saveBuildTitle" required placeholder="Build Title" value="<?= htmlspecialchars($prefill_title, ENT_QUOTES); ?>">
            <div style="margin: 12px 0;">
                <label style="display:block; margin-bottom:6px; font-size:0.9rem; color:var(--text-secondary);">Build Image (optional)</label>
                <input type="file" name="build_image" accept="image/*" style="color:var(--text-primary);">
                <p style="font-size:0.8rem; color:var(--text-secondary); margin-top:4px;">Leave blank to use a default placeholder image.</p>
            </div>
            <label style="display:flex; align-items:center; gap:10px; margin:12px 0; cursor:pointer;">
                <input type="checkbox" name="share_community" id="shareCommunityCheck" style="width:16px; height:16px;">
                <span style="font-size:0.9rem;">Share with Community</span>
            </label>
            <input type="hidden" name="total_price" id="saveTotalPrice">
            <input type="hidden" name="build_data" id="saveBuildData">
            <input type="hidden" name="estimated_hp" id="saveEstimatedHP">
            <button type="submit" name="save_build" class="btn">Save Build</button>
        </form>
    </div>
</div>

<script>
// --- Car Search ---
const ALL_CARS = <?= $cars_json; ?>;

function filterCars() {
    const brand = document.getElementById('carBrandSearch').value.trim().toLowerCase();
    const year  = document.getElementById('carYearSearch').value.trim();
    const list  = document.getElementById('carResultsList');

    if (!brand && !year) {
        list.style.display = 'none';
        list.innerHTML = '';
        return;
    }

    const filtered = ALL_CARS.filter(c => {
        const brandMatch = !brand || c.brand.toLowerCase().includes(brand);
        const yearMatch  = !year  || String(c.year).startsWith(year);
        return brandMatch && yearMatch;
    });

    if (filtered.length === 0) {
        list.innerHTML = '<div class="car-no-results">No cars found. Try a different brand or year.</div>';
    } else {
        list.innerHTML = filtered.map(c => `
            <div class="car-result-item" onclick="selectCar(${c.id})">
                <span>${c.brand} ${c.model}</span>
                <span class="car-year-badge">${c.year}</span>
            </div>`).join('');
    }
    list.style.display = 'block';
}

function selectCar(carId) {
    document.getElementById('carIdInput').value = carId;
    document.getElementById('carSelectForm').submit();
}

// --- Global State ---
let buildParts = [];
let currentCategory = 'all';
let showCompatibleOnly = false;

// --- Car Data (Passed from PHP) ---
const carData = {
    id: <?= $selected_car ? (int)$selected_car['car_id'] : 0; ?>,
    year: <?= $selected_car ? (int)$selected_car['year'] : 0; ?>,
    engine: "<?= $selected_car ? htmlspecialchars($selected_car['engine_code'], ENT_QUOTES) : ''; ?>",
    chassis: "<?= $selected_car ? htmlspecialchars($selected_car['chassis_code'], ENT_QUOTES) : ''; ?>",
    stockHp: <?= ($selected_car && !empty($selected_car['stock_hp'])) ? (int)$selected_car['stock_hp'] : 0; ?>
};

let stockHpValue = carData.stockHp;

// --- Drag & Drop ---
function drag(event) {
    const d = event.target.dataset;
    // Set all data needed for transfer
    for (let key in d) {
        event.dataTransfer.setData(key, d[key]);
    }
}

function allowDrop(event) { event.preventDefault(); event.currentTarget.classList.add('drag-over'); }
function dragLeave(event) { event.currentTarget.classList.remove('drag-over'); }

function drop(event) {
    event.preventDefault();
    event.currentTarget.classList.remove('drag-over');

    // Retrieve data using lowercase keys (dataset converts CamelCase to lowercase in getData if not careful, 
    // but here we manually set them in drag(). Let's grab them safely.)
    const d = {
        id: event.dataTransfer.getData("partId"),
        name: event.dataTransfer.getData("name"), // Note: dataset.name maps to data-name
        price: parseFloat(event.dataTransfer.getData("price")),
        link: event.dataTransfer.getData("link"),
        category: event.dataTransfer.getData("category"),
        engine: event.dataTransfer.getData("engine"),
        chassis: event.dataTransfer.getData("chassis"),
        yStart: parseInt(event.dataTransfer.getData("yearStart")),
        yEnd: parseInt(event.dataTransfer.getData("yearEnd"))
    };

    // Check compatibility using the helper
    const isCompatible = checkPartCompatibility(d.engine, d.chassis, d.yStart, d.yEnd);

    const slotMap = { 'Exhaust': 'exhaust', 'Intake': 'intake', 'Suspension': 'suspension', 'Wheels': 'wheels' };

    buildParts.push({ 
        part_id: d.id, 
        name: d.name, 
        price: d.price, 
        link: d.link,
        position: slotMap[d.category] || 'general',
        isCompatible: isCompatible 
    });

    updateBuildDisplay();
    filterParts(); // Refresh list to hide added parts
}

// --- Helper: Check Compatibility ---
    function checkPartCompatibility(pEngine, pChassis, pStart, pEnd) {
        // 1. Normalize the data to prevent case/spacing errors
        const carEng = carData.engine ? carData.engine.trim().toLowerCase() : "";
        const carChas = carData.chassis ? carData.chassis.trim().toLowerCase() : "";
        const partEng = pEngine ? pEngine.trim().toLowerCase() : "";
        const partChas = pChassis ? pChassis.trim().toLowerCase() : "";

        // 2. If the part has no codes assigned at all, it's incompatible by default
        if (partEng === "" && partChas === "") {
            return false;
        }

        // 3. Ensure neither string is empty BEFORE checking if they match
        const engineMatches = (partEng !== "" && carEng !== "" && partEng === carEng);
        const chassisMatches = (partChas !== "" && carChas !== "" && partChas === carChas);

        // 4. Return true if either a valid engine OR a valid chassis is a match
        return engineMatches || chassisMatches;
    }
// --- UI Updates ---
function updateBuildDisplay() {
    const buildPartsDiv = document.getElementById('buildParts');
    const totalPrice = buildParts.reduce((sum, p) => sum + p.price, 0);
    const incompatibleList = buildParts.filter(p => !p.isCompatible);
    const saveBtn = document.getElementById('saveBuildBtn');
    const clearBtn = document.getElementById('clearBuildBtn');

    buildPartsDiv.innerHTML = buildParts.map((p, i) => `
        <div class="build-item-row ${!p.isCompatible ? 'is-incompatible' : ''}">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <span>
                    <strong>${p.name}</strong> ($${p.price.toFixed(2)})
                    ${!p.isCompatible ? '<span class="incompatible-badge">✕ Incompatible</span>' : ''}
                </span>
                <div style="display: flex; gap: 8px; align-items: center;">
                    ${p.link ? `
                        <a href="${p.link}" target="_blank" class="affiliate-link-mini" title="View on Store">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M15 2a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1zM0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm10.096 8.803a.5.5 0 1 0 .707-.707L6.707 6h2.768a.5.5 0 1 0 0-1H5.5a.5.5 0 0 0-.5.5v3.975a.5.5 0 0 0 1 0V6.707z"/>
                            </svg>
                        </a>` : ''}
                    <button onclick="removePart(${i})" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size:1.2rem;">×</button>
                </div>
            </div>
        </div>`).join('');

    document.getElementById('totalPrice').textContent = totalPrice.toFixed(2);

    const countDiv = document.getElementById('incompatibleCount');
    if (incompatibleList.length > 0) {
        countDiv.textContent = `⚠️ ${incompatibleList.length} part(s) incompatible`;
        countDiv.style.display = 'block';
        saveBtn.disabled = true;
    } else {
        countDiv.style.display = 'none';
        saveBtn.disabled = false;
    }

    clearBtn.style.display = buildParts.length > 0 ? 'block' : 'none';

    fetchEstimatedHP();
}

function removePart(index) {
    buildParts.splice(index, 1);
    updateBuildDisplay();
    filterParts();
}

function clearAllParts() {
    if(confirm("Are you sure you want to clear your current build?")) {
        buildParts = [];
        updateBuildDisplay();
        filterParts();
    }
}

// --- Filters & Toggles ---
function toggleCategoryDropdown() {
    const dropdown = document.getElementById('categoryDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';

    // Auto-close on outside click
    if (dropdown.style.display === 'block') {
        setTimeout(() => document.addEventListener('click', closeDropdownOnClickOutside), 0);
    }
}

function closeDropdownOnClickOutside(event) {
    const dropdown = document.getElementById('categoryDropdown');
    const btn = document.getElementById('categoryBtn');
    if (!dropdown.contains(event.target) && event.target !== btn) {
        dropdown.style.display = 'none';
        document.removeEventListener('click', closeDropdownOnClickOutside);
    }
}

function selectCategory(category) {
    currentCategory = category;
    document.getElementById('categoryDropdown').style.display = 'none';
    filterParts();
}

function toggleCompatibilityFilter() {
    showCompatibleOnly = document.getElementById('compatibleToggle').checked;
    filterParts();
}

function filterParts() {
    const searchTerm = document.getElementById('searchParts').value.toLowerCase();
    const usedIds = new Set(buildParts.map(p => p.part_id)); // Used parts are always hidden

    document.querySelectorAll('.part-item').forEach(part => {
        const d = part.dataset;

        // 1. Check if already in build (Always hide)
        if (usedIds.has(d.partId)) {
            part.style.display = 'none';
            return;
        }

        // 2. Check Name Search
        const matchesSearch = d.name.toLowerCase().includes(searchTerm);

        // 3. Check Category
        const matchesCategory = (currentCategory === 'all' || d.category === currentCategory);

        // 4. Check Compatibility (Only if toggle is ON)
        let matchesCompat = true;
        if (showCompatibleOnly) {
            matchesCompat = checkPartCompatibility(d.engine, d.chassis, parseInt(d.yearStart), parseInt(d.yearEnd));
        }

        // Final Visibility Decision
        part.style.display = (matchesSearch && matchesCategory && matchesCompat) ? 'block' : 'none';
    });
}

// --- Stock Horsepower ---
function fetchStockHP() {
    if (!carData.id) return;

    const stockEl = document.getElementById('stockHP');
    const spinner = document.getElementById('stockHpSpinner');

    if (stockHpValue > 0) {
        stockEl.textContent = stockHpValue + ' HP';
        return;
    }

    spinner.style.display = 'inline';
    stockEl.textContent = '—';

    fetch('/api/stock_hp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ car_id: carData.id })
    })
    .then(r => r.json())
    .then(data => {
        spinner.style.display = 'none';
        if (data.stock_hp) {
            stockHpValue = data.stock_hp;
            stockEl.textContent = data.stock_hp + ' HP';
            const hpEl = document.getElementById('estimatedHP');
            if (hpEl.textContent === '—') {
                hpEl.textContent = data.stock_hp + ' HP';
            }
        } else {
            stockEl.textContent = '—';
        }
    })
    .catch(() => {
        spinner.style.display = 'none';
        stockEl.textContent = '—';
    });
}

// --- Estimated Horsepower ---
let hpDebounceTimer = null;

function fetchEstimatedHP() {
    const carName = "<?= $selected_car ? htmlspecialchars($selected_car['name'], ENT_QUOTES) : ''; ?>";
    if (!carName) return;

    const compatibleParts = buildParts.filter(p => p.isCompatible);
    const hpEl = document.getElementById('estimatedHP');
    const spinner = document.getElementById('hpSpinner');
    const noteEl = document.getElementById('hpNote');

    if (compatibleParts.length === 0) {
        noteEl.style.display = 'none';
        if (stockHpValue > 0) {
            hpEl.textContent = stockHpValue + ' HP';
        } else {
            hpEl.textContent = '—';
        }
        return;
    }

    hpEl.textContent = '—';
    noteEl.style.display = 'none';
    spinner.style.display = 'inline';

    clearTimeout(hpDebounceTimer);
    hpDebounceTimer = setTimeout(() => {
        fetch('/api/estimate_hp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                car: carName,
                stock_hp: stockHpValue,
                parts: compatibleParts.map(p => ({ name: p.name }))
            })
        })
        .then(r => r.json())
        .then(data => {
            spinner.style.display = 'none';
            if (data.hp) {
                hpEl.textContent = data.hp + ' HP';
                if (data.note) {
                    noteEl.textContent = '(' + data.note + ')';
                    noteEl.style.display = 'inline';
                } else {
                    noteEl.style.display = 'none';
                }
            } else {
                hpEl.textContent = stockHpValue > 0 ? stockHpValue + ' HP' : '—';
                noteEl.style.display = 'none';
            }
        })
        .catch(() => {
            spinner.style.display = 'none';
            hpEl.textContent = stockHpValue > 0 ? stockHpValue + ' HP' : '—';
        });
    }, 800);
}

// --- Save Modal ---
function showSaveModal() { 
    if(document.getElementById('saveBuildBtn').disabled) return;
    document.getElementById('saveModal').classList.add('active'); 
}

function prepareBuildData() {
    document.getElementById('saveTotalPrice').value = buildParts.reduce((sum, p) => sum + p.price, 0).toFixed(2);
    document.getElementById('saveBuildData').value = JSON.stringify(buildParts);
    const hpText = document.getElementById('estimatedHP').textContent;
    const hpVal = parseInt(hpText, 10);
    document.getElementById('saveEstimatedHP').value = isNaN(hpVal) ? '' : hpVal;
}

// --- Prefill Parts from Fork / Edit ---
const PREFILL_PARTS = <?= $prefill_parts_json; ?>;
if (PREFILL_PARTS && PREFILL_PARTS.length > 0) {
    PREFILL_PARTS.forEach(prefillPart => {
        // Try to look up full details (link, compatibility) from the rendered DOM
        const domPart = document.querySelector(`.part-item[data-part-id="${prefillPart.part_id}"]`);
        let link = prefillPart.link || '';
        let isCompatible = true;
        if (domPart) {
            const d = domPart.dataset;
            link = d.link || link;
            isCompatible = checkPartCompatibility(d.engine, d.chassis, parseInt(d.yearStart), parseInt(d.yearEnd));
        }
        buildParts.push({
            part_id: String(prefillPart.part_id),
            name: prefillPart.name,
            price: prefillPart.price,
            link: link,
            position: prefillPart.position || 'general',
            isCompatible: isCompatible
        });
    });
    updateBuildDisplay();
    filterParts();
}

// --- Page Init ---
<?php if ($selected_car): ?>
fetchStockHP();
<?php endif; ?>
</script>

<?php renderFooter(); ?>