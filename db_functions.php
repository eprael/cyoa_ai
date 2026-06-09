<?php
/**
 * Database Functions for CYOA Maker
 *
 * Complete CRUD functions for users, stories, scenes, and choices
 */

require_once __DIR__ . '/data.php';   // load_data_json() for JSON reference data

// ==========================================
// REFERENCE DATA ACCESSORS
// ==========================================

/**
 * Story audience map (Phase 39) — key => ['label', 'complexity'].
 * Sourced from data/audiences.json. Consumed by resolve_audience() (the AI
 * prompt builder), the editor audience dropdown, and api_create_story_ai.php.
 */
function story_audiences(): array {
    return load_data_json('audiences')['audiences'] ?? [];
}

/**
 * The validated story tones used across the app (UI dropdown + AI hint).
 */
function ai_story_tones(): array {
    return ['suspenseful', 'hopeful', 'dark', 'humorous', 'neutral'];
}

/**
 * Pick a random premise seed from data/premises.json.
 *   - $genre = '' draws from the WHOLE list; a specific genre filters to seeds
 *     tagged with it (falling back to the full list if none match).
 *   - $excludePremises lets a batch run avoid reusing the same seed ("without
 *     replacement"); the exclusion is skipped if it would empty the pool.
 * Returns a seed array {premise, genres[], tone} or [] when the file is empty.
 */
function ai_pick_seed(string $genre = '', array $excludePremises = []): array {
    $list = load_data_json('premises');
    if (!is_array($list) || !$list) return [];

    if ($genre !== '') {
        $filtered = array_values(array_filter($list, fn($s) => in_array($genre, $s['genres'] ?? [], true)));
        if ($filtered) $list = $filtered;
    }
    if ($excludePremises) {
        $fresh = array_values(array_filter($list, fn($s) => !in_array($s['premise'] ?? '', $excludePremises, true)));
        if ($fresh) $list = $fresh;   // only narrow when something remains
    }
    return $list[array_rand($list)];
}

/**
 * Resolve AI story-creation parameters, expanding "Any" (an empty/unknown value)
 * for genre, tone, and audience into concrete random choices — done in code before
 * the job is queued. Rules:
 *   - audience "Any"  → a random audience.
 *   - blank premise   → pulled from a seed (data/premises.json). When genre is
 *     "Any" the seed is drawn from the WHOLE list and the genre is taken from the
 *     seed; otherwise the seed is filtered to the chosen genre. An unset tone takes
 *     the seed's tone.
 *   - explicit premise + "Any" genre/tone → a random genre / tone.
 * $usedPremises supports batch de-dup (passed through to ai_pick_seed).
 *
 * @param array $in  Keys: premise, genre, tone, audience (any may be '' = Any).
 * @return array{premise:string,genre:string,tone:string,audience:string,premise_source:string}
 */
function ai_resolve_story_params(array $in, array $usedPremises = []): array {
    $genres = json_decode(app_setting('story_genres') ?? '[]', true) ?: ['Adventure'];
    // "Other" is a catch-all with no seeds — exclude it from random genre draws.
    $randGenres = array_values(array_filter($genres, fn($g) => strcasecmp($g, 'Other') !== 0)) ?: $genres;
    $tones = ai_story_tones();
    $auds  = array_keys(story_audiences()) ?: ['middle_grade'];

    $premise = trim((string)($in['premise'] ?? ''));
    $genre   = trim((string)($in['genre']    ?? ''));   // '' = Any
    $tone    = trim((string)($in['tone']     ?? ''));   // '' = Any
    $aud     = trim((string)($in['audience'] ?? ''));   // '' = Any

    // Treat unknown explicit values as "Any" so a stale option can't slip through.
    if ($genre !== '' && !in_array($genre, $genres, true)) $genre = '';
    if ($tone  !== '' && !in_array($tone,  $tones,  true)) $tone  = '';
    if ($aud   !== '' && !in_array($aud,   $auds,   true)) $aud   = '';

    // Audience is independent of the premise/seed.
    if ($aud === '') $aud = $auds[array_rand($auds)];

    $source = 'user';
    if ($premise === '') {
        $source = 'seed';
        $seed = ai_pick_seed($genre, $usedPremises);   // genre '' => whole list
        $premise    = $seed['premise'] ?? '';
        $seedGenres = $seed['genres']  ?? [];
        if ($genre === '' || (!empty($seedGenres) && !in_array($genre, $seedGenres, true))) {
            $genre = $seedGenres[0] ?? $genre;
        }
        if ($tone === '') $tone = (string)($seed['tone'] ?? '');
    }

    // Final fallbacks (explicit premise with "Any" genre/tone, or an empty seed file).
    if ($genre === '') $genre = $randGenres[array_rand($randGenres)];
    if ($tone  === '') $tone  = $tones[array_rand($tones)];

    return [
        'premise'        => $premise,
        'genre'          => $genre,
        'tone'           => $tone,
        'audience'       => $aud,
        'premise_source' => $source,
    ];
}

/**
 * Pick one random image style from all configured styles (flattened across the
 * category groups in the image_styles setting). Returns '' if none are configured.
 *
 * Used to resolve a blank / "Any" story image style into a single concrete style,
 * so every image in a story shares a consistent look. Shared by the web create-AI
 * endpoint and the CLI batch tool.
 */
function ai_random_image_style(): string {
    $byCat = json_decode(app_setting('image_styles') ?? '{}', true) ?: [];
    $all = [];
    foreach ($byCat as $subs) {
        foreach ((array)$subs as $s) {
            if ($s !== '') $all[] = $s;
        }
    }
    return $all ? $all[array_rand($all)] : '';
}

// ==========================================
// DATABASE CONNECTION
// ==========================================

/**
 * Get a database connection
 * @return mysqli Database connection object
 */
function db_connect() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// ==========================================
// USER FUNCTIONS
// ==========================================

/**
 * Register a new user
 * @return int|false New user ID or false on failure
 */
function register_user($firstName, $lastName, $email, $password, $profileImage = '', $claudeApiKey = '', $openaiApiKey = '') {
    $conn = db_connect();
    // Check if email already exists
    $stmt = $conn->prepare("SELECT userID FROM " . DB_PREFIX . "users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $conn->close();
        return false;
    }
    $stmt->close();

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $claudeApiKey  = $claudeApiKey  ?: null;
    $openaiApiKey  = $openaiApiKey  ?: null;
    $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "users (firstName, lastName, email, claude_api_key, openai_api_key, password, profileImage) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $firstName, $lastName, $email, $claudeApiKey, $openaiApiKey, $hashedPassword, $profileImage);
    $result = $stmt->execute();
    $newID = $result ? $conn->insert_id : false;
    $stmt->close();
    $conn->close();
    return $newID;
}

/**
 * Login user - verify email and password
 * @return array|false User data array or false on failure
 */
function login_user($email, $password) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT userID, firstName, lastName, email, password, profileImage, isAdmin FROM " . DB_PREFIX . "users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        return false;
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (password_verify($password, $user['password'])) {
        unset($user['password']);
        return $user;
    }
    return false;
}

/**
 * Get user by ID
 */
function get_user_by_id($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT userID, firstName, lastName, email, claude_api_key, openai_api_key, profileImage, isAdmin, created_date FROM " . DB_PREFIX . "users WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $user;
}

/**
 * Get user by email
 */
function get_user_by_email($email) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT userID, firstName, lastName, email, profileImage FROM " . DB_PREFIX . "users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $user;
}

/**
 * Update user password
 */
