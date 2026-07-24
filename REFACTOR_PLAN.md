# BitStream — Refactor Plan

> Generated from a comprehensive read-only audit.
> Execute steps **one at a time, top to bottom**. Mark each checkbox after verifying.

### Mandatory Step Wrap-Up Rules
For **EVERY** step executed:
1. **`CHANGELOG.md`**: Update `CHANGELOG.md` when making user-facing or noteworthy backend changes for the current unreleased version.
2. **`ARCHITECTURE.md`**: Update `ARCHITECTURE.md` if structural file routing, hooks, or component organization change during implementation.

---

## Priority 1 — Bugs & Fragile Logic (fix before anything else)

### [x] Step 1: Fix stray CSS rule in `class-content-display.php`

**File:** `includes/class-content-display.php` — lines 244-246

**Problem:** An orphaned CSS declaration (`visibility: visible !important; }`) sat outside any selector block. This invalid CSS caused browsers to skip the immediately following wildcard rule `.bitstream-single-wrapper .bit-card * { display: revert !important; }`. When the invalid syntax was removed, the wildcard rule took effect, forcing `display: revert !important` onto all child elements (including FontAwesome `<i>` tags and footer action buttons), breaking icon rendering and flex layout.

**Fix:** Removed both the invalid syntax block and the destructive `.bitstream-single-wrapper .bit-card *` wildcard rule. Specific card components (`.bit-card-header`, `.bit-card-footer`, `.bit-action`, etc.) retain their explicit layout rules.

**Verify:** Load any single-bit permalink (e.g. `/bit/some-slug/`). Confirm FontAwesome action icons (comment dots, heart, share, retweet, pencil, trash) render cleanly with correct alignment and colors. Inspect `<head>` in DevTools to confirm no CSS parse errors.

**Documentation:** Update `CHANGELOG.md` if applicable; check `ARCHITECTURE.md`.

---

### [x] Step 2: Fix `save_rebit_og_data` — fragile `$_POST` hijack in `class-content-display.php`

**File:** `includes/class-content-display.php` — lines 370-392

**Problem:** When a Rebit URL is saved without OG data (e.g. from wp-admin), the method overwrites the entire `$_POST` superglobal, injects a fake nonce, fakes `wp_doing_ajax`, and calls `handle_fetch_og_data()` — which itself calls `wp_send_json_*`, terminating the PHP process via `die()`. This means:
1. The `$_POST` backup/restore on line 390 is **never reached** because `wp_send_json_*` exits.
2. Any hooks attached after `save_post_bit` with priority > 10 (like `save_post_hashtags`) lose the real `$_POST`.
3. The entire approach only works "by accident" because WordPress's publish flow has already finished writing the post by the time this `die()` fires.

**Fix:** Replace the AJAX-call hack with a direct call to `BitStream_OG_Fetcher::fetch_og_data($rebit_url)`, writing the returned OG data into postmeta directly. No `$_POST` manipulation, no faked AJAX context, no `die()`.

**Verify:**
1. Create a new Rebit from wp-admin by setting `bitstream_rebit_url` meta to any valid URL (e.g. `https://example.com`). Save the post.
2. Confirm `_bitstream_og_title` and/or `_bitstream_og_desc` meta are populated (check in post meta debug or the database).
3. Confirm the admin save flow completes normally — no white-screen or "headers already sent" errors.

**Documentation:** Update `CHANGELOG.md` (bug fix); update `ARCHITECTURE.md` if OG saving flow/routing changes.

---

### [x] Step 3: Harden `handle_like` — no per-user tracking in `class-ajax-handlers.php`

**File:** `includes/class-ajax-handlers.php` — lines 1456-1490

**Problem:** The like/unlike handler blindly increments or decrements a counter based on a `type` parameter from the client. There is **zero server-side validation** of whether the current user has already liked the post:
- A user (or bot) can send unlimited `like` requests to inflate the count.
- A user can send unlimited `unlike` requests to decrement to zero.
- Unauthenticated requests are allowed (`wp_ajax_nopriv_bitstream_like`).
- The `max(0, ...)` guard only prevents negative values — not duplicates.

**Fix:** Track likes per-user (or per-IP for logged-out users) via `user_meta` or a dedicated `postmeta` array. On `like`, check if already liked and skip. On `unlike`, check if actually liked and decrement. Remove the `nopriv` registration if likes should require login, or implement IP-based tracking for anonymous likes.

