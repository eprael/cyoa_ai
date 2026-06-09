# API Endpoints

The app uses traditional form POST submissions for most pages. AI features and social
interactions use lightweight AJAX endpoints that return JSON.

---

## `api_jobs.php`

Handles all AI job queue interactions. Every request requires a valid session unless noted.

### `GET ?action=unseen_count` — Badge count for the header poller

Returns the number of the current user's jobs that have `status IN ('completed', 'failed')`
and `seen_at IS NULL`. Used by the continuous header poller to update the notification badge.

**Response:**
```json
{ "count": 3 }
```

Returns `{ "count": 0 }` if the user is not logged in (badge hidden for guests).

---

### `GET ?action=status&job_id=N` — Poll a single job's status

**Response (pending / running):**
```json
{ "job_id": 42, "status": "running", "job_type": "scene", "created_at": "2026-05-15 10:30:00" }
```

**Response (completed):**
```json
{ "job_id": 42, "status": "completed", "job_type": "scene", "result": { ... } }
```

**Response (failed):**
```json
{ "job_id": 42, "status": "failed", "error": "API rate limit exceeded" }
```

Auth: user must own the job or be admin.

---

### `GET ?action=list` — List user's jobs (or all jobs for admin)

**Query params:**
- `status` (optional) — filter by status
- `story_id` (optional) — filter by story
- `limit` (optional, default 20)

**Response:**
```json
{
    "jobs": [
        { "job_id": 42, "job_type": "scene", "status": "completed", "story_id": 15, "created_at": "..." },
        { "job_id": 41, "job_type": "image", "status": "failed",    "story_id": 15, "created_at": "...", "error": "..." }
    ]
}
```

---

### `GET ?action=detail&job_id=N` — Full job record for the detail modal (Phase 32)

Returns the complete job row with context (story/scene titles, user name) plus the decoded
`input_json` and `result_json`. Drives the Job Queue / Job History detail modal (`job_detail.js`).

**Response (abridged):**
```json
{
    "job_id": 42, "job_type": "story", "status": "completed",
    "user_name": "Alice", "story_title": "The Cave", "scene_title": null,
    "created_at": "...", "started_at": "...", "updated_at": "...",
    "cost_usd": 0.1421, "error_message": null,
    "input_json": { ... }, "result_json": { ... }
}
```

