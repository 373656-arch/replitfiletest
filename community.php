<?php
require_once 'config.php';

// Ensure session is started in case config.php doesn't start it
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$selected_build = null;
if (isset($_GET['build'])) {
    $build_id = (int)$_GET['build'];
    $stmt = $conn->prepare("
        SELECT b.*, c.name as car_name, u.username as creator_name, u.uid as creator_id
        FROM builds b
        JOIN cars c ON b.car_id = c.car_id
        JOIN users u ON b.user_id = u.uid
        WHERE b.build_id = ? AND b.is_community_shared = 1
    ");
    $stmt->bind_param("i", $build_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $selected_build = $result->fetch_assoc();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isLoggedIn()) {
        header('Location: /user/login.php');
        exit;
    }

    // -------------------------
    // Like / Unlike build
    // -------------------------
    if (isset($_POST['like_build'])) {
        $build_id = (int)$_POST['build_id'];

        // Check existing like
        $stmt = $conn->prepare("SELECT 1 FROM user_likes WHERE user_id = ? AND build_id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $build_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            // Unlike: remove row and decrement safely
            $del = $conn->prepare("DELETE FROM user_likes WHERE user_id = ? AND build_id = ?");
            $del->bind_param("ii", $_SESSION['user_id'], $build_id);
            $del->execute();
            $upd = $conn->prepare("UPDATE builds SET likes_count = GREATEST(likes_count - 1, 0) WHERE build_id = ?");
            $upd->bind_param("i", $build_id);
            $upd->execute();
        } else {
            // Like: insert and increment
            $ins = $conn->prepare("INSERT INTO user_likes (user_id, build_id) VALUES (?, ?)");
            $ins->bind_param("ii", $_SESSION['user_id'], $build_id);
            $ins->execute();
            $upd = $conn->prepare("UPDATE builds SET likes_count = likes_count + 1 WHERE build_id = ?");
            $upd->bind_param("i", $build_id);
            $upd->execute();
        }

        header("Location: community.php?build=" . $build_id);
        exit;
    }

    // -------------------------
    // Save build to user's saved list
    // -------------------------
    if (isset($_POST['save_build'])) {
        $build_id = (int)$_POST['build_id'];

        $stmt = $conn->prepare("SELECT 1 FROM user_saved_builds WHERE user_id = ? AND build_id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $build_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result || $result->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO user_saved_builds (user_id, build_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $_SESSION['user_id'], $build_id);
            $stmt->execute();
        }

        header("Location: community.php?build=" . $build_id);
        exit;
    }

    // -------------------------
    // Add comment / reply
    // -------------------------
    if (isset($_POST['add_comment'])) {
        $build_id = (int)$_POST['build_id'];
        $content = trim($_POST['content'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        if ($content !== '') {
            $stmt = $conn->prepare("INSERT INTO comments (build_id, user_id, parent_comment_id, content) VALUES (?, ?, ?, ?)");
            // parent_comment_id can be NULL
            if ($parent_id === null) {
                $null = null;
                $stmt->bind_param("iiss", $build_id, $_SESSION['user_id'], $null, $content);
            } else {
                $stmt->bind_param("iiis", $build_id, $_SESSION['user_id'], $parent_id, $content);
            }
            $stmt->execute();
        }

        header("Location: community.php?build=" . $build_id);
        exit;
    }

    // -------------------------
    // Delete comment (and its direct replies)
    // -------------------------
    if (isset($_POST['delete_comment'])) {
        $comment_id = (int)$_POST['comment_id'];

        // fetch the comment author
        $stmt = $conn->prepare("SELECT user_id FROM comments WHERE comment_id = ?");
        $stmt->bind_param("i", $comment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $comment = $result->fetch_assoc();
            $user = getUserData($_SESSION['user_id']);

            // Allow deletion if owner or admin
            if ($comment['user_id'] == $_SESSION['user_id'] || isAdmin($user['email'])) {
                // delete the comment and any replies that have this comment as parent
                $del = $conn->prepare("DELETE FROM comments WHERE comment_id = ? OR parent_comment_id = ?");
                $del->bind_param("ii", $comment_id, $comment_id);
                $del->execute();
            }
        }

        header("Location: community.php?build=" . (int)($_POST['build_id'] ?? 0));
        exit;
    }

    // -------------------------
    // Fork build -> do NOT create a DB record
    // Instead: store a prefill in session and redirect to index.php
    // -------------------------
    if (isset($_POST['fork_build'])) {
        $original_build_id = (int)$_POST['build_id'];

        // Load original build and ensure it's community-shared
        $stmt = $conn->prepare("SELECT * FROM builds WHERE build_id = ? AND is_community_shared = 1");
        $stmt->bind_param("i", $original_build_id);
        $stmt->execute();
        $original_build_result = $stmt->get_result();

        if (!$original_build_result || $original_build_result->num_rows === 0) {
            // build not found or not shared — redirect back to the community page
            header("Location: /community.php?build=" . $original_build_id);
            exit;
        }

        $original_build = $original_build_result->fetch_assoc();

        // Load parts for the original build
        $stmt = $conn->prepare("
            SELECT p.part_id, p.name, p.price, bp.position_data
            FROM build_parts bp
            JOIN parts p ON bp.part_id = p.part_id
            WHERE bp.build_id = ?
        ");
        $stmt->bind_param("i", $original_build_id);
        $stmt->execute();
        $parts_result = $stmt->get_result();
        $prefill_parts = [];

        if ($parts_result) {
            while ($p = $parts_result->fetch_assoc()) {
                $prefill_parts[] = [
                    'part_id' => (int)$p['part_id'],
                    'name'    => $p['name'],
                    'price'   => (float)$p['price'],
                    'position'=> $p['position_data'] ?? 'general'
                ];
            }
        }

        // Store prefill data in session — index.php will consume and unset it
        $_SESSION['prefill_build'] = [
            'car_id'      => (int)$original_build['car_id'],
            'parts'       => $prefill_parts,
            'total_price' => (float)$original_build['total_price'],
            'build_title' => 'Fork of ' . ($original_build['build_title'] ?? 'Build')
        ];

        // Redirect to build page (index.php) where the UI will be prefilled
        header("Location: /index.php");
        exit;
    }
}

// -------------------------
// Filters and community list
// -------------------------
$filter_car = $_GET['filter_car'] ?? '';
$filter_budget = $_GET['filter_budget'] ?? '';

// Basic sanitization: cast filter_car to int when present, and allow only known budget tokens
$filter_car_id = $filter_car !== '' ? (int)$filter_car : 0;
$allowed_budgets = ['low','medium','high'];
$filter_budget = in_array($filter_budget, $allowed_budgets, true) ? $filter_budget : '';

$query = "SELECT b.*, c.name as car_name, u.username as creator_name 
          FROM builds b 
          JOIN cars c ON b.car_id = c.car_id 
          JOIN users u ON b.user_id = u.uid 
          WHERE b.is_community_shared = 1";

if ($filter_car_id) {
    $query .= " AND c.car_id = " . $filter_car_id;
}
if ($filter_budget === 'low') {
    $query .= " AND b.total_price < 1000";
} elseif ($filter_budget === 'medium') {
    $query .= " AND b.total_price BETWEEN 1000 AND 3000";
} elseif ($filter_budget === 'high') {
    $query .= " AND b.total_price > 3000";
}

$query .= " ORDER BY b.likes_count DESC, b.date_created DESC";
$community_builds = $conn->query($query);

$pageTitle = "Community Builds - ModMyCar";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <h2>Community Builds</h2>

    <?php if (!$selected_build): ?>
        <div class="card">
            <h3>Filter Builds</h3>
            <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <div>
                    <select name="filter_car">
                        <option value="">All Cars</option>
                        <?php
                        $cars = $conn->query("SELECT * FROM cars");
                        while ($car = $cars->fetch_assoc()):
                        ?>
                            <option value="<?php echo (int)$car['car_id']; ?>" <?php echo ($filter_car == $car['car_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($car['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <select name="filter_budget">
                        <option value="">All Budgets</option>
                        <option value="low" <?php echo ($filter_budget === 'low') ? 'selected' : ''; ?>>Under $1,000</option>
                        <option value="medium" <?php echo ($filter_budget === 'medium') ? 'selected' : ''; ?>>$1,000 - $3,000</option>
                        <option value="high" <?php echo ($filter_budget === 'high') ? 'selected' : ''; ?>>Over $3,000</option>
                    </select>
                </div>
                <button type="submit" class="btn">Apply Filters</button>
            </form>
        </div>

        <div class="community-grid">
            <?php if ($community_builds && $community_builds->num_rows > 0): ?>
                <?php while ($build = $community_builds->fetch_assoc()): ?>
                    <div class="build-card">
                        <?php if (!empty($build['featured_image'])): ?>
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
                            <p class="price" style="color: var(--accent-1); font-weight: bold;">$<?php echo number_format((float)$build['total_price'], 2); ?></p>
                            <p>👍 <?php echo (int)$build['likes_count']; ?> likes</p>
                            <div class="build-actions">
                                <a href="?build=<?php echo (int)$build['build_id']; ?>" class="btn">View Details</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No community builds found. Be the first to share one!</p>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <a href="/community.php" class="btn btn-secondary" style="margin-bottom: 1rem;">← Back to Community</a>

        <div class="card">
            <h2><?php echo htmlspecialchars($selected_build['build_title']); ?></h2>
            <p>by <strong><?php echo htmlspecialchars($selected_build['creator_name']); ?></strong></p>
            <p><?php echo htmlspecialchars($selected_build['car_name']); ?></p>
            <p style="font-size: 1.5rem; color: var(--accent-1); font-weight: bold;">Total: $<?php echo number_format((float)$selected_build['total_price'], 2); ?></p>
            <p>👍 <?php echo (int)$selected_build['likes_count']; ?> likes</p>

            <?php if (isLoggedIn()): ?>
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" name="like_build" class="btn">
                            <?php
                            $stmt = $conn->prepare("SELECT 1 FROM user_likes WHERE user_id = ? AND build_id = ?");
                            $stmt->bind_param("ii", $_SESSION['user_id'], $selected_build['build_id']);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            echo ($res && $res->num_rows > 0) ? 'Unlike' : 'Like';
                            ?>
                        </button>
                    </form>

                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" name="save_build" class="btn btn-secondary">Save Build</button>
                    </form>

                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" name="fork_build" class="btn btn-secondary">Fork Build</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Parts List</h3>
            <?php
            $stmt = $conn->prepare("
                SELECT p.*, bp.position_data 
                FROM build_parts bp 
                JOIN parts p ON bp.part_id = p.part_id 
                WHERE bp.build_id = ?
            ");
            $stmt->bind_param("i", $selected_build['build_id']);
            $stmt->execute();
            $parts = $stmt->get_result();
            ?>
            <?php while ($part = $parts->fetch_assoc()): ?>
                <div style="background: var(--bg-primary); padding: 1rem; margin: 0.5rem 0; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong><?php echo htmlspecialchars($part['name']); ?></strong>
                        <span style="color: var(--accent-1); margin-left: 1rem;">$<?php echo number_format((float)$part['price'], 2); ?></span>
                        <span style="color: var(--accent-2); margin-left: 1rem; font-size: 0.9rem;">[<?php echo htmlspecialchars($part['position_data']); ?>]</span>
                    </div>
                    <a href="/redirect.php?part_id=<?php echo (int)$part['part_id']; ?>" target="_blank" class="btn btn-secondary">View Product</a>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="comment-section">
            <h3>Comments</h3>

            <?php if (isLoggedIn()): ?>
                <form method="POST" style="margin-bottom: 2rem;">
                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                    <textarea name="content" placeholder="Add a comment..." required></textarea>
                    <button type="submit" name="add_comment" class="btn">Post Comment</button>
                </form>
            <?php endif; ?>

            <?php
            $stmt = $conn->prepare("
                SELECT c.*, u.username 
                FROM comments c 
                JOIN users u ON c.user_id = u.uid 
                WHERE c.build_id = ? AND c.parent_comment_id IS NULL 
                ORDER BY c.date_posted DESC
            ");
            $stmt->bind_param("i", $selected_build['build_id']);
            $stmt->execute();
            $comments = $stmt->get_result();
            $user = isLoggedIn() ? getUserData($_SESSION['user_id']) : null;
            ?>

            <?php while ($comment = $comments->fetch_assoc()): ?>
                <div class="comment">
                    <div class="comment-header">
                        <span class="comment-author"><?php echo htmlspecialchars($comment['username']); ?></span>
                        <span style="font-size: 0.9rem; opacity: 0.7;"><?php echo date('M j, Y', strtotime($comment['date_posted'])); ?></span>
                    </div>
                    <p><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                    
                    <?php if (isLoggedIn()): ?>
                        <button onclick="showReplyForm(<?php echo (int)$comment['comment_id']; ?>)" class="btn btn-secondary" style="margin-top: 0.5rem; padding: 0.5rem 1rem; font-size: 0.9rem;">Reply</button>
                        
                        <?php if ($user && ($comment['user_id'] == $_SESSION['user_id'] || isAdmin($user['email']))): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$comment['comment_id']; ?>">
                                <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                <button type="submit" name="delete_comment" class="btn" style="background: #ef4444; padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="return confirm('Delete this comment?')">Delete</button>
                            </form>
                        <?php endif; ?>

                        <div id="reply-form-<?php echo (int)$comment['comment_id']; ?>" style="display: none; margin-top: 1rem;">
                            <form method="POST">
                                <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                <input type="hidden" name="parent_id" value="<?php echo (int)$comment['comment_id']; ?>">
                                <textarea name="content" placeholder="Write a reply..." required></textarea>
                                <button type="submit" name="add_comment" class="btn">Post Reply</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php
                    $stmt2 = $conn->prepare("
                        SELECT c.*, u.username 
                        FROM comments c 
                        JOIN users u ON c.user_id = u.uid 
                        WHERE c.parent_comment_id = ? 
                        ORDER BY c.date_posted ASC
                    ");
                    $stmt2->bind_param("i", $comment['comment_id']);
                    $stmt2->execute();
                    $replies_result = $stmt2->get_result();
                    $replies_array = $replies_result->fetch_all(MYSQLI_ASSOC);
                    $reply_count = count($replies_array);
                    ?>

                    <?php if ($reply_count > 0): ?>
                        <button onclick="toggleReplies(<?php echo (int)$comment['comment_id']; ?>, this)" class="btn btn-secondary toggle-replies" style="margin-top: 0.5rem; padding: 0.5rem 1rem; font-size: 0.9rem;">Show Replies (<span id="reply-count-<?php echo (int)$comment['comment_id']; ?>"><?php echo $reply_count; ?></span>)</button>
                        <div id="replies-container-<?php echo (int)$comment['comment_id']; ?>" class="replies-container" style="display: none;">
                    <?php endif; ?>

                    <?php foreach ($replies_array as $reply): ?>
                        <div class="comment reply">
                            <div class="comment-header">
                                <span class="comment-author"><?php echo htmlspecialchars($reply['username']); ?></span>
                                <span style="font-size: 0.9rem; opacity: 0.7;"><?php echo date('M j, Y', strtotime($reply['date_posted'])); ?></span>
                            </div>
                            <p><?php echo nl2br(htmlspecialchars($reply['content'])); ?></p>
                            
                            <?php if ($user && ($reply['user_id'] == $_SESSION['user_id'] || isAdmin($user['email']))): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="comment_id" value="<?php echo (int)$reply['comment_id']; ?>">
                                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                    <button type="submit" name="delete_comment" class="btn" style="background: #ef4444; padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="return confirm('Delete this reply?')">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if ($reply_count > 0): ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function showReplyForm(commentId) {
    const form = document.getElementById('reply-form-' + commentId);
    if (!form) return;
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleReplies(commentId, button) {
    const container = document.getElementById('replies-container-' + commentId);
    const replyCount = document.getElementById('reply-count-' + commentId);
    if (!container || !button) return;
    
    const replyCountText = replyCount ? replyCount.textContent : '0';
    
    if (container.style.display === 'none') {
        container.style.display = 'block';
        button.textContent = 'Hide Replies (' + replyCountText + ')';
    } else {
        container.style.display = 'none';
        button.textContent = 'Show Replies (' + replyCountText + ')';
    }
}
</script>

<?php renderFooter(); ?>