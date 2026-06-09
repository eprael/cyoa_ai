# AI Prompt Templates

This document describes the prompt engineering strategy for each AI generation level.
Prompt text lives in external `.txt` files under `prompts/` and is loaded at runtime via
`load_prompt(string $name, array $vars = [])`. PHP builds the user prompt dynamically in
the cron handler and passes it to the API alongside the loaded system prompt.

---

## Level 1 — Image Generation (OpenAI, model configurable)

### System Prompt

Two templates, selected by whether the job is a story cover:
- `prompts/image_system.txt` — scene images
- `prompts/cover_image_system.txt` — the story cover (book-cover/poster framing, landscape, safe
  margins for 16:9 / 3:2 crops)

`image_system.txt`:
```
Generate an illustration for a choose-your-own-adventure story.
Style: Digital illustration, vivid colours, suitable for a web story.
Do not include any text or lettering in the image.
```

> **No theme.** The visual *theme* (colours/fonts) is NOT sent to image generation — there is no
> `{THEME_LINE}` placeholder anymore, and no per-theme style table. Look comes from the base
> template plus the user's explicit **style** (and optional **mood**) selection only.

### Prompt Composition (in `ai_image_handler.php`)

The chosen style + mood are appended to the subject prompt:

```php
$stylePart = $style ? " in {$style} style" : ($category ? " in {$category} style" : '');
$moodPart  = $mood  ? ", {$mood}" : '';
$fullPrompt = $systemCtx . "\n\n" . $prompt . $stylePart . $moodPart;
```

- **Style** comes from the single combined image-style dropdown (categories are just `optgroup`
  headers). `category` is only a legacy fallback — manual picks send an empty category and let the
  style speak for itself.
- A **blank** style at story-creation time resolves to one random style for the *whole* story
  (`ai_random_image_style()`), so every image shares a consistent look.
- `mood` is an optional modifier from `image_moods`; omitted when blank ("Skip").

### API Parameters

| Parameter | Value |
|-----------|-------|
| Model | `app_setting('openai_image_model')` — default `gpt-image-2` |
| Size | `1024x1024` |
| Quality | `app_setting('openai_image_quality')` — default `medium` (or per-user override) |
| Output format | `app_setting('openai_image_format')` — default `jpeg` (Phase 27) |
| N | `1` |

### Post-Processing

1. Download the generated image from the returned URL (or decode base64 if format is b64_json)
2. Save to `images/stories/{storyID}/ai_{timestamp}_{random}.{ext}` (extension from format setting)
3. Validate file size (reject if > 5 MB)
4. Store the filename and prompt used in `result_json`

---

## Level 2 — Scene Generation (Claude API)

### System Prompt

Template file: `prompts/scene_system.txt`

The system prompt instructs Claude to act as a creative writing assistant for a single
scene in a branching narrative. It requires a specific JSON return structure:

```json
{
    "title": "Scene title (short, compelling, max 60 characters)",
    "description": "Scene narrative (2-4 paragraphs, second person 'you', HTML allowed: <p> <em> <strong>)",
    "hint": "Subtle player hint (1 sentence, or empty string)",
    "image_prompt": "Visual description of scene's key moment (1-2 sentences, no text/lettering)",
    "choices": [
        { "text": "Choice button text (action-oriented, max 80 characters)" }
    ]
}
```

Key rules from the prompt:
- Second person ("You enter the room...")
- Match tone and genre of the existing story
- If mode is `ending`: return empty `choices []`, write a satisfying conclusion
- If mode is `continue`: generate the specified number of choices
- Build tension based on story depth (using `previous_scenes` context)

### Building `previous_scenes` (Backward Path Walk)

The backward path walk finds the narrative thread leading to the target scene:

1. Start from the target `sceneID`
2. Find the choice whose `destinationID` = this scene → get its source scene
3. Repeat until reaching the opening scene (no incoming choices)
4. Reverse the list → ordered root-to-current
5. If multiple incoming choices exist (converging paths), pick the first found
6. If the target scene is the opening scene, `previous_scenes` is empty

