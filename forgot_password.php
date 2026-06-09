<?php
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'mail_helper.php';

// Redirect if already logged in
if (isset($_SESSION['userID'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$email = '';
$resetLink = ''; // For testing on localhost

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if user exists
        $user = get_user_by_email($email);
        if ($user) {
            // Generate a secure random token
            $token = bin2hex(random_bytes(32));
            create_password_reset($email, $token);

            // Build the reset link
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['REQUEST_URI']);
            $resetLink = $protocol . '://' . $host . $path . '/reset_password.php?token=' . $token;

            // Attempt to send email via SMTP
            $subject = 'Password Reset - CYOA Maker';
            $message = "Hello " . $user['firstName'] . ",\n\n";
            $message .= "You requested a password reset. Click the link below to reset your password:\n\n";
            $message .= $resetLink . "\n\n";
            $message .= "This link will expire in 1 hour.\n\n";
            $message .= "If you did not request this, please ignore this email.\n\n";
            $message .= "- CYOA Maker";

            $emailSent = send_mail($email, $user['firstName'], $subject, $message);

            if ($emailSent) {
                $success = 'A password reset link has been sent to your email address.';
                $resetLink = ''; // Don't show testing link if email was sent
            } else {
                $success = 'Email could not be sent. Use the link below to reset your password.';
            }
        } else {
            // Don't reveal whether the email exists
            $success = 'If an account with that email exists, a password reset link has been generated.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <div class="auth-card">
            <h2>Forgot Password</h2>
            <p class="subtitle">Enter your email address and we'll send you a link to reset your password.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>

                <?php if ($resetLink): ?>
                    <!-- Reset link displayed for localhost testing (email may not work locally) -->
                    <div class="alert alert-success" style="margin-top: 0.5rem; word-break: break-all;">
                        <strong>Testing link:</strong><br>
                        <a href="<?php echo htmlspecialchars($resetLink); ?>"><?php echo htmlspecialchars($resetLink); ?></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" action="forgot_password.php">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <button type="submit" class="btn btn-primary">Send Reset Link</button>
            </form>

            <div class="auth-links">
                <p><a href="login.php">&larr; Back to Login</a></p>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>
</body>
</html>
