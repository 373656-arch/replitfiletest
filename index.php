<?php
require_once 'config.php';
if (isset($_GET['clear_car'])) {
    unset($_SESSION['selected_car_id']);
    header('Location: /index.php');
    exit;
}
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

// Build a JSON-safe parts array for the AI assistant
$ai_parts_json = 'null';
if (!empty($parts) && $selected_car) {
    $ai_parts_data = array_map(function($p) use ($selected_car) {
        $pEng  = strtolower(trim($p['engine_code'] ?? ''));
        $pChas = strtolower(trim($p['chassis_code'] ?? ''));
        $carEng  = strtolower(trim($selected_car['engine_code'] ?? ''));
        $carChas = strtolower(trim($selected_car['chassis_code'] ?? ''));
        $year = (int)$selected_car['year'];
        $yStart = (int)($p['year_start'] ?? 0);
        $yEnd   = (int)($p['year_end'] ?? 9999);

        if ($pEng === '' && $pChas === '') {
            $compatible = true;
        } else {
            $compatible = ($pEng !== '' && $carEng !== '' && $pEng === $carEng)
                       || ($pChas !== '' && $carChas !== '' && $pChas === $carChas);
        }
        if ($year && $yStart && $yEnd) {
            $compatible = $compatible && ($year >= $yStart && $year <= $yEnd);
        }

        return [
            'id'        => (int)$p['part_id'],
            'name'      => $p['name'],
            'category'  => $p['category'],
            'price'     => (float)$p['price'],
            'hp_gain'   => (int)($p['hp_gain'] ?? 0),
            'compatible'=> $compatible,
            'link'      => $p['link'] ?? '',
            'engine'    => $p['engine_code'] ?? '',
            'chassis'   => $p['chassis_code'] ?? '',
            'year_start'=> (int)($p['year_start'] ?? 0),
            'year_end'  => (int)($p['year_end'] ?? 9999),
        ];
    }, $parts);
    $ai_parts_json = json_encode($ai_parts_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
/* ── AI Assistant ── */
#aiAssistantWrap {
    margin-top: 0;
    position: relative;
}
#aiAssistantToggle {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    background: var(--bg-tertiary);
    color: var(--accent-1);
    border: 1px solid var(--accent-1);
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
    letter-spacing: 0.01em;
}
#aiAssistantToggle:hover { background: var(--accent-1); color: #fff; }
.ai-panel {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    margin-top: 10px;
    overflow: hidden;
    box-shadow: var(--shadow-md);
    max-width: 100%;
    animation: aiSlideIn 0.2s ease;
}
@keyframes aiSlideIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.ai-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    background: var(--bg-tertiary);
    border-bottom: 1px solid var(--border-color);
}
.ai-panel-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--accent-1);
}
.ai-panel-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 1rem;
    padding: 2px 6px;
    border-radius: 4px;
    transition: color 0.15s;
}
.ai-panel-close:hover { color: var(--text-primary); }
.ai-messages {
    padding: 16px;
    max-height: 280px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.ai-msg { display: flex; }
.ai-msg-bot  { justify-content: flex-start; }
.ai-msg-user { justify-content: flex-end; }
.ai-msg-bubble {
    max-width: 85%;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 0.88rem;
    line-height: 1.55;
}
.ai-msg-bot .ai-msg-bubble {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-bottom-left-radius: 3px;
}
.ai-msg-user .ai-msg-bubble {
    background: var(--accent-1);
    color: #fff;
    border-bottom-right-radius: 3px;
}
.ai-msg-thinking .ai-msg-bubble { color: var(--text-secondary); font-style: italic; }
.ai-actions-applied {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.ai-action-tag {
    font-size: 0.78rem;
    padding: 3px 8px;
    border-radius: 20px;
    font-weight: 600;
}
.ai-action-tag.added   { background: rgba(100,200,100,0.15); color: #5c9e5c; border: 1px solid #5c9e5c44; }
.ai-action-tag.removed { background: rgba(200,100,100,0.15); color: #c05050; border: 1px solid #c0505044; }
.ai-input-row {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-tertiary);
}
.ai-input {
    flex: 1;
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 9px 13px;
    color: var(--text-primary);
    font-size: 0.88rem;
    outline: none;
    transition: border-color 0.2s;
}
.ai-input:focus { border-color: var(--accent-1); }
.ai-send-btn {
    background: var(--accent-1);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 13px;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
}
.ai-send-btn:hover { background: var(--accent-2); }
.ai-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>


<?php if (!$selected_car): ?>
<div class="container">
    <div class="landing-hero">
        <div class="section-label">The Car Enthusiast Platform</div>
        <h1>Mod Your <span class="hero-accent">Ride</span></h1>
        <p>Build your perfect setup, discover compatible parts, and share your builds with a growing community of car enthusiasts.</p>
        <div class="hero-cta">
            <a href="#car-builder" class="btn">Start Building</a>
            <a href="/community.php" class="btn btn-secondary">Browse Community</a>
        </div>
        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat-number">6+</div>
                <div class="hero-stat-label">Part Categories</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-number">100%</div>
                <div class="hero-stat-label">Compatibility Checked</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-number">Free</div>
                <div class="hero-stat-label">Always</div>
            </div>
        </div>
    </div>

    <div class="landing-features">
        <div class="feature-card">
            <span class="feature-icon">🔧</span>
            <h4>Drag &amp; Drop Builder</h4>
            <p>Assemble your build visually. Add compatible parts with a simple drag and drop interface.</p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">⚡</span>
            <h4>HP Estimator</h4>
            <p>See how each modification affects your estimated horsepower in real time.</p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">🤝</span>
            <h4>Community Builds</h4>
            <p>Share your setups, like others' builds, and fork any community build as your starting point.</p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">✅</span>
            <h4>Compatibility Check</h4>
            <p>Parts are filtered by engine and chassis code so you only see what fits your car.</p>
        </div>
    </div>

    <?php if (!empty($community_highlights)): ?>
    <div class="landing-highlights">
        <h3>🔥 Trending Community Builds</h3>
        <div class="highlights-grid">
            <?php foreach ($community_highlights as $hl): ?>
            <a href="/community.php?build=<?= (int)$hl['build_id']; ?>" class="highlight-card">
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
                        <span class="text-accent fw-700">$<?= number_format((float)$hl['total_price'], 0); ?></span>
                        <span class="text-muted">👍 <?= (int)$hl['likes_count']; ?></span>
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
                <input type="text" id="carBrandSearch" placeholder="Search brand (e.g. Honda, Toyota...)" oninput="filterCars()" onfocus="filterCars()" autocomplete="off">
                <input type="text" id="carYearSearch" placeholder="Year (e.g. 2020)" oninput="filterCars()" onfocus="filterCars()" autocomplete="off" style="max-width: 140px;">
            </div>
            <?php if ($selected_car): ?>
                <div class="car-current-selection">
                    <span>Selected: <strong><?= $selected_car_name; ?></strong></span>
                    <a href="?clear_car=1" class="car-clear-btn">✕ Change</a>
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

                        <!-- AI Assistant Floating Button & Panel -->
        <div id="aiAssistantWrap">
            <button id="aiAssistantToggle" onclick="toggleAIPanel()" title="AI Build Assistant">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M6 12.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5M3 8.062C3 6.76 4.235 5.765 5.53 5.886a26.6 26.6 0 0 0 4.94 0C11.765 5.765 13 6.76 13 8.062v1.157a1.75 1.75 0 0 1-1.267 1.679l-.323.093A2.31 2.31 0 0 1 10 11.584V12H6v-.416a2.31 2.31 0 0 1-1.41-1.093l-.323-.093A1.75 1.75 0 0 1 3 9.219zm4.247 2.024.062.14a.5.5 0 0 0 .461.308h.46a.5.5 0 0 0 .461-.308l.062-.14A1 1 0 0 1 9.6 9.988l.2-.3a.5.5 0 0 0 .083-.276V8.52a25 25 0 0 0-3.766 0v.892a.5.5 0 0 0 .083.276l.2.3a1 1 0 0 1 .847.566"/>
                    <path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8"/>
                </svg>
                <span>AI Assistant</span>
            </button>

            <div id="aiAssistantPanel" class="ai-panel" style="display:none;">
                <div class="ai-panel-header">
                    <div class="ai-panel-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M6 12.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5M3 8.062C3 6.76 4.235 5.765 5.53 5.886a26.6 26.6 0 0 0 4.94 0C11.765 5.765 13 6.76 13 8.062v1.157a1.75 1.75 0 0 1-1.267 1.679l-.323.093A2.31 2.31 0 0 1 10 11.584V12H6v-.416a2.31 2.31 0 0 1-1.41-1.093l-.323-.093A1.75 1.75 0 0 1 3 9.219zm4.247 2.024.062.14a.5.5 0 0 0 .461.308h.46a.5.5 0 0 0 .461-.308l.062-.14A1 1 0 0 1 9.6 9.988l.2-.3a.5.5 0 0 0 .083-.276V8.52a25 25 0 0 0-3.766 0v.892a.5.5 0 0 0 .083.276l.2.3a1 1 0 0 1 .847.566"/>
                            <path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8"/>
                        </svg>
                        AI Build Assistant
                    </div>
                    <button class="ai-panel-close" onclick="toggleAIPanel()">✕</button>
                </div>

                <div id="aiMessages" class="ai-messages">
                    <div class="ai-msg ai-msg-bot">
                        <div class="ai-msg-bubble">
                            Hey! I'm your build assistant. Tell me what you're going for — like <em>"build me a performance setup"</em>, <em>"add a better exhaust"</em>, or <em>"clear my build and start fresh"</em>.
                        </div>
                    </div>
                </div>

                <div class="ai-input-row">
                    <input type="text" id="aiInput" class="ai-input" placeholder="Ask me to build something..." onkeydown="if(event.key==='Enter') sendAIMessage()">
                    <button id="aiSendBtn" class="ai-send-btn" onclick="sendAIMessage()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 1.59 2.498C8 14 8 13 8 12.5a4.5 4.5 0 0 1 5.026-4.47zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471z"/>
                        </svg>
                    </button>
                </div>
                            </div>
                    </div>
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

    const filtered = ALL_CARS.filter(c => {
        const brandMatch = !brand || c.brand.toLowerCase().includes(brand) || c.model.toLowerCase().includes(brand) || c.name.toLowerCase().includes(brand);
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

document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.car-search-wrapper');
    const list = document.getElementById('carResultsList');
    if (wrapper && list && !wrapper.contains(e.target)) {
        list.style.display = 'none';
    }
});

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

        const d = {
            id: event.dataTransfer.getData("partId"),
            name: event.dataTransfer.getData("name"),
            price: parseFloat(event.dataTransfer.getData("price")),
            link: event.dataTransfer.getData("link"),
            category: event.dataTransfer.getData("category"),
            engine: event.dataTransfer.getData("engine"),
            chassis: event.dataTransfer.getData("chassis"),
            yStart: parseInt(event.dataTransfer.getData("yearStart")),
            yEnd: parseInt(event.dataTransfer.getData("yearEnd"))
        };

        // NEW: Check if a part from this category is already in the build
        if (buildParts.some(p => p.category === d.category)) {
            alert(`You already have a part from the ${d.category} category in your build. Please remove it first.`);
            return; // Stop the drop execution
        }

        // Check compatibility using the helper
        const isCompatible = checkPartCompatibility(d.engine, d.chassis, d.yStart, d.yEnd);

        const slotMap = { 'Exhaust': 'exhaust', 'Intake': 'intake', 'Suspension': 'suspension', 'Wheels': 'wheels' };

        buildParts.push({ 
            part_id: d.id, 
            name: d.name, 
            price: d.price, 
            link: d.link,
            position: slotMap[d.category] || 'general',
            category: d.category, // NEW: Save the category so we can check it later
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

        // 2. FIXED: If the part has no codes assigned at all, it's universally compatible 
        // This allows Wheels, Tires, and universal accessories to show up for all cars.
        if (partEng === "" && partChas === "") {
            return true; 
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

    fetch('/stock_hp.php', {
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
        fetch('/estimate_hp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                car: carName,
                stock_hp: stockHpValue,
                parts: compatibleParts.map(p => ({ id: p.part_id, name: p.name }))
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
    // --- Prefill Parts from Fork / Edit ---
    const PREFILL_PARTS = <?= $prefill_parts_json; ?>;
    if (PREFILL_PARTS && PREFILL_PARTS.length > 0) {
        PREFILL_PARTS.forEach(prefillPart => {
            const domPart = document.querySelector(`.part-item[data-part-id="${prefillPart.part_id}"]`);
            let link = prefillPart.link || '';
            let category = 'general'; // Default fallback
            let isCompatible = true;

            if (domPart) {
                const d = domPart.dataset;
                link = d.link || link;
                category = d.category || category; // Grab category from DOM
                isCompatible = checkPartCompatibility(d.engine, d.chassis, parseInt(d.yearStart), parseInt(d.yearEnd));
            }

            buildParts.push({
                part_id: String(prefillPart.part_id),
                name: prefillPart.name,
                price: prefillPart.price,
                link: link,
                position: prefillPart.position || 'general',
                category: category, // Save category during prefill
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

// ── AI Assistant ──
const AI_PARTS = <?= $ai_parts_json ?? 'null'; ?>;

// Map part_id (as string or number) → part object for fast lookup
const AI_PARTS_MAP = {};
if (AI_PARTS) {
    AI_PARTS.forEach(p => { AI_PARTS_MAP[String(p.id)] = p; });
}

function toggleAIPanel() {
    const panel = document.getElementById('aiAssistantPanel');
    const isHidden = panel.style.display === 'none';
    panel.style.display = isHidden ? 'block' : 'none';
    if (isHidden) {
        document.getElementById('aiInput').focus();
        scrollAIMessages();
    }
}

function scrollAIMessages() {
    const box = document.getElementById('aiMessages');
    if (box) box.scrollTop = box.scrollHeight;
}

function appendAIMessage(role, html, extraClass) {
    const box = document.getElementById('aiMessages');
    const row = document.createElement('div');
    row.className = 'ai-msg ai-msg-' + role + (extraClass ? ' ' + extraClass : '');
    row.innerHTML = '<div class="ai-msg-bubble">' + html + '</div>';
    box.appendChild(row);
    scrollAIMessages();
    return row;
}

async function sendAIMessage() {
    const input = document.getElementById('aiInput');
    const sendBtn = document.getElementById('aiSendBtn');
    const message = input.value.trim();
    if (!message) return;

    input.value = '';
    input.disabled = true;
    sendBtn.disabled = true;

    // Show user message
    appendAIMessage('user', escapeHtml(message));

    // Show thinking indicator
    const thinkingRow = appendAIMessage('bot', 'Thinking...', 'ai-msg-thinking');

    try {
        const response = await fetch('/ai_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: message,
                car: carData,
                available_parts: AI_PARTS || [],
                current_build: buildParts,
            }),
        });

        const data = await response.json();
        thinkingRow.remove();

        if (data.error) {
            appendAIMessage('bot', '<span style="color:#c05050;">' + escapeHtml(data.error) + '</span>');
        } else {
            const actions = data.actions || [];
            const applied = applyAIActions(actions);

            let html = escapeHtml(data.message);

            // Show action tags
            if (applied.added.length > 0 || applied.removed.length > 0) {
                html += '<div class="ai-actions-applied">';
                applied.added.forEach(name => {
                    html += '<span class="ai-action-tag added">+ ' + escapeHtml(name) + '</span>';
                });
                applied.removed.forEach(name => {
                    html += '<span class="ai-action-tag removed">− ' + escapeHtml(name) + '</span>';
                });
                html += '</div>';
            }

            appendAIMessage('bot', html);
        }
    } catch (e) {
        thinkingRow.remove();
        appendAIMessage('bot', '<span style="color:#c05050;">Something went wrong. Please try again.</span>');
    } finally {
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
    }
}

function applyAIActions(actions) {
    const result = { added: [], removed: [] };
    if (!actions || !actions.length) return result;

    actions.forEach(action => {
        const partId = String(action.part_id);
        const part   = AI_PARTS_MAP[partId];
        if (!part) return;

        if (action.action === 'remove') {
            const idx = buildParts.findIndex(p => String(p.part_id) === partId);
            if (idx !== -1) {
                result.removed.push(buildParts[idx].name);
                buildParts.splice(idx, 1);
            }
        } else if (action.action === 'add') {
            // Skip if already in build
            if (buildParts.some(p => String(p.part_id) === partId)) return;

            const slotMap = { 'Exhaust': 'exhaust', 'Intake': 'intake', 'Suspension': 'suspension', 'Wheels': 'wheels' };
            const isCompatible = checkPartCompatibility(part.engine, part.chassis, part.year_start, part.year_end);

            // Build a proper link via redirect
            const link = part.link
                ? (part.link.match(/^https?:\/\//) ? part.link : 'https://' + part.link)
                : '';

            buildParts.push({
                part_id:      partId,
                name:         part.name,
                price:        part.price,
                link:         link,
                position:     slotMap[part.category] || 'general',
                category:     part.category,
                isCompatible: isCompatible,
            });
            result.added.push(part.name);
        }
    });

    if (result.added.length > 0 || result.removed.length > 0) {
        updateBuildDisplay();
        filterParts();
    }

    return result;
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

</script>

<?php renderFooter(); ?>