Auth: user must own the job or be admin. (There is no separate `chain_cost` action — the parent
job's **chain cost** is computed server-side when rendering the Job Queue, not via AJAX.)

---

### `POST ?action=create` — Submit a new AI job (editor inline image / scene generation)

Creates `image` or `scene` jobs from the editor (cover image, scene image, scene-AI modal).
The client sends only user-authored params; for `scene` jobs the server augments `input_json`
with story context (`story_title`, **`story_genre`**, `story_description`) and the backward path
walk (`previous_scenes`). Note it injects **genre, not theme** — the visual theme is never sent.

**Body:** `job_type=image|scene`, `story_id`, `scene_id` (cover-image jobs send `story_id` only,
with `input_json.target = "story_cover"`), `input_json` (≤ 65535 bytes, valid JSON).

**Behaviour:** validates `ai_enabled`, job_type, required IDs, story ownership, and the
`ai_max_pending_per_user` cap, then inserts the job.

**Response:** `{ "success": true, "job_id": 91 }`
**Errors:** `503` AI disabled · `400` bad type/params/JSON · `403` not owner · `404` story · `429` rate limit

---

### `POST ?action=cancel&job_id=N` — Cancel a pending job

Only works if the job is still `pending`. Sets status to `cancelled`. Returns `400` if
the job is `running`, `completed`, or `failed` — once the cron worker picks up a job,
there is no way to abort the in-flight API call.

**Response:**
```json
{ "success": true }
```

**Error (job already running or done):**
```json
{ "success": false, "error": "Job cannot be cancelled — it is already running or complete." }
```

---

### `POST ?action=retry&job_id=N` — Retry a failed job

Resets a `failed` job back to `pending` so the cron worker picks it up again.

**Response:**
```json
{ "success": true }
```

---

### `POST ?action=apply&job_id=N` — Re-apply a completed job

Results are applied automatically by the cron worker. This endpoint is for cases where
the apply step failed — it allows a manual re-apply without re-calling the AI API.

**Response:**
```json
{ "success": true, "story_id": 15, "scene_id": 88 }
```

---

## `api_create_story_ai.php`

Accepts the full-story AI creation form (from the editor's "Use AI" panel on the new-story form).
Creates a draft story record + a `story` AI job and **returns JSON immediately** (the form's JS then
shows a success modal and redirects). Login + `ai_enabled` required; POST only.

### `POST` — Submit full-story AI creation

**Key form fields:**
```
premise, genre, tone, ai-audience, target_scenes, num_endings, word_length,
ai-include-images, ai-image-style, ai-image-mood, image_quality,
gen_title, gen_description, gen_theme, gen_genres, genres (JSON chips),
theme (+ theme_font/_heading/_bg/_text/_accent), layout, publish
```

**Behaviour:**
1. Check the `ai_max_pending_per_user` limit (top-level jobs only).
2. Resolve "Any" genre/tone/audience to concrete random values in code, and fill a blank premise
   from a `data/premises.json` seed (`ai_resolve_story_params()`). A blank image style resolves to
   one random style for the whole story.
3. Seed the draft with the user's own title/description when AI isn't generating those fields.
4. Create the draft story, then insert a `cyoa_ai_jobs` row with `job_type = 'story'`.

**Response (success):**
```json
{ "ok": true, "storyID": 144, "jobID": 91 }
```

**Response (error — rate limit / validation / disabled):**
```json
{ "ok": false, "error": "Human-readable message" }
```

> Note: this endpoint uses `ok` (not `success`) as its result key — see the convention note below.

---

## `api_social.php`

Handles ratings, favourites, and comments via AJAX. Requires a valid session.

### `POST ?action=rate`

**Body:** `story_id=N&rating=4`

**Response:**
```json
{ "success": true, "new_average": 4.2, "total_ratings": 15 }
```

Upserts the rating (one per user per story).

---

### `POST ?action=toggle_favorite`

**Body:** `story_id=N`

**Response:**
```json
{ "success": true, "is_favorited": true, "total_favorites": 8 }
```

---

### `POST ?action=comment`

**Body:** `story_id=N&comment=Great story!&reply_to=0`

(`reply_to` is 0 or omitted for top-level; a `comment_id` for replies)

**Response:**
```json
{ "success": true, "comment_id": 77, "comment_html": "<rendered comment markup>" }
```

---

### `POST ?action=delete_comment` (admin or comment owner)

**Body:** `comment_id=N`

**Response:**
```json
{ "success": true }
```

---

### `GET ?action=comments&story_id=N`

Returns all comments for a story, threaded.

**Response:**
```json
{
    "comments": [
        {
            "comment_id": 1,
            "user_name": "Alice",
            "user_image": "profile_abc.jpg",
            "comment": "Loved this story!",
            "created_at": "2026-05-15 14:30:00",
            "replies": [
                {
                    "comment_id": 3,
                    "user_name": "Bob",
                    "comment": "Same here!",
                    "created_at": "2026-05-15 15:00:00",
                    "replies": []
                }
            ]
        }
    ]
}
```

---

## `api_tree.php` (Phase 31) — Story scene-graph data for Tree View

### `GET ?storyID=N`

Returns the scene/choice graph used by the editor's Tree View (`tree-view.js`). The first scene by
`sceneID ASC` is flagged `is_start` (matching how `play.php` picks the start).

**Response:**
```json
{
    "scenes":  [ { "id": 88, "title": "Cave Entrance", "thumbnail": "ai_….png", "is_start": true } ],
    "choices": [ { "from_scene_id": 88, "to_scene_id": 89, "label": "Go left" } ]
}
```

Auth: requester must own the story or be admin.

---

## `api_content.php` — Content settings (admin, immediate persistence)

POST-only, **admin only**. Backs the chip editors + guardrails toggle on the Content Settings page,
persisting each change immediately.

### `POST` — `field` + `value`

| `field` | `value` | Writes setting |
|---|---|---|
| `genres` | JSON array of strings | `story_genres` |
| `moods` | JSON array of strings | `image_moods` |
| `styles` | JSON object `{category:[styles]}` | `image_styles` |
| `guardrails_enabled` | `"1"` / `"0"` | `guardrails_enabled` |

**Response:** `{ "success": true }` · **Errors:** `403` not admin · `405` non-POST

---

## Response Format Convention

Most endpoints use `success`; **`api_create_story_ai.php` uses `ok`** (legacy). Check the specific
endpoint above.

**Success:**
```json
{ "success": true, ... }
```

**Error:**
```json
{ "success": false, "error": "Human-readable error message" }
```

**HTTP status codes:**
- `200` — success
- `400` — bad request (missing/invalid params)
- `401` — not logged in
- `403` — not authorized (wrong owner, not admin)
- `404` — resource not found
- `429` — rate limited (too many pending jobs)
- `500` — server error

---

## Security Notes

- All endpoints are same-origin only (no CORS headers)
- POST endpoints verify a valid `$_SESSION['userID']` before acting
- Rate limits enforced server-side
- JSON body parsing: `json_decode(file_get_contents('php://input'))` for JSON, `$_POST` for form-encoded
