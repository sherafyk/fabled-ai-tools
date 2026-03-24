# AGENTS.md

## Purpose
This repository contains a WordPress plugin: **Fabled AI Tools**.

Your job is to implement the **next practical layer** of the plugin so generated outputs can optionally be **applied back into WordPress content**.

This work must support two closely related workflows:

1. **Post / draft update workflow**
   - A user runs a tool against pasted content or a selected WordPress post.
   - After generation, the user can optionally apply selected outputs back to the chosen post or draft.
   - Immediate example: generate an SEO excerpt, then apply it to `post_excerpt`.

2. **Media metadata workflow**
   - A user selects an attachment from the Media Library.
   - The tool generates fields such as **title**, **alt text**, **caption**, and **description**.
   - The user can choose which generated fields to apply back to the attachment.

Do **not** redesign the plugin.
Do **not** convert this into React.
Do **not** add external dependencies.
Keep the implementation **WordPress-native, minimal, and backward-compatible**.

---

## Product Goal
Extend the plugin from **generate-only** into **generate + optional apply**.

The safest and most maintainable UX for this codebase is:

1. user selects source content or media
2. tool runs and returns outputs
3. plugin shows an **Apply to WordPress** panel
4. user explicitly checks which fields to update
5. plugin updates the selected post or attachment server-side

Do **not** auto-save generated outputs immediately after generation.
Require an explicit apply step.

This keeps the current runner model intact and adds a controlled editorial workflow.

---

## Recommended Strategy
Use a **two-step apply flow** instead of trying to update WordPress during the initial run request.

### Why this is the right fit
- It matches the current architecture: the plugin already has a clear **runner request** and **rendered outputs** flow.
- It avoids accidental content overwrites.
- It keeps generation logging separate from content mutation.
- It works for both posts and attachments.
- It is flexible enough for future tools without requiring a large rewrite.

### Chosen implementation shape
Add **small optional WordPress integration config** to each tool so the admin can define:

- whether the tool can source content from a **post** or **attachment**
- whether the tool can apply outputs to a **post** or **attachment**
- which output keys map to which WordPress fields

Use a single new JSON field on the tool record for this configuration rather than hardcoding behavior based only on output keys.

---

## Constraints
- Keep this a **WordPress plugin-first** implementation.
- Use the existing plugin structure and coding style.
- No external packages.
- No build step.
- No public/front-end UI.
- No Gutenberg integration.
- No React/Vue/SPA rewrite.
- No broad architectural refactor.
- Preserve existing generate-only behavior when no WordPress integration is configured.
- Prefer explicit mappings over magic inference.
- Require user confirmation before updating posts or attachments.

A **small database schema change is allowed** for tool configuration because this feature needs structured per-tool mapping rules and should not be hardcoded per slug.

---

## Existing Plugin Areas
These files are the core implementation surface and should be used instead of inventing new architecture:

- `includes/class-fat-activator.php`
- `includes/class-fat-tools-repository.php`
- `includes/class-fat-tool-validator.php`
- `includes/class-fat-admin.php`
- `includes/class-fat-rest-controller.php`
- `includes/class-fat-tool-runner.php`
- `includes/class-fat-openai-client.php`
- `includes/class-fat-helpers.php`
- `assets/admin.js`
- `assets/admin.css` (only if needed)

Current relevant behavior already exists:
- the runner page is rendered in `class-fat-admin.php`
- tools are sent to the runner via `public_tool_definition()`
- tool execution happens through the REST route in `class-fat-rest-controller.php`
- the runner already supports **post-based article body sourcing**
- outputs are already rendered client-side in `assets/admin.js`
- the plugin already stores tool definitions in the custom `fat_tools` table

Build on these patterns.

---

## Best Implementation Approach

### 1. Keep generation and apply separate
Do not combine “run tool” and “save to WordPress” into one request.

Instead:
- `/run` generates outputs
- `/apply` applies selected outputs to WordPress

This is safer, easier to debug, and much easier to make optional.

### 2. Add per-tool WordPress integration config
Add one new JSON field to the tools table, for example:
- `wp_integration`

Store structured config such as:
- source mode support
- apply target type
- output-to-WP-field mappings

Example shape:

