# AGENTS.md

This file is the current implementation source of truth for Codex for this repository.

`AGENTS-DOC-ARCHIVE.md` is historical context only. It documents earlier implementation phases that are already partly reflected in the codebase. Do **not** treat that archive as the current plan unless this file explicitly tells you to reuse part of it.

---

## 1. Repository understanding

### What this plugin is today

**Fabled AI Tools** is a WordPress admin-only plugin that lets admins define structured AI tools and run them inside `wp-admin`, with server-side OpenAI calls and explicit apply-to-WordPress actions.

It currently has three major product modes:

1. **Generic structured text tools**
   - Tools are stored in a custom DB table.
   - Each tool defines input schema, output schema, prompts, optional role/capability access, optional WordPress apply mappings, limits, and logging flags.
   - The runner UI is rendered in wp-admin and hydrated by localized tool definitions.
   - Outputs are validated as string-only JSON schema objects.

2. **Built-in Featured Image Generator workflow**
   - Prompt in -> server-side image generation -> Media Library save -> metadata generation -> featured derivative creation -> explicit featured image apply.

3. **Built-in Uploaded Image Processor workflow**
   - Upload in -> server-side validation -> crop/resize/convert -> Media Library save -> metadata generation -> explicit featured image apply.

### Stack and architectural shape

- **PHP + WordPress APIs**, no framework, no Composer, no build step.
- **Vanilla JS** admin app in `assets/admin.js`.
- **Vanilla CSS** in `assets/admin.css`.
- **Custom tables**:
  - `{$wpdb->prefix}fat_tools`
  - `{$wpdb->prefix}fat_runs`
- **Manual service bootstrapping** in `fabled-ai-tools.php` and `includes/class-fat-plugin.php`.
- **REST for run/apply** via `includes/class-fat-rest-controller.php`.
- **AJAX for post/media lookups** via `includes/class-fat-admin.php`.
- **Synchronous request model**; no queue, no cron-driven workflow execution, no background jobs.

### Key files and current responsibilities

- `fabled-ai-tools.php`
  - plugin bootstrap, constants, manual `require_once` loading, activation/deactivation hooks.

- `includes/class-fat-plugin.php`
  - simple service wiring; the plugin’s de facto composition root.

- `includes/class-fat-settings.php`
  - stores API key and global defaults (`model`, daily limit, timeout).

- `includes/class-fat-activator.php`
  - DB schema creation, capability seeding, built-in tool seeding/backfill/repair.

- `includes/class-fat-tools-repository.php`
  - persistence for tool definitions.

- `includes/class-fat-runs-repository.php`
  - persistence for run logs.

- `includes/class-fat-tool-validator.php`
  - input schema normalization, output schema normalization, prompt placeholder validation, apply mapping validation.

- `includes/class-fat-prompt-engine.php`
  - prompt interpolation, output JSON schema creation, runtime input/output validation, public tool definition shaping for JS.

- `includes/class-fat-openai-client.php`
  - OpenAI Responses API and Images API requests.

- `includes/class-fat-tool-runner.php`
  - the main orchestration layer; source resolution, access checks, execution, apply logic, logging, workflow dispatch.

- `includes/class-fat-featured-image-generator.php`
  - dedicated generated-image workflow executor.

- `includes/class-fat-uploaded-image-processor.php`
  - dedicated uploaded-image workflow executor.

- `includes/class-fat-admin.php`
  - admin menus, settings page, tools UI, logs UI, runner page, admin AJAX lookups, tool save actions.

- `assets/admin.js`
  - schema row builder, runner rendering, AJAX entity lookups, REST run/apply calls, workflow-specific UI.

### Current admin surfaces

- **Runner** (`page=fabled-ai-tools`)
- **Tools** (`page=fat-tools`)
- **Tool edit** (`page=fat-tool-edit`)
- **Logs** (`page=fat-logs`)
- **Settings** (`page=fat-settings`)

### Current data model

#### `fat_tools`
Stores:
- name / slug / description / active flag
- allowed roles / allowed capabilities
- model override
- system prompt / user prompt template
- input schema / output schema
- `wp_integration` JSON
- input/output limits
- per-tool daily run limit
- log flags
- sort order
- timestamps

#### `fat_runs`
Stores:
- tool / user / status
- request/response previews
- optional full request/response payloads
- error message
- model / token counts / latency
- OpenAI request id
- created timestamp

### Current settings flow

