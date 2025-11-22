<?php
require_once 'config.php';

// Prefill logic: if user arrived from a fork, read prefills from session
$prefill_build = null;
if (!empty($_SESSION['prefill_build'])) {
    $prefill_build = $_SESSION['prefill_build'];
    $selected_car_id = $prefill_build['car_id'];
    unset($_SESSION['prefill_build']);
}

// Prefill logic: if user arrived from a shared link, decode the build data
if (!empty($_GET['share_build'])) {
    $decoded_build = json_decode(base64_decode($_GET['share_build'], true), true);
    if ($decoded_build && !empty($decoded_build['car_id'])) {
        $prefill_build = $decoded_build;
        $selected_car_id = $decoded_build['car_id'];
    }
}

// Prepare JS variables for client-side use
$prefill_parts_json = $prefill_build ? json_encode($prefill_build['parts'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';
$prefill_total_price = $prefill_build ? number_format((float)$prefill_build['total_price'], 2, '.', '') : '0.00';
$prefill_build_title = $prefill_build ? addslashes($prefill_build['build_title']) : '';

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

    $stmt = $conn->prepare("
        SELECT p.*, a.base_url 
        FROM parts p 
        JOIN part_compatibility pc ON p.part_id = pc.part_id 
        LEFT JOIN affiliate_sources a ON p.source_id = a.source_id 
        WHERE pc.car_id = ? 
        ORDER BY p.category, p.name
    ");
    $stmt->bind_param("i", $selected_car_id);
    $stmt->execute();
    $parts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_build'])) {
    if (!isLoggedIn()) {
        header('Location: /user/login.php');
        exit;
    }

    $build_title = trim($_POST['build_title'] ?? '');
    $total_price = (float)($_POST['total_price'] ?? 0);
    $is_shared = isset($_POST['share_community']) ? 1 : 0;
    $build_data = json_decode($_POST['build_data'] ?? '[]', true);

    if (empty($build_title)) {
        $error = 'Build title is required.';
    } elseif (empty($build_data)) {
        $error = 'Please add at least one part to your build.';
    } else {
        $stmt = $conn->prepare("INSERT INTO builds (user_id, car_id, build_title, total_price, is_community_shared) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisdi", $_SESSION['user_id'], $selected_car_id, $build_title, $total_price, $is_shared);
        
        if ($stmt->execute()) {
            $build_id = $conn->insert_id;

            foreach ($build_data as $item) {
                $stmt = $conn->prepare("INSERT INTO build_parts (build_id, part_id, position_data) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $build_id, $item['part_id'], $item['position']);
                $stmt->execute();
            }

            $_SESSION['build_saved_success'] = true;
            header('Location: /user/profile.php');
            exit;
        } else {
            $error = 'Failed to save build.';
        }
    }
}

$pageTitle = "Build Your Car - ModMyCar";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <h2>Build Your Dream Car</h2>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Select Your Car</h3>
        <form method="GET">
            <select name="car_id" onchange="this.form.submit()" required>
                <option value="">Choose a car...</option>
                <?php while ($car = $cars->fetch_assoc()): ?>
                    <option value="<?php echo $car['car_id']; ?>" <?php echo ($selected_car_id == $car['car_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($car['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </form>
    </div>

    <?php if ($selected_car): ?>
        <div class="build-area">
            <div class="parts-panel">
                <h3>Available Parts</h3>
                <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1.5rem;">
                    <input type="text" id="searchParts" placeholder="Search parts..." onkeyup="filterParts()" style="flex: 1; margin-bottom: 0;">
                    <div class="category-dropdown">
                        <button id="categoryBtn" class="filter-icon-btn" onclick="toggleCategoryDropdown()" title="Filter by category">☰</button>
                        <div id="categoryDropdown" class="dropdown-menu" style="display: none;">
                            <div class="dropdown-item" onclick="selectCategory('all', 'All')">All Categories</div>
                            <div class="dropdown-item" onclick="selectCategory('Exhaust', 'Exhaust')">Exhaust</div>
                            <div class="dropdown-item" onclick="selectCategory('Intake', 'Intake')">Intake</div>
                            <div class="dropdown-item" onclick="selectCategory('Suspension', 'Suspension')">Suspension</div>
                            <div class="dropdown-item" onclick="selectCategory('Wheels', 'Wheels')">Wheels</div>
                            <div class="dropdown-item" onclick="selectCategory('Tires', 'Tires')">Tires</div>
                            <div class="dropdown-item" onclick="selectCategory('Brakes', 'Brakes')">Brakes</div>
                        </div>
                    </div>
                </div>

                <div id="partsList">
                    <?php foreach ($parts as $part): ?>
                        <div class="part-item" 
                             data-part-id="<?php echo $part['part_id']; ?>"
                             data-category="<?php echo htmlspecialchars($part['category']); ?>"
                             data-name="<?php echo htmlspecialchars($part['name']); ?>"
                             data-price="<?php echo $part['price']; ?>"
                             draggable="true"
                             ondragstart="drag(event)">
                            <?php if ($part['image']): ?>
                                <img src="<?php echo htmlspecialchars($part['image']); ?>" alt="<?php echo htmlspecialchars($part['name']); ?>">
                            <?php endif; ?>
                            <h4><?php echo htmlspecialchars($part['name']); ?></h4>
                            <p class="price">$<?php echo number_format($part['price'], 2); ?></p>
                            <a href="/redirect.php?part_id=<?php echo $part['part_id']; ?>" target="_blank" class="btn btn-secondary">View</a>
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
                    <p><strong>Car:</strong> <?php echo htmlspecialchars($selected_car['name']); ?></p>
                    <p><strong>Total Price:</strong> $<span id="totalPrice">0.00</span></p>
                    <p><strong>Parts Count:</strong> <span id="partsCount">0</span></p>

                    <div id="compatibilityCheck" style="margin-top: 1rem; padding: 0.75rem; border-radius: 5px; font-weight: 500; display: none;">
                        <span id="compatibilityStatus"></span>
                    </div>

                    <div id="similarBuildsContainer" style="display: none; margin-top: 1.5rem; padding: 1rem; background: var(--bg-primary); border-radius: 5px; border-left: 4px solid var(--accent-1);">
                        <h5 style="margin-top: 0; margin-bottom: 0.75rem;">Similar Builds</h5>
                        <div id="similarBuildsList"></div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap;">
                        <button class="btn" onclick="generateShareableLink(this)" style="flex: 1; min-width: 150px;">Share Link</button>
                        <?php if (isLoggedIn()): ?>
                            <button class="btn" onclick="showSaveModal()" style="flex: 1; min-width: 150px;">Save Build</button>
                        <?php else: ?>
                            <a href="/user/login.php" class="btn" style="flex: 1; min-width: 150px; text-align: center;">Login to Save</a>
                        <?php endif; ?>
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
            <div class="form-group">
                <label for="build_title">Build Title *</label>
                <input type="text" id="build_title" name="build_title" required placeholder="e.g., My Street Racing Setup">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="share_community" value="1">
                    Share with Community
                </label>
            </div>
            <input type="hidden" name="total_price" id="saveTotalPrice">
            <input type="hidden" name="build_data" id="saveBuildData">
            <button type="submit" name="save_build" class="btn">Save Build</button>
        </form>
    </div>
</div>

<script>
let buildParts = [];
let currentCategory = 'all';

function drag(event) {
    event.dataTransfer.setData("partId", event.target.dataset.partId);
    event.dataTransfer.setData("partName", event.target.dataset.name);
    event.dataTransfer.setData("partPrice", event.target.dataset.price);
    event.dataTransfer.setData("partCategory", event.target.dataset.category);
}

function allowDrop(event) { event.preventDefault(); event.currentTarget.classList.add('drag-over'); }
function dragLeave(event) { event.currentTarget.classList.remove('drag-over'); }

function drop(event) {
    event.preventDefault(); 
    event.currentTarget.classList.remove('drag-over');

    const partId = event.dataTransfer.getData("partId");
    const partName = event.dataTransfer.getData("partName");
    const partPrice = parseFloat(event.dataTransfer.getData("partPrice"));
    const partCategory = event.dataTransfer.getData("partCategory");

    const slotMap = {
        'Exhaust': 'exhaust', 'Intake': 'intake', 'Suspension': 'suspension',
        'Wheels': 'wheels', 'Tires': 'tires', 'Brakes': 'brakes'
    };
    const position = slotMap[partCategory] || 'general';

    buildParts.push({ part_id: partId, name: partName, price: partPrice, position: position });
    updateBuildDisplay();
    updatePartsList();
}

function removePart(index) { 
    buildParts.splice(index, 1); 
    updateBuildDisplay();
    updatePartsList();
}

function updatePartsList() {
    const usedPartIds = new Set(buildParts.map(p => p.part_id));
    document.querySelectorAll('.part-item').forEach(part => {
        const partId = part.dataset.partId;
        part.style.display = usedPartIds.has(partId) ? 'none' : 'block';
    });
}

function checkCompatibility() {
    if (buildParts.length === 0) {
        document.getElementById('compatibilityCheck').style.display = 'none';
        return true;
    }

    const positions = buildParts.map(p => p.position);
    const uniquePositions = new Set(positions);
    const isCompatible = positions.length === uniquePositions.size;

    const compatibilityDiv = document.getElementById('compatibilityCheck');
    const statusSpan = document.getElementById('compatibilityStatus');

    if (isCompatible) {
        compatibilityDiv.style.display = 'block';
        compatibilityDiv.style.backgroundColor = '#dcfce7';
        compatibilityDiv.style.borderLeft = '4px solid #22c55e';
        statusSpan.innerHTML = '✓ <span style="color: #16a34a;">All parts are compatible</span>';
    } else {
        compatibilityDiv.style.display = 'block';
        compatibilityDiv.style.backgroundColor = '#fee2e2';
        compatibilityDiv.style.borderLeft = '4px solid #ef4444';
        statusSpan.innerHTML = '✗ <span style="color: #dc2626;">Duplicate part positions detected</span>';
    }

    return isCompatible;
}

function updateBuildDisplay() {
    const buildPartsDiv = document.getElementById('buildParts');
    const totalPrice = buildParts.reduce((sum, part) => sum + parseFloat(part.price), 0);

    buildPartsDiv.innerHTML = buildParts.map((part, index) => `
        <div style="background: var(--bg-primary); padding: 1rem; margin: 0.5rem 0; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>${part.name}</strong>
                <span style="color: var(--accent-1); margin-left: 1rem;">$${parseFloat(part.price).toFixed(2)}</span>
                <span style="color: var(--accent-2); margin-left: 1rem; font-size: 0.9rem;">[${part.position}]</span>
            </div>
            <button onclick="removePart(${index})" class="btn" style="background: #ef4444; padding: 0.5rem 1rem;">Remove</button>
        </div>`).join('');

    document.getElementById('totalPrice').textContent = totalPrice.toFixed(2);
    document.getElementById('partsCount').textContent = buildParts.length;
    checkCompatibility();
    fetchSimilarBuilds();
}

function fetchSimilarBuilds() {
    if (buildParts.length === 0) {
        document.getElementById('similarBuildsContainer').style.display = 'none';
        return;
    }

    const partIds = buildParts.map(p => p.part_id);
    const carId = <?php echo (int)$selected_car_id; ?>;

    const formData = new FormData();
    formData.append('car_id', carId);
    partIds.forEach(id => formData.append('part_ids[]', id));

    fetch('/api_similar_builds.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const container = document.getElementById('similarBuildsContainer');
        const list = document.getElementById('similarBuildsList');

        if (data.builds && data.builds.length > 0) {
            list.innerHTML = data.builds.map(build => `
                <div style="padding: 0.75rem; background: var(--bg-secondary); border-radius: 4px; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <p style="margin: 0; font-weight: 500; color: var(--text-primary);">${build.build_title}</p>
                        <p style="margin: 0.25rem 0 0 0; font-size: 0.85rem; color: var(--text-secondary);">by ${build.username} • ${build.parts_count} parts • $${parseFloat(build.total_price).toFixed(2)}</p>
                    </div>
                    <a href="/user/profile.php?view_build=${build.build_id}" class="btn" style="padding: 0.5rem 1rem; font-size: 0.9rem; white-space: nowrap; margin-left: 1rem;">View</a>
                </div>
            `).join('');
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
        }
    })
    .catch(err => console.error('Error fetching similar builds:', err));
}

function filterParts() {
    const searchTerm = document.getElementById('searchParts').value.toLowerCase();
    document.querySelectorAll('.part-item').forEach(part => {
        const name = part.dataset.name.toLowerCase();
        const category = part.dataset.category;
        part.style.display = (name.includes(searchTerm) && (currentCategory === 'all' || category === currentCategory)) ? 'block' : 'none';
    });
}

function toggleCategoryDropdown() {
    const dropdown = document.getElementById('categoryDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    document.addEventListener('click', closeDropdownOnClickOutside);
}

function closeDropdownOnClickOutside(event) {
    const dropdown = document.getElementById('categoryDropdown');
    const categoryDiv = document.querySelector('.category-dropdown');
    if (!categoryDiv.contains(event.target)) {
        dropdown.style.display = 'none';
        document.removeEventListener('click', closeDropdownOnClickOutside);
    }
}

function selectCategory(category, label) {
    currentCategory = category;
    document.getElementById('categoryDropdown').style.display = 'none';
    filterParts();
}

function showSaveModal() {
    if (!buildParts.length) { alert('Please add at least one part.'); return; }
    document.getElementById('saveModal').classList.add('active');
}

function prepareBuildData() {
    const totalPrice = buildParts.reduce((sum, part) => sum + parseFloat(part.price), 0);
    document.getElementById('saveTotalPrice').value = totalPrice.toFixed(2);
    document.getElementById('saveBuildData').value = JSON.stringify(buildParts);
    return true;
}

// Prefill parts after fork
<?php if ($prefill_parts_json !== 'null'): ?>
(function() {
    try {
        const prefillParts = <?php echo $prefill_parts_json; ?>;
        if (Array.isArray(prefillParts) && prefillParts.length) {
            buildParts = prefillParts.map(p => ({ part_id: p.part_id, name: p.name, price: parseFloat(p.price), position: p.position || 'general' }));
            updateBuildDisplay();
        }
    } catch(e) { console.error('Prefill error:', e); }
})();
<?php endif; ?>

function generateShareableLink(buttonElement) {
    if (!buildParts.length) {
        alert('Please add at least one part to your build before sharing.');
        return;
    }

    const buildData = {
        car_id: <?php echo (int)$selected_car_id; ?>,
        parts: buildParts,
        total_price: buildParts.reduce((sum, part) => sum + parseFloat(part.price), 0).toFixed(2),
        build_title: 'Shared Build'
    };

    const encodedBuild = btoa(JSON.stringify(buildData));
    const shareLink = window.location.origin + window.location.pathname + '?share_build=' + encodedBuild;

    // Copy to clipboard
    navigator.clipboard.writeText(shareLink).then(() => {
        // Show feedback
        const button = buttonElement || document.querySelector('button[onclick*="generateShareableLink"]');
        if (button) {
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.style.background = '#10b981';
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = '';
            }, 2000);
        }
    }).catch(err => {
        alert('Failed to copy link. Please try again.');
        console.error('Clipboard error:', err);
    });
}
</script>

<?php renderFooter(); ?>