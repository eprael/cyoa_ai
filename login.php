<?php
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

// Redirect if already logged in
if (isset($_SESSION['userID'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$email = '';

// Check for flash messages from registration or password reset
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $user = login_user($email, $password);
        if ($user) {
            $_SESSION['userID'] = $user['userID'];
            $_SESSION['firstName'] = $user['firstName'];
            $_SESSION['lastName'] = $user['lastName'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['profileImage'] = $user['profileImage'];
            $_SESSION['isAdmin'] = (int)($user['isAdmin'] ?? 0);

            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), time() + 60*60*24*30,
                    $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <div class="auth-card">
            <h2>Welcome Back</h2>
            <p class="subtitle">Log in to create and manage your adventures</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me</label>
                </div>

                <button type="submit" class="btn btn-primary">Login</button>
            </form>

            <div class="auth-links">
                <p>Don't have an account? <a href="register.php">Create Account</a></p>
                <p><a href="forgot_password.php">Forgot your password?</a></p>
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
