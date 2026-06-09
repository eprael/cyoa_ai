<?php
/**
 * Story Editor
 * Handles: story overview, story properties form, scene form
 * All views are rendered in a single file, controlled by GET parameters.
 * Bare URL (no params) redirects to index.php?filter=mine.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'fonts.php';   // Phase 41 — play-font allow-list
require_once 'theme.php';   // Phase 42 — theme engine helpers

// Require login
if (!isset($_SESSION['userID'])) {
    header('Location: login.php');
    exit;
}

$userID = (int)$_SESSION['userID'];
$userName = $_SESSION['firstName'] . ' ' . $_SESSION['lastName'];
$isAdmin = !empty($_SESSION['isAdmin']);

// AI key availability (user BYOK overrides the site key). Claude drives text
// (story/scene); OpenAI drives images. Used to gate the "Use AI" badges and the
// include-image options below.
$aiUser      = get_user_by_id($userID);
$aiHasClaude = ai_provider_available('claude', $aiUser);
$aiHasOpenai = ai_provider_available('openai', $aiUser);

// Flash messages
$message = '';
$error = '';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Available themes
// Theme dropdown labels — sourced from the theme presets (data/themes.json) so
// new presets appear automatically. key => display name.
$themes = array();
foreach (theme_presets() as $themeKey => $themeMeta) {
    $themes[$themeKey] = $themeMeta['name'];
}
// Present presets alphabetically by display name ("Custom" is appended separately,
// after this list, so it always stays last).
asort($themes, SORT_FLAG_CASE | SORT_STRING);

// Available layouts (desktop only — mobile always uses single column)
$layouts = array(
    'image_left'  => 'Image Left',
    'image_right' => 'Image Right',
    'image_top'   => 'Image Top'
);

/**
 * Resolve the URL for displaying a story/scene image.
 * Shadow drafts share the published story's image folder; new uploads go to the
 * draft's own folder. Checks the draft folder first, then falls back to the
 * published folder so existing images always display correctly.
 */
function editor_img_url($storyID, $publishedStoryID, $filename) {
    if (empty($filename)) return '';
    if (!empty($publishedStoryID)) {
        if (file_exists('images/stories/' . (int)$storyID . '/' . $filename)) {
            return 'images/stories/' . (int)$storyID . '/' . $filename;
        }
        return 'images/stories/' . (int)$publishedStoryID . '/' . $filename;
    }
    return 'images/stories/' . (int)$storyID . '/' . $filename;
}

// ================================================================
// HANDLE POST ACTIONS (Create/Update/Delete)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    switch ($postAction) {

        // ---- SAVE STORY (Create or Update) ----
        case 'save_story':
            $storyID = isset($_POST['storyID']) ? (int)$_POST['storyID'] : 0;
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $theme = trim($_POST['theme'] ?? 'egyptian');
            $layout = trim($_POST['layout'] ?? 'image_left');
            // Phase 42 — theme engine. Always store a sanitized theme_json built from
            // the theme-editor fields; $theme (the Theme preset dropdown) is the legacy
            // column value and the per-field sanitize fallback.
            $themeJson = theme_to_json([
                'font'         => $_POST['theme_font']         ?? '',
                'font_heading' => $_POST['theme_font_heading'] ?? '',
                'bg'           => $_POST['theme_bg']           ?? '',
                'text'         => $_POST['theme_text']         ?? '',
                'accent'       => $_POST['theme_accent']       ?? '',
            ], $theme);
            // Genres: JSON array from the chip editor
            $genres = json_decode($_POST['genres'] ?? '[]', true);
            if (!is_array($genres)) $genres = [];
            // Per-story default AI image settings (edit properties form)
            $aiImageCategory = trim($_POST['ai_image_category'] ?? '');
            $aiImageStyle    = trim($_POST['ai_image_style']    ?? '');
            $aiImageMood     = trim($_POST['ai_image_mood']     ?? '');
            $aiImageQuality  = trim($_POST['ai_image_quality']  ?? '');

            if (empty($title)) {
                $_SESSION['flash_error'] = 'Story title is required.';
                $redirect = $storyID > 0
                    ? "editor.php?action=story_properties&storyID=$storyID"
                    : "editor.php?action=new_story";
                header("Location: $redirect");
                exit;
            }

            if ($storyID > 0) {
                // Update existing story - verify ownership (admins can edit any)
                $story = get_story($storyID);
                if (!$story || ($story['userID'] != $userID && !$isAdmin)) {
                    $_SESSION['flash_error'] = 'You do not have permission to edit this story.';
                    header('Location: index.php?filter=mine');
                    exit;
                }

                // Admin: reassign owner if changed
                if ($isAdmin && isset($_POST['owner'])) {
                    $newOwnerID = (int)$_POST['owner'];
                    if ($newOwnerID > 0 && $newOwnerID != $story['userID']) {
                        update_story_owner($storyID, $newOwnerID);
                    }
                }

                // Handle image upload / explicit removal
                $image = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $image = upload_image($_FILES['image'], $storyID, 'story_');
                } elseif (!empty($_POST['remove_image'])) {
                    $image = '';   // clear the cover (file left in place — may be shared with the published version)
                }

                update_story($storyID, $title, $description, $image, $theme, $layout, $genres);
                update_story_image_settings($storyID, $aiImageCategory, $aiImageStyle, $aiImageMood, $aiImageQuality);
                update_story_theme_json($storyID, $themeJson); // Phase 42 (null clears → legacy theme)
                $_SESSION['flash_message'] = 'Story updated successfully!';
                header("Location: editor.php?storyID=$storyID");
                exit;
            } else {
                // Create new story
                $newStoryID = create_story($title, $description, '', $theme, $userID, $userName, $layout, $genres);
                if ($newStoryID && $themeJson !== null) {
                    update_story_theme_json($newStoryID, $themeJson); // Phase 42 custom theme
                }
                if ($newStoryID) {
                    // Upload image with the new storyID
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $image = upload_image($_FILES['image'], $newStoryID, 'story_');
                        if ($image) {
                            update_story($newStoryID, $title, $description, $image, $theme, $layout, $genres);
                        }
                    }
                    $_SESSION['flash_message'] = 'Story created successfully!';
                    header("Location: editor.php?storyID=$newStoryID");
                    exit;
                } else {
                    $_SESSION['flash_error'] = 'Failed to create story.';
                    header("Location: editor.php?action=new_story");
                    exit;
                }
            }
            break;

        // ---- DELETE STORY (soft delete — moves to trash) ----
        case 'delete_story':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)) {
                db_soft_delete_story($storyID);
                $_SESSION['flash_message'] = 'Story moved to Trash.';
                $_SESSION['flash_type']    = 'success';
            } else {
                $_SESSION['flash_error'] = 'You do not have permission to delete this story.';
            }
            header('Location: index.php?filter=mine');
            exit;

        // ---- SAVE SCENE (Create or Update) ----
        case 'save_scene':
            $sceneID = isset($_POST['sceneID']) ? (int)$_POST['sceneID'] : 0;
            $storyID = (int)($_POST['storyID'] ?? 0);
            $isNewScene = !empty($_POST['is_new']);
            $title = trim($_POST['sp_title'] ?? '');
            $description = trim($_POST['sp_description'] ?? '');
            $imageGen = trim($_POST['sp_image_gen'] ?? '');
            $hint = trim($_POST['sp_hint'] ?? '');

            // Verify story ownership
            $story = get_story($storyID);
            if (!$story || $story['userID'] != $userID) {
                $_SESSION['flash_error'] = 'You do not have permission to edit this story.';
                header('Location: index.php?filter=mine');
                exit;
            }

            if (empty($title)) {
                $_SESSION['flash_error'] = 'Scene title is required.';
                $redirect = $sceneID > 0
                    ? "editor.php?action=edit_scene&storyID=$storyID&sceneID=$sceneID" . ($isNewScene ? '&is_new=1' : '')
                    : "editor.php?action=new_scene&storyID=$storyID";
                header("Location: $redirect");
                exit;
            }

            // Handle image upload / explicit removal
            $image = null;
            if (isset($_FILES['sp_image']) && $_FILES['sp_image']['error'] === UPLOAD_ERR_OK) {
                $image = upload_image($_FILES['sp_image'], $storyID, 'sp_');
            } elseif (!empty($_POST['remove_sp_image'])) {
                $image    = '';   // clear the scene image …
                $imageGen = '';   // … and its stored prompt
            }

            $enableAutoBackNav = isset($_POST['enable_autoBack_nav']) ? 1 : 0;

            if ($sceneID > 0) {
                // Update existing scene
                update_scene($sceneID, $title, $description, $image, $imageGen, $hint, $enableAutoBackNav);
            } else {
                // Create new scene
                $sceneID = create_scene($storyID, $title, $description, $image, $imageGen, $hint, $enableAutoBackNav);
            }

            // Save choices
            $choices = array();
            if (isset($_POST['choice_text']) && is_array($_POST['choice_text'])) {
                foreach ($_POST['choice_text'] as $i => $text) {
                    $text = trim($text);
                    $dest = (int)($_POST['choice_dest'][$i] ?? 0);
                    if (!empty($text)) {
                        $choices[] = array('text' => $text, 'dest' => $dest);
                    }
                }
            }
            save_choices($sceneID, $choices);

            $_SESSION['flash_message'] = 'Scene saved successfully!';
            header("Location: editor.php?storyID=$storyID#sp-$sceneID");
            exit;

        // ---- CLONE STORY ----
        case 'clone_story':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $cloneTitle = trim($_POST['clone_title'] ?? '');
            $story = get_story($storyID);
            // Any logged-in user may clone a published story; owners/admins may clone their own drafts
            $canClone = $story && ($story['status'] === 'published' || $story['userID'] == $userID || $isAdmin);
            if ($canClone) {
                if (empty($cloneTitle)) $cloneTitle = $story['title'] . ' (Copy)';
                $newID = clone_story($storyID, $cloneTitle, $userID);
                if ($newID) {
                    $_SESSION['flash_message'] = 'Story cloned successfully!';
                    header("Location: editor.php?storyID=$newID");
                    exit;
                } else {
                    $_SESSION['flash_error'] = 'Failed to clone story.';
                }
            } else {
                $_SESSION['flash_error'] = 'You do not have permission to clone this story.';
            }
            header('Location: index.php?filter=mine');
            exit;

        // ---- DELETE SCENE ----
        case 'delete_scene':
            $sceneID = (int)($_POST['sceneID'] ?? 0);
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && $story['userID'] == $userID) {
                delete_scene($sceneID);
                $_SESSION['flash_message'] = 'Scene deleted successfully!';
            } else {
                $_SESSION['flash_error'] = 'You do not have permission to delete this scene.';
            }
            header("Location: editor.php?storyID=$storyID");
            exit;

        // ---- PUBLISH STORY (standalone draft → published) ----
        case 'publish_story':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)
                && $story['status'] === 'draft' && $story['published_story_id'] === null) {
                publish_story($storyID);
                $_SESSION['flash_message'] = 'Story published! It is now visible to everyone.';
            } else {
                $_SESSION['flash_error'] = 'Could not publish this story.';
            }
            header("Location: editor.php?storyID=$storyID");
            exit;

        // ---- UNPUBLISH STORY (published → standalone draft) ----
        case 'unpublish_story':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)
                && $story['status'] === 'published') {
                set_story_draft($storyID);
                $_SESSION['flash_message'] = 'Story unpublished. It is now only visible to you.';
            } else {
                $_SESSION['flash_error'] = 'Could not unpublish this story.';
            }
            header("Location: editor.php?storyID=$storyID");
            exit;

        // ---- START EDITING (published → create/find shadow draft → redirect) ----
        case 'start_edit':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)
                && $story['status'] === 'published') {
                $draft = get_edit_draft($storyID);
                if (!$draft) {
                    $draftID = create_edit_draft($storyID, $story['userID']);
                    if (!$draftID) {
                        $_SESSION['flash_error'] = 'Failed to create a working draft.';
                        header("Location: editor.php?storyID=$storyID");
                        exit;
                    }
                } else {
                    $draftID = (int)$draft['storyID'];
                }
                header("Location: editor.php?storyID=$draftID");
                exit;
            }
            header("Location: editor.php?storyID=$storyID");
            exit;

        // ---- PUBLISH DRAFT (shadow draft replaces published original) ----
        case 'publish_draft':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)
                && !empty($story['published_story_id'])) {
                $publishedID = (int)$story['published_story_id'];
                if (publish_draft($storyID)) {
                    $_SESSION['flash_message'] = 'Changes published! Your story is live.';
                    header("Location: editor.php?storyID=$publishedID");
                    exit;
                } else {
                    $_SESSION['flash_error'] = 'Failed to publish draft.';
                }
            } else {
                $_SESSION['flash_error'] = 'Could not publish this draft.';
            }
            header("Location: editor.php?storyID=$storyID");
            exit;

        // ---- CREATE BLANK SCENE (Add Scene flow — creates a DB row immediately so a sceneID exists) ----
        case 'create_blank_scene':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if (!$story || ($story['userID'] != $userID && !$isAdmin)) {
                $_SESSION['flash_error'] = 'You do not have permission to add scenes to this story.';
                header('Location: index.php?filter=mine');
                exit;
            }
            // If published, route through shadow draft first
            if ($story['status'] === 'published' && $story['published_story_id'] === null) {
                $existingDraft = get_edit_draft($storyID);
                if ($existingDraft) {
                    $storyID = (int)$existingDraft['storyID'];
                } else {
                    $newDraftID = create_edit_draft($storyID, $story['userID']);
                    if (!$newDraftID) {
                        $_SESSION['flash_error'] = 'Failed to create a working draft.';
                        header("Location: editor.php?storyID=$storyID");
                        exit;
                    }
                    $storyID = $newDraftID;
                }
            }
            $newSceneID = create_scene($storyID, '', '', null, null, null, 1);
            if (!$newSceneID) {
                $_SESSION['flash_error'] = 'Failed to create scene.';
                header("Location: editor.php?storyID=$storyID");
                exit;
            }
            header("Location: editor.php?action=edit_scene&storyID=$storyID&sceneID=$newSceneID&is_new=1");
            exit;

        // ---- DISCARD NEW SCENE (cancel during Add Scene flow) ----
        case 'discard_scene':
            $sceneID = (int)($_POST['sceneID'] ?? 0);
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)) {
                delete_scene($sceneID);
                $_SESSION['flash_message'] = 'New scene discarded.';
            } else {
                $_SESSION['flash_error'] = 'You do not have permission to delete this scene.';
            }
            header("Location: editor.php?storyID=$storyID");
            exit;

        // ---- DISCARD DRAFT (delete shadow draft, keep published original) ----
        case 'discard_draft':
            $storyID = (int)($_POST['storyID'] ?? 0);
            $story = get_story($storyID);
            if ($story && ($story['userID'] == $userID || $isAdmin)
                && !empty($story['published_story_id'])) {
                $publishedID = (int)$story['published_story_id'];
                if (discard_draft($storyID)) {
                    $_SESSION['flash_message'] = 'Draft discarded. The published version is unchanged.';
                    header("Location: editor.php?storyID=$publishedID");
                    exit;
                } else {
                    $_SESSION['flash_error'] = 'Failed to discard draft.';
                }
            } else {
                $_SESSION['flash_error'] = 'Could not discard this draft.';
            }
            header("Location: editor.php?storyID=$storyID");
            exit;
    }
}