Settings are stored in one option: `fat_settings`.
Current keys:
- `api_key`
- `default_model`
- `default_daily_limit`
- `default_timeout`

There is support for `FAT_OPENAI_API_KEY` in `wp-config.php`.

### Current media flow

#### Featured Image Generator
1. prompt collected in runner
2. image generated via OpenAI Images API
3. original image saved to Media Library as PNG
4. metadata generated via OpenAI multimodal response
5. `1200x675` PNG derivative created
6. derivative can be applied as post thumbnail explicitly

#### Uploaded Image Processor
1. image upload posted to REST route
2. upload validated and moved using WordPress APIs
3. `1200x675` WebP derivative created
4. derivative saved to Media Library
5. metadata generated via OpenAI multimodal response
6. derivative can be applied as post thumbnail explicitly

### Current REST / AJAX patterns

- `POST /wp-json/fabled-ai-tools/v1/run`
- `POST /wp-json/fabled-ai-tools/v1/apply`
- admin AJAX:
  - `fat_runner_posts`
  - `fat_runner_attachments`

### Current security model

Strengths:
- server-side OpenAI key usage only
- capability gating exists for admin pages and REST routes
- nonce usage exists for REST and AJAX
- explicit apply action instead of automatic mutation
- attachment apply checks use `edit_post`
- upload workflow validates mime/ext/image-ness and max size

Weak spots:
- object-level permissions are inconsistent for source/lookup flows
- draft/media lookup endpoints are too permissive for multi-user editorial sites
- logs can store sensitive payloads indefinitely
- stored API key UX is not enterprise-safe enough

### Current deployment assumptions

The plugin currently assumes:
- outbound HTTPS to OpenAI is available
- WordPress image editing is available
- long synchronous admin requests are acceptable
- tool definitions live in DB, not code
- promotion between environments is manual
- logs can grow indefinitely
- the plugin primarily operates on the core `post` post type
- no multisite-specific behavior is required

---

## 2. Architectural observations

### What is good and worth preserving

- The plugin is already **WordPress-native** and admin-first.
- The current **manual service wiring** is simple and readable.
- The `FAT_Tool_Runner` class is the correct orchestration seam, even though it is currently too large.
- The plugin already has a useful split between:
  - tool persistence
  - validation
  - prompt rendering
  - provider client
  - execution
  - admin UI
- Built-in workflows are implemented as dedicated executors instead of forcing everything through a generic text-only path.
- The runner JS is framework-free and can stay that way.

### What is brittle today

- `FAT_Admin` (~1240 lines), `assets/admin.js` (~1260 lines), `FAT_Tool_Runner` (~864 lines), and `FAT_Activator` (~762 lines) are all oversized change-risk hotspots.
- Workflow identity is partly encoded in `wp_integration.workflow`, partly in hidden form fields, and partly in slug fallbacks. That is fragile.
- Source-selection logic and apply-target logic are duplicated across PHP and JS.
- The generic text path and workflow path share some concepts but not enough reusable infrastructure.
- A number of configuration values are stored but not truly honored end-to-end.

### Current product-model mismatch

The plugin is positioned as a configurable commercial tool system, but several behaviors are still “internal-v1” in feel:

- built-ins are not treated as protected productized workflows
- source/apply selection is too hard-coded
- logs are diagnostic, not operational
- entity selection is not scalable
- settings are developer-leaning, not operator-friendly
- environment portability is poor

---

## 3. Identified risks and opportunities

### A. Security and permission risks

1. **Post lookup leakage**
   - `handle_runner_posts_lookup()` returns recent draft/published posts without per-object filtering.
   - On a multi-author site, a user with runner access can discover titles/IDs they should not see.

2. **Selected post source resolution lacks object-level auth**
   - `FAT_Tool_Runner::resolve_inputs_from_selected_post()` validates post existence/status but does not enforce a matching per-post capability check.

3. **Attachment lookup leakage**
   - `handle_runner_attachments_lookup()` returns recent image attachments without per-object filtering.

4. **Sensitive payload retention**
   - `fat_runs` can store full request/response payloads forever.
   - There is no retention policy, purge UX, or redaction strategy.

5. **Stored API key handling is weak**
   - The stored API key is editable as a populated password field instead of a masked-preserve flow.

### B. Maintainability risks

1. **Overlarge classes/files**
   - Change scope is too wide for routine enhancements.

