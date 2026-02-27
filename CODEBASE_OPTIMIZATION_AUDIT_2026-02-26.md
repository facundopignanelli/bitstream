# BitStream Codebase Optimization Audit (2026-02-26)

## Scope and Method

This audit was performed against first-party plugin code only (excluding `vendor/`).

Reviewed:
- Runtime entrypoints and includes (`bitstream.php`, all `includes/*.php` loaded by bootstrap)
- Frontend and admin assets (`assets/js/bitstream.js`, `assets/css/bitstream.css`, `sw.js`, `manifest.json`)
- Full-file deep pass of all first-party source files (including large classes/assets in entirety)
- Recent churn from git history (last ~3 days)
- Symbol/reference checks for dead or unhooked methods
- Structural bloat and duplication analysis

Key principle used for recommendations: **safe optimizations that do not alter current behavior**.

---

## Executive Summary

### Highest-priority removals/refactors
1. **Remove orphan file**: `bitstream-quick-media.js` (empty + unreferenced).
2. **Prune dead methods and stale routes**: several methods are defined but not wired/used.
3. **Fix admin-side script injection risk in block-editor share handling** (`inject_shared_url_script` with raw JS string interpolation).
4. **Fix split-brain admin/frontend JS config contract**: `ajaxurl` vs `ajax_url` mismatch in admin localization.
5. **Mitigate hashtag-scan scalability bottleneck** in `get_hashtag_counts()` by moving to indexed/tag-based counting.
6. **Decompose oversized files by domain** (especially `assets/js/bitstream.js`, `class-shortcodes.php`, `class-ajax-handlers.php`, `class-pwa-manager.php`).
7. **Strip production debug surface in PWA manager**: excessive `error_log`, debug endpoints, debug file writes.

### Size hotspots (first-party)
- `assets/js/bitstream.js`: 3349 lines
- `assets/css/bitstream.css`: 3237 lines
- `includes/class-ajax-handlers.php`: 1492 lines
- `includes/class-shortcodes.php`: 1403 lines
- `includes/class-block-editor.php`: 1239 lines
- `includes/class-pwa-manager.php`: 1047 lines
- `includes/class-admin-interface.php`: 897 lines
- `includes/admin-rebit-mappings-interface.php`: 915 lines

### Inline style/script density hotspots
- `includes/class-shortcodes.php`: 72 inline `style="..."` occurrences
- `bitstream.php`: 42 inline `style="..."` occurrences
- `includes/admin-rebit-mappings-interface.php`: 25 inline `style="..."` occurrences
- `includes/class-pwa-manager.php`: 4 inline `<script>` blocks + 48 `error_log(...)` calls

---

## Findings by Priority

## P0 — Safe to remove now (very high confidence)

### P0-01 — Orphaned file
- **File**: `bitstream-quick-media.js`
- **Evidence**:
  - File exists but is empty (0 lines)
  - No references found in repository
- **Risk**: None
- **Action**: Delete file from repository.

### P0-02 — Dead method in PWA manager
- **File**: `includes/class-pwa-manager.php`
- **Symbol**: `show_upload_progress_page()`
- **Evidence**: Function exists but has no internal/external caller.
- **Risk**: None if removed.
- **Action**: Remove method.

### P0-03 — Stale service-worker feed rewrite target
- **File**: `includes/class-pwa-manager.php`
- **Evidence**:
  - Rewrite and serving path references `sw-feed.js`
  - File `sw-feed.js` does not exist in repository
- **Risk**: 404 path and dead branch maintenance cost.
- **Action**: Remove `sw-feed.js` rewrite branch OR add file if intentionally required. Current safest cleanup: remove dead branch.

### P0-04 — Legacy OG background fetch path left behind
- **File**: `includes/class-og-fetcher.php`
- **Symbols**: `schedule_og_fetch()`, `process_og_data()`
- **Evidence**:
  - Constructor comments explicitly disable hooks for these methods
  - No active hooks to invoke them
