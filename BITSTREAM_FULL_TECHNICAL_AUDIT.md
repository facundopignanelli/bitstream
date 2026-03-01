# BitStream Full Technical Audit Report

Date: 2026-03-01  
Scope: Full repository audit for optimization, security hardening, and technical debt reduction without changing feature set.

---

## bitstream.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Comment form action uses request URI override; even escaped output still relies on server-supplied path and bypasses default WP form handling. | Remove custom `form_action`/`comment_post_redirect` overrides and use default `comment_form()` behavior. |
| Redundancy & Dead Code | Med | Weekly cleanup scheduling responsibility overlaps with admin component scheduling path. | Keep cron scheduling in one place only (activation or dedicated scheduler class). |
| Refactoring Opportunities | High | `bitstream_render_card()` and related render helpers are very large and mix data preparation with full HTML output and inline style declarations. | Split rendering into template partials and keep PHP methods focused on data assembly. |
| Modernization | Low | Heavy inline styles in render helpers reduce maintainability and override flexibility. | Move inline styles to `assets/css/bitstream.css` and keep semantic class-based markup. |

---

## includes/admin-rebit-mappings-interface.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Loads Font Awesome from external CDN in admin at runtime. | Bundle/admin-enqueue local asset or enforce integrity/crossorigin policy and fallback strategy. |
| Redundancy & Dead Code | High | Remove-flag hidden field naming does not align with backend expected structure (`existing` key mismatch), risking failed remove persistence. | Align hidden remove input names with backend parser shape used in mappings save handler. |
| Refactoring Opportunities | High | Single file contains large mixed PHP+HTML+CSS+JS UI and global JS state. | Split into dedicated PHP template + enqueueable CSS/JS assets. |
| Modernization | Med | Multiple debug logs and repeated initialization patterns in inline JS. | Convert to scoped module pattern and remove production debug logging. |

---

## includes/class-admin-interface.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | High | `save_quoted_meta()` updates metadata without nonce validation, autosave/revision checks, or explicit edit capability check. | Add nonce field verification, autosave/revision bailouts, and `current_user_can('edit_post', $post_id)` gate. |
| Redundancy & Dead Code | High | Several public methods appear unhooked/unreachable in current flow (`handle_post_rebit_redirect`, `feed_intro_page`, `rss_feeds_page`, `reset_bitstream_page`, `media_cleanup_page`). | Remove unused methods or wire them explicitly through menus/routes. |
| Refactoring Opportunities | High | Class handles too many concerns (menus, reset, cleanup, feeds, mappings, permalink notices). | Split into focused classes: AdminMenu, MaintenanceService, FeedSettings, MappingsController. |
| Modernization | Med | Large inline JS/CSS emitted directly from PHP pages. | Move scripts/styles to proper asset files with localized data only. |

---

## includes/class-ajax-handlers.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | High | Debug logging includes raw request payloads and metadata in multiple handlers, risking sensitive data leakage in logs. | Remove request-dump logging or gate behind strict `WP_DEBUG` with redaction. |
| Security & Best Practices | Med | Nonce checking style is inconsistent (`check_ajax_referer` vs raw `wp_verify_nonce` usage). | Standardize request parsing and nonce validation via `check_ajax_referer`. |
| Redundancy & Dead Code | Med | `wp_ajax_nopriv_bitstream_like` is registered but logic requires `current_user_can('read')`, so anonymous route is effectively dead. | Either remove nopriv route or implement explicit guest-like support with anti-abuse controls. |
| Refactoring Opportunities | High | Single class (~1700 lines) combines upload, crop, audio metadata, OG preview, post creation, likes, deletes, and cleanup. | Split into service classes (`MediaService`, `PosterService`, `InteractionService`, `PreviewService`). |
| Modernization | Med | Large monolithic handlers repeat validation/parsing logic. | Introduce shared validation helpers and structured request handling. |

---

## includes/class-block-editor.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | High | Extensive direct script injection based on query params and echoed JS string blocks in admin context. | Pass sanitized values via localization and move logic to external script modules. |
| Security & Best Practices | Med | Verbose debug logging remains in runtime paths. | Gate logs behind `WP_DEBUG` and remove high-volume logs from production. |
| Redundancy & Dead Code | Med | Significant overlap with frontend `assets/js/bitstream.js` for media insertion/share handling logic. | Keep editor-specific logic only in block editor script and deduplicate shared flows. |
| Refactoring Opportunities | High | `get_block_editor_js()` returns very large inline JS heredoc. | Move to dedicated file with modularized functions and clearer ownership. |
| Modernization | Med | Mixed legacy fallback patterns and repeated polling loops. | Use modern Gutenberg data-store subscriptions and bounded retry utility wrappers. |