2. **Implicit workflow identity**
   - The codebase relies on a mix of slug conventions and hidden workflow metadata.

3. **Partially dead config**
   - `workflow_config.featured_size`, `featured_format`, `target_size`, `target_format` exist, but support is inconsistent or effectively fixed.

4. **Very few extensibility points**
   - The only current filter is `fat_uploaded_image_max_bytes`.
   - There are no meaningful `do_action` or request/response filters around core workflow seams.

5. **No export/import story**
   - Tools live in DB only; promotion across local/staging/prod is manual and error-prone.

### C. UX and product friction

1. **Entity selection does not scale**
   - only latest 40 posts / attachments
   - no search
   - no pagination
   - duplicated logic across multiple panels

2. **Only `post` is really supported**
   - source lookup, apply target lookup, and featured-image assignment are all effectively hard-coded to `post`
   - pages and CPTs are excluded from premium editorial use cases

3. **No overwrite confidence layer**
   - generic apply flows do not show before/after previews or explicit overwrite warnings

4. **Tool editor hides too much and exposes the wrong things**
   - source behavior is not intentionally editable
   - built-in workflow config is hidden
   - built-ins are easy to accidentally distort
   - there is no reset-to-default or export/import workflow

5. **Logs page is basic**
   - useful for debugging, not yet suitable as an operational console

### D. Operational gaps

1. no connection test
2. no global pause/maintenance switch
3. no log retention / purge controls
4. no run/apply activity consistency in logs
5. no site-wide guardrails beyond per-user daily counts
6. no environment promotion workflow
7. no quick health view for recent failures

### E. High-value opportunities that fit this repo cleanly

1. **Attachment Metadata Assistant**
   - Existing attachment source resolution + metadata generation already exists.
   - A built-in workflow for “selected attachment -> generate/apply title/alt text/description” is a natural next product.

2. **Content Gap Triage surface**
   - A lightweight admin page showing content missing excerpt / featured image / image alt text can launch existing tools instead of building a huge batch processor.

3. **Reusable entity selector layer**
   - A single search-capable JS/PHP selector service can improve runner source selection, apply target selection, and future deep links.

4. **Protected built-in workflow management**
   - Built-ins should be resettable, inspectable, and optionally tunable without looking like arbitrary editable rows.

---

## 4. Recommended product / engineering direction

### Direction summary

Keep this plugin **WordPress-native, admin-first, DB-backed, and OpenAI-first**. Do **not** rewrite it into a JS app or generic automation platform.

Instead, evolve it into a premium plugin by strengthening four layers:

1. **Trust layer**
   - object-level permissions
   - safer settings
   - retention / operational controls

2. **Runner / entity layer**
   - reusable searchable source/target selectors
   - apply previews
   - support for supported post types, not just posts

3. **Workflow layer**
   - make built-ins explicit and protected
   - keep generic structured text tools simple
   - add only a few high-value built-in assistants that reuse the same infrastructure

4. **Operations / extensibility layer**
   - structured logs
   - import/export
   - lifecycle hooks / filters
   - health/debug surfaces

### Specific guidance

- **Preserve backward compatibility** for:
  - existing table names
  - current REST routes unless versioning is introduced intentionally
  - seeded tool slugs
  - basic schema shape for existing text tools

- **Do not**:
  - add React/Vue
  - introduce a queue system yet
  - replace OpenAI integration with a multi-provider abstraction yet
  - turn the tool editor into a free-form arbitrary schema builder with nested objects
  - add large batch workflows before the trust + entity layers are fixed

- **Prefer**:
  - additive migrations
  - small helper/service classes when they remove duplication cleanly
  - thin UI improvements over flashy UI
  - predictable WP APIs over clever abstractions

### Suggested small helper/services (allowed if needed)

These are acceptable additions if a chunk needs them:

- `FAT_Entity_Query_Service`
  - capability-gated lookup for posts / attachments / supported post types

- `FAT_Media_Service`
  - shared image-derivative + metadata copy helpers

- `FAT_Builtin_Tools`
  - registry/reset logic for seeded built-ins

Do **not** add a container, event bus, or framework-level abstraction.

---

## 5. Prioritized roadmap

### P0 — required before this feels commercially trustworthy

1. object-level access hardening for lookup/source/apply flows
2. safer settings + operational controls
3. consistent run/apply logging and retention controls
4. scalable entity search/select UX

### P1 — required before this feels commercially polished

