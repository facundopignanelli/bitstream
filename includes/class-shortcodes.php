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

    /**
     * Shared quick actions used by both right rail and floating menu
     */
    public static function get_quick_actions() {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return [];
        }

        return [
            [
                'url' => self::get_poster_page_url(['poster_tab' => 'bit']),
                'icon' => 'fa-solid fa-comment',
                'label' => 'Add New Bit',
            ],
            [
                'url' => self::get_poster_page_url(['poster_tab' => 'rebit']),
                'icon' => 'fa-solid fa-link',
                'label' => 'Add New ReBit',
            ],
            [
                'url' => admin_url('edit.php?post_type=bit&page=bitstream-rss-feeds'),
                'icon' => 'fa-solid fa-rss',
                'label' => 'RSS Feeds',
            ],
            [
                'url' => admin_url('edit.php?post_type=bit&page=bitstream-rebit-mappings'),
                'icon' => 'fa-solid fa-sitemap',
                'label' => 'ReBit Mappings',
            ],
        ];
    }

    /**
     * Render quick action links with a shared source of options
     */
    public static function render_quick_action_links($link_class = 'bitstream-filter-link') {
        $actions = self::get_quick_actions();
        if (empty($actions)) {
            return '';
        }

        $html = '';
        foreach ($actions as $action) {
            $html .= '<a class="'.esc_attr(trim($link_class.' bitstream-quick-action-link')).'" href="'.esc_url($action['url']).'">';
            $html .= '<i class="'.esc_attr($action['icon']).'" aria-hidden="true"></i>';
            $html .= '<span>'.esc_html($action['label']).'</span>';
            $html .= '</a>';
        }

        return $html;
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

        $requested_type = isset($_GET['bitstream_type']) ? sanitize_key(wp_unslash($_GET['bitstream_type'])) : 'all';
        $selected_type = in_array($requested_type, ['all', 'bits', 'rebits'], true) ? $requested_type : 'all';

        $requested_month = isset($_GET['bitstream_month']) ? sanitize_text_field(wp_unslash($_GET['bitstream_month'])) : '';
        $selected_month = preg_match('/^\d{4}-\d{2}$/', $requested_month) ? $requested_month : '';

        $requested_search = isset($_GET['bitstream_search']) ? sanitize_text_field(wp_unslash($_GET['bitstream_search'])) : '';
        $selected_search = trim($requested_search);

        $intro_title = get_option('bitstream_feed_intro_title', 'About BitStream');
        $intro_text = get_option('bitstream_feed_intro_text', 'BitStream is a lightweight microblog where you can post Bits, share Rebits, and follow updates in one place.');

        // If limit is set, override posts_per_page and disable pagination
        $posts_per_page = !empty($atts['limit']) ? intval($atts['limit']) : intval($atts['posts_per_page']);
        $paged = !empty($atts['limit']) ? 1 : intval($atts['paged']);

        $query_args = [
            'post_type' => 'bit',
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if ($selected_type === 'bits') {
            $query_args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'bitstream_rebit_url',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => 'bitstream_rebit_url',
                    'value' => '',
                    'compare' => '=',
                ],
            ];
        } elseif ($selected_type === 'rebits') {
            $query_args['meta_query'] = [
                [
                    'key' => 'bitstream_rebit_url',
                    'value' => '',
                    'compare' => '!=',
                ],
            ];
        }

        if (!empty($selected_month)) {
            [$year, $month] = explode('-', $selected_month);
            $query_args['date_query'] = [[
                'year' => intval($year),
                'monthnum' => intval($month),
            ]];
        }

        if (!empty($selected_search)) {
            $query_args['s'] = $selected_search;
        }

        $q = new WP_Query($query_args);
        
        $max = $q->max_num_pages;
        $infinite_scroll = ($atts['infinite_scroll'] === 'true' || $atts['infinite_scroll'] === '1');
        $show_load_more = ($atts['show_load_more'] === 'true' || $atts['show_load_more'] === '1');
        $has_limit = !empty($atts['limit']);

        global $wpdb;
        $archive_rows = [];
        if (is_object($wpdb) && isset($wpdb->posts) && method_exists($wpdb, 'get_results')) {
            $posts_table = (string) $wpdb->posts;
            $archive_rows = $wpdb->get_results(
                "SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(ID) AS c
                 FROM {$posts_table}
                 WHERE post_type = 'bit' AND post_status = 'publish'
                 GROUP BY YEAR(post_date), MONTH(post_date)
                 ORDER BY post_date DESC
                 LIMIT 18"
            );
        }

        $base_filter_url = remove_query_arg(['bitstream_type', 'bitstream_month', 'bitstream_search', 'paged']);
        $build_filter_url = static function($base_url, $type, $month, $search) {
            $params = [];
            if (!empty($type) && $type !== 'all') {
                $params['bitstream_type'] = $type;
            }
            if (!empty($month)) {
                $params['bitstream_month'] = $month;
            }
            if (!empty($search)) {
                $params['bitstream_search'] = $search;
            }
            return empty($params) ? $base_url : add_query_arg($params, $base_url);
        };
        
        ob_start();
        $current_page = intval($paged);
        $feed_classes = 'bitstream-feed';
        if ($infinite_scroll) {
            $feed_classes .= ' bitstream-infinite-scroll';
        }

        $has_active_filters = ($selected_type !== 'all') || !empty($selected_month) || !empty($selected_search);
        $selected_month_label = '';
        if (!empty($selected_month)) {
            [$selected_year, $selected_month_num] = explode('-', $selected_month);
            $selected_month_label = date_i18n('F Y', mktime(0, 0, 0, intval($selected_month_num), 1, intval($selected_year)));
        }

        echo '<div class="bitstream-feed-layout">';

        echo '<div class="bitstream-feed-sidebar-column">';

        echo '<aside class="bitstream-feed-sidebar bitstream-feed-sidebar-intro">';
        echo '<div class="bitstream-filter-box bitstream-intro-box">';
        echo '<h3 class="bitstream-feed-sidebar-title">'.esc_html($intro_title).'</h3>';
        echo '<p class="bitstream-intro-text">'.nl2br(esc_html($intro_text)).'</p>';
        echo '</div>';
        echo '</aside>';

        echo '<aside class="bitstream-feed-sidebar bitstream-feed-sidebar-left">';
        echo '<div class="bitstream-feed-sidebar-tabs">';

        echo '<details class="bitstream-feed-sidebar-panel bitstream-feed-sidebar-panel-search" open>';
        echo '<summary class="bitstream-feed-sidebar-summary"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</summary>';
        echo '<div class="bitstream-filter-box">';
        echo '<h3 class="bitstream-feed-sidebar-title">Search</h3>';
        echo '<form class="bitstream-filter-search" method="get" action="'.esc_url($base_filter_url).'">';
        if ($selected_type !== 'all') {
            echo '<input type="hidden" name="bitstream_type" value="'.esc_attr($selected_type).'">';
        }
        if (!empty($selected_month)) {
            echo '<input type="hidden" name="bitstream_month" value="'.esc_attr($selected_month).'">';
        }
        echo '<input type="search" name="bitstream_search" value="'.esc_attr($selected_search).'" placeholder="Search posts...">';
        echo '<button type="submit">Search</button>';
        echo '</form>';
        echo '</div>';
        echo '</details>';

        echo '<details class="bitstream-feed-sidebar-panel bitstream-feed-sidebar-panel-filters" open>';
        echo '<summary class="bitstream-feed-sidebar-summary"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters</summary>';
        echo '<div class="bitstream-filter-box">';
        echo '<h3 class="bitstream-feed-sidebar-title">Archive</h3>';
        echo '<a class="bitstream-filter-link '.(empty($selected_month) ? 'is-active' : '').'" href="'.esc_url($build_filter_url($base_filter_url, $selected_type, '', $selected_search)).'">All dates</a>';
        if (!empty($archive_rows)) {
            $archive_by_year = [];
            foreach ($archive_rows as $row) {
                $year = intval($row->y);
                if (!isset($archive_by_year[$year])) {
                    $archive_by_year[$year] = [
                        'total' => 0,
                        'months' => [],
                    ];
                }

                $archive_by_year[$year]['total'] += intval($row->c);
                $archive_by_year[$year]['months'][] = [
                    'm' => intval($row->m),
                    'c' => intval($row->c),
                ];
            }

            $selected_year = !empty($selected_month) ? substr($selected_month, 0, 4) : '';
            $year_index = 0;

            foreach ($archive_by_year as $year => $year_data) {
                $is_open = ($selected_year === strval($year)) || (empty($selected_year) && $year_index === 0);
                echo '<details class="bitstream-archive-year"'.($is_open ? ' data-default-open="1"' : '').'>';
                echo '<summary class="bitstream-archive-year-summary">';
                echo '<span class="bitstream-archive-year-label">'.esc_html(strval($year)).'</span>';
                echo '<span class="bitstream-archive-year-count">('.intval($year_data['total']).')</span>';
                echo '</summary>';
                echo '<div class="bitstream-archive-months">';

                foreach ($year_data['months'] as $month_data) {
                    $month_value = sprintf('%04d-%02d', intval($year), intval($month_data['m']));
                    $is_active = ($selected_month === $month_value) ? ' is-active' : '';
                    $label = date_i18n('F', mktime(0, 0, 0, intval($month_data['m']), 1, intval($year)));
                    echo '<a class="bitstream-filter-link'.$is_active.'" href="'.esc_url($build_filter_url($base_filter_url, $selected_type, $month_value, $selected_search)).'">';
                    echo '<strong class="bitstream-archive-label">'.esc_html($label).'</strong> <span class="bitstream-archive-count">('.intval($month_data['c']).')</span>';
                    echo '</a>';
                }

                echo '</div>';
                echo '</details>';
                $year_index++;
            }

        }
        echo '</div>';

        echo '<div class="bitstream-filter-box">';
        echo '<h3 class="bitstream-feed-sidebar-title">Content</h3>';
        echo '<a class="bitstream-filter-link '.($selected_type === 'all' ? 'is-active' : '').'" href="'.esc_url($build_filter_url($base_filter_url, 'all', $selected_month, $selected_search)).'">All</a>';
        echo '<a class="bitstream-filter-link '.($selected_type === 'bits' ? 'is-active' : '').'" href="'.esc_url($build_filter_url($base_filter_url, 'bits', $selected_month, $selected_search)).'">Bits</a>';
        echo '<a class="bitstream-filter-link '.($selected_type === 'rebits' ? 'is-active' : '').'" href="'.esc_url($build_filter_url($base_filter_url, 'rebits', $selected_month, $selected_search)).'">Rebits</a>';
        echo '</div>';
        echo '</details>';

        echo '</div>';
        echo '</aside>';

        echo '</div>';

        echo '<main class="bitstream-feed-main">';
        if ($has_active_filters) {
            echo '<div class="bitstream-active-filters" aria-label="Active filters">';
            if ($selected_type !== 'all') {
                $type_label = ($selected_type === 'rebits') ? 'Rebits' : 'Bits';
                echo '<span class="bitstream-filter-chip">'.esc_html($type_label).'</span>';
            }
            if (!empty($selected_month_label)) {
                echo '<span class="bitstream-filter-chip">'.esc_html($selected_month_label).'</span>';
            }
            if (!empty($selected_search)) {
                echo '<span class="bitstream-filter-chip">Search: '.esc_html($selected_search).'</span>';
            }
            echo '<a class="bitstream-filter-chip bitstream-filter-chip-clear" href="'.esc_url($base_filter_url).'">Clear all</a>';
            echo '</div>';
        }

        if ($q->have_posts()) {
            echo '<div class="'.$feed_classes.'" data-page="'.$current_page.'" data-max-page="'.$max.'" data-infinite-scroll="'.($infinite_scroll ? 'true' : 'false').'" data-filter-type="'.esc_attr($selected_type).'" data-filter-month="'.esc_attr($selected_month).'" data-filter-search="'.esc_attr($selected_search).'">';
            while ($q->have_posts()) {
                $q->the_post();
                echo bitstream_render_card(get_the_ID());
            }
            echo '</div>';

            if ($max > 1 && !$has_limit && $show_load_more && !$infinite_scroll) {
                echo '<button id="bitstream-load-more" class="bitstream-load-more">Load More</button>';
            }

            if ($infinite_scroll && $max > 1 && !$has_limit) {
                echo '<div class="bitstream-scroll-trigger" style="height: 1px; margin-top: 20px;"></div>';
            }
        } else {
            echo '<p>No Bits found for this filter.</p>';
        }
        echo '</main>';

        echo '<aside class="bitstream-feed-sidebar bitstream-feed-sidebar-right">';
        $desktop_quick_actions = self::render_quick_action_links();
        if (!empty($desktop_quick_actions)) {
            echo '<div class="bitstream-filter-box">';
            echo '<h3 class="bitstream-feed-sidebar-title">Quick Actions</h3>';
            echo $desktop_quick_actions;
            echo '</div>';
        } else {
            echo '<div class="bitstream-right-rail-reserved" aria-hidden="true"></div>';
        }
        echo '</aside>';
        echo '</div>';
        
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
        if ($requested_tab === 'advanced') {
            $requested_tab = 'scheduled';
        }

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
                            <i class="fa-solid fa-photo-film bitstream-media-dropzone-icon" aria-hidden="true"></i>
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
                        <summary>Advanced</summary>
                        <div class="bitstream-post-options-section">
                            <h4 class="bitstream-post-options-section-title">Schedule</h4>
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
                        </div>
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
                            <i class="fa-solid fa-photo-film bitstream-media-dropzone-icon" aria-hidden="true"></i>
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
                        <summary>Advanced</summary>
                        <div class="bitstream-post-options-section">
                            <h4 class="bitstream-post-options-section-title">Schedule</h4>
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
                        </div>
                    </details>

                    <button type="submit" class="bitstream-poster-submit">Publish Rebit</button>
                </form>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_scheduled_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-scheduled" role="tabpanel" aria-labelledby="bitstream-poster-tab-scheduled" <?php echo $is_scheduled_active ? '' : 'hidden'; ?>>
                <section class="bitstream-advanced-section bitstream-advanced-section-schedule">
                    <h3>Schedule</h3>
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
                </section>
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