**Verify:**
1. Like a bit. Refresh the page — the heart should stay filled and the count stable.
2. Click like again — the count should NOT increment a second time.
3. Unlike — count decrements by 1. Click unlike again — stays at same value.
4. Open a private/incognito window: verify behavior matches chosen strategy (login-required or IP-based).

**Documentation:** Update `CHANGELOG.md` (security/feature fix); update `ARCHITECTURE.md` for like handler state tracking.

---

## Priority 2 — Inline Style Explosion (maintainability)

### [x] Step 4: Extract inline styles from `render_card()` in `class-content-display.php`

**File:** `includes/class-content-display.php` — `render_card()` method (lines 980-1198)

**Problem:** 72 inline `style="..."` attributes are scattered across the card markup. Every visual tweak requires editing PHP, and the same values (e.g. `border-radius:15px`, `color:var(--wp--preset--color--accent-1,#2c6e49)`) are duplicated dozens of times. This makes theming impossible and increases HTML payload per card.

**Fix:** Move all inline styles to named classes in `bitstream.css`. The HTML already has class names (`.bit-card`, `.bit-card-header`, `.bit-card-content`, `.bit-card-footer`, `.bit-action`, etc.) — they just need CSS rules. Strip `style="..."` from the PHP and rely on the external stylesheet.

**Verify:** Load the feed page. Compare visual rendering to a "before" screenshot taken prior to changes. All cards should look identical. Check that `bitstream.css` contains the new rules and the PHP no longer has `style=` in `render_card()`.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [x] Step 5: Extract inline styles from `render_nested_quoted_card()` in `class-content-display.php`

**File:** `includes/class-content-display.php` — `render_nested_quoted_card()` (lines 827-920)

**Problem:** Same inline-style explosion as `render_card()`, but for nested quoted cards. Duplicates many of the same values (border-radius, padding, colors).

**Fix:** Use the existing `.bit-card-quoted-nested` class and add child rules in `bitstream.css`. Remove inline `style=` attributes from the PHP template.

**Verify:** Create or view a bit that quotes another bit. Both the parent card and the nested quote card should render identically to before.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [x] Step 6: Extract inline styles from `render_rebit_section()` in `class-content-display.php`

**File:** `includes/class-content-display.php` — `render_rebit_section()` (lines 715-818)

**Problem:** Rebit preview cards (YouTube embeds, Twitter-style cards, generic link cards) all use extensive inline styles. Three separate visual branches (YouTube, Twitter, generic) repeat similar layout rules.

**Fix:** Consolidate into CSS classes: `.bit-rebit-embed`, `.bit-rebit-preview`, `.bit-rebit-twitter`, `.bit-rebit-label`. Move shared layout (flex, border-radius, padding) to a base class and branch-specific overrides to modifiers.

**Verify:** View a Rebit that links to YouTube, one that links to Twitter/X, and one with a generic URL. All three should render correctly.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [x] Step 7: Extract inline styles from `render_og_card()` in `class-content-display.php`

**File:** `includes/class-content-display.php` — `render_og_card()` (lines 455-485)

**Problem:** The OG card (used inside quoted content) has all styles inline: flex layout, border-radius, box-shadow, thumbnail sizing.

**Fix:** Use the existing `.bitstream-og-card`, `.bitstream-og-thumb`, `.bitstream-og-meta`, `.bitstream-og-title`, `.bitstream-og-desc`, `.bitstream-og-url` classes. Move styles to `bitstream.css`.

**Verify:** View a quoted bit that contains a Rebit. The OG preview card within the quote should render with correct flex layout, thumbnail, and text.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [x] Step 8: Extract inline styles from `single_bit_styles()` to `bitstream.css`

**File:** `includes/class-content-display.php` — `single_bit_styles()` (lines 138-342)

**Problem:** ~200 lines of CSS are injected as a `<style>` block in `wp_head` only on single-bit pages. This bypasses the external stylesheet, cannot be cached by the browser, and uses excessive `!important` overrides.

**Fix:** Move these rules into `bitstream.css` under a `.bitstream-single-bit` scope. Remove the `single_bit_styles()` method and its `wp_head` hook. Reduce `!important` usage by increasing specificity where needed.