- **Risk**: None if removed (currently inactive).
- **Action**: Remove these unused methods and related comments.

### P0-05 — Admin-side JS injection vector in shared URL script injection
- **File**: `includes/class-block-editor.php`
- **Symbol**: `inject_shared_url_script()`
- **Evidence**:
  - Builds inline `<script>` and interpolates URL/text/title into JS strings via `addslashes(...)`
  - Parameters originate from query args and are not safely serialized as JSON
- **Why it matters**:
  - `addslashes` is not safe JS context encoding
  - Crafted values can break script context and execute arbitrary JS in admin/editor context
- **Action**:
  - Replace string interpolation with `wp_json_encode(...)` values
  - Prefer enqueued script + localized structured payload instead of large inline script blobs

### P0-06 — Unreachable dead code in poster submit path
- **File**: `assets/js/bitstream.js`
- **Evidence**:
  - After `window.location.href = ...; return;` there is trailing `form.reset()` and preview cleanup block that never executes
- **Risk**: None if removed.
- **Action**: Delete unreachable block to reduce cognitive noise and avoid misleading maintenance paths.

---

## P1 — High impact / high value cleanup

### P1-01 — Production debug logging flood in PWA manager
- **File**: `includes/class-pwa-manager.php`
- **Evidence**:
  - Extensive `error_log(...)` calls in request paths (service worker serving, media share handling, shortcut handling)
- **Why it matters**:
  - High I/O noise under traffic
  - Harder operational debugging due to log spam
  - Potential sensitive request metadata in logs
- **Action**:
  - Wrap logs with debug flag gate (or remove)
  - Keep only error-level logging for exceptional failures

### P1-02 — Public debug endpoints retained in production
- **File**: `includes/class-pwa-manager.php`
- **Evidence**:
  - `handle_debug_requests()` supports `?bitstream_debug=test_share`
  - Renders debugging UI and behaviors directly
- **Why it matters**:
  - Unnecessary attack surface and maintenance overhead
  - Debug behavior mixed into production runtime
- **Action**:
  - Remove debug endpoint logic from production code
  - If needed, gate with capability + nonce + explicit debug constant

### P1-03 — Debug file write in plugin tree
- **File**: `includes/class-pwa-manager.php`
- **Evidence**:
  - `show_debug_page()` writes to `../debug-share.log`
- **Why it matters**:
  - File system side-effects in plugin directory
  - Potential leakage and permissions friction
- **Action**: Remove file logging branch; use guarded WP logger only when debug-enabled.

### P1-04 — Admin JS localization contract mismatch
- **Files**:
  - `includes/class-admin-interface.php`
  - `assets/js/bitstream.js`
- **Evidence**:
  - Admin localizes `bitstream_ajax.ajaxurl`
  - JS expects `bitstream_ajax.ajax_url`
- **Why it matters**:
  - Admin poster/settings interactions can fail or degrade silently
- **Action**:
  - Standardize on one key (`ajax_url`) everywhere
  - Keep backward compatibility by outputting both keys during transition

### P1-05 — Multiple runtime localizations of same global object
- **Files**:
  - `includes/class-admin-interface.php`
  - `includes/class-block-editor.php`
- **Evidence**: Repeated `wp_localize_script('bitstream-js', 'bitstream_ajax', ...)` with different payload shape.
- **Why it matters**:
  - Last writer wins; missing keys at runtime depending on page
  - Hard-to-debug regressions
- **Action**:
  - Centralize localization payload builder
  - Merge page-specific keys onto baseline payload

### P1-06 — Duplicate cron schedule/registration responsibilities
- **Files**:
  - `bitstream.php`
  - `includes/class-admin-interface.php`
- **Evidence**:
  - Event scheduling exists in both bootstrap and admin class
- **Why it matters**:
  - Ownership ambiguity
  - Higher chance of drift / duplicate logic bugs
