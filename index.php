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
    $parts = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
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
    .incompatible-badge { color: #ff4d4d; font-size: 0.8rem; font-weight: bold; margin-left: 10px; }
    .summary-warning { color: #ff4d4d; font-weight: bold; margin-top: 5px; display: none; }
    .build-item-row.is-incompatible { border-left: 3px solid #ff4d4d; background: rgba(255, 77, 77, 0.1); }

    .btn:disabled {
        background-color: #444 !important;
        color: #888 !important;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .btn-outline-danger {
        background: transparent;
        border: 1px solid #ff4d4d;
        color: #ff4d4d;
        margin-top: 10px;
    }
    .btn-outline-danger:hover {
        background: #ff4d4d;
        color: #fff;
    }
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
                <input type="text" id="searchParts" placeholder="Search parts..." onkeyup="filterParts()">

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
                             draggable="true"
                             ondragstart="drag(event)">
                            <?php if ($part['image']): ?>
                                <img src="<?= htmlspecialchars($part['image']); ?>">
                            <?php endif; ?>
                            <h4><?= htmlspecialchars($part['name']); ?></h4>
                            <p class="price">$<?= number_format($part['price'], 2); ?></p>
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
let buildParts = [];

function drag(event) {
    const d = event.target.dataset;
    event.dataTransfer.setData("partId", d.partId);
    event.dataTransfer.setData("partName", d.name);
    event.dataTransfer.setData("partPrice", d.price);
    event.dataTransfer.setData("partCategory", d.category);
    event.dataTransfer.setData("partEngine", d.engine);
    event.dataTransfer.setData("partChassis", d.chassis);
    event.dataTransfer.setData("yearStart", d.yearStart);
    event.dataTransfer.setData("yearEnd", d.yearEnd);
}

function allowDrop(event) { event.preventDefault(); event.currentTarget.classList.add('drag-over'); }
function dragLeave(event) { event.currentTarget.classList.remove('drag-over'); }

function drop(event) {
    event.preventDefault();
    event.currentTarget.classList.remove('drag-over');

    const d = {
        id: event.dataTransfer.getData("partId"),
        name: event.dataTransfer.getData("partName"),
        price: parseFloat(event.dataTransfer.getData("partPrice")),
        category: event.dataTransfer.getData("partCategory"),
        engine: event.dataTransfer.getData("partEngine"),
        chassis: event.dataTransfer.getData("partChassis"),
        yStart: parseInt(event.dataTransfer.getData("yearStart")),
        yEnd: parseInt(event.dataTransfer.getData("yearEnd"))
    };

    const carYear = <?= (int)$selected_car['year']; ?>;
    const carEngine = "<?= $selected_car['engine_code']; ?>";
    const carChassis = "<?= $selected_car['chassis_code']; ?>";

    let isCompatible = true;
    if (d.engine && d.engine !== carEngine) isCompatible = false;
    if (d.chassis && d.chassis !== carChassis) isCompatible = false;
    if (carYear < d.yStart || carYear > d.yEnd) isCompatible = false;

    const slotMap = { 'Exhaust': 'exhaust', 'Intake': 'intake', 'Suspension': 'suspension', 'Wheels': 'wheels' };

    buildParts.push({ 
        part_id: d.id, 
        name: d.name, 
        price: d.price, 
        position: slotMap[d.category] || 'general',
        isCompatible: isCompatible 
    });

    updateBuildDisplay();
    updatePartsList();
}

function updateBuildDisplay() {
    const buildPartsDiv = document.getElementById('buildParts');
    const totalPrice = buildParts.reduce((sum, p) => sum + p.price, 0);
    const incompatibleList = buildParts.filter(p => !p.isCompatible);
    const saveBtn = document.getElementById('saveBuildBtn');
    const clearBtn = document.getElementById('clearBuildBtn');

    buildPartsDiv.innerHTML = buildParts.map((p, i) => `
        <div class="build-item-row ${!p.isCompatible ? 'is-incompatible' : ''}">
            <span>
                <strong>${p.name}</strong> ($${p.price.toFixed(2)})
                ${!p.isCompatible ? '<span class="incompatible-badge">✕ Incompatible</span>' : ''}
            </span>
            <button onclick="removePart(${i})">×</button>
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

    // Toggle Clear All Button
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

function updatePartsList() {
    const usedIds = new Set(buildParts.map(p => p.part_id));
    document.querySelectorAll('.part-item').forEach(p => {
        p.style.display = usedIds.has(p.dataset.partId) ? 'none' : 'block';
    });
}

function filterParts() {
    const s = document.getElementById('searchParts').value.toLowerCase();
    document.querySelectorAll('.part-item').forEach(p => {
        p.style.display = p.dataset.name.toLowerCase().includes(s) ? 'block' : 'none';
    });
}

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