function update_user_password($userID, $newPassword) {
    $conn = db_connect();
    $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET password = ? WHERE userID = ?");
    $stmt->bind_param("si", $hashed, $userID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Update a user's own API keys.
 * Empty string clears the value → stored as NULL (falls back to site default).
 */
function update_user_api_keys($userID, $claudeApiKey, $openaiApiKey) {
    $conn = db_connect();
    $claudeApiKey = $claudeApiKey ?: null;
    $openaiApiKey = $openaiApiKey ?: null;
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET claude_api_key = ?, openai_api_key = ? WHERE userID = ?");
    $stmt->bind_param("ssi", $claudeApiKey, $openaiApiKey, $userID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

// ==========================================
// PASSWORD RESET FUNCTIONS
// ==========================================

/**
 * Ensure password resets table exists
 */
function ensure_password_resets_table() {
    $conn = db_connect();
    $sql = "CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(256) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $conn->query($sql);
    $conn->close();
}

/**
 * Create a password reset token
 */
function create_password_reset($email, $token) {
    ensure_password_resets_table();
    $conn = db_connect();
    // Delete any existing tokens for this email
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    // Create new token (expires in 1 hour)
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "password_resets (email, token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $token, $expires);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Validate a reset token, return email if valid
 */
function validate_reset_token($token) {
    ensure_password_resets_table();
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT email FROM " . DB_PREFIX . "password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $email = $result->num_rows > 0 ? $result->fetch_assoc()['email'] : null;
    $stmt->close();
    $conn->close();
    return $email;
}

/**
 * Delete a reset token after use
 */
function delete_reset_token($token) {
    $conn = db_connect();
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// ==========================================
// STORY FUNCTIONS
// ==========================================

/**
 * Get all stories
 */
function get_all_stories($currentUserID = null, string $genre = '', string $sort = 'latest', bool $isAdmin = false) {
    $conn = db_connect();

    $allowedSorts = [
        'latest'   => 's.date_created DESC, s.storyID DESC',
        'rating'   => 'avg_rating DESC, s.date_created DESC, s.storyID DESC',
        'views'    => 'view_count DESC, s.date_created DESC, s.storyID DESC',
        'comments' => 'comment_count DESC, s.date_created DESC, s.storyID DESC',
    ];
    $orderBy = $allowedSorts[$sort] ?? $allowedSorts['latest'];

    // Subquery counts for sort columns
    $countCols = "(SELECT COUNT(*) FROM " . DB_PREFIX . "views    WHERE story_id = s.storyID) AS view_count,
                  (SELECT COUNT(*) FROM " . DB_PREFIX . "comments WHERE story_id = s.storyID) AS comment_count,
                  (SELECT AVG(rating) FROM " . DB_PREFIX . "ratings WHERE story_id = s.storyID) AS avg_rating";

    $genreClause = $genre !== '' ? " AND JSON_CONTAINS(s.genre, JSON_QUOTE(?))" : "";

    if ($isAdmin) {
        // Admins see every story — published or draft, any owner — so they can
        // review/manage the whole library from the gallery. Shadow drafts (editing
        // copies) and trashed stories stay hidden, same as for everyone else.
        $sql = "SELECT s.storyID, s.title, s.description, s.genre, s.image, s.theme, s.userID, s.created_by, s.date_created, s.status, s.published_story_id,
                       $countCols
                FROM " . DB_PREFIX . "stories s
                WHERE s.published_story_id IS NULL
                  AND s.status != 'deleted'
                  $genreClause
                ORDER BY $orderBy";
        $stmt = $conn->prepare($sql);
        if ($genre !== '') {
            $stmt->bind_param("s", $genre);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } elseif ($currentUserID) {
        // Show published stories to everyone + the current user's own standalone drafts.
        // Shadow drafts (published_story_id IS NOT NULL) are never shown in the gallery.
        $sql = "SELECT s.storyID, s.title, s.description, s.genre, s.image, s.theme, s.userID, s.created_by, s.date_created, s.status, s.published_story_id,
                       $countCols
                FROM " . DB_PREFIX . "stories s
                WHERE s.published_story_id IS NULL
                  AND (s.status = 'published' OR (s.status = 'draft' AND s.userID = ?))
                  $genreClause
                ORDER BY $orderBy";
        if ($genre !== '') {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $currentUserID, $genre);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $currentUserID);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $sql = "SELECT s.storyID, s.title, s.description, s.genre, s.image, s.theme, s.userID, s.created_by, s.date_created, s.status, s.published_story_id,
                       $countCols
                FROM " . DB_PREFIX . "stories s
                WHERE s.status = 'published' AND s.published_story_id IS NULL
                  $genreClause
                ORDER BY $orderBy";
        if ($genre !== '') {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $genre);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        } else {
            $result = $conn->query($sql);
        }
    }
    $stories = array();
    while ($row = $result->fetch_assoc()) {
        $row['genre'] = decode_story_genre($row['genre']);
        $stories[] = $row;
    }
    $conn->close();
    return $stories;
}

/**
 * Get stories by user
 */
function get_stories_by_user($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT storyID, title, description, genre, image, theme, userID, created_by, date_created, status, published_story_id
                            FROM " . DB_PREFIX . "stories
                            WHERE userID = ? AND published_story_id IS NULL AND status != 'deleted'
                            ORDER BY date_created DESC, storyID DESC");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $stories = array();
    while ($row = $result->fetch_assoc()) {
        $row['genre'] = decode_story_genre($row['genre']);
        $stories[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $stories;
}

/**
 * Search published stories (plus the current user's own standalone drafts) by title / description.
 */
function search_stories($query, $currentUserID = null, bool $isAdmin = false) {
    $conn = db_connect();
    $like = '%' . $conn->real_escape_string($query) . '%';
    if ($isAdmin) {
        // Admins search the whole library — published or draft, any owner.
        $stmt = $conn->prepare(
            "SELECT storyID, title, description, genre, image, theme, userID, created_by, date_created, status, published_story_id
             FROM " . DB_PREFIX . "stories
             WHERE published_story_id IS NULL
               AND status != 'deleted'
               AND (title LIKE ? OR description LIKE ?)
             ORDER BY date_created DESC, storyID DESC"
        );
        $stmt->bind_param("ss", $like, $like);
    } elseif ($currentUserID) {
        $stmt = $conn->prepare(
            "SELECT storyID, title, description, genre, image, theme, userID, created_by, date_created, status, published_story_id
             FROM " . DB_PREFIX . "stories
             WHERE published_story_id IS NULL
               AND (status = 'published' OR (status = 'draft' AND userID = ?))
               AND (title LIKE ? OR description LIKE ?)
             ORDER BY date_created DESC, storyID DESC"
        );
        $stmt->bind_param("iss", $currentUserID, $like, $like);
    } else {
        $stmt = $conn->prepare(
            "SELECT storyID, title, description, genre, image, theme, userID, created_by, date_created, status, published_story_id
             FROM " . DB_PREFIX . "stories
             WHERE status = 'published' AND published_story_id IS NULL
               AND (title LIKE ? OR description LIKE ?)
             ORDER BY date_created DESC, storyID DESC"
        );
        $stmt->bind_param("ss", $like, $like);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stories = [];
    while ($row = $result->fetch_assoc()) {
        $row['genre'] = decode_story_genre($row['genre']);
        $stories[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $stories;
}

/**
 * Get an array of story IDs favourited by a user (lightweight — IDs only).
 */
function get_user_favorite_ids($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT story_id FROM " . DB_PREFIX . "favorites WHERE user_id = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['story_id'];
    }
    $stmt->close();
    $conn->close();
    return $ids;
}

/**
 * Get a single story by ID
 */
function get_story($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT storyID, title, description, genre, ai_image_category, ai_image_style, ai_image_mood, ai_image_quality, image, theme, theme_json, layout, userID, created_by, date_created, status, published_story_id
                            FROM " . DB_PREFIX . "stories WHERE storyID = ?");
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $result = $stmt->get_result();
    $story = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    if ($story !== null) {
        $story['genre'] = decode_story_genre($story['genre']);
    }
    return $story;
}

/**
 * Normalize a genre value (array of strings, single string, or null) into a JSON
 * array string for storage, or null when empty. (Phase 28 — multi-genre)
 */
function story_genres_to_json($genres): ?string {
    if (is_string($genres)) {
        $genres = ($genres === '') ? [] : [$genres];
    }
    if (!is_array($genres)) return null;
    $clean = array_values(array_filter(array_map('trim', $genres), fn($g) => $g !== ''));
    return $clean ? json_encode($clean) : null;
}

/**
 * Decode a stored genre value into an array of genre strings. Tolerates legacy
 * single-string values (pre-migration data). (Phase 28 — multi-genre)
 */
function decode_story_genre($value): array {
    if (empty($value)) return [];
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return $decoded;
    return [(string)$value];
}

/**
 * Create a new story
 * @param array|string|null $genres  Genre(s) to store as a JSON array
 * @return int|false New story ID or false on failure
 */
function create_story($title, $description, $image, $theme, $userID, $createdBy, $layout = 'image_left', $genres = null) {
    $conn = db_connect();
    $genre = story_genres_to_json($genres);
    $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "stories (title, description, genre, image, theme, layout, userID, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
    $stmt->bind_param("ssssssis", $title, $description, $genre, $image, $theme, $layout, $userID, $createdBy);
    $result = $stmt->execute();
    $newID = $result ? $conn->insert_id : false;
    $stmt->close();
    $conn->close();
    return $newID;
}

/**
 * Update a story (image is optional - pass null to keep existing)
 * @param array|string|null $genres  Genre(s) to store as a JSON array
 */
function update_story($storyID, $title, $description, $image, $theme, $layout = 'image_left', $genres = null) {
    $conn = db_connect();
    $genre = story_genres_to_json($genres);
    if ($image !== null) {
        $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "stories SET title = ?, description = ?, genre = ?, image = ?, theme = ?, layout = ? WHERE storyID = ?");
        $stmt->bind_param("ssssssi", $title, $description, $genre, $image, $theme, $layout, $storyID);
    } else {
        $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "stories SET title = ?, description = ?, genre = ?, theme = ?, layout = ? WHERE storyID = ?");
        $stmt->bind_param("sssssi", $title, $description, $genre, $theme, $layout, $storyID);
    }
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Update only the per-story AI image settings columns. (Phase 28)
 * Empty strings are stored as NULL.
 */
function update_story_image_settings(int $storyID, ?string $category, ?string $style, ?string $mood, ?string $quality): bool {
    $conn = db_connect();
    $category = ($category !== null && trim($category) !== '') ? trim($category) : null;
    $style    = ($style    !== null && trim($style)    !== '') ? trim($style)    : null;
    $mood     = ($mood     !== null && trim($mood)     !== '') ? trim($mood)     : null;
    $quality  = ($quality  !== null && trim($quality)  !== '') ? trim($quality)  : null;
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "stories SET ai_image_category = ?, ai_image_style = ?, ai_image_mood = ?, ai_image_quality = ? WHERE storyID = ?");
    $stmt->bind_param("ssssi", $category, $style, $mood, $quality, $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Phase 42 — Update only the theme_json column (the data-driven theme engine).
 * Pass a JSON string (already sanitized via theme_sanitize/theme_to_json) or null
 * to clear it (reverting the story to the legacy per-file theme).
 */
function update_story_theme_json(int $storyID, ?string $themeJson): bool {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "stories SET theme_json = ? WHERE storyID = ?");
    $stmt->bind_param("si", $themeJson, $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Update only the image column on a story (used by AI cover image apply).
 */
function update_story_image($storyID, $filename) {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "stories SET image = ? WHERE storyID = ?");
    $stmt->bind_param("si", $filename, $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Update story owner (admin only)
 */
function update_story_owner($storyID, $newOwnerID) {
    $conn = db_connect();
    $newOwner = get_user_by_id($newOwnerID);
    $createdBy = $newOwner ? $newOwner['firstName'] . ' ' . $newOwner['lastName'] : '';
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "stories SET userID = ?, created_by = ? WHERE storyID = ?");
    $stmt->bind_param("isi", $newOwnerID, $createdBy, $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Delete a story and all its related data (scenes + choices)
 */
function delete_story($storyID) {
    $conn = db_connect();

    // Delete all choices for scenes in this story
    $stmt = $conn->prepare("DELETE c FROM " . DB_PREFIX . "choices c
                            INNER JOIN " . DB_PREFIX . "scenes s ON c.sceneID = s.sceneID
                            WHERE s.storyID = ?");
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $stmt->close();

    // Delete all scenes
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "scenes WHERE storyID = ?");
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $stmt->close();

    // Delete the story
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "stories WHERE storyID = ?");
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $stmt->close();

    $conn->close();

    // Delete the entire image folder for this story
    $dir = 'images/stories/' . (int)$storyID;
    if (is_dir($dir)) {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($dir);
    }

    return true;
}

// ==========================================
// SCENE FUNCTIONS
// ==========================================

/**
 * Get a scene with all its associated choices
 */
function get_scene($id, $storyID = null) {
    $id = (int)$id;
    $conn = db_connect();

    if ($storyID !== null) {
        $storyID = (int)$storyID;
        $stmt = $conn->prepare("SELECT sceneID, title, description, image, image_gen, hint, storyID, enable_autoBack_nav
                                FROM " . DB_PREFIX . "scenes
                                WHERE sceneID = ? AND storyID = ?");
        $stmt->bind_param("ii", $id, $storyID);
    } else {
        $stmt = $conn->prepare("SELECT sceneID, title, description, image, image_gen, hint, storyID, enable_autoBack_nav
                                FROM " . DB_PREFIX . "scenes
                                WHERE sceneID = ?");
        $stmt->bind_param("i", $id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        return null;
    }

    $scene = $result->fetch_assoc();
    $stmt->close();

    // Fetch all choices for this scene
    $sceneID = (int)$scene['sceneID'];
    $stmt = $conn->prepare("SELECT choiceID, choiceText, destinationID
                            FROM " . DB_PREFIX . "choices
                            WHERE sceneID = ?
                            ORDER BY choiceID");
    $stmt->bind_param("i", $sceneID);
    $stmt->execute();
    $result = $stmt->get_result();

    $choices = array();
    while ($row = $result->fetch_assoc()) {
        $choices[] = array(
            'choiceID' => (int)$row['choiceID'],
            'text' => $row['choiceText'],
            'dest' => (int)$row['destinationID']
        );
    }

    $stmt->close();
    $conn->close();

    $scene['choices'] = $choices;
    return $scene;
}

/**
 * Get all scenes for a story with their choices attached (for the scene card list).
 * Uses 2 queries total instead of N+1.
 */
function get_scenes_with_choices_by_story($storyID) {
    $scenes = get_scenes_by_story($storyID);
    if (empty($scenes)) return $scenes;

    $conn = db_connect();
    $ids  = implode(',', array_map('intval', array_column($scenes, 'sceneID')));
    $result = $conn->query(
        "SELECT sceneID, choiceText, destinationID FROM " . DB_PREFIX . "choices
         WHERE sceneID IN ($ids) ORDER BY sceneID, choiceID"
    );
    $choicesByScene = [];
    while ($row = $result->fetch_assoc()) {
        $choicesByScene[(int)$row['sceneID']][] = ['text' => $row['choiceText'], 'dest' => (int)$row['destinationID']];
    }
    $conn->close();

    foreach ($scenes as &$scene) {
        $scene['choices'] = $choicesByScene[(int)$scene['sceneID']] ?? [];
    }
    return $scenes;
}

/**
 * Get all scenes for a story (without choices)
 */
function get_scenes_by_story($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT sceneID, storyID, title, description, image, image_gen, hint, enable_autoBack_nav
                            FROM " . DB_PREFIX . "scenes WHERE storyID = ? ORDER BY sceneID");
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $result = $stmt->get_result();
    $scenes = array();
    while ($row = $result->fetch_assoc()) {
        $scenes[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $scenes;
}

/**
 * v7 — Ordered image list for a story's gallery view.
 *
 * Returns the story cover first (when it has an image), then every scene that has
 * an image, in scene order. Scenes without an image are skipped — this is an image
 * gallery. Each item:
 *   ['type' => 'cover'|'scene', 'id' => int, 'title' => string, 'src' => string]
 *
 * Image folders are resolved shadow-draft-aware: an editing copy may still
 * reference the published story's image folder (mirrors summary.php / api_tree.php).
 * This is the single data seam for the gallery feature — a future global gallery
 * would aggregate across stories on top of it.
 */
function get_gallery_items($storyID) {
    $storyID = (int)$storyID;
    $story   = get_story($storyID);
    if (!$story) return [];

    $pubStoryID = !empty($story['published_story_id']) ? (int)$story['published_story_id'] : 0;

    // Resolve an image filename to a web path, preferring the story's own folder
    // and falling back to the published story's folder for shadow drafts.
    $resolve = function (string $filename) use ($storyID, $pubStoryID): ?string {
        if ($filename === '') return null;
        $ownRel = 'images/stories/' . $storyID . '/' . $filename;
        if ($pubStoryID && !file_exists(__DIR__ . '/' . $ownRel)) {
            return 'images/stories/' . $pubStoryID . '/' . $filename;
        }
        return $ownRel;
    };

    $items = [];

    $coverSrc = $resolve((string)($story['image'] ?? ''));
    if ($coverSrc !== null) {
        $items[] = [
            'type'  => 'cover',
            'id'    => 0,
            'title' => $story['title'] . ' (Cover)',
            'src'   => $coverSrc,
        ];
    }

    foreach (get_scenes_by_story($storyID) as $s) {
        $src = $resolve((string)($s['image'] ?? ''));
        if ($src === null) continue;
        $items[] = [
            'type'  => 'scene',
            'id'    => (int)$s['sceneID'],
            'title' => (string)$s['title'],
            'src'   => $src,
        ];
    }

    return $items;
}

/**
 * Count scenes for a story.
 */
function db_count_scenes(int $storyID): int {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM " . DB_PREFIX . "scenes WHERE storyID = ?");
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $conn->close();
    return $count;
}

/**
 * Create a new scene
 * @return int|false New scene ID or false on failure
 */
function create_scene($storyID, $title, $description, $image = null, $imageGen = null, $hint = null, $enableAutoBackNav = 1) {
    $conn = db_connect();
    $enableAutoBackNav = (int)$enableAutoBackNav;
    $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "scenes (storyID, title, description, image, image_gen, hint, enable_autoBack_nav) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssi", $storyID, $title, $description, $image, $imageGen, $hint, $enableAutoBackNav);
    $result = $stmt->execute();
    $newID = $result ? $conn->insert_id : false;
    $stmt->close();
    $conn->close();
    return $newID;
}

/**
 * Update a scene (image is optional - pass null to keep existing)
 */
function update_scene($sceneID, $title, $description, $image, $imageGen, $hint, $enableAutoBackNav = 1) {
    $conn = db_connect();
    $enableAutoBackNav = (int)$enableAutoBackNav;
    if ($image !== null) {
        $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "scenes SET title = ?, description = ?, image = ?, image_gen = ?, hint = ?, enable_autoBack_nav = ? WHERE sceneID = ?");
        $stmt->bind_param("sssssii", $title, $description, $image, $imageGen, $hint, $enableAutoBackNav, $sceneID);
    } else {
        $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "scenes SET title = ?, description = ?, image_gen = ?, hint = ?, enable_autoBack_nav = ? WHERE sceneID = ?");
        $stmt->bind_param("ssssii", $title, $description, $imageGen, $hint, $enableAutoBackNav, $sceneID);
    }
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Update only the content fields of a scene (used by AI scene apply).
 * Does not touch image, image_gen, or enable_autoBack_nav.
 */
function update_scene_content($sceneID, $title, $description, $hint) {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "scenes SET title = ?, description = ?, hint = ? WHERE sceneID = ?");
    $stmt->bind_param("sssi", $title, $description, $hint, $sceneID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Walk backward from a scene to the story root, following one incoming choice at each step.
 * Returns the path in root-to-scene order, each entry including the choice that led forward.
 * Used to build the previous_scenes context for AI scene generation.
 *
 * @param  int   $sceneID  The scene being generated (NOT included in the result)
 * @return array           [['title', 'description' (first 200 chars), 'choice_taken'], …]
 */
function get_scene_path_to_root(int $sceneID): array {
    $conn    = db_connect();
    $segments = [];
    $current  = $sceneID;
    $visited  = [];

    while (true) {
        if (isset($visited[$current])) break; // cycle guard
        $visited[$current] = true;

        // Find any choice that leads TO $current, and fetch that source scene's content
        $stmt = $conn->prepare(
            "SELECT c.sceneID AS parent_id, c.choiceText, s.title, s.description
             FROM " . DB_PREFIX . "choices c
             JOIN " . DB_PREFIX . "scenes s ON s.sceneID = c.sceneID
             WHERE c.destinationID = ?
             LIMIT 1"
        );
        $stmt->bind_param("i", $current);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) break; // no parent — $current is the opening scene

        $segments[] = [
            'title'        => $row['title'],
            'description'  => mb_substr($row['description'] ?? '', 0, 200),
            'choice_taken' => $row['choiceText'],
        ];
        $current = (int)$row['parent_id'];
    }

    $conn->close();
    return array_reverse($segments); // return root → most-recent-parent order
}

/**
 * Update only the image and image_gen fields on a scene (used by AI image apply).
 */
function update_scene_image($sceneID, $filename, $imageGen) {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "scenes SET image = ?, image_gen = ? WHERE sceneID = ?");
    $stmt->bind_param("ssi", $filename, $imageGen, $sceneID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Delete a scene, its choices, and its image file
 */
function delete_scene($sceneID) {
    $conn = db_connect();

    // Get the scene's image and storyID before deleting
    $stmt = $conn->prepare("SELECT image, storyID FROM " . DB_PREFIX . "scenes WHERE sceneID = ?");
    $stmt->bind_param("i", $sceneID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Delete the image file from disk
    if ($row && !empty($row['image'])) {
        $path = 'images/stories/' . (int)$row['storyID'] . '/' . $row['image'];
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // Delete choices first
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "choices WHERE sceneID = ?");
    $stmt->bind_param("i", $sceneID);
    $stmt->execute();
    $stmt->close();

    // Delete scene
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "scenes WHERE sceneID = ?");
    $stmt->bind_param("i", $sceneID);
    $stmt->execute();
    $stmt->close();

    $conn->close();
    return true;
}

// ==========================================
// CHOICE FUNCTIONS
// ==========================================

/**
 * Save choices for a scene (replaces all existing choices)
 */
function save_choices($sceneID, $choices) {
    $conn = db_connect();

    // Delete existing choices for this scene
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "choices WHERE sceneID = ?");
    $stmt->bind_param("i", $sceneID);
    $stmt->execute();
    $stmt->close();

    // Insert new choices
    if (!empty($choices)) {
        $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "choices (sceneID, choiceText, destinationID) VALUES (?, ?, ?)");
        foreach ($choices as $choice) {
            $text = $choice['text'];
            $dest = (int)$choice['dest'];
            $stmt->bind_param("isi", $sceneID, $text, $dest);
            $stmt->execute();
        }
        $stmt->close();
    }

    $conn->close();
    return true;
}

// ==========================================
// CLONE STORY
// ==========================================

/**
 * Clone a story: duplicates story, all scenes, choices, and images.
 * Choice destination IDs are remapped to the new scene IDs.
 *
 * @param int    $sourceStoryID  Original story ID
 * @param string $newTitle       Title for the cloned story
 * @param int    $userID         Owner of the new story
 * @return int|false             New story ID or false on failure
 */
function clone_story($sourceStoryID, $newTitle, $userID) {
    // Get original story
    $story = get_story($sourceStoryID);
    if (!$story) return false;

    // Create the new story record (preserve genre + per-story AI image settings)
    $newStoryID = create_story(
        $newTitle,
        $story['description'],
        '',  // image set after copying files
        $story['theme'],
        $userID,
        $story['created_by'],
        $story['layout'],
        $story['genre'] ?? null
    );
    if (!$newStoryID) return false;

    update_story_image_settings(
        (int)$newStoryID,
        $story['ai_image_category'] ?? null,
        $story['ai_image_style']    ?? null,
        $story['ai_image_mood']     ?? null,
        $story['ai_image_quality']  ?? null
    );

    // Create the new image folder
    $newDir = 'images/stories/' . (int)$newStoryID . '/';
    if (!is_dir($newDir)) {
        mkdir($newDir, 0755, true);
    }

    // Copy story thumbnail image
    if (!empty($story['image'])) {
        $srcPath = 'images/stories/' . (int)$sourceStoryID . '/' . $story['image'];
        if (file_exists($srcPath)) {
            copy($srcPath, $newDir . $story['image']);
            // Update the new story's image field (preserve genre)
            update_story($newStoryID, $newTitle, $story['description'], $story['image'], $story['theme'], $story['layout'], $story['genre'] ?? null);
        }
    }

    // Get all scenes from the source story
    $sourceScenes = get_scenes_by_story($sourceStoryID);

    // Map old sceneID -> new sceneID
    $idMap = array();

    // First pass: create all scenes (so we have all new IDs for remapping)
    foreach ($sourceScenes as $sp) {
        // Copy scene image file
        $newImage = $sp['image'];
        if (!empty($sp['image'])) {
            $srcPath = 'images/stories/' . (int)$sourceStoryID . '/' . $sp['image'];
            if (file_exists($srcPath)) {
                copy($srcPath, $newDir . $sp['image']);
            }
        }

        $newSPID = create_scene(
            $newStoryID,
            $sp['title'],
            $sp['description'],
            $newImage,
            $sp['image_gen'],
            $sp['hint'],
            isset($sp['enable_autoBack_nav']) ? $sp['enable_autoBack_nav'] : 1
        );
        $idMap[(int)$sp['sceneID']] = $newSPID;
    }

    // Second pass: copy choices with remapped destination IDs
    foreach ($sourceScenes as $sp) {
        $oldSP = get_scene((int)$sp['sceneID']);
        if ($oldSP && !empty($oldSP['choices'])) {
            $newChoices = array();
            foreach ($oldSP['choices'] as $choice) {
                $newDest = isset($idMap[(int)$choice['dest']]) ? $idMap[(int)$choice['dest']] : 0;
                $newChoices[] = array('text' => $choice['text'], 'dest' => $newDest);
            }
            $newSPID = $idMap[(int)$sp['sceneID']];
            save_choices($newSPID, $newChoices);
        }
    }

    return $newStoryID;
}

// ==========================================
// ADMIN / USER MANAGEMENT FUNCTIONS
// ==========================================

/**
 * Get all users (admin)
 */
function get_all_users() {
    $conn = db_connect();
    $sql = "SELECT userID, firstName, lastName, email, claude_api_key, openai_api_key, profileImage, isAdmin, created_date
            FROM " . DB_PREFIX . "users ORDER BY userID";
    $result = $conn->query($sql);
    $users = array();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    $conn->close();
    return $users;
}

/**
 * Admin: update a user's profile fields
 */
function admin_update_user($userID, $firstName, $lastName, $email, $isAdmin, $profileImage = null, $newPassword = null) {
    $conn = db_connect();

    // Check email uniqueness (exclude self)
    $stmt = $conn->prepare("SELECT userID FROM " . DB_PREFIX . "users WHERE email = ? AND userID != ?");
    $stmt->bind_param("si", $email, $userID);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $conn->close();
        return 'email_taken';
    }
    $stmt->close();

    // Build dynamic update
    $fields = "firstName = ?, lastName = ?, email = ?, isAdmin = ?";
    $types  = "sssi";
    $params = [$firstName, $lastName, $email, $isAdmin];

    if ($profileImage !== null) {
        $fields .= ", profileImage = ?";
        $types  .= "s";
        $params[] = $profileImage;
    }
    if ($newPassword !== null && $newPassword !== '') {
        $fields .= ", password = ?";
        $types  .= "s";
        $params[] = password_hash($newPassword, PASSWORD_BCRYPT);
    }

    $types .= "i";
    $params[] = $userID;

    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET $fields WHERE userID = ?");
    $stmt->bind_param($types, ...$params);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result ? true : false;
}

/**
 * Admin: create a new user
 */
function admin_create_user($firstName, $lastName, $email, $password, $isAdmin, $profileImage = '') {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT userID FROM " . DB_PREFIX . "users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $conn->close();
        return 'email_taken';
    }
    $stmt->close();

    $hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "users (firstName, lastName, email, password, isAdmin, profileImage) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssis", $firstName, $lastName, $email, $hashed, $isAdmin, $profileImage);
    $result = $stmt->execute();
    $newID = $result ? $conn->insert_id : false;
    $stmt->close();
    $conn->close();
    return $newID;
}

/**
 * Delete a user, their profile image, and all their stories
 */
function delete_user($userID) {
    $conn = db_connect();

    // Get the user's profile image before deleting
    $stmt = $conn->prepare("SELECT profileImage FROM " . DB_PREFIX . "users WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get all story IDs owned by this user
    $stmt = $conn->prepare("SELECT storyID FROM " . DB_PREFIX . "stories WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $storyIDs = array();
    while ($r = $result->fetch_assoc()) {
        $storyIDs[] = (int)$r['storyID'];
    }
    $stmt->close();
    $conn->close();

    // Delete each story (cascades to scenes, choices, and image folders)
    foreach ($storyIDs as $sid) {
        delete_story($sid);
    }

    // Delete profile image from disk
    if ($row && !empty($row['profileImage'])) {
        $path = 'images/profiles/' . $row['profileImage'];
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // Delete the user record
    $conn = db_connect();
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "users WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Update user profile image
 */
function update_user_profile_image($userID, $profileImage) {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET profileImage = ? WHERE userID = ?");
    $stmt->bind_param("si", $profileImage, $userID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

// ==========================================
// FILE UPLOAD HELPERS
// ==========================================

/**
 * Handle image file upload for stories/scenes
 * @return string|false Filename on success, false on failure
 */
function upload_image($file, $storyID, $prefix = '') {
    $targetDir = "images/stories/" . (int)$storyID . "/";

    // Create directory if it doesn't exist
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');

    if (!in_array($extension, $allowed)) {
        return false;
    }

    $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $filename;
    }

    return false;
}

/**
 * Handle profile image upload
 * @return string Filename on success, empty string on failure
 */
function upload_profile_image($file) {
    $targetDir = "images/profiles/";

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');

    if (!in_array($extension, $allowed)) {
        return '';
    }

    $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $filename;
    }

    return '';
}

// ==========================================
// AI JOB QUEUE FUNCTIONS
// ==========================================

/**
 * Create a new AI job
 * @return int|false New job ID or false on failure
 */
function create_ai_job($userID, $storyID, $sceneID, $jobType, $inputJson, $parentJobID = null) {
    $conn = db_connect();
    if ($parentJobID !== null) {
        $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "jobs (user_id, story_id, scene_id, job_type, input_json, parent_job_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiissi", $userID, $storyID, $sceneID, $jobType, $inputJson, $parentJobID);
    } else {
        $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "jobs (user_id, story_id, scene_id, job_type, input_json) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $userID, $storyID, $sceneID, $jobType, $inputJson);
    }
    $result = $stmt->execute();
    $newID = $result ? $conn->insert_id : false;
    $stmt->close();
    $conn->close();
    return $newID;
}

/**
 * Get an AI job by ID
 */
function get_ai_job($jobID) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT * FROM " . DB_PREFIX . "jobs WHERE job_id = ?");
    $stmt->bind_param("i", $jobID);
    $stmt->execute();
    $result = $stmt->get_result();
    $job = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $job;
}

/**
 * Count image jobs currently in 'running' state — used by the dispatcher
 * to enforce the ai_max_concurrent_image_jobs threshold.
 */
function db_count_running_image_jobs(): int {
    $conn  = db_connect();
    $stmt  = $conn->prepare(
        "SELECT COUNT(*) FROM " . DB_PREFIX . "jobs WHERE job_type = 'image' AND status = 'running'"
    );
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $conn->close();
    return $count;
}

/**
 * Return a claimed (running) job back to pending — used by the dispatcher
 * when it overshoots the per-type concurrency cap.
 */
function db_reset_job_to_pending(int $jobID): void {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'pending', started_at = NULL
         WHERE job_id = ? AND status = 'running'"
    );
    $stmt->bind_param("i", $jobID);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Claim all pending jobs atomically (for dispatcher).
 * Marks them 'running' and returns the claimed jobs.
 * @param int $limit Max jobs to claim at once
 * @return array Claimed job rows
 */
function claim_pending_jobs($limit = 10) {
    $conn = db_connect();

    // Atomically claim pending jobs by updating their status
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'running', started_at = NOW()
         WHERE status = 'pending'
         ORDER BY created_at ASC
         LIMIT ?"
    );
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $claimedCount = $stmt->affected_rows;
    $stmt->close();

    if ($claimedCount === 0) {
        $conn->close();
        return [];
    }

    // Fetch the jobs we just claimed (running + just started)
    $stmt = $conn->prepare(
        "SELECT * FROM " . DB_PREFIX . "jobs
         WHERE status = 'running'
         AND started_at >= NOW() - INTERVAL 5 SECOND
         ORDER BY created_at ASC
         LIMIT ?"
    );
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Claim pending jobs whose job_type is NOT the given type.
 * Used by the dispatcher to claim non-image jobs without touching the
 * image-type pool, which has its own concurrency limit.
 */
function claim_pending_jobs_excluding_type(int $limit, string $excludeType): array {
    if ($limit <= 0) return [];
    $conn = db_connect();

    // Phase 1: identify which jobs to claim (by ID) before touching their status
    $stmt = $conn->prepare(
        "SELECT job_id FROM " . DB_PREFIX . "jobs
         WHERE status = 'pending' AND job_type != ?
         ORDER BY created_at ASC LIMIT ?"
    );
    $stmt->bind_param("si", $excludeType, $limit);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'job_id');
    $stmt->close();
    if (empty($ids)) { $conn->close(); return []; }

    // Phase 2: claim exactly those IDs
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt  = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'running', started_at = NOW()
         WHERE job_id IN ($ph) AND status = 'pending'"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $stmt->close();

    // Phase 3: return exactly the rows we just claimed (status='running' proves we own them)
    $stmt = $conn->prepare(
        "SELECT * FROM " . DB_PREFIX . "jobs
         WHERE job_id IN ($ph) AND status = 'running'"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) $jobs[] = $row;
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Claim up to $limit pending jobs of a specific type.
 * Uses a three-phase SELECT→UPDATE→SELECT-by-ID pattern so the returned
 * list always reflects exactly the jobs we claimed, regardless of other
 * running jobs that happen to share a recent started_at timestamp.
 */
function claim_pending_jobs_of_type(int $limit, string $type): array {
    if ($limit <= 0) return [];
    $conn = db_connect();

    // Phase 1: identify which jobs to claim
    $stmt = $conn->prepare(
        "SELECT job_id FROM " . DB_PREFIX . "jobs
         WHERE status = 'pending' AND job_type = ?
         ORDER BY created_at ASC LIMIT ?"
    );
    $stmt->bind_param("si", $type, $limit);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'job_id');
    $stmt->close();
    if (empty($ids)) { $conn->close(); return []; }

    // Phase 2: claim exactly those IDs
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt  = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'running', started_at = NOW()
         WHERE job_id IN ($ph) AND status = 'pending'"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $stmt->close();

    // Phase 3: return exactly those rows (only the ones we won the race on)
    $stmt = $conn->prepare(
        "SELECT * FROM " . DB_PREFIX . "jobs
         WHERE job_id IN ($ph) AND status = 'running'"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) $jobs[] = $row;
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Mark a job as completed with result data
 */
function complete_ai_job($jobID, $resultJson) {
    $conn = db_connect();
    // completed_at is a stable terminal timestamp (no ON UPDATE), so the "Completed
    // In" duration isn't disturbed by later row writes like mark_jobs_seen().
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'completed', result_json = ?, updated_at = NOW(), completed_at = NOW()
         WHERE job_id = ?"
    );
    $stmt->bind_param("si", $resultJson, $jobID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Overwrite started_at to NOW() — called just before an external API call so
 * the "time to complete" duration reflects the API round-trip, not queue wait.
 */
function db_update_job_started_at(int $jobID): void {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "jobs SET started_at = NOW() WHERE job_id = ?");
    $stmt->bind_param("i", $jobID);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Mark a job as failed with an error message
 */
function fail_ai_job($jobID, $errorMessage) {
    // Never store a blank reason — a failed job with no message is impossible to
    // diagnose in the queue UI.
    $errorMessage = trim((string)$errorMessage);
    if ($errorMessage === '') {
        $errorMessage = 'Job failed without an error message.';
    }
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'failed', error_message = ?, updated_at = NOW(), completed_at = NOW()
         WHERE job_id = ?"
    );
    $stmt->bind_param("si", $errorMessage, $jobID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Timeout stale running jobs (running for longer than app_setting('ai_job_timeout_seconds'))
 * @return int Number of jobs timed out
 */
function timeout_stale_jobs($timeoutSeconds = 300) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'failed', error_message = 'Job timed out', updated_at = NOW(), completed_at = NOW()
         WHERE status = 'running'
         AND started_at < NOW() - INTERVAL ? SECOND"
    );
    $stmt->bind_param("i", $timeoutSeconds);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    $conn->close();
    return $count;
}

/**
 * Cancel a pending job
 */
function cancel_ai_job($jobID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'cancelled', updated_at = NOW(), completed_at = NOW()
         WHERE job_id = ? AND status = 'pending'"
    );
    $stmt->bind_param("i", $jobID);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    return $affected;
}

/**
 * Count a user's active (pending/running) top-level jobs — used for the
 * submit-time "max pending jobs per user" rate limit.
 *
 * Only parent/single jobs are counted (parent_job_id IS NULL). Child subjobs —
 * e.g. the per-scene image jobs a story spawns — are excluded, so submitting one
 * full-story job doesn't instantly consume a user's whole quota when it fans out
 * into 9+ image children.
 */
function count_pending_jobs_by_user($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "jobs
         WHERE user_id = ? AND status IN ('pending', 'running')
           AND parent_job_id IS NULL"
    );
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)$row['cnt'];
}

/**
 * Get jobs by user (for user's job history), with story and scene titles
 */
function get_jobs_by_user($userID, $limit = 20) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT j.*, s.title AS story_title, sc.title AS scene_title
         FROM " . DB_PREFIX . "jobs j
         LEFT JOIN " . DB_PREFIX . "stories s  ON j.story_id = s.storyID
         LEFT JOIN " . DB_PREFIX . "scenes  sc ON j.scene_id = sc.sceneID
         WHERE j.user_id = ?
         ORDER BY j.created_at DESC
         LIMIT ?"
    );
    $stmt->bind_param("ii", $userID, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Get all jobs (admin view), with story/scene titles and username
 */
function get_all_jobs($limit = 50) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT j.*, s.title AS story_title, sc.title AS scene_title,
                u.firstName, u.lastName, u.email
         FROM " . DB_PREFIX . "jobs j
         LEFT JOIN " . DB_PREFIX . "stories s  ON j.story_id = s.storyID
         LEFT JOIN " . DB_PREFIX . "scenes  sc ON j.scene_id = sc.sceneID
         LEFT JOIN " . DB_PREFIX . "users   u  ON j.user_id  = u.userID
         ORDER BY j.created_at DESC
         LIMIT ?"
    );
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Phase 40 — Job History pagination.
 *
 * The history page lists top-level jobs (parent_job_id IS NULL) created strictly
 * before $beforeDate (today's local midnight, computed in PHP so it stays in sync
 * with job_queue.php's "today" cutoff regardless of the MySQL session timezone).
 * Admins see all users' jobs; regular users see only their own. Child jobs are
 * fetched separately for the page's parents via db_get_child_jobs().
 */
function db_count_history_jobs(?int $userID, bool $isAdmin, string $beforeDate): int {
    $conn = db_connect();
    if ($isAdmin) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "jobs
                                WHERE parent_job_id IS NULL AND created_at < ?");
        $stmt->bind_param("s", $beforeDate);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "jobs
                                WHERE parent_job_id IS NULL AND created_at < ? AND user_id = ?");
        $stmt->bind_param("si", $beforeDate, $userID);
    }
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    $conn->close();
    return $c;
}

function db_get_history_jobs(?int $userID, bool $isAdmin, string $beforeDate, int $limit, int $offset): array {
    $conn = db_connect();
    if ($isAdmin) {
        $stmt = $conn->prepare(
            "SELECT j.*, s.title AS story_title, sc.title AS scene_title,
                    u.firstName, u.lastName, u.email
             FROM " . DB_PREFIX . "jobs j
             LEFT JOIN " . DB_PREFIX . "stories s  ON j.story_id = s.storyID
             LEFT JOIN " . DB_PREFIX . "scenes  sc ON j.scene_id = sc.sceneID
             LEFT JOIN " . DB_PREFIX . "users   u  ON j.user_id  = u.userID
             WHERE j.parent_job_id IS NULL AND j.created_at < ?
             ORDER BY j.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param("sii", $beforeDate, $limit, $offset);
    } else {
        $stmt = $conn->prepare(
            "SELECT j.*, s.title AS story_title, sc.title AS scene_title
             FROM " . DB_PREFIX . "jobs j
             LEFT JOIN " . DB_PREFIX . "stories s  ON j.story_id = s.storyID
             LEFT JOIN " . DB_PREFIX . "scenes  sc ON j.scene_id = sc.sceneID
             WHERE j.parent_job_id IS NULL AND j.created_at < ? AND j.user_id = ?
             ORDER BY j.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param("siii", $beforeDate, $userID, $limit, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Phase 40 — Child jobs for a set of parent job IDs (used to attach children to
 * the paginated history parents). Returns a flat list; the caller groups them.
 */
function db_get_child_jobs(array $parentIds, bool $isAdmin): array {
    if (empty($parentIds)) return [];
    $conn = db_connect();
    $parentIds = array_map('intval', $parentIds);
    $in    = implode(',', array_fill(0, count($parentIds), '?'));
    $types = str_repeat('i', count($parentIds));

    $userCols = $isAdmin ? ", u.firstName, u.lastName, u.email" : "";
    $userJoin = $isAdmin ? "LEFT JOIN " . DB_PREFIX . "users u ON j.user_id = u.userID" : "";

    $stmt = $conn->prepare(
        "SELECT j.*, s.title AS story_title, sc.title AS scene_title $userCols
         FROM " . DB_PREFIX . "jobs j
         LEFT JOIN " . DB_PREFIX . "stories s  ON j.story_id = s.storyID
         LEFT JOIN " . DB_PREFIX . "scenes  sc ON j.scene_id = sc.sceneID
         $userJoin
         WHERE j.parent_job_id IN ($in)
         ORDER BY j.created_at ASC"
    );
    $stmt->bind_param($types, ...$parentIds);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $jobs;
}

/**
 * Phase 32 — A single job row joined with story/scene titles and owner name.
 * Used by the job detail modal (api_jobs.php?action=detail).
 */
function get_job_with_context($jobID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT j.*, s.title AS story_title, sc.title AS scene_title,
                u.firstName, u.lastName, u.email
         FROM " . DB_PREFIX . "jobs j
         LEFT JOIN " . DB_PREFIX . "stories s  ON j.story_id = s.storyID
         LEFT JOIN " . DB_PREFIX . "scenes  sc ON j.scene_id = sc.sceneID
         LEFT JOIN " . DB_PREFIX . "users   u  ON j.user_id  = u.userID
         WHERE j.job_id = ?"
    );
    $stmt->bind_param("i", $jobID);
    $stmt->execute();
    $res = $stmt->get_result();
    $job = $res->num_rows ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $job;
}

/**
 * Phase 32 — System-wide job statistics for the admin stat cards.
 * "Today" counts use the server's current date. Returned in one query.
 */
function db_get_job_stats(): array {
    $conn = db_connect();
    $sql = "SELECT
              COALESCE(SUM(status='pending'),0) AS pending,
              COALESCE(SUM(status='running'),0) AS running,
              COALESCE(SUM(status IN ('completed','completed_with_errors') AND DATE(created_at)=CURDATE()),0) AS completed_today,
              COALESCE(SUM(status='failed' AND DATE(created_at)=CURDATE()),0) AS failed_today,
              COALESCE(SUM(DATE(created_at)=CURDATE()),0) AS total_today,
              COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN cost_usd ELSE 0 END),0) AS spent_today
            FROM " . DB_PREFIX . "jobs";
    $row = $conn->query($sql)->fetch_assoc();
    $conn->close();
    return [
        'pending'         => (int)$row['pending'],
        'running'         => (int)$row['running'],
        'completed_today' => (int)$row['completed_today'],
        'failed_today'    => (int)$row['failed_today'],
        'total_today'     => (int)$row['total_today'],
        'spent_today'     => (float)$row['spent_today'],
    ];
}

/**
 * Phase 32 — Hard-delete terminal jobs (completed, failed, cancelled, and
 * completed_with_errors). Admins clear all rows; regular users only their own.
 * Returns the number of rows deleted.
 */
function db_clear_completed_jobs(int $userId, bool $isAdmin): int {
    $conn = db_connect();
    $terminal = "('completed','failed','cancelled','completed_with_errors')";
    if ($isAdmin) {
        $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "jobs WHERE status IN $terminal");
    } else {
        $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "jobs WHERE status IN $terminal AND user_id = ?");
        $stmt->bind_param("i", $userId);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    $conn->close();
    return $n;
}

/**
 * Count unseen completed/failed jobs for a user (drives header badge)
 */
function get_unseen_job_count($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "jobs
         WHERE user_id = ? AND status IN ('completed', 'failed', 'completed_with_errors')
           AND seen_at IS NULL AND parent_job_id IS NULL"
    );
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)$row['cnt'];
}

/**
 * Stamp seen_at on all unseen completed/failed jobs for a user (clears badge)
 */
function mark_jobs_seen($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET seen_at = NOW()
         WHERE user_id = ? AND status IN ('completed', 'failed', 'completed_with_errors') AND seen_at IS NULL"
    );
    $stmt->bind_param("i", $userID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Reset a failed job back to pending so the dispatcher picks it up again
 */
function retry_ai_job($jobID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET status = 'pending', error_message = NULL, started_at = NULL,
             result_json = NULL, seen_at = NULL, completed_at = NULL, updated_at = NOW()
         WHERE job_id = ? AND status = 'failed'"
    );
    $stmt->bind_param("i", $jobID);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    return $affected;
}

/**
 * Demote a completed parent job to completed_with_errors when a child job fails.
 * Only transitions from 'completed' — idempotent if already demoted.
 */
/**
 * After any child job finishes (success or failure), check whether all siblings
 * are now in terminal states. If they are and at least one failed, mark the parent
 * as completed_with_errors. If siblings are still running, do nothing — the next
 * child completion will call this again.
 */
function check_and_finalize_parent(int $parentJobID): void {
    $conn = db_connect();

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*)                                                                   AS total,
            SUM(status IN ('completed','failed','cancelled','completed_with_errors'))  AS terminal,
            SUM(status = 'failed')                                                     AS failed_count
         FROM " . DB_PREFIX . "jobs WHERE parent_job_id = ?"
    );
    $stmt->bind_param("i", $parentJobID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int)$row['total'] === 0) { $conn->close(); return; }

    // Not all children are done yet — nothing to do
    if ((int)$row['terminal'] < (int)$row['total']) { $conn->close(); return; }

    // All children finished. If any failed, flag the parent with the warning state;
    // otherwise ensure it reads as a clean completion — this also reverts the warning
    // when a previously-failed child is retried successfully.
    if ((int)$row['failed_count'] > 0) {
        $stmt = $conn->prepare(
            "UPDATE " . DB_PREFIX . "jobs
             SET status = 'completed_with_errors', updated_at = NOW(), completed_at = NOW()
             WHERE job_id = ? AND status NOT IN ('failed','cancelled')"
        );
        $stmt->bind_param("i", $parentJobID);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "UPDATE " . DB_PREFIX . "jobs
             SET status = 'completed', updated_at = NOW(), completed_at = NOW()
             WHERE job_id = ? AND status = 'completed_with_errors'"
        );
        $stmt->bind_param("i", $parentJobID);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();

    // All children are terminal now — if this parent is a publish-requesting
    // create-story job and everything succeeded, auto-publish the story.
    maybe_publish_created_story($parentJobID);
}

/**
 * Auto-publish an AI-created story, but only once every job in its image family
 * has succeeded.
 *
 * Called when one of a create-story ('story') job's image children reaches a
 * terminal state. Publishes the story ONLY when:
 *   - the parent job requested it (input_json "publish" flag), and
 *   - the parent job itself completed cleanly ('completed'), and
 *   - it has at least one image child (image-less stories aren't auto-published —
 *     their blank-image presentation isn't ready for the gallery/player), and
 *   - every child completed cleanly, with none still pending/running, failed,
 *     cancelled, or completed_with_errors.
 * If any job errored or is still unfinished, the story is left as a draft.
 *
 * Idempotent and safe to call repeatedly: it only acts on a standalone story that
 * is still in 'draft' status, so a successful retry of a previously-failed child
 * will publish it then.
 */
function maybe_publish_created_story(int $parentJobID): void {
    $parent = get_ai_job($parentJobID);
    if (!$parent || ($parent['job_type'] ?? '') !== 'story') return;

    $input = json_decode($parent['input_json'] ?? '{}', true);
    if (empty($input['publish'])) return;

    $storyID = (int)($parent['story_id'] ?? 0);
    if (!$storyID) return;

    // The parent's own text generation must have completed cleanly.
    if (($parent['status'] ?? '') !== 'completed') return;

    // Every child must be completed cleanly; none unfinished or errored.
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'completed')            AS ok,
                SUM(status IN ('pending','running')) AS active
         FROM " . DB_PREFIX . "jobs WHERE parent_job_id = ?"
    );
    $stmt->bind_param("i", $parentJobID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $total  = (int)($row['total']  ?? 0);
    $ok     = (int)($row['ok']     ?? 0);
    $active = (int)($row['active'] ?? 0);

    if ($total === 0)   return;   // no images — image-less stories aren't auto-published
    if ($active > 0)    return;   // children still working — re-checked when they finish
    if ($total !== $ok) return;   // a child errored/cancelled — leave the story a draft

    // Only publish a standalone story that's still a draft (idempotent guard).
    $story = get_story($storyID);
    if (!$story || $story['status'] !== 'draft' || $story['published_story_id'] !== null) return;

    publish_story($storyID);
}

/** @deprecated Use check_and_finalize_parent() instead */
function mark_parent_completed_with_errors(int $parentJobID): bool {
    check_and_finalize_parent($parentJobID);
    return true;
}

/**
 * Update a job's story_id after apply_full_story_result() creates the story.
 * Enables the "Go to story" link in job_queue.php for full_story jobs.
 */
function update_ai_job_story_id(int $jobID, int $storyID): bool {
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "jobs SET story_id = ? WHERE job_id = ?");
    $stmt->bind_param("ii", $storyID, $jobID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool)$result;
}


// ==========================================
// RATINGS FUNCTIONS
// ==========================================

/**
 * Upsert a rating (1–5) for a story by a user.
 * @return bool
 */
function rate_story($userID, $storyID, $rating) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "INSERT INTO " . DB_PREFIX . "ratings (user_id, story_id, rating)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating)"
    );
    $stmt->bind_param("iii", $userID, $storyID, $rating);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Get the average rating and count for a story.
 * @return array ['average' => float|null, 'count' => int]
 */
function get_story_rating($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT AVG(rating) as average, COUNT(*) as count
         FROM " . DB_PREFIX . "ratings WHERE story_id = ?"
    );
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return [
        'average' => $row['average'] !== null ? (float)$row['average'] : null,
        'count'   => (int)$row['count'],
    ];
}

/**
 * Get the rating a specific user gave a story (null if not rated).
 */
function get_user_rating($userID, $storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT rating FROM " . DB_PREFIX . "ratings WHERE user_id = ? AND story_id = ?"
    );
    $stmt->bind_param("ii", $userID, $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ? (int)$row['rating'] : null;
}

/**
 * Delete a user's rating for a story.
 */
function delete_rating($userID, $storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "DELETE FROM " . DB_PREFIX . "ratings WHERE user_id = ? AND story_id = ?"
    );
    $stmt->bind_param("ii", $userID, $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}


// ==========================================
// FAVORITES FUNCTIONS
// ==========================================

/**
 * Toggle a user's favourite for a story.
 * @return bool True if now favourited, false if removed.
 */
function toggle_favorite($userID, $storyID) {
    $conn = db_connect();

    // Check if already favourited
    $stmt = $conn->prepare(
        "SELECT favorite_id FROM " . DB_PREFIX . "favorites WHERE user_id = ? AND story_id = ?"
    );
    $stmt->bind_param("ii", $userID, $storyID);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        $stmt = $conn->prepare(
            "DELETE FROM " . DB_PREFIX . "favorites WHERE user_id = ? AND story_id = ?"
        );
        $stmt->bind_param("ii", $userID, $storyID);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return false;
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO " . DB_PREFIX . "favorites (user_id, story_id) VALUES (?, ?)"
        );
        $stmt->bind_param("ii", $userID, $storyID);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return true;
    }
}

/**
 * Check if a user has favourited a story.
 */
function is_favorited($userID, $storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT favorite_id FROM " . DB_PREFIX . "favorites WHERE user_id = ? AND story_id = ?"
    );
    $stmt->bind_param("ii", $userID, $storyID);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    $conn->close();
    return $exists;
}