**Verify:** View a single-bit permalink. Card should render identically. Check that no `<style>` block with these rules appears in `<head>`. Verify CSS is loaded from the external stylesheet.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` if hooks in `class-content-display.php` are removed.

---

### [x] Step 9: Extract inline styles from mood buttons in `class-shortcodes.php`

**File:** `includes/class-shortcodes.php` — mood modal markup (lines ~888-934)

**Problem:** 179 instances of `style="..."` in the shortcodes file. The mood grid buttons repeat the exact same 4-line `style=` attribute (flex, column, padding, border, border-radius, background, cursor, transition) for **every single mood button** — 9 predefined moods × identical styles.

**Fix:** Create a `.bitstream-mood-btn` CSS rule in `bitstream.css` that covers these shared styles. Strip inline `style=` from each `<button>`. Also move the custom mood form layout, saved moods grid, and divider styles to CSS.

**Verify:** Open the Composer → Mood modal. All predefined moods should display as a 3-column grid. Click a mood — it should highlight. Custom mood section should look identical.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

## Priority 3 — Code Duplication & Structural Debt

### [x] Step 10: Deduplicate "best preview size" logic in `class-ajax-handlers.php`

**File:** `includes/class-ajax-handlers.php`

**Problem:** The logic for finding the best preview URL for an image attachment (iterate `['large', 'medium_large', 'medium']`, fall back to `full`) is duplicated verbatim in:
- `create_media_attachment_from_upload()` (lines 326-351)
- `handle_get_attachment_data()` (lines 888-911)

**Fix:** Extract a `private function get_best_preview_url($attachment_id)` and call it from both methods.

**Verify:** Upload a media file via the composer — check the preview thumbnail renders. Edit an existing bit that has media — the attachment data endpoint should return a valid `preview_url`.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` if AJAX helper functions change.

---

### [x] Step 11: Deduplicate Bit vs Rebit submit logic in `handle_submit_composer()`

**File:** `includes/class-ajax-handlers.php` — `handle_submit_composer()` (lines 1088-1451)

**Problem:** The Bit and Rebit branches of `handle_submit_composer()` repeat nearly identical blocks:
- Schedule argument building + draft overrides (~20 lines each)
- Post content assembly with media markup (~10 lines each)
- `wp_insert_post` / `wp_update_post` call (~15 lines each)
- Attachment assignment, mood saving, post-count flushing (~20 lines each)
- JSON success response building (~25 lines each)

The method is 363 lines total. The two branches share ~80% of their logic.

**Fix:** Extract shared operations into private helpers:
- `private function build_post_content($content, $attachment_ids)` — builds content + media markup
- `private function save_post_metadata($post_id, $data)` — saves mood, attachments, quote, rebit OG
- `private function build_submit_response($post_id, ...)` — assembles the JSON success response

Then `handle_submit_composer()` becomes two thin branches calling these helpers.

**Verify:** Publish a new Bit, a new Rebit, edit a Bit, edit a Rebit, save a draft, and schedule a Bit. All should succeed with correct data.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` for handler refactoring.

---

### [x] Step 12: Reduce `window.*` global pollution in JS

**Files:** `assets/js/bitstream-uploader.js`, `assets/js/bitstream-composer.js`, `assets/js/bitstream-timeline.js`, `assets/js/bitstream.js`

**Problem:** 96+ functions and variables are exported to `window.*` for cross-module communication:
- `window.getExistingAttachments`, `window.updateAttachmentsList`, `window.renderMultiplePreviews` (uploader → composer/timeline)
- `window.bitstreamOpenCropper`, `window.bitstreamOpenLightbox` (cropper/lightbox → everywhere)
- `window.bsMobileAutoResize`, `window.showDiscardConfirmation`, `window.closeAllBsEmojiPickers` (bitstream.js → composer)
- `window.syncEditPreviewArea`, `window.parseEmojis` (timeline ↔ composer)

This creates an implicit dependency graph that is invisible and fragile — any typo silently becomes `undefined`.

**Fix:** Consolidate all shared functions onto `window.BitStream.*` namespace sub-objects (e.g. `window.BitStream.Media.updateAttachmentsList`, `window.BitStream.UI.openLightbox`). This is a gradual migration: add the new paths, update callers module-by-module, then remove old `window.*` exports.

**Verify:** Full manual smoke test of all features: upload media, crop, open lightbox, compose a bit with a mood, quote a bit, open the edit modal, resize on mobile. No console errors.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` for frontend JS API changes.

