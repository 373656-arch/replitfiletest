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
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] == '1';
    $action = $_POST['action'] ?? '';

    if (!isLoggedIn()) {
        if ($is_ajax) {
            echo json_encode(['success' => false, 'error' => 'Please log in first.']);
            exit;
        }
        header('Location: /user/login.php');
        exit;
    }

    // -------------------------
    // Like / Unlike build
    // -------------------------
    if ($action === 'like_build' || isset($_POST['like_build'])) {
        $build_id = (int)$_POST['build_id'];

        $stmt = $conn->prepare("SELECT 1 FROM user_likes WHERE user_id = ? AND build_id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $build_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $del = $conn->prepare("DELETE FROM user_likes WHERE user_id = ? AND build_id = ?");
            $del->bind_param("ii", $_SESSION['user_id'], $build_id);
            $del->execute();
            $upd = $conn->prepare("UPDATE builds SET likes_count = GREATEST(likes_count - 1, 0) WHERE build_id = ?");
            $upd->bind_param("i", $build_id);
            $upd->execute();
            $new_status = 'Like';
        } else {
            $ins = $conn->prepare("INSERT INTO user_likes (user_id, build_id) VALUES (?, ?)");
            $ins->bind_param("ii", $_SESSION['user_id'], $build_id);
            $ins->execute();
            $upd = $conn->prepare("UPDATE builds SET likes_count = likes_count + 1 WHERE build_id = ?");
            $upd->bind_param("i", $build_id);
            $upd->execute();
            $new_status = 'Unlike';
        }

        if ($is_ajax) {
            echo json_encode(['success' => true, 'action' => 'like_build', 'text' => $new_status]);
            exit;
        }
        header("Location: community.php?build=" . $build_id);
        exit;
    }

    // -------------------------
    // Save build to user's saved list
    // -------------------------
    if ($action === 'save_build' || isset($_POST['save_build'])) {
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

        if ($is_ajax) {
            echo json_encode(['success' => true, 'action' => 'save_build', 'text' => 'Saved!']);
            exit;
        }
        header("Location: community.php?build=" . $build_id);
        exit;
    }

    // -------------------------
    // Add comment / reply
    // -------------------------
    if ($action === 'add_comment' || isset($_POST['add_comment'])) {
        $build_id = (int)$_POST['build_id'];
        $content = trim($_POST['content'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        if ($content !== '') {
            $stmt = $conn->prepare("INSERT INTO comments (build_id, user_id, parent_comment_id, content) VALUES (?, ?, ?, ?)");
            if ($parent_id === null) {
                $null = null;
                $stmt->bind_param("iiss", $build_id, $_SESSION['user_id'], $null, $content);
            } else {
                $stmt->bind_param("iiis", $build_id, $_SESSION['user_id'], $parent_id, $content);
            }
            $stmt->execute();
            $new_comment_id = $conn->insert_id;

            if ($is_ajax) {
                // Fetch user info to render the HTML
                $user = getUserData($_SESSION['user_id']);
                $date_posted = date('M j, Y');
                $is_reply = ($parent_id !== null);

                ob_start(); // Buffer the HTML snippet to send back
                ?>
                <div class="comment <?php echo $is_reply ? 'reply' : ''; ?>">
                    <div class="comment-header">
                        <a href="/public_profile.php?username=<?php echo urlencode($user['username']); ?>" style="color: var(--accent-2); text-decoration: none;"><span class="comment-author"><?php echo htmlspecialchars($user['username']); ?></span></a>
                        <span style="font-size: 0.9rem; opacity: 0.7;"><?php echo $date_posted; ?></span>
                    </div>
                    <p><?php echo nl2br(htmlspecialchars($content)); ?></p>

                    <?php if (!$is_reply): ?>
                        <button onclick="showReplyForm(<?php echo $new_comment_id; ?>)" class="btn btn-secondary" style="margin-top: 0.5rem; padding: 0.5rem 1rem; font-size: 0.9rem;">Reply</button>
                    <?php endif; ?>

                    <form method="POST" class="ajax-form" style="display: inline;">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="action" value="delete_comment">
                        <input type="hidden" name="comment_id" value="<?php echo $new_comment_id; ?>">
                        <input type="hidden" name="build_id" value="<?php echo $build_id; ?>">
                        <button type="submit" class="btn" style="background: #ef4444; padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="return confirm('Delete this?')">Delete</button>
                    </form>

                    <?php if (!$is_reply): ?>
                        <div id="reply-form-<?php echo $new_comment_id; ?>" style="display: none; margin-top: 1rem;">
                            <form method="POST" class="ajax-form">
                                <input type="hidden" name="ajax" value="1">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="build_id" value="<?php echo $build_id; ?>">
                                <input type="hidden" name="parent_id" value="<?php echo $new_comment_id; ?>">
                                <textarea name="content" placeholder="Write a reply..." required></textarea>
                                <button type="submit" class="btn">Post Reply</button>
                            </form>
                        </div>
                        <div id="replies-container-<?php echo $new_comment_id; ?>" class="replies-container" style="display: block;"></div>
                    <?php endif; ?>
                </div>
                <?php
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'action' => 'add_comment', 'html' => $html, 'parent_id' => $parent_id]);
                exit;
            }
        }

        if (!$is_ajax) {
            header("Location: community.php?build=" . $build_id);
            exit;
        }
    }

    // -------------------------
    // Delete comment
    // -------------------------
    if ($action === 'delete_comment' || isset($_POST['delete_comment'])) {
        $comment_id = (int)$_POST['comment_id'];

        $stmt = $conn->prepare("SELECT user_id FROM comments WHERE comment_id = ?");
        $stmt->bind_param("i", $comment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $comment = $result->fetch_assoc();
            $user = getUserData($_SESSION['user_id']);

            if ($comment['user_id'] == $_SESSION['user_id'] || isAdmin($user['email'])) {
                $del = $conn->prepare("DELETE FROM comments WHERE comment_id = ? OR parent_comment_id = ?");
                $del->bind_param("ii", $comment_id, $comment_id);
                $del->execute();
            }
        }

        if ($is_ajax) {
            echo json_encode(['success' => true, 'action' => 'delete_comment']);
            exit;
        }
        header("Location: community.php?build=" . (int)($_POST['build_id'] ?? 0));
        exit;
    }

    // -------------------------
    // Fork build (Keeps normal redirect, no AJAX needed here)
    // -------------------------
    if (isset($_POST['fork_build'])) {
        // [Existing Fork logic remains unchanged]
        $original_build_id = (int)$_POST['build_id'];
        $stmt = $conn->prepare("SELECT * FROM builds WHERE build_id = ? AND is_community_shared = 1");
        $stmt->bind_param("i", $original_build_id);
        $stmt->execute();
        $original_build_result = $stmt->get_result();

        if (!$original_build_result || $original_build_result->num_rows === 0) {
            header("Location: /community.php?build=" . $original_build_id);
            exit;
        }

        $original_build = $original_build_result->fetch_assoc();
        $stmt = $conn->prepare("SELECT p.part_id, p.name, p.price, bp.position_data FROM build_parts bp JOIN parts p ON bp.part_id = p.part_id WHERE bp.build_id = ?");
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

        $_SESSION['prefill_build'] = [
            'car_id'      => (int)$original_build['car_id'],
            'parts'       => $prefill_parts,
            'total_price' => (float)$original_build['total_price'],
            'build_title' => 'Fork of ' . ($original_build['build_title'] ?? 'Build')
        ];

        header("Location: /index.php");
        exit;
    }
}

