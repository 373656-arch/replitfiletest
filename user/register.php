<?php
require_once '../config.php';

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error = '';
$email = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Core Validation Rules
    if (empty($email) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } 
    // 2. Format Control: Restrict usernames to alphanumeric and underscores (3-20 chars)
    elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = 'Username must be between 3 and 20 characters and contain only letters, numbers, or underscores.';
    } else {
        try {
            // 3. Double-Check Duplicates: Catch both Email AND Username conflicts in one step
            $chk_stmt = $conn->prepare("SELECT email, username FROM users WHERE email = ? OR username = ?");
            $chk_stmt->bind_param("ss", $email, $username);
            $chk_stmt->execute();
            $result = $chk_stmt->get_result();

            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    if (strcasecmp($row['email'], $email) === 0) {
                        $error = 'Email already registered.';
                        break;
                    }
                    if (strcasecmp($row['username'], $username) === 0) {
                        $error = 'Username is already taken.';
                        break;
                    }
                }
            } 
            $chk_stmt->close();

            // 4. Ingestion Process
            if (empty($error)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO users (email, username, password_hash) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $email, $username, $password_hash);

                if ($stmt->execute()) {
                    $new_user_id = $stmt->insert_id;
                    $stmt->close();

                    // Security Milestone: Regenerate session ID to prevent Session Fixation
                    session_regenerate_id(true);

                    // Map Session footprints
                    $_SESSION['user_id']  = $new_user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['email']    = $email;

                    header('Location: /index.php');
                    exit;
                } else {
                    $error = 'Registration failed. Please try again.';
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            error_log("Registration System Crash: " . $e->getMessage());
            $error = 'A database configuration issue occurred. Please try again later.';
        }
    }
}

$pageTitle = "Register - ModMyCar";
require_once '../includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <div class="card card-auth">
        <h2>Create Account</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($email); ?>">
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($username); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn">Create Account</button>
        </form>

        <p class="auth-redirect">Already have an account? <a href="login.php" class="accent-link">Sign in here</a></p>
    </div>
</div>

<?php renderFooter(); ?>