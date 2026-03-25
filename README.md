# Fabled AI Tools

**Fabled AI Tools** is a WordPress-admin plugin for creating and running structured AI workflows with server-side OpenAI calls.

**Owner / Developer:** Fabled Sky Research  
**Project website:** https://fabledsky.com/community-projects/  
**Project year:** 2026

It supports two usage styles:

1. **Configurable text tools** (e.g., SEO excerpt generation).
2. **Dedicated built-in image workflow**: **Featured Image Generator**.

Everything runs inside wp-admin using WordPress-native permissions, nonces, REST/AJAX endpoints, and media/post APIs.

---

## Table of Contents

- [What the plugin does](#what-the-plugin-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Built-in tools](#built-in-tools)
- [Runner workflows](#runner-workflows)
  - [A) Generate-only text workflow](#a-generate-only-text-workflow)
  - [B) Generate + Apply text workflow](#b-generate--apply-text-workflow)
  - [C) Featured Image Generator workflow](#c-featured-image-generator-workflow)
- [How apply-to-WordPress works](#how-apply-to-wordpress-works)
- [Tool configuration guide (admin)](#tool-configuration-guide-admin)
- [Security and permissions](#security-and-permissions)
- [Logging and observability](#logging-and-observability)
- [Troubleshooting](#troubleshooting)
- [Architecture notes](#architecture-notes)
- [Developer notes](#developer-notes)

---

## What the plugin does

Fabled AI Tools lets admins define tool schemas and prompts, then lets authorized users run those tools from a single **Runner** UI.

### Core capabilities

- Define tools with:
  - input schema
  - output schema
  - system prompt
  - user prompt template
- Run tools against pasted content or selected WordPress entities (when configured).
- Return structured outputs validated against the configured output schema.
- Optionally apply selected outputs back into WordPress content with explicit confirmation.
- Log runs, model usage fields, latency, and errors for diagnostics.

---

## Requirements

- WordPress with admin access.
- OpenAI API key.
- Ability to create/update content and media for apply workflows.

---

## Installation

1. Copy the plugin folder into:
   - `wp-content/plugins/fabled-ai-tools`
2. In WordPress admin, activate **Fabled AI Tools**.
3. Open **Fabled AI Tools → Settings** and save your OpenAI API key.
4. Verify capabilities for intended users (administrator/editor/author by default for run access).

---

## Quick start

1. Go to **Fabled AI Tools → Runner**.
2. Select a tool from the dropdown.
3. Fill required inputs.
4. Click **Generate**.
5. If the tool has apply mappings/workflow, use the **Apply** controls explicitly.

---

## Built-in tools

The plugin seeds built-ins on activation/upgrade.

### 1) Featured Image Prompt

- Input: article body.
- Output: one image prompt string.
- Purpose: generate prompt text for external image systems.

### 2) SEO Excerpt

- Input: article body.
- Output: excerpt.
- Supports optional apply to post excerpt when configured.

### 3) Combined Publishing Tool

- Inputs: title, URL, body, optional instructions.
- Outputs: excerpt + image prompt.

### 4) Featured Image Generator (dedicated workflow)

- Input: single image prompt.
- Generates image server-side with defaults:
  - model: `gpt-image-1-mini`
  - quality: `low`
  - source size: `1536x1024`
- Saves generated media into WordPress library.
- Auto-generates and saves attachment metadata:
  - title
  - alt text
  - description
- Creates featured derivative:
  - exact `1200x675`
  - PNG output
- Provides explicit action to apply generated derivative as selected post’s featured image.

---

## Runner workflows

## A) Generate-only text workflow

Use any tool with no apply configuration.

1. Select tool.
2. Fill inputs.
3. Click **Generate**.
4. Copy outputs as needed.

## B) Generate + Apply text workflow

Use tools configured with `wp_integration.apply` mappings.

1. Run generation.
2. Review outputs.
3. In **Apply to WordPress**, check only fields you want updated.
4. Select target (if required).
5. Click **Apply Selected Fields**.

Notes:
- Apply is always explicit.
- Unsupported/tampered mappings are rejected server-side.

## C) Featured Image Generator workflow

1. Select **Featured Image Generator**.
2. Enter a prompt (required).
3. Click **Generate**.
4. Plugin performs stages server-side:
   - image generation
   - save original attachment
   - generate metadata (title/alt/description)
   - create `1200x675` PNG derivative
5. Runner shows:
   - image preview
   - original + derivative attachment IDs
   - generated metadata
6. Select a post in apply section.
7. Click **Apply as Featured Image**.

Notes:
- Generation/save may be combined in one run action.
- Featured-image apply remains explicit and requires post selection.

---

## How apply-to-WordPress works

For schema-based text tools, apply is driven by `wp_integration.apply.mappings`.

### Supported post fields

- `post_title`
- `post_excerpt`
- `post_content`

### Supported attachment fields

- `post_title`
- `post_excerpt` (caption)
- `post_content` (description)
- `alt_text` (`_wp_attachment_image_alt`)

For **Featured Image Generator**, apply action sets post thumbnail using generated derivative attachment.

---

## Tool configuration guide (admin)

Open **Fabled AI Tools → Tools** and edit/create a tool.

### Required pieces for normal text tools

- Name + slug
- System prompt
- User prompt template
- Input schema rows
- Output schema rows

### Optional WordPress integration

You can configure apply mappings to enable UI/apply flow.

Mapping row fields:
- `output_key`
- `wp_field`
- `label`

Validation rules enforce:
- output key must exist in output schema
- allowed `wp_field` values only
- duplicate mapping safety checks

---

## Security and permissions

- OpenAI API key remains server-side.
- Runner/apply endpoints require authenticated users with plugin capabilities.
- Nonce/auth checks are used for REST and AJAX requests.
- Entity IDs (post/attachment) are validated server-side.
- Capability checks gate edits (`edit_post`, media permissions, etc.).
- Apply actions reject unsupported mappings/fields.

---

## Logging and observability

Run logs are available in **Fabled AI Tools → Logs**.

Typical run data includes:
- status
- request/response previews
- model used
- latency
- token fields when available
- error messages

For Featured Image Generator, logs include workflow details such as prompt/model/quality/size and generated attachment references when logging is enabled.

---

## Troubleshooting

### “OpenAI API key is not configured”

Set API key in **Settings**.

### “You are not allowed to run/apply”

Check user role/capabilities (`fat_run_ai_tools`, `fat_manage_tools`, and post/media edit permissions).

### Apply button disabled

For text tools: no valid mappings/outputs selected.

For Featured Image Generator: select a target post first.

### Image generated but apply failed

Confirm:
- target post exists
- user can edit target post
- generated attachment still exists and is editable

### No tools appear in Runner

Ensure tool is active and user can access it by role/capability restrictions.

---

## Architecture notes

Key files:

- `includes/class-fat-admin.php` — admin pages, tool forms, localized runner data.
- `assets/admin.js` — runner UX, generation requests, apply actions.
- `includes/class-fat-rest-controller.php` — `/run` and `/apply` REST routes.
- `includes/class-fat-tool-runner.php` — core execution + apply orchestration.
- `includes/class-fat-openai-client.php` — OpenAI Responses + Images calls.
- `includes/class-fat-featured-image-generator.php` — dedicated built-in image workflow executor.
- `includes/class-fat-activator.php` — DB setup, capabilities, built-in seeding/backfills.

Design principles:
- Keep generation and apply explicit.
- Use small WordPress-native layers.
- Preserve backward compatibility for existing tools.

---

## Developer notes

- No external JS frameworks/build step required.
- Keep server-side operations in WordPress APIs where possible.
- For image workflow extensions, preserve staged flow:
  1. generate
  2. save media
  3. metadata
  4. derivative
  5. explicit apply
