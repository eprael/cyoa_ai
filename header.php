<?php
/* Determine which nav link is "active" */
$_navPage   = basename($_SERVER['PHP_SELF'], '.php');
$_navFilter = $_GET['filter'] ?? '';
$_searchVal = htmlspecialchars($_GET['q'] ?? '');
$_appTitle  = function_exists('app_setting')
    ? htmlspecialchars(app_setting('app_title') ?? 'Choose Your Own Adventure!')
    : 'Choose Your Own Adventure!';
?>
<link rel="stylesheet" href="styles/modal.css">
<script>
/* Apply saved theme before first paint to prevent flash */
(function () {
    var t = localStorage.getItem('cyoa-theme');
    if (t) document.documentElement.setAttribute('data-theme', t);

    /* Inject favicons / app icons into <head> (header.php renders in <body>,
       so do this via JS to guarantee the links land in the document head). */
    var icons = [
        { rel: 'icon',            type: 'image/png', sizes: '32x32',   href: 'images/app/favicon-32.png' },
        { rel: 'icon',            type: 'image/png', sizes: '16x16',   href: 'images/app/favicon-16.png' },
        { rel: 'icon',            type: 'image/png', sizes: '192x192', href: 'images/app/icon-192.png' },
        { rel: 'icon',            type: 'image/png', sizes: '512x512', href: 'images/app/icon-512.png' },
        { rel: 'apple-touch-icon',                   sizes: '180x180', href: 'images/app/apple-touch-icon-180.png' },
        { rel: 'shortcut icon',                                        href: 'images/app/favicon.ico' },
        { rel: 'manifest',                                             href: 'site.webmanifest' }
    ];
    icons.forEach(function (attrs) {
        var l = document.createElement('link');
        for (var k in attrs) l.setAttribute(k, attrs[k]);
        document.head.appendChild(l);
    });
})();
</script>

<nav class="navbar">

    <a href="index.php" class="nav-brand">
        <img src="images/app/logo_square.png" alt="" class="nav-logo">
        <span class="nav-brand-text"><?= $_appTitle ?></span>
    </a>

    <div class="nav-center">
        <form method="GET" action="index.php" class="nav-search-form" role="search">
            <input type="search" name="q" value="<?= $_searchVal ?>"
                   placeholder="Search stories…" class="nav-search-input"
                   aria-label="Search stories">
            <button type="submit" class="nav-search-btn" aria-label="Search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </button>
        </form>
    </div>

    <div class="nav-actions">
        <?php if (isset($_SESSION['userID'])): ?>

        <!-- Job queue icon -->
        <a href="job_queue.php" class="nav-icon-btn" title="Job Queue" aria-label="Job Queue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <!-- U-shaped container -->
                <path d="M2 5v11.5a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V5"/>
                <!-- stack of sheets inside -->
                <line x1="8" y1="5"  x2="16" y2="5"/>
                <line x1="8" y1="10" x2="16" y2="10"/>
                <line x1="8" y1="15" x2="16" y2="15"/>
            </svg>
            <span id="job-queue-badge" class="nav-badge" hidden></span>
        </a>

        <?php if (!empty($_SESSION['isAdmin'])): ?>
        <!-- Admin settings cog — dropdown -->
        <div class="nav-dropdown">
            <button class="nav-icon-btn nav-dropdown-trigger" title="Settings" aria-label="Settings">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </button>
            <div class="nav-dropdown-menu nav-dropdown-right">
                <a href="settings_site.php"    <?= $_navPage === 'settings_site'    ? 'class="active"' : '' ?>>Site Settings</a>
                <a href="settings_content.php" <?= $_navPage === 'settings_content' ? 'class="active"' : '' ?>>AI Settings</a>
                <a href="settings_users.php"   <?= $_navPage === 'settings_users'   ? 'class="active"' : '' ?>>Users</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- User / theme dropdown -->
        <div class="nav-dropdown">
            <button class="nav-user-trigger nav-dropdown-trigger" aria-label="User menu">
                <span class="nav-avatar-icon">
                    <?php if (!empty($_SESSION['profileImage'])): ?>
                        <img src="images/profiles/<?= htmlspecialchars($_SESSION['profileImage']) ?>" alt="">
                    <?php else: ?>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    <?php endif; ?>
                </span>
                <span class="nav-username"><?= htmlspecialchars($_SESSION['firstName'] ?? '') ?></span>
            </button>
            <div class="nav-dropdown-menu nav-dropdown-right">
                <a href="index.php?filter=mine"
                   <?= ($_navPage === 'index' && $_navFilter === 'mine') ? 'class="active"' : '' ?>>My Stories</a>
                <a href="index.php?filter=likes"
                   <?= ($_navPage === 'index' && $_navFilter === 'likes') ? 'class="active"' : '' ?>>Favorites</a>
                <a href="account.php">Account</a>
                <a href="trash.php">View Trash</a>
                <div class="nav-dropdown-divider"></div>
                <div class="theme-swatches-label">Theme</div>
                <div class="theme-swatches">
                    <button class="swatch" data-theme="light"  title="Light"></button>
                    <button class="swatch" data-theme="dark"   title="Dark"></button>
                    <button class="swatch" data-theme="ocean"  title="Ocean"></button>
                    <button class="swatch" data-theme="forest" title="Forest"></button>
                    <button class="swatch" data-theme="ember"  title="Ember"></button>
                </div>
                <div class="nav-dropdown-divider"></div>
                <a href="logout.php" class="nav-dropdown-danger">Logout</a>
            </div>
        </div>

        <?php else: ?>
        <a href="login.php" class="btn btn-primary btn-sm">Login / Sign Up</a>
        <?php endif; ?>
    </div>

</nav>

<script>
(function () {
    /* ── Dropdowns ── */
    document.querySelectorAll('.nav-dropdown-trigger').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var menu = btn.closest('.nav-dropdown').querySelector('.nav-dropdown-menu');
            var isOpen = menu.classList.contains('open');
            /* close all */
            document.querySelectorAll('.nav-dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });
            if (!isOpen) menu.classList.add('open');
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.nav-dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });
    });

    /* ── Theme switcher ── */
    function setTheme(t) {
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('cyoa-theme', t);
        document.querySelectorAll('.swatch').forEach(function (s) {
            s.classList.toggle('active', s.dataset.theme === t);
        });
    }

    /* Mark current swatch on load */
    var current = localStorage.getItem('cyoa-theme') || 'light';
    document.querySelectorAll('.swatch').forEach(function (s) {
        s.classList.toggle('active', s.dataset.theme === current);
        s.addEventListener('click', function (e) {
            e.stopPropagation();
            setTheme(s.dataset.theme);
        });
    });

    <?php if (isset($_SESSION['userID'])): ?>
    /* ── Job queue badge polling ── */
    var badge = document.getElementById('job-queue-badge');
    if (badge) {
        function checkJobs() {
            fetch('api_jobs.php?action=unseen_count')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.count > 0) {
                        badge.textContent = data.count;
                        badge.hidden = false;
                    } else {
                        badge.hidden = true;
                    }
                })
                .catch(function () {});
        }
        checkJobs();
        setInterval(checkJobs, 5000);
    }
    <?php endif; ?>
})();
</script>
<script src="modal.js"></script>
