<?php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: /user/login.php');
    exit;
}

$user = getUserData($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_build'])) {
    $build_id = (int)$_POST['build_id'];
    
    $stmt = $conn->prepare("SELECT user_id FROM builds WHERE build_id = ?");
    $stmt->bind_param("i", $build_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $build = $result->fetch_assoc();
        if ($build['user_id'] == $_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM builds WHERE build_id = ?");
            $stmt->bind_param("i", $build_id);
            $stmt->execute();
            header('Location: profile.php?deleted=success');
            exit;
        }
    }
}

$my_builds = $conn->prepare("
    SELECT b.*, c.name as car_name, c.brand, c.model, c.year 
    FROM builds b 
    JOIN cars c ON b.car_id = c.car_id 
    WHERE b.user_id = ? 
    ORDER BY b.date_created DESC
");
$my_builds->bind_param("i", $_SESSION['user_id']);
$my_builds->execute();
$my_builds_result = $my_builds->get_result();

$saved_builds = $conn->prepare("
    SELECT b.*, c.name as car_name, u.username as creator_name 
    FROM user_saved_builds usb 
    JOIN builds b ON usb.build_id = b.build_id 
    JOIN cars c ON b.car_id = c.car_id 
    JOIN users u ON b.user_id = u.uid 
    WHERE usb.user_id = ? 
    ORDER BY usb.date_saved DESC
");
$saved_builds->bind_param("i", $_SESSION['user_id']);
$saved_builds->execute();
$saved_builds_result = $saved_builds->get_result();

$pageTitle = htmlspecialchars($user['username']);
require_once '../includes/headerFooter.php';
renderHeader();

$show_confetti = isset($_SESSION['build_saved_success']) && $_SESSION['build_saved_success'];
if ($show_confetti) {
    unset($_SESSION['build_saved_success']);
}
?>

<canvas id="confetti-canvas"></canvas>

<div class="container">
    <?php if (isset($_GET['deleted']) && $_GET['deleted'] === 'success'): ?>
        <div class="alert alert-success">Build deleted successfully.</div>
    <?php endif; ?>

    <div class="profile-header">
        <?php if ($user['profileImage']): ?>
            <img src="<?php echo htmlspecialchars($user['profileImage']); ?>" alt="Profile" class="profile-image">
        <?php else: ?>
            <div class="profileImage profile-image-placeholder">
                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
            </div>
        <?php endif; ?>
        <div>
            <h2><?php echo htmlspecialchars($user['username']); ?></h2>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
            <a href="editProfile.php" class="btn mt-1">Edit Profile</a>
        </div>
    </div>

    <div class="tabs">
        <button class="active" onclick="showTab('my-builds')">My Builds</button>
        <button onclick="showTab('saved-builds')">Saved Builds</button>
    </div>

    <div id="my-builds" class="tab-content">
        <h3>My Original Builds</h3>
        <?php if ($my_builds_result->num_rows > 0): ?>
            <div class="community-grid">
                <?php while ($build = $my_builds_result->fetch_assoc()): ?>
                    <div class="build-card">
                        <img src="<?php echo !empty($build['featured_image']) ? htmlspecialchars($build['featured_image']) : '/assets/placeholder-build.svg'; ?>" alt="Build" class="build-card-img<?php echo empty($build['featured_image']) ? ' build-card-img--placeholder' : ''; ?>">
                        <div class="build-card-content">
                            <h3><?php echo htmlspecialchars($build['build_title']); ?></h3>
                            <p><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price price-bold">$<?php echo number_format($build['total_price'], 2); ?></p>
                            <p class="text-muted">
                                <?php echo $build['is_community_shared'] ? 'Shared with Community' : 'Private'; ?>
                            </p>
                            <div class="build-actions">
                                <a href="/index.php?load_build=<?php echo $build['build_id']; ?>" class="btn btn-secondary">Edit</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this build?');">
                                    <input type="hidden" name="build_id" value="<?php echo $build['build_id']; ?>">
                                    <button type="submit" name="delete_build" class="btn bg-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p class="empty-state-text">Add your first part to start building</p>
                <p class="empty-state-subtext">Create a custom car build by adding parts and components</p>
                <a href="/index.php" class="start-link">Start Building →</a>
            </div>
        <?php endif; ?>
    </div>

    <div id="saved-builds" class="tab-content" style="display: none;">
        <h3>Saved Builds</h3>
        <?php if ($saved_builds_result->num_rows > 0): ?>
            <div class="community-grid">
                <?php while ($build = $saved_builds_result->fetch_assoc()): ?>
                    <div class="build-card">
                        <img src="<?php echo !empty($build['featured_image']) ? htmlspecialchars($build['featured_image']) : '/assets/placeholder-build.svg'; ?>" alt="Build" class="build-card-img<?php echo empty($build['featured_image']) ? ' build-card-img--placeholder' : ''; ?>">
                        <div class="build-card-content">
                            <h3><?php echo htmlspecialchars($build['build_title']); ?></h3>
                            <p>by <?php echo htmlspecialchars($build['creator_name']); ?></p>
                            <p><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price price-bold">$<?php echo number_format($build['total_price'], 2); ?></p>
                            <div class="build-actions">
                                <a href="/community.php?build=<?php echo $build['build_id']; ?>" class="btn btn-secondary">View</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>You haven't saved any builds yet. <a href="/community.php" class="accent-link">Explore the community!</a></p>
        <?php endif; ?>
    </div>
</div>

<script>
function showTab(tabId) {
    const tabs = document.querySelectorAll('.tab-content');
    const buttons = document.querySelectorAll('.tabs button');
    
    tabs.forEach(tab => tab.style.display = 'none');
    buttons.forEach(btn => btn.classList.remove('active'));
    
    document.getElementById(tabId).style.display = 'block';
    event.target.classList.add('active');
}

<?php if ($show_confetti): ?>
// Confetti animation
const canvas = document.getElementById('confetti-canvas');
const ctx = canvas.getContext('2d');

canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

const confetti = [];

class Confetto {
    constructor() {
        this.x = Math.random() * canvas.width;
        this.y = Math.random() * canvas.height - canvas.height;
        this.vx = (Math.random() - 0.5) * 8;
        this.vy = Math.random() * 3 + 4;
        this.gravity = 0.1;
        this.rotation = Math.random() * Math.PI * 2;
        this.rotationSpeed = (Math.random() - 0.5) * 0.2;
        this.size = Math.random() * 6 + 4;
        this.color = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#FFD93D', '#FF6B9D'][Math.floor(Math.random() * 7)];
        this.opacity = 1;
    }

    update() {
        this.x += this.vx;
        this.y += this.vy;
        this.vy += this.gravity;
        this.rotation += this.rotationSpeed;
        this.opacity -= 0.01;
    }

    draw() {
        ctx.save();
        ctx.globalAlpha = this.opacity;
        ctx.translate(this.x, this.y);
        ctx.rotate(this.rotation);
        ctx.fillStyle = this.color;
        ctx.fillRect(-this.size / 2, -this.size / 2, this.size, this.size);
        ctx.restore();
    }
}

function createConfetti() {
    for (let i = 0; i < 100; i++) {
        confetti.push(new Confetto());
    }
}

function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    for (let i = confetti.length - 1; i >= 0; i--) {
        confetti[i].update();
        confetti[i].draw();
        
        if (confetti[i].opacity <= 0 || confetti[i].y > canvas.height) {
            confetti.splice(i, 1);
        }
    }

    if (confetti.length > 0) {
        requestAnimationFrame(animate);
    } else {
        canvas.style.display = 'none';
    }
}

window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
});

createConfetti();
animate();
<?php endif; ?>
</script>

<?php renderFooter(); ?>
