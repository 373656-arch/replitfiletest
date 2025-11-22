<?php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: /user/login.php');
    exit;
}

$user = getUserData($_SESSION['user_id']);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $profile_image = trim($_POST['profileImage'] ?? '');

        if (empty($username) || empty($email)) {
            $error = 'Username and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare("SELECT uid FROM users WHERE email = ? AND uid != ?");
            $stmt->bind_param("si", $email, $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = 'Email already in use by another account.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, profileImage = ? WHERE uid = ?");
                $stmt->bind_param("sssi", $username, $email, $profile_image, $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $success = 'Profile updated successfully!';
                    $user = getUserData($_SESSION['user_id']);
                } else {
                    $error = 'Update failed. Please try again.';
                }
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE uid = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();

        if (!password_verify($current_password, $user_data['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE uid = ?");
            $stmt->bind_param("si", $password_hash, $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $success = 'Password changed successfully!';
            } else {
                $error = 'Password change failed. Please try again.';
            }
        }
    } elseif (isset($_POST['delete_account'])) {
        $password = $_POST['delete_password'] ?? '';

        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE uid = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();

        if (!password_verify($password, $user_data['password_hash'])) {
            $error = 'Password is incorrect.';
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE uid = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                session_destroy();
                header('Location: /index.php?account_deleted=1');
                exit;
            } else {
                $error = 'Account deletion failed. Please try again.';
            }
        }
    }
}

$pageTitle = "Edit Profile - ModMyCar";
require_once '../includes/headerFooter.php';
renderHeader();
?>

<div class="container">
    <div style="max-width: 600px; margin: 0 auto;">
        <h2>Edit Profile</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <h3>Profile Information</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="profileImage">Profile Image URL</label>
                    <input type="url" id="profileImage" name="profileImage" value="<?php echo htmlspecialchars($user['profileImage'] ?? ''); ?>">
                </div>

                <button type="submit" name="update_profile" class="btn">Update Profile</button>
            </form>
        </div>

        <div class="card">
            <h3>Change Password</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" name="change_password" class="btn">Change Password</button>
            </form>
        </div>

        <div class="card" style="border-color: #ef4444;">
            <h3 style="color: #ef4444;">Danger Zone</h3>
            <p>Deleting your account is permanent and cannot be undone. All your builds will be removed.</p>
            <button onclick="document.getElementById('deleteModal').classList.add('active')" class="btn" style="background: #ef4444;">Delete Account</button>
        </div>
    </div>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('deleteModal').classList.remove('active')">&times;</span>
        <h3>Confirm Account Deletion</h3>
        <p>Are you absolutely sure? This action cannot be undone.</p>
        <form method="POST">
            <div class="form-group">
                <label for="delete_password">Enter your password to confirm:</label>
                <input type="password" id="delete_password" name="delete_password" required>
            </div>
            <button type="submit" name="delete_account" class="btn" style="background: #ef4444;">Yes, Delete My Account</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteModal').classList.remove('active')">Cancel</button>
        </form>
    </div>
</div>

<?php renderFooter(); ?>