---

## Priority 4 — Performance & Efficiency

### [x] Step 13: Optimize `collect_bit_attachment_ids()` — N+1 queries

**File:** `includes/class-ajax-handlers.php` — `collect_bit_attachment_ids()` (lines 207-256)

**Problem:** When deleting a bit, this method performs:
1. `get_post_meta` × 2 (attachment ID + attachment IDs)
2. `get_post_thumbnail_id` × 1
3. `get_children` × 1 (separate query)
4. `get_post_field` × 1 (post content)
5. `attachment_url_to_postid` × N (one per URL found in content — each is a full DB query)

Step 5 is the killer: `attachment_url_to_postid()` does a `LIKE` search on `_wp_attached_file` meta for **every** URL found in the post content. For a gallery with 10 images, that's 10 separate DB queries.

**Fix:** The content-based URL resolution (step 5) is redundant because step 3 (`get_children`) and step 1 (meta tracking) already capture all media that was properly attached. Remove the `preg_match_all` URL extraction loop. If the `wp-image-{id}` class extraction (step 4a) is kept, it makes the URL loop fully redundant.

**Verify:** Delete a bit that has multiple attached images. Confirm all orphaned media files are deleted from the media library. Confirm media shared with other posts is NOT deleted.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [x] Step 14: Avoid full `WP_Query` in submit response for draft count

**File:** `includes/class-ajax-handlers.php` — submit response (lines 1282-1288 and 1433-1439)

**Problem:** Every successful publish/draft/schedule fires a `new WP_Query` just to get the current user's draft count — purely for UI badge updates. This is wasteful for a published post response where the user may not even be looking at the drafts panel.

**Fix:** Use `wp_count_posts('bit')` with author filtering, or cache the count in user meta and decrement/increment on save/delete instead of querying every time.

**Verify:** Publish a bit and inspect the AJAX response — it should still contain a `draft_count` key with the correct number.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [x] Step 15: Lazy-load emoji parsing in `class-shortcodes.php`

**File:** `includes/class-shortcodes.php` — mood grid and saved moods rendering

**Problem:** The mood modal markup includes 9+ emoji buttons, each with emoji characters that trigger `parseEmojis()` calls on every modal open. The `renderSavedMoods()` function (JS-side) rebuilds the entire saved moods grid from scratch every time the modal opens, including DOM creation with inline styles for each button.

**Fix:** Render the predefined mood grid once in PHP without inline styles (done in Step 9). For saved moods, avoid calling `parseEmojis()` on the entire grid — instead, only parse newly added/changed buttons.

**Verify:** Open the mood modal quickly 5+ times. There should be no perceptible lag. Check Performance panel in DevTools for reduced DOM churn.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

## Priority 5 — Code Organization

### [ ] Step 16: Split `class-shortcodes.php` into template partials

**File:** `includes/class-shortcodes.php` (2603 lines)

**Problem:** This is the largest PHP file at 170KB. It mixes:
- Shortcode registration and data querying (business logic)
- HTML rendering for the composer form, all modals, settings panels, feed sidebar, and search screen
- Static helper methods for feed URL resolution and user post counts

**Fix:** Extract template partials into `templates/` directory:
- `templates/composer-form.php` — the composer and its modals
- `templates/feed-sidebar.php` — sidebar panels (search, filters, hashtags, emotions, archive)
- `templates/settings-panel.php` — settings form markup
Keep `class-shortcodes.php` as the controller that prepares data and `include()`s the partials.

**Verify:** Load the feed page — full layout should be identical. Open each modal (media, rebit, schedule, mood, settings, drafts, scheduled). All should work normally.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` for new template directory structure.

---

### [ ] Step 17: Split `bitstream-timeline.js` into focused modules

**File:** `assets/js/bitstream-timeline.js` (3218 lines)

**Problem:** Single file handles: edit modal population, media upload binding, link metadata fetching/editing, schedule controls, form submission, quote preview, mood integration, AJAX delete, like/unlike, comment toggling, infinite scroll, load more, share flow, lightbox delegation, and gallery click handling.

**Fix:** Incrementally extract into focused modules under `assets/js/`:
- `bitstream-edit-modal.js` — all edit-form logic (populate, bind, submit, schedule, media, link-meta)
- Keep `bitstream-timeline.js` for feed-level concerns (scroll, load-more, like, comment toggle, delete, share)

Wire both via the existing `BitStream.Timeline.init()` pattern.

**Verify:** Same as Step 12 smoke test: edit a bit, delete a bit, like a bit, toggle comments, load more, scroll, open edit modal from timeline. No console errors.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` for new JS asset breakdown.

