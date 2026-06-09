# Phase 20 — AI Job Cost Tracking

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

- Phase 17 must be complete (`AI_IMAGE_PRICING` in `config.php`, `AI_COST_INPUT_PER_M` / `AI_COST_OUTPUT_PER_M` defined)
- Phase 20.1 schema migration must be applied (`ALTER TABLE` adding cost columns to `cyoa_ai_jobs`)
- Have valid Anthropic and OpenAI API keys configured in Site Settings
- Have the cron worker running so jobs are processed during testing
- Log in as any user who can create stories and scenes

---

### 20.1 — Schema: Cost Columns on cyoa_ai_jobs

- [ ] Open phpMyAdmin and run `DESCRIBE cyoa_ai_jobs;`
- [ ] Confirm the following four columns exist:
  - `input_tokens  INT NULL`
  - `output_tokens INT NULL`
  - `image_count   INT NULL`
  - `cost_usd      DECIMAL(10,6) NULL`
- [ ] Confirm all four columns appear **after** `parent_job_id` in column order
- [ ] Confirm all four columns allow NULL (no default value required — they are filled in at job completion)
- [ ] Run `SELECT job_id, input_tokens, output_tokens, image_count, cost_usd FROM cyoa_ai_jobs LIMIT 5;` on any existing jobs — confirm all four columns return NULL (not yet populated for old jobs, which is expected)

---

### 20.2 — Cost Rate Constants

- [ ] Open `config.php` and confirm `AI_IMAGE_PRICING` is defined with all four models:
  - `gpt-image-1-mini`: low `0.005`, medium `0.011`, high `0.036`
  - `gpt-image-1`:      low `0.011`, medium `0.042`, high `0.167`
  - `gpt-image-1.5`:    low `0.009`, medium `0.034`, high `0.133`
  - `gpt-image-2`:      low `0.006`, medium `0.053`, high `0.211`
- [ ] Confirm `AI_COST_INPUT_PER_M` is defined as `3.00`
- [ ] Confirm `AI_COST_OUTPUT_PER_M` is defined as `15.00`

---

### 20.3 — DB Helper Functions

#### db_update_job_cost()

- [ ] Pick any existing completed job from `cyoa_ai_jobs` and note its `job_id`
- [ ] Add a temporary test call: `db_update_job_cost($jobID, 1000, 500, 0, 0.010500);`
- [ ] Run it (via a temp script or by placing it in any page temporarily)
- [ ] Query the job: `SELECT input_tokens, output_tokens, image_count, cost_usd FROM cyoa_ai_jobs WHERE job_id = X;`
- [ ] Confirm: `input_tokens = 1000`, `output_tokens = 500`, `image_count = 0`, `cost_usd = 0.010500`
- [ ] Remove the temporary code

#### db_get_chain_cost() — single job (no parent)

- [ ] Pick a job that has **no `parent_job_id`** and has a `cost_usd` value
- [ ] Call `db_get_chain_cost($jobID)` via a temporary script
- [ ] Confirm it returns that job's own `cost_usd` value
- [ ] Verify against: `SELECT cost_usd FROM cyoa_ai_jobs WHERE job_id = X;`

#### db_get_chain_cost() — parent with child jobs

- [ ] Find or create a parent job (one with `parent_job_id = NULL`) that has at least one child (a row where `parent_job_id = parentJobID`)
- [ ] Set cost on both: update parent to `cost_usd = 0.050000`, update child to `cost_usd = 0.006000`
- [ ] Call `db_get_chain_cost($parentJobID)`
- [ ] Confirm the return value is `0.056000` (sum of parent + child)
- [ ] Call `db_get_chain_cost($childJobID)` (passing the child's ID instead of the parent's)
- [ ] Confirm the same result `0.056000` (function finds the root regardless of which job ID is passed)

#### db_get_chain_cost() — returns null when all costs are null

- [ ] Find a parent job where both it and all its children have `cost_usd = NULL`
- [ ] Call `db_get_chain_cost()` on it
- [ ] Confirm it returns `null` (not `0`)

#### db_get_chain_cost() — partial sum while chain in progress

- [ ] Set parent job `cost_usd = 0.030000`, leave child job `cost_usd = NULL` (simulating an in-progress child)
- [ ] Call `db_get_chain_cost($parentJobID)`
- [ ] Confirm it returns `0.030000` (partial sum — only the non-null rows counted)

---

### 20.4 — Cost Logged in Cron Workers

#### Claude text job — tokens and cost recorded

