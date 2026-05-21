<?php
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$conn->query("CREATE TABLE IF NOT EXISTS qa_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    answer_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    date_posted TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (answer_id)
)");

$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] == '1';
    $action = $_POST['action'] ?? '';

    if ($action === 'search_questions' && $is_ajax) {
        $search_term = '%' . trim($_POST['query']) . '%';
        $stmt = $conn->prepare("SELECT id, title FROM qa_questions WHERE title LIKE ? LIMIT 5");
        $stmt->bind_param("s", $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        $matches = [];
        while ($row = $result->fetch_assoc()) {
            $matches[] = $row;
        }
        echo json_encode(['success' => true, 'matches' => $matches]);
        exit;
    }

    if ($action === 'upvote_answer' && $is_ajax) {
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Log in to upvote.']);
            exit;
        }
        $answer_id = (int)$_POST['answer_id'];
        $user_id = (int)$_SESSION['user_id'];

        $check_stmt = $conn->prepare("SELECT id FROM qa_upvotes WHERE user_id = ? AND answer_id = ?");
        $check_stmt->bind_param("ii", $user_id, $answer_id);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows === 0) {
            $ins_stmt = $conn->prepare("INSERT INTO qa_upvotes (user_id, answer_id) VALUES (?, ?)");
            $ins_stmt->bind_param("ii", $user_id, $answer_id);
            $ins_stmt->execute();

            $upd_stmt = $conn->prepare("UPDATE qa_answers SET upvotes = upvotes + 1 WHERE id = ?");
            $upd_stmt->bind_param("i", $answer_id);
            $upd_stmt->execute();

            $res_stmt = $conn->prepare("SELECT upvotes, user_id FROM qa_answers WHERE id = ?");
            $res_stmt->bind_param("i", $answer_id);
            $res_stmt->execute();
            $ans_row  = $res_stmt->get_result()->fetch_assoc();
            $new_count = $ans_row['upvotes'];
            $ans_owner = $ans_row['user_id'];

            if ($ans_owner != $user_id) {
                $u_data = getUserData($user_id);
                createNotification($ans_owner, 'like', $u_data['username'] . " upvoted your answer.", "/faq.php", $user_id);
            }

            echo json_encode(['success' => true, 'new_count' => $new_count]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Already voted!']);
        }
        exit;
    }

    if ($action === 'add_faq_reply' && $is_ajax) {
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Log in to reply.']);
            exit;
        }
        $answer_id = (int)$_POST['answer_id'];
        $content   = trim($_POST['content'] ?? '');
        if ($content === '') {
            echo json_encode(['success' => false, 'error' => 'Reply cannot be empty.']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO qa_replies (answer_id, user_id, content) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $answer_id, $_SESSION['user_id'], $content);
        if ($stmt->execute()) {
            $owner_stmt = $conn->prepare("SELECT user_id FROM qa_answers WHERE id = ?");
            $owner_stmt->bind_param("i", $answer_id);
            $owner_stmt->execute();
            $ans_owner = $owner_stmt->get_result()->fetch_assoc()['user_id'] ?? null;
            if ($ans_owner && $ans_owner != $_SESSION['user_id']) {
                $u_data = getUserData($_SESSION['user_id']);
                $preview = mb_strlen($content) > 60 ? mb_substr($content, 0, 60) . '…' : $content;
                createNotification($ans_owner, 'reply', $u_data['username'] . ' replied to your answer: "' . $preview . '"', "/faq.php", $_SESSION['user_id']);
            }
            $u = getUserData($_SESSION['user_id']);
            echo json_encode(['success' => true, 'username' => $u['username'], 'content' => htmlspecialchars($content), 'date' => date('M j, Y')]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to post reply.']);
        }
        exit;
    }

    if (!isLoggedIn() && in_array($action, ['ask_question', 'post_answer'])) {
        $error_msg = "You must be logged in to post.";
    } else {
        if ($action === 'ask_question') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            if (strlen($title) < 10) {
                $error_msg = "Question must be at least 10 characters long.";
            } else {
                $stmt = $conn->prepare("INSERT INTO qa_questions (user_id, title, description) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $_SESSION['user_id'], $title, $description);
                try {
                    if ($stmt->execute()) {
                        $success_msg = "Question posted successfully!";
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        $error_msg = "This exact question has already been asked. Please check the feed!";
                    } else {
                        $error_msg = "An error occurred. Please try again.";
                    }
                }
            }
        }

        if ($action === 'post_answer') {
            $question_id = (int)$_POST['question_id'];
            $content = trim($_POST['content'] ?? '');
            if ($content !== '') {
                $check = $conn->prepare("SELECT id FROM qa_answers WHERE question_id = ? AND user_id = ?");
                $check->bind_param("ii", $question_id, $_SESSION['user_id']);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error_msg = "You have already answered this question.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO qa_answers (question_id, user_id, content) VALUES (?, ?, ?)");
                    $stmt->bind_param("iis", $question_id, $_SESSION['user_id'], $content);
                    if ($stmt->execute()) {
                        $success_msg = "Answer posted!";
                        $owner_stmt = $conn->prepare("SELECT user_id FROM qa_questions WHERE id = ?");
                        $owner_stmt->bind_param("i", $question_id);
                        $owner_stmt->execute();
                        $q_owner = $owner_stmt->get_result()->fetch_assoc()['user_id'] ?? null;
                        if ($q_owner && $q_owner != $_SESSION['user_id']) {
                            $u_data = getUserData($_SESSION['user_id']);
                            createNotification($q_owner, 'comment', $u_data['username'] . " answered your question.", "/faq.php", $_SESSION['user_id']);
                        }
                    }
                }
            }
        }
    }
}

