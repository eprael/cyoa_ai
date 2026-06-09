<?php
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header('Location: login.php');
    exit;
}

$currentUserID = (int)$_SESSION['userID'];
$isAdmin = !empty($_SESSION['isAdmin']);
$error   = '';
$success = '';

// ==========================================
// Handle POST actions (own account only)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update own password ──
    if ($action === 'update_password') {
        $newPw      = $_POST['password'] ?? '';
        $confirmPw  = $_POST['confirmPassword'] ?? '';
        if (empty($newPw)) {
            $error = 'Please enter a new password.';
        } elseif (strlen($newPw) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'Passwords do not match.';
        } else {
            update_user_password($currentUserID, $newPw);
            $success = 'Password updated successfully.';
        }
    }

    // ── Update own profile image ──
    if ($action === 'update_profile_image') {
        if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === UPLOAD_ERR_OK) {
            $filename = upload_profile_image($_FILES['profileImage']);
            if ($filename) {
                update_user_profile_image($currentUserID, $filename);
                $_SESSION['profileImage'] = $filename;
                $success = 'Profile image updated.';
            } else {
                $error = 'Invalid image file.';
            }
        } else {
            $error = 'Please select an image to upload.';
        }
    }

    // ── Update own API keys (BYOK) ──
    // Per key: a "clear" box wipes it; a new value replaces it; blank keeps the
    // current one (so changing one key never silently wipes the other).
    if ($action === 'update_api_keys') {
        $existing = get_user_by_id($currentUserID);

        if (!empty($_POST['clear_claude_api_key'])) {
            $claudeKey = '';
        } else {
            $in        = trim($_POST['claude_api_key'] ?? '');
            $claudeKey = $in !== '' ? $in : ($existing['claude_api_key'] ?? '');
        }

        if (!empty($_POST['clear_openai_api_key'])) {
            $openaiKey = '';
        } else {
            $in        = trim($_POST['openai_api_key'] ?? '');
            $openaiKey = $in !== '' ? $in : ($existing['openai_api_key'] ?? '');
        }

        update_user_api_keys($currentUserID, $claudeKey, $openaiKey);
        $success = 'API keys updated.';
    }
}

// ── Fetch data ──
$currentUser = get_user_by_id($currentUserID);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/account.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

<!-- ================================================================
     MY PROFILE  (all users)
     ================================================================ -->
        <div class="account-section">
            <h2>My Profile</h2>
            <div class="profile-card">
                <div class="profile-avatar-large">
                    <?php if (!empty($currentUser['profileImage'])): ?>
                        <img src="images/profiles/<?php echo htmlspecialchars($currentUser['profileImage']); ?>" alt="Profile">
                    <?php else: ?>
                        <span class="avatar-fallback-large">&#128100;</span>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <div class="profile-row">
                        <span class="profile-label">Name</span>
                        <span class="profile-value"><?php echo htmlspecialchars($currentUser['firstName'] . ' ' . $currentUser['lastName']); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label">Email</span>
                        <span class="profile-value"><?php echo htmlspecialchars($currentUser['email']); ?></span>
                    </div>
                    <div class="profile-row">
                        <a href="#" id="show-image-form-link" onclick="document.getElementById('profile-image-form').style.display='block'; this.style.display='none'; return false;" style="font-size:0.85rem;">Update profile image</a>
                    </div>
                </div>
            </div>

            <!-- Update Profile Image -->
            <div class="account-form-block" id="profile-image-form" style="display:none;">
                <h3>Update Profile Image</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile_image">
                    <div class="form-group" style="display:flex; align-items:center; gap:1rem;">
                        <img id="profile-img-preview"
                             src="<?php echo !empty($currentUser['profileImage']) ? 'images/profiles/' . htmlspecialchars($currentUser['profileImage']) : ''; ?>"
                             alt="Preview"
                             style="width:80px; height:80px; object-fit:cover; border-radius:50%; border:1px solid var(--border); <?php echo empty($currentUser['profileImage']) ? 'display:none;' : ''; ?>">
                        <input type="file" name="profileImage" class="form-control" accept="image/*" onchange="previewImage(this,'profile-img-preview')">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;">Upload Image</button>
                </form>
            </div>

            <!-- Update Password -->
            <div class="account-form-block">
                <h3>Change Password</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_password">
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-control" required minlength="6">
                            <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirmPassword" name="confirmPassword" class="form-control" required minlength="6">
                            <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
                </form>
            </div>
        </div>

<!-- ================================================================
     BYOK — Bring Your Own API Keys  (all users)
     ================================================================ -->
        <div class="account-section" style="margin-top:2rem;">
            <h2>BYOK &mdash; Bring Your Own API Keys</h2>
            <p style="color:var(--text-light); font-size:0.9rem; margin-bottom:1.25rem;">
                Bring your own Claude or OpenAI key to use AI features on your account.
                Enter a key to set or replace it, or leave a field blank to keep your current key.
                Tick &ldquo;Clear&rdquo; to remove a key and fall back to the site-wide default.
            </p>
            <div class="account-form-block">
                <form method="POST">
                    <input type="hidden" name="action" value="update_api_keys">
                    <div class="form-group">
                        <label for="claude_api_key">
                            Claude API Key
                            <?php if (!empty($currentUser['claude_api_key'])): ?>
                                <span style="font-weight:normal; color:var(--success,#2a9d5c); font-size:0.82rem;">&#10003; Key set</span>
                            <?php else: ?>
                                <span style="font-weight:normal; color:var(--text-light); font-size:0.82rem;">Not set — using site default</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" id="claude_api_key" name="claude_api_key" class="form-control"
                               placeholder="<?php echo htmlspecialchars(api_key_placeholder($currentUser['claude_api_key'] ?? null, 'sk-ant-api03-...')); ?>"
                               maxlength="255" autocomplete="off">
                        <?php if (!empty($currentUser['claude_api_key'])): ?>
                        <label style="display:flex; align-items:center; gap:0.4rem; margin-top:0.35rem; font-size:0.85rem; color:var(--text-light);">
                            <input type="checkbox" name="clear_claude_api_key" value="1"
                                   onchange="document.getElementById('claude_api_key').disabled = this.checked;">
                            Clear my Claude key (use the site default)
                        </label>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="openai_api_key">
                            OpenAI API Key
                            <?php if (!empty($currentUser['openai_api_key'])): ?>
                                <span style="font-weight:normal; color:var(--success,#2a9d5c); font-size:0.82rem;">&#10003; Key set</span>
                            <?php else: ?>
                                <span style="font-weight:normal; color:var(--text-light); font-size:0.82rem;">Not set — using site default</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" id="openai_api_key" name="openai_api_key" class="form-control"
                               placeholder="<?php echo htmlspecialchars(api_key_placeholder($currentUser['openai_api_key'] ?? null, 'sk-proj-...')); ?>"
                               maxlength="255" autocomplete="off">
                        <?php if (!empty($currentUser['openai_api_key'])): ?>
                        <label style="display:flex; align-items:center; gap:0.4rem; margin-top:0.35rem; font-size:0.85rem; color:var(--text-light);">
                            <input type="checkbox" name="clear_openai_api_key" value="1"
                                   onchange="document.getElementById('openai_api_key').disabled = this.checked;">
                            Clear my OpenAI key (use the site default)
                        </label>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Keys</button>
                </form>
            </div>
        </div>

    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>

    <script>
    // Image preview helper
    function previewImage(input, previewId) {
        var preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Password toggle
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
