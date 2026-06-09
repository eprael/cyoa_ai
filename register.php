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
$firstName = '';
$lastName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    // Validation
    if (empty($firstName) || empty($lastName)) {
        $error = 'First name and last name are required.';
    } elseif (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($password)) {
        $error = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Handle profile image upload
        $profileImage = '';
        if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === UPLOAD_ERR_OK) {
            $profileImage = upload_profile_image($_FILES['profileImage']);
        }

        $claudeKey = trim($_POST['claude_api_key'] ?? '');
        $openaiKey = trim($_POST['openai_api_key'] ?? '');

        $newUserID = register_user($firstName, $lastName, $email, $password, $profileImage, $claudeKey, $openaiKey);
        if ($newUserID) {
            // Send welcome email (non-blocking — don't prevent registration if it fails)
            send_welcome_email($email, $firstName);

            $_SESSION['flash_success'] = 'Account created successfully! Please log in.';
            header('Location: login.php');
            exit;
        } else {
            $error = 'An account with that email already exists.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <div class="auth-card">
            <h2>Create Account</h2>
            <p class="subtitle">Sign up to create your own adventures</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="register.php" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name</label>
                        <input type="text" id="firstName" name="firstName" value="<?php echo htmlspecialchars($firstName); ?>" required maxlength="128">
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name</label>
                        <input type="text" id="lastName" name="lastName" value="<?php echo htmlspecialchars($lastName); ?>" required maxlength="128">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required maxlength="256">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required minlength="6">
                        <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirmPassword" name="confirmPassword" required minlength="6">
                        <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="profileImage">Profile Image (optional)</label>
                    <div style="display:flex; align-items:center; gap:1rem;">
                        <img id="profile-img-preview" src="" alt="Preview"
                             style="width:80px; height:80px; object-fit:cover; border-radius:50%; border:1px solid var(--border); display:none;">
                        <input type="file" id="profileImage" name="profileImage" accept="image/*"
                               onchange="previewImage(this,'profile-img-preview')">
                    </div>
                </div>

                <details class="form-group" style="margin-bottom:1.25rem;">
                    <summary style="cursor:pointer; font-weight:600; color:var(--text-light); font-size:0.9rem;">
                        AI API Keys (optional)
                    </summary>
                    <div style="margin-top:0.75rem; padding:1rem; background:var(--bg-secondary,#f8f8f8); border-radius:var(--radius); border:1px solid var(--border);">
                        <p style="font-size:0.82rem; color:var(--text-light); margin-bottom:0.75rem;">
                            Bring your own Claude or OpenAI key to use AI features on your account.
                            Leave blank to use the site-wide keys.
                        </p>
                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label for="claude_api_key" style="font-size:0.88rem;">Claude API Key</label>
                            <input type="text" id="claude_api_key" name="claude_api_key"
                                   placeholder="sk-ant-api03-..." maxlength="255" autocomplete="off">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="openai_api_key" style="font-size:0.88rem;">OpenAI API Key</label>
                            <input type="text" id="openai_api_key" name="openai_api_key"
                                   placeholder="sk-proj-..." maxlength="255" autocomplete="off">
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn btn-primary">Create Account</button>
            </form>

            <div class="auth-links">
                <p>Already have an account? <a href="login.php">Log in</a></p>
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
    function previewImage(input, previewId) {
        var img = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) { img.src = e.target.result; img.style.display = 'block'; };
            reader.readAsDataURL(input.files[0]);
        } else {
            img.src = ''; img.style.display = 'none';
        }
    }
    </script>
</body>
</html>