- [ ] Queue a **scene generation** job (via the scene AI modal — see testing_18.md § 18.7)
- [ ] Wait for the cron worker to process it
- [ ] Query: `SELECT input_tokens, output_tokens, image_count, cost_usd FROM cyoa_ai_jobs WHERE job_type = 'scene' ORDER BY job_id DESC LIMIT 1;`
- [ ] Confirm `input_tokens` is a positive integer (> 0)
- [ ] Confirm `output_tokens` is a positive integer (> 0)
- [ ] Confirm `image_count = 0`
- [ ] Confirm `cost_usd` is a non-null positive decimal
- [ ] Manually calculate expected cost:
  `(input_tokens / 1,000,000 × 3.00) + (output_tokens / 1,000,000 × 15.00)`
- [ ] Confirm the stored `cost_usd` matches the manual calculation (within rounding precision)

#### Image job — image count and cost recorded

- [ ] Queue an **image generation** job (via the scene or story thumbnail `✨ AI` button — see testing_18.md § 18.4 or 18.8)
- [ ] Wait for the cron worker to process it
- [ ] Query: `SELECT input_tokens, output_tokens, image_count, cost_usd FROM cyoa_ai_jobs WHERE job_type = 'image' ORDER BY job_id DESC LIMIT 1;`
- [ ] Confirm `input_tokens` is NULL (not recorded for image jobs)
- [ ] Confirm `output_tokens` is NULL
- [ ] Confirm `image_count = 1`
- [ ] Confirm `cost_usd` is non-null and matches `AI_IMAGE_PRICING['gpt-image-2'][$quality]` (using the quality that was selected when the job was queued)

#### create_story job — cost recorded for each phase

- [ ] Queue a **create_story** job with AI on (see testing_18.md § 18.5)
- [ ] Wait for the cron worker to complete all phases
- [ ] Query the parent `create_story` job — confirm `cost_usd` is non-null (from the properties and/or story-plan Claude calls)
- [ ] If scenes were also generated, confirm child scene jobs each have their own `cost_usd`
- [ ] If images were requested, confirm child image jobs each have `cost_usd` and `image_count = 1`

---

### 20.5 — Cost Display in Job Queue UI

#### Cost label appears for root jobs

- [ ] Go to `job_queue.php`
- [ ] Find a completed root job (one with `parent_job_id = NULL` and `cost_usd` populated)
- [ ] Confirm a cost label is visible on that job's row (e.g. `$0.0105`)
- [ ] Confirm the label uses a small, muted, monospace style

#### Chain cost shown for root job (not child)

- [ ] Find a completed root job that has completed child jobs
- [ ] Confirm the cost label on the **root job** shows the **total chain cost** (sum of parent + all children)
- [ ] Find a child job row in the queue
- [ ] Confirm the child job row shows **no cost label**

#### Partial cost with "…" suffix while chain is running

- [ ] Queue a `create_story` job that will generate images (so child jobs are queued after the parent completes)
- [ ] While the parent job is completed but at least one child image job is still `pending` or `running`:
  - [ ] Go to `job_queue.php`
  - [ ] Confirm the root job shows a partial cost followed by `…` (e.g. `$0.0300 …`)
- [ ] Wait for all child jobs to complete
- [ ] Reload `job_queue.php` — confirm the `…` suffix is gone and the final total is shown

#### No cost label for zero-cost or incomplete jobs

- [ ] Find a job where `cost_usd` is NULL (e.g. an older job from before Phase 20)
- [ ] Confirm **no cost label** is shown for that job

#### Cost label styling

- [ ] Confirm the `.job-cost` CSS class is applied to cost labels
- [ ] Confirm the font is monospace
- [ ] Confirm the colour is muted / lighter than the main job text
- [ ] Confirm the font size is smaller than the job title text

---

### Edge Cases

#### Two jobs with identical chains — costs are independent

- [ ] Create two separate stories with AI (two separate `create_story` jobs, each with their own children)
- [ ] Confirm the chain cost for Job A does not include costs from Job B's children

#### Model change mid-use

- [ ] Change the Image Model in Site Settings from `gpt-image-2` to `gpt-image-1`
- [ ] Queue and complete a new image job
- [ ] Confirm `cost_usd` stored matches `AI_IMAGE_PRICING['gpt-image-1'][$quality]` (the model active at time of job)
- [ ] Change the model back to `gpt-image-2` in Site Settings

---

### Regression

- [ ] Confirm existing jobs (created before Phase 20) still display correctly in `job_queue.php` — their cost columns are NULL but no errors occur
- [ ] Confirm the scene AI modal still works and queues jobs correctly
- [ ] Confirm the image AI button still works and queues jobs correctly
- [ ] Confirm `db_update_job_cost()` does not interfere with job status updates (jobs still transition from `running` → `completed` correctly)
- [ ] Confirm the job queue page loads without PHP errors
- [ ] Confirm the gallery, editor, and play pages load without errors (cost tracking is backend-only for all pages except the job queue)