/**
 * Get the favourite count for a story.
 */
function get_favorite_count($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "favorites WHERE story_id = ?"
    );
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)$row['cnt'];
}

/**
 * Get all stories favourited by a user (for account page).
 */
function get_favorites_by_user($userID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT s.storyID, s.title, s.description, s.genre, s.image, s.theme, s.userID, s.created_by, s.date_created, s.status, s.published_story_id,
                f.created_at as favorited_at
         FROM " . DB_PREFIX . "favorites f
         JOIN " . DB_PREFIX . "stories s ON f.story_id = s.storyID
         WHERE f.user_id = ? AND s.status != 'deleted'
         ORDER BY f.created_at DESC"
    );
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['genre'] = decode_story_genre($row['genre']);
        $rows[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $rows;
}


// ==========================================
// COMMENTS FUNCTIONS
// ==========================================

/**
 * Add a comment (or reply) to a story.
 * @return int|false New comment ID or false on failure.
 */
function add_comment($userID, $storyID, $comment, $replyToCommentID = null) {
    $conn = db_connect();
    $replyToCommentID = $replyToCommentID ?: null;
    $stmt = $conn->prepare(
        "INSERT INTO " . DB_PREFIX . "comments (user_id, story_id, comment, reply_to_comment_id)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("iisi", $userID, $storyID, $comment, $replyToCommentID);
    $result = $stmt->execute();
    $newID = $result ? $conn->insert_id : false;
    $stmt->close();
    $conn->close();
    return $newID;
}

/**
 * Get all comments for a story, with author info, ordered oldest first.
 */
function get_comments_by_story($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT c.comment_id, c.user_id, c.story_id, c.comment, c.reply_to_comment_id, c.created_at,
                u.firstName, u.lastName, u.profileImage
         FROM " . DB_PREFIX . "comments c
         JOIN " . DB_PREFIX . "users u ON c.user_id = u.userID
         WHERE c.story_id = ?
         ORDER BY c.created_at ASC"
    );
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Delete a comment by ID.
 * Pass $userID for ownership check; admins pass null to skip the check.
 */
function delete_comment($commentID, $userID = null) {
    $conn = db_connect();
    if ($userID !== null) {
        $stmt = $conn->prepare(
            "DELETE FROM " . DB_PREFIX . "comments WHERE comment_id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $commentID, $userID);
    } else {
        $stmt = $conn->prepare(
            "DELETE FROM " . DB_PREFIX . "comments WHERE comment_id = ?"
        );
        $stmt->bind_param("i", $commentID);
    }
    $result = $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    return $affected;
}


// ==========================================
// VIEWS FUNCTIONS
// ==========================================

/**
 * Record a view for a story (called on summary page load).
 */
function record_view($storyID, $userID = null) {
    $conn = db_connect();
    $userID = $userID ?: null;
    $stmt = $conn->prepare(
        "INSERT INTO " . DB_PREFIX . "views (story_id, user_id) VALUES (?, ?)"
    );
    $stmt->bind_param("ii", $storyID, $userID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Get total view count for a story.
 */
function get_view_count($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "views WHERE story_id = ?"
    );
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)$row['cnt'];
}

/**
 * Get combined social stats for a story in a single query.
 * @return array ['views' => int, 'avg_rating' => float|null, 'rating_count' => int, 'fave_count' => int]
 */
function get_story_stats($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM " . DB_PREFIX . "views    WHERE story_id = ?) AS views,
            (SELECT AVG(rating) FROM " . DB_PREFIX . "ratings WHERE story_id = ?) AS avg_rating,
            (SELECT COUNT(*) FROM " . DB_PREFIX . "ratings  WHERE story_id = ?) AS rating_count,
            (SELECT COUNT(*) FROM " . DB_PREFIX . "favorites WHERE story_id = ?) AS fave_count,
            (SELECT COUNT(*) FROM " . DB_PREFIX . "comments WHERE story_id = ?) AS comment_count"
    );
    $stmt->bind_param("iiiii", $storyID, $storyID, $storyID, $storyID, $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return [
        'views'         => (int)$row['views'],
        'avg_rating'    => $row['avg_rating'] !== null ? round((float)$row['avg_rating'], 1) : null,
        'rating_count'  => (int)$row['rating_count'],
        'fave_count'    => (int)$row['fave_count'],
        'comment_count' => (int)$row['comment_count'],
    ];
}


// ==========================================
// STORY STATUS / PUBLISH / DRAFT FUNCTIONS
// ==========================================

/**
 * Publish a standalone draft (makes it publicly visible).
 */
function publish_story($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "stories SET status = 'published' WHERE storyID = ?"
    );
    $stmt->bind_param("i", $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Set a published story back to draft (unpublish).
 */
function set_story_draft($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "stories SET status = 'draft' WHERE storyID = ?"
    );
    $stmt->bind_param("i", $storyID);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Check whether a story is a shadow draft (has a published_story_id set).
 */
function is_edit_draft($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT published_story_id FROM " . DB_PREFIX . "stories WHERE storyID = ?"
    );
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row && $row['published_story_id'] !== null;
}

/**
 * Get the active shadow draft for a published story, if one exists.
 * @return array|null Draft story row or null.
 */
function get_edit_draft($publishedStoryID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT storyID, title, description, image, theme, layout, userID, created_by, date_created, status, published_story_id
         FROM " . DB_PREFIX . "stories WHERE published_story_id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $publishedStoryID);
    $stmt->execute();
    $result = $stmt->get_result();
    $draft = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $draft;
}

