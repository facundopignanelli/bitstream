<?php
/**
 * BitStream Content Display Handler
 * 
 * Handles content filtering, single bit display, and frontend rendering
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class BitStream_Content_Display
{

    public function __construct()
    {
        add_action('template_redirect', [$this, 'handle_single_bit_display']);
        add_filter('the_content', [$this, 'display_quoted_content']);
        add_filter('the_content', [$this, 'linkify_hashtags'], 20);
        add_action('save_post_bit', [$this, 'save_rebit_og_data']);
    }

    /**
     * Handle single bit post display using theme template
     */
    public function handle_single_bit_display()
    {
        global $post;

        if (is_single() && $post && $post->post_type === 'bit') {
            // Ensure assets are loaded with proper priority
            wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION);
            wp_enqueue_script('bitstream-js', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js', ['jquery'], BITSTREAM_VERSION, true);

            // Add body class for better targeting
            add_filter('body_class', function ($classes) {
                $classes[] = 'bitstream-single-bit';
                return $classes;
            });

            // Use the theme's page template by filtering the content
            add_filter('the_content', [$this, 'single_bit_content']);
            add_action('wp_head', [$this, 'single_bit_styles']);
        }
    }

    /**
     * Filter content for single bit posts
     */
    public function single_bit_content($content)
    {
        global $post;

        // Prevent infinite loop by checking if we're already processing
        static $processing = false;
        if ($processing) {
            return $content;
        }

        if (is_single() && $post && $post->post_type === 'bit') {
            $processing = true;

            // Temporarily remove our filter to prevent infinite loop
            remove_filter('the_content', [$this, 'single_bit_content']);

            ob_start(); ?>
            <div class="bitstream-single-wrapper">
                <a href="<?php echo esc_url(home_url('/bitstream/')); ?>" class="bitstream-back-link">← Back to BitStream</a>
                <?php echo bitstream_render_card($post->ID, true); ?>
            </div>
            <?php
            $output = ob_get_clean();

            // Re-add our filter
            add_filter('the_content', [$this, 'single_bit_content']);

            $processing = false;
            return $output;
        }

        return $content;
    }

    /**
     * Add styles for single bit display
     */
    public function single_bit_styles()
    {
        global $post;

        if (is_single() && $post && $post->post_type === 'bit') { ?>
            <style>
                /* Hide theme's default post elements that might conflict with our bit card BUT keep title */
                .bitstream-single-bit .entry-footer:not(.bitstream-single-wrapper .entry-footer),
                .bitstream-single-bit .post-navigation,
                .bitstream-single-bit .author-info {
                    display: none !important;
                }
                
                /* Keep and style the entry title for SEO and navigation */
                .bitstream-single-bit .entry-header {
                    margin-bottom: 2rem !important;
                    border-bottom: 1px solid #eee !important;
                    padding-bottom: 1rem !important;
                }
                
                .bitstream-single-bit .entry-title,
                .bitstream-single-bit .entry-header .entry-title {
                    display: block !important;
                    font-size: 1.8rem !important;
                    margin-bottom: 0.5rem !important;
                    color: #333 !important;
                    line-height: 1.3 !important;
                }
                
                /* Show post meta using theme's default styling for better integration */
                .bitstream-single-bit .entry-meta,
                .bitstream-single-bit .entry-header .entry-meta {
                    display: block !important;
                }
                
                /* Hide other meta elements but keep date */
                .bitstream-single-bit .post-meta,
                .bitstream-single-bit .wp-block-post-author,
                .bitstream-single-bit .wp-block-post-terms {
                    display: none !important;
                }
                
                /* Allow wp-block-post-date to show using theme's default styling */
                .bitstream-single-bit .wp-block-post-date {
                    display: block !important;
                }
                
                /* Hide only empty content that's not our wrapper */
                .bitstream-single-bit .entry-content > p:empty,
                .bitstream-single-bit .entry-content > div:empty:not(.bitstream-single-wrapper) {
                    display: none !important;
                }
                
                /* Clean layout for BitStream content */
                .bitstream-single-bit .entry-content {
                    min-height: auto;
                }
                
                .bitstream-single-bit .site-main,
                .bitstream-single-bit .main-content {
                    padding-top: 2rem;
                }
                
                /* BitStream specific styles */
                .bitstream-single-wrapper { 
                    max-width: 800px; 
                    margin: 2rem auto; 
                    padding: 0 1rem; 
                    display: block !important;
                    visibility: visible !important;
                }
                
                .bitstream-back-link { 
                    display: inline-block; 
                    margin-bottom: 2rem; 
                    padding: 0.5rem 1rem; 
                    background: var(--wp--preset--color--accent-1, #2c6e49); 
                    color: white; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    transition: background-color 0.2s ease;
                    font-size: 0.9rem;
                }
                
                .bitstream-back-link:hover { 
                    background: var(--wp--preset--color--accent-2, #044389); 
                    color: white; 
                    text-decoration: none;
                }
                
                /* Ensure BitStream card displays properly - override masonry layout positioning */
                .bitstream-single-wrapper .bit-card {
                    position: static !important; /* Override masonry absolute positioning */
                    width: 100% !important; /* Full width for single display */
                    max-width: 100% !important;
                    margin: 0 auto !important;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    border: 1px solid #e1e1e1;
                    display: block !important;
                    visibility: visible !important;
                    background: white;
                    border-radius: 16px;
                    padding: 1.5rem;
                    transform: none !important; /* Remove masonry transforms */
                    left: auto !important;
                    top: auto !important;
                }
                    visibility: visible !important;
                }
                
                /* Ensure all bit card elements are visible and properly styled */
                .bitstream-single-wrapper .bit-card * {
                    display: revert !important;
                    visibility: visible !important;
                }
                
                /* Style the bit card header */
                .bitstream-single-wrapper .bit-card-header {
                    display: flex !important;
                    align-items: flex-start !important;
                    margin-bottom: 1rem !important;
                    gap: 0.75rem !important;
                }
                
                .bitstream-single-wrapper .bit-avatar {
                    flex-shrink: 0 !important;
                    width: 48px !important;
                    height: 48px !important;
                    border-radius: 15px !important;
                    overflow: hidden !important;
                }
                
                .bitstream-single-wrapper .bit-avatar img {
                    width: 100% !important;
                    height: 100% !important;
                    object-fit: cover !important;
                }
                
                .bitstream-single-wrapper .bit-author-info h3 {
                    margin: 0 !important;
                    font-size: 1rem !important;
                    font-weight: 600 !important;
                    color: #333 !important;
                }
                
                .bitstream-single-wrapper .bit-timestamp {
                    font-size: 0.875rem !important;
                    color: #666 !important;
                    margin: 0 !important;
                }
                
                /* Style the bit card content */
                .bitstream-single-wrapper .bit-card-content {
                    margin: 1rem 0 !important;
                    line-height: 1.6 !important;
                    color: #333 !important;
                }
                
                /* Style the bit card footer */
                .bitstream-single-wrapper .bit-card-footer {
                    display: flex !important;
                    gap: 1rem !important;
                    font-size: 0.875rem !important;
                    align-items: center !important;
                    margin-top: 1rem !important;
                    padding-top: 1rem !important;
                    border-top: 1px solid #eee !important;
                }
                
                .bitstream-single-wrapper .bit-action {
                    background: none !important;
                    border: none !important;
                    cursor: pointer !important;
                    color: #666 !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: 0.25rem !important;
                    font-size: 0.875rem !important;
                    transition: color 0.2s ease !important;
                }
                
                .bitstream-single-wrapper .bit-action:hover {
                    color: #2c6e49 !important;
                }
                
                /* Handle theme-specific containers */
                .bitstream-single-bit .content-area {
                    max-width: none;
                }
                
                /* Clean up article styling but don't hide everything */
                .bitstream-single-bit article:not(.bit-card) {
                    margin: 0;
                    padding: 0;
                }
                
                /* Ensure content is visible */
                .bitstream-single-bit .entry-content .bitstream-single-wrapper {
                    display: block !important;
                    opacity: 1 !important;
                    visibility: visible !important;
                }
            </style>
        <?php
        }
    }

    /**
     * Save ReBit OpenGraph data when post is saved
     */
    public function save_rebit_og_data($post_id)
    {
        // Check if this is a ReBit (has a rebit_url)
        $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);

        if (empty($rebit_url)) {
            // Not a ReBit, clean up any existing OG data
            delete_post_meta($post_id, '_bitstream_og_title');
            delete_post_meta($post_id, '_bitstream_og_desc');
            delete_post_meta($post_id, '_bitstream_og_image');
            return;
        }

        // Check if we already have OG data for this URL
        $existing_title = get_post_meta($post_id, '_bitstream_og_title', true);
        if (!empty($existing_title)) {
            return; // Already has OG data, probably from AJAX fetch
        }

        // If we reach here, it means we have a ReBit URL but no OG data
        // This could happen if the post was saved without using the block editor
        // In this case, we'll fetch the data synchronously
        if (class_exists('BitStream_Ajax_Handlers')) {
            $ajax_handler = new BitStream_Ajax_Handlers();

            // Simulate the AJAX request data
            $_POST_backup = $_POST;
            $_POST = [
                'url' => $rebit_url,
                'post_id' => $post_id,
                'nonce' => wp_create_nonce('bitstream_og_fetch_nonce')
            ];

            // Temporarily allow this to run without AJAX context
            add_filter('wp_doing_ajax', '__return_true');

            // Capture the output and handle it
            ob_start();
            $ajax_handler->handle_fetch_og_data();
            $output = ob_get_clean();

            // Restore original POST data
            $_POST = $_POST_backup;
            remove_filter('wp_doing_ajax', '__return_true');
        }
    }

    /**
     * Display quoted content in posts
     */
    public function display_quoted_content($content)
    {
        global $post;
        static $already_rendered = [];

        if (!isset($post) || !is_object($post) || $post->post_type !== 'bit')
            return $content;
        if (!empty($GLOBALS['bitstream_is_rendering_card']))
            return $content;

        if (!empty($already_rendered[$post->ID]))
            return $content;
        if (!empty($GLOBALS['bitstream_is_rendering_quote']))
            return $content;

        $quoted_id = get_post_meta($post->ID, '_bitstream_quoted_bit', true);
        if ($quoted_id) {
            $nested_card = function_exists('bitstream_render_nested_quoted_card')
                ? bitstream_render_nested_quoted_card($quoted_id)
                : '';

            if (empty($nested_card)) {
                $quoted_post = get_post($quoted_id);
                if ($quoted_post) {
                    $header = '<div style="color:var(--wp--preset--color--accent-1,#2c6e49);font-weight:600;margin-bottom:8px;">'
                        . $this->format_quoted_date($quoted_id) . '</div>';
                    $quoted_content = wpautop($quoted_post->post_content);
                    $quoted_content = preg_replace('/<!--\s*wp:.*?\/-->/s', '', $quoted_content);
                    $rich_preview = $this->render_og_card($quoted_id);
                    $nested_card = $header . $quoted_content . $rich_preview;
                }
            }

            if (!empty($nested_card)) {
                $quoted_box = '<div class="bitstream-quoted-preview">' . $nested_card . '</div>';
                $GLOBALS['bitstream_is_rendering_quote'] = true;
                $content = $content . $quoted_box; // Put quoted content after new content (social media style)
                unset($GLOBALS['bitstream_is_rendering_quote']);
            }
        }
        $already_rendered[$post->ID] = true;
        return $content;
    }

    /**
     * Format quoted date
     */
    private function format_quoted_date($post_id)
    {
        $date = get_the_date('', $post_id);
        $time = get_the_time('', $post_id);
        $author = get_the_author_meta('display_name', get_post_field('post_author', $post_id));
        return sprintf(esc_html__('%s · Posted on %s at %s', 'bitstream'), esc_html($author), $date, $time);
    }

    /**
     * Render OG card for quoted content
     */
    private function render_og_card($post_id)
    {
        $url = get_post_meta($post_id, 'bitstream_rebit_url', true);
        $title = get_post_meta($post_id, '_bitstream_og_title', true);
        $desc = get_post_meta($post_id, '_bitstream_og_desc', true);
        $img = get_post_meta($post_id, '_bitstream_og_image', true);

        if (!$url && !$title && !$desc && !$img)
            return '';

        $card = '<div class="bitstream-og-card" style="display:flex;gap:16px;align-items:flex-start;margin-top:14px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.09);padding:14px;">';

        if ($img) {
            $card .= '<div class="bitstream-og-thumb" style="min-width:72px;width:72px;height:72px;background:#eee;border-radius:8px;overflow:hidden;display:flex;align-items:center;"><img src="' . esc_url($img) . '" alt="" style="width:100%;height:auto;max-height:72px;object-fit:cover;display:block;"></div>';
        }

        $card .= '<div class="bitstream-og-meta" style="flex:1 1 0%;">';
        if ($title) {
            $card .= '<div class="bitstream-og-title" style="font-weight:600;font-size:1em;line-height:1.25;margin-bottom:4px;"><a href="' . esc_url($url) . '" target="_blank" style="color:inherit;text-decoration:none;">' . esc_html($title) . '</a></div>';
        }
        elseif ($url) {
            $card .= '<div class="bitstream-og-title"><a href="' . esc_url($url) . '" target="_blank" style="color:inherit;text-decoration:none;">' . esc_html($url) . '</a></div>';
        }
        if ($desc) {
            $card .= '<div class="bitstream-og-desc" style="font-size:0.97em;line-height:1.5;color:#444;margin-top:2px;">' . esc_html($desc) . '</div>';
        }
        $card .= '<div class="bitstream-og-url" style="font-size:0.92em;color:var(--wp--preset--color--accent-1,#2c6e49);overflow-wrap:anywhere;word-break:break-all;margin-top:6px;"><a href="' . esc_url($url) . '" target="_blank" style="color:var(--wp--preset--color--accent-1,#2c6e49);text-decoration:underline;word-break:break-all;">' . esc_html($url) . '</a></div>';
        $card .= '</div></div>';

        return $card;
    }

    /**
     * Convert #hashtag text in bit content to clickable filter links.
     */
    public function linkify_hashtags($content)
    {
        global $post;

        // Only process bit post type content
        if (!isset($post) || !is_object($post) || $post->post_type !== 'bit') {
            return $content;
        }

        // Skip inside admin / block editor
        if (is_admin()) {
            return $content;
        }

        // Resolve the feed page URL for hashtag links
        $feed_url = home_url('/bitstream/');

        // Split content by HTML tags so we only process text nodes
        $parts = preg_split('/(<[^>]*>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inside_anchor = 0;

        foreach ($parts as &$part) {
            // Track anchor nesting
            if (preg_match('/<a\s/i', $part)) {
                $inside_anchor++;
                continue;
            }
            if (preg_match('/<\/a>/i', $part)) {
                $inside_anchor--;
                continue;
            }
            // Skip HTML tags and anything inside anchors
            if ($part !== '' && $part[0] === '<') {
                continue;
            }
            if ($inside_anchor > 0) {
                continue;
            }

            // Replace #hashtag patterns in text nodes
            $part = preg_replace_callback(
                '/(?<=\s|^|>|\()#([A-Za-z][A-Za-z0-9_\x{00C0}-\x{024F}]*)/u',
                function ($matches) use ($feed_url) {
                $tag = $matches[1];
                $url = add_query_arg('bitstream_hashtag', rawurlencode($tag), $feed_url);
                return '<a class="bitstream-hashtag-link" href="' . esc_url($url) . '">#' . esc_html($tag) . '</a>';
            },
                $part
            );
        }
        unset($part);

        return implode('', $parts);
    }

    /**
     * Extract all hashtags from published bit content with counts.
     *
     * Returns an associative array of tag => count, sorted descending.
     * Results are cached via a transient for 1 hour.
     */
    public static function get_hashtag_counts()
    {
        $cached = get_transient('bitstream_hashtag_counts');
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'bit' AND post_status = 'publish'"
        );

        $counts = [];
        foreach ($rows as $body) {
            // Strip HTML so tags inside attributes are ignored
            $text = wp_strip_all_tags($body);
            if (preg_match_all('/(?<=\s|^)#([A-Za-z][A-Za-z0-9_\x{00C0}-\x{024F}]*)/u', $text, $m)) {
                foreach ($m[1] as $tag) {
                    $lower = mb_strtolower($tag, 'UTF-8');
                    if (!isset($counts[$lower])) {
                        $counts[$lower] = ['display' => $tag, 'count' => 0];
                    }
                    $counts[$lower]['count']++;
                }
            }
        }

        // Sort by count descending
        uasort($counts, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        // Build simple tag => count map using first-seen casing
        $result = [];
        foreach ($counts as $data) {
            $result[$data['display']] = $data['count'];
        }

        set_transient('bitstream_hashtag_counts', $result, HOUR_IN_SECONDS);
        return $result;
    }

    /**
     * Invalidate the hashtag counts cache when a bit is saved.
     */
    public static function flush_hashtag_cache($post_id)
    {
        if (get_post_type($post_id) === 'bit') {
            delete_transient('bitstream_hashtag_counts');
        }
    }
}