```json
{
  "source": {
    "type": "post",
    "allow_manual": true,
    "allow_draft": true,
    "allow_publish": true,
    "allow_attachment": false
  },
  "apply": {
    "target": "post",
    "mappings": [
      { "output_key": "excerpt", "wp_field": "post_excerpt", "label": "Post Excerpt" }
    ]
  }
}
```

For media metadata:

```json
{
  "source": {
    "type": "attachment",
    "allow_manual": false,
    "allow_attachment": true
  },
  "apply": {
    "target": "attachment",
    "mappings": [
      { "output_key": "title", "wp_field": "post_title", "label": "Media Title" },
      { "output_key": "alt_text", "wp_field": "alt_text", "label": "Alt Text" },
      { "output_key": "caption", "wp_field": "post_excerpt", "label": "Caption" },
      { "output_key": "description", "wp_field": "post_content", "label": "Description" }
    ]
  }
}
```

Keep the structure simple. Do not attempt arbitrary nested workflow configuration.

### 3. Add a dedicated apply endpoint
Add a new authenticated REST route for applying generated outputs:
- `POST /fabled-ai-tools/v1/apply`

This endpoint should:
- validate the current user can run the tool
- validate the selected target entity belongs to an allowed type
- validate the requested output keys are allowed by the tool’s mapping config
- update only the requested checked fields
- return a success response with the updated field list

### 4. For media metadata tools, prefer actual attachment context
For attachment-based tools:
- resolve the attachment server-side
- include attachment title, caption, description, alt text, filename, mime type, and URL in the tool inputs
- when possible, also include the image itself as an image input to the OpenAI Responses request for better metadata generation quality

If image input support is added, keep it **optional and tool-driven**.
Do not break existing text-only tools.

### 5. Do not hardcode behavior to specific tool slugs
Avoid special cases like:
- `if slug === 'seo-excerpt'`
- `if output key === 'excerpt'`

Use tool configuration and output mappings instead.

---

## Required Behavior

### A. Post / Draft update workflow
For tools configured with post sourcing and post apply mappings:

#### Runner UX
- continue supporting:
  - Paste Content
  - Select Draft
  - Select Published Post
- if the tool supports post apply mappings, and a post is selected, show an **Apply to Post** section after generation
- show one checkbox per allowed mapping
- example: generated `excerpt` can map to `post_excerpt`
- include an **Apply Selected Fields** button

#### Apply rules
- never apply automatically just because generation succeeded
- only apply fields the user explicitly checked
- only update fields configured in the tool’s mapping config
- if the source is a selected post, default the apply target to that same post
- if the source is plain text and the tool supports post apply, allow selecting a target draft/post before applying

#### Supported post fields in v1
Keep the allowed post update surface intentionally small:
- `post_title`
- `post_excerpt`
- `post_content`
- optionally selected post meta keys later, but **do not** implement arbitrary meta writes in v1 unless absolutely necessary

For this task, prioritize:
- `post_excerpt`
- `post_title`

### B. Media metadata workflow
For tools configured with attachment sourcing and attachment apply mappings:

#### Runner UX
- show a media source selector only for tools configured for attachments
- load a simple attachment dropdown via authenticated admin AJAX
- once an attachment is selected, run the tool and display outputs
- after generation, show an **Apply to Media** section with one checkbox per allowed mapping

#### Supported attachment fields in v1
Allow only these explicit mappings:
- `post_title` => media title
- `alt_text` => `_wp_attachment_image_alt`
- `post_excerpt` => caption
- `post_content` => description

Do not support arbitrary attachment meta writes in v1.

#### Attachment generation context
Before generation, resolve the selected attachment server-side and provide these effective inputs when relevant:
- attachment ID
- title
- caption
- description
- alt text
- file URL
- filename
- mime type
- parent post title if available

If the selected tool requires visual understanding, extend the OpenAI client carefully so a tool can optionally send the attachment as an image input as part of the Responses API request.

---

## Security Requirements
- All lookups and updates must remain admin-side only.
- Use current capability checks, not UI trust.
- Sanitize all request values.
- Validate all selected post and attachment IDs server-side.
- Reject mismatched entity types.
- Reject unsupported mappings.
- Reject updates for fields not declared in the tool’s apply mapping config.
- Use `wp_update_post()` for post and attachment post-field updates.
- Use `update_post_meta( $attachment_id, '_wp_attachment_image_alt', ... )` for alt text.
- Require explicit nonce/auth validation for AJAX and REST operations.