/**
 * Map a published story's sceneID to the corresponding sceneID in its shadow
 * draft. create_edit_draft() copies scenes in sceneID-ASC order, so a faithful
 * copy keeps a 1:1 positional correspondence. Returns null when the draft's scene
 * set no longer matches the published one (it was structurally edited) or the
 * sceneID isn't found, so callers can fall back to the draft's scene list rather
 * than guess wrong.
 */
function map_published_scene_to_draft($publishedStoryID, $draftStoryID, $publishedSceneID): ?int {
    $conn = db_connect();
    $load = function ($storyID) use ($conn) {
        $ids  = [];
        $stmt = $conn->prepare("SELECT sceneID FROM " . DB_PREFIX . "scenes WHERE storyID = ? ORDER BY sceneID ASC");
        $stmt->bind_param("i", $storyID);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $ids[] = (int)$row['sceneID'];
        $stmt->close();
        return $ids;
    };
    $pub = $load((int)$publishedStoryID);
    $drf = $load((int)$draftStoryID);
    $conn->close();

    if (count($pub) !== count($drf)) return null;        // draft structurally changed — don't guess
    $idx = array_search((int)$publishedSceneID, $pub, true);
    if ($idx === false || !isset($drf[$idx])) return null;
    return $drf[$idx];
}

