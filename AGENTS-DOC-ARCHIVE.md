# ARCHIVED AGENTS.md
Past tasks for this document. Current tasks see [AGENTS.md](https://github.com/sherafyk/fabled-ai-tools/blob/main/AGENTS.md)


2026-03-24
## Fabled AI Tools — Image Workflow Implementation Guide for Codex

This file replaces the previous `AGENTS.md`.

Its purpose is to guide Codex in implementing the next phase of this plugin, focused specifically on **image workflows** inside the WordPress plugin.

The implementation target is **not** a generic brainstorming exercise.  
It is a practical implementation roadmap for this codebase.

This document is intentionally focused on these built-in tools:

1. **Featured Image Generator**
   - user enters a prompt
   - plugin generates an image with OpenAI
   - plugin saves it to WordPress media library
   - plugin generates metadata
   - plugin creates a cropped derivative
   - user can apply it as featured image to a post

2. **Uploaded Image Processor**
   - user uploads an image
   - plugin crops/resizes it to a default featured-image size
   - plugin converts it to WebP
   - plugin saves it to WordPress media library
   - plugin generates metadata
   - user can apply it as featured image to a post

This file is written as direct implementation guidance for Codex.

---

# Core Product Goals

The plugin should support two image-centered workflows:

## A. AI-generated featured image workflow
The user enters a prompt and the plugin:

1. generates an image
2. saves it to the media library
3. generates title, alt text, and description
4. creates a normalized derivative suitable for featured-image use
5. optionally applies it as the featured image to a selected post

## B. Uploaded image processing workflow
The user uploads an image and the plugin:

1. validates the upload
2. crops/resizes it to the standard featured-image format
3. converts it to WebP
4. saves it to the media library
5. generates title, alt text, and description
6. optionally applies it as the featured image to a selected post

---

# Non-goals

Codex should **not** do the following unless explicitly required:

- refactor the entire plugin architecture
- rewrite all tools into a new generic framework
- replace existing text tool behavior
- remove or change current built-in text tools unless necessary for compatibility
- invent a second unrelated logging system if an existing one already works
- implement an elaborate asset-management subsystem
- depend on server-to-self HTTP requests when direct WordPress functions can be used
- auto-apply media to posts without explicit user action

---

# Expected Architectural Direction

Use the simplest maintainable architecture that fits the current plugin.

## High-level recommendation
Treat image workflows as **dedicated built-in executors**, separate from the existing text-tool execution path.

That means:

- existing text tools remain unchanged
- image-generation workflow gets its own execution path
- uploaded-image processing workflow gets its own execution path
- metadata generation can reuse shared internal helpers if appropriate
- featured-image assignment should use a shared apply/helper layer if both tools need it

## Why
These workflows are materially different from text-only tools.

They involve:

- media upload/generation
- media library insertion
- image processing
- metadata generation from an image
- featured-image assignment to a post

Trying to force these into the same contract as excerpt/prompt tools will likely make the plugin harder to maintain.

---

# Canonical Concepts

Codex should standardize around the following concepts.

## 1. Canonical post target fields
If this plugin exposes “apply” behavior for posts, it should use canonical target field names such as:

- `featured_image`
- `excerpt`
- `title`
- `content`

Do not expose raw internal WP field names in the UI or request contract unless there is a strong reason.

## 2. Canonical attachment metadata fields
For attachment metadata, use canonical internal names:

- `title`
- `alt_text`
- `description`

Then map internally to WordPress storage:

- `title` -> attachment post title
- `alt_text` -> `_wp_attachment_image_alt`
- `description` -> attachment post content or appropriate attachment description field

## 3. Shared media-processing stages
Image workflows should be thought of as discrete stages:

### Generated-image flow
1. accept prompt
2. generate image
3. save source image
4. generate metadata
5. create derivative
6. optionally apply as featured image

### Uploaded-image flow
1. accept file upload
2. validate image
3. process/crop/convert
4. save processed attachment
5. generate metadata
6. optionally apply as featured image

Keeping these stages separate makes debugging and future extension much easier.

---

# Default Product Specifications

## Tool 1: Featured Image Generator

### Purpose
User pastes a prompt and receives a ready-to-use media-library image with metadata and optional featured-image apply support.

### Defaults
- default model: `gpt-image-1-mini`
- default quality: `low`
- source generation size: `1536x1024`
- derivative target size: `1200x675`
- derivative target format: `png`

### Required behavior
- user provides only a prompt
- plugin generates the image
- plugin saves the generated source image or practical equivalent
- plugin generates title, alt text, and description
- plugin creates a cropped/normalized `1200x675` PNG derivative
- plugin allows user to explicitly apply that image as featured image to a selected post

### Important note
The derivative generation should be a server-side image-processing step, not a second image-generation request.

---

## Tool 2: Uploaded Image Processor

### Purpose
User uploads an image and receives a standardized, metadata-enriched featured-image-ready asset.

### Defaults
- target size: `1200x675`
- target format: `webp`

### Required behavior
- user uploads a single image
- plugin validates it
- plugin creates a `1200x675` WebP derivative
- plugin saves the processed image to the media library
- plugin generates title, alt text, and description
- plugin allows user to explicitly apply that image as featured image to a selected post

---

# Security and implementation constraints

Codex must preserve the following constraints:

- no API keys exposed to the browser
- no direct client-side OpenAI calls
- capability checks for post edit / media operations
- nonce/auth checks consistent with current plugin architecture
- file upload validation for uploaded-image workflow
- explicit apply actions only
- safe error handling with useful messages
- no reliance on users manually saving hidden internal plugin state into the editor

For WordPress interactions, prefer direct native WordPress functions/APIs over self-HTTP requests.

---

# Logging and diagnostics requirements

Codex should preserve or improve observability.

For each image workflow, log enough information to diagnose failures:

## Featured Image Generator
- prompt
- model
- quality
- requested size
- image generation result
- attachment save result
- metadata generation result
- derivative generation result
- apply-as-featured-image result

## Uploaded Image Processor
- uploaded file info
- validation result
- processed derivative result
- media save result
- metadata generation result
- apply-as-featured-image result

If the plugin already has a logging or run-history pattern, integrate with that instead of building a separate system.

Use temporary `error_log()` diagnostics during development only when necessary.

---

# Backend implementation principles

Codex should follow these principles:

## 1. Prefer dedicated helpers/services
Use dedicated internal services/helpers for:
- image generation
- image metadata generation
- image derivative processing
- attachment metadata persistence
- post featured-image assignment

## 2. Keep request contracts explicit
Do not rely on implicit state or hidden assumptions.
Requests should clearly indicate:
- target post if any
- apply action if any
- uploaded file or prompt input
- selected operation

## 3. Keep apply explicit
Applying an image as featured image must be user-triggered.

Generation and processing may happen automatically within the tool flow.
Featured-image assignment should remain a separate explicit action or an explicit combined action if clearly labeled.

## 4. Reuse shared logic when it genuinely reduces duplication
Shared logic is good for:
- metadata persistence
- post thumbnail assignment
- validation of target post
- capability checks

Do not overabstract prematurely.

---

# Frontend implementation principles

Codex should keep the UI simple and consistent with the plugin.

## Shared UX expectations
For both image tools:
- clear primary action
- visible loading states
- explicit success/failure feedback
- preview of resulting image if practical
- target post selector only when relevant
- apply button disabled when no valid target exists
- output metadata displayed after generation/processing

## Tool-specific UX

### Featured Image Generator
Inputs:
- prompt textarea
- optional post selector

Outputs:
- image preview
- generated title
- generated alt text
- generated description
- success state
- apply-as-featured-image action

### Uploaded Image Processor
Inputs:
- image upload input
- optional post selector

Outputs:
- processed image preview
- generated title
- generated alt text
- generated description
- success state
- apply-as-featured-image action

---

# Admin requirements

Both tools should be available as **default built-in tools**.

Codex should:
- use the existing built-in/default tool registration mechanism if one exists
- add these tools in the same spirit as the current built-in defaults
- avoid requiring manual custom-tool creation for these two workflows

If there is an admin-facing default settings UI for built-in tools, expose sensible defaults without overcomplicating configuration.

---

# Chunked implementation plan for Codex

Codex should **not** implement all of this in one giant change unless the repo is already structured for it.

Use the following chunk plan.

---

## Chunk 1 — Inspect and confirm architecture

### Goal
Understand the current plugin structure and identify the exact files/patterns to extend.

### Instructions for Codex
Read the current plugin implementation and confirm:

- where built-in tools are registered
- where existing tool runners are rendered
- where REST routes are registered
- where OpenAI/text tool execution is implemented
- where media-library and apply/update logic currently lives
- whether current logging already supports new run types

### What to inspect
At minimum, inspect files handling:
- plugin bootstrap
- built-in tool registration/defaults
- REST route registration
- admin UI pages
- runner UI
- logs/history
- any existing apply/media helpers

### Output
Return:
- exact files relevant to these new image tools
- recommended insertion points
- any ambiguities or risks
- a concrete file plan before coding

### Do not
- do not implement changes yet
- do not refactor anything yet

---

## Chunk 2 — Featured Image Generator backend

### Goal
Implement the backend/core logic for the AI-generated image workflow.

### Scope
Implement only the backend path for:

- receiving a prompt
- generating an image via OpenAI
- saving the image into WordPress media
- generating metadata for the image
- creating the `1200x675` PNG derivative
- persisting metadata
- storing/logging enough information to debug failures

### Important constraints
- do not implement final runner UI yet unless minimally required
- do not change Uploaded Image Processor yet
- do not change existing text-tool execution unnecessarily

### Definition of done
This chunk is done when:
- a backend execution path exists for Featured Image Generator
- image generation works
- media save works
- metadata generation works
- derivative creation works
- result is inspectable in the media library

---

## Chunk 3 — Featured Image Generator frontend and apply flow

### Goal
Complete the user-facing workflow for Featured Image Generator.

### Scope
Implement:
- prompt input UI
- result preview UI
- metadata display
- optional post selector
- explicit apply-as-featured-image action
- clear success/error/loading states

### Important constraints
- applying featured image must be explicit
- use canonical target field/action naming
- preserve backward compatibility for existing tools

### Definition of done
This chunk is done when:
- a user can generate an image from a prompt
- see the resulting media
- inspect generated metadata
- explicitly apply it as the featured image for a selected post

---

## Chunk 4 — Uploaded Image Processor backend

### Goal
Implement the backend/core logic for the uploaded-image workflow.

### Scope
Implement only the backend path for:

- accepting a user-uploaded image
- validating allowed image types and limits
- creating a processed `1200x675` WebP derivative
- saving that processed asset to media library
- generating metadata
- storing/logging enough information to debug failures

### Important constraints
- do not change Featured Image Generator behavior in this chunk
- do not overengineer file-processing abstractions

### Definition of done
This chunk is done when:
- a backend execution path exists for uploaded-image processing
- upload validation works
- conversion to `1200x675` WebP works
- media save works
- metadata generation works

---

## Chunk 5 — Uploaded Image Processor frontend and apply flow

### Goal
Complete the user-facing workflow for Uploaded Image Processor.

### Scope
Implement:
- upload input UI
- result preview UI
- metadata display
- optional post selector
- explicit apply-as-featured-image action
- clear success/error/loading states

### Important constraints
- applying featured image must be explicit
- do not regress other tool flows
- preserve architecture consistency

### Definition of done
This chunk is done when:
- a user can upload an image
- the plugin processes it into `1200x675` WebP
- metadata is generated and saved
- the resulting image can be explicitly applied as featured image to a selected post

---

## Chunk 6 — Final cleanup, validation, and compatibility pass

### Goal
Stabilize the implementation and ensure compatibility.

### Scope
Codex should:
- remove temporary noisy debug logging unless still useful
- ensure field/action contracts are consistent
- ensure both image tools are registered as built-in defaults
- verify existing text tools still work
- verify logging/run history remains coherent
- update any internal help text/admin descriptions if needed

### Definition of done
This chunk is done when:
- both new image tools work end-to-end
- existing tools still work
- code remains aligned with plugin conventions
- the implementation is understandable and maintainable

---

# Codex operating rules

For all chunks, Codex should follow these rules:

1. inspect first, then code
2. do not make large unrelated refactors
3. prefer extending current patterns over inventing new subsystems
4. preserve backward compatibility unless a change is clearly necessary
5. use explicit request/response contracts
6. prefer direct WordPress/media APIs over self-HTTP
7. use canonical field names internally and map to WordPress storage explicitly
8. return exact changed files after each chunk

---

# Testing expectations

Codex should include or describe verification for the following.

## Featured Image Generator
- prompt accepted
- image generated
- image saved to media library
- title generated and persisted
- alt text generated and persisted
- description generated and persisted
- derivative PNG created at `1200x675`
- image can be applied as featured image
- failure cases handled cleanly

## Uploaded Image Processor
- image upload accepted
- invalid file types rejected
- image processed to `1200x675` WebP
- processed image saved to media library
- title generated and persisted
- alt text generated and persisted
- description generated and persisted
- image can be applied as featured image
- failure cases handled cleanly

## Regression checks
- existing text tools still run correctly
- existing admin pages still load correctly
- existing logs/history still work or fail gracefully
- no API keys exposed to the browser
- no unexpected dependence on manual editor save state

---

# Final instruction to Codex

Use this file as the implementation source of truth for the next phase of plugin work.

Do not treat this as a request for a broad rewrite.
Treat it as a focused roadmap for adding two built-in image tools cleanly and safely.

If any part of the current plugin architecture makes this plan ambiguous, inspect first, state the ambiguity, and then choose the smallest viable implementation that fits the existing codebase.

2026-03-23
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