- **Action**: Keep scheduling and schedule definition in one place (prefer dedicated scheduler/service class).

### P1-07 — Session usage in WordPress request path
- **File**: `includes/class-pwa-manager.php`
- **Evidence**: `session_start()` in media share flow for unauthenticated users.
- **Why it matters**:
  - Session headers can conflict with caching/output
  - Non-standard in many WP plugin stacks
- **Action**: Replace session use with transient-based handoff (already used elsewhere in class).

### P1-08 — Hashtag counting algorithm does not scale with content volume
- **File**: `includes/class-content-display.php`
- **Method**: `get_hashtag_counts()`
- **Evidence**:
  - Executes `SELECT post_content FROM ... WHERE post_type='bit' AND post_status='publish'`
  - Performs regex extraction in PHP over all returned rows
- **Why it matters**:
  - Cache misses trigger full corpus scans (`O(N)` by published Bit volume/content size)
  - Memory/time cost rises sharply on larger sites and can become a runtime bottleneck
- **Action**:
  - Keep behavior unchanged short-term, but move hashtag extraction to save-time indexing
  - Introduce `bit_hashtag` taxonomy (or indexed equivalent) on `save_post_bit`
  - Use term counts for trending/sidebar instead of full-content scans

### P1-09 — Unsafe redirect/header patterns in request handling
- **Files**:
  - `includes/class-pwa-manager.php`
  - `includes/class-block-editor.php`
- **Evidence**:
  - Multiple `wp_redirect(...)` calls built from raw `$_SERVER['REQUEST_URI']`
  - `header(...)` calls in `inject_shared_url_script()` (admin render hook), which can trigger header timing issues
- **Why it matters**:
  - Redirect safety and URL normalization are inconsistent
  - Header emission from late render hooks is brittle (`headers already sent` risk)
- **Action**:
  - Replace with `wp_safe_redirect(...)` + `wp_validate_redirect(...)` where applicable
  - Stop using raw request URI directly; sanitize and normalize first
  - Remove ad-hoc `header(...)` calls from editor output hooks

### P1-10 — Share-target POST flow lacks nonce validation
- **File**: `includes/class-pwa-manager.php`
- **Evidence**:
  - `handle_shortcut_requests()` routes POST `new-bit` to `handle_media_share()` without nonce check
- **Why it matters**:
  - Privileged action path (`upload_files` / `edit_posts`) is CSRF-exposed compared with other AJAX/form handlers
- **Action**:
  - Add dedicated nonce token for share target POST flow (or equivalent signed transient handoff)
  - Validate before processing uploads/content

### P1-11 — Sensitive request payloads logged in production code paths
- **Files**:
  - `includes/class-pwa-manager.php`
  - `includes/class-ajax-handlers.php`
  - `includes/class-block-editor.php`
- **Evidence**:
  - Logs include `print_r($_POST)`, `print_r($_FILES)`, raw query dumps, and detailed runtime internals
- **Why it matters**:
  - Potential leakage of user data / URLs / upload metadata
  - High-volume noise and storage pressure in production
- **Action**:
  - Remove raw payload logging entirely or strictly gate behind debug constant + capability checks

### P1-12 — External CDN dependency in admin mappings screen
- **File**: `includes/admin-rebit-mappings-interface.php`
- **Evidence**:
  - Direct Font Awesome CSS loaded from `cdnjs.cloudflare.com`
- **Why it matters**:
  - External runtime dependency in admin UX (privacy, CSP/offline, deterministic build concerns)
- **Action**:
  - Serve icon CSS from plugin-bundled assets only
  - Keep JSON icon source local as already present in `assets/json/fontawesome6_free.json`

---

## P2 — Medium priority refactors (maintainability + performance)

### P2-01 — `bitstream.php` is overloaded
- **File**: `bitstream.php`
- **Evidence**: Contains bootstrap + rendering functions + HTML-heavy card rendering.
- **Action**:
  - Keep only plugin bootstrap/init here
  - Move rendering helpers to `includes/render/` (e.g., `card-renderer.php`, `rebit-renderer.php`)

