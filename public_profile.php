<?php
require_once 'config.php';

$user_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$username = isset($_GET['username']) ? trim($_GET['username']) : '';

$viewed_user = null;
$user_builds = null;

if ($user_id) {
    $stmt = $conn->prepare("SELECT uid, username, profileImage FROM users WHERE uid = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $viewed_user = $result->fetch_assoc();
    }
} elseif ($username) {
    $stmt = $conn->prepare("SELECT uid, username, profileImage FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $viewed_user = $result->fetch_assoc();
        $user_id = $viewed_user['uid'];
    }
}

if (!$viewed_user) {
    $pageTitle = "User Not Found - ModMyCar";
    require_once 'includes/headerFooter.php';
    renderHeader();
    echo '<div class="container"><p>User not found.</p></div>';
    renderFooter();
    exit;
}

$stmt = $conn->prepare("
    SELECT b.*, c.name as car_name
    FROM builds b
    JOIN cars c ON b.car_id = c.car_id
    WHERE b.user_id = ? AND b.is_community_shared = 1
    ORDER BY b.likes_count DESC, b.date_created DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_builds = $stmt->get_result();

$pageTitle = htmlspecialchars($viewed_user['username']) . "'s Profile - ModMyCar";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <a href="/community.php" class="back-link">← Back to Community</a>

    <div class="profile-header">
        <?php if ($viewed_user['profileImage']): ?>
            <img src="<?php echo htmlspecialchars($viewed_user['profileImage']); ?>" alt="Profile" class="profile-image">
        <?php else: ?>
            <div class="profile-image-placeholder">
                <?php echo strtoupper(substr($viewed_user['username'], 0, 1)); ?>
            </div>
        <?php endif; ?>
        <div>
            <h2 style="margin-bottom: 0.25rem;"><?php echo htmlspecialchars($viewed_user['username']); ?></h2>
            <p class="text-muted">Community Member</p>
        </div>
    </div>

    <div class="mt-2">
        <h3>Public Builds</h3>
        <?php if ($user_builds && $user_builds->num_rows > 0): ?>
            <div class="community-grid">
                <?php while ($build = $user_builds->fetch_assoc()): ?>
                    <div class="build-card">
                        <?php if ($build['featured_image']): ?>
                            <img src="<?php echo htmlspecialchars($build['featured_image']); ?>" alt="Build">
                        <?php else: ?>
                            <div class="img-placeholder">No Image</div>
                        <?php endif; ?>
                        <div class="build-card-content">
                            <h3><?php echo htmlspecialchars($build['build_title']); ?></h3>
                            <p class="text-muted"><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price-bold">$<?php echo number_format($build['total_price'], 2); ?></p>
                            <p class="text-muted fs-085">👍 <?php echo (int)$build['likes_count']; ?> likes</p>
                            <div class="build-actions">
                                <a href="/community.php?build=<?php echo (int)$build['build_id']; ?>" class="btn btn-sm">View Details</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p class="empty-state-text">No public builds yet</p>
                <p class="empty-state-subtext">This user hasn't shared any builds with the community.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter(); ?>
