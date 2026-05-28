<?php
require_once 'config.php';

$pageTitle = "Contact Us - ModMyCar";
$pageDescription = "Get in touch with the ModMyCar admin team. We're here to help.";
require_once 'includes/headerFooter.php';

$message_sent = isset($_GET['success']) && $_GET['success'] == '1';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    if (!isLoggedIn()) {
        header('Location: /user/login.php');
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $target_admin = trim($_POST['target_admin'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!empty($name) && !empty($email) && !empty($target_admin) && !empty($message)) {
        date_default_timezone_set('America/Los_Angeles');
        $current_time = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("INSERT INTO admin_messages (sender_name, sender_email, target_admin_email, message, date_sent) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $email, $target_admin, $message, $current_time);

        if ($stmt->execute()) {
            $new_msg_id = $conn->insert_id;
            $admin_lookup = $conn->prepare("SELECT uid FROM users WHERE email = ?");
            $admin_lookup->bind_param("s", $target_admin);
            $admin_lookup->execute();
            $admin_row = $admin_lookup->get_result()->fetch_assoc();
            if ($admin_row) {
                $msg_preview = mb_strlen($message) > 60 ? mb_substr($message, 0, 60) . '…' : $message;
                createNotification($admin_row['uid'], 'admin_contact',
                    'New message from ' . $name . ': "' . $msg_preview . '"',
                    '/admin.php?section=messages&msg_id=' . $new_msg_id,
                    $_SESSION['user_id'] ?? null);
            }
            header("Location: contact.php?success=1");
            exit;
        } else {
            $error = "Failed to send message. Please try again.";
        }
    } else {
        $error = "Please fill out all fields.";
    }
}

    $admins_result = $conn->query("SELECT email FROM admin_whitelist");

    // Safely grab the session variables (no database query needed!)
    $current_user_name = $_SESSION['username'] ?? '';
    $current_user_email = $_SESSION['email'] ?? '';

    renderHeader();
    ?>

<div class="container">
    <div class="card" style="max-width: 640px; margin: 0 auto;">
        <div class="section-label">Support</div>
        <h2 style="margin-bottom: 0.4rem;">Contact Us</h2>
        <p class="text-muted" style="margin-bottom: 2rem;">Have a question or issue? Send a message to the admin team and we'll get back to you.</p>

        <?php if ($message_sent): ?>
            <div class="alert alert-success">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
                Your message has been sent successfully!
            </div>
            <a href="contact.php" class="btn btn-secondary">Send Another Message</a>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!isLoggedIn()): ?>
                <div class="alert alert-info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
                    Please <a href="/user/login.php" class="accent-link">sign in</a> to send a message.
                </div>
            <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="name">Your Name</label>
                    <input type="text" id="name" name="name" required placeholder="John Doe" value="<?= htmlspecialchars($current_user_name); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Your Email</label>
                    <input type="email" id="email" name="email" required placeholder="you@example.com" value="<?= htmlspecialchars($current_user_email); ?>">
                </div>

                <div class="form-group">
                    <label for="target_admin">Contact</label>
                    <select id="target_admin" name="target_admin" required>
                        <option value="">— Select Admin —</option>
                        <?php
                        if ($admins_result && $admins_result->num_rows > 0) {
                            while ($admin = $admins_result->fetch_assoc()) {
                                echo '<option value="' . htmlspecialchars($admin['email']) . '">' . htmlspecialchars($admin['email']) . '</option>';
                            }
                        } else {
                            echo '<option value="" disabled>No admins available</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" required placeholder="Describe your question or issue..." style="height: 140px; resize: vertical;"></textarea>
                </div>

                <button type="submit" name="send_contact" class="btn">Send Message</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter(); ?>
