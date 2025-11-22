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
?>

<div class="container">
    <?php if (isset($_GET['deleted']) && $_GET['deleted'] === 'success'): ?>
        <div class="alert alert-success">Build deleted successfully.</div>
    <?php endif; ?>

    <div class="profile-header">
        <?php if ($user['profileImage']): ?>
            <img src="<?php echo htmlspecialchars($user['profileImage']); ?>" alt="Profile" class="profile-image">
        <?php else: ?>
            <div class="profileImage" style="background: var(--accent-1); display: flex; align-items: center; justify-content: center; font-size: 3rem; color: white;">
                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
            </div>
        <?php endif; ?>
        <div>
            <h2><?php echo htmlspecialchars($user['username']); ?></h2>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
            <a href="editProfile.php" class="btn" style="margin-top: 1rem;">Edit Profile</a>
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
                        <?php if ($build['featured_image']): ?>
                            <img src="<?php echo htmlspecialchars($build['featured_image']); ?>" alt="Build">
                        <?php else: ?>
                            <div style="height: 250px; background: var(--bg-primary); display: flex; align-items: center; justify-content: center;">
                                <span>No Image</span>
                            </div>
                        <?php endif; ?>
                        <div class="build-card-content">
                            <h3><?php echo htmlspecialchars($build['build_title']); ?></h3>
                            <p><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price" style="color: var(--accent-1); font-weight: bold;">$<?php echo number_format($build['total_price'], 2); ?></p>
                            <p style="font-size: 0.9rem; color: var(--text-primary); opacity: 0.8;">
                                <?php echo $build['is_community_shared'] ? 'Shared with Community' : 'Private'; ?>
                            </p>
                            <div class="build-actions">
                                <a href="/index.php?load_build=<?php echo $build['build_id']; ?>" class="btn btn-secondary">Edit</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this build?');">
                                    <input type="hidden" name="build_id" value="<?php echo $build['build_id']; ?>">
                                    <button type="submit" name="delete_build" class="btn" style="background: #ef4444;">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>You haven't created any builds yet. <a href="/index.php" style="color: var(--accent-2);">Create your first build!</a></p>
        <?php endif; ?>
    </div>

    <div id="saved-builds" class="tab-content" style="display: none;">
        <h3>Saved Builds</h3>
        <?php if ($saved_builds_result->num_rows > 0): ?>
            <div class="community-grid">
                <?php while ($build = $saved_builds_result->fetch_assoc()): ?>
                    <div class="build-card">
                        <?php if ($build['featured_image']): ?>
                            <img src="<?php echo htmlspecialchars($build['featured_image']); ?>" alt="Build">
                        <?php else: ?>
                            <div style="height: 250px; background: var(--bg-primary); display: flex; align-items: center; justify-content: center;">
                                <span>No Image</span>
                            </div>
                        <?php endif; ?>
                        <div class="build-card-content">
                            <h3><?php echo htmlspecialchars($build['build_title']); ?></h3>
                            <p>by <?php echo htmlspecialchars($build['creator_name']); ?></p>
                            <p><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price" style="color: var(--accent-1); font-weight: bold;">$<?php echo number_format($build['total_price'], 2); ?></p>
                            <div class="build-actions">
                                <a href="/community.php?build=<?php echo $build['build_id']; ?>" class="btn btn-secondary">View</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>You haven't saved any builds yet. <a href="/community.php" style="color: var(--accent-2);">Explore the community!</a></p>
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
</script>

<?php renderFooter(); ?>