// -------------------------
// Filters and community list [Unchanged]
// -------------------------
$filter_car = $_GET['filter_car'] ?? '';
$filter_budget = $_GET['filter_budget'] ?? '';
$filter_car_id = $filter_car !== '' ? (int)$filter_car : 0;
$allowed_budgets = ['low','medium','high'];
$filter_budget = in_array($filter_budget, $allowed_budgets, true) ? $filter_budget : '';

$query = "SELECT b.*, c.name as car_name, u.username as creator_name  
          FROM builds b  
          JOIN cars c ON b.car_id = c.car_id  
          JOIN users u ON b.user_id = u.uid  
          WHERE b.is_community_shared = 1";

if ($filter_car_id) { $query .= " AND c.car_id = " . $filter_car_id; }
if ($filter_budget === 'low') { $query .= " AND b.total_price < 1000"; } 
elseif ($filter_budget === 'medium') { $query .= " AND b.total_price BETWEEN 1000 AND 3000"; } 
elseif ($filter_budget === 'high') { $query .= " AND b.total_price > 3000"; }

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
                            <p>by <a href="/public_profile.php?username=<?php echo urlencode($build['creator_name']); ?>" style="color: var(--accent-2); text-decoration: none;"><?php echo htmlspecialchars($build['creator_name']); ?></a></p>
                            <p><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price" style="color: var(--accent-1); font-weight: bold;">$<?php echo number_format((float)$build['total_price'], 2); ?></p>
                            <?php if (!empty($build['estimated_hp'])): ?>
                                <p style="color: #f39c12; font-weight: bold;">⚡ <?php echo (int)$build['estimated_hp']; ?> HP (estimated)</p>
                            <?php endif; ?>
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
            <p>by <a href="/public_profile.php?user=<?php echo (int)$selected_build['creator_id']; ?>" style="color: var(--accent-2); text-decoration: none;"><strong><?php echo htmlspecialchars($selected_build['creator_name']); ?></strong></a></p>
            <p><?php echo htmlspecialchars($selected_build['car_name']); ?></p>
            <p style="font-size: 1.5rem; color: var(--accent-1); font-weight: bold;">Total: $<?php echo number_format((float)$selected_build['total_price'], 2); ?></p>
            <?php if (!empty($selected_build['estimated_hp'])): ?>
                <p style="font-size: 1.2rem; color: #f39c12; font-weight: bold;">⚡ Estimated Horsepower: <?php echo (int)$selected_build['estimated_hp']; ?> HP</p>
            <?php endif; ?>
            <p>👍 <?php echo (int)$selected_build['likes_count']; ?> likes</p>

            <?php if (isLoggedIn()): ?>
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <form method="POST" class="ajax-form" style="display:inline;">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="action" value="like_build">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" class="btn">
                            <?php
                            $stmt = $conn->prepare("SELECT 1 FROM user_likes WHERE user_id = ? AND build_id = ?");
                            $stmt->bind_param("ii", $_SESSION['user_id'], $selected_build['build_id']);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            echo ($res && $res->num_rows > 0) ? 'Unlike' : 'Like';
                            ?>
                        </button>
                    </form>

                    <form method="POST" class="ajax-form" style="display:inline;">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="action" value="save_build">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" class="btn btn-secondary">Save Build</button>
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
                <div style="background: var(--bg-primary); padding: 1rem; margin: 0.5rem 0; border-radius: 5px; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid var(--accent-1);">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <strong style="font-size: 1.1rem; color: #fff;"><?php echo htmlspecialchars($part['name']); ?></strong>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="color: var(--accent-1); font-weight: bold;">$<?php echo number_format((float)$part['price'], 2); ?></span>
                            <span style="color: #888; font-size: 0.85rem; text-transform: uppercase;">[<?php echo htmlspecialchars($part['position_data']); ?>]</span>
                        </div>
                    </div>
                    <a href="/redirect.php?part_id=<?php echo (int)$part['part_id']; ?>" target="_blank" class="btn" style="min-width: 150px; text-align: center; background: transparent; border: 1px solid var(--accent-2); color: #fff; transition: all 0.3s ease;">VIEW PRODUCT</a>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="comment-section">
            <h3>Comments</h3>

            <?php if (isLoggedIn()): ?>
                <form method="POST" class="ajax-form main-comment-form" style="margin-bottom: 2rem;">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                    <textarea name="content" placeholder="Add a comment..." required></textarea>
                    <button type="submit" class="btn">Post Comment</button>
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

            <div id="comments-list">
                <?php while ($comment = $comments->fetch_assoc()): ?>
                    <div class="comment">
                        <div class="comment-header">
                            <a href="/public_profile.php?username=<?php echo urlencode($comment['username']); ?>" style="color: var(--accent-2); text-decoration: none;"><span class="comment-author"><?php echo htmlspecialchars($comment['username']); ?></span></a>
                            <span style="font-size: 0.9rem; opacity: 0.7;"><?php echo date('M j, Y', strtotime($comment['date_posted'])); ?></span>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>

                        <?php if (isLoggedIn()): ?>
                            <button onclick="showReplyForm(<?php echo (int)$comment['comment_id']; ?>)" class="btn btn-secondary" style="margin-top: 0.5rem; padding: 0.5rem 1rem; font-size: 0.9rem;">Reply</button>

                            <?php if ($user && ($comment['user_id'] == $_SESSION['user_id'] || isAdmin($user['email']))): ?>
                                <form method="POST" class="ajax-form" style="display: inline;">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo (int)$comment['comment_id']; ?>">
                                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                    <button type="submit" class="btn" style="background: #ef4444; padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="return confirm('Delete this comment?')">Delete</button>
                                </form>
                            <?php endif; ?>

                            <div id="reply-form-<?php echo (int)$comment['comment_id']; ?>" style="display: none; margin-top: 1rem;">
                                <form method="POST" class="ajax-form">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="action" value="add_comment">
                                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                    <input type="hidden" name="parent_id" value="<?php echo (int)$comment['comment_id']; ?>">
                                    <textarea name="content" placeholder="Write a reply..." required></textarea>
                                    <button type="submit" class="btn">Post Reply</button>
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

                        <button onclick="toggleReplies(<?php echo (int)$comment['comment_id']; ?>, this)" class="btn btn-secondary toggle-replies" style="margin-top: 0.5rem; padding: 0.5rem 1rem; font-size: 0.9rem; <?php echo ($reply_count == 0) ? 'display:none;' : ''; ?>">Show Replies (<span id="reply-count-<?php echo (int)$comment['comment_id']; ?>"><?php echo $reply_count; ?></span>)</button>

                        <div id="replies-container-<?php echo (int)$comment['comment_id']; ?>" class="replies-container" style="display: none;">
                            <?php foreach ($replies_array as $reply): ?>
                                <div class="comment reply">
                                    <div class="comment-header">
                                        <a href="/public_profile.php?username=<?php echo urlencode($reply['username']); ?>" style="color: var(--accent-2); text-decoration: none;"><span class="comment-author"><?php echo htmlspecialchars($reply['username']); ?></span></a>
                                        <span style="font-size: 0.9rem; opacity: 0.7;"><?php echo date('M j, Y', strtotime($reply['date_posted'])); ?></span>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($reply['content'])); ?></p>

                                    <?php if ($user && ($reply['user_id'] == $_SESSION['user_id'] || isAdmin($user['email']))): ?>
                                        <form method="POST" class="ajax-form" style="display: inline;">
                                            <input type="hidden" name="ajax" value="1">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo (int)$reply['comment_id']; ?>">
                                            <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                            <button type="submit" class="btn" style="background: #ef4444; padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="return confirm('Delete this reply?')">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// --- UI Toggle Functions ---