Do not expose anything publicly.

---

## Implementation Plan
Follow this order.

### Step 1 — Inspect current runner and source flow
Understand exactly how these work before changing anything:
- tool edit form save flow
- tool validation
- runner page rendering
- localized public tool definitions
- REST run request
- output rendering
- existing post-source resolution in `FAT_Tool_Runner::resolve_inputs_from_selected_post()`

Confirm what is already implemented for:
- selected post source
- auto-fill behavior
- runner AJAX lookups

Do not refactor unrelated code.

### Step 2 — Add tool-level WordPress integration config
Add a new nullable JSON-backed tool column, e.g. `wp_integration`, using the existing `dbDelta()` upgrade pattern.

Files:
- `includes/class-fat-activator.php`
- `includes/class-fat-tools-repository.php`
- `includes/class-fat-tool-validator.php`
- `includes/class-fat-admin.php`

Requirements:
- add the column to the tools table
- persist it in the repository
- decode/encode it like existing schema fields
- add admin form controls to configure:
  - source type support (`post`, `attachment`, or none)
  - whether manual content is allowed
  - apply target (`post`, `attachment`, or none)
  - output-to-WordPress field mappings
- keep the UI basic and table-driven like existing schema editing

Do not build a full visual rule engine.

### Step 3 — Validate the integration config
In `class-fat-tool-validator.php`, validate the new config.

Requirements:
- reject invalid source types
- reject invalid apply target types
- reject duplicate mapping rows
- reject mappings for output keys that do not exist in `output_schema`
- reject unsupported `wp_field` values
- require source/apply target compatibility
  - post apply mappings require `target = post`
  - attachment mappings require `target = attachment`

Allowed `wp_field` values for v1:
- `post_title`
- `post_excerpt`
- `post_content`
- `alt_text`

Do not allow arbitrary field names.

### Step 4 — Expand runner lookup endpoints
Add any missing lookup endpoints in `class-fat-admin.php`.

Requirements:
- keep the existing post lookup endpoint
- add a simple authenticated attachment lookup endpoint, for example:
  - `wp_ajax_fat_runner_attachments`
- return recent image attachments ordered by modified date descending
- include at least:
  - `id`
  - `title`
  - `filename`
- keep it simple in v1; no search box required

Do not expose attachment lookup publicly.

### Step 5 — Extend server-side source resolution
In `class-fat-tool-runner.php`, extend source resolution so the runner can build effective inputs from both posts and attachments.

Requirements:
- keep existing post-based article resolution working
- add a new attachment-resolution branch
- when an attachment is selected:
  - fetch attachment with WordPress APIs
  - validate it is an attachment
  - gather attachment context fields
  - merge them into effective tool inputs without trusting browser values
- do this before runtime input validation and before prompt rendering

Suggested approach:
- generalize current selected-post resolution into a broader method such as:
  - `resolve_contextual_inputs()`
- keep logic explicit and readable
- preserve backward compatibility for plain text tools

### Step 6 — Extend the OpenAI client only as needed for media tools
Only if needed for attachment metadata quality, extend `class-fat-openai-client.php` to support image input in addition to text input.

Requirements:
- keep current text-only structured output path working exactly as before
- add an optional multimodal request path for tools configured to use attachment image input
- keep the response parsing and structured output validation consistent
- only enable image input when the tool config explicitly requires it

Do not break existing tools.
Do not replace the current structured JSON output flow.

### Step 7 — Return apply metadata from the run response
After generation succeeds, the runner needs enough context to offer an apply step.

In the run response, include safe metadata such as:
- selected entity type (`post` or `attachment`)
- selected entity ID if applicable
- tool apply config filtered for runtime use
- available output keys

Do not expose private tool internals.

This can be added in:
- `includes/class-fat-tool-runner.php`
- `includes/class-fat-rest-controller.php`

### Step 8 — Add an apply REST endpoint
Add a new route to `class-fat-rest-controller.php`, e.g.:
- `POST /fabled-ai-tools/v1/apply`

Payload shape should include:
- `tool_id`
- `target_type`
- `target_id`
- `outputs`
- `apply_fields`

Behavior:
- re-check tool access
- load the full tool definition
- validate target entity type and existence
- validate requested apply fields against tool config
- sanitize final values
- update WordPress fields server-side
- return success plus list of updated fields