/**
 * Clone a published story into a shadow draft for editing.
 * Copies story metadata and all scenes + choices.
 * @return int|false New draft storyID or false on failure.
 */
function create_edit_draft($publishedStoryID, $userID) {
    $conn = db_connect();
    $conn->begin_transaction();

    try {
        // Fetch the original story
        $stmt = $conn->prepare(
            "SELECT title, description, image, theme, layout, created_by
             FROM " . DB_PREFIX . "stories WHERE storyID = ?"
        );
        $stmt->bind_param("i", $publishedStoryID);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$original) {
            $conn->rollback();
            $conn->close();
            return false;
        }

        // Insert draft story row
        $stmt = $conn->prepare(
            "INSERT INTO " . DB_PREFIX . "stories
             (title, description, image, theme, layout, userID, created_by, status, published_story_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $stmt->bind_param(
            "ssssssii",
            $original['title'], $original['description'], $original['image'],
            $original['theme'], $original['layout'],
            $userID, $original['created_by'], $publishedStoryID
        );
        $stmt->execute();
        $draftID = $conn->insert_id;
        $stmt->close();

        // Copy scenes
        $stmt = $conn->prepare(
            "SELECT sceneID, title, description, image, image_gen, hint, enable_autoBack_nav
             FROM " . DB_PREFIX . "scenes WHERE storyID = ? ORDER BY sceneID ASC"
        );
        $stmt->bind_param("i", $publishedStoryID);
        $stmt->execute();
        $scenes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Map original sceneID → new sceneID
        $sceneMap = [];
        foreach ($scenes as $scene) {
            $sStmt = $conn->prepare(
                "INSERT INTO " . DB_PREFIX . "scenes
                 (storyID, title, description, image, image_gen, hint, enable_autoBack_nav)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $sStmt->bind_param(
                "isssssi",
                $draftID, $scene['title'], $scene['description'], $scene['image'],
                $scene['image_gen'], $scene['hint'], $scene['enable_autoBack_nav']
            );
            $sStmt->execute();
            $sceneMap[$scene['sceneID']] = $conn->insert_id;
            $sStmt->close();
        }

        // Copy choices (remapping scene IDs)
        foreach ($sceneMap as $origSceneID => $newSceneID) {
            $cStmt = $conn->prepare(
                "SELECT choiceText, destinationID FROM " . DB_PREFIX . "choices WHERE sceneID = ?"
            );
            $cStmt->bind_param("i", $origSceneID);
            $cStmt->execute();
            $choices = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $cStmt->close();

            foreach ($choices as $choice) {
                $destID = isset($sceneMap[$choice['destinationID']]) ? $sceneMap[$choice['destinationID']] : null;
                $iStmt = $conn->prepare(
                    "INSERT INTO " . DB_PREFIX . "choices (sceneID, choiceText, destinationID) VALUES (?, ?, ?)"
                );
                $iStmt->bind_param("isi", $newSceneID, $choice['choiceText'], $destID);
                $iStmt->execute();
                $iStmt->close();
            }
        }

        $conn->commit();
        $conn->close();
        return $draftID;

    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        return false;
    }
}

/**
 * Publish a shadow draft: replace the original story's content with the draft's,
 * move any new images, and delete the draft.
 *
 * The original storyID is preserved so all social data (ratings, comments,
 * favourites, views) remains intact.
 *
 * @return bool
 */
function publish_draft($draftStoryID) {
    $conn = db_connect();
    $conn->begin_transaction();

    try {
        // Fetch draft
        $stmt = $conn->prepare(
            "SELECT storyID, title, description, image, theme, layout, published_story_id
             FROM " . DB_PREFIX . "stories WHERE storyID = ? AND published_story_id IS NOT NULL"
        );
        $stmt->bind_param("i", $draftStoryID);
        $stmt->execute();
        $draft = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$draft) {
            $conn->rollback();
            $conn->close();
            return false;
        }

        $origID = (int)$draft['published_story_id'];

        // Update original story metadata
        $stmt = $conn->prepare(
            "UPDATE " . DB_PREFIX . "stories
             SET title = ?, description = ?, image = ?, theme = ?, layout = ?
             WHERE storyID = ?"
        );
        $stmt->bind_param(
            "sssssi",
            $draft['title'], $draft['description'], $draft['image'],
            $draft['theme'], $draft['layout'], $origID
        );
        $stmt->execute();
        $stmt->close();

        // Delete all original scenes and choices (cascade deletes choices)
        $stmt = $conn->prepare(
            "DELETE FROM " . DB_PREFIX . "scenes WHERE storyID = ?"
        );
        $stmt->bind_param("i", $origID);
        $stmt->execute();
        $stmt->close();

        // Move draft scenes to the original story
        $stmt = $conn->prepare(
            "UPDATE " . DB_PREFIX . "scenes SET storyID = ? WHERE storyID = ?"
        );
        $stmt->bind_param("ii", $origID, $draftStoryID);
        $stmt->execute();
        $stmt->close();

        // Move any new images from draft folder → original folder
        $draftImgDir = "images/stories/" . $draftStoryID . "/";
        $origImgDir  = "images/stories/" . $origID . "/";
        if (is_dir($draftImgDir)) {
            if (!is_dir($origImgDir)) {
                mkdir($origImgDir, 0755, true);
            }
            foreach (glob($draftImgDir . "*") as $file) {
                rename($file, $origImgDir . basename($file));
            }
            rmdir($draftImgDir);
        }

        // Delete draft story row
        $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "stories WHERE storyID = ?");
        $stmt->bind_param("i", $draftStoryID);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $conn->close();
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        return false;
    }
}