This is done server-side when building `input_json` before inserting the job.

### User Prompt Construction

```
Story: "{story_title}"
Genre: {story_genre}
Story description: "{story_description}"

The player's path through the story so far (in order):
- "{scene.title}": {scene.description first 200 chars}...
  Player chose: "{choice_taken}"

Direction for this scene: "{user_direction}"
Mode: {mode}  (continue | ending)
[Ending type: {ending_type}]  (success | death — only if mode=ending)
Tone: {tone}
[Number of choices to generate: {num_choices}]  (only if mode=continue)
```

> The semantic hint is the story's **genre** (`story_genre`), injected server-side by
> `api_jobs.php?action=create`. The visual **theme** (colours/fonts) is deliberately never sent.

### API Parameters

| Parameter | Value |
|-----------|-------|
| Model | `app_setting('anthropic_model')` — default `claude-sonnet-4-6` |
| Max tokens | `1500` |
| Temperature | `0.8` |

### Key Resolution (BYOK)

If the job's user has a non-empty `claude_api_key` stored in `cyoa_ai_users`, that key is
used instead of `app_setting('anthropic_api_key')`.

### Validation

1. Parse as JSON — retry once with "please return valid JSON only" if invalid
2. Verify required fields: `title`, `description`, `image_prompt`, `choices`
3. Truncate `title` to 60 chars, `hint` to 255 chars if needed
4. Sanitize `description` — allow only: `<p>` `<em>` `<strong>` `<br>`

### Apply Logic

1. Update the target scene with generated `title`, `description`, `hint`
2. If "Generate image" was checked, queue an `image` job using `image_prompt`
3. For each choice: create a stub scene (title from choice text, empty description) and
   a `choices` row linking source → stub

---

## Level 3 — Full Story Generation (Claude API, Multi-Phase)

Full-story generation uses the `story` job type and runs through sequential phases inside
`cron/ai_story_handler.php`. The story record is pre-created as a `draft` before the job starts;
the handler fills it in and `cron/ai_apply.php` writes the result.

### Phase 0: Premise & parameter resolution (at submission)

Before the job is queued, `ai_resolve_story_params()` resolves "Any" genre/tone/audience to concrete
random values **in code**, and fills a **blank premise** from a `data/premises.json` seed
(`ai_pick_seed()` — filtered by genre, or the whole list when genre is "Any"; CLI/batch runs pick
without replacement). So the handler always receives a concrete premise + parameters. There is **no
separate "story properties" Claude phase** — the plan below provides title/description, and whether
they overwrite the story is gated by the `gen_title` / `gen_description` flags (when off, the user's
own values, seeded at creation, are kept). Likewise **theme** is the user's choice unless `gen_theme`
is set, and is visual-only (never part of any prompt).

### Phase 1: Story Planning

**System Prompt:** `prompts/story_plan_system.txt`

Instructs Claude to return a complete structural plan as JSON:

```json
{
    "title": "Story title",
    "description": "One-paragraph premise for the gallery (50-100 words)",
    "suggested_theme": "<a preset key from data/themes.json>",
    "scenes": [
        {
            "temp_id": "sp_1",
            "title": "Scene title",
            "summary": "1-2 sentence summary",
            "scene_type": "opening|mid_story|climax|ending",
            "choices": [
                { "text": "Brief choice description", "dest_temp_id": "sp_2" }
            ]
        }
    ]
}
```

Key rules:
- `sp_1` is always the story opening
- Branching tree, not linear — choices diverge
- Include `{NUM_ENDINGS}` distinct endings (scenes with empty choices)
- Target `{TARGET_SCENES}` total scenes
- Every non-ending scene must have 2–4 choices

**User Prompt:**
```
Premise: "{premise}"
Tone: {tone}
Target number of scenes: {target_scenes}
Number of endings: {num_endings}
Audience: {audience}
```

**API Parameters:**

| Parameter | Value |
|-----------|-------|
| Model | `app_setting('anthropic_model')` |
| Max tokens | `4000` |
| Temperature | `0.7` |

