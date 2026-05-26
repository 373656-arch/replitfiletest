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

            // --- NOTIFICATION: LIKE ---
            $owner_stmt = $conn->prepare("SELECT user_id FROM builds WHERE build_id = ?");
            $owner_stmt->bind_param("i", $build_id);
            $owner_stmt->execute();
            $owner_id = $owner_stmt->get_result()->fetch_assoc()['user_id'];

            if ($owner_id != $_SESSION['user_id']) {
                $u_data = getUserData($_SESSION['user_id']);
                createNotification($owner_id, 'like', $u_data['username'] . " liked your build!", "/community.php?build=" . $build_id, $_SESSION['user_id']);
            }
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

            // --- NOTIFICATION: SAVE ---
            $owner_stmt = $conn->prepare("SELECT user_id FROM builds WHERE build_id = ?");
            $owner_stmt->bind_param("i", $build_id);
            $owner_stmt->execute();
            $owner_id = $owner_stmt->get_result()->fetch_assoc()['user_id'];

            if ($owner_id != $_SESSION['user_id']) {
                $u_data = getUserData($_SESSION['user_id']);
                createNotification($owner_id, 'save', $u_data['username'] . " saved your build!", "/community.php?build=" . $build_id, $_SESSION['user_id']);
            }
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

            // --- NOTIFICATION: COMMENT / REPLY ---
            $u_data = getUserData($_SESSION['user_id']);
            $content_preview = mb_strlen($content) > 60 ? mb_substr($content, 0, 60) . '…' : $content;
            if ($parent_id === null) {
                $owner_stmt = $conn->prepare("SELECT user_id FROM builds WHERE build_id = ?");
                $owner_stmt->bind_param("i", $build_id);
                $owner_stmt->execute();
                $owner_id = $owner_stmt->get_result()->fetch_assoc()['user_id'];

                if ($owner_id != $_SESSION['user_id']) {
                    createNotification($owner_id, 'comment',
                        $u_data['username'] . ' commented: "' . $content_preview . '"',
                        "/community.php?build=" . $build_id . "#comment-" . $new_comment_id,
                        $_SESSION['user_id']);
                }
            } else {
                $parent_stmt = $conn->prepare("SELECT user_id FROM comments WHERE comment_id = ?");
                $parent_stmt->bind_param("i", $parent_id);
                $parent_stmt->execute();
                $parent_owner_id = $parent_stmt->get_result()->fetch_assoc()['user_id'];

                if ($parent_owner_id != $_SESSION['user_id']) {
                    createNotification($parent_owner_id, 'reply',
                        $u_data['username'] . ' replied: "' . $content_preview . '"',
                        "/community.php?build=" . $build_id . "#comment-" . $new_comment_id,
                        $_SESSION['user_id']);
                }
            }

            if ($is_ajax) {
                $user = getUserData($_SESSION['user_id']);
                $date_posted = date('M j, Y');
                $is_reply = ($parent_id !== null);

                ob_start();
                ?>
                <div class="comment <?php echo $is_reply ? 'reply' : ''; ?>" id="comment-<?php echo $new_comment_id; ?>">
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
                            <textarea name="content" required placeholder="Write a reply..." style="width: 100%; margin-bottom: 0.5rem; background: var(--bg-color); color: var(--text-color); border: 1px solid var(--border-color); border-radius: 4px; padding: 0.5rem; resize: vertical; min-height: 60px;"></textarea>
                            <button type="submit" class="btn" style="padding: 0.5rem 1rem; font-size: 0.9rem;">Post Reply</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'action' => 'add_comment', 'html' => $html]);
                exit;
            }
            header("Location: community.php?build=" . $build_id);
            exit;
        }
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
        $stmt = $conn->prepare("SELECT p.part_id, p.name, p.price, p.link, bp.position_data FROM build_parts bp JOIN parts p ON bp.part_id = p.part_id WHERE bp.build_id = ?");
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
                    'link'    => $p['link'] ?? '',
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
    <div class="section-label">Browse</div>
    <h2>Community Builds</h2>

    <?php if (!$selected_build): ?>
        <div class="card">
            <h3>Filter &amp; Search</h3>
            <form method="GET" class="community-filters" style="margin-bottom: 1rem;">
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
                <select name="filter_budget">
                    <option value="">All Budgets</option>
                    <option value="low" <?php echo ($filter_budget === 'low') ? 'selected' : ''; ?>>Under $1,000</option>
                    <option value="medium" <?php echo ($filter_budget === 'medium') ? 'selected' : ''; ?>>$1,000 – $3,000</option>
                    <option value="high" <?php echo ($filter_budget === 'high') ? 'selected' : ''; ?>>Over $3,000</option>
                </select>
                <button type="submit" class="btn btn-sm">Apply</button>
            </form>
            <div class="community-filters">
                <input type="text" id="communitySearch" placeholder="Search builds, cars, or creators..." oninput="filterCommunity()">
                <select id="communitySort" onchange="filterCommunity()">
                    <option value="likes">Most Liked</option>
                    <option value="newest">Newest First</option>
                    <option value="price_asc">Lowest Price</option>
                    <option value="price_desc">Highest Price</option>
                </select>
            </div>
        </div>

        <div class="community-grid" id="communityGrid">
            <?php if ($community_builds && $community_builds->num_rows > 0): ?>
                <?php while ($build = $community_builds->fetch_assoc()): ?>
                    <div class="build-card"
                         data-title="<?php echo htmlspecialchars(strtolower($build['build_title'])); ?>"
                         data-car="<?php echo htmlspecialchars(strtolower($build['car_name'])); ?>"
                         data-creator="<?php echo htmlspecialchars(strtolower($build['creator_name'])); ?>"
                         data-likes="<?php echo (int)$build['likes_count']; ?>"
                         data-price="<?php echo (float)$build['total_price']; ?>"
                         data-date="<?php echo strtotime($build['date_created'] ?? 'now'); ?>">
                        <?php if (!empty($build['featured_image'])): ?>
                            <img src="<?php echo htmlspecialchars($build['featured_image']); ?>" alt="Build">
                        <?php else: ?>
                            <div class="img-placeholder">No Image</div>
                        <?php endif; ?>
                        <div class="build-card-content">
                            <h3><?php echo htmlspecialchars($build['build_title']); ?></h3>
                            <p class="text-muted fs-085">by <a href="/public_profile.php?username=<?php echo urlencode($build['creator_name']); ?>" class="creator-link"><?php echo htmlspecialchars($build['creator_name']); ?></a></p>
                            <p class="text-muted fs-085"><?php echo htmlspecialchars($build['car_name']); ?></p>
                            <p class="price-bold">$<?php echo number_format((float)$build['total_price'], 2); ?></p>
                            <?php if (!empty($build['estimated_hp'])): ?>
                                <p class="build-card-hp">⚡ <?php echo (int)$build['estimated_hp']; ?> HP</p>
                            <?php endif; ?>
                            <p class="text-muted fs-085">👍 <?php echo (int)$build['likes_count']; ?> likes</p>
                            <div class="build-actions">
                                <a href="?build=<?php echo (int)$build['build_id']; ?>" class="btn btn-sm">View Details</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1/-1;">
                    <p class="empty-state-text">No community builds yet</p>
                    <p class="empty-state-subtext">Be the first to share your build with the community!</p>
                    <a href="/index.php" class="start-link">Start Building →</a>
                </div>
            <?php endif; ?>
        </div>
        <p id="noSearchResults" style="display:none;" class="text-center text-secondary mt-2">No builds match your search.</p>

    <?php else: ?>
        <a href="/community.php" class="back-link">← Back to Community</a>

        <div class="card build-detail-header">
            <h2 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($selected_build['build_title']); ?></h2>
            <p class="build-detail-meta">by <a href="/public_profile.php?user=<?php echo (int)$selected_build['creator_id']; ?>" class="creator-link"><?php echo htmlspecialchars($selected_build['creator_name']); ?></a></p>
            <p class="build-detail-meta"><?php echo htmlspecialchars($selected_build['car_name']); ?></p>
            <div class="build-detail-price">$<?php echo number_format((float)$selected_build['total_price'], 2); ?></div>
            <?php if (!empty($selected_build['estimated_hp'])): ?>
                <p class="build-detail-hp">⚡ Estimated Horsepower: <?php echo (int)$selected_build['estimated_hp']; ?> HP</p>
            <?php endif; ?>
            <p class="build-detail-meta">👍 <?php echo (int)$selected_build['likes_count']; ?> likes</p>

            <?php if (isLoggedIn()): ?>
                <div class="build-detail-actions">
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
                            echo ($res && $res->num_rows > 0) ? '👍 Unlike' : '👍 Like';
                            ?>
                        </button>
                    </form>

                    <form method="POST" class="ajax-form" style="display:inline;">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="action" value="save_build">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" class="btn btn-secondary">🔖 Save Build</button>
                    </form>

                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                        <button type="submit" name="fork_build" class="btn btn-secondary">🍴 Fork Build</button>
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
                <div class="part-row">
                    <div class="part-row-info">
                        <span class="part-row-name"><?php echo htmlspecialchars($part['name']); ?></span>
                        <div class="part-row-meta">
                            <span class="part-row-price">$<?php echo number_format((float)$part['price'], 2); ?></span>
                            <span class="part-row-position"><?php echo htmlspecialchars($part['position_data']); ?></span>
                        </div>
                    </div>
                    <a href="/redirect.php?part_id=<?php echo (int)$part['part_id']; ?>" target="_blank" class="btn btn-secondary btn-sm">View Product</a>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="comment-section">
            <h3>Comments</h3>

            <?php if (isLoggedIn()): ?>
                <form method="POST" class="ajax-form main-comment-form" style="margin-bottom: 1.5rem;">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                    <textarea name="content" placeholder="Share your thoughts on this build..." required style="margin-bottom: 0.5rem;"></textarea>
                    <button type="submit" class="btn">Post Comment</button>
                </form>
            <?php else: ?>
                <p class="text-muted fs-085" style="margin-bottom: 1.5rem;"><a href="/user/login.php" class="accent-link">Sign in</a> to leave a comment.</p>
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
                    <div class="comment" id="comment-<?php echo (int)$comment['comment_id']; ?>">
                        <div class="comment-header">
                            <a href="/public_profile.php?username=<?php echo urlencode($comment['username']); ?>" class="creator-link"><span class="comment-author"><?php echo htmlspecialchars($comment['username']); ?></span></a>
                            <span class="comment-date"><?php echo date('M j, Y', strtotime($comment['date_posted'])); ?></span>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>

                        <?php if (isLoggedIn()): ?>
                            <div class="comment-actions">
                                <button onclick="showReplyForm(<?php echo (int)$comment['comment_id']; ?>)" class="btn btn-secondary btn-sm">Reply</button>

                            <?php if ($user && ($comment['user_id'] == $_SESSION['user_id'] || isAdmin($user['email']))): ?>
                                <form method="POST" class="ajax-form" style="display: inline;">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo (int)$comment['comment_id']; ?>">
                                    <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                    <button type="submit" class="btn-danger-sm" onclick="return confirm('Delete this comment?')">Delete</button>
                                </form>
                            <?php endif; ?>
                            </div>

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

                        <?php if ($reply_count > 0): ?>
                        <button onclick="toggleReplies(<?php echo (int)$comment['comment_id']; ?>, this)" class="btn btn-secondary btn-sm toggle-replies" style="margin-top: 0.5rem;">Show Replies (<span id="reply-count-<?php echo (int)$comment['comment_id']; ?>"><?php echo $reply_count; ?></span>)</button>
                        <?php endif; ?>

                        <div id="replies-container-<?php echo (int)$comment['comment_id']; ?>" class="replies-container" style="display: none;">
                            <?php foreach ($replies_array as $reply): ?>
                                <div class="comment reply" id="comment-<?php echo (int)$reply['comment_id']; ?>">
                                    <div class="comment-header">
                                        <a href="/public_profile.php?username=<?php echo urlencode($reply['username']); ?>" class="creator-link"><span class="comment-author"><?php echo htmlspecialchars($reply['username']); ?></span></a>
                                        <span class="comment-date"><?php echo date('M j, Y', strtotime($reply['date_posted'])); ?></span>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($reply['content'])); ?></p>

                                    <?php if ($user && ($reply['user_id'] == $_SESSION['user_id'] || isAdmin($user['email']))): ?>
                                        <form method="POST" class="ajax-form" style="display: inline;">
                                            <input type="hidden" name="ajax" value="1">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo (int)$reply['comment_id']; ?>">
                                            <input type="hidden" name="build_id" value="<?php echo (int)$selected_build['build_id']; ?>">
                                            <button type="submit" class="btn-danger-sm" onclick="return confirm('Delete this reply?')">Delete</button>
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