5. workflow-aware tool editor and built-in protection/reset
6. import/export for tool portability
7. post type support beyond `post`
8. media workflow polish and an attachment metadata assistant

### P2 — high-value premium usability additions

9. content gap triage surface
10. deep links / launch points from post and media management contexts
11. lightweight health dashboard and recent failures summary

---

## 6. Chunked execution plan

Chunks are ordered. Later chunks may assume earlier ones are complete.

---

## Chunk 1 — Security and access-control hardening

### Goal
Fix the current trust issues around source selection, target selection, and entity discovery without redesigning the plugin.

### Why this is first
The current runner can expose draft titles and attachment titles/IDs too broadly, and selected post source resolution is not enforcing object-level permissions. That is the largest commercial blocker.

### Likely files to inspect or modify
- `includes/class-fat-admin.php`
- `includes/class-fat-tool-runner.php`
- `includes/class-fat-featured-image-generator.php`
- `assets/admin.js`
- `assets/admin.css` (only if status/help text needs minor UX support)
- optional new small helper: `includes/class-fat-entity-query-service.php`

### What should change

1. **Harden post lookup**
   - Filter returned posts by what the current user may legitimately use in the runner.
   - At minimum, do not expose drafts/private content the user cannot edit.
   - Prefer a shared helper instead of embedding rules twice.

2. **Harden attachment lookup**
   - Return only attachments the current user can use/edit.

3. **Harden selected post source resolution**
   - In `resolve_inputs_from_selected_post()`, require an explicit object-level capability check before using the selected post as AI input.
   - Reject unauthorized selection server-side even if the browser submits a valid ID.

4. **Harden apply target validation**
   - Keep explicit apply behavior.
   - Ensure the target object is editable by the current user before any apply operation.
   - Apply logging comes in Chunk 2; this chunk focuses on permission enforcement.

5. **Do not widen scope yet**
   - Keep post-type support narrow in this chunk if needed.
   - The goal is trust hardening first, not UX expansion.

### Constraints
- No framework rewrite.
- Preserve current route names and basic request shapes.
- Preserve manual paste behavior.
- Preserve current seeded tool slugs.

### Definition of done
- A runner user cannot discover other users’ unauthorized drafts via AJAX lookup.
- A runner user cannot use an unauthorized selected post as AI input.
- A runner user cannot discover unauthorized attachments via AJAX lookup.
- Existing valid source/apply flows still work for allowed users.

### Testing expectations
Manual role-based tests at minimum:

- **Administrator**
  - can still see/edit/use all expected content
- **Editor**
  - can use content they are allowed to edit
- **Author**
  - cannot see or use other users’ drafts through plugin lookups or direct request tampering
- **Unauthorized object selection**
  - direct crafted requests fail cleanly with 403/400-style errors

### Regression concerns
- Do not accidentally block manual paste tools.
- Do not accidentally block published post usage for users who should be allowed.
- Keep generic text apply and workflow apply behavior intact.

---

## Chunk 2 — Settings, operational controls, and logging foundation

### Goal
Make the plugin safer to operate in production and improve observability without inventing a new platform.

### Likely files to inspect or modify
- `includes/class-fat-settings.php`
- `includes/class-fat-admin.php`
- `includes/class-fat-tool-runner.php`
- `includes/class-fat-openai-client.php`
- `includes/class-fat-runs-repository.php`
- `includes/class-fat-activator.php`
- `uninstall.php`

### What should change

1. **Safer API key UX**
   - Stop rendering the stored API key as a populated editable value.
   - Use a mask/preserve pattern:
     - show masked state
     - leave field blank by default
     - preserve existing key if the field is left empty
     - allow explicit replace / clear behavior

2. **Connection test / health check**
   - Add a small authenticated “Test OpenAI connection” action on Settings.
   - Use the real configured key and current timeout.
   - Return request ID and concise diagnostics; do not leak raw secrets.

3. **Global operational switch**
   - Add a plugin-level enable/disable or maintenance-mode setting that blocks runner execution cleanly.
   - This is valuable for incidents and budget control.

4. **Log retention + purge**
   - Add settings for log retention days.
   - Add a manual purge action.
   - If a tiny scheduled cleanup is introduced, keep it simple and WordPress-native.

5. **Consistent apply logging**
   - Log apply actions for:
     - generic text apply
     - featured image generator apply
     - uploaded image apply
   - Current behavior is inconsistent; normalize it.

