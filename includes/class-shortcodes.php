<?php
/**
 * BitStream Shortcodes
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class BitStream_Shortcodes
{

    /**
     * Resolve a primary attachment id used by a Bit post.
     */
    private function get_primary_attachment_id($post_id)
    {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return 0;
        }

        $tracked_id = intval(get_post_meta($post_id, '_bitstream_attachment_id', true));
        if ($tracked_id > 0) {
            return $tracked_id;
        }

        $thumb_id = intval(get_post_thumbnail_id($post_id));
        if ($thumb_id > 0) {
            return $thumb_id;
        }

        $children = get_children([
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'numberposts' => 1,
        ]);

        if (!empty($children)) {
            return intval(reset($children));
        }

        return 0;
    }

    /**
     * Strip media markup from a Bit body for textarea editing.
     */
    private function get_editable_text_content($content)
    {
        $content = (string)$content;
        if ($content === '') {
            return '';
        }

        $content = strip_shortcodes($content);
        $content = preg_replace('#<figure[^>]*>[\s\S]*?</figure>#i', '', $content);
        $content = preg_replace('#<(audio|video)[^>]*>[\s\S]*?</\1>#i', '', $content);
        $content = preg_replace('#<img[^>]*>#i', '', $content);

        return trim(html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Render a compact quote preview card without interactive controls/forms
     */
    private function render_quote_preview_card($post_id)
    {
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
    public static function get_poster_page_url($query_args = [])
    {
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
    public static function get_quick_actions()
    {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return [];
        }

        return [
            [
                'url' => self::get_poster_page_url(['poster_tab' => 'bit']),
                'icon' => 'fa-solid fa-comment',
                'label' => 'New Bit',
            ],
            [
                'url' => admin_url('edit.php?post_type=bit&page=bitstream-settings'),
                'icon' => 'fa-solid fa-gear',
                'label' => 'Settings',
            ],
        ];
    }

    /**
     * Render quick action links with a shared source of options
     */
    public static function render_quick_action_links($link_class = 'bitstream-filter-link')
    {
        $actions = self::get_quick_actions();
        if (empty($actions)) {
            return '';
        }

        $html = '';
        foreach ($actions as $action) {
            $html .= '<a class="' . esc_attr(trim($link_class . ' bitstream-quick-action-link')) . '" href="' . esc_url($action['url']) . '">';
            $html .= '<i class="' . esc_attr($action['icon']) . '" aria-hidden="true"></i>';
            $html .= '<span>' . esc_html($action['label']) . '</span>';
            $html .= '</a>';
        }

        return $html;
    }

    /**
     * Public RSS feeds links
     */
    public static function get_public_rss_links()
    {
        return [
            [
                'url' => home_url('/bitstream/feed/'),
                'icon' => 'fa-solid fa-rss',
                'label' => 'All Feed',
            ],
            [
                'url' => home_url('/bitstream/feed/bits/'),
                'icon' => 'fa-solid fa-comment',
                'label' => 'Bits Only',
            ],
            [
                'url' => home_url('/bitstream/feed/rebits/'),
                'icon' => 'fa-solid fa-link',
                'label' => 'ReBits Only',
            ],
        ];
    }

    public static function render_public_rss_links($link_class = 'bitstream-filter-link')
    {
        $links = self::get_public_rss_links();
        $html = '';
        foreach ($links as $link) {
            $html .= '<a class="' . esc_attr(trim($link_class . ' bitstream-quick-action-link')) . '" href="' . esc_url($link['url']) . '">';
            $html .= '<i class="' . esc_attr($link['icon']) . '" aria-hidden="true"></i>';
            $html .= '<span>' . esc_html($link['label']) . '</span>';
            $html .= '</a>';
        }
        return $html;
    }

    /**
     * Render the Quick Post sidebar box
     */
    public static function render_quick_post_form()
    {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return '';
        }

        $submit_nonce = wp_create_nonce('bitstream_poster_submit_nonce');

        ob_start();
?>
        <div class="bitstream-sidebar-quick-post" style="box-sizing: border-box;">
            <h3 class="bitstream-feed-sidebar-title">Quick Bit</h3>
            <form class="bitstream-sidebar-quick-post-form" data-submit-nonce="<?php echo esc_attr($submit_nonce); ?>" style="display: flex; flex-direction: column;">
                <textarea name="bit_content" rows="3" placeholder="What's on your mind?" required class="bitstream-poster-field" style="width: 100%; box-sizing: border-box; resize: vertical; min-height: 80px;"></textarea>
                <div style="margin-top: 10px;">
                    <button type="submit" class="bitstream-poster-submit" style="width: 100%; padding: 0.4rem 0.8rem; font-size: 0.9rem;">Post Bit</button>
                </div>
                <div class="bitstream-sidebar-quick-post-status" style="margin-top: 5px; font-size: 0.85rem;" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the About Box
     */
    public static function render_about_box()
    {
        ob_start();
?>
        <div class="bitstream-about-box" style="font-size: 0.85rem; color: var(--wp--preset--color--secondary, #666); text-align: center;">
            <p style="margin: 0 0 0.5rem; font-weight: 600;">
                BitStream v<?php echo esc_html(BITSTREAM_VERSION); ?>
            </p>
            <p style="margin: 0;">
                <a href="https://github.com/facundopignanelli/bitstream" target="_blank" rel="noopener" style="color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <i class="fa-brands fa-github" aria-hidden="true" style="font-size: 1.1em;"></i> GitHub Repository
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_shortcode_assets']);
    }

    /**
     * Register all shortcodes
     */
    public function register_shortcodes()
    {
        add_shortcode('bitstream', [$this, 'render_feed']);
        add_shortcode('bitstream_poster', [$this, 'render_poster']);
        add_shortcode('bitstream_settings', [$this, 'render_settings']);
    }

    /**
     * Enqueue assets required by shortcodes
     */
    public function enqueue_shortcode_assets()
    {
        if (is_admin()) {
            return;
        }

        wp_enqueue_script('comment-reply');

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
    public function render_feed($atts)
    {
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

        $requested_hashtag = isset($_GET['bitstream_hashtag']) ? sanitize_text_field(wp_unslash($_GET['bitstream_hashtag'])) : '';
        $selected_hashtag = preg_match('/^[A-Za-z][A-Za-z0-9_\x{00C0}-\x{024F}]*$/u', $requested_hashtag) ? $requested_hashtag : '';

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
        }
        elseif ($selected_type === 'rebits') {
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

        // Hashtag content filter — applied via posts_where
        if (!empty($selected_hashtag)) {
            $hashtag_term = $selected_hashtag;
            add_filter('posts_where', $bitstream_hashtag_where = static function ($where) use ($hashtag_term) {
                global $wpdb;
                $like = '%#' . $wpdb->esc_like($hashtag_term) . '%';
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_content LIKE %s", $like);
                return $where;
            });
        }

        $q = new WP_Query($query_args);

        // Remove the hashtag where filter after query
        if (!empty($selected_hashtag) && isset($bitstream_hashtag_where)) {
            remove_filter('posts_where', $bitstream_hashtag_where);
        }

        $max = $q->max_num_pages;
        $infinite_scroll = ($atts['infinite_scroll'] === 'true' || $atts['infinite_scroll'] === '1');
        $show_load_more = ($atts['show_load_more'] === 'true' || $atts['show_load_more'] === '1');
        $has_limit = !empty($atts['limit']);

        global $wpdb;
        $archive_rows = [];
        if (is_object($wpdb) && isset($wpdb->posts) && method_exists($wpdb, 'get_results')) {
            $posts_table = (string)$wpdb->posts;
            $archive_rows = $wpdb->get_results(
                "SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(ID) AS c
                 FROM {$posts_table}
                 WHERE post_type = 'bit' AND post_status = 'publish'
                 GROUP BY YEAR(post_date), MONTH(post_date)
                 ORDER BY post_date DESC
                 LIMIT 18"
            );
        }

        $base_filter_url = remove_query_arg(['bitstream_type', 'bitstream_month', 'bitstream_search', 'bitstream_hashtag', 'paged']);
        $build_filter_url = static function ($base_url, $type, $month, $search, $hashtag = '') {
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
            if (!empty($hashtag)) {
                $params['bitstream_hashtag'] = $hashtag;
            }
            return empty($params) ? $base_url : add_query_arg($params, $base_url);
        };

        ob_start();
        $current_page = intval($paged);
        $feed_classes = 'bitstream-feed';
        if ($infinite_scroll) {
            $feed_classes .= ' bitstream-infinite-scroll';
        }

        $has_active_filters = ($selected_type !== 'all') || !empty($selected_month) || !empty($selected_search) || !empty($selected_hashtag);
        $selected_month_label = '';
        if (!empty($selected_month)) {
            [$selected_year, $selected_month_num] = explode('-', $selected_month);
            $selected_month_label = date_i18n('F Y', mktime(0, 0, 0, intval($selected_month_num), 1, intval($selected_year)));
        }

        echo '<div class="bitstream-feed-layout">';

        $desktop_quick_actions = self::render_quick_action_links();
        $desktop_rss_links = self::render_public_rss_links();
        $desktop_quick_post = self::render_quick_post_form();

        echo '<div class="bitstream-feed-sidebar-column">';

        echo '<aside class="bitstream-feed-sidebar bitstream-feed-sidebar-intro">';
        echo '<div class="bitstream-intro-box">';
        echo '<h3 class="bitstream-feed-sidebar-title">' . esc_html($intro_title) . '</h3>';
        echo '<p class="bitstream-intro-text">' . nl2br(esc_html($intro_text)) . '</p>';
        echo '</div>';
        echo '</aside>';

        echo '<div class="bitstream-feed-sidebar-left">';

        // Mobile Tab Buttons (Hidden on desktop)
        $hashtag_counts = class_exists('BitStream_Content_Display') ?BitStream_Content_Display::get_hashtag_counts() : [];
        echo '<div class="bitstream-mobile-tabs-nav hide-on-desktop">';
        echo '<button class="bitstream-feed-sidebar-summary" data-panel="search" title="Search" aria-label="Search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>';
        if (!empty($desktop_rss_links)) {
            echo '<button class="bitstream-feed-sidebar-summary" data-panel="rss" title="RSS Feeds" aria-label="RSS Feeds"><i class="fa-solid fa-rss" aria-hidden="true"></i></button>';
        }
        echo '<button class="bitstream-feed-sidebar-summary" data-panel="filters" title="Filters" aria-label="Filters"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>';
        if (!empty($hashtag_counts)) {
            echo '<button class="bitstream-feed-sidebar-summary" data-panel="hashtags" title="Hashtags" aria-label="Hashtags"><i class="fa-solid fa-hashtag" aria-hidden="true"></i></button>';
        }
        echo '</div>';

        echo '<div class="bitstream-feed-sidebar-tabs">';

        // Search Panel
        echo '<div class="bitstream-feed-sidebar-panel bitstream-feed-sidebar-panel-search" data-panel-id="search">';
        echo '<h3 class="bitstream-feed-sidebar-title hide-on-mobile">Search</h3>';
        echo '<div class="bitstream-feed-sidebar">';
        echo '<form class="bitstream-filter-search" method="get" action="' . esc_url($base_filter_url) . '">';
        if ($selected_type !== 'all') {
            echo '<input type="hidden" name="bitstream_type" value="' . esc_attr($selected_type) . '">';
        }
        if (!empty($selected_month)) {
            echo '<input type="hidden" name="bitstream_month" value="' . esc_attr($selected_month) . '">';
        }
        echo '<input type="search" name="bitstream_search" value="' . esc_attr($selected_search) . '" placeholder="Search posts...">';
        echo '<button type="submit">Search</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        // RSS Panel
        if (!empty($desktop_rss_links)) {
            echo '<div class="bitstream-feed-sidebar-panel bitstream-feed-sidebar-panel-rss mobile-rss-panel" data-panel-id="rss">';
            echo '<h3 class="bitstream-feed-sidebar-title hide-on-mobile">RSS Feeds</h3>';
            echo '<div class="bitstream-feed-sidebar">';
            echo $desktop_rss_links;
            echo '</div>';
            echo '</div>';
        }

        // Filters Panel
        echo '<div class="bitstream-feed-sidebar-panel bitstream-feed-sidebar-panel-filters" data-panel-id="filters">';
        echo '<h3 class="bitstream-feed-sidebar-title hide-on-mobile">Archive</h3>';
        echo '<div class="bitstream-feed-sidebar">';
        echo '<a class="bitstream-filter-link ' . (empty($selected_month) ? 'is-active' : '') . '" href="' . esc_url($build_filter_url($base_filter_url, $selected_type, '', $selected_search, $selected_hashtag)) . '">All dates</a>';
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
                echo '<details class="bitstream-archive-year"' . ($is_open ? ' data-default-open="1"' : '') . '>';
                echo '<summary class="bitstream-archive-year-summary">';
                echo '<span class="bitstream-archive-year-label">' . esc_html(strval($year)) . '</span>';
                echo '<span class="bitstream-archive-year-count">(' . intval($year_data['total']) . ')</span>';
                echo '</summary>';
                echo '<div class="bitstream-archive-months">';

                foreach ($year_data['months'] as $month_data) {
                    $month_value = sprintf('%04d-%02d', intval($year), intval($month_data['m']));
                    $is_active = ($selected_month === $month_value) ? ' is-active' : '';
                    $label = date_i18n('F', mktime(0, 0, 0, intval($month_data['m']), 1, intval($year)));
                    echo '<a class="bitstream-filter-link' . $is_active . '" href="' . esc_url($build_filter_url($base_filter_url, $selected_type, $month_value, $selected_search, $selected_hashtag)) . '">';
                    echo '<strong class="bitstream-archive-label">' . esc_html($label) . '</strong> <span class="bitstream-archive-count">(' . intval($month_data['c']) . ')</span>';
                    echo '</a>';
                }

                echo '</div>';
                echo '</details>';
                $year_index++;
            }

        }
        echo '</div>';

        echo '<div class="bitstream-feed-sidebar" style="margin-top: 0.75rem;">';
        echo '<h3 class="bitstream-feed-sidebar-title hide-on-mobile">Content</h3>';
        echo '<a class="bitstream-filter-link ' . ($selected_type === 'all' ? 'is-active' : '') . '" href="' . esc_url($build_filter_url($base_filter_url, 'all', $selected_month, $selected_search, $selected_hashtag)) . '">All</a>';
        echo '<a class="bitstream-filter-link ' . ($selected_type === 'bits' ? 'is-active' : '') . '" href="' . esc_url($build_filter_url($base_filter_url, 'bits', $selected_month, $selected_search, $selected_hashtag)) . '">Bits</a>';
        echo '<a class="bitstream-filter-link ' . ($selected_type === 'rebits' ? 'is-active' : '') . '" href="' . esc_url($build_filter_url($base_filter_url, 'rebits', $selected_month, $selected_search, $selected_hashtag)) . '">Rebits</a>';
        echo '</div>';
        echo '</div>'; // End filters panel

        // Hashtags Panel
        if (!empty($hashtag_counts)) {
            echo '<div class="bitstream-feed-sidebar-panel bitstream-feed-sidebar-panel-hashtags" data-panel-id="hashtags">';
            echo '<h3 class="bitstream-feed-sidebar-title hide-on-mobile">Hashtags</h3>';
            echo '<div class="bitstream-feed-sidebar bitstream-hashtag-list">';
            foreach ($hashtag_counts as $tag => $count) {
                $is_active_tag = (mb_strtolower($selected_hashtag, 'UTF-8') === mb_strtolower($tag, 'UTF-8')) ? ' is-active' : '';
                // Keep selected type, month, and search when adding a hashtag filter
                $tag_url = $build_filter_url($base_filter_url, $selected_type, $selected_month, $selected_search, $tag);
                echo '<a class="bitstream-filter-link bitstream-hashtag-sidebar-link' . $is_active_tag . '" href="' . esc_url($tag_url) . '">';
                echo '<span class="bitstream-hashtag-sidebar-tag">#' . esc_html($tag) . '</span>';
                echo '<span class="bitstream-archive-count">(' . intval($count) . ')</span>';
                echo '</a>';
            }
            echo '</div>';
            echo '</div>';
        }

        echo '</div>'; // End tabs
        echo '</div>'; // End sidebar left

        echo '</div>';

        echo '<main class="bitstream-feed-main">';

        if (!empty($desktop_quick_post)) {
            echo '<div class="bitstream-mobile-quick-post-container" style="margin-bottom: 1rem;">';
            echo '<aside class="bitstream-feed-sidebar">';
            echo $desktop_quick_post;
            echo '</aside>';
            echo '</div>';
        }

        if ($has_active_filters) {
            echo '<div class="bitstream-active-filters" aria-label="Active filters">';
            if ($selected_type !== 'all') {
                $type_label = ($selected_type === 'rebits') ? 'Rebits' : 'Bits';
                $remove_url = $build_filter_url($base_filter_url, 'all', $selected_month, $selected_search, $selected_hashtag);
                echo '<a href="' . esc_url($remove_url) . '" class="bitstream-filter-chip" title="Remove filter">' . esc_html($type_label) . ' <i class="fa-solid fa-xmark" aria-hidden="true" style="margin-left: 6px; font-size: 0.9em; opacity: 0.6;"></i></a>';
            }
            if (!empty($selected_month_label)) {
                $remove_url = $build_filter_url($base_filter_url, $selected_type, '', $selected_search, $selected_hashtag);
                echo '<a href="' . esc_url($remove_url) . '" class="bitstream-filter-chip" title="Remove filter">' . esc_html($selected_month_label) . ' <i class="fa-solid fa-xmark" aria-hidden="true" style="margin-left: 6px; font-size: 0.9em; opacity: 0.6;"></i></a>';
            }
            if (!empty($selected_search)) {
                $remove_url = $build_filter_url($base_filter_url, $selected_type, $selected_month, '', $selected_hashtag);
                echo '<a href="' . esc_url($remove_url) . '" class="bitstream-filter-chip" title="Remove filter">Search: ' . esc_html($selected_search) . ' <i class="fa-solid fa-xmark" aria-hidden="true" style="margin-left: 6px; font-size: 0.9em; opacity: 0.6;"></i></a>';
            }
            if (!empty($selected_hashtag)) {
                $remove_url = $build_filter_url($base_filter_url, $selected_type, $selected_month, $selected_search, '');
                echo '<a href="' . esc_url($remove_url) . '" class="bitstream-filter-chip" title="Remove filter">#' . esc_html($selected_hashtag) . ' <i class="fa-solid fa-xmark" aria-hidden="true" style="margin-left: 6px; font-size: 0.9em; opacity: 0.6;"></i></a>';
            }
            echo '<a class="bitstream-filter-chip bitstream-filter-chip-clear" href="' . esc_url($base_filter_url) . '">Clear all</a>';
            echo '</div>';
        }

        if ($q->have_posts()) {
            echo '<div class="' . $feed_classes . '" data-page="' . $current_page . '" data-max-page="' . $max . '" data-infinite-scroll="' . ($infinite_scroll ? 'true' : 'false') . '" data-filter-type="' . esc_attr($selected_type) . '" data-filter-month="' . esc_attr($selected_month) . '" data-filter-search="' . esc_attr($selected_search) . '" data-filter-hashtag="' . esc_attr($selected_hashtag) . '">';
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
        }
        else {
            echo '<div class="' . $feed_classes . '">';
            echo '<div class="bit-card" style="padding: 3rem 1.5rem; text-align: center; border-radius: 15px; background: #fff;">';
            echo '<p style="margin: 0; color: #666; font-size: 1.05rem;">No Bits found for this filter.</p>';
            echo '</div>';
            echo '</div>';
        }
        echo '</main>';

        echo '<div class="bitstream-feed-sidebar-right">';

        $has_content = false;

        if (!empty($desktop_quick_post)) {
            echo '<aside class="bitstream-feed-sidebar hide-on-mobile">';
            echo $desktop_quick_post;
            echo '</aside>';
            $has_content = true;
        }

        if (!empty($desktop_quick_actions) || !empty($desktop_rss_links)) {
            if (!empty($desktop_quick_actions)) {
                echo '<aside class="bitstream-feed-sidebar hide-on-mobile">';
                echo '<h3 class="bitstream-feed-sidebar-title">Quick Actions</h3>';
                echo $desktop_quick_actions;
                echo '</aside>';
            }
            if (!empty($desktop_rss_links)) {
                echo '<aside class="bitstream-feed-sidebar hide-on-mobile">';
                echo '<h3 class="bitstream-feed-sidebar-title">RSS Feeds</h3>';
                echo $desktop_rss_links;
                echo '</aside>';
            }
            $has_content = true;
        }

        // Render About Box as an independent right-sidebar widget
        echo '<aside class="bitstream-feed-sidebar">';
        echo self::render_about_box();
        echo '</aside>';
        $has_content = true;

        if (!$has_content) {
            echo '<aside class="bitstream-feed-sidebar" style="visibility:hidden;">';
            echo '<div class="bitstream-right-rail-reserved" aria-hidden="true"></div>';
            echo '</aside>';
        }

        echo '</div>'; // End feed layout

        // Inline script for Mobile Tabs
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var tabsNav = document.querySelector(".bitstream-mobile-tabs-nav");
            if (!tabsNav) return;
            var buttons = tabsNav.querySelectorAll("button");
            var panels = document.querySelectorAll(".bitstream-feed-sidebar-panel");
            
            buttons.forEach(function(btn) {
                btn.addEventListener("click", function() {
                    var targetId = this.getAttribute("data-panel");
                    var isActive = this.classList.contains("is-active");
                    
                    // Reset all
                    buttons.forEach(function(b) { b.classList.remove("is-active"); });
                    panels.forEach(function(p) { p.style.display = "none"; });
                    
                    // Toggle current
                    if (!isActive) {
                        this.classList.add("is-active");
                        var targetPanel = document.querySelector(".bitstream-feed-sidebar-panel-" + targetId);
                        if(targetPanel) targetPanel.style.display = "block";
                    }
                });
            });
        });
        </script>';

        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Render the BitStream Settings tabbed interface
     */
    public function render_settings($atts)
    {
        if (!is_user_logged_in()) {
            return '<p>Please log in to access settings.</p>';
        }

        if (!current_user_can('edit_posts')) {
            return '<p>You do not have permission to access settings.</p>';
        }

        $requested_tab = isset($_GET['settings_tab']) ? sanitize_key(wp_unslash($_GET['settings_tab'])) : 'personalisation';
        $valid_tabs = ['personalisation', 'mappings', 'rss', 'advanced'];
        $initial_tab = in_array($requested_tab, $valid_tabs, true) ? $requested_tab : 'personalisation';

        $is_personalisation = ($initial_tab === 'personalisation');
        $is_mappings = ($initial_tab === 'mappings');
        $is_rss = ($initial_tab === 'rss');
        $is_advanced = ($initial_tab === 'advanced');

        ob_start();
?>
        <section class="bitstream-settings">
            <div class="bitstream-settings-tabs" role="tablist" aria-label="BitStream Settings">
                <button type="button" class="bitstream-settings-tab <?php echo $is_personalisation ? 'is-active' : ''; ?>" data-settings-tab="personalisation" role="tab" aria-selected="<?php echo $is_personalisation ? 'true' : 'false'; ?>">
                    <i class="fa-solid fa-palette" aria-hidden="true"></i> Personalisation
                </button>
                <button type="button" class="bitstream-settings-tab <?php echo $is_mappings ? 'is-active' : ''; ?>" data-settings-tab="mappings" role="tab" aria-selected="<?php echo $is_mappings ? 'true' : 'false'; ?>">
                    <i class="fa-solid fa-sitemap" aria-hidden="true"></i> ReBit Mappings
                </button>
                <button type="button" class="bitstream-settings-tab <?php echo $is_rss ? 'is-active' : ''; ?>" data-settings-tab="rss" role="tab" aria-selected="<?php echo $is_rss ? 'true' : 'false'; ?>">
                    <i class="fa-solid fa-rss" aria-hidden="true"></i> RSS Feeds
                </button>
                <?php if (current_user_can('manage_options')): ?>
                <button type="button" class="bitstream-settings-tab <?php echo $is_advanced ? 'is-active' : ''; ?>" data-settings-tab="advanced" role="tab" aria-selected="<?php echo $is_advanced ? 'true' : 'false'; ?>">
                    <i class="fa-solid fa-gear" aria-hidden="true"></i> Advanced
                </button>
                <?php
        endif; ?>
            </div>

            <!-- Personalisation Panel -->
            <div class="bitstream-settings-panel <?php echo $is_personalisation ? 'is-active' : ''; ?>" id="bitstream-settings-panel-personalisation" role="tabpanel" <?php echo $is_personalisation ? '' : 'hidden'; ?>>
                <?php $this->render_settings_personalisation(); ?>
            </div>

            <!-- ReBit Mappings Panel -->
            <div class="bitstream-settings-panel <?php echo $is_mappings ? 'is-active' : ''; ?>" id="bitstream-settings-panel-mappings" role="tabpanel" <?php echo $is_mappings ? '' : 'hidden'; ?>>
                <?php $this->render_settings_mappings(); ?>
            </div>

            <!-- RSS Feeds Panel -->
            <div class="bitstream-settings-panel <?php echo $is_rss ? 'is-active' : ''; ?>" id="bitstream-settings-panel-rss" role="tabpanel" <?php echo $is_rss ? '' : 'hidden'; ?>>
                <?php $this->render_settings_rss(); ?>
            </div>

            <!-- Advanced Panel -->
            <?php if (current_user_can('manage_options')): ?>
            <div class="bitstream-settings-panel <?php echo $is_advanced ? 'is-active' : ''; ?>" id="bitstream-settings-panel-advanced" role="tabpanel" <?php echo $is_advanced ? '' : 'hidden'; ?>>
                <?php $this->render_settings_advanced(); ?>
            </div>
            <?php
        endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Settings Tab 1: Personalisation (Feed Intro)
     */
    private function render_settings_personalisation()
    {
        $default_intro_title = 'About BitStream';
        $default_intro_text = 'BitStream is a lightweight microblog where you can post Bits, share Rebits, and follow updates in one place.';

        $intro_title = get_option('bitstream_feed_intro_title', $default_intro_title);
        $intro_text = get_option('bitstream_feed_intro_text', $default_intro_text);
        $updated = false;

        if (isset($_POST['bitstream_save_feed_intro']) && check_admin_referer('bitstream_feed_intro_save', 'bitstream_feed_intro_nonce')) {
            $new_intro_title = sanitize_text_field(wp_unslash($_POST['bitstream_feed_intro_title'] ?? ''));
            $new_intro_text = sanitize_textarea_field(wp_unslash($_POST['bitstream_feed_intro_text'] ?? ''));

            $intro_title = !empty($new_intro_title) ? $new_intro_title : $default_intro_title;
            $intro_text = !empty($new_intro_text) ? $new_intro_text : $default_intro_text;

            update_option('bitstream_feed_intro_title', $intro_title, false);
            update_option('bitstream_feed_intro_text', $intro_text, false);
            $updated = true;
        }

        echo '<h2 style="margin-top: 0;">Feed Intro</h2>';
        echo '<p>Edit the intro text shown in the BitStream feed sidebar.</p>';

        if ($updated) {
            echo '<div class="notice notice-success" style="padding: 10px; border-left: 4px solid #2c6e49; background: #f0f9f4; margin-bottom: 1rem;"><p style="margin: 0;">Intro text updated.</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('bitstream_feed_intro_save', 'bitstream_feed_intro_nonce');
        echo '<input type="hidden" name="settings_tab" value="personalisation">';

        echo '<div style="margin-bottom: 1rem;">';
        echo '<label for="bitstream-feed-intro-title" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Title</label>';
        echo '<input id="bitstream-feed-intro-title" name="bitstream_feed_intro_title" type="text" style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box;" value="' . esc_attr($intro_title) . '">';
        echo '</div>';

        echo '<div style="margin-bottom: 1rem;">';
        echo '<label for="bitstream-feed-intro-text" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Description</label>';
        echo '<textarea id="bitstream-feed-intro-text" name="bitstream_feed_intro_text" rows="5" style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box;">' . esc_textarea($intro_text) . '</textarea>';
        echo '</div>';

        echo '<button type="submit" name="bitstream_save_feed_intro" style="background: var(--wp--preset--color--accent-1, #2c6e49); color: #fff; border: none; border-radius: 10px; padding: 0.6rem 1.5rem; cursor: pointer; font-weight: 600; font-size: 0.95rem;">Save Intro</button>';
        echo '</form>';
    }

    /**
     * Settings Tab 2: ReBit Mappings
     */
    private function render_settings_mappings()
    {
        if (!current_user_can('manage_options')) {
            echo '<p>You do not have permission to manage ReBit mappings.</p>';
            return;
        }

        // Delegate to existing admin interface method which handles form processing & rendering
        $admin = new BitStream_Admin_Interface();
        $admin->rebit_mappings_page();
    }

    /**
     * Settings Tab 3: RSS Feeds
     */
    private function render_settings_rss()
    {
        $home_url = home_url();

        // Handle flush rewrite rules request
        if (isset($_POST['flush_feeds']) && check_admin_referer('bitstream_flush_feeds', 'bitstream_flush_feeds_nonce')) {
            flush_rewrite_rules();
            echo '<div style="padding: 10px; border-left: 4px solid #2c6e49; background: #f0f9f4; margin-bottom: 1rem;"><p style="margin: 0;">Rewrite rules flushed! RSS feeds should now work properly.</p></div>';
        }

        $feeds = [
            'All Content' => [
                'url' => $home_url . '/bitstream/feed/',
                'description' => 'Complete BitStream feed with all Bits and ReBits'
            ],
            'Bits Only' => [
                'url' => $home_url . '/bitstream/feed/bits/',
                'description' => 'Original Bits only (excluding ReBits)'
            ],
            'ReBits Only' => [
                'url' => $home_url . '/bitstream/feed/rebits/',
                'description' => 'ReBits only (shared content from other platforms)'
            ]
        ];

        echo '<h2 style="margin-top: 0;">RSS Feeds</h2>';
        echo '<p>BitStream provides multiple RSS feeds for different content types.</p>';

        foreach ($feeds as $name => $feed) {
            echo '<div class="bitstream-settings-section">';
            echo '<h3>' . esc_html($name) . '</h3>';
            echo '<p>' . esc_html($feed['description']) . '</p>';

            echo '<div style="margin-bottom: 0.75rem;">';
            echo '<label style="font-weight: 600; display: block; margin-bottom: 0.4rem;">Feed URL:</label>';
            echo '<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">';
            echo '<input type="text" value="' . esc_attr($feed['url']) . '" readonly style="flex: 1; min-width: 200px; padding: 0.5rem; border: 1px solid #ccc; border-radius: 8px; font-family: monospace; background: #f9f9f9; box-sizing: border-box;" onclick="this.select();" />';
            echo '<button type="button" class="bitstream-copy-btn" data-copy-text="' . esc_attr($feed['url']) . '" style="background: var(--wp--preset--color--accent-1, #2c6e49); color: #fff; border: none; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-weight: 600; white-space: nowrap;">Copy</button>';
            echo '<a href="' . esc_url($feed['url']) . '" target="_blank" style="display: inline-block; padding: 0.5rem 1rem; border: 1px solid #ccc; border-radius: 8px; text-decoration: none; color: #333; font-weight: 600; white-space: nowrap;">View Feed</a>';
            echo '</div>';
            echo '</div>';

            $feed_url_encoded = urlencode($feed['url']);
            $subscribe_links = [
                'Feedly' => 'https://feedly.com/i/subscription/feed/' . $feed_url_encoded,
                'Inoreader' => 'https://www.inoreader.com/?add_feed=' . $feed_url_encoded,
                'NewsBlur' => 'https://newsblur.com/?url=' . $feed_url_encoded,
                'Pocket' => 'https://getpocket.com/edit?url=' . $feed_url_encoded
            ];

            echo '<div style="margin-top: 0.5rem;">';
            echo '<strong>Subscribe with:</strong> ';
            echo '<span style="display: inline-flex; gap: 6px; flex-wrap: wrap; margin-top: 0.4rem;">';
            foreach ($subscribe_links as $service => $link) {
                echo '<a href="' . esc_url($link) . '" target="_blank" style="display: inline-block; padding: 0.3rem 0.7rem; border: 1px solid #ccc; border-radius: 6px; text-decoration: none; color: #333; font-size: 0.85rem;">' . esc_html($service) . '</a>';
            }
            echo '</span>';
            echo '</div>';
            echo '</div>';
        }

        // Troubleshooting
        if (current_user_can('manage_options')) {
            echo '<div style="padding: 1rem; background: #f9f9f9; border: 1px solid #eee; border-radius: 10px;">';
            echo '<h3 style="margin-top: 0;">Troubleshooting</h3>';
            echo '<p>If the RSS feeds are showing 404 errors, try flushing the rewrite rules:</p>';
            echo '<form method="post">';
            wp_nonce_field('bitstream_flush_feeds', 'bitstream_flush_feeds_nonce');
            echo '<input type="hidden" name="settings_tab" value="rss">';
            echo '<button type="submit" name="flush_feeds" style="background: #666; color: #fff; border: none; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-weight: 600;">Flush Rewrite Rules</button>';
            echo '</form>';
            echo '</div>';
        }
    }

    /**
     * Settings Tab 4: Advanced (Debug Logs, Media Cleanup, Reset)
     */
    private function render_settings_advanced()
    {
        if (!current_user_can('manage_options')) {
            echo '<p>You do not have permission to access advanced settings.</p>';
            return;
        }

        // --- Debug Logs Section ---
        echo '<div class="bitstream-settings-section">';
        echo '<h2 style="margin-top: 0;">Debug Logs</h2>';

        if (isset($_POST['clear_logs']) && check_admin_referer('bitstream_clear_logs')) {
            delete_option('bitstream_debug_logs');
            echo '<div style="padding: 10px; border-left: 4px solid #2c6e49; background: #f0f9f4; margin-bottom: 1rem;"><p style="margin: 0;">Logs cleared successfully.</p></div>';
        }

        $logs = get_option('bitstream_debug_logs', []);
        if (empty($logs)) {
            echo '<p>No logs found.</p>';
        }
        else {
            echo '<form method="post" style="margin-bottom: 1rem;">';
            wp_nonce_field('bitstream_clear_logs');
            echo '<input type="hidden" name="settings_tab" value="advanced">';
            echo '<button type="submit" name="clear_logs" style="background: #666; color: #fff; border: none; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-weight: 600;">Clear Logs</button>';
            echo '</form>';

            echo '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #eee; border-radius: 8px;">';
            echo '<table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">';
            echo '<thead><tr style="background: #f5f5f5;"><th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid #ddd;">Time</th><th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid #ddd;">Level</th><th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid #ddd;">Message</th></tr></thead>';
            echo '<tbody>';
            foreach ($logs as $log) {
                $color = ($log['level'] === 'error') ? '#dc3545' : '#555';
                echo '<tr><td style="padding: 0.4rem 0.5rem; border-bottom: 1px solid #f0f0f0; white-space: nowrap;">' . esc_html($log['time']) . '</td>';
                echo '<td style="padding: 0.4rem 0.5rem; border-bottom: 1px solid #f0f0f0; color: ' . $color . '; font-weight: 600;">' . esc_html(strtoupper($log['level'])) . '</td>';
                echo '<td style="padding: 0.4rem 0.5rem; border-bottom: 1px solid #f0f0f0;">' . esc_html($log['message']) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        echo '</div>';

        // --- Media Cleanup Section ---
        echo '<div class="bitstream-settings-section">';
        echo '<h2>Media Cleanup</h2>';
        echo '<p>Scan BitStream-managed uploads and remove media no longer referenced anywhere on your site.</p>';
        echo '<p><strong>Safety checks:</strong> media is only deleted when not referenced by active content, and recent uploads (&lt; 30 minutes) are skipped.</p>';

        $results = null;
        $did_delete = false;
        $last_weekly = get_option('bitstream_last_weekly_media_cleanup', []);

        if (isset($_POST['bitstream_media_scan']) && check_admin_referer('bitstream_media_cleanup', 'bitstream_media_cleanup_nonce')) {
            $admin = new BitStream_Admin_Interface();
            $results = $admin->run_bitstream_media_cleanup(false);
        }

        if (isset($_POST['bitstream_media_delete']) && check_admin_referer('bitstream_media_cleanup', 'bitstream_media_cleanup_nonce')) {
            $admin = new BitStream_Admin_Interface();
            $results = $admin->run_bitstream_media_cleanup(true);
            $did_delete = true;
        }

        if (!empty($last_weekly) && !empty($last_weekly['timestamp'])) {
            $run_time = wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($last_weekly['timestamp']));
            echo '<p><strong>Last weekly cleanup:</strong> ' . esc_html($run_time) . ' — Scanned: ' . intval($last_weekly['scanned'] ?? 0) . ', Deleted: ' . intval($last_weekly['deleted'] ?? 0) . ', Protected: ' . intval($last_weekly['protected'] ?? 0) . '</p>';
        }

        if ($results) {
            $bg = $did_delete ? '#f0f9f4' : '#e7f3ff';
            $border = $did_delete ? '#2c6e49' : '#72aee6';
            echo '<div style="padding: 10px; border-left: 4px solid ' . $border . '; background: ' . $bg . '; margin-bottom: 1rem;"><p style="margin: 0;">';
            echo($did_delete ? 'Cleanup complete. ' : 'Scan complete. ');
            echo 'Scanned: <strong>' . intval($results['scanned']) . '</strong>, Candidates: <strong>' . intval($results['candidates']) . '</strong>, Deleted: <strong>' . intval($results['deleted']) . '</strong>, Protected: <strong>' . intval($results['protected']) . '</strong>';
            echo '</p></div>';
        }

        echo '<form method="post" style="display: flex; gap: 8px; flex-wrap: wrap;">';
        wp_nonce_field('bitstream_media_cleanup', 'bitstream_media_cleanup_nonce');
        echo '<input type="hidden" name="settings_tab" value="advanced">';
        echo '<button type="submit" name="bitstream_media_scan" style="background: #666; color: #fff; border: none; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-weight: 600;">Scan Only</button>';
        echo '<button type="submit" name="bitstream_media_delete" style="background: #dc3545; color: #fff; border: none; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-weight: 600;" onclick="return confirm(\'Delete all currently detected orphaned BitStream media? This cannot be undone.\');">Scan and Delete Orphans</button>';
        echo '</form>';
        echo '</div>';

        // --- Reset Section ---
        echo '<div class="bitstream-settings-section">';
        echo '<h2>Reset BitStream</h2>';
        echo '<div style="padding: 1rem; background: #fff5f5; border: 1px solid #dc3545; border-radius: 10px;">';
        echo '<p style="color: #dc3545; font-weight: 600;"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> WARNING: This will permanently delete all Bits, ReBits, associated media files, and plugin settings. This cannot be undone!</p>';

        if (isset($_POST['bitstream_confirm_reset']) && check_admin_referer('bitstream_reset', 'bitstream_reset_nonce')) {
            $admin = new BitStream_Admin_Interface();
            $admin->perform_bitstream_reset();
            echo '<div style="padding: 10px; border-left: 4px solid #2c6e49; background: #f0f9f4; margin: 1rem 0;"><p style="margin: 0;"><strong>BitStream Reset Complete.</strong> All posts, media, and settings have been removed.</p></div>';
            echo '<script>setTimeout(function() { window.location.href = "' . esc_js(admin_url('edit.php?post_type=bit&page=bitstream-settings')) . '"; }, 2000);</script>';
            echo '</div></div>';
            return;
        }

        echo '<form method="post" style="margin-top: 1rem;">';
        wp_nonce_field('bitstream_reset', 'bitstream_reset_nonce');
        echo '<input type="hidden" name="settings_tab" value="advanced">';
        echo '<button type="submit" name="bitstream_confirm_reset" style="background: #dc3545; color: #fff; border: none; border-radius: 8px; padding: 0.6rem 1.5rem; cursor: pointer; font-weight: 600;" onclick="return confirm(\'Permanently delete ALL BitStream data and reset to virgin state? This cannot be undone.\');">Reset Everything</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render tabbed frontend poster (Bit/Rebit)
     */
    public function render_poster($atts)
    {
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

        $initial_tab = in_array($requested_tab, ['bit', 'rebit', 'scheduled', 'drafts'], true) ? $requested_tab : 'bit';

        $shared_url = isset($_GET['shared_url']) ? esc_url_raw(wp_unslash($_GET['shared_url'])) : '';
        $shared_title = isset($_GET['shared_title']) ? sanitize_text_field(wp_unslash($_GET['shared_title'])) : '';
        $shared_text = isset($_GET['shared_text']) ? sanitize_textarea_field(wp_unslash($_GET['shared_text'])) : '';
        $edit_post_id = isset($_GET['edit_post_id']) ? intval($_GET['edit_post_id']) : 0;

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

        $bit_attachment_id_prefill = 0;
        $rebit_attachment_id_prefill = 0;
        if (!empty($_GET['media_ids'])) {
            $media_ids_raw = sanitize_text_field(wp_unslash($_GET['media_ids']));
            $media_ids = array_filter(array_map('intval', explode(',', $media_ids_raw)));
            if (!empty($media_ids)) {
                $bit_attachment_id_prefill = intval(reset($media_ids));
            }
        }

        $bit_content_prefill = '';
        $rebit_commentary_prefill = '';
        $rebit_og_desc_prefill = '';
        $rebit_og_image_prefill = '';
        $rebit_image_removed_prefill = '0';
        $is_edit_mode = false;
        $editing_is_rebit = false;

        $bit_schedule_mode = 'now';
        $bit_schedule_datetime = '';
        $bit_schedule_enabled = '0';
        $rebit_schedule_mode = 'now';
        $rebit_schedule_datetime = '';
        $rebit_schedule_enabled = '0';

        if (!empty($shared_url)) {
            if (!empty($shared_text) && $shared_text !== $shared_url) {
                $rebit_commentary_prefill = $shared_text;
            }
        }
        elseif (!empty($shared_text)) {
            $bit_content_prefill = $shared_text;
        }

        if ($edit_post_id > 0) {
            $editing_post = get_post($edit_post_id);
            if ($editing_post && $editing_post->post_type === 'bit' && current_user_can('edit_post', $edit_post_id)) {
                $is_edit_mode = true;
                $editing_is_rebit = !empty(get_post_meta($edit_post_id, 'bitstream_rebit_url', true));

                if ($editing_is_rebit) {
                    $initial_tab = 'rebit';
                    $shared_url = esc_url_raw(get_post_meta($edit_post_id, 'bitstream_rebit_url', true));
                    $rebit_commentary_prefill = $this->get_editable_text_content($editing_post->post_content);
                    $shared_title = sanitize_text_field(get_post_meta($edit_post_id, '_bitstream_og_title', true));
                    $rebit_og_desc_prefill = sanitize_textarea_field(get_post_meta($edit_post_id, '_bitstream_og_desc', true));
                    $rebit_og_image_prefill = esc_url_raw(get_post_meta($edit_post_id, '_bitstream_og_image', true));
                    $rebit_attachment_id_prefill = $this->get_primary_attachment_id($edit_post_id);

                    if ($editing_post->post_status === 'future') {
                        $rebit_schedule_mode = 'later';
                        $rebit_schedule_enabled = '1';
                        $rebit_schedule_datetime = mysql2date('Y-m-d\\TH:i', $editing_post->post_date, false);
                    }
                }
                else {
                    $initial_tab = 'bit';
                    $bit_content_prefill = $this->get_editable_text_content($editing_post->post_content);
                    $quote_post_id = intval(get_post_meta($edit_post_id, '_bitstream_quoted_bit', true));
                    $bit_attachment_id_prefill = $this->get_primary_attachment_id($edit_post_id);

                    if ($editing_post->post_status === 'future') {
                        $bit_schedule_mode = 'later';
                        $bit_schedule_enabled = '1';
                        $bit_schedule_datetime = mysql2date('Y-m-d\\TH:i', $editing_post->post_date, false);
                    }
                }
            }
        }

        $editing_is_draft = ($is_edit_mode && $editing_post && $editing_post->post_status === 'draft');

        $bit_edit_post_id = ($is_edit_mode && !$editing_is_rebit) ? $edit_post_id : 0;
        $rebit_edit_post_id = ($is_edit_mode && $editing_is_rebit) ? $edit_post_id : 0;
        $bit_submit_label = ($bit_edit_post_id > 0) ? 'Update Bit' : 'Publish Bit';
        $rebit_submit_label = ($rebit_edit_post_id > 0) ? 'Update Rebit' : 'Publish Rebit';
        $bit_edit_banner = ($bit_edit_post_id > 0) ? sprintf('Editing Bit #%d', $bit_edit_post_id) : '';
        $rebit_edit_banner = ($rebit_edit_post_id > 0) ? sprintf('Editing Rebit #%d', $rebit_edit_post_id) : '';

        $is_bit_active = ($initial_tab === 'bit');
        $is_rebit_active = ($initial_tab === 'rebit');
        $is_scheduled_active = ($initial_tab === 'scheduled');
        $is_drafts_active = ($initial_tab === 'drafts');
        $highlight_scheduled_id = isset($_GET['highlight_scheduled']) ? intval($_GET['highlight_scheduled']) : 0;
        $highlight_draft_id = isset($_GET['highlight_draft']) ? intval($_GET['highlight_draft']) : 0;

        $scheduled_query = new WP_Query([
            'post_type' => 'bit',
            'post_status' => 'future',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
            'author' => get_current_user_id(),
            'no_found_rows' => true,
        ]);

        $drafts_query = new WP_Query([
            'post_type' => 'bit',
            'post_status' => 'draft',
            'posts_per_page' => 50,
            'orderby' => 'modified',
            'order' => 'DESC',
            'author' => get_current_user_id(),
            'no_found_rows' => true,
        ]);

        $quote_preview = '';
        if ($quote_post_id > 0) {
            $quoted_post = get_post($quote_post_id);
            if ($quoted_post && $quoted_post->post_type === 'bit' && $quoted_post->post_status === 'publish') {
                $quote_preview = $this->render_quote_preview_card($quote_post_id);
            }
            else {
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
                <button type="button" class="bitstream-poster-tab <?php echo $is_drafts_active ? 'is-active' : ''; ?>" data-tab="drafts" role="tab" aria-selected="<?php echo $is_drafts_active ? 'true' : 'false'; ?>" aria-controls="bitstream-poster-panel-drafts" id="bitstream-poster-tab-drafts">
                    Drafts
                </button>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_bit_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-bit" role="tabpanel" aria-labelledby="bitstream-poster-tab-bit" <?php echo $is_bit_active ? '' : 'hidden'; ?>>
                <?php if (!empty($bit_edit_banner)): ?>
                    <p class="bitstream-poster-editing-banner"><?php echo esc_html($bit_edit_banner); ?></p>
                <?php
        endif; ?>
                <form class="bitstream-poster-form" data-poster-type="bit">
                    <label for="bitstream-bit-content"><strong>Bit content</strong></label>
                    <textarea id="bitstream-bit-content" name="bit_content" rows="5" placeholder="What’s happening?"><?php echo esc_textarea($bit_content_prefill); ?></textarea>

                    <input type="hidden" name="quote_post_id" value="<?php echo esc_attr($quote_post_id); ?>">

                    <div class="bitstream-media-field">
                        <input type="hidden" name="edit_post_id" value="<?php echo esc_attr($bit_edit_post_id); ?>">
                        <input type="hidden" id="bitstream-bit-attachment-id" name="bit_attachment_id" value="<?php echo esc_attr($bit_attachment_id_prefill); ?>">
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
                            <button type="button" class="bitstream-media-paste" data-target-input="bitstream-bit-attachment-id" data-target-preview="bitstream-bit-media-preview">Paste from clipboard</button>
                        </div>
                    </div>

                    <?php if (!empty($quote_preview)): ?>
                        <div class="bitstream-poster-quote-preview">
                            <p><strong>You are quoting this bit:</strong></p>
                            <?php echo $quote_preview; ?>
                        </div>
                    <?php
        endif; ?>

                    <details class="bitstream-post-options">
                        <summary>Advanced</summary>
                        <div class="bitstream-post-options-section">
                            <h4 class="bitstream-post-options-section-title">Schedule</h4>
                        <div class="bitstream-schedule-options">
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="bit_schedule_mode" value="now" data-schedule-toggle="bit" <?php checked($bit_schedule_mode, 'now'); ?>>
                                Post now
                            </label>
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="bit_schedule_mode" value="later" data-schedule-toggle="bit" <?php checked($bit_schedule_mode, 'later'); ?>>
                                Schedule for later
                            </label>
                        </div>
                        <input type="datetime-local" name="bit_schedule_datetime" class="bitstream-schedule-datetime" data-schedule-input="bit" value="<?php echo esc_attr($bit_schedule_datetime); ?>" <?php echo $bit_schedule_enabled === '1' ? '' : 'disabled'; ?>>
                        <input type="hidden" name="bit_schedule_enabled" value="<?php echo esc_attr($bit_schedule_enabled); ?>" data-schedule-hidden="bit">
                        </div>
                    </details>

                    <div class="bitstream-poster-actions">
                        <button type="submit" class="bitstream-poster-submit"><?php echo esc_html($bit_submit_label); ?></button>
                        <button type="button" class="bitstream-poster-save-draft"><?php echo $editing_is_draft ? 'Update Draft' : 'Save to Drafts'; ?></button>
                    </div>
                </form>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_rebit_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-rebit" role="tabpanel" aria-labelledby="bitstream-poster-tab-rebit" <?php echo $is_rebit_active ? '' : 'hidden'; ?>>
                <?php if (!empty($rebit_edit_banner)): ?>
                    <p class="bitstream-poster-editing-banner"><?php echo esc_html($rebit_edit_banner); ?></p>
                <?php
        endif; ?>
                <form class="bitstream-poster-form" data-poster-type="rebit">
                    <label for="bitstream-rebit-url"><strong>Link URL</strong></label>
                    <div class="bitstream-rebit-url-row">
                        <input type="url" id="bitstream-rebit-url" name="rebit_url" required placeholder="https://example.com/post" value="<?php echo esc_attr($shared_url); ?>">
                        <button type="button" class="bitstream-fetch-og">Fetch metadata</button>
                    </div>

                    <label for="bitstream-rebit-commentary"><strong>Bit Content</strong></label>
                    <textarea id="bitstream-rebit-commentary" name="rebit_commentary" rows="5" placeholder="What’s happening?"><?php echo esc_textarea($rebit_commentary_prefill); ?></textarea>
                    <input type="hidden" name="edit_post_id" value="<?php echo esc_attr($rebit_edit_post_id); ?>">
                    <input type="hidden" id="bitstream-rebit-og-title" name="rebit_og_title" value="<?php echo esc_attr($shared_title); ?>">
                    <input type="hidden" id="bitstream-rebit-og-desc" name="rebit_og_desc" value="<?php echo esc_attr($rebit_og_desc_prefill); ?>">
                    <input type="hidden" id="bitstream-rebit-og-image" name="rebit_og_image" value="<?php echo esc_attr($rebit_og_image_prefill); ?>">
                    <input type="hidden" id="bitstream-rebit-og-image-removed" name="rebit_og_image_removed" value="<?php echo esc_attr($rebit_image_removed_prefill); ?>">
                    <input type="hidden" id="bitstream-rebit-attachment-id" name="rebit_attachment_id" value="<?php echo esc_attr($rebit_attachment_id_prefill); ?>">

                    <div class="bitstream-rebit-preview-actions" hidden>
                        <button type="button" class="bitstream-rebit-edit-preview">Edit metadata</button>
                        <button type="button" class="bitstream-rebit-refresh-preview">Refresh preview</button>
                    </div>

                    <div class="bitstream-rebit-live-preview" id="bitstream-rebit-live-preview" hidden>
                        <p class="bitstream-rebit-live-preview-label"><strong>Live preview</strong></p>
                        <p class="bitstream-rebit-live-preview-loading" hidden>Loading live preview...</p>
                        <div class="bitstream-rebit-live-preview-card"></div>
                    </div>

                    <details class="bitstream-post-options">
                        <summary>Advanced</summary>
                        <div class="bitstream-post-options-section">
                            <h4 class="bitstream-post-options-section-title">Schedule</h4>
                        <div class="bitstream-schedule-options">
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="rebit_schedule_mode" value="now" data-schedule-toggle="rebit" <?php checked($rebit_schedule_mode, 'now'); ?>>
                                Post now
                            </label>
                            <label class="bitstream-schedule-radio">
                                <input type="radio" name="rebit_schedule_mode" value="later" data-schedule-toggle="rebit" <?php checked($rebit_schedule_mode, 'later'); ?>>
                                Schedule for later
                            </label>
                        </div>
                        <input type="datetime-local" name="rebit_schedule_datetime" class="bitstream-schedule-datetime" data-schedule-input="rebit" value="<?php echo esc_attr($rebit_schedule_datetime); ?>" <?php echo $rebit_schedule_enabled === '1' ? '' : 'disabled'; ?>>
                        <input type="hidden" name="rebit_schedule_enabled" value="<?php echo esc_attr($rebit_schedule_enabled); ?>" data-schedule-hidden="rebit">
                        </div>
                    </details>

                    <div class="bitstream-poster-actions">
                        <button type="submit" class="bitstream-poster-submit"><?php echo esc_html($rebit_submit_label); ?></button>
                        <button type="button" class="bitstream-poster-save-draft"><?php echo $editing_is_draft ? 'Update Draft' : 'Save to Drafts'; ?></button>
                    </div>
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
                            <?php while ($scheduled_query->have_posts()):
                $scheduled_query->the_post(); ?>
                                <?php
                $scheduled_id = get_the_ID();
                $is_rebit = !empty(get_post_meta($scheduled_id, 'bitstream_rebit_url', true));
                $row_type = $is_rebit ? 'rebit' : 'bit';
                $is_highlighted = ($highlight_scheduled_id > 0 && $highlight_scheduled_id === $scheduled_id);
?>
                                <article class="bitstream-scheduled-item <?php echo $is_highlighted ? 'is-highlighted' : ''; ?>" data-type="<?php echo esc_attr($row_type); ?>" data-post-id="<?php echo esc_attr($scheduled_id); ?>">
                                    <div>
                                        <strong><?php echo $is_rebit ? 'Rebit' : 'Bit'; ?></strong>
                                        <p><?php echo esc_html(wp_trim_words(get_post_field('post_content', $scheduled_id), 16)); ?></p>
                                        <small>Scheduled for <?php echo esc_html(get_the_date('Y-m-d H:i', $scheduled_id)); ?></small>
                                    </div>
                                    <div class="bitstream-scheduled-actions">
                                        <a class="bitstream-scheduled-action bitstream-scheduled-edit bit-action" href="<?php echo esc_url(BitStream_Shortcodes::get_poster_page_url(['poster_tab' => $row_type, 'edit_post_id' => $scheduled_id])); ?>" aria-label="Edit scheduled bit" title="Edit scheduled bit">
                                            <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                                        </a>
                                        <a class="bitstream-scheduled-action bitstream-scheduled-preview bit-action" href="<?php echo esc_url(get_preview_post_link($scheduled_id)); ?>" target="_blank" rel="noopener" aria-label="Preview scheduled bit" title="Preview scheduled bit">
                                            <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i>
                                        </a>
                                        <button type="button" class="bitstream-scheduled-action bitstream-scheduled-delete bit-action" data-post-id="<?php echo esc_attr($scheduled_id); ?>" aria-label="Delete scheduled bit" title="Delete scheduled bit">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </article>
                            <?php
            endwhile; ?>
                            <?php wp_reset_postdata(); ?>
                        <?php
        else: ?>
                            <p>No scheduled Bits or Rebits yet.</p>
                        <?php
        endif; ?>
                    </div>
                </section>
            </div>

            <div class="bitstream-poster-panel <?php echo $is_drafts_active ? 'is-active' : ''; ?>" id="bitstream-poster-panel-drafts" role="tabpanel" aria-labelledby="bitstream-poster-tab-drafts" <?php echo $is_drafts_active ? '' : 'hidden'; ?>>
                <section class="bitstream-advanced-section bitstream-advanced-section-drafts">
                    <h3>Drafts</h3>
                    <div class="bitstream-scheduled-filter bitstream-drafts-filter">
                        <button type="button" class="bitstream-scheduled-filter-btn bitstream-drafts-filter-btn is-active" data-filter="all">All</button>
                        <button type="button" class="bitstream-scheduled-filter-btn bitstream-drafts-filter-btn" data-filter="bit">Bits</button>
                        <button type="button" class="bitstream-scheduled-filter-btn bitstream-drafts-filter-btn" data-filter="rebit">Rebits</button>
                    </div>
                    <div class="bitstream-scheduled-list bitstream-drafts-list">
                        <?php if ($drafts_query->have_posts()): ?>
                            <?php while ($drafts_query->have_posts()):
                $drafts_query->the_post(); ?>
                                <?php
                $draft_id = get_the_ID();
                $is_rebit = !empty(get_post_meta($draft_id, 'bitstream_rebit_url', true));
                $row_type = $is_rebit ? 'rebit' : 'bit';
                $is_highlighted = ($highlight_draft_id > 0 && $highlight_draft_id === $draft_id);
?>
                                <article class="bitstream-scheduled-item bitstream-draft-item <?php echo $is_highlighted ? 'is-highlighted' : ''; ?>" data-type="<?php echo esc_attr($row_type); ?>" data-post-id="<?php echo esc_attr($draft_id); ?>">
                                    <div>
                                        <strong><?php echo $is_rebit ? 'Rebit' : 'Bit'; ?></strong>
                                        <p><?php echo esc_html(wp_trim_words(get_post_field('post_content', $draft_id), 16)); ?></p>
                                        <small>Last modified <?php echo esc_html(get_the_modified_date('Y-m-d H:i', $draft_id)); ?></small>
                                    </div>
                                    <div class="bitstream-scheduled-actions">
                                        <a class="bitstream-scheduled-action bitstream-scheduled-edit bit-action" href="<?php echo esc_url(BitStream_Shortcodes::get_poster_page_url(['poster_tab' => $row_type, 'edit_post_id' => $draft_id])); ?>" aria-label="Edit draft" title="Edit draft">
                                            <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                                        </a>
                                        <a class="bitstream-scheduled-action bitstream-scheduled-preview bit-action" href="<?php echo esc_url(get_preview_post_link($draft_id)); ?>" target="_blank" rel="noopener" aria-label="Preview draft" title="Preview draft">
                                            <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i>
                                        </a>
                                        <button type="button" class="bitstream-scheduled-action bitstream-scheduled-delete bitstream-draft-delete bit-action" data-post-id="<?php echo esc_attr($draft_id); ?>" aria-label="Delete draft" title="Delete draft">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </article>
                            <?php
            endwhile; ?>
                            <?php wp_reset_postdata(); ?>
                        <?php
        else: ?>
                            <p>No draft Bits or Rebits yet.</p>
                        <?php
        endif; ?>
                    </div>
                </section>
            </div>

            <div class="bitstream-poster-status" aria-live="polite"></div>

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

            <div class="bitstream-rebit-editor-modal" hidden>
                <div class="bitstream-rebit-editor-backdrop" data-rebit-editor-close="true"></div>
                <div class="bitstream-rebit-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="bitstream-rebit-editor-title">
                    <header class="bitstream-rebit-editor-header">
                        <h3 id="bitstream-rebit-editor-title">Edit metadata</h3>
                    </header>
                    <div class="bitstream-rebit-editor-body">
                        <label for="bitstream-rebit-modal-og-title">
                            <span>Preview title</span>
                            <input type="text" id="bitstream-rebit-modal-og-title" placeholder="Auto-filled from metadata">
                        </label>
                        <label for="bitstream-rebit-modal-og-desc">
                            <span>Preview description</span>
                            <textarea id="bitstream-rebit-modal-og-desc" rows="3" placeholder="Auto-filled from metadata"></textarea>
                        </label>

                        <div class="bitstream-rebit-editor-image">
                            <img class="bitstream-rebit-editor-image-preview" src="" alt="" hidden>
                            <div class="bitstream-rebit-editor-image-buttons">
                                <button type="button" class="bitstream-rebit-editor-image-select">Choose image</button>
                                <button type="button" class="bitstream-rebit-editor-image-crop">Crop image</button>
                                <button type="button" class="bitstream-rebit-editor-image-clear">Remove image</button>
                                <button type="button" class="bitstream-media-paste" data-target-input="bitstream-rebit-attachment-id" data-target-preview="bitstream-rebit-media-preview">Paste from clipboard</button>
                            </div>
                            <div class="bitstream-media-preview" id="bitstream-rebit-media-preview" hidden></div>
                        </div>
                    </div>
                    <footer class="bitstream-rebit-editor-footer">
                        <button type="button" class="bitstream-rebit-editor-close" data-rebit-editor-close="true">Close</button>
                        <button type="button" class="bitstream-rebit-editor-save">Save</button>
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