---

### [ ] Step 18: Split `bitstream-composer.js` into focused modules

**File:** `assets/js/bitstream-composer.js` (1715 lines)

**Problem:** Similar to timeline — one file handles: modal open/close, rebit fetching/preview, media modal sync, schedule modal, mood modal (including custom mood CRUD and settings persistence), draft loading, submit, and preview area sync.

**Fix:** Extract the mood management logic (custom mood CRUD, settings tab, emoji picker integration) into `bitstream-mood.js`. Keep core composer flow in `bitstream-composer.js`.

**Verify:** Open composer, set a mood, save a custom mood, manage moods in settings, remove a mood. All should work.

**Documentation:** Update `CHANGELOG.md`; update `ARCHITECTURE.md` for new JS asset breakdown.

---

## Priority 6 — Minor Cleanup

### [ ] Step 19: Remove duplicate docblock in `class-ajax-handlers.php`

**File:** `includes/class-ajax-handlers.php` — lines 156-164

**Problem:** Two consecutive `/** */` docblock comments before `build_media_markup()`:
```php
    /**
     * Build media markup for Bit post content
     */
    /**
     * Build media markup for Bit post content (supports multiple media gallery)
     */
```
The first is the old single-attachment docblock that wasn't removed when the method was updated.

**Fix:** Remove the first (outdated) docblock.

**Verify:** `php -l includes/class-ajax-handlers.php` passes.

**Documentation:** Update `CHANGELOG.md` if applicable; check `ARCHITECTURE.md`.

---

### [ ] Step 20: Clean up `const` declarations that are always `null` in `bitstream-composer.js`

**File:** `assets/js/bitstream-composer.js` — lines 76-77, 82-83

**Problem:**
```js
const previewDraft = null;
const previewDraftLabel = null;
// ...
const composerSaveDraftBtn = null;
```
These are declared as `const` but never reassigned — they will always be `null`. Code elsewhere checks `previewDraft && !previewDraft.hidden` which will always be `false`. This is dead code that adds confusion.

**Fix:** Either wire these to actual DOM queries (if the elements exist in the HTML) or remove the declarations and all references to them.

**Verify:** Open the composer. Save a draft via the action button. Reopen the composer — the draft preview should work as expected (or confirm it was never functional and remove cleanly).

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

### [ ] Step 21: Remove JS inline styles from dynamically created mood buttons

**File:** `assets/js/bitstream-composer.js` — `renderSavedMoods()` and `renderSettingsMoods()` functions

**Problem:** Both functions create DOM elements with multi-line `style.cssText = '...'` assignments. This duplicates the PHP-side inline styles from Step 9 and makes it impossible to theme mood buttons from CSS alone.

**Fix:** After Step 9 CSS classes are in place, update the JS to use the same class names instead of `style.cssText`. E.g. `btn.className = 'bitstream-mood-btn'` with no inline styles.

**Verify:** Open mood modal → saved moods grid should render correctly. Open Settings → Moods tab → manage list should render correctly with up/down/delete buttons.

**Documentation:** Update `CHANGELOG.md`; check `ARCHITECTURE.md`.

---

## Notes

- **Step ordering matters.** Priority 1 steps fix real bugs. Priority 2 steps remove the inline-style debt that makes all future work harder. Priority 3+ builds on the cleaner base.
- **Each step is independently deployable.** You can ship after any completed step.
- **CSS file size will increase** during Priority 2 steps, but total payload decreases because inline styles are removed from every card in the HTML. A single CSS file is cached by the browser; inline styles are sent per-page-load.
- **Documentation wrap-up is required after every step:** Maintain `CHANGELOG.md` for notable changes and `ARCHITECTURE.md` whenever structural files, routes, or assets change.