---

## includes/class-content-display.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | `save_rebit_og_data()` mutates global `$_POST` and forces AJAX context to reuse handler behavior. | Extract OG-fetch persistence into shared service and call directly without global mutation. |
| Redundancy & Dead Code | Med | OG preview rendering responsibilities overlap with other renderers/helpers. | Consolidate to one rendering source used by feed, preview, and quote contexts. |
| Refactoring Opportunities | Med | `single_bit_styles()` outputs large inline CSS block from PHP. | Move styles into stylesheet and use body classes only. |
| Modernization | Low | Class combines routing/display/filtering/cache concerns. | Separate display controller from enrichment/cache logic. |

---

## includes/class-error-logger.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Redundancy & Dead Code | Med | `add_debug_menu()` exists but is not hooked in constructor. | Hook explicitly or remove method. |
| Security & Best Practices | Med | AJAX `clear_logs()` uses `check_admin_referer` flow and `wp_die` response style. | Use `check_ajax_referer` and return consistent JSON responses. |
| Refactoring Opportunities | Low | Logger storage and admin UI display responsibilities are mixed. | Split persistence/logger utility from UI/controller layer. |

---

## includes/class-og-fetcher.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Modernization | High | Uses `str_starts_with` / `str_contains` while plugin header states PHP 7.4 minimum. | Raise required PHP version to 8.0+ or replace with PHP 7.4-compatible helper functions. |
| Redundancy & Dead Code | High | `schedule_og_fetch()` and `process_og_data()` remain implemented while hooks are commented out. | Remove obsolete methods or restore fully tested scheduled flow. |
| Refactoring Opportunities | Med | Parsing and persistence logic duplicated between sync/legacy methods. | Keep one OG parser pipeline and reuse across call sites. |
| Security & Best Practices | Low | External avatar fallback introduces third-party fetch path/privacy dependency. | Make external avatar enrichment optional/configurable via setting/filter. |

---

## includes/class-post-type.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Search query is altered via regex over SQL where-clause, which is brittle. | Replace regex SQL rewriting with explicit query clauses/meta queries where possible. |
| Refactoring Opportunities | Med | `auto_generate_title()` couples cache/query/title generation and can be simplified. | Extract deterministic title generation and use lighter count retrieval strategy. |
| Modernization | Low | Cache invalidation and daily count logic can be made more explicit and resilient. | Use dedicated helper for daily sequence with strict post-status criteria. |

---

## includes/class-pwa-manager.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | High | Extensive raw request logging (`$_GET`, `$_POST`, `$_FILES`) risks leaking sensitive content. | Remove or heavily redact logs; keep debug logging gated and minimal. |
| Security & Best Practices | High | Uses `session_start()` and stores request payloads in session during WP request lifecycle, which can conflict with caching/headers. | Replace with transient-based tokenized storage (minimal fields). |
| Redundancy & Dead Code | High | Routes and serving logic reference `sw-feed.js` file that is missing from repository. | Remove dead rewrite path or add/ship missing service worker file. |
| Redundancy & Dead Code | Med | `show_upload_progress_page()` exists but is not used in active share/upload flow. | Remove unused method or integrate intentionally. |
| Refactoring Opportunities | High | Class mixes PWA head assets, floating menu, rewrite rules, share ingestion, debug pages, and upload handling. | Split by responsibility: asset controller, rewrite controller, share controller, debug controller. |
| Modernization | Med | Large inline script/style blocks and debugging UI embedded in PHP methods. | Move behavior to versioned JS/CSS assets and keep PHP focused on routing/data. |

---

## includes/class-rebit-mappings.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Domain matching uses broad substring checks (`stripos`) and may produce false positives. | Normalize host and enforce exact/subdomain-boundary match logic. |
| Redundancy & Dead Code | Med | Preset/default mappings are duplicated across methods. | Use single source of truth for default/preset mapping definitions. |
| Refactoring Opportunities | Low | Repeated sanitization/duplicate-detection logic appears in multiple methods. | Centralize normalization/dedup helper for mapping records. |

