# 🧊 _archive — Frozen Working Material

**Nothing in here is maintained.** This is the project-root archive of the CYOA Maker AI-upgrade
build — old working documents and scratch kept for reference only. It is a sibling of `docs/`
(which holds the current, curated documentation).

> ⚠️ **Read as snapshots, not current truth.** Every file reflects the state at the time it was
> written — file paths, schema, table/column names, and design decisions may since have changed.
> For anything current, use **`../docs/architecture/`** (the canonical, maintained reference).

## Contents

| Path | What it was |
|---|---|
| `implementation/1/` | Initial setup + early plans (phases 1–16) and the first schema migration |
| `implementation/3/` | AI integration redesign (phases 17–22) + `migration_3.sql` |
| `implementation/4/` | Social + UI + content settings (phases 23–31) + `migration_4.sql` |
| `implementation/5/` | UI polish / social enhancements |
| `implementation/6/` | Font allow-list + theme engine (phases 39–43) |
| `implementation/7/` | Per-story image gallery + the Phase 43 documentation plan |
| `implementation/testing/` | Per-phase manual testing notes |
| `ai_workflow.drawio`, `ai_job_queue_flow.md` | Early architecture/flow diagrams |
| `notes.txt`, `todo.txt`, `progress.txt` | Loose working notes / task lists |
| `prompts.txt`, `queries.txt` | Scratch prompts and SQL snippets |
| `session-start-prompt.md` | Old session bootstrap prompt |

Note: the `implementation/vN` folders are **build milestones**, not re-versions of the same thing —
together they cover one continuous build (phases 1 → 43). Internal `.claude/…` and `_dev/…`
references point at where files lived *at the time* and are intentionally left as-is.
