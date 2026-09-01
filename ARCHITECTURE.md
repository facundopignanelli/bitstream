# BitStream Architecture Index & Detailed File Directory

This index serves as the primary map for understanding the backend and frontend architecture of the BitStream WordPress plugin. It outlines how components interact and provides a detailed file-by-file breakdown to assist in targeting modifications.

---

## 1. Core Entry Point

### [bitstream.php](bitstream.php)
* **Description**: The primary entry file for the BitStream plugin. It handles plugin bootstrapping, constants definition, global assets registration, and core helper functions for rendering posts.
* **Component Interactions**: Loads all required files from the `includes/` directory and instantiates their classes within `BitStream_Plugin`.
* **Classes & Functions**:
  * `BitStream_Plugin`: Core class handling lifecycle and boot.
    * `__construct()`: Hooks into `wp_enqueue_scripts`, `admin_enqueue_scripts`, and `plugins_loaded`.
    * `init()`: Runs initialization steps, loads files via `load_includes()`, and instantiates components via `init_components()`.
    * `strip_image_metadata_on_upload($metadata, $attachment_id)`: Intercepts attachment uploads to strip image metadata (EXIF/GPS) from full images and generated sub-sizes.
    * `strip_metadata_from_file($file_path)`: Uses `Imagick` (preferred) or `GD` (fallback) to clean metadata from JPEGs, PNGs, and WebPs.
  * `bitstream_render_rebit_section($post_id)`: Delegation wrapper calling `BitStream_Content_Display::render_rebit_section($post_id)`.
  * `bitstream_render_nested_quoted_card($post_id, $depth)`: Delegation wrapper calling `BitStream_Content_Display::render_nested_quoted_card($post_id, $depth)`.
  * `bitstream_comment_callback($comment, $args, $depth)`: Delegation wrapper calling `BitStream_Content_Display::comment_callback($comment, $args, $depth)`.
  * `bitstream_render_card($post_id, $skip_content_filter, $options)`: Delegation wrapper calling `BitStream_Content_Display::render_card($post_id, $skip_content_filter, $options)`.
  * `bitstream_plugin_activate()`: Triggered on plugin activation; flushes rewrite rules, maps default domains, and schedules crons.
  * `bitstream_plugin_deactivate()`: Triggered on deactivation; clears cron schedules and flushes rewrite rules.
  * `bitstream_schedule_weekly_media_cleanup()`: Registers the weekly cleanup hook.
* **Registered Hooks**:
  * Action: `wp_enqueue_scripts` -> `register_global_assets` (priority 5)
  * Action: `admin_enqueue_scripts` -> `register_global_assets` (priority 5)
  * Filter: `wp_generate_attachment_metadata` -> `strip_image_metadata_on_upload`

---

## 2. Backend Includes & Components ([includes/](includes/))

### [includes/class-post-type.php](includes/class-post-type.php)
* **Description**: Establishes the core data structure of BitStream. Registers the custom post type and custom taxonomies, and modifies search queries.
* **Component Interactions**: Provides the data base for `class-shortcodes.php` and `class-ajax-handlers.php`.
* **Classes & Functions**:
  * `BitStream_Post_Type`: Class managing the 'bit' post type.
    * `__construct()`: Hooks into custom post registration, saving actions, comments default state, and search queries.
    * `register_post_type()`: Defines the labels, options, and REST attributes for the `bit` post type.
    * `auto_generate_title($post_id)`: Automatically numbers posts daily (e.g., `Bit #2026-07-18:001`) on publication and manages transient counters.
    * `enable_comments($data, $postarr)`: Hook to open comments by default on newly created posts.
    * `is_bit_search_query($query)`: Helper to identify active search actions on the 'bit' custom post type.
    * `search_join($join, $query)`: Joins the `postmeta` database table to search queries.
    * `search_posts($search, $query)`: Modifies the search SQL condition to matching metadata like OG title, OG description, ReBit URL, and active moods.
    * `search_distinct($distinct, $query)`: Enforces SQL `DISTINCT` queries to prevent duplicate card results.
