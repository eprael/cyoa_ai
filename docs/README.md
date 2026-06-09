# CYOA Maker — Documentation

Map of everything under `docs/`. The golden rule: **only `architecture/` is kept current.**

| Folder | Status | What's in it |
|---|---|---|
| **`architecture/`** | 🟢 **Current** — canonical reference, kept in sync with the code | Technical architecture, API endpoints, AI prompt logic, and the DB schema dump |
| `proposal/` | 📄 Deliverable | Project proposal / planning docs |
| `presentation/` | 📄 Deliverable | Class/teacher demo materials |
| `report/` | 📄 Deliverable | Write-up(s) explaining the app |
| `visualizations/` | 🔖 Reference | Diagrams / visual aids |
| `claude tips/` | 🔖 Reference | Notes on working with Claude/Claude Code |

> **Frozen history lives outside `docs/`.** Old working docs (implementation plans, migrations,
> testing notes, scratch) are in **`../_archive/`** — a project-root sibling, kept deliberately
> separate so it's easy to spot. It is **not maintained**; when in doubt, trust `architecture/`.

`.claude/` (at the project root) holds only Claude Code config — not documentation.
`CLAUDE.md` (also at the project root) is the entry point and points back into `architecture/`.
