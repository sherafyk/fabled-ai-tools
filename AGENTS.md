# AGENTS.md

## Purpose
This repository contains a WordPress plugin: **Fabled AI Tools**.

Your job is to implement a **minimal, reliable feature** that lets a user either:

1. paste article body text manually, or
2. select an existing WordPress **draft** or **published post** and have the plugin pull the content automatically.

Do **not** redesign the plugin architecture.
Do **not** convert this into a React app.
Do **not** add external dependencies.
Keep changes small, readable, and backward-compatible.

---

## Goal
Add a practical admin-side enhancement to the tool runner so users can choose:

- **Paste Content**
- **Select Draft**
- **Select Published Post**

If a draft or published post is selected, the plugin should:

- fetch the selected post server-side
- extract clean text from `post_content`
- populate the effective `article_body` input before prompt rendering
- optionally auto-fill `title`
- optionally auto-fill `url` when the selected post is published

The feature should work especially well for tools that already use:

- `article_body`
- `title`
- `url`

---

## Constraints
- Keep this a **WordPress plugin-first** implementation.
- Use existing plugin structure and patterns.
- No database schema changes.
- No new custom tables.
- No front-end/public UI.
- No heavy abstraction.
- No third-party libraries.
- No build step.
- Preserve current tool behavior when post selection is not used.

---

## Existing Plugin Areas
You will likely need to touch these files:

- `includes/class-fat-admin.php`
- `includes/class-fat-tool-runner.php`
- `assets/admin.js`
- `assets/admin.css` (only if needed)

You may add small helper methods, but avoid adding unnecessary new classes.

---

## Required Behavior

### 1. Runner UI
In the admin runner UI, for tools that include an `article_body` field, add a simple content source control:

- Paste Content
- Select Draft
- Select Published Post

If the user chooses draft or published post:
- show a dropdown of matching posts
- hide or de-emphasize manual paste input if appropriate

Keep the UX simple and admin-friendly.

### 2. Draft vs Published Separation
Do **not** use one mixed dropdown if avoidable.
Preferred UX:
- first choose content source
- then load a filtered dropdown for that source

### 3. Server-Side Resolution
Never trust the browser alone.
Before tool input validation and before prompt rendering:
- inspect submitted runner inputs
- if the user selected a post
- fetch it with WordPress APIs
- verify status is allowed (`draft` or `publish`)
- normalize content to plain text
- inject into the effective tool inputs

### 4. Content Normalization
Use a simple normalization approach suitable for editorial AI tasks:
- remove shortcodes
- strip HTML/tags
- collapse whitespace
- trim result

### 5. Auto-Fill Mapping
If available and relevant:
- map `post_title` into `title` when title is empty
- map permalink into `url` when the selected post is published and `url` is empty

Do not overwrite manually provided values unless there is a clear reason.

### 6. Security
Use WordPress capability checks for any AJAX/admin endpoint.
Sanitize all request values.
Validate selected post IDs server-side.
Do not expose anything publicly.

---

## Implementation Plan
Follow this order.

### Step 1 — Inspect current runner flow
Understand how:
- the runner form is rendered
- the JS submits tool inputs
- the REST endpoint receives inputs
- `FAT_Tool_Runner::execute()` validates and renders prompts

Do not refactor unrelated code.

### Step 2 — Add admin-side post lookup endpoint
Add a small authenticated admin AJAX endpoint in `class-fat-admin.php` that returns recent posts for a requested status.

Requirements:
- allow only authenticated users with tool-running capability
- accept status = `draft` or `publish`
- return a small list of posts ordered by modified date descending
- include at least `id` and `title`
- keep it simple; no search UI in v1

### Step 3 — Update runner JS
In `assets/admin.js`:
- detect whether selected tool has an `article_body` field
- render a content source selector
- when source is `draft` or `publish`, request matching posts from the AJAX endpoint
- show a dropdown of choices
- include hidden/submitted values alongside normal inputs

Keep manual paste behavior unchanged when `Paste Content` is selected.

### Step 4 — Resolve selected post server-side
In `class-fat-tool-runner.php`:
- add a helper method that inspects raw inputs for source selection
- if a post is selected, fetch it with `get_post()`
- ensure the status is allowed
- normalize content
- replace effective `article_body`
- optionally fill `title` and `url`

This should happen **before** the existing runtime input validation.

### Step 5 — Keep backward compatibility
If no post is selected:
- plugin should behave exactly as before

If a tool does not use `article_body`:
- do not force the content source UI

### Step 6 — Test manually
After changes, verify:
- existing pasted-body tools still work
- draft selection works
- published post selection works
- excerpt tool still returns correct output
- combined tool can auto-fill from selected post
- invalid or missing post selection fails cleanly

---

## Acceptance Criteria
The feature is complete when all are true:

1. A user can run the SEO Excerpt tool by selecting a draft instead of pasting body text.
2. A user can run the Featured Image Prompt tool by selecting a published post instead of pasting body text.
3. The Combined Publishing Tool can use selected post content and auto-fill title/url where appropriate.
4. Existing tools still work with manual paste.
5. No database migrations were added.
6. No plugin-wide architectural rewrite was introduced.
7. All checks remain server-side and secure.

---

## Coding Preferences
- Prefer small patches over large rewrites.
- Reuse current plugin patterns.
- Keep method names explicit.
- Add comments only where they help.
- Avoid cleverness.
- Keep output production-leaning.

---

## Deliverable Format
When making changes, provide:

1. a short summary of what changed
2. the exact files changed
3. complete updated file contents for each changed file
4. a short test checklist

Do not return vague pseudocode.
Do not omit changed code with placeholders.

---

## Non-Goals
Do not implement these in this task:
- searchable dropdowns
- Select2
- Gutenberg sidebar integration
- custom post type configuration UI
- content preview modal
- autosave integrations
- automatic post updates
- front-end forms

Those can come later.

---

## Final Guidance
This should be implemented as a **focused v1.1 enhancement** to the existing plugin.
Optimize for:

1. simplicity
2. reliability
3. backward compatibility
4. low-risk changes
5. admin usability