* **Registered Hooks**:
  * Action: `init` -> `register_post_type`
  * Action: `save_post` -> `auto_generate_title`
  * Filter: `wp_insert_post_data` -> `enable_comments`
  * Filter: `posts_join` -> `search_join`
  * Filter: `posts_search` -> `search_posts`
  * Filter: `posts_distinct` -> `search_distinct`

### [includes/class-ajax-handlers.php](includes/class-ajax-handlers.php)
* **Description**: The gateway and callback router for all asynchronous frontend interactions. 
* **Component Interactions**: Handled by [assets/js/bitstream.js](assets/js/bitstream.js) request payloads. Uses classes like `BitStream_OG_Fetcher` to retrieve metadata and `BitStream_Content_Display` to count taxonomy tags.
* **Classes & Functions**:
  * `BitStream_Ajax_Handlers`: Primary AJAX router.
    * `__construct()`: Maps the AJAX hooks to class methods.
    * `get_localized_data()`: Generates localized parameters (AJAX URLs, nonces, settings, active moods) for outputting to frontend scripts.
    * `sanitize_live_preview_markup($markup)`: Strips recursive elements (like comments and forms) to ensure safe live card pre-rendering inside the composer.
    * `assign_attachments_to_bit($post_id, $attachment_ids)`: Connects uploaded media IDs to parent posts for ownership and cleanup tracking.
    * `cleanup_bit_attachments($post_id)`: Triggers automatic file cleaning on post deletion.
    * `create_media_attachment_from_upload($file_path, $file_url)`: Processes and indexes uploaded image/video payloads inside the media library.
    * `handle_save_share_image()`: Receives client-side canvas-drawn cards, stores them as PNGs in `/uploads/bitstream-shares/`, and links them via post meta.
    * `handle_upload_media()` / `handle_upload_media_chunk()`: Accepts chunked multi-part files for large file uploading.
    * `handle_crop_media()`: Crops images based on client coordinates using GD or Imagick.
    * `get_best_preview_url($attachment_id, $fallback_url)`: Resolves highest-quality browser-safe preview image URL for media attachments.
    * `assemble_post_content($content, $attachment_ids)`: Appends media gallery markup to post content.
    * `persist_common_metadata($post_id, $author_id, $attachment_ids, $mood_emoji, $mood_emotion)`: Handles attachment ownership, mood meta, and post-count cache invalidation.
    * `build_composer_response($post_id, $author_id, $is_update, $save_as_draft, $is_auto_draft, $schedule, $item_label, $extra)`: Formats and emits unified composer JSON success response.
    * `handle_submit_composer()`: Validates and saves incoming post submissions as published, scheduled, or drafts.
    * `handle_like()`: Validates like/unlike requests and manages dual voter tracking (`_bitstream_liked_by` postmeta storing user IDs for logged-in users and IP hashes for anonymous visitors) to prevent duplicate vote inflation.
    * `handle_delete_post()`: Clientside triggered post trashing.
    * `handle_load_more()`: Renders paginated masonry cards.
    * `handle_fetch_og_data()`: Scrapes link meta tags.
    * `handle_render_rebit_preview()`: Formats draft links metadata previews.
    * `handle_get_quoted_bit()`: Returns cards details for quote embeds.
    * `handle_get_draft_data()` / `handle_get_post_edit_data()`: Resolves data fields for editing drafts or post updates.
    * `handle_save_custom_moods()`: Persists user-defined emotion overrides.
* **Registered Hooks**:
  * AJAX Actions: `wp_ajax_bitstream_like`, `wp_ajax_nopriv_bitstream_like`, `wp_ajax_bitstream_load_more`, `wp_ajax_nopriv_bitstream_load_more`, `wp_ajax_bitstream_fetch_og_data`, `wp_ajax_bitstream_render_rebit_preview`, `wp_ajax_bitstream_get_quoted_bit`, `wp_ajax_bitstream_submit_composer`, `wp_ajax_bitstream_upload_media`, `wp_ajax_bitstream_upload_media_chunk`, `wp_ajax_bitstream_prepare_rebit_image_for_crop`, `wp_ajax_bitstream_crop_media`, `wp_ajax_bitstream_delete_post`, `wp_ajax_bitstream_get_draft_data`, `wp_ajax_bitstream_get_post_data`, `wp_ajax_bitstream_get_post_edit_data`, `wp_ajax_bitstream_get_quote_preview`, `wp_ajax_bitstream_get_attachment_data`, `wp_ajax_bitstream_save_share_image`, `wp_ajax_bitstream_save_custom_moods`.
  * Action: `before_delete_post` -> `handle_before_delete_post`
  * Action: `post_updated` -> `handle_post_updated`

