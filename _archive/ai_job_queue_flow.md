# AI Job Queue Flow

## Main Flow (All Job Types)

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TB
    subgraph U["User / Browser Lane"]
        U1["Author clicks Generate with AI"] --> U2["JS sends POST to api_jobs.php?action=create"]
        U2 --> U3["Server validates input + rate limit check"]
        U3 --> U4["Create ai_jobs row — status: pending"]
        U4 --> U5["Return job_id to browser"]
        U5 --> U6["Browser starts polling api_jobs.php?action=status"]
        U6 --> U7{"Poll response"}
        U7 -- "pending / running" --> U8["Show spinner + status text"]
        U8 --> U6
        U7 -- "completed" --> U9["Call api_jobs.php?action=apply"]
        U9 --> U10["Apply result to story/storypoint"]
        U10 --> U11["Set story status = draft"]
        U11 --> U12["Show success notification"]
        U7 -- "failed" --> U13["Show error message + retry option"]
    end

    subgraph C["Cron Worker Lane (every 30s)"]
        C1["Cron triggers ai_worker.php"] --> C1b{"Stale running job?"}
        C1b -- "Yes - over 5 min" --> C1c["Mark stale job as failed"]
        C1b -- "No" --> C2
        C1c --> C2["SELECT oldest pending job"]
        C2 --> C3{"Job found?"}
        C3 -- "No" --> C8["Exit — nothing to do"]
        C3 -- "Yes" --> C4["UPDATE status = running, started_at = NOW"]
        C4 --> C5{"job_type?"}
        C5 -- "image" --> C6a["Call DALL-E 3 API"]
        C5 -- "scene" --> C6b["Call Claude API — single scene"]
        C5 -- "full_story" --> C6c["Call Claude API — plan then write each scene"]
        C6a --> C7{"Success?"}
        C6b --> C7
        C6c --> C7
        C7 -- "Yes" --> C10["Store result_json — mark completed"]
        C7 -- "No" --> C9["Store error_message — mark failed"]
    end

    U4 -. "queued job" .-> C2
    C10 -. "completed" .-> U7
    C9 -. "failed" .-> U7
```

## Full Story Generation Detail (Level 3)

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TB
    FS1["ai_story_handler.php starts"] --> FS2["Phase 1: Send premise to Claude"]
    FS2 --> FS3["Receive story plan JSON — title, scenes, choice graph"]
    FS3 --> FS4["Validate plan structure"]
    FS4 --> FS5{"Plan valid?"}
    FS5 -- "No" --> FS6["Retry once with correction prompt"]
    FS6 --> FS5
    FS5 -- "Yes" --> FS7["Phase 2: Loop through each scene"]
    FS7 --> FS8["Send scene context + plan to Claude"]
    FS8 --> FS9["Receive scene content — title, description, hint"]
    FS9 --> FS10{"More scenes?"}
    FS10 -- "Yes" --> FS8
    FS10 -- "No" --> FS11["Assemble complete story JSON"]
    FS11 --> FS12["Store in result_json — mark completed"]
```

## Result Application Detail

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart LR
    A1{"job_type?"} -- "image" --> A2["Save image file to images/storyID/"]
    A2 --> A3["Update storypoint image + image_gen fields"]
    A1 -- "scene" --> A4["Create or update storypoint"]
    A4 --> A5["Save choices with destination IDs"]
    A1 -- "full_story" --> A6["Create story record"]
    A6 --> A7["Create all storypoints — build temp_id to real ID map"]
    A7 --> A8["Create all choices with remapped destination IDs"]
    A3 --> A9["Set story status = draft"]
    A5 --> A9
    A8 --> A9
```

## File Map

| File | Role |
|------|------|
| `api_jobs.php` | Browser-facing API (create, status, apply, cancel, retry, list) |
| `cron/ai_worker.php` | Cron entry point — picks up jobs, dispatches to handlers |
| `cron/ai_image_handler.php` | DALL-E API call + image download |
| `cron/ai_scene_handler.php` | Claude API call for single scene |
| `cron/ai_story_handler.php` | Claude API call for full story (two-phase) |
| `cron/ai_apply.php` | Functions to write results into story/storypoint tables |