---

## includes/class-rss-feeds.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Feed description/content includes rich post markup in CDATA without strict feed-oriented sanitization policy. | Sanitize rendered feed content with feed-safe allowlist (`wp_kses`) before output. |
| Redundancy & Dead Code | Low | `flush_feeds()` method appears unused in current flow. | Remove method or wire through a single canonical admin action. |
| Refactoring Opportunities | Med | `generate_rss_feed()` is large and tightly coupled to output echoes. | Separate feed item mapping from serialization/output. |

---

## includes/class-shortcodes.php

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Frontend settings rendering instantiates admin interface class, which registers admin hooks as side effects. | Extract reusable services and avoid constructing hook-heavy admin controllers in shortcode rendering paths. |
| Redundancy & Dead Code | High | Settings and maintenance flows duplicate logic already present in `class-admin-interface.php`. | Keep one implementation and call shared service methods. |
| Refactoring Opportunities | High | Very large class (~1600 lines) combines feed, poster, settings, and admin-like operations. | Split into `FeedShortcode`, `PosterShortcode`, and `SettingsShortcode` classes. |
| Modernization | Med | Complex UI is assembled as large inline HTML strings. | Move major view markup into dedicated templates/partials. |

---

## bitstream-quick-media.js

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Redundancy & Dead Code | High | File is empty and no references/enqueue usage were found. | Remove file if obsolete, or implement and enqueue intentionally. |

---

## sw.js

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Service worker cache list uses hardcoded plugin paths, fragile under renamed plugin directory/custom installations. | Build/cache URLs from localized runtime base values. |
| Refactoring Opportunities | Med | Complex fetch interception conditions and custom POST path handling increase fragility. | Isolate route handling by strategy (assets vs navigation vs share target). |
| Modernization | Med | Broad promise-chain style and mixed concerns in one file. | Adopt clearer route strategy patterns (network-first/stale-while-revalidate by route type). |

---

## manifest.json

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | Manifest paths are static/absolute and assume fixed install path and scope. | Serve dynamic manifest or ensure generated paths adapt to deployment context. |
| Modernization | Low | Static manifest limits multisite/subdirectory flexibility. | Generate manifest values dynamically via PHP endpoint if portability is needed. |

---

## README.md

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Med | Documentation claims PHP 7.4 support while codebase currently uses PHP 8-only functions. | Align requirements docs with actual runtime support or add 7.4-compatible code paths. |
| Refactoring Opportunities | Low | Changelog link casing/path reference is inconsistent with actual filename in repository. | Correct linked path to match existing file name exactly. |

---

## CHANGELOG.md

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## assets/js/bitstream.js

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Redundancy & Dead Code | High | Unreachable code remains after redirect return in publish success path. | Remove unreachable branch and keep one clear post-submit flow. |
| Redundancy & Dead Code | Med | Floating menu logic duplicates behavior emitted elsewhere (inline PHP scripts), increasing drift risk. | Keep one source of truth for floating menu behavior and remove duplicate handlers. |
| Refactoring Opportunities | High | Very large monolithic script (~3300 lines) combines poster, feed, comments, settings, media tools, and PWA UX. | Split into modular files with clear ownership and build pipeline. |
| Modernization | Med | Mixed jQuery/vanilla patterns and legacy fallback usage. | Standardize coding style and isolate compatibility fallbacks. |

---

## assets/css/bitstream.css

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Redundancy & Dead Code | Med | Contains legacy `quickbit` floating menu style blocks not matched by active markup. | Remove obsolete selector blocks and keep only active component styles. |
| Refactoring Opportunities | High | Large stylesheet (~3200 lines) with repeated selector overrides and high `!important` usage. | Split by feature domain and reduce specificity debt. |
| Modernization | Med | Many overrides exist primarily to counter inline styles generated by PHP. | Remove inline style generation and rely on class-driven styling tokens. |

---

## assets/json/fontawesome6_free.json

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## assets/images/new-bit-192.png

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## assets/images/new-rebit-192.png

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## assets/images/logo_512.png

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## assets/images/logo_192.png

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## assets/images/bitstream.svg

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |

---

## .gitignore

| Issue Type | Priority (High/Med/Low) | Description | Suggested Fix |
|---|---|---|---|
| Security & Best Practices | Low | No issues found. | No issues found. |