### [includes/class-shortcodes.php](includes/class-shortcodes.php)
* **Description**: Houses frontend HTML construction and registers the main timeline shortcodes.
* **Component Interactions**: Integrates with [assets/js/bitstream.js](assets/js/bitstream.js) and [assets/css/bitstream.css](assets/css/bitstream.css) for client rendering.
* **Classes & Functions**:
  * `BitStream_Shortcodes`: Core layout compiler.
    * `__construct()`: Registers frontend assets, footer forms, mobile layout filters, and cash flush triggers.
    * `register_shortcodes()`: Registers `[bitstream]` and `[bitstream_settings]`.
    * `enqueue_shortcode_assets()`: Loads `comment-reply` and native WP media frames.
    * `render_feed($atts)`: Outputs the complete microblogging timeline layout (filters, search, composer modals, scheduled drawers, profiles, and hashtag widgets).
    * `render_settings($atts)`: Emits settings interface panels (personalization, custom moods, domain mappings, RSS, notifications, advanced options).
    * `render_settings_moods()`: Renders the layout and controls for custom mood sorting, deletion, and additions inside settings.
    * `render_timeline_edit_modal()`: Deprecated helper (editing and quoting now execute within the unified main composer).
    * `get_primary_attachment_id($post_id)`: Resolves thumbnail images or associated attachment paths.
    * `get_editable_text_content($content)`: Cleans posts from shortcode/html tags for editing inside textareas.
    * `hide_mobile_admin_bar($show)`: Hides standard administrative bars on mobile timeline layouts.
* **Registered Hooks**:
  * Action: `wp_enqueue_scripts` -> `enqueue_shortcode_assets`
  * Filter: `show_admin_bar` -> `hide_mobile_admin_bar`
  * Action: `clean_post_cache` -> `clear_feed_page_url_cache`
  * Action: `transition_post_status` -> `flush_user_post_counts_on_transition`
  * Action: `before_delete_post` -> `flush_user_post_counts_on_delete`

### [includes/class-og-fetcher.php](includes/class-og-fetcher.php)
* **Description**: Specialized parser to scrape URL headers for indexing ReBit bookmarks.
* **Component Interactions**: Used by `class-ajax-handlers.php` inside the OG fetching endpoint.
* **Classes & Functions**:
  * `BitStream_OG_Fetcher`: Metadata fetch utility.
    * `fetch_og_data($url)`: Evaluates targets, queries transients, performs oEmbed queries, or makes SSRF-protected HTTP gets to extract title/image/descriptions.
    * `fetch_twitter_oembed($url)`: Connects to custom fallback pipelines for Twitter/X URLs.
    * `fetch_instagram_data($url)`: Parses Instagram posts, reels, and stories for authors, media, and captions.

### [includes/class-error-logger.php](includes/class-error-logger.php)
* **Description**: Logs system bugs and serves the Debug Log admin page.
* **Classes & Functions**:
  * `BitStream_Error_Logger`:
    * `log($message, $level)`: Formats and stores logging messages in the options table (caps total rows at 50) and error log.
    * `add_debug_menu()`: Inserts administrative debug submenu.
    * `debug_page()`: Renders interactive logs inspector interface.
* **Registered Hooks**:
  * Action: `wp_ajax_bitstream_clear_logs` -> `clear_logs`

### [includes/class-admin-interface.php](includes/class-admin-interface.php)
* **Description**: Oversees WordPress backend administration pages, redirects, metaboxes, and weekly cron operations.
* **Classes & Functions**:
  * `BitStream_Admin_Interface`:
    * `__construct()`: Maps admin actions, notice flags, cron registrations, and post save handles.
    * `register_cron_schedules($schedules)`: Registers a custom weekly cron interval.
    * `redirect_new_bit_creation()`: Intercepts standard post editors to forward authors to frontend composers.
    * `add_admin_menus()` / `remove_default_add_new()`: Manages custom admin sidebar layouts.
    * `run_bitstream_media_cleanup($perform_delete)`: Runs sweeps to delete unattached media items uploaded during draft edits.
    * `rebit_mappings_page()`: Saves user configurations and includes [includes/admin-rebit-mappings-interface.php](includes/admin-rebit-mappings-interface.php).
    * `save_quoted_meta($post_id)`: Saves quoted bit references when modifying bits inside backend interfaces.