### P2-02 — `class-shortcodes.php` mixes too many domains
- **File**: `includes/class-shortcodes.php`
- **Evidence**:
  - Feed rendering, poster rendering, settings tabs, admin-like maintenance actions
- **Action**: Split by concern:
  - `class-shortcode-feed.php`
  - `class-shortcode-poster.php`
  - `class-shortcode-settings.php`

### P2-03 — `class-admin-interface.php` contains dead or legacy admin pages
- **File**: `includes/class-admin-interface.php`
- **Likely dead/legacy methods**:
  - `reset_bitstream_page()`
  - `media_cleanup_page()`
  - `handle_post_rebit_redirect()`
  - `feed_intro_page()`
  - `rss_feeds_page()`
- **Evidence**: Not wired in current admin menu registration path; settings now delegated through shortcode UI.
- **Action**:
  - Remove truly unused methods
  - Or move to dedicated legacy file and deprecate

### P2-04 — Duplicated settings UI logic across classes
- **Files**:
  - `includes/class-shortcodes.php`
  - `includes/class-admin-interface.php`
- **Evidence**: Feed intro/RSS-related UI + form processing appear in both places.
- **Action**: Keep a single source of truth renderer/service for settings sections.

### P2-05 — PWA page detection logic duplicated
- **File**: `includes/class-pwa-manager.php`
- **Evidence**: Same “is bitstream page” checks repeated in `pwa_assets()` and `render_floating_bitstream_button()`.
- **Action**: Extract helper `is_bitstream_context()`.

### P2-06 — Inline styling in PHP templates is excessive
- **Files**:
  - `includes/class-shortcodes.php`
  - `bitstream.php`
  - `includes/admin-rebit-mappings-interface.php`
  - `includes/class-content-display.php`
- **Why it matters**:
  - Heavier HTML payload
  - Harder visual maintenance
  - CSS overrides become brittle
- **Action**: Move all inline styles to `assets/css/bitstream.css` and keep PHP as semantic markup only.

### P2-07 — Inlined JS snippets embedded in PHP classes
- **Files**:
  - `includes/class-pwa-manager.php`
  - `includes/class-block-editor.php`
  - `includes/admin-rebit-mappings-interface.php`
- **Action**:
  - Extract to dedicated JS modules in `assets/js/`
  - Keep server-side to data bootstrapping only

### P2-08 — `class-block-editor.php` includes non-editor runtime concerns
- **File**: `includes/class-block-editor.php`
- **Evidence**:
  - Frontend asset localizations and share/media request logic in editor-focused class
  - Large inline JS payload returned by `get_block_editor_js()`
  - Heavy inline injection path in `inject_shared_url_script()`
- **Action**:
  - Split into `class-editor-integration.php` and `class-shared-media-intake.php`
  - Move inline editor JS to dedicated asset file and enqueue via `wp_enqueue_script()`

### P2-09 — `assets/js/bitstream.js` is a monolith
- **File**: `assets/js/bitstream.js`
- **Evidence**: ~3349 lines; handles poster tabs, media uploads, cropper, floating menu, feed actions, comments, settings tab UI, and PWA behavior.
- **Action**: Split by feature modules:
  - `poster.js`
  - `feed-actions.js`
  - `media-uploader.js`
  - `cropper.js`
  - `floating-menu.js`
  - `pwa-share.js`

### P2-10 — `assets/css/bitstream.css` is a monolith
- **File**: `assets/css/bitstream.css`
- **Evidence**: ~3237 lines, mixed frontend/admin/settings/modals/legacy overrides.
- **Action**: Split to:
  - `feed.css`
  - `poster.css`
  - `settings.css`
  - `admin-mappings.css`
  - `single-bit.css`