6. **Improve log context**
   - Ensure logs capture enough context to answer:
     - what was run/applied
     - to which target object
     - which OpenAI request IDs were involved
     - whether the event was generate vs apply
   - Keep the simplest schema change possible if a DB migration is needed.
   - If no schema change is needed, use structured payloads consistently.

### Constraints
- Do not overbuild an analytics system.
- Keep settings simple and operator-facing.
- Preserve current option keys where reasonable.
- If a DB migration is introduced, make it additive and backward-compatible.

### Definition of done
- Settings page supports safe key management and a working connection test.
- An operator can pause plugin execution globally.
- Run logs no longer grow forever without operator control.
- Apply actions are logged consistently across tool types.

### Testing expectations
- save settings with blank key field preserves prior key
- replace key works
- clear key works if explicitly requested
- connection test succeeds with valid key and fails cleanly with invalid key
- paused mode blocks run/apply and shows a clear message
- purge / retention behavior works without damaging tool definitions

### Regression concerns
- Do not break `FAT_OPENAI_API_KEY` constant behavior.
- Do not lose existing settings values on migration.
- Do not log secrets.

---

## Chunk 3 — Runner UX and reusable entity selectors

### Goal
Replace the fragile “latest 40 items” approach with a reusable, scalable source/target selection experience that still fits the current no-build, no-framework architecture.

### Likely files to inspect or modify
- `assets/admin.js`
- `assets/admin.css`
- `includes/class-fat-admin.php`
- optionally `includes/class-fat-rest-controller.php` if lookup routes move to REST
- optional helper: `includes/class-fat-entity-query-service.php`

### What should change

1. **Introduce reusable async entity selectors**
   - Use one shared selector pattern for:
     - article source post selection
     - attachment source selection
     - generic apply target selection
     - featured image apply target selection
     - uploaded image apply target selection

2. **Add search support**
   - Search by title for posts/pages/CPTs (scope expands in Chunk 5).
   - Search by title/filename for attachments.
   - Keep implementation light; no Select2.

3. **Support pagination or incremental load**
   - Do not fetch an unbounded list.

4. **Improve apply confidence**
   - Show the target object label in the apply panel.
   - Show exactly which fields will be overwritten.
   - If practical, include a minimal before/after preview for generic text fields.

5. **De-duplicate workflow-specific UI logic where practical**
   - The featured-image and uploaded-image panels currently re-implement very similar target selection behavior.
   - Move toward one reusable helper instead of copy/paste blocks.

### Constraints
- No JS framework.
- Keep the runner page server-rendered and progressively enhanced.
- Preserve existing rest/apply contracts unless there is a strong reason to version.

### Definition of done
- The runner can find relevant posts/media on larger sites without scrolling a small hard-coded list.
- Generic apply and image apply flows use the same underlying selector patterns.
- The apply panel is clearer about what will change.

### Testing expectations
- search for a draft by title
- search for a published item by title
- search for an attachment by title / filename
- no-results and loading states are clear
- keyboard-only usage still works
- generic text apply still works
- featured image apply still works
- uploaded image apply still works

### Regression concerns
- Do not break existing source-selection hidden values relied on by `FAT_Tool_Runner`.
- Do not create a fragile client-only source of truth; all actual validation stays server-side.

---

## Chunk 4 — Tool editor hardening, built-in workflow safety, and portability

### Goal
Make the tool editor feel intentional and commercial: safer built-ins, clearer integration settings, and an environment-portable tool configuration story.

### Likely files to inspect or modify
- `includes/class-fat-admin.php`
- `includes/class-fat-tool-validator.php`
- `includes/class-fat-tools-repository.php`
- `includes/class-fat-prompt-engine.php`
- `includes/class-fat-activator.php`
- `fabled-ai-tools.php`
- optional helper: `includes/class-fat-builtin-tools.php`

### What should change

1. **Make built-ins explicit**
   - Identify seeded built-ins intentionally.
   - Show a built-in badge/banner in the editor.
   - Add “reset to defaults” for built-ins.
   - Avoid silent auto-reseed behavior being the only protection mechanism.

2. **Expose integration settings intentionally**
   - Source behavior should be configurable in the editor in a controlled way.
   - Built-in workflow config should be either:
     - safely editable with validation, or
     - clearly read-only with explanations.
   - Do not leave critical behavior hidden in form fields.

