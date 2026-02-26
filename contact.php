<?php
require_once 'config.php';
$pageTitle = "Contact Admin - ModMyCar";
require_once 'includes/headerFooter.php';

// Check if we just redirected here after a successful send
$message_sent = isset($_GET['success']) && $_GET['success'] == '1';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $target_admin = trim($_POST['target_admin'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!empty($name) && !empty($email) && !empty($target_admin) && !empty($message)) {

        // FIX: Set your local timezone (I set it to Pacific Time based on your location)
        date_default_timezone_set('America/Los_Angeles'); 
        $current_time = date('Y-m-d H:i:s');

        // Insert including the precise local time
        $stmt = $conn->prepare("INSERT INTO admin_messages (sender_name, sender_email, target_admin_email, message, date_sent) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $email, $target_admin, $message, $current_time);

        if ($stmt->execute()) {
            // FIX: Redirect to clear POST data and prevent double-submits on refresh
            header("Location: contact.php?success=1");
            exit;
        } else {
            $error = "Failed to send message.";
        }
    } else {
        $error = "Please fill out all fields and select a valid admin.";
    }
}

$admins_result = $conn->query("SELECT email FROM admin_whitelist");
renderHeader();
?>
renderHeader();
?>

<div class="container">
    <h2>Contact Administrators</h2>

    <?php if ($message_sent): ?>
        <div style="background: #22c55e; color: white; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">
            Your message has been sent to the admin team!
        </div>
        <a href="contact.php" class="btn btn-secondary">Send another message</a>
    <?php else: ?>
        <form method="POST" class="card">
            <label>Your Name:</label>
            <input type="text" name="name" required style="width: 100%; margin-bottom: 1rem; padding: 0.5rem;">

            <label>Your Email:</label>
            <input type="email" name="email" required style="width: 100%; margin-bottom: 1rem; padding: 0.5rem;">

            <label>Select Admin to Contact:</label>
            <select name="target_admin" required style="width: 100%; margin-bottom: 1rem; padding: 0.5rem;">
                <option value="">-- Choose an Admin --</option>
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

            <label>Message:</label>
            <textarea name="message" required style="width: 100%; height: 150px; margin-bottom: 1rem; padding: 0.5rem;"></textarea>

            <button type="submit" name="send_contact" class="btn">Submit Message</button>
        </form>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>