> `suggested_theme` is **advisory only** — applied solely when `gen_theme` is set; otherwise the
> user's chosen theme wins. Either way it affects only the visual presentation, not generation.

### Phase 2: Scene Writing (looped)

For each scene in the plan, one Claude request writes the full content.

**System Prompt:** `prompts/story_scene_writer_system.txt`

Returns JSON for a single scene:
```json
{
    "title": "Scene title",
    "description": "Full scene narrative (2-4 paragraphs, second person, HTML allowed)",
    "hint": "Optional player hint (1 sentence or empty string)"
}
```

**User Prompt:**
```
Full story plan:
{JSON of Phase 2 plan}

Now write scene "{scene.temp_id}": {scene.summary}
This scene's choices lead to: {list of destination scene summaries}
```

**API Parameters:**

| Parameter | Value |
|-----------|-------|
| Model | `app_setting('anthropic_model')` |
| Max tokens | `1200` |
| Temperature | `0.8` |

### Phase 3: Image Generation (optional)

If images were included, child `image` jobs are queued for the story cover and each scene after all
scenes are written. These run as independent jobs through the same queue with `parent_job_id`
pointing to the `story` job.

### Assembly

1. The apply step (`cron/ai_apply.php`) creates scene and choice rows, building a map of
   `temp_id → real sceneID`.
2. All choices are inserted with destination IDs remapped from `temp_id` to real IDs.
3. The story stays in `draft` — then **auto-publishes only if** the `publish` flag was set, images
   were included, and every job (parent + all child image jobs) succeeds
   (`maybe_publish_created_story()`). Otherwise it remains a draft for manual publishing.

---

## Audience Targeting (Phase 27)

When the `audience` field is set in job input, a `{AUDIENCE}` placeholder is substituted
into both planning and scene-writing prompts:

| Value | Writing guidance |
|---|---|
| `picture_book` | Very simple vocabulary, 1–2 sentence scenes, concrete imagery |
| `early_readers` | Simple words, short sentences, relatable characters, age 6–9 |
| `middle_grade` | Moderate complexity, relatable themes, age 9–12 |
| `young_adults` | Nuanced themes, more complex choices, age 13–18 |
| `adults` | Unrestricted complexity, mature themes acceptable |

---

## Guardrails (Phase 28)

When `guardrails_enabled` is true and `guardrails_text` is non-empty, a guardrail clause
is appended to all Claude system prompts:

```
Content guardrails: Never generate content involving: {comma-separated topics}.
If any part of your response would involve these topics, include a "red_flag" field
in your JSON response with the name of the breached topic as its string value.
```

For OpenAI image prompts, the clause is prepended:
```
Do not depict: {comma-separated topics}. {rest of prompt}
```

On red_flag detection: job status → `error`, event logged to `logs/guardrails_YYYYMMDD.log`.

---

## Error Handling

| Error | Handling |
|-------|----------|
| Invalid JSON response | Retry once with "please return valid JSON only" appended |
| API rate limit (429) | Retry up to 5 times with a 20-second delay between attempts; log each retry attempt. If all retries exhausted, mark job `failed`. |
| API timeout | Mark job `failed`; dispatcher catches stale `running` jobs |
| Red flag / guardrail breach | Mark job `error` with breach message; log event |
| Partial completion (story) | Mark `completed_with_errors`; store partial results in `result_json` |

---

## Token / Cost Awareness

Approximate usage per job type (varies by context length):

| Type | Input tokens | Output tokens | Approx. cost |
|------|-------------|---------------|--------------|
| Image | N/A | N/A | ~$0.05 (gpt-image-2, medium quality) |
| Scene | ~500–1000 | ~500–1000 | ~$0.005 |
| Story (12 scenes) | ~1000 plan + ~800×12 | ~2000 plan + ~600×12 | ~$0.10–0.20 |

Actual costs depend on context length, model, and image quality. Per-job costs are tracked
in `ai_jobs.cost_usd` and summed via `db_get_chain_cost()` for parent jobs.