Important:
- do not trust client-declared mappings
- do not trust unchecked outputs
- only allow known mappings from tool config

### Step 9 — Update runner UI for apply workflow
In `assets/admin.js`, extend the runner UI.

Requirements:
- preserve current generate flow
- preserve current post source flow
- add attachment source controls for tools that support attachments
- after a successful run, if the tool supports apply mappings:
  - render an **Apply** panel under the outputs
  - show the resolved target label
  - show one checkbox per allowed mapping
  - pre-check sensible defaults if appropriate
  - add an **Apply Selected Fields** button
- after apply succeeds, show a clear success status

If generation came from pasted text and the tool supports post apply:
- allow selecting a target post before applying
- keep this simple: a post dropdown is enough

Do not auto-apply on generation.

### Step 10 — Minimal admin UI for integration config
In `class-fat-admin.php`, add a small new section on the tool edit screen for WordPress integration.

Requirements:
- allow the admin to enable post source or attachment source
- allow the admin to enable apply target type
- allow the admin to define mapping rows:
  - output key
  - target WordPress field
  - display label
- keep it table-based like the existing input/output schema sections
- use existing row-template style instead of introducing a new framework

Do not overdesign the editor.
The admin only needs enough control to define reliable mappings.

---

## Field Mapping Rules
These are the only supported mappings in v1.

### Post target mappings
- `post_title`
- `post_excerpt`
- `post_content`

### Attachment target mappings
- `post_title`
- `post_excerpt` (caption)
- `post_content` (description)
- `alt_text` (maps to `_wp_attachment_image_alt`)

No arbitrary post meta.
No arbitrary attachment meta.
No taxonomy updates.
No featured-image assignment.

---

## Acceptance Criteria
The feature is complete when all are true:

1. A user can generate an excerpt from a selected draft and explicitly apply it to that post’s excerpt.
2. A user can generate an excerpt from a selected published post and explicitly apply it to that post’s excerpt.
3. A media metadata tool can select an attachment and generate title, alt text, caption, and description outputs.
4. The user can choose any subset of those generated media outputs and apply only the checked fields.
5. Existing generate-only tools still work unchanged.
6. Existing post-source behavior still works.
7. No automatic overwrites happen without an explicit apply step.
8. Invalid mappings, invalid targets, and unauthorized requests fail safely server-side.
9. The implementation does not introduce a plugin-wide rewrite.

---

## Manual Test Checklist
### Existing behavior
- existing featured image prompt tool still works with pasted body text
- existing SEO excerpt tool still works with pasted body text
- combined tool still works with selected draft/published post source

### Post apply workflow
- select a draft, generate excerpt, apply excerpt to the same draft
- select a published post, generate excerpt, apply excerpt to the same post
- generate output from pasted text, then manually choose a target post and apply excerpt
- attempt to apply with no fields selected and verify a clean error
- attempt to apply to an invalid post ID and verify server-side rejection

### Media workflow
- select an attachment, generate metadata
- apply only alt text
- apply title + caption + description without alt text
- verify attachment title updates correctly
- verify caption updates correctly
- verify description updates correctly
- verify `_wp_attachment_image_alt` updates correctly
- verify non-image or invalid attachments fail cleanly if unsupported

### Security
- user without run capability cannot use lookups or apply endpoint
- user cannot update unsupported fields by tampering with the request
- tool with no apply config does not show apply UI

---

## Coding Preferences
- Prefer small, explicit methods.
- Reuse current repository and admin patterns.
- Avoid introducing extra classes unless they eliminate clear duplication.
- Add comments only where they clarify a non-obvious safety rule.
- Keep JS imperative and simple like the existing runner code.
- Keep CSS minimal.

---

## Deliverable Format
When making changes, provide:

1. a short summary of what changed
2. the exact files changed
3. complete updated file contents for each changed file
4. a short manual test checklist

Do not return vague pseudocode.
Do not omit changed code with placeholders.

---

## Non-Goals
Do **not** implement these in this task:
- searchable Select2 media pickers
- Gutenberg sidebar or block editor integration
- arbitrary custom field updates
- taxonomy/tag/category updates
- automatic post publish flows
- image generation inside this task
- asynchronous background jobs
- bulk media updates