* **Registered Hooks**:
  * Action: `admin_menu` -> `add_admin_menus`
  * Action: `admin_menu` -> `remove_default_add_new` (priority 99)
  * Action: `admin_notices` -> `permalink_admin_notice`
  * Action: `wp_ajax_bitstream_flush_permalinks` -> `flush_permalinks_ajax`
  * Filter: `post_row_actions` -> `add_quote_action`
  * Action: `edit_form_after_title` -> `show_quoted_preview`
  * Action: `save_post_bit` -> `save_quoted_meta`
  * Action: `admin_init` -> `redirect_new_bit_creation`
  * Filter: `cron_schedules` -> `register_cron_schedules`
  * Action: `init` -> `ensure_weekly_media_cleanup_scheduled`
  * Action: `bitstream_weekly_media_cleanup_event` -> `run_weekly_media_cleanup`

### [includes/class-block-editor.php](includes/class-block-editor.php)
* **Description**: Gutenberg integrations. Registers meta fields and editor hooks.
* **Classes & Functions**:
  * `BitStream_Block_Editor`:
    * `register_meta_and_block()`: Exposes `bitstream_rebit_url` meta parameters to REST channels.
    * `enqueue_block_editor_assets()`: Registers and enqueues the block editor script asset.
    * `inject_shared_url_script()`: Injects support variables for bookmark sharing operations.
* **Registered Hooks**:
  * Action: `init` -> `register_meta_and_block`
  * Action: `enqueue_block_editor_assets` -> `enqueue_block_editor_assets`
  * Action: `wp_enqueue_scripts` -> `enqueue_frontend_assets`
  * Filter: `default_content` -> `default_rebit_content`
  * Action: `add_meta_boxes` -> `handle_shared_content_meta`
  * Action: `edit_form_after_title` -> `inject_shared_url_script`
  * Action: `admin_init` -> `handle_shared_key_restoration`

### [includes/class-pwa-manager.php](includes/class-pwa-manager.php)
* **Description**: Oversees dynamic PWA generation, Web Share Target processing, and Web Push notifications.
* **Component Interactions**: Intercepts queries to render [manifest.json](manifest.json) or [sw.js](sw.js).
* **Classes & Functions**:
  * `BitStream_PWA_Manager`:
    * `pwa_assets()`: Prints manifest URLs, apple touch icons, theme colors, and registers service workers in headers.
    * `serve_service_worker()` / `serve_manifest()`: Renders files on query matches.
    * `handle_shortcut_requests()`: Intercepts shortcut paths to load the composer.
    * `handle_media_share()` / `handle_single_file_upload()`: Processes Web Share Target upload items.
    * `generate_vapid_keys()` / `get_vapid_keys()`: Encryption key utilities.
    * `handle_save_push_subscription()`: Registers active client subscription endpoints.
    * `on_post_status_transition($new_status, $old_status, $post)`: Fires a cron dispatch event on bit publication.
    * `maybe_flush_rewrites()`: Dynamic, version-independent helper to flush rewrite rules upon plugin updates and prune deprecated version flags from options.
    * `send_push_notifications_cron($post_id)`: Background worker compiling payloads and dispatching notifications.
* **Registered Hooks**:
  * Action: `wp_head` -> `pwa_assets`
  * Action: `init` -> `add_service_worker_rewrite`
  * Action: `init` -> `add_shortcut_rewrite`
  * Action: `template_redirect` -> `serve_service_worker`
  * Action: `template_redirect` -> `serve_manifest`
  * Action: `template_redirect` -> `handle_shortcut_requests`
  * Filter: `query_vars` -> `add_query_vars`
  * Action: `template_redirect` -> `handle_debug_requests`
  * AJAX: `wp_ajax_bitstream_save_push_subscription`, `wp_ajax_nopriv_bitstream_save_push_subscription`, `wp_ajax_bitstream_get_latest_notification`, `wp_ajax_nopriv_bitstream_get_latest_notification`
  * Action: `transition_post_status` -> `on_post_status_transition`
  * Action: `bitstream_send_push_notifications_job` -> `send_push_notifications_cron`

