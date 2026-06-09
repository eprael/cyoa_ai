# CYOA AI — Architecture Diagram Explained

## What This Is

This diagram shows how a **Choose Your Own Adventure AI web application** handles story and image generation requests asynchronously. Rather than making the user wait while an AI API processes their request (which can take many seconds), the app uses a **job queue pattern** — the request is logged immediately, processed in the background by a separate worker, and the browser checks back periodically for the result.

---

## The Five Panels (Left to Right)

### 🟠 Browser

This represents the **user's web browser**. There are two things happening here:

- **At the top** — the user clicks the "Generate Story" button, which kicks off the whole flow.
- **At the bottom** — a separate JavaScript timer runs independently every 4 seconds, silently polling the database to check whether their job is done yet. When the result is ready, it displays it. This polling loop runs continuously after the button is clicked, regardless of what the server is doing.

The clock icon signals that this section is time-driven — it fires on a schedule, not in response to a user action.

---

### 🔵 Web Application (PHP)

This is the **server-side PHP application** that the browser talks to directly. It handles two distinct responsibilities:

- **Insert new row to ai_jobs table / Set status to 'pending'** — when the Generate Story button is clicked, PHP immediately writes a new job record to the database and returns a response to the browser. This happens fast — PHP is *not* calling the AI API here. It's just taking the order.
- **Get job status** — when the browser's polling loop fires every 4 seconds, it calls PHP again asking "is my job done?". PHP queries the database and returns the current status. This is a lightweight read operation.

The blue arrow connecting the Generate Story button to the Insert step shows that the button click triggers the PHP insert. The orange arrow connecting the poll loop to "Get job status" shows the repeated polling calls.

---

### 🟢 Job Queue Table (mySQL)

This is the **heart of the async pattern** — a simple database table called `ai_jobs` that acts as a queue. Every job that needs AI processing gets a row here. The table has five columns:

| Column | Description |
|--------|-------------|
| **ID** | Unique job identifier |
| **PROMPT** | The text the user submitted (e.g. "Write a scene where…") |
| **TYPE** | What kind of output is expected: Scene, Image, or Story |
| **STATUS** | Where the job is in its lifecycle (see below) |
| **AGE** | How long ago the job was created or last updated |

#### Job Statuses

| Icon | Status | Meaning |
|------|--------|---------|
| ✅ | completed | Result is ready for the browser to retrieve |
| ⚡ | running | A worker is actively processing it right now |
| ❌ | failed | Something went wrong during processing |
| 🚫 | canceled | Abandoned before completion |

The table acts as the **communication layer** between the web app and the background worker — they never talk to each other directly; they only read and write this shared table.

---

### 🟣 Cron Worker

This is a **background PHP script** that runs on a schedule (every 30 seconds, triggered by the server's cron scheduler — hence the clock). It has no web interface and users never interact with it directly. Its job is to:

1. **Pick the oldest pending job** from the queue — it looks for rows with `status = pending`
2. **Mark it as running** — it updates the row immediately so no other worker instance picks the same job
3. **Call AI API** — it sends the prompt to either Claude (for text/scenes/stories) or DALL-E (for images), depending on the job type
4. **Store the result** — it writes the AI's response back to the database row
5. **Mark as completed** — it updates the status so the browser's next poll will find it

The purple arrows fanning out to the right show the two possible AI API calls the worker can make.

---

### 🔵 Cloud (AI Services)

These are the **external AI APIs** the cron worker calls out to:

- **🧠 Claude** — handles text-based requests: writing scenes, stories, and dialogue
- **🖼️ DALL-E** — handles image generation requests

The cron worker sends a JSON payload and receives a JSON response back. These are third-party services — the application has no control over how long they take, which is exactly why the async queue exists in the first place.

---

## How a Request Flows End-to-End

```
1. User clicks "Generate Story" in the browser
        ↓
2. Browser sends request to the PHP web app
        ↓
3. PHP inserts a row into ai_jobs with status = pending, returns immediately
        ↓
4. Every 4 seconds, the browser polls PHP: "is my job done?"
        ↓ (meanwhile, in the background...)
5. Every 30 seconds, the cron worker wakes up
        ↓
6. Worker finds the pending job, marks it running, calls the AI API
        ↓
7. AI API returns result; worker stores it, marks job completed
        ↓
8. Browser's next poll finds status = completed, retrieves result
        ↓
9. Browser displays the generated story or image to the user
```

---

## Why This Pattern?

AI APIs can take anywhere from a few seconds to over a minute to respond. If PHP tried to call Claude or DALL-E synchronously during the user's HTTP request, the browser would just hang — and most web servers would time out after 30–60 seconds anyway.

The job queue pattern solves this cleanly:

- The **web server** stays fast and responsive
- The **heavy lifting** happens in the background, decoupled from the user's session
- The **user** gets a smooth experience with a progress indicator rather than a frozen page
- The **database table** acts as a durable, inspectable record of every request — easy to debug, retry, or audit

This is a well-established pattern in web development, sometimes called a **background job queue** or **task queue**, and is the same underlying concept used by libraries like Laravel Queues, Sidekiq (Ruby), Celery (Python), and Bull (Node.js) — just implemented here with a simple MySQL table and a cron job.