/**
 * Discard a shadow draft: delete the draft row and its image folder.
 * The published original is untouched.
 * @return bool
 */
function discard_draft($draftStoryID) {
    // Remove draft image folder if it exists
    $draftImgDir = "images/stories/" . (int)$draftStoryID . "/";
    if (is_dir($draftImgDir)) {
        foreach (glob($draftImgDir . "*") as $file) {
            unlink($file);
        }
        rmdir($draftImgDir);
    }

    $conn = db_connect();
    // Scenes are deleted by cascade (ON DELETE CASCADE on choices → scene; storyID FK on scenes)
    $stmt = $conn->prepare(
        "DELETE FROM " . DB_PREFIX . "stories WHERE storyID = ? AND published_story_id IS NOT NULL"
    );
    $stmt->bind_param("i", $draftStoryID);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    return $affected;
}

/**
 * Resolve the image directory for reading story images.
 * Shadow drafts share the published story's image folder for existing images.
 * New images uploaded to a draft are saved in images/stories/{draftStoryID}/ — that
 * directory is only created when an image is actually uploaded to the draft.
 *
 * @param  int    $storyID
 * @return string Directory path (with trailing slash), e.g. "images/stories/42/"
 */
function get_story_image_dir($storyID) {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT published_story_id FROM " . DB_PREFIX . "stories WHERE storyID = ?"
    );
    $stmt->bind_param("i", $storyID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $resolvedID = ($row && $row['published_story_id'] !== null)
        ? (int)$row['published_story_id']
        : (int)$storyID;

    return "images/stories/" . $resolvedID . "/";
}