### [includes/class-rebit-mappings.php](includes/class-rebit-mappings.php)
* **Description**: Central configuration lookup registry mapping shared bookmark domains.
* **Classes & Functions**:
  * `BitStream_ReBit_Mappings`:
    * `get_rebit_presets()`: Supplies predefined domain structures (e.g. YouTube, Twitter, GitHub) with designated icons and labels.
    * `get_mapping_for_domain($domain)`: Finds saved overrides matching current hosts.
    * `import_default_mappings()`: Activator handler writing configuration presets.

### [includes/class-rss-feeds.php](includes/class-rss-feeds.php)
* **Description**: Generates syndications of microblogging posts.
* **Classes & Functions**:
  * `BitStream_RSS_Feeds`:
    * `add_rss_feeds()`: Establishes regex rewrites linking feeds.
    * `add_rss_links()`: Appends RSS links to headers on timeline pages.
    * `generate_rss_feed($type)`: Renders custom XML content based on queries (Bits, ReBits, all, emotions).
* **Registered Hooks**:
  * Action: `init` -> `add_rss_feeds`
  * Action: `wp_head` -> `add_rss_links`
  * Filter: `query_vars` -> `add_query_vars`

### [includes/class-content-display.php](includes/class-content-display.php)
* **Description**: Resolves custom template routing and processes post contents before display.
* **Classes & Functions**:
  * `BitStream_Content_Display`:
    * `handle_single_bit_display()`: Hooks timeline assets onto pages rendering single posts.
    * `refresh_media_cache_busters($content)`: Updates cache busters on embedded images.
    * `linkify_hashtags($content)`: Scans contents to hyperlink terms.
    * `get_hashtag_counts()` / `get_emotion_counts()`: Generates list maps for widgets.
    * `flush_hashtag_cache($post_id)`: Clears widgets transient records on modifications.
    * `is_attachment_used($attachment_id, $exclude_post_ids)`: Checks if an attachment is still referenced by parent relations, metadata, or post content inline tags.
    * `render_card($post_id, $skip_content_filter, $options)`: Primary generator of timeline card HTML.
    * `render_nested_quoted_card($post_id, $depth)`: Renders recursively quoted posts (up to depth of 1) with specific display constraints.
    * `render_rebit_section($post_id)`: Renders shared links or video embeds (e.g. YouTube frames or mapped oEmbed structures).
    * `comment_callback($comment, $args, $depth)`: Custom walker callback for modern, semantic comment layout rendering on frontend posts.
* **Registered Hooks**:
  * Action: `template_redirect` -> `handle_single_bit_display`
  * Filter: `the_content` -> `display_quoted_content`
  * Filter: `the_content` -> `linkify_hashtags` (priority 20)
  * Filter: `the_content` -> `refresh_media_cache_busters` (priority 30)
  * Action: `save_post_bit` -> `save_rebit_og_data`

### [includes/admin-rebit-mappings-interface.php](includes/admin-rebit-mappings-interface.php)
* **Description**: Admin template script included by `rebit_mappings_page()`. Renders configuration forms, icon layouts, domain options, presets, and picker scripts in backend settings screens.

---

## 3. Progressive Web App (PWA) Root Files

### [manifest.json](manifest.json)
* **Description**: Web App Manifest defining standalone shell parameters.
* **Configuration Details**:
  * Root path: `/bitstream/?pwa=1`.
  * Categories: `social`, `news`.
  * Shortcuts: Defines fast targets for `/bitstream/new-bit/` and `/bitstream/new-rebit/`.
  * Share Target: Handles POST file inputs from standard system share panels.

