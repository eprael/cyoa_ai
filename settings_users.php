<?php
/**
 * Users (admin) — user management: list, create, edit, delete, plus a stats strip
 * and a client-side search/filter bar.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

if (!isset($_SESSION['userID']) || empty($_SESSION['isAdmin'])) {
    header('Location: ' . (isset($_SESSION['userID']) ? 'index.php' : 'login.php'));
    exit;
}

$currentUserID = (int)$_SESSION['userID'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'admin_create_user') {
        $fn = trim($_POST['firstName'] ?? ''); $ln = trim($_POST['lastName'] ?? '');
        $em = trim($_POST['email'] ?? '');     $pw = $_POST['password'] ?? '';
        $admin = isset($_POST['isAdmin']) ? 1 : 0; $img = '';
        if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === UPLOAD_ERR_OK) {
            $img = upload_profile_image($_FILES['profileImage']);
        }
        if (empty($fn) || empty($ln) || empty($em) || empty($pw)) {
            $error = 'All fields except profile image are required.';
        } elseif (strlen($pw) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $result = admin_create_user($fn, $ln, $em, $pw, $admin, $img);
            if ($result === 'email_taken') $error = 'A user with that email already exists.';
            elseif ($result) $success = 'User created successfully.';
            else $error = 'Failed to create user.';
        }
    }

    if ($action === 'admin_update_user') {
        $uid = (int)($_POST['userID'] ?? 0);
        $fn = trim($_POST['firstName'] ?? ''); $ln = trim($_POST['lastName'] ?? '');
        $em = trim($_POST['email'] ?? '');     $pw = $_POST['password'] ?? '';
        $admin = isset($_POST['isAdmin']) ? 1 : 0; $img = null;
        if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === UPLOAD_ERR_OK) {
            $img = upload_profile_image($_FILES['profileImage']);
        }
        if (empty($fn) || empty($ln) || empty($em)) {
            $error = 'First name, last name, and email are required.';
        } else {
            $result = admin_update_user($uid, $fn, $ln, $em, $admin, $img, ($pw !== '' ? $pw : null));
            if ($result === 'email_taken') {
                $error = 'Another user already has that email.';
            } elseif ($result) {
                if ($uid === $currentUserID) {
                    $_SESSION['firstName'] = $fn; $_SESSION['lastName'] = $ln;
                    $_SESSION['email'] = $em;     $_SESSION['isAdmin'] = $admin;
                    if ($img) $_SESSION['profileImage'] = $img;
                }
                $success = 'User updated successfully.';
            } else {
                $error = 'Failed to update user.';
            }
        }
    }

    if ($action === 'admin_delete_user') {
        $uid = (int)($_POST['userID'] ?? 0);
        if ($uid === $currentUserID) $error = 'You cannot delete your own account.';
        elseif ($uid > 0) { delete_user($uid); $success = 'User deleted.'; }
    }
}

$allUsers = get_all_users();

// Stats
$totalUsers = count($allUsers);
$adminCount = 0; $byokCount = 0;
foreach ($allUsers as $u) {
    if (!empty($u['isAdmin'])) $adminCount++;
    if (!empty($u['claude_api_key']) || !empty($u['openai_api_key'])) $byokCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/account.css">
    <style>
        .users-toolbar { display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin:0.75rem 0 1.25rem; }
        .users-stats { display:flex; gap:1.5rem; font-size:0.9rem; color:var(--text-light); }
        .users-stats strong { color:var(--text); }
        .users-search { display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; }
        /* The toolbar search sits outside a .form-group, so forms.css doesn't reach
           it — style it here to match the app's inputs. */
        .users-search input[type=search] {
            min-width:200px;
            padding:0.5rem 0.75rem;
            border:1px solid var(--border, #e2e8f0);
            border-radius:var(--radius, 8px);
            background:var(--input-bg, #fff);
            color:var(--text);
            font-size:0.9rem;
        }
        .users-search input[type=search]:focus { outline:none; border-color:var(--primary, #3d72ef); }
        .users-search .check { display:inline-flex; align-items:center; gap:0.3rem; font-size:0.85rem; white-space:nowrap; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <div class="account-section">
            <div class="admin-header">
                <h2>Users</h2>
                <button class="btn btn-primary btn-sm" onclick="openModal('create')">+ Add User</button>
            </div>

            <div class="users-toolbar">
                <div class="users-stats">
                    <span>Total Users: <strong><?php echo $totalUsers; ?></strong></span>
                    <span>Admins: <strong><?php echo $adminCount; ?></strong></span>
                    <span>BYOK: <strong><?php echo $byokCount; ?></strong></span>
                </div>
                <div class="users-search">
                    <input type="search" id="user-search" class="form-control" placeholder="Search users…"
                           oninput="filterUsers()" aria-label="Search users">
                    <label class="check"><input type="checkbox" id="filter-admin" onchange="filterUsers()"> is admin</label>
                    <label class="check"><input type="checkbox" id="filter-keys" onchange="filterUsers()"> has own keys</label>
                </div>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:60px;"></th>
                            <th>Email</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Admin</th>
                            <th title="Has their own Claude API key">Claude Key</th>
                            <th title="Has their own OpenAI API key">OpenAI Key</th>
                            <th style="width:130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="users-tbody">
                    <?php foreach ($allUsers as $u): ?>
                        <?php
                            $hasKeys = (!empty($u['claude_api_key']) || !empty($u['openai_api_key'])) ? 1 : 0;
                            $search  = strtolower($u['email'] . ' ' . $u['firstName'] . ' ' . $u['lastName']);
                        ?>
                        <tr data-search="<?php echo htmlspecialchars($search); ?>"
                            data-is-admin="<?php echo !empty($u['isAdmin']) ? 1 : 0; ?>"
                            data-has-keys="<?php echo $hasKeys; ?>">
                            <td>
                                <?php if (!empty($u['profileImage'])): ?>
                                    <img src="images/profiles/<?php echo htmlspecialchars($u['profileImage']); ?>" class="table-avatar">
                                <?php else: ?>
                                    <span class="table-avatar-fallback">&#128100;</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><?php echo htmlspecialchars($u['firstName']); ?></td>
                            <td><?php echo htmlspecialchars($u['lastName']); ?></td>
                            <td><?php echo !empty($u['isAdmin']) ? '<span class="badge badge-admin">Admin</span>' : '—'; ?></td>
                            <td style="text-align:center;"><?php echo !empty($u['claude_api_key']) ? '&#10003;' : '—'; ?></td>
                            <td style="text-align:center;"><?php echo !empty($u['openai_api_key']) ? '&#10003;' : '—'; ?></td>
                            <td class="action-cell">
                                <?php $modalUser = [
                                    'userID'       => (int)$u['userID'],
                                    'firstName'    => $u['firstName'],
                                    'lastName'     => $u['lastName'],
                                    'email'        => $u['email'],
                                    'isAdmin'      => (int)!empty($u['isAdmin']),
                                    'profileImage' => $u['profileImage'] ?? '',
                                ]; ?>
                                <button class="btn btn-secondary btn-xs"
                                        onclick='openModal("edit", <?php echo json_encode($modalUser, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>Edit</button>
                                <?php if ((int)$u['userID'] !== $currentUserID): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="admin_delete_user">
                                    <input type="hidden" name="userID" value="<?php echo (int)$u['userID']; ?>">
                                    <button type="button" class="btn btn-danger btn-xs"
                                            onclick="Modal.confirmDanger({heading:'Delete User?', message:'This user will be permanently deleted.', confirmLabel:'Delete', onConfirm: () => this.closest('form').submit()})">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="no-users-match" style="display:none; color:var(--text-light); padding:1rem 0;">No users match your filters.</p>
            </div>
        </div>

<!-- ── User Modal ── -->
<div id="user-modal" class="modal-overlay" onclick="if(event.target===this)closeModal()">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title">Add User</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="modal-form">
            <input type="hidden" name="action" id="modal-action" value="admin_create_user">
            <input type="hidden" name="userID" id="modal-userID" value="">

            <div class="form-group">
                <label for="m_firstName">First Name</label>
                <input type="text" id="m_firstName" name="firstName" class="form-control" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="m_lastName">Last Name</label>
                <input type="text" id="m_lastName" name="lastName" class="form-control" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="m_email">Email</label>
                <input type="email" id="m_email" name="email" class="form-control" required maxlength="256">
            </div>
            <div class="form-group">
                <label for="m_password" id="m_password_label">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="m_password" name="password" class="form-control" minlength="6">
                    <button type="button" class="password-toggle" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
            </div>
            <div class="form-group">
                <label for="m_profileImage">Profile Image</label>
                <div style="display:flex; align-items:center; gap:1rem;">
                    <img id="modal-img-preview" src="" alt="Preview"
                         style="width:60px; height:60px; object-fit:cover; border-radius:50%; border:1px solid var(--border); display:none;">
                    <input type="file" id="m_profileImage" name="profileImage" class="form-control" accept="image/*" onchange="previewImage(this,'modal-img-preview')">
                </div>
            </div>
            <div class="form-group checkbox-group">
                <input type="checkbox" id="m_isAdmin" name="isAdmin" value="1">
                <label for="m_isAdmin">Administrator</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="modal-submit-btn">Create User</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>

    <script>
    function openModal(mode, user) {
        var modal = document.getElementById('user-modal');
        var title = document.getElementById('modal-title');
        var action = document.getElementById('modal-action');
        var submitBtn = document.getElementById('modal-submit-btn');
        var pwField = document.getElementById('m_password');
        var pwLabel = document.getElementById('m_password_label');
        var preview = document.getElementById('modal-img-preview');

        document.getElementById('modal-form').reset();
        preview.style.display = 'none';

        if (mode === 'edit' && user) {
            title.textContent = 'Edit User';
            action.value = 'admin_update_user';
            submitBtn.textContent = 'Save Changes';
            document.getElementById('modal-userID').value = user.userID;
            document.getElementById('m_firstName').value = user.firstName;
            document.getElementById('m_lastName').value = user.lastName;
            document.getElementById('m_email').value = user.email;
            document.getElementById('m_isAdmin').checked = user.isAdmin == 1;
            pwField.required = false;
            pwLabel.textContent = 'Password (leave blank to keep current)';
            if (user.profileImage) {
                preview.src = 'images/profiles/' + user.profileImage;
                preview.style.display = 'block';
            }
        } else {
            title.textContent = 'Add User';
            action.value = 'admin_create_user';
            submitBtn.textContent = 'Create User';
            document.getElementById('modal-userID').value = '';
            pwField.required = true;
            pwLabel.textContent = 'Password';
        }
        // Use the global modal's show mechanism (.modal-open) — the shared
        // .modal-overlay rule in modal.css keeps it opacity:0/visibility:hidden
        // until this class is present, so toggling display alone left it invisible.
        modal.classList.add('modal-open');
    }
    function closeModal() { document.getElementById('user-modal').classList.remove('modal-open'); }

    // Search / filter
    function filterUsers() {
        var q = document.getElementById('user-search').value.trim().toLowerCase();
        var onlyAdmin = document.getElementById('filter-admin').checked;
        var onlyKeys  = document.getElementById('filter-keys').checked;
        var rows = document.querySelectorAll('#users-tbody tr');
        var shown = 0;
        rows.forEach(function (row) {
            var match = (!q || row.dataset.search.indexOf(q) !== -1)
                && (!onlyAdmin || row.dataset.isAdmin === '1')
                && (!onlyKeys  || row.dataset.hasKeys === '1');
            row.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        document.getElementById('no-users-match').style.display = shown === 0 ? 'block' : 'none';
    }

    // Image preview
    function previewImage(input, previewId) {
        var preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Password toggle
    document.querySelectorAll('.password-toggle').forEach(function(btn){
        btn.addEventListener('click',function(){
            var input=this.previousElementSibling;
            var show=input.type==='password';
            input.type=show?'text':'password';
            this.setAttribute('aria-label',show?'Hide password':'Show password');
        });
    });
    </script>
</body>
</html>