// ================================================================
// DETERMINE CURRENT VIEW (based on GET parameters)
// ================================================================
$action = $_GET['action'] ?? '';
$storyID = isset($_GET['storyID']) ? (int)$_GET['storyID'] : null;
$sceneID = isset($_GET['sceneID']) ? (int)$_GET['sceneID'] : null;
$isNew = false; // true when a blank scene was just auto-created via "Add Scene"

if ($action === 'new_story') {
    $view = 'story_form';
    $editStory = null;

} elseif ($action === 'story_properties' && $storyID) {
    $view = 'story_form';
    $editStory = get_story($storyID);
    if (!$editStory || ($editStory['userID'] != $userID && !$isAdmin)) {
        header('Location: index.php?filter=mine');
        exit;
    }
    if ($isAdmin) {
        $allUsers = get_all_users();
    }

} elseif ($action === 'edit_scene' && $storyID && $sceneID) {
    $view = 'scene_form';
    $story = get_story($storyID);
    if (!$story || ($story['userID'] != $userID && !$isAdmin)) {
        header('Location: index.php?filter=mine');
        exit;
    }
    // If the story is published, route edits through a shadow draft. The draft is
    // a fresh copy with NEW scene IDs, so remap the requested (published) sceneID
    // to its counterpart in the draft — otherwise the editor can't find the scene
    // and falls back to a blank "new scene" form.
    if ($story['status'] === 'published' && $story['published_story_id'] === null) {
        $existingDraft = get_edit_draft($storyID);
        $draftID = $existingDraft ? (int)$existingDraft['storyID'] : create_edit_draft($storyID, $story['userID']);
        if ($draftID) {
            $draftSceneID = map_published_scene_to_draft($storyID, $draftID, $sceneID);
            header('Location: ' . ($draftSceneID
                ? "editor.php?action=edit_scene&storyID=$draftID&sceneID=$draftSceneID"
                : "editor.php?storyID=$draftID"));   // couldn't map — show the draft's scene list
            exit;
        }
    }
    $isNew = !empty($_GET['is_new']);
    $editScene = get_scene($sceneID, $storyID);

} elseif ($action === 'new_scene' && $storyID) {
    $view = 'scene_form';
    $editScene = null;
    $story = get_story($storyID);
    if (!$story || ($story['userID'] != $userID && !$isAdmin)) {
        header('Location: index.php?filter=mine');
        exit;
    }
    // If the story is published, route edits through a shadow draft.
    if ($story['status'] === 'published' && $story['published_story_id'] === null) {
        $existingDraft = get_edit_draft($storyID);
        if ($existingDraft) {
            $draftID = (int)$existingDraft['storyID'];
        } else {
            $draftID = create_edit_draft($storyID, $story['userID']);
        }
        if ($draftID) {
            header("Location: editor.php?action=new_scene&storyID=$draftID");
            exit;
        }
    }

} elseif ($storyID) {
    $view = 'view_story';
    $story = get_story($storyID);
    if (!$story || ($story['userID'] != $userID && !$isAdmin)) {
        header('Location: index.php?filter=mine');
        exit;
    }

    // If published and a shadow draft already exists, redirect straight to it.
    if ($story['status'] === 'published' && $story['published_story_id'] === null) {
        $existingDraft = get_edit_draft($storyID);
        if ($existingDraft) {
            header('Location: editor.php?storyID=' . (int)$existingDraft['storyID']);
            exit;
        }
    }

    // Determine which UI state to render.
    if ($story['status'] === 'published') {
        $storyState = 'published';       // published, no active shadow draft
    } elseif (!empty($story['published_story_id'])) {
        $storyState = 'shadow_draft';    // editing a live story's shadow copy
    } else {
        $storyState = 'standalone_draft'; // new story, not yet published
    }

    $scenes = get_scenes_with_choices_by_story($storyID);
    $storyOwner = get_user_by_id($story['userID']);
    $storyOwnerName = $storyOwner
        ? htmlspecialchars($storyOwner['firstName'] . ' ' . $storyOwner['lastName'])
        : htmlspecialchars($story['created_by']);

} else {
    header('Location: index.php?filter=mine');
    exit;
}

// Phase 28 — content settings used by the genre + image-style controls
$genreList   = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];
$imageStyles = json_decode(app_setting('image_styles') ?? '{}', true) ?: [];
$imageMoods  = json_decode(app_setting('image_moods')  ?? '[]', true) ?: [];

// Sort dropdown options alphabetically for easier scanning. Genres keep the
// catch-all "Other" pinned to the bottom; image styles keep their category
// (optgroup) order but sort the styles within each category; moods sort flat.
usort($genreList, function ($a, $b) {
    $ao = strcasecmp($a, 'other') === 0;
    $bo = strcasecmp($b, 'other') === 0;
    if ($ao !== $bo) return $ao ? 1 : -1;
    return strcasecmp($a, $b);
});
foreach ($imageStyles as $cat => $subs) {
    usort($subs, 'strcasecmp');
    $imageStyles[$cat] = $subs;
}
usort($imageMoods, 'strcasecmp');

// Current story's saved genres + AI image settings (empty for a new story)
$storyGenres        = ($editStory['genre'] ?? []);
if (!is_array($storyGenres)) $storyGenres = [];
$storyImageCategory = $editStory['ai_image_category'] ?? '';
$storyImageStyle    = $editStory['ai_image_style']    ?? '';
$storyImageMood     = $editStory['ai_image_mood']     ?? '';
$storyImageQuality  = $editStory['ai_image_quality']  ?? '';

/**
 * Render the "Use story settings" checkbox + manual style controls for an inline
 * image-generation panel (cover or scene). $prefix namespaces the element IDs.
 */