3. **Add import/export for tools**
   - JSON export/import is the cleanest answer for staging/prod promotion in this codebase.
   - Keep it scoped to tool definitions; do not attempt full run-log migration.

4. **Harden repository conventions**
   - Whitelist/sanitize `orderby` in `FAT_Tools_Repository::get_all()`.
   - Add `load_plugin_textdomain()`.
   - Keep the plugin closer to normal commercial WP plugin conventions.

5. **Add narrow extensibility points**
   - Add filters/actions around:
     - public tool definition shaping
     - pre-run inputs
     - post-run outputs
     - apply completion
     - built-in workflow defaults
   - Keep names predictable and specific.

### Constraints
- Do not redesign the DB model unless a chunk requirement truly demands it.
- Built-in slugs must remain stable.
- Import/export should be additive and human-readable.

### Definition of done
- Built-ins are clearly distinguished and recoverable.
- The editor is no longer hiding critical workflow behavior.
- Tools can move between environments without manual SQL.
- The codebase exposes a small but real extension surface.

### Testing expectations
- edit built-in tool without losing workflow identity
- reset built-in tool restores expected defaults
- export a tool, import it on a clean site, and run it successfully
- generic text tools still save and validate correctly
- translation loading still works as expected for strings using the plugin text domain

### Regression concerns
- Do not break hidden workflow preservation for existing stored rows during migration.
- Do not change import/export formats casually after introducing them.

---

## Chunk 5 — Media workflow polish and the Attachment Metadata Assistant

### Goal
Turn the media side of the plugin from “working internal workflow” into a polished, extensible commercial feature set without bloating it.

### Likely files to inspect or modify
- `includes/class-fat-featured-image-generator.php`
- `includes/class-fat-uploaded-image-processor.php`
- `includes/class-fat-tool-runner.php`
- `includes/class-fat-activator.php`
- `includes/class-fat-admin.php`
- `assets/admin.js`
- `assets/admin.css`
- optional helper: `includes/class-fat-media-service.php`

### What should change

1. **Unify media-processing behavior**
   - Share derivative-generation logic where it is duplicated.
   - Ensure fallback behavior is consistent between generated-image and uploaded-image workflows.

2. **Honor workflow config or simplify it**
   - Today, some workflow config values exist but are effectively hard-coded.
   - Either make these values real and validated, or remove/ignore them intentionally with no dead config.

3. **Support broader target content**
   - Move from hard-coded `post` to supported post types that can actually hold featured images.
   - Keep defaults conservative.

4. **Add Attachment Metadata Assistant built-in**
   - New built-in workflow or seeded tool for:
     - selecting an existing attachment
     - generating title / alt text / description
     - optionally applying those fields back to the same attachment
   - This should reuse existing attachment-context and apply infrastructure.

5. **Polish metadata persistence**
   - Be explicit about which attachment fields map to title / caption / description / alt text.
   - Keep output contracts stable.

### Constraints
- Do not introduce batch queues in this chunk.
- Keep featured-image apply explicit.
- Reuse existing infrastructure wherever it genuinely simplifies maintenance.

### Definition of done
- Media workflows share more code and fewer sharp edges.
- Supported post types for featured-image apply are broader than just `post`.
- The new Attachment Metadata Assistant exists and feels native to the plugin.

### Testing expectations
- featured image generation still works end-to-end
- uploaded image processing still works end-to-end
- featured-image apply works on supported non-`post` post types if they support thumbnails
- attachment metadata assistant updates attachment metadata correctly
- metadata fallback behavior remains safe when AI metadata generation fails

### Regression concerns
- Preserve existing `featured-image-generator` and `uploaded-image-processor` slugs.
- Preserve current output field names unless a migration path is explicitly implemented.

---

## Chunk 6 — Premium productivity surfaces (without turning this into bloat)

### Goal
Add a small number of high-value admin management surfaces that make the plugin feel premium and useful day-to-day.

### Likely files to inspect or modify
- `includes/class-fat-admin.php`
- `includes/class-fat-tools-repository.php`
- `includes/class-fat-runs-repository.php`
- `assets/admin.js`
- `assets/admin.css`
- `includes/class-fat-activator.php` (if additional seeded tool(s) or dashboard defaults are needed)

### What should change

1. **Add a lightweight “Needs Attention” surface**
   - Show content missing:
     - excerpt
     - featured image
     - image alt text (or attachments missing alt text)
   - Provide launch links into the runner with preselected tool + target.
   - Keep this intentionally lightweight; do not build a massive batch processor.