// ==========================================
// SETTINGS
// ==========================================

function db_get_all_settings(): array {
    $conn = db_connect();
    $result = $conn->query("SELECT setting_key, setting_value FROM " . DB_PREFIX . "settings");
    $map = [];
    while ($row = $result->fetch_assoc()) {
        $map[$row['setting_key']] = $row['setting_value'];
    }
    $conn->close();
    return $map;
}

function db_set_setting(string $key, string $value): void {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "INSERT INTO " . DB_PREFIX . "settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// ==========================================
// AI JOB COSTS
// ==========================================

function db_update_job_cost(int $jobID, int $inputTokens, int $outputTokens, int $imageCount, float $costUsd): void {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "jobs
         SET input_tokens = ?, output_tokens = ?, image_count = ?, cost_usd = ?
         WHERE job_id = ?"
    );
    $stmt->bind_param("iiidi", $inputTokens, $outputTokens, $imageCount, $costUsd, $jobID);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Return the total cost_usd for a job chain (root + all children).
 * Chains in this app are max 2 levels deep, so a simple self-join suffices.
 * Returns null if no cost has been recorded yet (all rows have NULL cost_usd).
 * Returns a partial sum while some child jobs are still in progress.
 */
function db_get_chain_cost(int $jobID): ?float {
    $conn = db_connect();

    // Find the root: if the given job has a parent, that parent is the root
    $stmt = $conn->prepare(
        "SELECT COALESCE(parent_job_id, job_id) AS root_id FROM " . DB_PREFIX . "jobs WHERE job_id = ?"
    );
    $stmt->bind_param("i", $jobID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        return null;
    }

    $rootId = (int)$row['root_id'];

    $stmt = $conn->prepare(
        "SELECT SUM(cost_usd) AS total FROM " . DB_PREFIX . "jobs
         WHERE job_id = ? OR parent_job_id = ?"
    );
    $stmt->bind_param("ii", $rootId, $rootId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if ($result === null || $result['total'] === null) {
        return null;
    }

    return (float)$result['total'];
}

// === PROMPT LOADER ===

/**
 * Load a prompt template from prompts/{name}.txt and substitute {PLACEHOLDER} tokens.
 *
 * Keys in $vars are uppercased automatically: 'target_scenes' → {TARGET_SCENES}.
 *
 * @throws RuntimeException if the template file cannot be read
 */
// ==========================================
// STORY SOFT DELETE
// ==========================================

/**
 * Move a story to the trash (set status='deleted', record timestamp).
 */
function db_soft_delete_story(int $storyId): void {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "stories SET status = 'deleted', date_deleted = NOW() WHERE storyID = ?"
    );
    $stmt->bind_param("i", $storyId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Restore a story from trash (reset to draft, clear timestamp).
 */
function db_restore_story(int $storyId): void {
    $conn = db_connect();
    $stmt = $conn->prepare(
        "UPDATE " . DB_PREFIX . "stories SET status = 'draft', date_deleted = NULL WHERE storyID = ?"
    );
    $stmt->bind_param("i", $storyId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Get deleted stories. Admins see all; regular users see only their own.
 * Returns full story rows plus owner username, ordered by date_deleted DESC.
 */
function db_get_deleted_stories(int $userId, bool $isAdmin): array {
    $conn = db_connect();
    if ($isAdmin) {
        $stmt = $conn->prepare(
            "SELECT s.*, u.firstName, u.lastName
             FROM " . DB_PREFIX . "stories s
             JOIN " . DB_PREFIX . "users u ON s.userID = u.userID
             WHERE s.status = 'deleted'
             ORDER BY s.date_deleted DESC"
        );
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            "SELECT s.*, u.firstName, u.lastName
             FROM " . DB_PREFIX . "stories s
             JOIN " . DB_PREFIX . "users u ON s.userID = u.userID
             WHERE s.status = 'deleted' AND s.userID = ?
             ORDER BY s.date_deleted DESC"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Phase 30 — Permanently purge trashed stories older than the retention window.
 *
 * Selects stories with status='deleted' whose date_deleted is older than the
 * given interval, then hard-deletes their choices, scenes, story rows, and image
 * folders (the same scope as delete_story()). Self-contained so both the admin
 * "Empty Trash Now" button and the maintenance cron call it directly. Image
 * folders are resolved via an absolute path so it works from web and CLI alike.
 *
 * @param string $interval A MySQL INTERVAL expression from retention_to_interval()
 *                         (fixed, non-user-supplied — safe to interpolate).
 * @return int[] The storyIDs that were purged.
 */
function db_purge_deleted_stories(string $interval): array {
    $conn = db_connect();

    // Collect eligible storyIDs first.
    $ids = [];
    $res = $conn->query(
        "SELECT storyID FROM " . DB_PREFIX . "stories
         WHERE status = 'deleted' AND date_deleted < (NOW() - $interval)"
    );
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['storyID'];
    }
    $res->free();

    if (empty($ids)) {
        $conn->close();
        return [];
    }

    foreach ($ids as $storyID) {
        // Choices belonging to this story's scenes
        $stmt = $conn->prepare(
            "DELETE c FROM " . DB_PREFIX . "choices c
             INNER JOIN " . DB_PREFIX . "scenes s ON c.sceneID = s.sceneID
             WHERE s.storyID = ?"
        );
        $stmt->bind_param("i", $storyID);
        $stmt->execute();
        $stmt->close();

        // Scenes
        $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "scenes WHERE storyID = ?");
        $stmt->bind_param("i", $storyID);
        $stmt->execute();
        $stmt->close();

        // The story row itself
        $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "stories WHERE storyID = ?");
        $stmt->bind_param("i", $storyID);
        $stmt->execute();
        $stmt->close();

        // The story's image folder (absolute path → safe from web or CLI)
        $dir = __DIR__ . '/images/stories/' . $storyID;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                if (is_file($file)) @unlink($file);
            }
            @rmdir($dir);
        }
    }

    $conn->close();
    return $ids;
}

// ==========================================
// PROMPT LOADER
// ==========================================

function load_prompt(string $name, array $vars = []): string {
    $path = __DIR__ . '/prompts/' . $name . '.txt';
    $text = file_get_contents($path);
    if ($text === false) {
        throw new RuntimeException("Prompt file not found: $name");
    }
    if ($vars) {
        $search  = array_map(fn($k) => '{' . strtoupper($k) . '}', array_keys($vars));
        $replace = array_values($vars);
        $text    = str_replace($search, $replace, $text);
    }
    return $text;
}

?>