### P2-11 — Legacy masonry references remain after layout migration
- **Files**:
  - `bitstream.php` plugin description
  - `includes/class-content-display.php`
- **Evidence**: Comments/styles refer to “masonry” behavior despite modern timeline architecture.
- **Action**: Remove stale comments/strings to reduce cognitive load.

### P2-12 — Heavy dynamic HTML in service classes
- **Files**:
  - `includes/class-shortcodes.php`
  - `includes/class-admin-interface.php`
  - `includes/class-pwa-manager.php`
- **Action**: Move large HTML blocks into `includes/views/` partials and keep classes focused on orchestration.

### P2-13 — ReBit mappings admin is still huge despite extraction
- **File**: `includes/admin-rebit-mappings-interface.php`
- **Evidence**: ~915 lines with mixed HTML/CSS/JS and duplicated DOM-ready wiring + CDN dependency.
- **Action**:
  - Split into view + enqueued CSS/JS assets
  - Keep form processing in PHP class only

### P2-18 — Over-broad MutationObserver work on entire document body
- **File**: `assets/js/bitstream.js`
- **Evidence**:
  - A body-wide `MutationObserver` re-runs expensive setup (`makeEmbedsResponsive`, media session binding, floating menu init, comment toggle init) for every subtree mutation
- **Why it matters**:
  - Unnecessary repeated work on dynamic pages
  - Risk of event rebinding churn and avoidable layout/script overhead
- **Action**:
  - Scope observer to feed container and use targeted selectors
  - Debounce batched updates and guard idempotent initializers

### P2-14 — Duplicate media cleanup workflow touchpoints
- **Files**:
  - `includes/class-admin-interface.php`
  - `includes/class-shortcodes.php`
  - `bitstream.php`
- **Evidence**: Scheduling, cleanup invocation, and settings controls spread across files.
- **Action**: Introduce single `class-media-cleanup-service.php` and call it from all entrypoints.

### P2-15 — Debug logs duplicated in two places
- **Files**:
  - `includes/class-error-logger.php`
  - `includes/class-shortcodes.php` advanced settings
- **Evidence**: log listing/clearing logic duplicated.
- **Action**: Keep only logger class for log operations; settings tab should call logger API.

### P2-16 — `class-ajax-handlers.php` needs explicit domain split
- **File**: `includes/class-ajax-handlers.php`
- **Evidence**: Single class handles media, poster submit flow, feed interactions, OG/rebit preview helpers.
- **Action** (target split map):
  - `class-ajax-media.php` (upload, crop, audio/meta/artwork)
  - `class-ajax-poster.php` (submit/update/draft/schedule)
  - `class-ajax-interactions.php` (likes, load more, delete)
  - `class-ajax-rebit.php` (OG fetch + preview rendering)

### P2-17 — Root-level file policy should be enforced
- **Files**: plugin root
- **Evidence**:
  - Root currently includes non-bootstrap artifact (`bitstream-quick-media.js`)
  - Legacy root worker route references non-existent `sw-feed.js`
- **Action**:
  - Keep root restricted to bootstrap, required root-scope service worker(s), and config/docs
  - Move/retain all feature assets under `assets/`
  - Remove stale root worker references unless worker file is intentionally reintroduced

---

## P3 — Low priority / hygiene

### P3-01 — Naming consistency (“Rebit” vs “ReBit”)
- **Files**: multiple UI strings/classes
- **Action**: Normalize naming in UI text/constants (no behavior change).

### P3-02 — Keep README/plugin header terminology aligned
- **Files**:
  - `README.md`
  - `bitstream.php` header description
- **Evidence**: plugin description still mentions masonry while docs describe newer social feed.
- **Action**: Update text for clarity.

### P3-03 — Standardize strictness/sanitization style
- **Files**: multiple (mixed `sanitize_url`, `esc_url_raw`, direct `$_GET` checks)
- **Action**: Introduce helper layer for request input normalization.

---

## File Rename / Reorganization Recommendations