2. **Add deep-link launch points**
   - From the plugin admin surfaces, allow jumping straight into relevant workflows for a selected post or attachment.
   - Query-string preselection is acceptable if kept simple and secure.

3. **Add a lightweight health / recent failures summary**
   - Surface recent error counts, last failed runs, and quick links to filtered logs.

### Constraints
- No background queue system.
- No Gutenberg rewrite.
- No massive dashboard or analytics product.
- Reuse existing tools instead of inventing a parallel workflow system.

### Definition of done
- Editors/admins can identify common content gaps and jump directly into the correct workflow.
- Recent operational issues are easier to see without opening raw logs first.
- The plugin feels more like a daily editorial assistant and less like a hidden utility.

### Testing expectations
- content-gap lists show expected items
- deep links open the runner in a useful preselected state
- recent-failure summaries match the logs page filters

### Regression concerns
- Keep admin queries efficient.
- Do not add expensive unbounded scans to every page load.

---

## 7. Recommended built-in tools / workflows that fit this repository cleanly

These are appropriate **after** the trust + UX foundations are in place.

### Highest-fit additions

1. **Attachment Metadata Assistant**
   - already covered in Chunk 5
   - best fit with current codebase

2. **Content Gap Triage + Launch surface**
   - uses current tools rather than inventing new automation
   - best fit for premium usability

3. **Post Editor / Media Library launch links**
   - “Open in Fabled AI Tools” with context preselected
   - minimal implementation, high usability payoff

### Lower priority / avoid for now

- fully generic batch jobs
- autonomous content mutation
- front-end/public workflows
- provider abstraction layer
- complex schema types beyond current string outputs
- giant analytics/cost dashboard

---

## 8. Repository-specific implementation notes for Codex

1. **Do not trust `AGENTS-DOC-ARCHIVE.md` as the current plan.**
   It is historical.

2. **Workflow identity is currently split across storage and slug fallback.**
   Be very careful when touching:
   - `wp_integration.workflow`
   - `public_tool_definition()`
   - slug-based JS fallbacks
   - `FAT_Tool_Runner::tool_workflow_type()`

3. **The runner/apply contract is shared by multiple product modes.**
   If you change:
   - localized tool data shape
   - apply meta shape
   - output payload structure
   then update both PHP and `assets/admin.js` together.

4. **Built-in tool slugs are de facto public identifiers.**
   Preserve:
   - `featured-image-generator`
   - `uploaded-image-processor`
   - `featured-image-prompt`
   - `seo-excerpt`
   - `combined-publishing-tool`

5. **Do not hide critical behavior in hidden inputs long-term.**
   The current editor preserves built-in workflow config that way, but that is a product smell. Chunk 4 is the right place to improve it.

6. **Keep migrations additive.**
   Existing installs should not lose tools, settings, or logs.

7. **Keep the plugin WP-native.**
   Prefer:
   - REST or authenticated admin AJAX
   - WordPress media APIs
   - WordPress capability checks
   - no extra JS toolchain

---

## 9. Coding guardrails

- Favor small, composable helper classes over further enlarging the current giant classes.
- Avoid introducing new global state.
- Use the existing `FAT_` prefix.
- Keep route names and option keys stable unless a migration is explicit.
- Keep user-facing strings translatable.
- Keep logs useful but do not log secrets.
- Prefer explicit product language over internal-engineer language in the admin UI.

---

## 10. Minimum manual regression matrix after every chunk

Always manually verify at least:

1. **Runner**
   - generic text tool run
   - featured image workflow run
   - uploaded image workflow run

2. **Apply flows**
   - generic text apply
   - featured image apply
   - uploaded image apply

3. **Tool management**
   - create tool
   - edit tool
   - duplicate tool
   - toggle tool active/inactive

4. **Logs**
   - success log visible
   - error log visible

5. **Settings**
   - settings save still works
   - constant-based API key still works

If a chunk changes permissions or selectors, also test with multiple roles.

---

## 11. Final instruction to Codex

When asked to implement a specific chunk:

- read this file first
- inspect the current code, not just historical docs
- implement only that chunk’s scope unless a tiny supporting change is necessary
- keep the simplest maintainable approach that fits this repository
- preserve backward compatibility unless there is a strong, explicit reason not to
- update README when admin-visible behavior materially changes