$questions_query = "
    SELECT q.*, u.username,
           (SELECT COUNT(*) FROM qa_answers WHERE question_id = q.id) as answer_count
    FROM qa_questions q
    JOIN users u ON q.user_id = u.uid
    ORDER BY q.date_posted DESC LIMIT 50";
$community_questions = $conn->query($questions_query);
$user_upvotes = [];
if (isLoggedIn()) {
    $uv_stmt = $conn->prepare("SELECT answer_id FROM qa_upvotes WHERE user_id = ?");
    $uv_stmt->bind_param("i", $_SESSION['user_id']);
    $uv_stmt->execute();
    $uv_res = $uv_stmt->get_result();
    while ($row = $uv_res->fetch_assoc()) {
        $user_upvotes[] = $row['answer_id'];
    }
}
$pageTitle = "FAQ & Q&A - ModMyCar";
$pageDescription = "Get answers from the ModMyCar community. Ask questions, share tips, and learn from fellow car enthusiasts.";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<style>
.faq-layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
    align-items: start;
}
.question-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-left: 3px solid var(--accent-2);
    border-radius: 0 10px 10px 0;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: border-color 0.2s;
}
.question-card:hover {
    border-color: var(--accent-1);
    border-left-color: var(--accent-1);
}
.question-meta {
    font-size: 0.82rem;
    color: var(--text-secondary);
    margin-bottom: 1rem;
}
.question-meta strong {
    color: var(--accent-2);
}
.answer-block {
    background: var(--bg-tertiary);
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 0.5rem;
    display: flex;
    gap: 1rem;
    align-items: flex-start;
}
.upvote-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 36px;
    gap: 2px;
}
.upvote-btn {
    background: transparent;
    border: none;
    color: var(--accent-1);
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    transition: color 0.2s;
}
.upvote-btn:hover { color: var(--accent-2); }
.upvote-btn:disabled { color: var(--text-secondary); cursor: default; }
.upvote-count {
    font-weight: 700;
    font-size: 0.88rem;
}
.answers-section {
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--border-color);
}
.answer-count-label {
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-secondary);
    margin-bottom: 0.75rem;
}
.answer-form {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}
.answer-form input {
    flex: 1;
    margin: 0;
}
.faq-sidebar-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1.5rem;
    position: sticky;
    top: 5rem;
}
.faq-sidebar-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-color);
}
details {
    margin-bottom: 1rem;
    cursor: pointer;
}
details summary {
    font-weight: 600;
    color: var(--accent-1);
    margin-bottom: 0.4rem;
    font-size: 0.9rem;
    list-style: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
details summary::after {
    content: '+';
    font-size: 1.1rem;
    color: var(--text-secondary);
    transition: transform 0.2s;
}
details[open] summary::after {
    content: '−';
}
details p {
    font-size: 0.85rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin-top: 0.4rem;
}
.dup-warning {
    display: none;
    background: var(--bg-tertiary);
    padding: 1rem;
    border-left: 3px solid #f59e0b;
    margin-bottom: 1rem;
    border-radius: 0 6px 6px 0;
}
.dup-warning strong { color: #f59e0b; }
.dup-warning ul { margin-top: 0.5rem; padding-left: 1.25rem; color: var(--text-secondary); font-size: 0.88rem; }
@media (max-width: 768px) {
    .faq-layout { grid-template-columns: 1fr; }
    .faq-sidebar-card { position: static; }
}
</style>

<div class="container">
    <div class="section-label">Help</div>
    <h2 style="margin-bottom: 0.4rem;">FAQ &amp; Community Q&amp;A</h2>
    <p class="text-muted" style="margin-bottom: 2rem;">Ask questions and get answers from the ModMyCar community.</p>

    <div id="alert-container">
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
    </div>

    <div class="faq-layout">
        <div>
            <div class="card" style="margin-bottom: 2rem;">
                <h3>Ask the Community</h3>
                <p class="text-muted fs-085" style="margin-bottom: 1rem;">Start typing to see if your question has already been answered.</p>

                <?php if (!isLoggedIn()): ?>
                    <div class="alert alert-info">
                        <a href="/user/login.php" class="accent-link">Sign in</a>&nbsp; to ask a question.
                    </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="ask_question">
                    <div class="form-group">
                        <label for="question_title">Your Question</label>
                        <input type="text" id="question_title" name="title" placeholder="E.g., What is the best cold air intake for a 2018 Civic?" required>
                    </div>
                    <div id="duplicate-warning" class="dup-warning">
                        <strong>Similar questions found:</strong>
                        <ul id="similar-list"></ul>
                    </div>
                    <div class="form-group">
                        <label for="question_desc">Additional Details (optional)</label>
                        <textarea id="question_desc" name="description" placeholder="Add more context..." style="height: 90px; resize: vertical;"></textarea>
                    </div>
                    <button type="submit" class="btn">Post Question</button>
                </form>
                <?php endif; ?>
            </div>

            <h3 style="margin-bottom: 1.25rem;">Recent Discussions</h3>
            <?php if ($community_questions && $community_questions->num_rows > 0): ?>
                <?php while ($q = $community_questions->fetch_assoc()): ?>
                    <div class="question-card">
                        <h4 style="margin-bottom: 0.4rem;"><?php echo htmlspecialchars($q['title']); ?></h4>
                        <div class="question-meta">
                            Asked by <strong><?php echo htmlspecialchars($q['username']); ?></strong>
                            &bull; <?php echo date('M j, Y', strtotime($q['date_posted'])); ?>
                        </div>
                        <?php if (!empty($q['description'])): ?>
                            <p style="font-size: 0.9rem; margin-bottom: 1rem; color: var(--text-secondary);"><?php echo nl2br(htmlspecialchars($q['description'])); ?></p>
                        <?php endif; ?>

                        <div class="answers-section">
                            <div class="answer-count-label"><?php echo (int)$q['answer_count']; ?> <?php echo $q['answer_count'] == 1 ? 'Answer' : 'Answers'; ?></div>

                            <?php
                            $ans_stmt = $conn->prepare("SELECT a.*, u.username FROM qa_answers a JOIN users u ON a.user_id = u.uid WHERE a.question_id = ? ORDER BY a.upvotes DESC, a.date_posted ASC");
                            $ans_stmt->bind_param("i", $q['id']);
                            $ans_stmt->execute();
                            $answers = $ans_stmt->get_result();
                            while ($ans = $answers->fetch_assoc()):
                            ?>
                                <div class="answer-block" id="answer-<?php echo $ans['id']; ?>">
                                    <div class="upvote-col">
                                        <?php $has_voted = in_array($ans['id'], $user_upvotes); ?>
                                        <button class="upvote-btn" 
                                                onclick="upvoteAnswer(<?php echo $ans['id']; ?>, this)" 
                                                title="<?php echo $has_voted ? 'Already upvoted' : 'Upvote'; ?>"
                                                <?php echo $has_voted ? 'disabled style="color: var(--text-secondary);"' : ''; ?>>
                                            ▲
                                        </button>
                                        <span class="upvote-count"><?php echo (int)$ans['upvotes']; ?></span>
                                    </div>
                                    <div style="flex: 1;">
                                        <p style="margin-bottom: 0.4rem; font-size: 0.9rem; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($ans['content'])); ?></p>
                                        <span class="text-muted fs-085">by <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($ans['username']); ?></strong></span>

                                        <?php
                                        $rep_stmt = $conn->prepare("SELECT r.*, u.username FROM qa_replies r JOIN users u ON r.user_id = u.uid WHERE r.answer_id = ? ORDER BY r.date_posted ASC");
                                        $rep_stmt->bind_param("i", $ans['id']);
                                        $rep_stmt->execute();
                                        $replies = $rep_stmt->get_result();
                                        if ($replies->num_rows > 0): ?>
                                            <div class="faq-replies" id="replies-<?php echo $ans['id']; ?>" style="margin-top: 0.75rem; padding-left: 1rem; border-left: 2px solid var(--border-color);">
                                                <?php while ($rep = $replies->fetch_assoc()): ?>
                                                    <div class="faq-reply" style="font-size: 0.85rem; margin-bottom: 0.5rem; color: var(--text-secondary);">
                                                        <strong style="color: var(--accent-2);"><?php echo htmlspecialchars($rep['username']); ?></strong>:
                                                        <?php echo nl2br(htmlspecialchars($rep['content'])); ?>
                                                        <span style="font-size: 0.75rem; color: var(--text-secondary); margin-left: 0.4rem;"><?php echo date('M j', strtotime($rep['date_posted'])); ?></span>
                                                    </div>
                                                <?php endwhile; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="faq-replies" id="replies-<?php echo $ans['id']; ?>" style="margin-top: 0.75rem; padding-left: 1rem; border-left: 2px solid var(--border-color); display: none;"></div>
                                        <?php endif; ?>

                                        <?php if (isLoggedIn()): ?>
                                            <button onclick="toggleFaqReplyForm(<?php echo $ans['id']; ?>)" class="btn btn-secondary btn-sm" style="margin-top: 0.5rem; padding: 0.3rem 0.75rem; font-size: 0.8rem;">Reply</button>
                                            <div id="faq-reply-form-<?php echo $ans['id']; ?>" style="display: none; margin-top: 0.5rem;">
                                                <div style="display: flex; gap: 0.5rem;">
                                                    <input type="text" id="faq-reply-input-<?php echo $ans['id']; ?>" placeholder="Write a reply…" style="flex: 1; margin: 0; font-size: 0.85rem;">
                                                    <button onclick="submitFaqReply(<?php echo $ans['id']; ?>)" class="btn btn-secondary btn-sm" style="white-space: nowrap;">Post</button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>

                            <?php if (isLoggedIn()): ?>
                                <form method="POST" class="answer-form">
                                    <input type="hidden" name="action" value="post_answer">
                                    <input type="hidden" name="question_id" value="<?php echo (int)$q['id']; ?>">
                                    <input type="text" name="content" placeholder="Write your answer..." required style="margin: 0;">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="white-space: nowrap;">Submit</button>
                                </form>
                            <?php else: ?>
                                <p class="text-muted fs-085" style="margin-top: 0.75rem;"><a href="/user/login.php" class="accent-link">Sign in</a> to post an answer.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p class="empty-state-text">No questions yet</p>
                    <p class="empty-state-subtext">Be the first to ask the community something!</p>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="faq-sidebar-card">
                <h3>Site FAQ</h3>

                <details>
                    <summary>How do I fork a build?</summary>
                    <p>Navigate to the Community page, select a build you like, and click the "Fork Build" button. This copies the parts into your own workspace so you can customize it.</p>
                </details>

                <details>
                    <summary>Where are prices from?</summary>
                    <p>Prices are estimates from major online retailers. Click "View" on any part to see the live price before buying.</p>
                </details>

                <details>
                    <summary>How do I share my build?</summary>
                    <p>When saving your build, check the "Share with Community" box to make it public for others to see and fork.</p>
                </details>

                <details>
                    <summary>What is the HP Estimator?</summary>
                    <p>The HP Estimator uses AI to calculate an estimated horsepower boost based on the compatible parts you've added to your build.</p>
                </details>

                <details>
                    <summary>How does compatibility work?</summary>
                    <p>Parts are tagged with engine codes and chassis codes. When you select your car, only parts that match your engine or chassis will be marked as compatible.</p>
                </details>
            </div>
        </div>
    </div>
</div>

<script>
setTimeout(function() {
    const alerts = document.querySelectorAll('#alert-container .alert');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease-out';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 4000);

function upvoteAnswer(answerId, btnElement) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'upvote_answer');
    formData.append('answer_id', answerId);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const countSpan = btnElement.parentElement.querySelector('.upvote-count');
                countSpan.textContent = data.new_count;
                btnElement.style.color = 'var(--text-primary)';
                btnElement.disabled = true;
            } else {
                alert(data.error);
            }
        })
        .catch(err => console.error('Error upvoting:', err));
}