These are non-functional refactors to improve navigation and ownership.

### Suggested renames (behavior-preserving)
- `includes/class-block-editor.php` → `includes/class-editor-and-share-integration.php`
- `includes/class-content-display.php` → `includes/class-feed-content-rendering.php`
- `includes/admin-rebit-mappings-interface.php` → `includes/views/admin/rebit-mappings.php`

### Suggested new folders
- `includes/services/` (media cleanup, OG fetch orchestration, PWA routing)
- `includes/views/` (feed cards, settings sections, admin templates)
- `assets/js/modules/` (feature modules)
- `assets/css/modules/` (feature styles)

---

## Dead Code Candidate Inventory (with confidence)

### High confidence
- `bitstream-quick-media.js` (orphan)
- `BitStream_PWA_Manager::show_upload_progress_page()` (unused)
- `BitStream_OG_Fetcher::schedule_og_fetch()` (unhooked)
- `BitStream_OG_Fetcher::process_og_data()` (unhooked)
- `sw-feed.js` rewrite/serve branch (target file missing)
- Unreachable block after feed redirect in `assets/js/bitstream.js` poster submit handler

### Medium confidence (needs one runtime pass before delete)
- `BitStream_Admin_Interface::reset_bitstream_page()`
- `BitStream_Admin_Interface::media_cleanup_page()`
- `BitStream_Admin_Interface::handle_post_rebit_redirect()`
- `BitStream_Admin_Interface::feed_intro_page()`
- `BitStream_Admin_Interface::rss_feeds_page()`

Rationale: methods exist but current admin menu flow appears to route through unified shortcode-driven settings.

---

## Safe Optimization Roadmap (order to minimize risk)

### Phase 1 — Zero-risk cleanup
1. Remove orphan and dead branches (`bitstream-quick-media.js`, unused methods, missing `sw-feed.js` route path).
2. Remove/guard debug endpoints and logging in production paths.
3. Normalize JS config keys (`ajax_url`) while maintaining compatibility.

### Phase 2 — Structural split without behavior changes
1. Extract rendering helpers from `bitstream.php` into render/view files.
2. Split `class-shortcodes.php` and `class-pwa-manager.php` by responsibilities.
3. Extract inline CSS/JS from PHP into asset files.

### Phase 3 — Asset modularization
1. Break `assets/js/bitstream.js` into feature modules.
2. Break `assets/css/bitstream.css` into feature CSS modules.
3. Add lightweight bundling/build step only if desired (optional).

### Phase 4 — Hardening and consistency
1. Consolidate cleanup scheduler ownership.
2. Consolidate logger UI/logic.
3. Standardize naming and request sanitization patterns.

---

## Notes and Caveats

- This audit is static and repository-based; no integration runtime test matrix was executed against a live WordPress instance.
- “Medium confidence” dead-code candidates should be validated with one quick manual smoke run before final deletion.
- Vendor diagnostics warnings about WP constants (e.g., `ABSPATH`, `HOUR_IN_SECONDS`) are expected in non-WP runtime analysis context and are not by themselves runtime defects.

---

## Quick Win Checklist

- [ ] Delete `bitstream-quick-media.js`
- [ ] Replace inline JS interpolation in `inject_shared_url_script()` with `wp_json_encode` payload
- [ ] Remove `sw-feed.js` rewrite branch or add actual file
- [ ] Remove unhooked OG legacy methods
- [ ] Remove/guard debug endpoints (`bitstream_debug=*`) and file logging
- [ ] Replace `wp_redirect(...)` flows with `wp_safe_redirect(...)` + URL validation
- [ ] Add nonce validation to PWA share-target POST flow
- [ ] Standardize `bitstream_ajax.ajax_url` everywhere
- [ ] Stop full-table hashtag scans by moving to save-time hashtag indexing
- [ ] Consolidate cron scheduling logic into one class
- [ ] Extract top 3 inline-style-heavy templates into CSS classes
