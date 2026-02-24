<?php
/**
 * BitStream Shortcodes
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Shortcodes {

    /**
     * Render a compact quote preview card without interactive controls/forms
     */
    private function render_quote_preview_card($post_id) {
        $post = get_post($post_id);
        if (!($post instanceof WP_Post) || $post->post_type !== 'bit' || $post->post_status !== 'publish') {
            return '';
        }

        if (function_exists('bitstream_render_nested_quoted_card')) {
            return bitstream_render_nested_quoted_card($post_id);
        }

        return wpautop(get_post_field('post_content', $post_id));
    }

    /**
     * Resolve the frontend poster page URL
     */
    public static function get_poster_page_url($query_args = []) {
        static $cached_url = null;

        if ($cached_url === null) {
            $cached_url = '';

            $candidates = get_posts([
                'post_type' => ['page', 'post'],
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);

            foreach ($candidates as $candidate_id) {
                $content = get_post_field('post_content', $candidate_id);
                if ($content && has_shortcode($content, 'bitstream_poster')) {
                    $cached_url = get_permalink($candidate_id);
                    break;
                }
            }

            if (empty($cached_url)) {
                $cached_url = home_url('/bitstream/');
            }
        }

        if (!empty($query_args)) {
            return add_query_arg($query_args, $cached_url);
        }

        return $cached_url;
    }
    
    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_shortcode_assets']);
    }
    
    /**
     * Register all shortcodes
     */
    public function register_shortcodes() {
        add_shortcode('bitstream', [$this, 'render_feed']);
        add_shortcode('bitstream_poster', [$this, 'render_poster']);
    }

    /**
     * Enqueue assets required by shortcodes
     */
    public function enqueue_shortcode_assets() {
        if (is_admin()) {
            return;
        }

        global $post;
        if (!($post instanceof WP_Post)) {
            return;
        }

        if (has_shortcode($post->post_content, 'bitstream_poster')) {
            wp_enqueue_media();
        }
    }
    
    /**
     * Render the main BitStream feed
     */
    public function render_feed($atts) {
        $atts = shortcode_atts([
            'posts_per_page' => 10,
            'paged' => get_query_var('paged') ?: 1,
            'limit' => '', // Limit total number of posts (e.g., "3" for latest 3)
            'infinite_scroll' => 'false', // Enable infinite scroll instead of load more button
            'show_load_more' => 'true' // Control whether to show load more button
        ], $atts);
        
        // If limit is set, override posts_per_page and disable pagination
        $posts_per_page = !empty($atts['limit']) ? intval($atts['limit']) : intval($atts['posts_per_page']);
        $paged = !empty($atts['limit']) ? 1 : intval($atts['paged']);
        
        $q = new WP_Query([
            'post_type' => 'bit',
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        $max = $q->max_num_pages;
        $infinite_scroll = ($atts['infinite_scroll'] === 'true' || $atts['infinite_scroll'] === '1');
        $show_load_more = ($atts['show_load_more'] === 'true' || $atts['show_load_more'] === '1');
        $has_limit = !empty($atts['limit']);
        
        ob_start();
        if ($q->have_posts()) {
            $current_page = intval($paged);
            $feed_classes = 'bitstream-feed';
            if ($infinite_scroll) {
                $feed_classes .= ' bitstream-infinite-scroll';
            }
            
            echo '<div class="'.$feed_classes.'" data-page="'.$current_page.'" data-max-page="'.$max.'" data-infinite-scroll="'.($infinite_scroll ? 'true' : 'false').'">';
            while ($q->have_posts()) {
                $q->the_post();
                echo bitstream_render_card(get_the_ID());
            }
            echo '</div>';
            
            // Only show load more button if:
            // - There are more pages
            // - Not using limit parameter (which shows fixed number)
            // - show_load_more is true
            // - Not using infinite scroll (unless explicitly enabled)
            if ($max > 1 && !$has_limit && $show_load_more && !$infinite_scroll) {
                echo '<button id="bitstream-load-more" class="bitstream-load-more">Load More</button>';
            }
            
            // Add infinite scroll trigger if enabled
            if ($infinite_scroll && $max > 1 && !$has_limit) {
                echo '<div class="bitstream-scroll-trigger" style="height: 1px; margin-top: 20px;"></div>';
            }
        } else {
            echo '<p>No Bits found.</p>';
        }
        
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Render tabbed frontend poster (Bit/Rebit)
     */
    public function render_poster($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to post.</p>';
        }

        if (!current_user_can('edit_posts')) {
            return '<p>You do not have permission to create Bits.</p>';
        }

        $submit_nonce = wp_create_nonce('bitstream_poster_submit_nonce');
        $requested_tab = isset($_GET['poster_tab']) ? sanitize_key(wp_unslash($_GET['poster_tab'])) : 'bit';
        $initial_tab = in_array($requested_tab, ['bit', 'rebit', 'scheduled'], true) ? $requested_tab : 'bit';

        $shared_url = isset($_GET['shared_url']) ? esc_url_raw(wp_unslash($_GET['shared_url'])) : '';
        $shared_title = isset($_GET['shared_title']) ? sanitize_text_field(wp_unslash($_GET['shared_title'])) : '';
        $shared_text = isset($_GET['shared_text']) ? sanitize_textarea_field(wp_unslash($_GET['shared_text'])) : '';

        if (!empty($_GET['shared_key'])) {
            $shared_key = sanitize_text_field(wp_unslash($_GET['shared_key']));
            $shared_data = get_transient($shared_key);

            if (is_array($shared_data)) {
                delete_transient($shared_key);
                $shared_url = !empty($shared_data['url']) ? esc_url_raw($shared_data['url']) : $shared_url;
                $shared_title = !empty($shared_data['title']) ? sanitize_text_field($shared_data['title']) : $shared_title;
                $shared_text = !empty($shared_data['text']) ? sanitize_textarea_field($shared_data['text']) : $shared_text;
            }
        }
        $quote_post_id = isset($_GET['quote_post_id']) ? intval($_GET['quote_post_id']) : 0;

        if (!empty($shared_url)) {
            $initial_tab = 'rebit';
        }

        $media_id = 0;
        if (!empty($_GET['media_ids'])) {
            $media_ids_raw = sanitize_text_field(wp_unslash($_GET['media_ids']));
            $media_ids = array_filter(array_map('intval', explode(',', $media_ids_raw)));
            if (!empty($media_ids)) {
                $media_id = intval(reset($media_ids));
            }
        }

        $bit_content_prefill = '';
        $rebit_commentary_prefill = '';

        if (!empty($shared_url)) {
            if (!empty($shared_text) && $shared_text !== $shared_url) {
                $rebit_commentary_prefill = $shared_text;
            }
        } elseif (!empty($shared_text)) {
            $bit_content_prefill = $shared_text;
        }

        $is_bit_active = ($initial_tab === 'bit');
        $is_rebit_active = ($initial_tab === 'rebit');
        $is_scheduled_active = ($initial_tab === 'scheduled');

        $scheduled_query = new WP_Query([
            'post_type' => 'bit',
            'post_status' => 'future',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
            'author' => get_current_user_id(),
            'no_found_rows' => true,
        ]);

        $quote_preview = '';
        if ($quote_post_id > 0) {
            $quoted_post = get_post($quote_post_id);
            if ($quoted_post && $quoted_post->post_type === 'bit' && $quoted_post->post_status === 'publish') {
                $quote_preview = $this->render_quote_preview_card($quote_post_id);
            } else {
                $quote_post_id = 0;
            }
        }

        ob_start();
        ?>
        <section class="bitstream-poster" data-submit-nonce="<?php echo esc_attr($submit_nonce); ?>">
            <div class="bitstream-poster-tabs" role="tablist" aria-label="Create a Bit or Rebit">
                <button type="button" class="bitstream-poster-tab <?php echo $is_bit_active ? 'is-active' : ''; ?>" data-tab="bit" role="tab" aria-selected="<?php echo $is_bit_active ? 'true' : 'false'; ?>" aria-controls="bitstream-poster-panel-bit" id="bitstream-poster-tab-bit">
                    Post a Bit
                </button>
                <button type="button" class="bitstream-poster-tab <?php echo $is_rebit_active ? 'is-active' : ''; ?>" data-tab="rebit" role="tab" aria-selected="<?php echo $is_rebit_active ? 'true' : 'false'; ?>" aria-controls="bitstream-poster-panel-rebit" id="bitstream-poster-tab-rebit">
                    Post a Rebit
                </button>
                <button type="button" class="bitstream-poster-tab <?php echo $is_scheduled_active ? 'is-active' : ''; ?>" data-tab="scheduled" role="tab" aria-selected="<?php echo $is_scheduled_active ? 'true' : 'false'; ?>" aria-controls="bitstream-poster-panel-scheduled" id="bitstream-poster-tab-scheduled">
                    Scheduled
                </button>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_bit_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-bit" role="tabpanel" aria-labelledby="bitstream-poster-tab-bit" <?php echo $is_bit_active ? '' : 'hidden'; ?>>
                <form class="bitstream-poster-form" data-poster-type="bit">
                    <label for="bitstream-bit-content"><strong>Bit content</strong></label>
                    <textarea id="bitstream-bit-content" name="bit_content" rows="5" placeholder="What’s happening?"><?php echo esc_textarea($bit_content_prefill); ?></textarea>

                    <input type="hidden" name="quote_post_id" value="<?php echo esc_attr($quote_post_id); ?>">

                    <div class="bitstream-media-field">
                        <input type="hidden" id="bitstream-bit-attachment-id" name="bit_attachment_id" value="<?php echo esc_attr($media_id); ?>">
                        <div class="bitstream-media-dropzone" data-target-input="bitstream-bit-attachment-id" data-target-preview="bitstream-bit-media-preview" data-accept="image/*,video/*,audio/*">
                            <span>Drag and drop media here, or click to upload</span>
                            <div class="bitstream-media-preview" id="bitstream-bit-media-preview"></div>
                            <input type="file" class="bitstream-media-file" accept="image/*,video/*,audio/*">
                        </div>
                        <div class="bitstream-media-progress is-hidden" data-progress-bar="bitstream-bit-attachment-id">
                            <div class="bitstream-media-progress-track">
                                <div class="bitstream-media-progress-bar"></div>
                            </div>
                            <span class="bitstream-media-progress-text">Uploading...</span>
                        </div>
                        <div class="bitstream-media-controls">
                            <button type="button" class="bitstream-media-remove is-hidden" data-target-input="bitstream-bit-attachment-id" data-target-preview="bitstream-bit-media-preview">Remove media</button>
                            <a class="bitstream-media-crop is-hidden" data-target-input="bitstream-bit-attachment-id" href="#" target="_blank" rel="noopener">Crop image</a>
                            <a class="bitstream-media-audio-tags is-hidden" data-target-input="bitstream-bit-attachment-id" data-target-preview="bitstream-bit-media-preview" href="#">Edit audio tags</a>
                        </div>
                    </div>

                    <?php if (!empty($quote_preview)): ?>
                        <div class="bitstream-poster-quote-preview">
                            <p><strong>You are quoting this bit:</strong></p>
                            <?php echo $quote_preview; ?>
                        </div>
                    <?php endif; ?>

                    <details class="bitstream-post-options">
                        <summary>Schedule</summary>
                        <div class="bitstream-schedule-options">
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="bit_schedule_mode" value="now" data-schedule-toggle="bit" checked>
                                Post now
                            </label>
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="bit_schedule_mode" value="later" data-schedule-toggle="bit">
                                Schedule for later
                            </label>
                        </div>
                        <input type="datetime-local" name="bit_schedule_datetime" class="bitstream-schedule-datetime" data-schedule-input="bit" disabled>
                        <input type="hidden" name="bit_schedule_enabled" value="0" data-schedule-hidden="bit">
                    </details>

                    <button type="submit" class="bitstream-poster-submit">Publish Bit</button>
                </form>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_rebit_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-rebit" role="tabpanel" aria-labelledby="bitstream-poster-tab-rebit" <?php echo $is_rebit_active ? '' : 'hidden'; ?>>
                <form class="bitstream-poster-form" data-poster-type="rebit">
                    <label for="bitstream-rebit-url"><strong>Link URL</strong></label>
                    <div class="bitstream-rebit-url-row">
                        <input type="url" id="bitstream-rebit-url" name="rebit_url" required placeholder="https://example.com/post" value="<?php echo esc_attr($shared_url); ?>">
                        <button type="button" class="bitstream-fetch-og">Fetch metadata</button>
                    </div>

                    <label for="bitstream-rebit-commentary"><strong>Commentary</strong></label>
                    <textarea id="bitstream-rebit-commentary" name="rebit_commentary" rows="4" placeholder="Add your thoughts"><?php echo esc_textarea($rebit_commentary_prefill); ?></textarea>

                    <label for="bitstream-rebit-og-title"><strong>Preview title</strong></label>
                    <input type="text" id="bitstream-rebit-og-title" name="rebit_og_title" placeholder="Auto-filled from metadata" value="<?php echo esc_attr($shared_title); ?>">

                    <label for="bitstream-rebit-og-desc"><strong>Preview description</strong></label>
                    <textarea id="bitstream-rebit-og-desc" name="rebit_og_desc" rows="3" placeholder="Auto-filled from metadata"></textarea>

                    <div class="bitstream-media-field">
                        <input type="hidden" id="bitstream-rebit-attachment-id" name="rebit_attachment_id" value="">
                        <div class="bitstream-media-dropzone" data-target-input="bitstream-rebit-attachment-id" data-target-preview="bitstream-rebit-media-preview" data-accept="image/*">
                            <span>Drag and drop image here, or click to upload</span>
                            <div class="bitstream-media-preview" id="bitstream-rebit-media-preview"></div>
                            <input type="file" class="bitstream-media-file" accept="image/*">
                        </div>
                        <div class="bitstream-media-progress is-hidden" data-progress-bar="bitstream-rebit-attachment-id">
                            <div class="bitstream-media-progress-track">
                                <div class="bitstream-media-progress-bar"></div>
                            </div>
                            <span class="bitstream-media-progress-text">Uploading...</span>
                        </div>
                        <div class="bitstream-media-controls">
                            <button type="button" class="bitstream-media-remove is-hidden" data-target-input="bitstream-rebit-attachment-id" data-target-preview="bitstream-rebit-media-preview">Remove selected image</button>
                            <a class="bitstream-media-crop is-hidden" data-target-input="bitstream-rebit-attachment-id" href="#" target="_blank" rel="noopener">Crop image</a>
                        </div>
                    </div>

                    <div class="bitstream-rebit-preview-card" id="bitstream-rebit-og-preview" hidden>
                        <img src="" alt="" class="bitstream-rebit-preview-image">
                        <div class="bitstream-rebit-preview-content">
                            <h4 class="bitstream-rebit-preview-title"></h4>
                            <p class="bitstream-rebit-preview-description"></p>
                        </div>

                    </div>

                    <details class="bitstream-post-options">
                        <summary>Schedule</summary>
                        <div class="bitstream-schedule-options">
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="rebit_schedule_mode" value="now" data-schedule-toggle="rebit" checked>
                                Post now
                            </label>
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="rebit_schedule_mode" value="later" data-schedule-toggle="rebit">
                                Schedule for later
                            </label>
                        </div>
                        <input type="datetime-local" name="rebit_schedule_datetime" class="bitstream-schedule-datetime" data-schedule-input="rebit" disabled>
                        <input type="hidden" name="rebit_schedule_enabled" value="0" data-schedule-hidden="rebit">
                    </details>

                    <button type="submit" class="bitstream-poster-submit">Publish Rebit</button>
                </form>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_scheduled_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-scheduled" role="tabpanel" aria-labelledby="bitstream-poster-tab-scheduled" <?php echo $is_scheduled_active ? '' : 'hidden'; ?>>
                <div class="bitstream-scheduled-filter">
                    <button type="button" class="bitstream-scheduled-filter-btn is-active" data-filter="all">All</button>
                    <button type="button" class="bitstream-scheduled-filter-btn" data-filter="bit">Bits</button>
                    <button type="button" class="bitstream-scheduled-filter-btn" data-filter="rebit">Rebits</button>
                </div>
                <div class="bitstream-scheduled-list">
                    <?php if ($scheduled_query->have_posts()): ?>
                        <?php while ($scheduled_query->have_posts()): $scheduled_query->the_post(); ?>
                            <?php
                            $scheduled_id = get_the_ID();
                            $is_rebit = !empty(get_post_meta($scheduled_id, 'bitstream_rebit_url', true));
                            $row_type = $is_rebit ? 'rebit' : 'bit';
                            ?>
                            <article class="bitstream-scheduled-item" data-type="<?php echo esc_attr($row_type); ?>">
                                <div>
                                    <strong><?php echo $is_rebit ? 'Rebit' : 'Bit'; ?></strong>
                                    <p><?php echo esc_html(wp_trim_words(get_post_field('post_content', $scheduled_id), 16)); ?></p>
                                    <small>Scheduled for <?php echo esc_html(get_the_date('Y-m-d H:i', $scheduled_id)); ?></small>
                                </div>
                                <div class="bitstream-scheduled-actions">
                                    <a href="<?php echo esc_url(get_edit_post_link($scheduled_id, '')); ?>" target="_blank" rel="noopener">Edit</a>
                                    <a href="<?php echo esc_url(get_preview_post_link($scheduled_id)); ?>" target="_blank" rel="noopener">Preview</a>
                                </div>
                            </article>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    <?php else: ?>
                        <p>No scheduled Bits or Rebits yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bitstream-poster-status" aria-live="polite"></div>

            <div class="bitstream-poster-result" hidden>
                <h3>Published preview</h3>
                <div class="bitstream-poster-result-actions">
                    <a class="bitstream-poster-action-edit" href="#" target="_blank" rel="noopener">Edit</a>
                    <button type="button" class="bitstream-poster-action-copy">Copy permalink</button>
                    <a class="bitstream-poster-action-view" href="#" target="_blank" rel="noopener">Open post</a>
                </div>
                <div class="bitstream-poster-result-card"></div>
            </div>

            <div class="bitstream-audio-tags-modal" hidden>
                <div class="bitstream-audio-tags-backdrop" data-audio-tags-close="true"></div>
                <div class="bitstream-audio-tags-dialog" role="dialog" aria-modal="true" aria-labelledby="bitstream-audio-tags-title">
                    <header class="bitstream-audio-tags-header">
                        <h3 id="bitstream-audio-tags-title">Edit audio tags</h3>
                    </header>
                    <div class="bitstream-audio-tags-body">
                        <div class="bitstream-audio-tags-artwork">
                            <img class="bitstream-audio-tags-preview" src="" alt="" hidden>
                            <div class="bitstream-audio-tags-buttons">
                                <button type="button" class="bitstream-audio-tags-select">Choose artwork</button>
                                <button type="button" class="bitstream-audio-tags-clear">Remove artwork</button>
                            </div>
                        </div>
                        <label>
                            <span>Title</span>
                            <input type="text" class="bitstream-audio-tags-input" data-audio-tags-field="title" placeholder="Track title">
                        </label>
                        <label>
                            <span>Artist</span>
                            <input type="text" class="bitstream-audio-tags-input" data-audio-tags-field="artist" placeholder="Artist name">
                        </label>
                        <label>
                            <span>Album</span>
                            <input type="text" class="bitstream-audio-tags-input" data-audio-tags-field="album" placeholder="Album name">
                        </label>
                    </div>
                    <footer class="bitstream-audio-tags-footer">
                        <button type="button" class="bitstream-audio-tags-close" data-audio-tags-close="true">Close</button>
                        <button type="button" class="bitstream-audio-tags-save">Save tags</button>
                    </footer>
                </div>
            </div>

            <div class="bitstream-cropper-modal" hidden>
                <div class="bitstream-cropper-backdrop" data-cropper-close="true"></div>
                <div class="bitstream-cropper-dialog" role="dialog" aria-modal="true" aria-label="Crop Image">
                    <div class="bitstream-cropper-header">
                        <h3>Crop Image</h3>
                    </div>
                    <div class="bitstream-cropper-body">
                        <div class="bitstream-cropper-stage">
                            <img class="bitstream-cropper-image" src="" alt="">
                            <div class="bitstream-cropper-selection" aria-hidden="true">
                                <span class="bitstream-cropper-handle handle-nw" data-handle="nw"></span>
                                <span class="bitstream-cropper-handle handle-ne" data-handle="ne"></span>
                                <span class="bitstream-cropper-handle handle-n" data-handle="n"></span>
                                <span class="bitstream-cropper-handle handle-e" data-handle="e"></span>
                                <span class="bitstream-cropper-handle handle-s" data-handle="s"></span>
                                <span class="bitstream-cropper-handle handle-w" data-handle="w"></span>
                                <span class="bitstream-cropper-handle handle-sw" data-handle="sw"></span>
                                <span class="bitstream-cropper-handle handle-se" data-handle="se"></span>
                            </div>
                        </div>
                        <p class="bitstream-cropper-help">Drag to select.</p>
                        <p class="bitstream-cropper-size" aria-live="polite">Size: --</p>
                    </div>
                    <div class="bitstream-cropper-footer">
                        <button type="button" class="bitstream-cropper-cancel" data-cropper-close="true">Cancel</button>
                        <button type="button" class="bitstream-cropper-apply">Crop &amp; Use</button>
                    </div>
                </div>
            </div>
        </section>
        <?php

        return ob_get_clean();
    }
}