function showReplyForm(commentId) {
    const form = document.getElementById('reply-form-' + commentId);
    if (!form) return;
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleReplies(commentId, button) {
    const container = document.getElementById('replies-container-' + commentId);
    const replyCount = document.getElementById('reply-count-' + commentId);
    if (!container || !button || !replyCount) return;

    const replyCountText = replyCount.textContent || '0';

    if (container.style.display === 'none') {
        container.style.display = 'block';
        button.textContent = 'Hide Replies (' + replyCountText + ')';
    } else {
        container.style.display = 'none';
        button.textContent = 'Show Replies (' + replyCountText + ')';
    }
}

// --- AJAX Form Handling ---
document.addEventListener('DOMContentLoaded', function() {
    // Listen for submits on anything with the class "ajax-form"
    document.addEventListener('submit', function(e) {
        if (!e.target.classList.contains('ajax-form')) return;

        e.preventDefault(); // Stop the page refresh!

        const form = e.target;
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');

        // Temporarily disable the button so users don't double click
        const originalText = submitBtn.textContent;
        submitBtn.textContent = '...';
        submitBtn.disabled = true;

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;

            if (data.error) {
                alert(data.error);
                return;
            }

            if (data.success) {
                const action = data.action;

                // 1. Handling Likes & Saves
                if (action === 'like_build' || action === 'save_build') {
                    submitBtn.textContent = data.text; 
                } 

                // 2. Handling Comment Deletions
                else if (action === 'delete_comment') {
                    // Remove the closest comment element visually
                    form.closest('.comment').remove();
                } 

                // 3. Handling New Comments / Replies
                else if (action === 'add_comment') {
                    form.querySelector('textarea').value = ''; // Clear text box

                    if (data.parent_id) {
                        // It's a reply! Add it to the replies container
                        const container = document.getElementById('replies-container-' + data.parent_id);
                        container.insertAdjacentHTML('beforeend', data.html);
                        container.style.display = 'block'; // Ensure it's visible
                        form.parentElement.style.display = 'none'; // Hide reply form

                        // Update the reply count button
                        const countSpan = document.getElementById('reply-count-' + data.parent_id);
                        if(countSpan) {
                            countSpan.textContent = parseInt(countSpan.textContent) + 1;
                            countSpan.parentElement.style.display = 'inline-block'; // Show button if it was hidden
                        }
                    } else {
                        // It's a top-level comment!
                        const commentsList = document.getElementById('comments-list');
                        commentsList.insertAdjacentHTML('afterbegin', data.html); // Add to the top
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            submitBtn.disabled = false;
            submitBtn.textContent = 'Error!';
        });
    });
});
</script>

<?php renderFooter(); ?>