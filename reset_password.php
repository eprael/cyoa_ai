<?php
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

$error = '';
$success = '';
$validToken = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// Validate the token
if (!empty($token)) {
    $email = validate_reset_token($token);
    if ($email) {
        $validToken = true;
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if (empty($password)) {
        $error = 'Please enter a new password.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $user = get_user_by_email($email);
        if ($user) {
            update_user_password($user['userID'], $password);
            delete_reset_token($token);
            $_SESSION['flash_success'] = 'Your password has been reset successfully! Please log in.';
            header('Location: login.php');
            exit;
        } else {
            $error = 'User account not found.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <div class="auth-card">
            <?php if ($validToken): ?>
                <h2>Reset Password</h2>
                <p class="subtitle">Enter your new password below.</p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="reset_password.php">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required minlength="6">
                            <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirmPassword" name="confirmPassword" required minlength="6">
                            <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </form>
            <?php else: ?>
                <h2>Invalid or Expired Link</h2>
                <p class="subtitle">This password reset link is invalid or has expired. Please request a new one.</p>

                <a href="forgot_password.php" class="btn btn-primary" style="display:block; text-align:center; margin-top:1rem;">Request New Link</a>
            <?php endif; ?>

            <div class="auth-links">
                <p><a href="login.php">&larr; Back to Login</a></p>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>
    <script>
    document.querySelectorAll('.password-toggle').forEach(function(btn){
        btn.addEventListener('click',function(){
            var input=this.previousElementSibling;
            var show=input.type==='password';
            input.type=show?'text':'password';
            this.innerHTML=show
                ?'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                :'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
            this.setAttribute('aria-label',show?'Hide password':'Show password');
        });
    });
    </script>
</body>
</html>
