<?php
require_once 'config.php';
$pageTitle = "Contact Admin - ModMyCar";
require_once 'includes/headerFooter.php';

$message_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    $name = htmlspecialchars(trim($_POST['name']));
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
    $message = htmlspecialchars(trim($_POST['message']));

    if ($email && !empty($message)) {
        $to = "admin@yourdomain.com"; // Change to your admin email
        $subject = "New Contact from $name";
        $headers = "From: $email\r\nReply-To: $email\r\n";

        // mail($to, $subject, $message, $headers); // Uncomment when on a live server
        $message_sent = true;
    }
}
renderHeader();
?>
<div class="container">
    <h2>Contact Administrators</h2>
    <?php if ($message_sent): ?>
        <p style="color: green;">Your message has been sent successfully!</p>
    <?php else: ?>
        <form method="POST" class="card">
            <label>Name:</label>
            <input type="text" name="name" required style="width: 100%; margin-bottom: 1rem;">

            <label>Email:</label>
            <input type="email" name="email" required style="width: 100%; margin-bottom: 1rem;">

            <label>Message:</label>
            <textarea name="message" required style="width: 100%; height: 150px; margin-bottom: 1rem;"></textarea>

            <button type="submit" name="send_contact" class="btn">Send Message</button>
        </form>
    <?php endif; ?>
</div>
<?php renderFooter(); ?>