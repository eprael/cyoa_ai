# Phase 0 — Foundation

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

See [testing_before.md](testing_before.md) for the one-time database migration steps.

---

### API Keys — Account Page
- [ ] Log in and go to `account.php`
- [ ] Confirm an **AI API Keys** section is visible with Claude and OpenAI fields
- [ ] Enter any text (e.g. `test-claude-key`) and save
- [ ] Reload the page — the field placeholder should say "Enter new key to replace, or leave blank to keep current"
- [ ] Save with both fields blank — existing keys should be unchanged
- [ ] Clear both keys by saving a single space — confirm they clear (or test the clear behaviour)

### API Keys — Register Page
- [ ] Go to `register.php`
- [ ] Confirm an optional **AI API Keys** section is present at the bottom of the form
- [ ] Register a new test user with a Claude key filled in
- [ ] Log in as that user → go to `account.php` → confirm the key was saved

### API Keys — Admin Panel
- [ ] Log in as an admin and go to `account.php`
- [ ] Scroll to the admin user table
- [ ] Confirm **Claude Key** and **OpenAI Key** columns are present
- [ ] The test user created above should show ✓ under Claude Key and — under OpenAI Key

### Regression — Existing Features Still Work
- [ ] Existing stories appear in the gallery
- [ ] Can create a new story
- [ ] Can add a scene to a story
- [ ] Can play a story end-to-end
- [ ] Clone still works
