<?php
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error_msg = '';
$success_msg = '';

// Handle AJAX Requests & Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] == '1';
    $action = $_POST['action'] ?? '';

    // 1. Live Search for Duplicate Prevention
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

    // 2. Handle Answer Upvotes
    if ($action === 'upvote_answer' && $is_ajax) {
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Log in to upvote.']);
            exit;
        }

        $answer_id = (int)$_POST['answer_id'];

        // Prevent spam clicking during the session
        if (!isset($_SESSION['upvoted_answers'])) $_SESSION['upvoted_answers'] = [];

        if (!in_array($answer_id, $_SESSION['upvoted_answers'])) {
            $conn->query("UPDATE qa_answers SET upvotes = upvotes + 1 WHERE id = $answer_id");
            $_SESSION['upvoted_answers'][] = $answer_id;

            $res = $conn->query("SELECT upvotes FROM qa_answers WHERE id = $answer_id");
            $new_count = $res->fetch_assoc()['upvotes'];

            echo json_encode(['success' => true, 'new_count' => $new_count]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Already voted!']);
        }
        exit;
    }

    // Require login for posting questions/answers
    if (!isLoggedIn() && in_array($action, ['ask_question', 'post_answer'])) {
        $error_msg = "You must be logged in to post.";
    } else {
        // Handle Posting a New Question
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
                        $error_msg = "An error occurred: " . $e->getMessage();
                    }
                }
            }
        }

        // Handle Posting an Answer
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
                    }
                }
            }
        }
    }
}

// Fetch Community Questions
$questions_query = "
    SELECT q.*, u.username, 
           (SELECT COUNT(*) FROM qa_answers WHERE question_id = q.id) as answer_count 
    FROM qa_questions q 
    JOIN users u ON q.user_id = u.uid 
    ORDER BY q.date_posted DESC LIMIT 50";
$community_questions = $conn->query($questions_query);