function render_inline_image_style_controls(string $prefix): void {
    global $imageStyles, $imageMoods, $storyImageCategory, $storyImageStyle, $storyImageMood, $storyImageQuality;
    $hasStory  = ($storyImageCategory !== '' || $storyImageStyle !== '');
    $allStyles = array_merge(...array_values($imageStyles ?: [['']]));
    $styleOff  = ($storyImageStyle !== '' && !in_array($storyImageStyle, $allStyles, true)); // saved style no longer on-list
    $moodList  = $imageMoods;
    if ($storyImageMood !== '' && !in_array($storyImageMood, $moodList, true)) $moodList[] = $storyImageMood;
    ?>
    <?php if ($hasStory): ?>
    <label class="checkbox-label" style="display:flex; align-items:center; gap:0.4rem; font-size:0.85rem; margin-top:0.4rem;">
        <input type="checkbox" id="<?php echo $prefix; ?>-use-story" checked onchange="inlineToggleStyle('<?php echo $prefix; ?>')">
        Use story's image settings (<?php echo htmlspecialchars($storyImageStyle ?: $storyImageCategory); ?>)
    </label>
    <?php endif; ?>
    <div id="<?php echo $prefix; ?>-style-controls" style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.4rem;" <?php echo $hasStory ? 'hidden' : ''; ?>>
        <select id="<?php echo $prefix; ?>-style" class="ai-inline-select">
            <option value="">Image style…</option>
            <?php foreach ($imageStyles as $cat => $subs): ?>
            <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                <?php foreach ($subs as $s): ?>
                <option value="<?php echo htmlspecialchars($s); ?>"<?php echo $s === $storyImageStyle ? ' selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
            <?php if ($styleOff): ?>
            <optgroup label="Other"><option value="<?php echo htmlspecialchars($storyImageStyle); ?>" selected><?php echo htmlspecialchars($storyImageStyle); ?></option></optgroup>
            <?php endif; ?>
        </select>
        <select id="<?php echo $prefix; ?>-mood" class="ai-inline-select">
            <option value="">(no modifier)</option>
            <?php foreach ($moodList as $m): ?>
                <option value="<?php echo htmlspecialchars($m); ?>"<?php echo $m === $storyImageMood ? ' selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
    // Emit the shared inline-style helpers once per request, used by every view that
    // renders these controls (story cover, scene thumbnail, scene-AI modal).
    static $jsEmitted = false;
    if ($jsEmitted) return;
    $jsEmitted = true;
    ?>
    <script>
    if (!window.inlineStyleParams) {
        // One combined image-style dropdown (categories are just optgroup headers);
        // the category is no longer a separate field, so manual picks send an empty
        // image_category and let the chosen style speak for itself.
        window.inlineStoryDefaults = {
            category: <?php echo json_encode($storyImageCategory); ?>,
            style:    <?php echo json_encode($storyImageStyle); ?>,
            mood:     <?php echo json_encode($storyImageMood); ?>,
            quality:  <?php echo json_encode($storyImageQuality); ?>
        };
        window.inlineToggleStyle = function (prefix) {
            var cb  = document.getElementById(prefix + '-use-story');
            var ctl = document.getElementById(prefix + '-style-controls');
            if (cb && ctl) ctl.hidden = cb.checked;
        };
        window.inlineStyleParams = function (prefix) {
            var cb = document.getElementById(prefix + '-use-story');
            if (cb && cb.checked) {
                return { image_category: window.inlineStoryDefaults.category, image_style: window.inlineStoryDefaults.style, image_mood: window.inlineStoryDefaults.mood, _quality: window.inlineStoryDefaults.quality };
            }
            var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
            return { image_category: '', image_style: g(prefix + '-style'), image_mood: g(prefix + '-mood'), _quality: '' };
        };
    }
    </script>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Story Editor - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/cards.css">
    <link rel="stylesheet" href="styles/editor.css">
    <link rel="stylesheet" href="styles/tree-view.css">
    <link rel="stylesheet" href="styles/gallery.css">
    <?php if ($view === 'scene_form'): ?>
    <link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
    <?php endif; ?>
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">
        <div class="breadcrumb">
            <a href="index.php">Home</a>
            <?php if ($view === 'story_form' && !$editStory): ?>
                <a href="index.php?filter=mine">My Stories</a>
                <span>New Story</span>
            <?php elseif ($view === 'story_form' && $editStory): ?>
                <a href="index.php?filter=mine">My Stories</a>
                <a href="summary.php?storyID=<?php echo $storyID; ?>"><?php echo htmlspecialchars($editStory['title']); ?></a>
                <a href="editor.php?storyID=<?php echo $storyID; ?>">Editor</a>
                <span>Properties</span>
            <?php elseif ($view === 'view_story'): ?>
                <a href="index.php?filter=mine">My Stories</a>
                <a href="summary.php?storyID=<?php echo $storyID; ?>"><?php echo htmlspecialchars($story['title']); ?></a>
                <span>Editor</span>
            <?php elseif ($view === 'scene_form' && $editScene && !$isNew): ?>
                <a href="index.php?filter=mine">My Stories</a>
                <a href="summary.php?storyID=<?php echo $storyID; ?>"><?php echo htmlspecialchars($story['title']); ?></a>
                <a href="editor.php?storyID=<?php echo $storyID; ?>">Editor</a>
                <span><?php echo htmlspecialchars($editScene['title']); ?></span>
            <?php elseif ($view === 'scene_form'): ?>
                <a href="index.php?filter=mine">My Stories</a>
                <a href="summary.php?storyID=<?php echo $storyID; ?>"><?php echo htmlspecialchars($story['title']); ?></a>
                <a href="editor.php?storyID=<?php echo $storyID; ?>">Editor</a>
                <span>New Scene</span>
            <?php endif; ?>
        </div>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>


<?php if ($view === 'view_story'): ?>
<!-- ================================================================
     VIEW: STORY OVERVIEW (with scene list)
     ================================================================ -->
        <div class="editor-header">
            <div>
                <h2>Story Editor</h2>
                <p style="margin:0.25rem 0 0; font-size:0.875rem; opacity:0.75;">Editing: &ldquo;<?php echo htmlspecialchars($story['title']); ?>&rdquo; &nbsp;&mdash;&nbsp; Owner: <?php echo $storyOwnerName; ?></p>
            </div>
            <a href="summary.php?storyID=<?php echo $storyID; ?>" class="btn btn-secondary btn-sm">&larr; Back to Story Summary</a>
        </div>

        <!-- Story Info Card -->
        <div class="story-overview">
            <div class="story-overview-header">
                <div class="story-overview-image">
                    <?php if (!empty($story['image'])): ?>
                        <?php $coverImgUrl = htmlspecialchars(editor_img_url($storyID, $story['published_story_id'] ?? null, $story['image'])); ?>
                        <button type="button" class="scene-thumb-btn" data-img="<?php echo $coverImgUrl; ?>"
                                title="View full image" aria-label="View full image" style="height:100%;">
                            <img src="<?php echo $coverImgUrl; ?>" alt="">
                        </button>
                    <?php else: ?>
                        <div class="placeholder-image" style="height:100%">&#128214;</div>
                    <?php endif; ?>
                </div>
                <div class="story-overview-info">
                    <div class="story-overview-titlebar">
                        <h3><?php echo htmlspecialchars($story['title']); ?></h3>
                        <?php if ($storyState === 'published'): ?>
                            <span class="status-tag status-published">Published</span>
                        <?php else: ?>
                            <span class="status-tag status-draft">Draft</span>
                        <?php endif; ?>
                    </div>
                    <p><?php echo htmlspecialchars($story['description']); ?></p>
                    <div class="story-overview-meta">
                        <span>Theme: <?php echo htmlspecialchars($themes[$story['theme']] ?? $story['theme']); ?></span>
                        <span>Layout: <?php echo htmlspecialchars($layouts[$story['layout']] ?? $story['layout']); ?></span>
                        <span>Created: <?php echo htmlspecialchars($story['date_created']); ?></span>
                        <span>Scenes: <?php echo count($scenes); ?></span>
                    </div>
                    <div class="story-overview-actions">
                        <div class="story-actions-left">
                            <a href="editor.php?action=story_properties&storyID=<?php echo $storyID; ?>" class="btn btn-edit btn-sm">Edit Properties</a>
                            <a href="play.php?storyID=<?php echo $storyID; ?>" class="btn btn-play btn-sm" target="_blank">Play Story</a>
                        </div>
                        <div class="story-actions-right">
                        <?php if ($storyState === 'standalone_draft'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="publish_story">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Publish</button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="delete_story">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <button type="button" class="btn btn-danger btn-sm"
                                        onclick="Modal.confirmDanger({heading:'Delete Story?', message:'It will be moved to Trash — you can restore it from the Trash page.', confirmLabel:'Delete', onConfirm: () => this.closest('form').submit()})">Delete Story</button>
                            </form>
                        <?php elseif ($storyState === 'shadow_draft'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="publish_draft">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <button type="button" class="btn btn-primary btn-sm"
                                        onclick="Modal.confirm('Publish these changes? The live story will be updated.', () => this.closest('form').submit())">Publish</button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="discard_draft">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="Modal.confirm('Discard all your changes? The published version will be unchanged.', () => this.closest('form').submit())">Revert to Original</button>
                            </form>
                        <?php elseif ($storyState === 'published'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="unpublish_story">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="Modal.confirm('Unpublish this story? It will become a draft visible only to you.', () => this.closest('form').submit())">Set to Draft</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($storyState !== 'standalone_draft'): ?>
                            <?php /* Owner or admin (the only viewers of this page) can move a published
                                     story or its shadow draft to Trash; standalone drafts have their
                                     own Delete button above. */ ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="delete_story">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <button type="button" class="btn btn-danger btn-sm"
                                        onclick="Modal.confirmDanger({heading:'Delete Story?', message:'It will be moved to Trash — you can restore it from the Trash page.', confirmLabel:'Delete', onConfirm: () => this.closest('form').submit()})">Delete Story</button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scenes List -->
        <div class="scenes-section">
            <h3>
                Scenes
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="create_blank_scene">
                    <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                    <button type="submit" class="btn btn-primary btn-sm">+ Add Scene</button>
                </form>
                <?php if (!empty($scenes)): ?>
                <button type="button" id="btn-tree-view" class="btn btn-secondary btn-sm">Tree View</button>
                <a href="gallery.php?storyID=<?php echo $storyID; ?>&from=editor" class="btn btn-secondary btn-sm">Gallery</a>
                <?php endif; ?>
            </h3>

            <?php if (empty($scenes)): ?>
                <div class="empty-message">
                    <p>No scenes yet. Add your first scene to begin building your adventure!</p>
                </div>
            <?php else: ?>
                <style>
                .scene-item-thumb,
                .scene-item-thumb img { width:<?php echo (int) app_setting('scene_thumb_size'); ?>px; min-width:<?php echo (int) app_setting('scene_thumb_size'); ?>px; height:<?php echo (int) app_setting('scene_thumb_size'); ?>px; }
                </style>
                <div class="scene-list">
                    <?php foreach ($scenes as $sp): ?>
                    <?php $imgUrl = !empty($sp['image']) ? htmlspecialchars(editor_img_url($storyID, $story['published_story_id'] ?? null, $sp['image'])) : null; ?>
                    <a id="sp-<?php echo (int)$sp['sceneID']; ?>" style="display:block; position:relative; top:-80px; visibility:hidden;"></a>
                    <div class="scene-item">
                        <div class="scene-item-thumb">
                            <?php if ($imgUrl): ?>
                                <button type="button" class="scene-thumb-btn" data-img="<?php echo $imgUrl; ?>"
                                        title="View full image" aria-label="View full image">
                                    <img src="<?php echo $imgUrl; ?>" alt="">
                                </button>
                            <?php else: ?>
                                <div class="placeholder-image">&#128214;</div>
                            <?php endif; ?>
                        </div>
                        <div class="scene-item-info">
                            <h4><?php echo htmlspecialchars($sp['title']); ?></h4>
                            <?php $preview = htmlspecialchars(mb_substr(strip_tags($sp['description']), 0, 150)); ?>
                            <p><?php echo $preview; ?><?php echo mb_strlen(strip_tags($sp['description'])) > 150 ? '…' : ''; ?></p>
                            <?php if (!empty($sp['choices'])): ?>
                                <ul class="scene-card-choices">
                                    <?php foreach ($sp['choices'] as $ch): ?>
                                        <li>&#8627; <?php echo htmlspecialchars($ch['text']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="scene-card-no-choices">No choices — ending scene</p>
                            <?php endif; ?>
                        </div>
                        <div class="scene-item-actions">
                            <a href="editor.php?action=edit_scene&storyID=<?php echo $storyID; ?>&sceneID=<?php echo (int)$sp['sceneID']; ?>" class="btn btn-edit btn-sm">Edit</a>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="delete_scene">
                                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                                <input type="hidden" name="sceneID" value="<?php echo (int)$sp['sceneID']; ?>">
                                <button type="button" class="btn btn-danger btn-sm"
                                        onclick="Modal.confirmDanger({heading:'Delete Scene?', message:'This scene and all its choices will be permanently removed.', confirmLabel:'Delete', onConfirm: () => this.closest('form').submit()})">Delete</button>
                            </form>
                            <a href="play.php?storyID=<?php echo $storyID; ?>&id=<?php echo (int)$sp['sceneID']; ?>" class="btn btn-play btn-sm" target="_blank">Play</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>

        <!-- Full-size image gallery lightbox (cover + scene thumbnails) -->
        <?php $galleryItems = get_gallery_items($storyID); ?>
        <style>.gallery-lightbox { --gallery-strip: <?php echo max(40, (int) app_setting('gallery_filmstrip_size')); ?>px; }</style>
        <div class="gallery-lightbox" id="gallery-lightbox" hidden>
            <div class="gallery-lightbox-title" id="gallery-lightbox-title"></div>
            <button type="button" class="gallery-lightbox-close" aria-label="Close">&times;</button>
            <button type="button" class="gallery-nav gallery-nav-prev" aria-label="Previous">&#10094;</button>
            <div class="gallery-lightbox-stage">
                <img id="gallery-lightbox-img" src="" alt="">
            </div>
            <button type="button" class="gallery-nav gallery-nav-next" aria-label="Next">&#10095;</button>
            <div class="gallery-filmstrip" id="gallery-filmstrip"></div>
        </div>
        <script id="gallery-data" type="application/json"><?php
            echo json_encode($galleryItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?></script>
        <script src="gallery.js"></script>
        <script>
        // Reuse the gallery lightbox for the cover + scene thumbnails: clicking a
        // thumbnail opens it in the lightbox (with prev/next + filmstrip), matched
        // to its gallery item by image src.
        (function () {
            document.querySelectorAll('.scene-thumb-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!window.Gallery) return;
                    var idx = window.Gallery.indexOfSrc(btn.dataset.img);
                    window.Gallery.open(idx >= 0 ? idx : 0);
                });
            });
        })();
        </script>

<?php elseif ($view === 'story_form'): ?>
<!-- ================================================================
     VIEW: STORY PROPERTIES FORM (Create / Edit)
     ================================================================ -->
        <div class="editor-header">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <h2><?php echo $editStory ? 'Edit Story Properties' : 'Create New Story'; ?></h2>
                <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                <button type="button" id="btn-story-ai" class="btn-ai-inline"
                        onclick="aiGuard('text') && toggleStoryAI()" title="Generate with AI">&#10022; Use AI</button>
                <?php endif; ?>
            </div>
            <?php if ($editStory): ?>
                <a href="editor.php?storyID=<?php echo (int)$editStory['storyID']; ?>" class="btn btn-secondary btn-sm">&larr; Back to Story</a>
            <?php else: ?>
                <a href="index.php?filter=mine" class="btn btn-secondary btn-sm">&larr; Back to My Stories</a>
            <?php endif; ?>
        </div>

        <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
        <div id="story-ai-section" class="story-ai-section" hidden>
            <div class="story-ai-header">
                <span>&#10022; AI Settings</span>
                <button type="button" class="btn-ai-dice" onclick="randomizeStoryAI()" title="Randomize">&#127922; Randomize</button>
            </div>
            <div class="story-ai-dropdowns">
                <div class="form-group">
                    <label for="ai-scene-count">Scenes</label>
                    <select id="ai-scene-count">
                        <option value="8" selected>~8 scenes</option>
                        <option value="12">~12 scenes</option>
                        <option value="16">~16 scenes</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ai-num-endings">Endings</label>
                    <select id="ai-num-endings">
                        <option value="2" selected>2 endings</option>
                        <option value="3">3 endings</option>
                        <option value="4">4 endings</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ai-word-length">Scene Length</label>
                    <select id="ai-word-length">
                        <option value="50" selected>~50 words</option>
                        <option value="100">~100 words</option>
                        <option value="200">~200 words</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ai-audience">Audience</label>
                    <select id="ai-audience">
                        <option value="" selected>Any</option>
                        <?php foreach (story_audiences() as $audKey => $audMeta): ?>
                        <option value="<?php echo htmlspecialchars($audKey); ?>"><?php echo htmlspecialchars($audMeta['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ai-tone">Tone</label>
                    <select id="ai-tone">
                        <option value="" selected>Any</option>
                        <option value="dark">Dark</option>
                        <option value="hopeful">Hopeful</option>
                        <option value="humorous">Humorous</option>
                        <option value="neutral">Neutral</option>
                        <option value="suspenseful">Suspenseful</option>
                    </select>
                </div>
            </div>
            <div class="ai-image-row" style="margin-top:0.75rem;">
                <label class="checkbox-label" style="display:flex; align-items:center; gap:0.5rem; <?php echo $aiHasOpenai ? '' : 'opacity:0.55; cursor:not-allowed;'; ?>">
                    <input type="checkbox" id="ai-include-images" <?php echo $aiHasOpenai ? 'checked' : 'disabled'; ?>> Include Images
                    <?php if (!$aiHasOpenai): ?>
                    <span style="font-size:0.8rem; color:var(--text-light);">— add an OpenAI key to your <a href="account.php">account</a> to include images</span>
                    <?php endif; ?>
                </label>
                <div class="ai-image-controls" id="ai-image-controls" style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.5rem;">
                    <div class="form-group">
                        <label for="ai-image-style">Image Style</label>
                        <select id="ai-image-style" disabled style="max-width:100%;">
                            <option value="" selected>Any</option>
                            <?php foreach ($imageStyles as $cat => $subs): ?>
                            <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                                <?php foreach ($subs as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ai-image-mood">Image Modifiers</label>
                        <select id="ai-image-mood" disabled>
                            <option value="" selected>Skip</option>
                            <?php foreach ($imageMoods as $mood): ?>
                                <option value="<?php echo htmlspecialchars($mood); ?>"><?php echo htmlspecialchars($mood); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ai-image-quality">Image Quality</label>
                        <select id="ai-image-quality" disabled>
                            <option value="low">Low quality</option>
                            <option value="medium" selected>Medium quality</option>
                            <option value="high">High quality</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function () {
                var cb       = document.getElementById('ai-include-images');
                var controls = document.getElementById('ai-image-controls');
                if (!cb || !controls) return; // AI panel only present on create-new

                cb.addEventListener('change', function () {
                    controls.querySelectorAll('select').forEach(function (s) { s.disabled = !cb.checked; });
                    // Publish only applies when images are being generated.
                    if (window.updatePublishRow) window.updatePublishRow();
                });
                // Initialise to match the checkbox (checked by default → controls enabled)
                controls.querySelectorAll('select').forEach(function (s) { s.disabled = !cb.checked; });
            })();
        </script>
        <?php endif; ?>

        <div class="editor-form">
            <form method="POST" enctype="multipart/form-data" id="story-form">
                <input type="hidden" name="action" value="save_story">

                <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                <div id="use-ai-row" class="form-group field-row use-ai-checklist" hidden>
                    <input type="checkbox" id="cb-all" class="use-ai-select-all-cb" onchange="toggleAllAI(this.checked)">
                    <label for="cb-all" class="use-ai-select-all-label">Use AI for all fields</label>
                </div>
                <?php endif; ?>
                <?php if ($editStory): ?>
                    <input type="hidden" name="storyID" value="<?php echo (int)$editStory['storyID']; ?>">
                <?php endif; ?>

                <!-- Story Title -->
                <div class="form-group field-row" id="row-title">
                    <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                    <input type="checkbox" class="ai-field-cb" id="cb-title" data-field="title" hidden
                           onchange="handleFieldCB('title', this.checked)">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label for="title">Story Title</label>
                        <input type="text" id="title" name="title"
                               value="<?php echo htmlspecialchars($editStory['title'] ?? ''); ?>"
                               <?php echo $editStory ? 'required' : ''; ?>
                               maxlength="128" placeholder="Enter your story title...">
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group field-row" id="row-description">
                    <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                    <input type="checkbox" class="ai-field-cb" id="cb-description" data-field="description" hidden
                           onchange="handleFieldCB('description', this.checked)">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="4" maxlength="512"
                                  placeholder="Describe your adventure..."><?php echo htmlspecialchars($editStory['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Genres (multi-select chip editor) -->
                <div class="form-group field-row" id="row-genres">
                    <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                    <input type="checkbox" class="ai-field-cb" id="cb-genres" data-field="genres" hidden
                           onchange="handleFieldCB('genres', this.checked)">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label>Genres</label>
                        <select id="genre-add-select" class="form-control" onchange="addGenreChip(this)">
                            <option value="">+ Add genre</option>
                            <?php foreach ($genreList as $g): ?>
                                <option value="<?php echo htmlspecialchars($g); ?>"
                                    <?php echo in_array($g, $storyGenres, true) ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($g); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="genre-chip-editor" id="genre-chip-editor"></div>
                        <input type="hidden" name="genres" id="genres-hidden"
                               value="<?php echo htmlspecialchars(json_encode(array_values($storyGenres))); ?>">
                    </div>
                </div>
                <style>
                    /* Chips sit below the dropdown; no reserved height so an empty list adds no gap. */
                    .genre-chip-editor { display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:0.5rem; min-height:0; }
                    .genre-chip-editor:empty { margin-top:0; }
                    .genre-chip { display:inline-flex; align-items:center; gap:0.25rem; background:var(--accent, #4a6); color:#fff;
                                  border-radius:999px; padding:0.2rem 0.6rem; font-size:0.85rem; }
                    .genre-chip-x { background:none; border:none; color:#fff; cursor:pointer; font-size:1rem; line-height:1; padding:0; }
                </style>
                <script>
                (function () {
                    var genres = <?php echo json_encode(array_values($storyGenres), JSON_UNESCAPED_UNICODE); ?>;
                    var editor = document.getElementById('genre-chip-editor');
                    var hidden = document.getElementById('genres-hidden');
                    var addSel = document.getElementById('genre-add-select');

                    function setOptionDisabled(val, disabled) {
                        for (var i = 0; i < addSel.options.length; i++) {
                            if (addSel.options[i].value === val) addSel.options[i].disabled = disabled;
                        }
                    }
                    function render() {
                        editor.innerHTML = '';
                        genres.forEach(function (g, i) {
                            var chip = document.createElement('span');
                            chip.className = 'genre-chip';
                            chip.appendChild(document.createTextNode(g));
                            var x = document.createElement('button');
                            x.type = 'button'; x.className = 'genre-chip-x'; x.textContent = '×';
                            x.onclick = function () {
                                genres.splice(i, 1); setOptionDisabled(g, false); render();
                            };
                            chip.appendChild(x);
                            editor.appendChild(chip);
                        });
                        hidden.value = JSON.stringify(genres);
                    }
                    window.addGenreChip = function (sel) {
                        var v = sel.value;
                        if (v && genres.indexOf(v) === -1) { genres.push(v); setOptionDisabled(v, true); render(); }
                        sel.value = '';
                    };
                    render();
                })();
                </script>

                <?php if ($editStory && (bool)(int) app_setting('ai_enabled')): ?>
                <!-- Per-story default AI image settings (Phase 28) -->
                <?php
                    // Keep a saved style that's no longer in settings selectable ("Other").
                    $allStyles = array_merge(...array_values($imageStyles ?: [['']]));
                    $styleOff  = ($storyImageStyle !== '' && !in_array($storyImageStyle, $allStyles, true));
                    $moodList  = $imageMoods;
                    if ($storyImageMood !== '' && !in_array($storyImageMood, $moodList, true)) $moodList[] = $storyImageMood;
                ?>
                <div class="form-group">
                    <label>Default AI Image Style</label>
                    <small style="display:block; color:var(--text-light); margin-bottom:0.4rem;">Used as the default when generating cover and scene images for this story.</small>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <select id="story-img-style" name="ai_image_style" style="max-width:100%;">
                            <option value="">Image style…</option>
                            <?php foreach ($imageStyles as $cat => $subs): ?>
                            <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                                <?php foreach ($subs as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>"<?php echo $s === $storyImageStyle ? ' selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                            <?php if ($styleOff): ?>
                            <optgroup label="Other"><option value="<?php echo htmlspecialchars($storyImageStyle); ?>" selected><?php echo htmlspecialchars($storyImageStyle); ?></option></optgroup>
                            <?php endif; ?>
                        </select>
                        <select id="story-img-mood" name="ai_image_mood">
                            <option value="">(no modifier)</option>
                            <?php foreach ($moodList as $mood): ?>
                                <option value="<?php echo htmlspecialchars($mood); ?>"<?php echo $mood === $storyImageMood ? ' selected' : ''; ?>><?php echo htmlspecialchars($mood); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="story-img-quality" name="ai_image_quality">
                            <option value=""<?php echo $storyImageQuality === '' ? ' selected' : ''; ?>>Default quality</option>
                            <?php foreach (['low','medium','high'] as $q): ?>
                                <option value="<?php echo $q; ?>"<?php echo $q === $storyImageQuality ? ' selected' : ''; ?>><?php echo ucfirst($q); ?> quality</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isAdmin && !empty($allUsers) && $editStory): ?>
                <div class="form-group">
                    <label for="owner">Owner</label>
                    <select id="owner" name="owner">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo $u['userID']; ?>"
                                <?php echo ($u['userID'] == ($editStory['userID'] ?? $userID)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['firstName'] . ' ' . $u['lastName'] . ' (' . $u['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php
                // Phase 42 theme engine — resolve the story's current theme values, then
                // work out which dropdown option matches: a named preset, or "Custom".
                $themeDefaultKey = theme_default_key();
                $themeBaseKey  = $editStory['theme'] ?? $themeDefaultKey;
                $themeInitSrc  = !empty($editStory['theme_json'])
                    ? $editStory['theme_json']
                    : theme_preset($themeBaseKey);
                $themeInit     = theme_sanitize($themeInitSrc, $themeBaseKey);

                $themeSelectKey = 'custom';
                foreach (theme_presets() as $pk => $pv) {
                    if (theme_to_json($themeInit, $pk) === theme_to_json(theme_preset($pk), $pk)) {
                        $themeSelectKey = $pk;
                        break;
                    }
                }
                ?>

                <!-- Theme (Phase 42 — data-driven theme engine). The Presets dropdown
                     picks a starting look; the font/colour fields fine-tune it. -->
                <div class="form-group field-row" id="row-theme">
                    <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                    <input type="checkbox" class="ai-field-cb" id="cb-theme" data-field="theme" hidden
                           onchange="handleFieldCB('theme', this.checked)">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label>Color Theme</label>
                        <div id="theme-custom-panel" class="theme-editor">
                            <div class="theme-editor-controls">
                                <div class="theme-ctl">
                                    <label for="theme">Presets</label>
                                    <select id="theme" name="theme">
                                        <?php foreach ($themes as $key => $name): ?>
                                            <option value="<?php echo $key; ?>"
                                                <?php echo ($themeSelectKey === $key) ? 'selected' : ''; ?>>
                                                <?php echo $name; ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="custom" <?php echo ($themeSelectKey === 'custom') ? 'selected' : ''; ?>>Custom</option>
                                    </select>
                                </div>
                                <div class="theme-ctl">
                                    <label for="theme_font_heading">Heading font</label>
                                <?php $headingFonts = play_fonts(); usort($headingFonts, fn($a, $b) => strcasecmp($a['family'], $b['family'])); ?>
                                <select id="theme_font_heading" name="theme_font_heading">
                                    <?php foreach ($headingFonts as $f): ?>
                                        <option value="<?php echo htmlspecialchars($f['family']); ?>" <?php echo $themeInit['font_heading'] === $f['family'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['family']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="theme-ctl">
                                <label for="theme_font">Body font</label>
                                <?php $bodyFonts = play_fonts_for_role('body'); usort($bodyFonts, fn($a, $b) => strcasecmp($a['family'], $b['family'])); ?>
                                <select id="theme_font" name="theme_font">
                                    <?php foreach ($bodyFonts as $f): ?>
                                        <option value="<?php echo htmlspecialchars($f['family']); ?>" <?php echo $themeInit['font'] === $f['family'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['family']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="theme-ctl theme-color-row">
                                <label>Background<input type="color" id="theme_bg" name="theme_bg" value="<?php echo htmlspecialchars($themeInit['bg']); ?>"></label>
                                <label>Text<input type="color" id="theme_text" name="theme_text" value="<?php echo htmlspecialchars($themeInit['text']); ?>"></label>
                                <label>Accent<input type="color" id="theme_accent" name="theme_accent" value="<?php echo htmlspecialchars($themeInit['accent']); ?>"></label>
                            </div>
                        </div>
                        <div class="theme-preview" id="theme-preview">
                            <div class="theme-preview-banner" id="tp-banner">Story Title</div>
                            <p class="theme-preview-body" id="tp-body">The path forks ahead. Moonlight spills across the ancient stones as you weigh your next move.</p>
                            <a class="theme-preview-btn" id="tp-btn">A tempting choice</a>
                        </div>
                        </div><!-- /.theme-editor -->
                    </div><!-- /flex -->
                </div><!-- /#row-theme -->

                <style>
                    .theme-editor { margin-top:0.75rem; border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:1rem; display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
                    .theme-editor[hidden] { display:none; }
                    .theme-editor-controls { display:flex; flex-direction:column; gap:0.6rem; }
                    .theme-ctl label { display:block; font-size:0.85rem; margin-bottom:0.2rem; }
                    .theme-color-row { display:flex; gap:1rem; }
                    .theme-color-row label { flex:1; display:flex; flex-direction:column; align-items:center; text-align:center; gap:0.35rem; font-size:0.8rem; }
                    .theme-color-row input[type=color] { width:48px; height:32px; border:1px solid var(--border,#ccc); border-radius:4px; background:none; cursor:pointer; padding:0; }
                    .theme-preview { border-radius:8px; padding:1.25rem; display:flex; flex-direction:column; gap:0.85rem; align-items:center; justify-content:center; text-align:center; min-height:190px; background:#111; color:#eee; }
                    .theme-preview-banner { font-size:1.5rem; font-weight:700; letter-spacing:0.08em; }
                    .theme-preview-body { font-size:1.05rem; line-height:1.5; margin:0; max-width:38ch; }
                    .theme-preview-btn { display:inline-block; padding:0.4rem 1.1rem; border-radius:4px; font-weight:600; cursor:default; }
                    @media (max-width:700px) { .theme-editor { grid-template-columns:1fr; } }
                </style>
                <script>
                (function () {
                    var panel = document.getElementById('theme-custom-panel');
                    if (!panel) return;

                    var PRESETS = <?php echo json_encode(array_map(fn($p) => theme_sanitize($p), theme_presets()), JSON_UNESCAPED_UNICODE); ?>;
                    var WEIGHTS = <?php echo json_encode(array_column(play_fonts(), 'weights', 'family'), JSON_UNESCAPED_UNICODE); ?>;

                    var $ = function (id) { return document.getElementById(id); };
                    var fH = $('theme_font_heading'), fB = $('theme_font');
                    var cBg = $('theme_bg'), cTx = $('theme_text'), cAc = $('theme_accent');
                    var pPick = $('theme'); // the Theme dropdown doubles as the preset picker
                    var prev = $('theme-preview'), pvBanner = $('tp-banner'), pvBody = $('tp-body'), pvBtn = $('tp-btn');

                    function fontUrl(fam) {
                        var w = WEIGHTS[fam] || '400';
                        return 'https://fonts.googleapis.com/css2?family=' + fam.replace(/ /g, '+') + ':wght@' + w + '&display=swap';
                    }
                    function loadFont(id, fam) {
                        var link = document.getElementById(id);
                        if (!link) { link = document.createElement('link'); link.id = id; link.rel = 'stylesheet'; document.head.appendChild(link); }
                        link.href = fontUrl(fam);
                    }
                    function refresh() {
                        var head = fH.value, body = fB.value, bg = cBg.value, tx = cTx.value, ac = cAc.value;
                        loadFont('tp-font-head', head);
                        loadFont('tp-font-body', body);
                        prev.style.background = bg; prev.style.color = tx;
                        pvBody.style.fontFamily = "'" + body + "'";
                        pvBanner.style.fontFamily = "'" + head + "'"; pvBanner.style.color = ac;
                        pvBtn.style.fontFamily = "'" + head + "'"; pvBtn.style.background = ac; pvBtn.style.color = bg;
                    }
                    function applyPreset(key) {
                        var p = PRESETS[key]; if (!p) return;
                        fH.value = p.font_heading; fB.value = p.font;
                        cBg.value = p.bg; cTx.value = p.text; cAc.value = p.accent;
                        refresh();
                    }
                    // Changing the Theme dropdown loads that preset's fonts/colours into
                    // the fields (applyPreset sets values without firing input/change, so
                    // it won't trip the "Custom" switch below).
                    if (pPick) pPick.addEventListener('change', function () { if (pPick.value && pPick.value !== 'custom') applyPreset(pPick.value); });

                    // A direct edit to any font/colour means the look no longer matches a
                    // preset — flip the Theme dropdown to "Custom".
                    function onFieldEdit() {
                        if (pPick) pPick.value = 'custom';
                        refresh();
                    }
                    [fH, fB, cBg, cTx, cAc].forEach(function (el) {
                        el.addEventListener('input', onFieldEdit);
                        el.addEventListener('change', onFieldEdit);
                    });
                    refresh(); // panel is always visible; sync the preview on load
                })();
                </script>

                <!-- Layout -->
                <div class="form-group field-row" id="row-layout">
                    <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                    <input type="checkbox" class="ai-field-cb" id="cb-layout" data-field="layout" hidden
                           onchange="handleFieldCB('layout', this.checked)">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label for="layout">Layout <span style="font-weight:normal; color:var(--text-light);">(desktop only)</span></label>
                        <select id="layout" name="layout">
                            <?php foreach ($layouts as $key => $name): ?>
                                <option value="<?php echo $key; ?>"
                                    <?php echo (($editStory['layout'] ?? 'image_left') === $key) ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Story Thumbnail -->
                <div class="form-group field-row" id="row-image">
                    <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                    <input type="checkbox" class="ai-field-cb" id="cb-image" data-field="image" hidden
                           onchange="handleFieldCB('image', this.checked)">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label>Story Thumbnail</label>
                        <div style="display:flex; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
                            <img id="image-preview"
                                 src="<?php echo ($editStory && !empty($editStory['image'])) ? htmlspecialchars(editor_img_url((int)$editStory['storyID'], $editStory['published_story_id'] ?? null, $editStory['image'])) : ''; ?>"
                                 alt="Image preview"
                                 style="width:170px; height:170px; object-fit:cover; border-radius:var(--radius); border:1px solid var(--border); image-rendering:crisp-edges; <?php echo ($editStory && !empty($editStory['image'])) ? '' : 'display:none;'; ?>">
                            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                <?php if ($editStory && !empty($editStory['image'])): ?>
                                    <p style="margin:0; font-size:0.85rem; color:var(--text-light);">Upload a new file to replace the current image.</p>
                                <?php endif; ?>
                                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                    <input type="file" id="image" name="image" accept="image/*"
                                           onchange="previewImage(this, 'image-preview')">
                                    <input type="hidden" name="remove_image" id="remove_image" value="">
                                    <?php if ($editStory && !empty($editStory['image'])): ?>
                                    <button type="button" id="btn-remove-cover" class="btn btn-secondary btn-sm"
                                            onclick="removeCoverImage()">Remove image</button>
                                    <?php endif; ?>
                                    <?php if ((bool)(int) app_setting('ai_enabled')): ?>
                                    <button type="button" id="btn-thumb-ai" class="btn-ai-inline"
                                            onclick="aiGuard('image') && toggleThumbAIExpand()" title="Generate image with AI">&#10022; Use AI</button>
                                    <?php endif; ?>
                                </div>
                                <?php if ((bool)(int) app_setting('ai_enabled')): ?>
                                <div id="story-thumb-ai-expand" class="thumb-ai-expand" hidden>
                                    <textarea id="thumb-ai-prompt" rows="2" maxlength="1000"
                                              placeholder="Describe the cover image, or leave blank to use the story description..."
                                              class="ai-inline-textarea"></textarea>
                                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-top:0.5rem;">
                                        <select id="thumb-ai-quality" class="ai-inline-select">
                                            <option value="low">Low quality</option>
                                            <option value="medium" selected>Medium quality</option>
                                            <option value="high">High quality</option>
                                        </select>
                                    </div>
                                    <?php render_inline_image_style_controls('cover'); ?>
                                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem;">
                                        <?php if ($editStory): ?>
                                        <button type="button" id="thumb-ai-btn" class="btn btn-primary btn-sm"
                                                onclick="generateCoverImage()">Generate Image</button>
                                        <span id="thumb-ai-status" style="font-size:0.875rem;"></span>
                                        <?php else: ?>
                                        <span style="font-size:0.85rem; color:var(--text-light);">Save the story first to generate a cover image.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$editStory && (bool)(int) app_setting('ai_enabled')): ?>
                <input type="hidden" id="ai-generate-fields" name="ai_generate_fields" value="[]">
                <input type="hidden" id="ai-generate-cover"  name="ai_generate_cover"  value="0">

                <!-- Auto-publish (shown only when "Use AI" is on; checked by default) -->
                <div id="publish-row" class="form-group" hidden style="margin-top:1rem;">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="ai-publish" name="publish" value="1">
                        Publish Story
                    </label>
                    <small style="display:block; color:var(--text-light); margin-top:0.25rem;">
                        When the AI finishes, the story is published automatically. Uncheck to keep it as a draft for review.
                    </small>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <?php if (!$editStory): ?>
                    <button type="button" id="btn-create-story" class="btn btn-primary"
                            onclick="submitStoryCreate()">Create Story</button>
                    <?php else: ?>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <?php endif; ?>
                    <?php if ($editStory): ?>
                        <a href="editor.php?storyID=<?php echo (int)$editStory['storyID']; ?>" class="btn btn-secondary">Cancel</a>
                    <?php else: ?>
                        <a href="index.php?filter=mine" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ((bool)(int) app_setting('ai_enabled')): ?>
        <script>
        // ── AI toggle (Create page only) ──
        (function () {
            // The "Publish Story" row only makes sense when AI is generating images —
            // an image-less story isn't presentable yet — so it shows only when AI is
            // on AND "Include Images" is checked.
            function updatePublishRow() {
                var pubRow = document.getElementById('publish-row');
                var pub    = document.getElementById('ai-publish');
                if (!pubRow || !pub) return;
                var section = document.getElementById('story-ai-section');
                var inc     = document.getElementById('ai-include-images');
                var show = section && !section.hidden && inc && inc.checked;
                pubRow.hidden = !show;
                // Check it whenever it's shown (default on), uncheck whenever hidden —
                // so a hidden option can never carry a stale value into submit.
                pub.checked = !!show;
            }
            window.updatePublishRow = updatePublishRow;

            function activateAI() {
                var section  = document.getElementById('story-ai-section');
                var useAIRow = document.getElementById('use-ai-row');
                var btn      = document.getElementById('btn-story-ai');
                if (section)  section.hidden  = false;
                if (useAIRow) useAIRow.hidden = false;
                updatePublishRow();
                document.querySelectorAll('.ai-field-cb').forEach(function (cb) { cb.hidden = false; });
                if (btn) btn.classList.add('active');

                // Auto-check "Use AI for all fields" when title and description are both empty
                var titleVal = (document.getElementById('title') || {}).value || '';
                var descVal  = (document.getElementById('description') || {}).value || '';
                if (!titleVal.trim() && !descVal.trim()) {
                    toggleAllAI(true);
                }
            }

            function deactivateAI() {
                var section  = document.getElementById('story-ai-section');
                var useAIRow = document.getElementById('use-ai-row');
                var btn      = document.getElementById('btn-story-ai');
                if (section)  section.hidden  = true;
                if (useAIRow) useAIRow.hidden = true;
                updatePublishRow();
                document.querySelectorAll('.ai-field-cb').forEach(function (cb) {
                    if (cb.checked) { cb.checked = false; handleFieldCB(cb.dataset.field, false); }
                    cb.hidden = true;
                });
                if (btn) btn.classList.remove('active');
            }

            window.toggleStoryAI = function () {
                var section = document.getElementById('story-ai-section');
                if (section && !section.hidden) {
                    deactivateAI();
                } else {
                    activateAI();
                }
            };
        })();

        // Collapse a field-row to just its heading + checkbox when AI handles it.
        function setRowCollapsed(field, collapsed) {
            var cb  = document.getElementById('cb-' + field);
            var row = cb && cb.closest('.field-row');
            if (row) row.classList.toggle('ai-generated', collapsed);
        }

        // ── Field checkboxes ──
        window.handleFieldCB = function (field, checked) {
            var elMap = {
                theme:       document.getElementById('theme'),
                layout:      document.getElementById('layout'),
                title:       document.getElementById('title'),
                description: document.getElementById('description'),
                image:       document.getElementById('image'),
            };
            var el = elMap[field];

            // Image manages its own collapse (it has a replace-confirm step).
            if (field !== 'image') setRowCollapsed(field, checked);

            if (field === 'image')  { handleImageCB(checked);  return; }
            if (field === 'theme')  { handleThemeCB(checked);  return; }
            if (field === 'genres') { handleGenresCB(checked); return; }
            if (!el) return;

            if (checked) {
                el.dataset.prevValue = el.tagName === 'SELECT' ? el.value : (el.value || '');
                if (el.tagName !== 'SELECT') { el.value = ''; el.placeholder = 'generated'; }
                el.setAttribute('disabled', 'disabled');
                el.classList.add('field-ai-checked');
            } else {
                el.removeAttribute('disabled');
                if (el.tagName !== 'SELECT' && el.dataset.prevValue !== undefined) {
                    el.value = el.dataset.prevValue;
                    el.placeholder = '';
                }
                el.classList.remove('field-ai-checked');
            }
            syncSelectAll();
        };

        // Theme: the whole theme editor (preset dropdown + fonts + colours) is
        // disabled together, since the AI generates the entire theme.
        function handleThemeCB(checked) {
            ['theme', 'theme_font_heading', 'theme_font', 'theme_bg', 'theme_text', 'theme_accent'].forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) return;
                if (checked) { el.setAttribute('disabled', 'disabled'); el.classList.add('field-ai-checked'); }
                else         { el.removeAttribute('disabled');          el.classList.remove('field-ai-checked'); }
            });
            var panel = document.getElementById('theme-custom-panel');
            if (panel) panel.classList.toggle('field-ai-checked', checked);
            syncSelectAll();
        }

        // Genres: grey out the chip editor when AI picks the genre.
        function handleGenresCB(checked) {
            var addSel = document.getElementById('genre-add-select');
            var chipEd = document.getElementById('genre-chip-editor');
            if (addSel) addSel.disabled = checked;
            if (chipEd) {
                chipEd.classList.toggle('field-ai-checked', checked);
                chipEd.style.pointerEvents = checked ? 'none' : '';
            }
            syncSelectAll();
        }

        function handleImageCB(checked) {
            var fileInput  = document.getElementById('image');
            var btnThumbAI = document.getElementById('btn-thumb-ai');
            var expand     = document.getElementById('story-thumb-ai-expand');
            if (checked) {
                if (fileInput && fileInput.value) {
                    document.getElementById('cb-image').checked = false;
                    Modal.confirm('This will replace the existing image. Continue?', function () {
                        document.getElementById('cb-image').checked = true;
                        if (expand)     expand.hidden = true;
                        if (fileInput)  { fileInput.disabled = true; fileInput.classList.add('field-ai-checked'); }
                        if (btnThumbAI) btnThumbAI.style.display = 'none';
                        document.getElementById('ai-generate-cover').value = '1';
                        setRowCollapsed('image', true);
                        syncSelectAll();
                    });
                    return;
                }
                if (expand)     expand.hidden = true;
                if (fileInput)  { fileInput.disabled = true; fileInput.classList.add('field-ai-checked'); }
                if (btnThumbAI) btnThumbAI.style.display = 'none';
                document.getElementById('ai-generate-cover').value = '1';
                setRowCollapsed('image', true);
            } else {
                if (fileInput)  { fileInput.disabled = false; fileInput.classList.remove('field-ai-checked'); }
                if (btnThumbAI) btnThumbAI.style.display = '';
                document.getElementById('ai-generate-cover').value = '0';
                setRowCollapsed('image', false);
            }
            syncSelectAll();
        }

        window.toggleAllAI = function (checked) {
            ['theme', 'layout', 'genres', 'title', 'description', 'image'].forEach(function (f) {
                var cb = document.getElementById('cb-' + f);
                if (cb && !cb.hidden) { cb.checked = checked; handleFieldCB(f, checked); }
            });
        };

        function syncSelectAll() {
            var cbAll = document.getElementById('cb-all');
            if (!cbAll) return;
            var all     = document.querySelectorAll('.ai-field-cb:not([hidden])');
            var checked = Array.from(all).filter(function (c) { return c.checked; });
            cbAll.checked      = all.length > 0 && checked.length === all.length;
            cbAll.indeterminate = checked.length > 0 && checked.length < all.length;
        }

        // ── Randomize ──
        window.randomizeStoryAI = function () {
            var TONES     = ['suspenseful','hopeful','dark','humorous','neutral'];
            var LENGTHS   = ['8','12','16'];
            var WORDS     = ['50','100','200'];
            var AUDIENCES = <?php echo json_encode(array_keys(story_audiences()), JSON_UNESCAPED_UNICODE); ?>;
            var QUALITY   = ['low','medium'];   // never "high" when randomizing
            var MOODS     = <?php echo json_encode(array_values($imageMoods), JSON_UNESCAPED_UNICODE); ?>;
            var THEMES    = <?php echo json_encode(array_keys(theme_presets()), JSON_UNESCAPED_UNICODE); ?>;
            var LAYOUTS   = ['image_left','image_right','image_top'];
            function pick(arr) { return arr.length ? arr[Math.floor(Math.random() * arr.length)] : ''; }
            function setVal(id, v) { var el = document.getElementById(id); if (el && !el.disabled) el.value = v; }

            // Audience first — tone and scene length depend on it.
            var aud   = pick(AUDIENCES);
            var young = (aud === 'picture_book' || aud === 'early_readers');
            setVal('ai-audience', aud);

            // Tone: avoid "dark" for the youngest audiences.
            var toneChoices = young ? TONES.filter(function (t) { return t !== 'dark'; }) : TONES;
            setVal('ai-tone', pick(toneChoices));

            // Scenes, with endings synced to the scene count (8→2, 12→3, 16→4).
            var scenes = pick(LENGTHS);
            setVal('ai-scene-count', scenes);
            setVal('ai-num-endings', String(parseInt(scenes, 10) / 4));

            // Scene length: youngest audiences always get the shortest (50 words).
            setVal('ai-word-length', young ? '50' : pick(WORDS));

            // Unconstrained.
            setVal('theme',  pick(THEMES));
            setVal('layout', pick(LAYOUTS));

            // Image controls — only randomise when "Include Images" is checked.
            var inc = document.getElementById('ai-include-images');
            if (inc && inc.checked) {
                var styleSel = document.getElementById('ai-image-style');
                if (styleSel) {
                    // Pick a random real style from the combined dropdown (skip the placeholder).
                    var opts = Array.prototype.slice.call(styleSel.options).filter(function (o) { return o.value; });
                    styleSel.value = opts.length ? opts[Math.floor(Math.random() * opts.length)].value : '';
                }
                setVal('ai-image-mood', (Math.random() < 0.2 || !MOODS.length) ? '' : pick(MOODS));
                setVal('ai-image-quality', pick(QUALITY));   // low/medium only
            }
        };

        // ── Thumbnail AI expand ──
        window.toggleThumbAIExpand = function () {
            var expand = document.getElementById('story-thumb-ai-expand');
            if (expand) expand.hidden = !expand.hidden;
        };

        // ── Inline image-style helpers are emitted by
        //    render_inline_image_style_controls() (shared across views) ──

        // ── Cover image generation (Edit page only) ──
        window.generateCoverImage = function () {
            var btn     = document.getElementById('thumb-ai-btn');
            var status  = document.getElementById('thumb-ai-status');
            var quality = document.getElementById('thumb-ai-quality').value;
            var prompt  = (document.getElementById('thumb-ai-prompt').value.trim())
                          || (document.getElementById('title').value.trim() + ' ' + document.getElementById('description').value.trim());
            if (!prompt.trim()) { status.textContent = 'Enter a prompt or fill in the story title first.'; return; }
            var sp = window.inlineStyleParams('cover');
            if (sp._quality) quality = sp._quality;
            btn.disabled = true;
            status.textContent = 'Queueing job…';
            status.style.color = 'var(--text-light)';
            fetch('api_jobs.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({
                    action: 'create', job_type: 'image',
                    story_id: '<?php echo $editStory ? (int)$editStory['storyID'] : 0; ?>',
                    input_json: JSON.stringify({
                        prompt: prompt,
                        quality: quality, target: 'story_cover',
                        image_category: sp.image_category, image_style: sp.image_style, image_mood: sp.image_mood
                    })
                }).toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    status.textContent = '';
                    Modal.success({
                        heading: 'Image Job Started!',
                        message: 'The AI is generating your cover image. It will appear here once the job completes.',
                        okLabel: 'Got it',
                        jobQueue: true
                    });
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Unknown error');
                    status.style.color = 'var(--danger, red)';
                }
                btn.disabled = false;
            })
            .catch(function () {
                status.textContent = 'Request failed. Please try again.';
                status.style.color = 'var(--danger, red)';
                btn.disabled = false;
            });
        };

        // ── Create story submit ──
        window.submitStoryCreate = function () {
            var section = document.getElementById('story-ai-section');
            var aiOn    = section && !section.hidden;

            if (!aiOn) {
                var titleVal = document.getElementById('title').value.trim();
                if (!titleVal) {
                    Modal.alert('Please enter a story title.', function () { document.getElementById('title').focus(); });
                    return;
                }
                document.getElementById('story-form').submit();
                return;
            }

            // Build premise from description field (use prevValue if AI has cleared it),
            // fall back to title if description is blank
            var descEl  = document.getElementById('description');
            var titleEl = document.getElementById('title');
            var premiseVal = '';
            if (descEl) {
                premiseVal = descEl.disabled
                    ? (descEl.dataset.prevValue || '').trim()
                    : descEl.value.trim();
            }
            if (!premiseVal && titleEl) {
                premiseVal = titleEl.disabled
                    ? (titleEl.dataset.prevValue || '').trim()
                    : titleEl.value.trim();
            }

            // Collect which fields are checked for AI
            var generateFields = [];
            ['title', 'description', 'theme'].forEach(function (f) {
                var cb = document.getElementById('cb-' + f);
                if (cb && cb.checked) generateFields.push(f);
            });

            if (generateFields.indexOf('title') === -1 && !document.getElementById('title').value.trim()) {
                Modal.alert('Please enter a story title, or check the Title checkbox to let AI generate it.');
                return;
            }

            document.getElementById('ai-generate-fields').value = JSON.stringify(generateFields);

            // Build FormData from the form, add AI settings
            var formData = new FormData(document.getElementById('story-form'));
            formData.delete('action');
            formData.set('premise',         premiseVal);
            // Genre comes from the form's genre chips (or "Any" when AI handles genres) —
            // the server resolves it; there is no separate AI-bar genre dropdown.
            formData.set('tone',            document.getElementById('ai-tone').value);
            formData.set('target_scenes',   document.getElementById('ai-scene-count').value);
            formData.set('num_endings',     document.getElementById('ai-num-endings').value);
            formData.set('word_length',     document.getElementById('ai-word-length').value);
            formData.set('ai-audience',     document.getElementById('ai-audience').value);
            // Image settings — "Include Images" unchecked maps to quality 'none' (no image jobs)
            var includeImages = document.getElementById('ai-include-images').checked;
            formData.set('ai-include-images', includeImages ? '1' : '');
            formData.set('image_quality',   includeImages ? document.getElementById('ai-image-quality').value : 'none');
            // Category is no longer a separate field — the single style dropdown carries it.
            formData.set('ai-image-category', '');
            formData.set('ai-image-style',    includeImages ? document.getElementById('ai-image-style').value : '');
            formData.set('ai-image-mood',     includeImages ? document.getElementById('ai-image-mood').value    : '');
            formData.set('gen_title',       generateFields.indexOf('title')       !== -1 ? '1' : '0');
            formData.set('gen_description', generateFields.indexOf('description') !== -1 ? '1' : '0');
            formData.set('gen_theme',       generateFields.indexOf('theme')       !== -1 ? '1' : '0');
            var cbGenres = document.getElementById('cb-genres');
            formData.set('gen_genres',      (cbGenres && cbGenres.checked) ? '1' : '0');
            // Publish only when images are included (image-less stories aren't published).
            var pubCb  = document.getElementById('ai-publish');
            var pubInc = document.getElementById('ai-include-images');
            formData.set('publish', (pubInc && pubInc.checked && pubCb && pubCb.checked) ? '1' : '0');

            var btn = document.getElementById('btn-create-story');
            btn.disabled = true;
            btn.textContent = 'Submitting…';

            fetch('api_create_story_ai.php', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    Modal.success({
                        heading: 'Story Submitted!',
                        message: 'The AI is crafting your adventure. You\'ll find it in your stories once it\'s ready.',
                        okLabel: 'Got it',
                        jobQueue: true,
                        onClose: function () { window.location = 'index.php'; }
                    });
                } else {
                    Modal.alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = 'Create Story';
                }
            })
            .catch(function () {
                Modal.alert('Request failed. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Create Story';
            });
        };
        </script>
        <?php endif; ?>


<?php elseif ($view === 'scene_form'): ?>
<!-- ================================================================
     VIEW: SCENE FORM (Create / Edit)
     ================================================================ -->
<?php
    // Get all scenes in this story (for the destination dropdown)
    $allScenes = get_scenes_by_story($storyID);
?>
        <div class="editor-header">
            <h2><?php echo ($editScene && !$isNew) ? 'Edit Scene' : 'Create New Scene'; ?></h2>
            <div style="display:flex; gap:0.5rem;">
                <button type="button" id="btn-tree-view" class="btn btn-secondary btn-sm">Tree View</button>
                <a href="editor.php?storyID=<?php echo $storyID; ?>" class="btn btn-secondary btn-sm">&larr; Back to Story</a>
            </div>
        </div>

        <div class="editor-form">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <h3 style="margin:0;"><?php echo htmlspecialchars($story['title']); ?></h3>
                    <?php if ($editScene && (bool)(int) app_setting('ai_enabled')): ?>
                    <button type="button" class="btn-ai-inline" id="btn-scene-ai"
                            onclick="aiGuard('text') && openSceneAIModal()"
                            title="<?php echo $isNew ? 'Generate With AI' : 'Regenerate with AI'; ?>">&#10022; Use AI</button>
                    <?php endif; ?>
                </div>
                <?php if ($editScene): ?>
                    <button type="button" class="btn btn-play btn-sm" onclick="saveAndPlay()">Play</button>
                <?php endif; ?>
            </div>

            <form method="POST" enctype="multipart/form-data" id="scene-form">
                <input type="hidden" name="action" value="save_scene">
                <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                <?php if ($editScene): ?>
                    <input type="hidden" name="sceneID" value="<?php echo (int)$editScene['sceneID']; ?>">
                <?php endif; ?>
                <?php if ($isNew): ?>
                    <input type="hidden" name="is_new" value="1">
                <?php endif; ?>

                <div class="form-group">
                    <label for="sp_title">Scene Title</label>
                    <input type="text" id="sp_title" name="sp_title"
                           value="<?php echo htmlspecialchars($editScene['title'] ?? ''); ?>"
                           required maxlength="255" placeholder="e.g., The Dark Chamber">
                </div>

                <div class="form-group">
                    <label>Story Text / Description</label>
                    <input type="hidden" id="sp_description" name="sp_description"
                           value="<?php echo htmlspecialchars($editScene['description'] ?? ''); ?>">
                    <div id="sp-description-quill" class="quill-editor"></div>
                </div>

                <div class="form-group">
                    <label for="sp_image">Scene Image</label>
                    <div style="display:flex; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
                        <img id="sp-image-preview"
                             src="<?php echo ($editScene && !empty($editScene['image'])) ? htmlspecialchars(editor_img_url($storyID, $story['published_story_id'] ?? null, $editScene['image'])) : ''; ?>"
                             alt="Image preview"
                             title="View full image"
                             onclick="enlargeScenePreview(this)"
                             style="width:170px; height:170px; object-fit:cover; border-radius:var(--radius); border:1px solid var(--border); image-rendering:crisp-edges; cursor:zoom-in; <?php echo ($editScene && !empty($editScene['image'])) ? '' : 'display:none;'; ?>">
                        <div style="display:flex; flex-direction:column; gap:0.5rem;">
                            <?php if ($editScene && !empty($editScene['image'])): ?>
                                <p style="margin:0; font-size:0.85rem; color:var(--text-light);">Upload a new file to replace the current image.</p>
                            <?php endif; ?>
                            <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                <input type="file" id="sp_image" name="sp_image" accept="image/*"
                                       onchange="previewImage(this, 'sp-image-preview')">
                                <input type="hidden" name="remove_sp_image" id="remove_sp_image" value="">
                                <?php if ($editScene && !empty($editScene['image'])): ?>
                                <button type="button" id="btn-remove-scene-img" class="btn btn-secondary btn-sm"
                                        onclick="removeSceneImage()">Remove image</button>
                                <?php endif; ?>
                                <?php if ($editScene && (bool)(int) app_setting('ai_enabled')): ?>
                                <button type="button" id="btn-scene-thumb-ai" class="btn-ai-inline"
                                        onclick="aiGuard('image') && toggleSceneThumbAI()" title="Generate image with AI">&#10022; Use AI</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($editScene && (bool)(int) app_setting('ai_enabled')): ?>
                            <div id="scene-thumb-ai-expand" class="thumb-ai-expand" hidden>
                                <textarea id="scene-thumb-ai-prompt" rows="2" maxlength="1000"
                                          placeholder="Describe the scene image, or leave blank to use the scene description..."
                                          class="ai-inline-textarea"></textarea>
                                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-top:0.5rem;">
                                    <select id="scene-thumb-ai-quality" class="ai-inline-select">
                                        <option value="low">Low quality</option>
                                        <option value="medium" selected>Medium quality</option>
                                        <option value="high">High quality</option>
                                    </select>
                                </div>
                                <?php render_inline_image_style_controls('scene'); ?>
                                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem;">
                                    <button id="scene-thumb-ai-btn" type="button" class="btn btn-primary btn-sm"
                                            onclick="submitSceneImageJob()">Generate Image</button>
                                    <span id="scene-thumb-ai-status" style="font-size:0.875rem;"></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Full-size image modal for the scene preview (matches the story-edit thumbnails) -->
                    <div id="scene-img-modal" class="scene-img-modal" hidden>
                        <button type="button" class="scene-img-modal-close" aria-label="Close">&times;</button>
                        <img id="scene-img-modal-img" src="" alt="">
                    </div>
                    <script>
                    (function () {
                        var modal = document.getElementById('scene-img-modal');
                        if (!modal) return;
                        var modalImg = document.getElementById('scene-img-modal-img');
                        var closeBtn = modal.querySelector('.scene-img-modal-close');
                        window.enlargeScenePreview = function (img) {
                            if (!img || !img.getAttribute('src')) return;  // no image yet
                            modalImg.src = img.src;
                            modal.hidden = false;
                        };
                        function closeModal() { modal.hidden = true; modalImg.src = ''; }
                        closeBtn.addEventListener('click', closeModal);
                        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
                        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
                    })();
                    </script>
                </div>

                <input type="hidden" name="sp_image_gen" value="<?php echo htmlspecialchars($editScene['image_gen'] ?? ''); ?>">

                <div class="form-group">
                    <label for="sp_hint">Hint (optional)</label>
                    <input type="text" id="sp_hint" name="sp_hint"
                           value="<?php echo htmlspecialchars($editScene['hint'] ?? ''); ?>"
                           maxlength="512" placeholder="A helpful hint for the player...">
                </div>

                <!-- Choices Section -->
                <div class="choices-editor">
                    <h4>Choices</h4>
                    <p style="font-size:0.85rem; color:var(--text-light); margin-bottom:0.75rem;">
                        Add choices that lead to other scenes. Leave empty for an ending scene.
                    </p>
                    <div id="choices-container">
                        <?php
                        $existingChoices = $editScene ? ($editScene['choices'] ?? array()) : array();
                        if (!empty($existingChoices)):
                            foreach ($existingChoices as $idx => $ch):
                        ?>
                        <div class="choice-row">
                            <input type="text" name="choice_text[]"
                                   value="<?php echo htmlspecialchars($ch['text']); ?>"
                                   placeholder="Choice text..." maxlength="255">
                            <select name="choice_dest[]">
                                <option value="0">-- Select destination --</option>
                                <?php foreach ($allScenes as $sp): ?>
                                    <option value="<?php echo (int)$sp['sceneID']; ?>"
                                        <?php echo ($ch['dest'] == $sp['sceneID']) ? 'selected' : ''; ?>>
                                        #<?php echo (int)$sp['sceneID']; ?> - <?php echo htmlspecialchars(substr($sp['title'], 0, 40)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-remove" onclick="this.parentElement.remove()">&times;</button>
                        </div>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </div>

                    <?php
                    $autoBackChecked = $editScene ? (!empty($editScene['enable_autoBack_nav'])) : true;
                    $hasChoices = !empty($existingChoices);
                    ?>
                    <div id="autoback-option" style="margin-top:0.75rem;<?php echo $hasChoices ? '' : ' display:none;'; ?>">
                        <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.9rem; cursor:pointer;">
                            <input type="checkbox" name="enable_autoBack_nav" value="1"
                                   id="enable_autoBack_nav"
                                   <?php echo $autoBackChecked ? 'checked' : ''; ?>>
                            Enable automatic back navigation
                        </label>
                    </div>

                    <button type="button" id="add-choice-btn" class="btn btn-secondary btn-sm" style="margin-top:1.25rem;">+ Add Choice</button>
                </div>

            </form>

            <div class="form-actions" style="display:flex; justify-content:space-between; align-items:center; margin-top:3rem;">
                <div style="display:flex; gap:0.5rem;">
                    <button type="submit" form="scene-form" class="btn btn-primary">
                        <?php echo ($editScene && !$isNew) ? 'Save Changes' : 'Create Scene'; ?>
                    </button>
                    <?php if ($isNew): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="discard_scene">
                            <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                            <input type="hidden" name="sceneID" value="<?php echo (int)$editScene['sceneID']; ?>">
                            <button type="button" class="btn btn-secondary"
                                    onclick="Modal.confirmDanger({heading:'Discard New Scene?', message:'This unsaved scene will be discarded.', confirmLabel:'Discard', onConfirm: () => this.closest('form').submit()})">Discard</button>
                        </form>
                    <?php else: ?>
                        <a href="editor.php?storyID=<?php echo $storyID; ?>" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
                <?php if ($editScene): ?>
                    <button type="button" class="btn btn-play btn-sm" onclick="saveAndPlay()">Play</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($editScene && (bool)(int) app_setting('ai_enabled')): ?>
        <!-- Scene AI Modal -->
        <div id="scene-ai-modal" hidden
             onclick="if(event.target===this)closeSceneAIModal()">
            <div class="scene-ai-modal-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h3 style="margin:0;">&#10022; Generate Scene with AI</h3>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeSceneAIModal()">&times;</button>
                </div>

                <?php if (!empty($editScene['description']) || !empty($editScene['title'])): ?>
                <div style="background:var(--warning-bg,#fff8e1); border:1px solid var(--warning,#f59e0b); border-radius:var(--radius); padding:0.75rem; margin-bottom:1rem; font-size:0.875rem;">
                    <strong>Warning:</strong> This will replace the current scene title, description, hint, and choices.
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="modal-ai-direction">Scene Direction</label>
                    <textarea id="modal-ai-direction" rows="3" maxlength="1000"
                              placeholder="e.g. The player discovers a hidden passage behind the altar and must decide whether to explore it..."
                              style="resize:vertical;"></textarea>
                </div>

                <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:140px;">
                        <label for="modal-ai-mode">Mode</label>
                        <select id="modal-ai-mode" onchange="toggleSceneModalOptions()">
                            <option value="continue">Continue story</option>
                            <option value="ending">Ending scene</option>
                        </select>
                    </div>
                    <div class="form-group" id="modal-ending-type-group" style="flex:1; min-width:140px; display:none;">
                        <label for="modal-ai-ending-type">Ending Type</label>
                        <select id="modal-ai-ending-type">
                            <option value="success">Success / survival</option>
                            <option value="death">Death / failure</option>
                        </select>
                    </div>
                    <div class="form-group" id="modal-num-choices-group" style="flex:1; min-width:140px;">
                        <label for="modal-ai-num-choices">Choices</label>
                        <select id="modal-ai-num-choices">
                            <option value="2" selected>2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width:140px;">
                        <label for="modal-ai-tone">Tone</label>
                        <select id="modal-ai-tone">
                            <option value="dark">Dark</option>
                            <option value="hopeful">Hopeful</option>
                            <option value="humorous">Humorous</option>
                            <option value="neutral">Neutral</option>
                            <option value="suspenseful" selected>Suspenseful</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width:140px;">
                        <label for="modal-ai-word-length">Length</label>
                        <select id="modal-ai-word-length">
                            <option value="75">Short (~75 words)</option>
                            <option value="150" selected>Medium (~150 words)</option>
                            <option value="250">Long (~250 words)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.9rem; <?php echo $aiHasOpenai ? 'cursor:pointer;' : 'opacity:0.55; cursor:not-allowed;'; ?>">
                        <input type="checkbox" id="modal-ai-gen-image" <?php echo $aiHasOpenai ? 'checked' : 'disabled'; ?> onchange="toggleSceneModalImageStyle()">
                        Also generate a scene image
                        <?php if (!$aiHasOpenai): ?>
                        <span style="font-size:0.8rem; color:var(--text-light);">— add an OpenAI key to your <a href="account.php">account</a> for images</span>
                        <?php endif; ?>
                    </label>
                    <div id="modal-ai-image-style" style="margin-top:0.5rem;" <?php echo $aiHasOpenai ? '' : 'hidden'; ?>>
                        <?php render_inline_image_style_controls('modal'); ?>
                    </div>
                </div>

                <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                    <button id="modal-ai-scene-btn" type="button" class="btn btn-primary"
                            onclick="submitSceneJob()">Generate Scene</button>
                    <button type="button" class="btn btn-secondary" onclick="closeSceneAIModal()">Cancel</button>
                    <span id="modal-ai-scene-status" style="font-size:0.875rem;"></span>
                </div>
            </div>
        </div>

        <script>
        function openSceneAIModal() {
            document.getElementById('scene-ai-modal').hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function closeSceneAIModal() {
            document.getElementById('scene-ai-modal').hidden = true;
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !document.getElementById('scene-ai-modal').hidden) {
                closeSceneAIModal();
            }
        });

        function toggleSceneModalOptions() {
            var mode = document.getElementById('modal-ai-mode').value;
            document.getElementById('modal-ending-type-group').style.display  = mode === 'ending'   ? '' : 'none';
            document.getElementById('modal-num-choices-group').style.display  = mode === 'continue' ? '' : 'none';
        }

        function toggleSceneModalImageStyle() {
            var cb  = document.getElementById('modal-ai-gen-image');
            var box = document.getElementById('modal-ai-image-style');
            if (box) box.hidden = !cb.checked;
        }

        function submitSceneJob() {
            var direction = document.getElementById('modal-ai-direction').value.trim();
            if (!direction) {
                document.getElementById('modal-ai-scene-status').textContent = 'Please enter a scene direction first.';
                return;
            }

            var mode       = document.getElementById('modal-ai-mode').value;
            var tone       = document.getElementById('modal-ai-tone').value;
            var numChoices = document.getElementById('modal-ai-num-choices').value;
            var endingType = document.getElementById('modal-ai-ending-type').value;
            var genImage   = document.getElementById('modal-ai-gen-image').checked;
            var wordLength = parseInt(document.getElementById('modal-ai-word-length').value, 10);

            var btn    = document.getElementById('modal-ai-scene-btn');
            var status = document.getElementById('modal-ai-scene-status');
            btn.disabled = true;
            status.textContent = 'Queueing job…';
            status.style.color = 'var(--text-light)';

            var inputData = {
                direction:      direction,
                mode:           mode,
                tone:           tone,
                num_choices:    parseInt(numChoices, 10),
                word_length:    wordLength,
                generate_image: genImage
            };
            if (mode === 'ending') {
                inputData.ending_type = endingType;
            }
            if (genImage && window.inlineStyleParams) {
                var sp = window.inlineStyleParams('modal');
                inputData.image_category = sp.image_category;
                inputData.image_style    = sp.image_style;
                inputData.image_mood     = sp.image_mood;
            }

            var body = new URLSearchParams({
                action:     'create',
                job_type:   'scene',
                story_id:   '<?php echo (int)$storyID; ?>',
                scene_id:   '<?php echo (int)$editScene["sceneID"]; ?>',
                input_json: JSON.stringify(inputData)
            });

            fetch('api_jobs.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString()
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    status.textContent = '';
                    document.getElementById('modal-ai-direction').value = '';
                    btn.disabled = false;
                    closeSceneAIModal();
                    Modal.success({
                        heading: 'Scene Job Started!',
                        message: 'The AI is writing your scene. It will appear once the job completes.',
                        okLabel: 'Got it',
                        jobQueue: true
                    });
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Unknown error');
                    status.style.color = 'var(--danger, red)';
                    btn.disabled = false;
                }
            })
            .catch(function() {
                status.textContent = 'Request failed. Please try again.';
                status.style.color = 'var(--danger, red)';
                btn.disabled = false;
            });
        }

        function toggleSceneThumbAI() {
            var expand = document.getElementById('scene-thumb-ai-expand');
            expand.hidden = !expand.hidden;
        }

        function submitSceneImageJob() {
            var prompt  = document.getElementById('scene-thumb-ai-prompt').value.trim();
            var btn     = document.getElementById('scene-thumb-ai-btn');
            var status  = document.getElementById('scene-thumb-ai-status');
            var quality = document.getElementById('scene-thumb-ai-quality').value;

            // Fall back to scene description (plain text) then scene title when prompt is blank
            if (!prompt) {
                prompt = <?php echo json_encode(trim(strip_tags($editScene['description'] ?? ''))); ?>;
            }
            if (!prompt) {
                prompt = <?php echo json_encode($editScene['title'] ?? ''); ?>;
            }
            if (!prompt) {
                status.textContent = 'Enter an image description, or fill in the scene description first.';
                status.style.color = 'var(--danger, red)';
                return;
            }

            var sp = window.inlineStyleParams('scene');
            if (sp._quality) quality = sp._quality;

            btn.disabled = true;
            status.textContent = 'Queueing job…';
            status.style.color = 'var(--text-light)';

            var inputJson = JSON.stringify({
                prompt:  prompt,
                quality: quality,
                image_category: sp.image_category,
                image_style:    sp.image_style,
                image_mood:     sp.image_mood
            });

            var body = new URLSearchParams({
                action:     'create',
                job_type:   'image',
                story_id:   '<?php echo (int)$storyID; ?>',
                scene_id:   '<?php echo (int)$editScene["sceneID"]; ?>',
                input_json: inputJson
            });

            fetch('api_jobs.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString()
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    status.textContent = '';
                    document.getElementById('scene-thumb-ai-prompt').value = '';
                    btn.disabled = false;
                    Modal.success({
                        heading: 'Image Job Started!',
                        message: 'The AI is generating the scene image. Refresh once the job completes to see it.',
                        okLabel: 'Got it',
                        jobQueue: true
                    });
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Unknown error');
                    status.style.color = 'var(--danger, red)';
                    btn.disabled = false;
                }
            })
            .catch(function() {
                status.textContent = 'Request failed. Please try again.';
                status.style.color = 'var(--danger, red)';
                btn.disabled = false;
            });
        }
        </script>
        <?php endif; ?>

        <!-- Quill init -->
        <script>
        (function () {
            var hidden = document.getElementById('sp_description');
            var quill  = new Quill('#sp-description-quill', {
                theme: 'snow',
                placeholder: 'Write the narrative for this story point…',
                modules: {
                    toolbar: [
                        [{ header: [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'clean']
                    ]
                }
            });
            if (hidden.value) {
                quill.clipboard.dangerouslyPasteHTML(0, hidden.value);
            }
            document.getElementById('scene-form').addEventListener('submit', function () {
                hidden.value = quill.root.innerHTML;
            });
            window.__quillInstance = quill;
        })();
        </script>

        <!-- JavaScript for dynamic choice rows -->
        <script>
        function saveAndPlay() {
            if (window.__quillInstance) {
                document.getElementById('sp_description').value = window.__quillInstance.root.innerHTML;
            }
            var form = document.getElementById('scene-form');
            var playUrl = 'play.php?storyID=<?php echo $storyID; ?>&id=<?php echo $editScene ? (int)$editScene['sceneID'] : 0; ?>';
            var formData = new FormData(form);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(function() {
                window.location.href = playUrl;
            });
        }
        function updateAutoBackVisibility() {
            var container = document.getElementById('choices-container');
            var autoBackOption = document.getElementById('autoback-option');
            var hasChoices = container.querySelectorAll('.choice-row').length > 0;
            autoBackOption.style.display = hasChoices ? '' : 'none';
        }

        // Observe choice removals
        var choicesContainer = document.getElementById('choices-container');
        var observer = new MutationObserver(updateAutoBackVisibility);
        observer.observe(choicesContainer, { childList: true });

        document.getElementById('add-choice-btn').addEventListener('click', function() {
            var container = document.getElementById('choices-container');
            var row = document.createElement('div');
            row.className = 'choice-row';

            // Build the destination options from PHP data
            var scenes = <?php echo json_encode(array_map(function($sp) {
                return ['id' => (int)$sp['sceneID'], 'title' => substr($sp['title'], 0, 40)];
            }, $allScenes)); ?>;

            var options = '<option value="0">-- Select destination --</option>';
            scenes.forEach(function(sp) {
                options += '<option value="' + sp.id + '">#' + sp.id + ' - ' + sp.title.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
            });

            row.innerHTML =
                '<input type="text" name="choice_text[]" placeholder="Choice text..." maxlength="255">' +
                '<select name="choice_dest[]">' + options + '</select>' +
                '<button type="button" class="btn-remove" onclick="this.parentElement.remove()">&times;</button>';

            container.appendChild(row);
        });
        </script>

<?php endif; ?>

    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>

    <script>
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

    // Stage cover/scene image removal (applied on save). A blank image is
    // sometimes preferable to a wrong one while editing.
    function removeCoverImage() {
        Modal.confirmDanger({
            heading: 'Remove image?',
            message: 'The cover image will be cleared when you save the story. You can upload or generate a new one anytime.',
            confirmLabel: 'Remove',
            onConfirm: function () {
                var rf = document.getElementById('remove_image');     if (rf) rf.value = '1';
                var p  = document.getElementById('image-preview');    if (p)  { p.removeAttribute('src'); p.style.display = 'none'; }
                var f  = document.getElementById('image');            if (f)  f.value = '';
                var b  = document.getElementById('btn-remove-cover'); if (b)  b.style.display = 'none';
            }
        });
    }
    function removeSceneImage() {
        Modal.confirmDanger({
            heading: 'Remove image?',
            message: 'The scene image will be cleared when you save the scene. You can upload or generate a new one anytime.',
            confirmLabel: 'Remove',
            onConfirm: function () {
                var rf = document.getElementById('remove_sp_image');      if (rf) rf.value = '1';
                var p  = document.getElementById('sp-image-preview');     if (p)  { p.removeAttribute('src'); p.style.display = 'none'; }
                var f  = document.getElementById('sp_image');             if (f)  f.value = '';
                var b  = document.getElementById('btn-remove-scene-img'); if (b)  b.style.display = 'none';
            }
        });
    }
    </script>

    <?php if (!empty($scenes) || !empty($allScenes)): ?>
    <!-- Phase 31 — Story Tree View (story overview + scene editor) -->
    <script src="tree-view.js"></script>
    <script>
    (function () {
        var btn = document.getElementById('btn-tree-view');
        if (!btn) return;
        var STORY_ID = <?php echo (int)$storyID; ?>;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            TreeView.openModal(STORY_ID).finally(function () { btn.disabled = false; });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ((bool)(int) app_setting('ai_enabled')): ?>
    <!-- AI key gating — block "Use AI" badges when the required provider key is missing -->
    <script>
    window.AI_HAS_CLAUDE = <?php echo $aiHasClaude ? 'true' : 'false'; ?>;
    window.AI_HAS_OPENAI = <?php echo $aiHasOpenai ? 'true' : 'false'; ?>;

    // Returns true if the action's required provider key is available; otherwise
    // shows an informative modal pointing the user to their account settings and
    // returns false (so `aiGuard(...) && doThing()` short-circuits).
    function aiGuard(type) {
        if (type === 'text'  && window.AI_HAS_CLAUDE) return true;
        if (type === 'image' && window.AI_HAS_OPENAI) return true;

        var msg;
        if (!window.AI_HAS_CLAUDE && !window.AI_HAS_OPENAI) {
            msg = 'To use AI features, add your own API keys to your account — a ' +
                  '<strong>Claude (Anthropic)</strong> key for stories and scenes, and an ' +
                  '<strong>OpenAI</strong> key for images.';
        } else if (type === 'text') {
            msg = 'Story and scene generation needs a <strong>Claude (Anthropic)</strong> ' +
                  'API key. Add one in your account settings.';
        } else {
            msg = 'Image generation needs an <strong>OpenAI</strong> API key. ' +
                  'Add one in your account settings.';
        }

        Modal.open({
            title: 'API key required',
            body:  '<p style="margin:0 0 0.5rem;">' + msg + '</p>',
            buttons: [
                { label: 'Go to Account Settings', className: 'btn-primary',
                  action: function () { window.location.href = 'account.php'; } },
                { label: 'Close', className: 'btn-secondary', action: function () { Modal.close(); } }
            ]
        });
        return false;
    }
    </script>
    <?php endif; ?>
</body>
</html>