### [sw.js](sw.js)
* **Description**: Service worker tracking lifecycle caching.
* **Configuration Details**:
  * Cache Registry: `ASSETS_TO_CACHE` targets primary stylesheet, scripts, manifests, and system logos.
  * Interceptor: Hijacks `/new-bit/` and `/new-rebit/` POST requests to parse incoming files and output upload progress pages.
  * Notifications: Handles Web Push notification messages and click routing.

---

## 4. Frontend Assets ([assets/](assets/))

### [assets/css/bitstream.css](assets/css/bitstream.css)
* **Description**: Primary layout stylesheet.
* **Styling Systems**: Contains dark mode overrides, custom variables, modal layouts, and fully consolidated card layout rules (all inline `style="..."` attributes extracted from card, quote, ReBit, single-bit, and mood templates into structured CSS classes).
* **Metaphors & Keyframes**:
  * Mobile Viewport slide-ups (`bitstreamMobileModalIn`).
  * Spring overlay center modals (`bitstreamModalIn`).
  * Backdrop fade effects (`bitstreamFadeIn`).
* **Z-Index Layer Ladder**:
  * Lightbox: `999 999`
  * Media Cropper: `100 010`
  * Mobile Bottom Nav: `100 005`
  * Sub-modal: `100 001`
  * Composer Screen: `100 000`
  * Edit Modal: `99 990`
  * Sheets / Search Screens: `99 997` / `99 996`

### [assets/js/bitstream.js](assets/js/bitstream.js)
* **Description**: Main frontend bootstrap script. Initializes all modular components under the `window.BitStream` namespace (`window.BitStream.Media`, `window.BitStream.Timeline`, `window.BitStream.UI`, `window.BitStream.Editor`) upon DOMContentLoaded.

### [assets/js/bitstream-lightbox.js](assets/js/bitstream-lightbox.js)
* **Description**: Fullscreen media lightbox script. Handles gallery navigation, gestures, keyboard shortcuts, and video players. Exposed under `window.BitStream.UI.openLightbox`.

### [assets/js/bitstream-cropper.js](assets/js/bitstream-cropper.js)
* **Description**: Canvas-based image cropping script. Allows users to resize, position, and crop images. Exposed under `window.BitStream.UI.openCropper`.

### [assets/js/bitstream-uploader.js](assets/js/bitstream-uploader.js)
* **Description**: Multi-file and chunked uploader script. Manages file compression, progress queues, and dynamic upload forms. Exposed under `window.BitStream.Media`.

### [assets/js/bitstream-editor.js](assets/js/bitstream-editor.js)
* **Description**: Contenteditable micro-editor script. Powers live `#hashtag` coloring, `http://` URL highlighting, caret-anchored autocomplete popup, inline Twemoji rendering, DOM selection range preservation, plain-text paste sanitization, and clipboard image paste detection (`bitstream:paste-media`). Exposed under `window.BitStream.Editor`.

### [assets/js/bitstream-composer.js](assets/js/bitstream-composer.js)
* **Description**: Composer and settings controller script. Powers the modal-free inline composing architecture: inline Rebit URL expansion bar with live metadata editing, anchored popovers for Media (native device upload & WP Media Library), Scheduling (presets & custom datetime), and Moods (feelings grid & custom emotions), direct composer drag-and-drop file uploading, character counters, settings forms, PWA share payloads, drafts lists, scheduled list drawers, direct clipboard image paste handling, twemoji lazy-parsing, and unified Composer Edit & Quote Modes (`openEdit`, `openQuote`, `cancelEdit`, and auto-stashed draft protection). Exposed under `window.BitStream.Composer`.

### [assets/js/bitstream-timeline.js](assets/js/bitstream-timeline.js)
* **Description**: Timeline viewer and utilities script. Manages page scroll pagination, comments toggling/styling, media session metadata tracking, exposing hashtag data (`getHashtags()`), push notifications registration, and image download protections. Exposed under `window.BitStream.Timeline`.


### [assets/js/bitstream-block.js](assets/js/bitstream-block.js)
* **Description**: Gutenberg block editor custom integration script.
* **Core Functions**:
  * Registers custom `bitstream/rebit-url` block.
  * Automates block updates and meta sync when loaded from a PWA Share Target redirect.
  * Fetches quoted bit summaries via AJAX to display quote previews directly inside Gutenberg.
  * Manages media block creation for items enqueued from share sheets.