$pageTitle = "FAQ & Q&A - ModMyCar";
require_once 'includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <h2 style="margin-bottom: 1.5rem;">Help Center & Community Q&A</h2>

    <div id="alert-container">
        <?php if ($error_msg): ?>
            <div class="alert-msg" style="background: #ef4444; color: white; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert-msg" style="background: #22c55e; color: white; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">
                <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: start;">

        <div>
            <div class="card" style="margin-bottom: 2rem;">
                <h3>Ask the Community</h3>
                <p style="font-size: 0.9rem; color: #aaa; margin-bottom: 1rem;">Start typing to see if your question has already been answered.</p>

                <form method="POST">
                    <input type="hidden" name="action" value="ask_question">

                    <input type="text" id="question_title" name="title" placeholder="E.g., What is the best cold air intake for a 2018 Civic?" required style="width: 100%; padding: 0.75rem; margin-bottom: 0.5rem; border-radius: 5px; border: 1px solid var(--accent-2); background: var(--bg-primary); color: white;">

                    <div id="duplicate-warning" style="display: none; background: #333; padding: 1rem; border-left: 4px solid #f59e0b; margin-bottom: 1rem; border-radius: 4px;">
                        <strong style="color: #f59e0b;">Similar questions found:</strong>
                        <ul id="similar-list" style="margin-top: 0.5rem; padding-left: 1.5rem; color: #ccc;"></ul>
                    </div>

                    <textarea name="description" placeholder="Add more details (optional)..." style="width: 100%; padding: 0.75rem; margin-bottom: 1rem; border-radius: 5px; border: 1px solid #444; background: var(--bg-primary); color: white; height: 100px;"></textarea>

                    <button type="submit" class="btn">Post Question</button>
                </form>
            </div>

            <h3 style="margin-bottom: 1rem;">Recent Discussions</h3>
            <?php if ($community_questions && $community_questions->num_rows > 0): ?>
                <?php while ($q = $community_questions->fetch_assoc()): ?>
                    <div class="card" style="margin-bottom: 1.5rem; border-left: 3px solid var(--accent-2);">
                        <h4 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($q['title']); ?></h4>
                        <div style="font-size: 0.85rem; color: #888; margin-bottom: 1rem;">
                            Asked by <strong style="color: var(--accent-2);"><?php echo htmlspecialchars($q['username']); ?></strong> on <?php echo date('M j, Y', strtotime($q['date_posted'])); ?>
                        </div>
                        <?php if (!empty($q['description'])): ?>
                            <p style="margin-bottom: 1rem;"><?php echo nl2br(htmlspecialchars($q['description'])); ?></p>
                        <?php endif; ?>

                        <div style="margin-top: 1rem; border-top: 1px solid #444; padding-top: 1rem;">
                            <h5 style="margin-bottom: 1rem; color: #aaa;"><?php echo (int)$q['answer_count']; ?> Answers</h5>

                            <?php
                            $ans_stmt = $conn->prepare("SELECT a.*, u.username FROM qa_answers a JOIN users u ON a.user_id = u.uid WHERE a.question_id = ? ORDER BY a.upvotes DESC, a.date_posted ASC");
                            $ans_stmt->bind_param("i", $q['id']);
                            $ans_stmt->execute();
                            $answers = $ans_stmt->get_result();

                            while ($ans = $answers->fetch_assoc()):
                            ?>
                                <div style="background: var(--bg-primary); padding: 1rem; border-radius: 5px; margin-bottom: 0.5rem; display: flex; gap: 1rem; align-items: flex-start;">

                                    <div style="display: flex; flex-direction: column; align-items: center; min-width: 40px;">
                                        <button onclick="upvoteAnswer(<?php echo $ans['id']; ?>, this)" style="background: transparent; border: none; color: var(--accent-1); font-size: 1.5rem; cursor: pointer; padding: 0;">▲</button>
                                        <span class="upvote-count" style="font-weight: bold; font-size: 0.9rem; color: #fff;"><?php echo (int)$ans['upvotes']; ?></span>
                                    </div>

                                    <div style="flex-grow: 1;">
                                        <p style="margin-bottom: 0.5rem; line-height: 1.4;"><?php echo nl2br(htmlspecialchars($ans['content'])); ?></p>
                                        <span style="font-size: 0.8rem; color: #888;">Answered by <strong><?php echo htmlspecialchars($ans['username']); ?></strong></span>
                                    </div>
                                </div>
                            <?php endwhile; ?>

                            <?php if (isLoggedIn()): ?>
                                <form method="POST" style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                    <input type="hidden" name="action" value="post_answer">
                                    <input type="hidden" name="question_id" value="<?php echo (int)$q['id']; ?>">
                                    <input type="text" name="content" placeholder="Type your answer here..." required style="flex-grow: 1; padding: 0.5rem; border-radius: 3px; border: 1px solid #555; background: #222; color: white;">
                                    <button type="submit" class="btn btn-secondary">Submit</button>
                                </form>
                            <?php else: ?>
                                <p style="font-size: 0.85rem; color: #888; margin-top: 1rem;"><a href="/user/login.php" style="color: var(--accent-2);">Log in</a> to post an answer.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No questions yet. Be the first to ask!</p>
            <?php endif; ?>
        </div>

        <div class="sidebar">
            <div class="card" style="position: sticky; top: 2rem;">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem; border-bottom: 1px solid #444; padding-bottom: 0.5rem;">Site FAQ</h3>

                <details style="margin-bottom: 1rem; cursor: pointer;">
                    <summary style="font-weight: bold; color: var(--accent-1); margin-bottom: 0.5rem;">How do I fork a build?</summary>
                    <p style="font-size: 0.85rem; color: #ccc; line-height: 1.4;">Navigate to the Community page, select a build you like, and click the "Fork Build" button. This copies the parts into your own workspace.</p>
                </details>

                <details style="margin-bottom: 1rem; cursor: pointer;">
                    <summary style="font-weight: bold; color: var(--accent-1); margin-bottom: 0.5rem;">Where are prices from?</summary>
                    <p style="font-size: 0.85rem; color: #ccc; line-height: 1.4;">Prices are estimates scraped from major online retailers. Click "View Product" on a build to see the live price before buying.</p>
                </details>

                <details style="margin-bottom: 1rem; cursor: pointer;">
                    <summary style="font-weight: bold; color: var(--accent-1); margin-bottom: 0.5rem;">How do I share my build?</summary>
                    <p style="font-size: 0.85rem; color: #ccc; line-height: 1.4;">When saving your build in the workspace, check the "Share to Community" box to make it public.</p>
                </details>
            </div>
        </div>

    </div>
</div>

<script>
// --- 1. Fade out alerts smoothly after 3.5 seconds ---
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-msg');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease-out';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500); // Remove from DOM after fade completes
    });
}, 3500);


// --- 2. Upvote Logic ---
function upvoteAnswer(answerId, btnElement) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'upvote_answer');
    formData.append('answer_id', answerId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update the count visually
            const countSpan = btnElement.parentElement.querySelector('.upvote-count');
            countSpan.textContent = data.new_count;
            btnElement.style.color = '#fff'; // Visual feedback that they clicked it
            btnElement.disabled = true;
        } else {
            alert(data.error); // E.g., "Log in to upvote" or "Already voted"
        }
    })
    .catch(err => console.error('Error upvoting:', err));
}


// --- 3. Live search for duplicate questions ---
let timeout = null;
const titleInput = document.getElementById('question_title');
const warningBox = document.getElementById('duplicate-warning');
const similarList = document.getElementById('similar-list');

if (titleInput) {
    titleInput.addEventListener('input', function() {
        clearTimeout(timeout);
        const query = this.value.trim();

        if (query.length < 5) {
            warningBox.style.display = 'none';
            return;
        }

        timeout = setTimeout(() => {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'search_questions');
            formData.append('query', query);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
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