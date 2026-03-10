<?php
require_once 'config.php';

// Prefill logic
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

$cars = $conn->query("SELECT * FROM cars ORDER BY brand, model, year");
$selected_car_id = $_GET['car_id'] ?? ($_SESSION['selected_car_id'] ?? null);

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

    $stmt = $conn->prepare("INSERT INTO builds (user_id, car_id, build_title, total_price, is_community_shared) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisdi", $_SESSION['user_id'], $selected_car_id, $build_title, $total_price, $is_shared);
    if ($stmt->execute()) {
        $build_id = $conn->insert_id;
        foreach ($build_data as $item) {
            $stmt = $conn->prepare("INSERT INTO build_parts (build_id, part_id, position_data) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $build_id, $item['part_id'], $item['position']);
            $stmt->execute();
        }
        header('Location: /user/profile.php');
        exit;
    }
}

$pageTitle = "Build Your Car - ModMyCar";
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

    /* Filter Toolbar Styles */
    .filters-toolbar { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; }
    .search-input { flex: 1; margin-bottom: 0; } /* Overwrite default mb */

    /* Toggle Switch Style */
    .toggle-container { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer; user-select: none; }
    .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #555; transition: .4s; border-radius: 20px; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #2196F3; }
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
        color: #2196F3;
        background: rgba(0,0,0,0.6);
        padding: 5px;
        border-radius: 4px;
        line-height: 0;
        transition: color 0.2s;
        z-index: 2;
    }
    .affiliate-link:hover { color: #fff; background: #2196F3; }
    .affiliate-link-mini { color: #2196F3; line-height: 0; display: flex; align-items: center; }
    .affiliate-link-mini:hover { color: #fff; }
    
    /* Ensure icon visibility in the card */
    .part-item h4 { margin-right: 35px; } /* Make room for the top-right icon */
</style>

<div class="container">
    <h2>Build Your Dream Car</h2>

    <div class="card">
        <h3>Select Your Car</h3>
        <form method="GET">
            <select name="car_id" onchange="this.form.submit()" required>
                <option value="">Choose a car...</option>
                <?php $cars->data_seek(0); while ($car = $cars->fetch_assoc()): ?>
                    <option value="<?= $car['car_id']; ?>" <?= ($selected_car_id == $car['car_id']) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($car['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </form>
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
                        <div class="part-item"
                             data-part-id="<?= $part['part_id']; ?>"
                             data-category="<?= htmlspecialchars($part['category']); ?>"
                             data-name="<?= htmlspecialchars($part['name']); ?>"
                             data-price="<?= $part['price']; ?>"
                             data-engine="<?= htmlspecialchars($part['engine_code'] ?? ''); ?>"
                             data-chassis="<?= htmlspecialchars($part['chassis_code'] ?? ''); ?>"
                             data-year-start="<?= $part['year_start'] ?? 0; ?>"
                             data-year-end="<?= $part['year_end'] ?? 9999; ?>"
                             data-link="<?= htmlspecialchars($part['base_url'] . ($part['affiliate_id'] ?? '')); ?>"
                             draggable="true"
                             ondragstart="drag(event)">
                            <?php if ($part['image']): ?>
                                <img src="<?= htmlspecialchars($part['image']); ?>">
                            <?php endif; ?>
                            <h4><?= htmlspecialchars($part['name']); ?></h4>
                            <p class="price">$<?= number_format($part['price'], 2); ?></p>
                            <div style="margin-top: 10px; display: flex; gap: 10px;">
                                <a href="<?= htmlspecialchars(($part['base_url'] ?? '') . ($part['affiliate_id'] ?? '')); ?>" target="_blank" class="btn btn-sm" style="width: 80px; text-align: center; text-decoration: none; background: #2196F3; color: white; padding: 5px; border-radius: 4px; font-size: 0.8rem;">View</a>
                            </div>
                            <?php if (!empty($part['base_url'])): ?>
                                <a href="<?= htmlspecialchars($part['base_url'] . ($part['affiliate_id'] ?? '')); ?>" target="_blank" class="affiliate-link" title="View on Store" onclick="event.stopPropagation();">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-up-left-square" viewBox="0 0 16 16">
                                        <path fill-rule="evenodd" d="M15 2a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1zM0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm10.096 8.803a.5.5 0 1 0 .707-.707L6.707 6h2.768a.5.5 0 1 0 0-1H5.5a.5.5 0 0 0-.5.5v3.975a.5.5 0 0 0 1 0V6.707z"/>
                                    </svg>
                                </a>
                            <?php endif; ?>
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
        <form method="POST" onsubmit="prepareBuildData()">
            <input type="text" name="build_title" required placeholder="Build Title">
            <input type="hidden" name="total_price" id="saveTotalPrice">
            <input type="hidden" name="build_data" id="saveBuildData">
            <button type="submit" name="save_build" class="btn">Save Build</button>
        </form>
    </div>
</div>

<script>
// --- Global State ---
let buildParts = [];
let currentCategory = 'all';
let showCompatibleOnly = false;

// --- Car Data (Passed from PHP) ---
const carData = {
    year: <?= (int)$selected_car['year']; ?>,
    engine: "<?= $selected_car['engine_code']; ?>",
    chassis: "<?= $selected_car['chassis_code']; ?>"
};

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
    updatePartsList(); // Refresh list to hide added parts
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
}

function removePart(index) {
    buildParts.splice(index, 1);
    updateBuildDisplay();
    updatePartsList();
}

function clearAllParts() {
    if(confirm("Are you sure you want to clear your current build?")) {
        buildParts = [];
        updateBuildDisplay();
        updatePartsList();
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

// --- Save Modal ---
function showSaveModal() { 
    if(document.getElementById('saveBuildBtn').disabled) return;
    document.getElementById('saveModal').classList.add('active'); 
}

function prepareBuildData() {
    document.getElementById('saveTotalPrice').value = buildParts.reduce((sum, p) => sum + p.price, 0).toFixed(2);
    document.getElementById('saveBuildData').value = JSON.stringify(buildParts);
}
</script>

<?php renderFooter(); ?>