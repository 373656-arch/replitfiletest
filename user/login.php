<?php
declare(strict_types=1);
require_once '../config.php';

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error = '';
$email = ''; // Initialize email variable for sticky form use

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT uid, username, email, password_hash FROM users WHERE email = ?");
            if (!$stmt) {
                throw new Exception("Database preparation failed.");
            }

            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                if (password_verify($password, $user['password_hash'])) {

                    // SECURITY UPGRADE: Prevent Session Fixation attacks
                    // This creates a fresh session ID upon privilege change
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['uid'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];

                    header('Location: /index.php');
                    exit;
                }
            }

            // Generic error message keeps user guessing hidden details
            $error = 'Invalid email or password.';

        } catch (Exception $e) {
            error_log($e->getMessage());
            $error = 'A secure connection error occurred. Please try again later.';
        } finally {
            if (isset($stmt) && $stmt !== false) {
                $stmt->close();
            }
        }
    }
}

$pageTitle = "Login - ModMyCar";
require_once '../includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <div class="card card-auth">
        <h2>Sign In</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['registered']) && $_GET['registered'] === 'success'): ?>
            <div class="alert alert-success">Registration successful! Please log in.</div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn">Sign In</button>
        </form>

        <p class="auth-redirect">Don't have an account? <a href="register.php" class="accent-link">Register here</a></p>
    </div>
</div>

<?php renderFooter(); ?>