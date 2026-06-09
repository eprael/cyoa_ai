# Cron Setup Notes

## Confirm PHP CLI Support

Run these commands over SSH:

```bash
which php
php -v
php -r 'echo PHP_SAPI . PHP_EOL;'
```

Expected result:

- `which php` should return a PHP binary path such as `/home/evan/bin/php`
- `php -v` should say `PHP 8.3.6 (cli)` or similar
- `PHP_SAPI` should print `cli`

This confirms that the server supports PHP CLI and that cron can use it.

## PHP Binary To Use

Use this PHP binary for cron jobs:

```bash
/home/evan/bin/php
```

## Manual Test Command

Before adding cron, run the test script manually:

```bash
/home/evan/bin/php /home/evan/public_html/projects/cyoa_ai/cron/cron_test_insert.php
```

If it works, the output should look like this:

```text
Inserted cron test row #1 via cli at 2026-03-12 12:34:56
```

Then verify the insert in MySQL:

```sql
SELECT * FROM cyoa_ai_cron_test_runs ORDER BY run_id DESC;
```

## Virtualmin Cron Command

In Virtualmin, create a scheduled command like this:

```bash
/home/evan/bin/php /home/evan/public_html/projects/cyoa_ai/cron/cron_test_insert.php >> /home/evan/cron_test.log 2>&1
```

Recommended first test:

- Schedule it every 1 minute
- Let it run for 2 to 3 minutes
- Check the database table
- Check the log file `/home/evan/cron_test.log`

## Windows Task Scheduler (Local Testing)

Use this when testing on a Windows machine instead of Linux cron.

1. Open `Task Scheduler`.
2. Click `Create Basic Task...`.
3. Name it `CYOA AI Cron Test`.
4. Choose `Daily` and click `Next`.
5. Set a start time and click `Next`.
6. Choose `Start a program`.
7. In `Program/script`, set your PHP executable path, for example:

```text
C:\xampp\php\php.exe
```

8. In `Add arguments (optional)`, set the full path to the script:

```text
"O:\_school\Grade 12A (2025-2026)\WebTech10\public_html\projects\cyoa_ai\cron\cron_test_insert.php"
```

9. Finish the wizard.
10. Open the task `Properties` and in `Triggers`, edit the trigger:
- Repeat task every: `1 minute`
- For a duration of: `Indefinitely`

11. Right-click the task and click `Run` to test immediately.
12. Verify rows are being inserted:

```sql
SELECT * FROM cyoa_ai_cron_test_runs ORDER BY run_id DESC;
```

Notes:

- Task Scheduler minimum repeat interval is usually 1 minute.
- If it fails, check `Last Run Result` and the `History` tab.
- If needed, set `Start in` to the script directory:

```text
O:\_school\Grade 12A (2025-2026)\WebTech10\public_html\projects\cyoa_ai\cron
```

---

## AI Dispatcher — Windows Task Scheduler (Local Dev)

Use this to schedule `ai_dispatcher.php` on a Windows dev machine.

### Setup Steps

1. Open **Task Scheduler**.
2. Click **Create Basic Task…**
3. Name it `CYOA AI Dispatcher` and click **Next**.
4. Choose **Daily** and click **Next**.
5. Set any start time, then click **Next**.
6. Choose **Start a program**.
7. In **Program/script**, enter your PHP CLI path:

```text
C:\xampp\php\php.exe
```

8. In **Add arguments (optional)**, enter the full script path:

```text
"O:\_school\Grade 12A (2025-2026)\WebTech10\public_html\projects\cyoa_ai\cron\ai_dispatcher.php"
```

9. In **Start in (optional)**, enter the cron directory:

```text
O:\_school\Grade 12A (2025-2026)\WebTech10\public_html\projects\cyoa_ai\cron
```

10. Click **Finish** to create the task.
11. Open the task's **Properties** → **Triggers** tab → edit the trigger:
    - Repeat task every: **1 minute**
    - For a duration of: **Indefinitely**

> **Note:** Task Scheduler minimum repeat interval is 1 minute.
> The target is 30 seconds (to match production cron), but 1 minute is
> close enough for local testing.

12. Right-click the task and click **Run** to test it immediately.

### Verify It Worked

Check that the dispatcher output makes sense by running it manually first:

```text
C:\xampp\php\php.exe "O:\_school\...\cron\ai_dispatcher.php"
```

Expected output when no jobs are queued (exits silently with no output — that is correct).

Expected output when jobs are present:

```text
2026-04-16 13:00:01 — Dispatching 2 job(s)
  → Spawned worker for job #5 (scene)
  → Spawned worker for job #6 (image)
2026-04-16 13:00:01 — Dispatcher done
```

---

## AI Dispatcher — Linux Cron (Production)

Linux cron has a 1-minute minimum, but two offset entries give ~30-second polling:

```bash
* * * * * /home/evan/bin/php /home/evan/public_html/projects/cyoa_ai/cron/ai_dispatcher.php >> /home/evan/ai_dispatcher.log 2>&1
* * * * * sleep 30 && /home/evan/bin/php /home/evan/public_html/projects/cyoa_ai/cron/ai_dispatcher.php >> /home/evan/ai_dispatcher.log 2>&1
```

Add both entries in Virtualmin → Scheduled Cron Jobs.

---

## If The Script Path Is Different

The real site path might be different on the server. Common possibilities:

- `/home/evan/public_html/...`
- `/home/evan/domains/yourdomain/public_html/...`
- `/home/evan/homes/yourdomain/public_html/...`

To find the exact path, run:

```bash
find /home/evan -name cron_test_insert.php
```

Then replace the script path in the cron command with the real one.