// --- Community Search & Sort ---
function filterCommunity() {
    const query = (document.getElementById('communitySearch')?.value || '').toLowerCase().trim();
    const sort = document.getElementById('communitySort')?.value || 'likes';
    const grid = document.getElementById('communityGrid');
    const noResults = document.getElementById('noSearchResults');
    if (!grid) return;

    const cards = Array.from(grid.querySelectorAll('.build-card'));

    // Filter
    let visible = cards.filter(card => {
        if (!query) return true;
        const title = card.dataset.title || '';
        const car = card.dataset.car || '';
        const creator = card.dataset.creator || '';
        return title.includes(query) || car.includes(query) || creator.includes(query);
    });

    cards.forEach(c => c.style.display = 'none');
    visible.forEach(c => c.style.display = '');

    // Sort
    visible.sort((a, b) => {
        if (sort === 'likes')      return parseFloat(b.dataset.likes) - parseFloat(a.dataset.likes);
        if (sort === 'newest')     return parseFloat(b.dataset.date) - parseFloat(a.dataset.date);
        if (sort === 'price_asc')  return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        if (sort === 'price_desc') return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        return 0;
    });
    visible.forEach(c => grid.appendChild(c));

    if (noResults) noResults.style.display = visible.length === 0 ? 'block' : 'none';
}

// --- Hash-scroll: jump to and highlight a specific comment ---
function scrollToComment() {
    const hash = window.location.hash;
    if (!hash || !hash.startsWith('#comment-')) return;
    const el = document.getElementById(hash.substring(1));
    if (!el) return;

    // If it's inside a collapsed replies container, expand it first
    const container = el.closest('[id^="replies-container-"]');
    if (container && container.style.display === 'none') {
        container.style.display = 'block';
        const pid = container.id.replace('replies-container-', '');
        const btn = document.querySelector('.toggle-replies[onclick*="toggleReplies(' + pid + ')"]');
        if (btn) {
            const cnt = document.getElementById('reply-count-' + pid);
            btn.textContent = 'Hide Replies (' + (cnt ? cnt.textContent : '') + ')';
        }
    }

    setTimeout(() => {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.transition = 'background-color 0.4s ease';
        el.style.backgroundColor = 'rgba(200, 136, 58, 0.35)';
        setTimeout(() => { el.style.backgroundColor = ''; }, 2000);
    }, 350);
}
window.addEventListener('load', scrollToComment);
</script>

<?php renderFooter(); ?>