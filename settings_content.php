<?php
/**
 * Content Settings (admin) — Story Genres, Image Styles, Image Modifiers (chip
 * editors that persist immediately via api_content.php) and Content Restrictions
 * (formerly Guardrails). Adds go through a modal; removes are immediate. One panel
 * per section; chips are colour-coded per section.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

if (!isset($_SESSION['userID']) || empty($_SESSION['isAdmin'])) {
    header('Location: ' . (isset($_SESSION['userID']) ? 'index.php' : 'login.php'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── API keys + AI generation models (moved here from Site Settings) ──
    if ($action === 'save_ai_settings') {
        foreach (['anthropic_model', 'openai_image_model', 'openai_image_quality', 'openai_image_format'] as $key) {
            if (isset($_POST[$key])) db_set_setting($key, trim($_POST[$key]));
        }
        // Blank key field = keep the existing key (we never round-trip the secret).
        // Ticking the matching "clear" box wipes the stored key instead.
        foreach (['anthropic_api_key', 'openai_api_key'] as $key) {
            if (!empty($_POST['clear_' . $key])) {
                db_set_setting($key, '');
            } else {
                $val = trim($_POST[$key] ?? '');
                if ($val !== '') db_set_setting($key, $val);
            }
        }
        global $SETTINGS;
        $SETTINGS = db_get_all_settings();
        $success = 'AI settings saved.';
    }

    // Content Restrictions textarea (the enable checkbox persists via AJAX).
    if ($action === 'save_guardrails') {
        if (isset($_POST['guardrails_text'])) {
            $raw   = str_replace("\r\n", "\n", (string)$_POST['guardrails_text']);
            $lines = array_filter(array_map('trim', explode("\n", $raw)), fn($v) => $v !== '');
            db_set_setting('guardrails_text', implode("\n", $lines));
        }
        global $SETTINGS;
        $SETTINGS = db_get_all_settings();
        $success = 'Content restrictions updated.';
    }
}

$storyGenresAdmin = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];
$imageStylesAdmin = json_decode(app_setting('image_styles') ?? '{}', true) ?: [];
$imageMoodsAdmin  = json_decode(app_setting('image_moods')  ?? '[]', true) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Settings - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/account.css">
    <style>
        .chip-list { display:flex; flex-wrap:wrap; gap:0.4rem; margin:0.5rem 0; }
        .admin-chip { display:inline-flex; align-items:center; gap:0.3rem; background:#e2e8f0; color:#1f2937;
                      border-radius:999px; padding:0.2rem 0.7rem; font-size:0.85rem; border:1px solid #cbd5e0; }
        /* Per-section pastel colours */
        .admin-chip-genre { background:#dbeafe; color:#1e3a8a; border-color:#bfdbfe; } /* pastel blue */
        .admin-chip-style { background:#dcfce7; color:#166534; border-color:#bbf7d0; } /* pastel green */
        .admin-chip-mood  { background:#ffedd5; color:#9a3412; border-color:#fed7aa; } /* pastel orange */
        .admin-chip-x { background:none; border:none; color:inherit; opacity:0.55; cursor:pointer; font-size:1rem; line-height:1; padding:0; }
        .admin-chip-x:hover { opacity:1; }
        /* Image-style categories live inside the single Image Styles panel (no per-category box) */
        .style-cat-group { margin-bottom:1.1rem; }
        .style-cat-group:last-child { margin-bottom:0; }
        .style-cat-group > strong { display:block; margin-bottom:0.15rem; }
        /* Modal form fields */
        .cs-modal-field { margin-bottom:0.75rem; }
        .cs-modal-field label { display:block; font-size:0.85rem; margin-bottom:0.25rem; }
        .cs-modal-instructions { color:var(--text-light); font-size:0.9rem; margin:0 0 1rem; }
        /* Each section is an outlined block within the single page panel */
        .settings-block { background:var(--bg); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:1rem 1.25rem; margin-bottom:1.25rem; }
        .settings-block:last-child { margin-bottom:0; }
        .settings-block > h2 { margin-top:0; }
        .settings-block .admin-header { margin-bottom:0.5rem; }
        .settings-block .admin-header h2 { margin:0; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <div class="account-section">
        <h1 style="margin:0 0 0.4rem; font-size:1.5rem;">AI Settings</h1>
        <p style="color:var(--text-light); font-size:0.9rem; margin:0 0 1.25rem;">Provider API keys, AI generation models, and the content lists (genres, image styles, modifiers, restrictions) that power AI features. Keys and models are saved with the Save button below; list changes apply immediately.</p>

        <!-- API Keys -->
        <div class="settings-block">
            <h2>API Keys</h2>
            <form method="post">
                <input type="hidden" name="action" value="save_ai_settings">
                <div class="form-group">
                    <label for="anthropic_api_key">Anthropic API Key</label>
                    <input type="password" id="anthropic_api_key" name="anthropic_api_key" class="form-control"
                           placeholder="<?php echo htmlspecialchars(api_key_placeholder(app_setting('anthropic_api_key'), 'sk-ant-...')); ?>" autocomplete="off">
                    <?php if (!empty(app_setting('anthropic_api_key'))): ?>
                    <label style="display:flex; align-items:center; gap:0.4rem; margin-top:0.35rem; font-size:0.85rem; color:var(--text-light,#6b7280);">
                        <input type="checkbox" name="clear_anthropic_api_key" value="1"
                               onchange="document.getElementById('anthropic_api_key').disabled = this.checked;">
                        Clear the stored key (disables Claude site-wide unless users bring their own)
                    </label>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="openai_api_key">OpenAI API Key</label>
                    <input type="password" id="openai_api_key" name="openai_api_key" class="form-control"
                           placeholder="<?php echo htmlspecialchars(api_key_placeholder(app_setting('openai_api_key'), 'sk-proj-...')); ?>" autocomplete="off">
                    <?php if (!empty(app_setting('openai_api_key'))): ?>
                    <label style="display:flex; align-items:center; gap:0.4rem; margin-top:0.35rem; font-size:0.85rem; color:var(--text-light,#6b7280);">
                        <input type="checkbox" name="clear_openai_api_key" value="1"
                               onchange="document.getElementById('openai_api_key').disabled = this.checked;">
                        Clear the stored key (disables image generation site-wide unless users bring their own)
                    </label>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;">Update</button>
            </form>
        </div>

        <!-- AI Generation -->
        <div class="settings-block">
            <h2>AI Generation</h2>
            <form method="post">
                <input type="hidden" name="action" value="save_ai_settings">
                <div class="form-group">
                    <label for="anthropic_model">Claude Model</label>
                    <select id="anthropic_model" name="anthropic_model" class="form-control">
                        <?php foreach (['claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-7', 'claude-opus-4-8'] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo app_setting('anthropic_model') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="openai_image_model">Image Model</label>
                    <select id="openai_image_model" name="openai_image_model" class="form-control">
                        <?php foreach (['gpt-image-1-mini', 'gpt-image-1', 'gpt-image-1.5', 'gpt-image-2'] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo app_setting('openai_image_model') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="openai_image_quality">Default Image Quality</label>
                    <select id="openai_image_quality" name="openai_image_quality" class="form-control">
                        <?php foreach (['low', 'medium', 'high'] as $q): ?>
                            <option value="<?php echo $q; ?>" <?php echo app_setting('openai_image_quality') === $q ? 'selected' : ''; ?>><?php echo ucfirst($q); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="openai_image_format">Image Output Format</label>
                    <select id="openai_image_format" name="openai_image_format" class="form-control">
                        <?php foreach (['jpeg', 'png', 'webp'] as $f): ?>
                            <option value="<?php echo $f; ?>" <?php echo app_setting('openai_image_format') === $f ? 'selected' : ''; ?>><?php echo strtoupper($f); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;">Update</button>
            </form>
        </div>

        <!-- Story Genres -->
        <div class="settings-block">
            <div class="admin-header">
                <h2>Story Genres</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="ContentSettings.openAdd('genres')">+ Add Genre</button>
            </div>
            <div class="chip-list" id="genres-chips"></div>
        </div>

        <!-- Image Styles -->
        <div class="settings-block">
            <div class="admin-header">
                <h2>AI Image Styles</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="ContentSettings.openAddStyle()">+ Add Style</button>
            </div>
            <div id="styles-accordion"></div>
        </div>

        <!-- Image Modifiers -->
        <div class="settings-block">
            <div class="admin-header">
                <h2>AI Image Modifiers</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="ContentSettings.openAdd('moods')">+ Add Modifier</button>
            </div>
            <div class="chip-list" id="moods-chips"></div>
        </div>

        <!-- Content Restrictions (formerly Guardrails) -->
        <div class="settings-block">
            <h2>AI Content Restrictions</h2>
            <p style="color:var(--text-light); font-size:0.9rem; margin:0 0 1.25rem;">Injected into every AI generation. The AI flags any response that would breach these topics (failing the job), and image prompts are prefixed with a “Do not depict” instruction. Disable to turn off all injection.</p>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="guardrails_enabled" <?php echo (bool)(int) app_setting('guardrails_enabled') ? 'checked' : ''; ?>>
                    Enable Content Restrictions
                </label>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="save_guardrails">
                <div class="form-group">
                    <label for="guardrails_text">Restricted topics (one per line)</label>
                    <textarea id="guardrails_text" name="guardrails_text" class="form-control" rows="6"><?php echo htmlspecialchars(app_setting('guardrails_text') ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;">Update</button>
            </form>
        </div>
        </div>

    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>

    <script>
    var ContentSettings = (function () {
        var flat = {
            genres: <?php echo json_encode(array_values($storyGenresAdmin), JSON_UNESCAPED_UNICODE); ?>,
            moods:  <?php echo json_encode(array_values($imageMoodsAdmin),  JSON_UNESCAPED_UNICODE); ?>
        };
        var styles = <?php echo json_encode((object)$imageStylesAdmin, JSON_UNESCAPED_UNICODE); ?>;
        var colorOf = { genres: 'admin-chip-genre', moods: 'admin-chip-mood' };
        var flatLabel = { genres: 'Genre', moods: 'Image Modifier' };

        function save(field, value) {
            var fd = new FormData();
            fd.append('field', field);
            fd.append('value', JSON.stringify(value));
            fetch('api_content.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (!d.success) Modal.alert(d.error || 'Save failed.'); })
                .catch(function () { Modal.alert('Save failed — check your connection.'); });
        }

        function makeChip(label, onRemove, colorClass) {
            var chip = document.createElement('span');
            chip.className = 'admin-chip ' + (colorClass || '');
            chip.appendChild(document.createTextNode(label));
            var x = document.createElement('button');
            x.type = 'button'; x.className = 'admin-chip-x'; x.textContent = '×';
            x.onclick = onRemove;
            chip.appendChild(x);
            return chip;
        }

        function renderFlat(key) {
            var wrap = document.getElementById(key + '-chips');
            wrap.innerHTML = '';
            flat[key].forEach(function (val, i) {
                wrap.appendChild(makeChip(val, function () {
                    flat[key].splice(i, 1); renderFlat(key); save(key, flat[key]);
                }, colorOf[key]));
            });
        }

        function renderStyles() {
            var acc = document.getElementById('styles-accordion');
            acc.innerHTML = '';
            Object.keys(styles).forEach(function (cat) {
                var group = document.createElement('div'); group.className = 'style-cat-group';
                var label = document.createElement('strong'); label.textContent = cat;
                group.appendChild(label);
                var list = document.createElement('div'); list.className = 'chip-list';
                (styles[cat] || []).forEach(function (sub, i) {
                    list.appendChild(makeChip(sub, function () {
                        styles[cat].splice(i, 1); renderStyles(); save('styles', styles);
                    }, 'admin-chip-style'));
                });
                group.appendChild(list);
                acc.appendChild(group);
            });
        }

        // ── Add via modal: flat lists (genres, moods) ──
        function openAdd(key) {
            var label = flatLabel[key];
            var wrap = document.createElement('div');
            var p = document.createElement('p');
            p.className = 'cs-modal-instructions';
            p.textContent = 'Add a new ' + label.toLowerCase() + '. It becomes available for new stories immediately.';
            var field = document.createElement('div'); field.className = 'cs-modal-field';
            var lab = document.createElement('label'); lab.textContent = label;
            var inp = document.createElement('input'); inp.type = 'text'; inp.className = 'form-control'; inp.placeholder = label + ' name…';
            inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } };
            field.appendChild(lab); field.appendChild(inp);
            wrap.appendChild(p); wrap.appendChild(field);

            function doAdd() {
                var v = inp.value.trim();
                if (v && flat[key].indexOf(v) === -1) { flat[key].push(v); renderFlat(key); save(key, flat[key]); }
                Modal.close();
            }
            Modal.open({
                title: 'Add ' + label,
                body: wrap,
                buttons: [
                    { label: 'Cancel', className: 'btn-secondary', action: Modal.close },
                    { label: 'Add',    className: 'btn-primary',   action: doAdd }
                ]
            });
            setTimeout(function () { inp.focus(); }, 60);
        }

        // ── Add via modal: image style (category dropdown + text) ──
        function openAddStyle() {
            var cats = Object.keys(styles);
            if (cats.length === 0) { Modal.alert('There are no image-style categories to add to.'); return; }
            var wrap = document.createElement('div');
            var p = document.createElement('p');
            p.className = 'cs-modal-instructions';
            p.textContent = 'Choose a category and enter a new image style to add to it.';

            var f1 = document.createElement('div'); f1.className = 'cs-modal-field';
            var l1 = document.createElement('label'); l1.textContent = 'Category';
            var sel = document.createElement('select'); sel.className = 'form-control';
            cats.forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = c; sel.appendChild(o); });
            f1.appendChild(l1); f1.appendChild(sel);

            var f2 = document.createElement('div'); f2.className = 'cs-modal-field';
            var l2 = document.createElement('label'); l2.textContent = 'Image style';
            var inp = document.createElement('input'); inp.type = 'text'; inp.className = 'form-control'; inp.placeholder = 'Image style name…';
            inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } };
            f2.appendChild(l2); f2.appendChild(inp);

            wrap.appendChild(p); wrap.appendChild(f1); wrap.appendChild(f2);

            function doAdd() {
                var cat = sel.value, v = inp.value.trim();
                if (cat && v) {
                    if (!styles[cat]) styles[cat] = [];
                    if (styles[cat].indexOf(v) === -1) { styles[cat].push(v); renderStyles(); save('styles', styles); }
                }
                Modal.close();
            }
            Modal.open({
                title: 'Add Image Style',
                body: wrap,
                buttons: [
                    { label: 'Cancel', className: 'btn-secondary', action: Modal.close },
                    { label: 'Add',    className: 'btn-primary',   action: doAdd }
                ]
            });
            setTimeout(function () { inp.focus(); }, 60);
        }

        renderFlat('genres');
        renderFlat('moods');
        renderStyles();

        return { openAdd: openAdd, openAddStyle: openAddStyle };
    })();

    // Content Restrictions enable toggle persists immediately.
    (function () {
        var cb = document.getElementById('guardrails_enabled');
        if (!cb) return;
        cb.addEventListener('change', function () {
            var fd = new FormData();
            fd.append('field', 'guardrails_enabled');
            fd.append('value', cb.checked ? '1' : '0');
            fetch('api_content.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (!d.success) { Modal.alert(d.error || 'Save failed.'); cb.checked = !cb.checked; } })
                .catch(function () { Modal.alert('Save failed — check your connection.'); cb.checked = !cb.checked; });
        });
    })();
    </script>
</body>
</html>