function toggleFaqReplyForm(answerId) {
    const form = document.getElementById('faq-reply-form-' + answerId);
    if (!form) return;
    const visible = form.style.display === 'block';
    form.style.display = visible ? 'none' : 'block';
    if (!visible) {
        const input = document.getElementById('faq-reply-input-' + answerId);
        if (input) input.focus();
    }
}

function submitFaqReply(answerId) {
    const input = document.getElementById('faq-reply-input-' + answerId);
    if (!input || !input.value.trim()) return;

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'add_faq_reply');
    formData.append('answer_id', answerId);
    formData.append('content', input.value.trim());

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const repliesBox = document.getElementById('replies-' + answerId);
                if (repliesBox) {
                    repliesBox.style.display = 'block';
                    const div = document.createElement('div');
                    div.className = 'faq-reply';
                    div.style.cssText = 'font-size: 0.85rem; margin-bottom: 0.5rem; color: var(--text-secondary);';
                    div.innerHTML = '<strong style="color: var(--accent-2);">' + data.username + '</strong>: ' + data.content + ' <span style="font-size: 0.75rem; color: var(--text-secondary); margin-left: 0.4rem;">' + data.date + '</span>';
                    repliesBox.appendChild(div);
                }
                input.value = '';
                document.getElementById('faq-reply-form-' + answerId).style.display = 'none';
            } else {
                alert(data.error || 'Failed to post reply.');
            }
        })
        .catch(err => console.error('Error posting reply:', err));
}

let timeout = null;
const titleInput = document.getElementById('question_title');
const warningBox = document.getElementById('duplicate-warning');
const similarList = document.getElementById('similar-list');

if (titleInput) {
    titleInput.addEventListener('input', function() {
        clearTimeout(timeout);
        const query = this.value.trim();
        if (query.length < 5) { warningBox.style.display = 'none'; return; }

        timeout = setTimeout(() => {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'search_questions');
            formData.append('query', query);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.matches.length > 0) {
                        similarList.innerHTML = '';
                        data.matches.forEach(match => {
                            const li = document.createElement('li');
                            li.textContent = match.title;
                            similarList.appendChild(li);
                        });
                        warningBox.style.display = 'block';
                    } else {
                        warningBox.style.display = 'none';
                    }
                })
                .catch(err => console.error(err));
        }, 500);
    });
}
</script>

<?php renderFooter(); ?>
