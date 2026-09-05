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
        add_filter('the_content', [$this, 'refresh_media_cache_busters'], 30);
        add_action('save_post_bit', [$this, 'save_rebit_og_data']);
        add_action('save_post_bit', [$this, 'save_post_hashtags'], 10, 2);
    }

    /**
     * Update any BitStream image cache busters in content to match latest attachment timestamps.
     */
    public function refresh_media_cache_busters($content)
    {
        if (empty($content) || !strpos($content, 'wp-image-')) {
            return $content;
        }

        // Robust attribute-order independent regex for <img> tags with wp-image-ID class
        return preg_replace_callback('/<img\s+([^>]+)>/i', function($matches) {
            $attrs_str = $matches[1];
            $attachment_id = 0;
            
            // Extract attachment ID from class
            if (!preg_replace_callback('/class=["\'][^"\']*wp-image-([0-9]+)[^"\']*["\']/i', function($m) use (&$attachment_id) {
                $attachment_id = intval($m[1]);
                return $m[0];
            }, $attrs_str)) {
                // If no wp-image-ID class found, return original tag
                if (!$attachment_id) return $matches[0];
            }

            if (!$attachment_id) return $matches[0];

            // Extract SRC
            $src = '';
            preg_match('/src=["\']([^"\']+)["\']/i', $attrs_str, $src_matches);
            if (empty($src_matches[1])) return $matches[0];
            
            $src = $src_matches[1];
            $url_base = explode('?t=', $src)[0];
            $latest_buster = get_post_modified_time('U', false, $attachment_id);
            
            // Replace the src attribute with the fresh buster
            $new_src_attr = 'src="' . $url_base . '?t=' . $latest_buster . '"';
            $new_attrs_str = preg_replace('/src=["\']([^"\']+)["\']/i', $new_src_attr, $attrs_str);
            
            return '<img ' . $new_attrs_str . '>';
        }, $content);
    }

    /**
     * Handle single bit post display using theme template
     */
    public function handle_single_bit_display()
    {
        global $post;

        if (is_single() && $post && $post->post_type === 'bit') {
            // Ensure assets are loaded with proper priority
            wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/css/bitstream.css'));
            wp_enqueue_script('bitstream-js');
            if (class_exists('BitStream_Ajax_Handlers')) {
                wp_localize_script('bitstream-js', 'bitstream_ajax', array_merge(BitStream_Ajax_Handlers::get_localized_data(), [
                    'post_id' => $post->ID
                ]));
            }

            // Add body class for better targeting
            add_filter('body_class', function ($classes) {
                $classes[] = 'bitstream-single-bit';
                return $classes;
            });

            // Use the theme's page template by filtering the content
            add_filter('the_content', [$this, 'single_bit_content']);
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
                 <?php echo self::render_card($post->ID, true); ?>
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

        // If we reach here, it means we have a ReBit URL but no OG data.
        // Fetch OG data synchronously without fake AJAX or process termination.
        if (class_exists('BitStream_OG_Fetcher')) {
            $fetcher = new BitStream_OG_Fetcher();
            $og_data = $fetcher->fetch_og_data($rebit_url);
            if (is_array($og_data)) {
                if (!empty($og_data['title'])) {
                    update_post_meta($post_id, '_bitstream_og_title', sanitize_text_field($og_data['title']));
                }
                if (!empty($og_data['description'])) {
                    update_post_meta($post_id, '_bitstream_og_desc', sanitize_textarea_field($og_data['description']));
                }
                if (!empty($og_data['image'])) {
                    update_post_meta($post_id, '_bitstream_og_image', esc_url_raw($og_data['image']));
                }
                update_post_meta($post_id, '_bitstream_og_fetched', time());
            }
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
            $nested_card = self::render_nested_quoted_card($quoted_id);

            if (empty($nested_card)) {
                $quoted_post = get_post($quoted_id);
                if ($quoted_post) {
                    $header = '<div style="color:var(--wp--preset--color--accent-1,#2c6e49);font-weight:600;margin-bottom:8px;">'
                        . $this->format_quoted_date($quoted_id) . '</div>';
                    $quoted_content = wpautop($quoted_post->post_content);
                    $quoted_content = preg_replace('/<!--\s*wp:.*?\/-->/s', '', $quoted_content);
                    $quoted_content = $this->linkify_hashtags($quoted_content);
                    $rich_preview = $this->render_og_card($quoted_id);
                    $nested_card = $header . $quoted_content . $rich_preview;
                }
            }

            if (!empty($nested_card)) {
                $quoted_box = '<div class="bitstream-quoted-preview" data-permalink="' . esc_url(add_query_arg('highlight_bit', $quoted_id, home_url('/bitstream/'))) . '">' . $nested_card . '</div>';
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

        $card = '<div class="bitstream-og-card">';

        if ($img) {
            $card .= '<div class="bitstream-og-thumb"><img src="' . esc_url($img) . '" alt=""></div>';
        }

        $card .= '<div class="bitstream-og-meta">';
        if ($title) {
            $card .= '<div class="bitstream-og-title"><a href="' . esc_url($url) . '" target="_blank">' . esc_html($title) . '</a></div>';
        }
        elseif ($url) {
            $card .= '<div class="bitstream-og-title"><a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></div>';
        }
        if ($desc) {
            $card .= '<div class="bitstream-og-desc">' . esc_html($desc) . '</div>';
        }
        $card .= '<div class="bitstream-og-url"><a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></div>';
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
        if (is_admin() && !wp_doing_ajax()) {
            return $content;
        }

        // Build base URL with current filters
        $feed_url = home_url('/bitstream/');

        $requested_type = isset($_GET['bitstream_type']) ? sanitize_key(wp_unslash($_GET['bitstream_type'])) : '';
        $requested_month = isset($_GET['bitstream_month']) ? sanitize_text_field(wp_unslash($_GET['bitstream_month'])) : '';
        $requested_search = isset($_GET['bitstream_search']) ? sanitize_text_field(wp_unslash($_GET['bitstream_search'])) : '';

        if (!empty($requested_type) && $requested_type !== 'all') {
            $feed_url = add_query_arg('bitstream_type', $requested_type, $feed_url);
        }
        if (!empty($requested_month)) {
            $feed_url = add_query_arg('bitstream_month', $requested_month, $feed_url);
        }
        if (!empty($requested_search)) {
            $feed_url = add_query_arg('bitstream_search', $requested_search, $feed_url);
        }

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
     * Format and linkify external embed text (URLs, @mentions, #hashtags).
     *
     * @param string $text Raw text from external post
     * @param string $platform 'twitter' or 'instagram'
     * @return string Safe HTML with clickable links
     */
    public static function format_embed_text($text, $platform = 'twitter')
    {
        if (empty($text)) {
            return '';
        }

        // Step 1: Escape HTML entities to prevent XSS
        $safe_text = esc_html($text);

        // Step 2: Convert URLs to clickable links
        $url_pattern = '~(?:https?://|www\.)[^\s<]+~i';
        $safe_text = preg_replace_callback($url_pattern, static function ($matches) {
            $raw_url = $matches[0];
            $trailing_punct = '';

            // Strip trailing punctuation from URL (. , : ; ! ? ) ] ' ")
            while (preg_match('/[.,;:!?)\]\'">]+$/', $raw_url, $pm)) {
                $trailing_punct = $pm[0] . $trailing_punct;
                $raw_url = substr($raw_url, 0, -strlen($pm[0]));
            }

            $clean_href = html_entity_decode($raw_url, ENT_QUOTES, 'UTF-8');
            if (stripos($clean_href, 'http') !== 0) {
                $clean_href = 'https://' . $clean_href;
            }

            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="bit-tweet-link">%s</a>%s',
                esc_url($clean_href),
                $raw_url,
                $trailing_punct
            );
        }, $safe_text);

        // Step 3: Parse @mentions (outside already-created <a> tags)
        $mention_pattern = '~/a>(*SKIP)(*FAIL)|(?<=^|\s)@([a-zA-Z0-9_]{1,30})\b~';
        $safe_text = preg_replace_callback($mention_pattern, static function ($matches) use ($platform) {
            $handle = $matches[1];
            $mention_url = ($platform === 'instagram')
                ? 'https://www.instagram.com/' . rawurlencode($handle) . '/'
                : 'https://x.com/' . rawurlencode($handle);
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="bit-tweet-mention">@%s</a>',
                esc_url($mention_url),
                esc_html($handle)
            );
        }, $safe_text);

        // Step 4: Parse #hashtags (outside already-created <a> tags)
        $hashtag_pattern = '~/a>(*SKIP)(*FAIL)|(?<=^|\s)#([a-zA-Z0-9_\x{0080}-\x{FFFF}]+)\b~u';
        $safe_text = preg_replace_callback($hashtag_pattern, static function ($matches) use ($platform) {
            $tag = $matches[1];
            $tag_url = ($platform === 'instagram')
                ? 'https://www.instagram.com/explore/tags/' . rawurlencode($tag) . '/'
                : 'https://x.com/hashtag/' . rawurlencode($tag);
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="bit-tweet-hashtag">#%s</a>',
                esc_url($tag_url),
                esc_html($tag)
            );
        }, $safe_text);

        return nl2br($safe_text);
    }

    /**
     * Parse and save hashtags for a bit post.
     */
    public function save_post_hashtags($post_id, $post = null)
    {
        if (!$post || !is_object($post)) {
            $post = get_post($post_id);
        }
        if (!$post || wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') {
            return;
        }

        delete_post_meta($post_id, '_bitstream_hashtag');

        $content = $post->post_content;
        if (empty($content)) {
            return;
        }

        $text = wp_strip_all_tags($content);
        if (preg_match_all('/(?<=\s|^)#([A-Za-z][A-Za-z0-9_\x{00C0}-\x{024F}]*)/u', $text, $m)) {
            $unique_tags = array_unique($m[1]);
            foreach ($unique_tags as $tag) {
                add_post_meta($post_id, '_bitstream_hashtag', $tag);
            }
        }
    }

    /**
     * One-time indexing of hashtags for existing bits.
     */
    private static function index_existing_hashtags()
    {
        global $wpdb;
        $posts = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'bit'");
        if (!empty($posts)) {
            foreach ($posts as $post) {
                delete_post_meta($post->ID, '_bitstream_hashtag');
                $text = wp_strip_all_tags($post->post_content);
                if (preg_match_all('/(?<=\s|^)#([A-Za-z][A-Za-z0-9_\x{00C0}-\x{024F}]*)/u', $text, $m)) {
                    $unique_tags = array_unique($m[1]);
                    foreach ($unique_tags as $tag) {
                        add_post_meta($post->ID, '_bitstream_hashtag', $tag);
                    }
                }
            }
        }
        update_option('bitstream_hashtags_indexed', '1');
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

        $indexed = get_option('bitstream_hashtags_indexed');
        if (!$indexed) {
            self::index_existing_hashtags();
        }

        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT pm.meta_value AS tag, COUNT(p.ID) AS count
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_bitstream_hashtag'
               AND p.post_type = 'bit'
               AND p.post_status = 'publish'
             GROUP BY pm.meta_value
             ORDER BY count DESC"
        );

        $result = [];
        if (!empty($results)) {
            foreach ($results as $row) {
                $result[$row->tag] = intval($row->count);
            }
        }

        set_transient('bitstream_hashtag_counts', $result, HOUR_IN_SECONDS);
        return $result;
    }

    /**
     * Get counts of active emotions from published bits.
     *
     * Returns an array of arrays containing 'emotion', 'emoji', and 'count'.
     * Cached via transient.
     */
    public static function get_emotion_counts()
    {
        $cached = get_transient('bitstream_emotion_counts');
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT pm_emotion.meta_value AS emotion, pm_emoji.meta_value AS emoji, COUNT(p.ID) AS count
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_emotion ON p.ID = pm_emotion.post_id AND pm_emotion.meta_key = '_bitstream_mood_emotion'
             LEFT JOIN {$wpdb->postmeta} pm_emoji ON p.ID = pm_emoji.post_id AND pm_emoji.meta_key = '_bitstream_mood_emoji'
             WHERE p.post_type = 'bit' AND p.post_status = 'publish' AND pm_emotion.meta_value != ''
             GROUP BY pm_emotion.meta_value, pm_emoji.meta_value
             ORDER BY count DESC"
        );

        $emotions = [];
        foreach ($results as $row) {
            $emotion = trim($row->emotion);
            $emoji = trim($row->emoji);
            $count = intval($row->count);
            
            $key = mb_strtolower($emotion, 'UTF-8');
            if (isset($emotions[$key])) {
                $emotions[$key]['count'] += $count;
            } else {
                $emotions[$key] = [
                    'emotion' => $emotion,
                    'emoji'   => $emoji,
                    'count'   => $count
                ];
            }
        }

        // Sort by count descending
        uasort($emotions, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        set_transient('bitstream_emotion_counts', $emotions, HOUR_IN_SECONDS);
        return $emotions;
    }

    /**
     * Invalidate the hashtag and emotion counts cache when a bit is saved.
     */
    public static function flush_hashtag_cache($post_id)
    {
        if (get_post_type($post_id) === 'bit') {
            delete_transient('bitstream_hashtag_counts');
            delete_transient('bitstream_emotion_counts');
        }
    }

    /**
     * Render ReBit media/preview section for a card
     *
     * @param int $post_id
     * @return string
     */
    public static function render_rebit_section($post_id)
    {
        $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
        if (empty($rebit_url)) {
            return '';
        }

        $parsed = parse_url($rebit_url);
        $host = $parsed['host'] ?? '';

        ob_start();

        $map = BitStream_ReBit_Mappings::get_mapping_for_domain($host, $rebit_url);
        if ($map) {
            echo '<div class="bit-rebit-label">'
                . '<i class="' . esc_attr($map['icon']) . '" aria-hidden="true"></i> '
                . esc_html($map['label'])
                . '</div>';
        }
        elseif (stripos($host, 'youtube.com') !== false || stripos($host, 'youtu.be') !== false || stripos($host, 'youtube-nocookie.com') !== false) {
            echo '<div class="bit-rebit-label">'
                . '<i class="fab fa-youtube" aria-hidden="true"></i>'
                . 'shared a video</div>';
        }
        else {
            echo '<div class="bit-rebit-label">'
                . '<i class="fas fa-link" aria-hidden="true"></i> shared a link</div>';
        }

        $is_yt = stripos($host, 'youtube.com') !== false || stripos($host, 'youtu.be') !== false || stripos($host, 'youtube-nocookie.com') !== false;
        $is_twitter = stripos($host, 'twitter.com') !== false || stripos($host, 'x.com') !== false;
        $is_instagram = stripos($host, 'instagram.com') !== false;

        if ($is_yt) {
            $video_id = '';
            if (stripos($host, 'youtu.be') !== false) {
                $video_id = ltrim($parsed['path'] ?? '', '/');
            }
            elseif (isset($parsed['query'])) {
                parse_str($parsed['query'], $args);
                $video_id = $args['v'] ?? '';
            }

            if ($video_id) {
                echo '<div class="bit-rebit-embed">'
                    . '<iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" '
                    . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                    . 'allowfullscreen></iframe></div>';
            }
            else {
                echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" class="bit-rebit-link">' . esc_html($rebit_url) . '</a>';
            }
        }
        elseif ($is_twitter) {
            $embed_html = get_post_meta($post_id, '_bitstream_rebit_embed_html', true);
            $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
            $og_desc = get_post_meta($post_id, '_bitstream_og_desc', true);
            $og_img = get_post_meta($post_id, '_bitstream_og_image', true);
            $og_avatar = get_post_meta($post_id, '_bitstream_og_avatar', true);
            $og_images = get_post_meta($post_id, '_bitstream_og_images', true);
            $clean_url = preg_replace('/\?.*$/', '', $rebit_url);

            // Backward compatibility: If avatar is not set, check if og_image was an avatar
            if (empty($og_avatar) && !empty($og_img)) {
                if (strpos($og_img, 'unavatar.io') !== false || strpos($og_img, '/profile_images/') !== false) {
                    $og_avatar = $og_img;
                    $og_img = '';
                }
            }

            $images = !empty($og_images) && is_array($og_images) ? $og_images : (!empty($og_img) ? [$og_img] : []);

            if (empty($embed_html) || empty($og_desc) || empty($og_avatar)) {
                if (class_exists('BitStream_OG_Fetcher')) {
                    $fetcher = new BitStream_OG_Fetcher();
                    $fetched = $fetcher->fetch_og_data($clean_url);
                    if (is_array($fetched)) {
                        if (!empty($fetched['embed_html']) && empty($embed_html)) {
                            $embed_html = $fetched['embed_html'];
                            update_post_meta($post_id, '_bitstream_rebit_embed_html', $embed_html);
                        }
                        if (!empty($fetched['description']) && empty($og_desc)) {
                            $og_desc = $fetched['description'];
                            update_post_meta($post_id, '_bitstream_og_desc', $og_desc);
                        }
                        if (!empty($fetched['title']) && empty($og_title)) {
                            $og_title = $fetched['title'];
                            update_post_meta($post_id, '_bitstream_og_title', $og_title);
                        }
                        if (!empty($fetched['avatar']) && empty($og_avatar)) {
                            $og_avatar = $fetched['avatar'];
                            update_post_meta($post_id, '_bitstream_og_avatar', $og_avatar);
                        }
                        if (!empty($fetched['image']) && empty($og_img)) {
                            $og_img = $fetched['image'];
                            update_post_meta($post_id, '_bitstream_og_image', $og_img);
                        }
                        if (!empty($fetched['images']) && empty($og_images)) {
                            $og_images = $fetched['images'];
                            update_post_meta($post_id, '_bitstream_og_images', $og_images);
                        }
                        $images = !empty($og_images) && is_array($og_images) ? $og_images : (!empty($og_img) ? [$og_img] : []);
                    }
                }
            }

            $author_name = preg_replace('/\s+on\s+(X|Twitter)$/i', '', $og_title ?: 'Post on X');
            $handle = '';
            if (preg_match('#(?:twitter\.com|x\.com)/([a-zA-Z0-9_]+)#i', $clean_url, $hm)) {
                $handle = '@' . $hm[1];
            }

            // Derive date and time from Snowflake ID or oEmbed HTML
            $tweet_time_date = '';
            if (preg_match('#/status/(\d+)#i', $clean_url, $sm)) {
                $tid = $sm[1];
                $ts = (floatval($tid) / 4194304) + 1288834974657;
                if ($ts > 1288834974657) {
                    $tweet_time_date = gmdate('g:i A · M j, Y', intval($ts / 1000));
                }
            }
            if (empty($tweet_time_date) && !empty($embed_html)) {
                if (preg_match('#<a[^>]*>([^<]+)</a>\s*</blockquote>#i', $embed_html, $dm)) {
                    $tweet_time_date = trim($dm[1]);
                }
            }

            $user_profile_url = $handle ? 'https://x.com/' . esc_attr(ltrim($handle, '@')) : esc_url($clean_url);

            echo '<div class="bit-rebit-embed-twitter">'
                . '<div class="bit-tweet-card">'
                . '<div class="bit-tweet-header">'
                . '<a href="' . esc_url($user_profile_url) . '" target="_blank" rel="noopener" class="bit-tweet-user-link">'
                . ($og_avatar ? '<img src="' . esc_url($og_avatar) . '" class="bit-tweet-avatar" alt="' . esc_attr($author_name) . '" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'flex\';"><div class="bit-tweet-avatar-placeholder" style="display:none;"><i class="fa-brands fa-x-twitter"></i></div>' : '<div class="bit-tweet-avatar-placeholder"><i class="fa-brands fa-x-twitter"></i></div>')
                . '<div class="bit-tweet-meta">'
                . '<div class="bit-tweet-author">' . esc_html($author_name) . '</div>'
                . ($handle ? '<div class="bit-tweet-handle">' . esc_html($handle) . '</div>' : '')
                . '</div>'
                . '</a>'
                . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-tweet-x-logo" aria-label="View on X">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true" class="bit-tweet-x-icon"><g><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"></path></g></svg>'
                . '</a>'
                . '</div>'
                . '<div class="bit-tweet-body">'
                . (!empty($og_desc) ? '<p class="bit-tweet-text">' . self::format_embed_text($og_desc, 'twitter') . '</p>' : '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-rebit-link">' . esc_html($clean_url) . '</a>')
                . '</div>';

            // Render tweet media / images if present
            if (!empty($images)) {
                $img_count = count($images);
                if ($img_count === 1) {
                    echo '<div class="bit-tweet-media">'
                        . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-tweet-media-link">'
                        . '<img src="' . esc_url($images[0]) . '" class="bit-tweet-thumb" alt="' . esc_attr($author_name) . '" loading="lazy" referrerpolicy="no-referrer">'
                        . '</a>'
                        . '</div>';
                } else {
                    $grid_count = min(4, $img_count);
                    echo '<div class="bit-tweet-gallery bit-tweet-gallery-' . $grid_count . '">';
                    foreach (array_slice($images, 0, 4) as $idx => $img_url) {
                        echo '<div class="bit-tweet-gallery-item">'
                            . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-tweet-media-link">'
                            . '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($author_name) . '" loading="lazy" referrerpolicy="no-referrer">'
                            . '</a>'
                            . '</div>';
                    }
                    echo '</div>';
                }
            }

            echo ($tweet_time_date ? '<div class="bit-tweet-timestamp"><a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener">' . esc_html($tweet_time_date) . '</a></div>' : '')
                . '<div class="bit-tweet-footer">'
                . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-tweet-pill-btn">'
                . '<span>Read on X</span>'
                . '</a>'
                . '</div>'
                . '</div>'
                . '</div>';
        }
        elseif ($is_instagram) {
            $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
            $og_desc = get_post_meta($post_id, '_bitstream_og_desc', true);
            $og_img = get_post_meta($post_id, '_bitstream_og_image', true);
            $og_avatar = get_post_meta($post_id, '_bitstream_og_avatar', true);
            if (strpos($og_avatar, 'unavatar.io') !== false) {
                $og_avatar = '';
                delete_post_meta($post_id, '_bitstream_og_avatar');
                delete_transient('bitstream_og_' . md5($clean_url));
                delete_transient('bitstream_og_' . md5($rebit_url));
            }
            $clean_url = preg_replace('/\?.*$/', '', $rebit_url);

            if ((empty($og_title) || empty($og_avatar)) && class_exists('BitStream_OG_Fetcher')) {
                $fetcher = new BitStream_OG_Fetcher();
                $fetched = $fetcher->fetch_og_data($clean_url);
                if (is_array($fetched)) {
                    if (!empty($fetched['title']) && empty($og_title)) {
                        $og_title = $fetched['title'];
                        update_post_meta($post_id, '_bitstream_og_title', $og_title);
                    }
                    if (!empty($fetched['description']) && empty($og_desc)) {
                        $og_desc = $fetched['description'];
                        update_post_meta($post_id, '_bitstream_og_desc', $og_desc);
                    }
                    if (!empty($fetched['image']) && empty($og_img)) {
                        $og_img = $fetched['image'];
                        update_post_meta($post_id, '_bitstream_og_image', $og_img);
                    }
                    if (!empty($fetched['avatar']) && empty($og_avatar)) {
                        $og_avatar = $fetched['avatar'];
                        update_post_meta($post_id, '_bitstream_og_avatar', $og_avatar);
                    }
                }
            }

            // Determine type: Post, Reel, or Story
            $parsed_path = trim(wp_parse_url($clean_url, PHP_URL_PATH) ?? '', '/');
            $path_parts = explode('/', $parsed_path);
            $type_label = 'Post';
            $username = '';

            if (!empty($path_parts[0])) {
                if (in_array($path_parts[0], ['reel', 'reels'], true)) {
                    $type_label = 'Reel';
                } elseif ($path_parts[0] === 'stories') {
                    $type_label = 'Story';
                    $username = $path_parts[1] ?? '';
                } elseif ($path_parts[0] === 'p') {
                    $type_label = 'Post';
                } else {
                    $username = $path_parts[0];
                }
            }

            if (empty($username) && !empty($og_title)) {
                if (preg_match('/@([a-zA-Z0-9._]+)/', $og_title, $um)) {
                    $username = $um[1];
                }
            }

            if ($type_label === 'Story') {
                if (empty($og_avatar) && !empty($og_img)) {
                    $og_avatar = $og_img;
                    update_post_meta($post_id, '_bitstream_og_avatar', $og_avatar);
                }
                $og_img = ''; // Do not display duplicate profile picture in story body
            }

            if (empty($og_avatar) && !empty($username)) {
                $prof_args = [
                    'timeout' => 8,
                    'redirection' => 3,
                    'user-agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                    'headers' => [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.5',
                    ]
                ];
                $prof_resp = wp_safe_remote_get('https://www.instagram.com/' . rawurlencode($username) . '/', $prof_args);
                $p_code = wp_remote_retrieve_response_code($prof_resp);
                if (!is_wp_error($prof_resp) && $p_code >= 200 && $p_code < 400) {
                    $prof_html = wp_remote_retrieve_body($prof_resp);
                    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $prof_html, $pm)) {
                        $og_avatar = esc_url_raw(html_entity_decode(trim($pm[1]), ENT_QUOTES, 'UTF-8'));
                        update_post_meta($post_id, '_bitstream_og_avatar', $og_avatar);
                    }
                }
            }

            // Extract concise author display name
            $display_author = '';
            if (!empty($og_title)) {
                if (preg_match('/^Watch this story by\s+(.+?)(?:\s+on Instagram.*)?$/i', $og_title, $sm)) {
                    $display_author = trim($sm[1]);
                } elseif (preg_match('/^(.*?)\s+on Instagram/i', $og_title, $anm)) {
                    $display_author = trim($anm[1]);
                } else {
                    $display_author = trim(preg_replace('/\s*•.*$/', '', $og_title));
                }
            }
            if (empty($display_author)) {
                $display_author = $username ? '@' . $username : 'Instagram ' . $type_label;
            }

            // Extract posted date and clean caption
            $posted_date = '';
            $display_desc = $og_desc;
            if (!empty($og_desc)) {
                if (preg_match('/\bon\s+([A-Za-z]+\s+\d{1,2}(?:,\s+\d{4})?)/i', $og_desc, $dm)) {
                    $posted_date = trim($dm[1]);
                }
                if (preg_match('/:\s*["\']?(.*?)["\']?\s*$/s', $og_desc, $cm)) {
                    $display_desc = trim($cm[1]);
                    $display_desc = preg_replace('/^["\']|["\']$/', '', $display_desc);
                }
            }

            if ($type_label === 'Story') {
                if (strpos($display_desc, 'Followers') !== false || strpos($display_desc, 'Posts -') !== false) {
                    $display_desc = '';
                }
            }

            $initial_source = $username ?: $display_author;
            $initial = !empty($initial_source) ? strtoupper(mb_substr(ltrim($initial_source, '@'), 0, 1)) : 'I';

            $action_btn_text = 'View on Instagram';
            if ($type_label === 'Reel') {
                $action_btn_text = 'Watch Reel on Instagram';
            } elseif ($type_label === 'Story') {
                $action_btn_text = 'View Story on Instagram';
            }

            echo '<div class="bit-rebit-embed-instagram">'
                . '<div class="bit-instagram-card">'
                . '<div class="bit-instagram-header">'
                . '<a href="' . esc_url($username ? 'https://www.instagram.com/' . rawurlencode($username) . '/' : $clean_url) . '" target="_blank" rel="noopener" class="bit-instagram-user-link">'
                . '<div class="bit-instagram-avatar-ring">'
                . ($og_avatar ? '<img src="' . esc_url($og_avatar) . '" class="bit-instagram-avatar" alt="' . esc_attr($display_author) . '" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'flex\';"><div class="bit-instagram-avatar-placeholder" style="display:none;">' . esc_html($initial) . '</div>' : '<div class="bit-instagram-avatar-placeholder">' . esc_html($initial) . '</div>')
                . '</div>'
                . '<div class="bit-instagram-meta">'
                . '<div class="bit-instagram-author">' . esc_html($display_author) . '</div>'
                . '<div class="bit-instagram-badge">' . ($username && $display_author !== '@' . $username ? '@' . esc_html($username) . ' · ' : '') . '<i class="' . ($type_label === 'Reel' ? 'fa-solid fa-clapperboard' : ($type_label === 'Story' ? 'fa-solid fa-circle-notch' : 'fa-solid fa-camera')) . '"></i> ' . esc_html($type_label) . '</div>'
                . '</div>'
                . '</a>'
                . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-instagram-ig-logo" aria-label="View on Instagram">'
                . '<i class="fa-brands fa-instagram"></i>'
                . '</a>'
                . '</div>';

            if (!empty($og_img)) {
                echo '<div class="bit-instagram-media">'
                    . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-instagram-media-link">'
                    . '<img src="' . esc_url($og_img) . '" class="bit-instagram-thumb" alt="' . esc_attr($display_author) . '" loading="lazy" referrerpolicy="no-referrer">'
                    . ($type_label === 'Reel' ? '<div class="bit-instagram-play-overlay"><i class="fa-solid fa-play"></i></div>' : '')
                    . '</a>'
                    . '</div>';
            }

            if (!empty($display_desc)) {
                echo '<div class="bit-instagram-body">'
                    . '<p class="bit-instagram-text">' . self::format_embed_text($display_desc, 'instagram') . '</p>'
                    . '</div>';
            }

            if (!empty($posted_date)) {
                echo '<div class="bit-instagram-timestamp">'
                    . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener">' . esc_html($posted_date) . '</a>'
                    . '</div>';
            }

            echo '<div class="bit-instagram-footer">'
                . '<a href="' . esc_url($clean_url) . '" target="_blank" rel="noopener" class="bit-instagram-pill-btn">'
                . '<i class="fa-brands fa-instagram"></i> <span>' . esc_html($action_btn_text) . '</span>'
                . '</a>'
                . '</div>'
                . '</div>'
                . '</div>';
        }
        else {
            echo '<div class="bit-rebit-preview">';
            $og_img = get_post_meta($post_id, '_bitstream_og_image', true);
            if ($og_img) {
                echo '<img src="' . esc_url($og_img) . '" alt="">';
            }

            echo '<div class="bit-rebit-preview-body">';
            $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
            $og_desc = get_post_meta($post_id, '_bitstream_og_desc', true);
            if ($og_title) {
                echo '<h4 style="margin:0 0 0.5rem;font-size:1.1rem;">'
                    . '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener">' . wp_kses_post($og_title) . '</a></h4>';
            }
            if ($og_desc) {
                echo '<p style="margin:0;font-size:0.95rem;color:#555;">' . wp_kses_post($og_desc) . '</p>';
            }
            if (!$og_desc) {
                echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" class="bit-rebit-link">' . esc_html($rebit_url) . '</a>';
            }
            echo '</div></div>';
        }

        return ob_get_clean();
    }

    /**
     * Render a nested quoted Bit card without action buttons/comments
     *
     * @param int $post_id
     * @param int $depth
     * @return string
     */
    public static function render_nested_quoted_card($post_id, $depth = 0)
    {
        $quoted_post = get_post($post_id);
        if (!($quoted_post instanceof WP_Post) || $quoted_post->post_type !== 'bit' || $quoted_post->post_status !== 'publish') {
            ob_start();
        ?>
            <div id="bit-quoted-missing-<?php echo esc_attr($post_id); ?>" class="bit-card bit-card-quoted-nested bit-card-quoted-unavailable" style="margin:0;padding:1rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #ddd;border-radius:15px;background:#fff;">
                <div class="bit-card-content" style="font-size:0.95rem;line-height:1.5;margin:0;">
                    <p style="margin:0;color:var(--wp--preset--color--secondary,#666);">Original Bit unavailable.</p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $content = wpautop(get_post_field('post_content', $post_id));

        if (class_exists('BitStream_Content_Display')) {
            global $post;
            $original_post = $post;
            $post = $quoted_post;
            $display = new BitStream_Content_Display();
            $content = $display->linkify_hashtags($content);
            $post = $original_post;
        }

        $timestamp = human_time_diff(get_post_time('U', true, $post_id), time()) . ' ago';
        $posted_datetime = get_post_time('d/m/Y H:i', false, $post_id);
        $is_edited = get_post_modified_time('U', false, $post_id) > get_post_time('U', false, $post_id);
        $timestamp_tooltip = 'Posted: ' . $posted_datetime . ($is_edited ? ' • Edited' : '');
        $rebit_markup = self::render_rebit_section($post_id);

        // Retrieve quoted post author and mood details
        $author_id = get_post_field('post_author', $post_id);
        $author_name = get_the_author_meta('display_name', $author_id);
        $mood_emoji = get_post_meta($post_id, '_bitstream_mood_emoji', true);
        $mood_emotion = get_post_meta($post_id, '_bitstream_mood_emotion', true);
        
        // Check if the quoted post is a pure mood post
        $raw_content = trim(get_post_field('post_content', $post_id));
        $has_attachments = !empty(get_post_meta($post_id, '_bitstream_attachment_id', true)) || !empty(get_post_meta($post_id, '_bitstream_attachment_ids', true));
        $quoted_id = (int)get_post_meta($post_id, '_bitstream_quoted_bit', true);
        $is_rebit_card = !empty(get_post_meta($post_id, 'bitstream_rebit_url', true));
        $is_pure_mood = empty($raw_content) && !$has_attachments && ($quoted_id <= 0) && !$is_rebit_card && !empty($mood_emotion);

        $quoted_markup = '';
        if ($quoted_id > 0 && $depth < 1) {
            $quoted_markup = self::render_nested_quoted_card($quoted_id, $depth + 1);
        }

        ob_start();
        ?>
        <div id="bit-quoted-<?php echo esc_attr($post_id); ?>" class="bit-card bit-card-quoted-nested">
            <header class="bit-card-header">
                <div class="bit-meta">
                    <span class="bit-author-line">
                        <?php echo esc_html($author_name); ?>
                        <?php if (!empty($mood_emotion) && !$is_pure_mood): ?>
                            <span class="bit-mood-status">
                                is feeling <?php echo esc_html($mood_emoji); ?> <strong><?php echo esc_html($mood_emotion); ?></strong>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="bit-timestamp" title="<?php echo esc_attr($timestamp_tooltip); ?>" tabindex="0"><span class="bit-timestamp-relative"><?php echo esc_html($timestamp); ?></span><span class="bit-timestamp-full" style="display:none;"><span class="bit-timestamp-separator"> | </span><?php echo esc_html(get_post_time(get_option('date_format') . ' ' . get_option('time_format'), false, $post_id)); ?></span></span>
                </div>
            </header>

            <?php if ($is_pure_mood): ?>
                <div class="bit-card-content bit-card-pure-mood">
                    <span class="bit-pure-mood-emoji"><?php echo esc_html($mood_emoji); ?></span>
                    <span class="bit-pure-mood-text">
                        is feeling <strong><?php echo esc_html($mood_emotion); ?></strong>
                    </span>
                </div>
            <?php else: ?>
                <?php if (!empty($raw_content)): ?>
                    <div class="bit-card-content">
                        <?php echo $content; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php echo $rebit_markup; ?>

            <?php if (!empty($quoted_markup)): ?>
                <div class="bitstream-quoted-preview" data-permalink="<?php echo esc_url(add_query_arg('highlight_bit', $quoted_id, home_url('/bitstream/'))); ?>">
                    <?php echo $quoted_markup; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Custom walker callback for modern, comment layouts
     */
    public static function comment_callback($comment, $args, $depth)
    {
        $GLOBALS['comment'] = $comment;
        $tag = ('div' === $args['style']) ? 'div' : 'li';
    ?>
        <<?php echo $tag; ?> id="comment-<?php comment_ID(); ?>" <?php comment_class(empty($args['has_children']) ? '' : 'parent'); ?>>
            <article id="div-comment-<?php comment_ID(); ?>" class="bit-modern-comment">
                <div class="bit-modern-comment-avatar">
                    <?php if (0 != $args['avatar_size'])
            echo get_avatar($comment, $args['avatar_size']); ?>
                </div>
                <div class="bit-modern-comment-content">
                    <div class="bit-modern-comment-header">
                        <cite class="fn bit-modern-comment-author"><?php echo get_comment_author_link(); ?></cite>
                        <span class="bit-modern-comment-date">
                            <a href="<?php echo esc_url(get_comment_link($comment, $args)); ?>">
                                <?php printf('%s', get_comment_date()); ?>
                            </a>
                        </span>
                    </div>
                    
                    <?php if ('0' == $comment->comment_approved): ?>
                        <p class="comment-awaiting-moderation"><?php esc_html_e('Your comment is awaiting moderation.', 'bitstream'); ?></p>
                    <?php
        endif; ?>

                    <div class="bit-modern-comment-text">
                        <?php comment_text(); ?>
                    </div>

                    <div class="bit-modern-comment-actions">
                        <?php edit_comment_link(esc_html__('Edit', 'bitstream'), '<span class="edit-link">', '</span>'); ?>
                        <?php
        comment_reply_link(array_merge($args, [
            'add_below' => 'div-comment',
            'depth' => $depth,
            'max_depth' => $args['max_depth'],
            'before' => '<span class="reply-link">',
            'after' => '</span>'
        ]));
    ?>
                    </div>
                </div>
            </article>
        <?php
    }

    /**
     * Render a bit card
     *
     * @param int  $post_id
     * @param bool $skip_content_filter
     * @param array $options
     * @return string
     */
    public static function render_card($post_id, $skip_content_filter = false, $options = [])
    {
        $options = wp_parse_args($options, [
            'comment_action' => 'toggle',
            'is_preview'     => false,
        ]);

        // Avoid infinite loop by skipping content filter when rendering in single bit context
        if ($skip_content_filter) {
            $content = get_post_field('post_content', $post_id);
            $content = wpautop($content); // Basic paragraph formatting
        }
        else {
            $GLOBALS['bitstream_is_rendering_card'] = true;
            $content = apply_filters('the_content', get_post_field('post_content', $post_id));
            unset($GLOBALS['bitstream_is_rendering_card']);

            // Ensure paragraph formatting is applied even if the theme disables wpautop filter
            if (strpos($content, '<p>') === false && strpos($content, '<p ') === false) {
                $content = wpautop($content);
            }
        }

        // Normalize WordPress video shortcode output so feed cards do not depend on
        // MediaElement wrapper sizing, which can collapse in the timeline.
        if (strpos($content, 'wp-video') !== false || strpos($content, 'mejs-container') !== false) {
            $previous_dom_state = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $wrapped_content = '<div id="bitstream-card-content-root">' . $content . '</div>';
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $xpath = new DOMXPath($document);
            $wrapper_nodes = $xpath->query('//*[@id="bitstream-card-content-root"]//*[contains(concat(" ", normalize-space(@class), " "), " wp-video ")]');
            if ($wrapper_nodes) {
                for ($index = $wrapper_nodes->length - 1; $index >= 0; $index--) {
                    $wrapper = $wrapper_nodes->item($index);
                    if (!$wrapper || !$wrapper->parentNode) {
                        continue;
                    }

                    $video_node = null;
                    foreach ($wrapper->getElementsByTagName('video') as $candidate_video) {
                        $video_node = $candidate_video;
                        break;
                    }

                    if ($video_node) {
                        $clean_video_node = $video_node->cloneNode(true);
                        $clean_video_node->setAttribute('class', 'bitstream-video-attachment');
                        $clean_video_node->removeAttribute('width');
                        $clean_video_node->removeAttribute('height');
                        $clean_video_node->removeAttribute('style');
                        $wrapper->parentNode->replaceChild($document->importNode($clean_video_node, true), $wrapper);
                    }
                }
            }

            $root = $document->getElementById('bitstream-card-content-root');
            if ($root) {
                $normalized_content = '';
                foreach ($root->childNodes as $child_node) {
                    $normalized_content .= $document->saveHTML($child_node);
                }
                if ($normalized_content !== '') {
                    $content = $normalized_content;
                }
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previous_dom_state);
        }

        $timestamp = human_time_diff(get_post_time('U', true, $post_id), time()) . ' ago';
        $posted_datetime = get_post_time('d/m/Y H:i', false, $post_id);
        $is_edited = get_post_modified_time('U', false, $post_id) > get_post_time('U', false, $post_id);
        $timestamp_tooltip = 'Posted: ' . $posted_datetime . ($is_edited ? ' • Edited' : '');
        $avatar = get_avatar(get_post_field('post_author', $post_id), 96, '', '', ['class' => 'bit-avatar-img', 'extra_attr' => 'style="width:100%;height:100%;object-fit:cover;"']);
        $author_id = get_post_field('post_author', $post_id);
        $author_name = get_the_author_meta('display_name', $author_id);
        $mood_emoji = get_post_meta($post_id, '_bitstream_mood_emoji', true);
        $mood_emotion = get_post_meta($post_id, '_bitstream_mood_emotion', true);
        $likes = (int)get_post_meta($post_id, '_bitstream_likes', true);
        $comments = get_comments_number($post_id);
        $quoted_id = (int)get_post_meta($post_id, '_bitstream_quoted_bit', true);
        $is_rebit_card = !empty(get_post_meta($post_id, 'bitstream_rebit_url', true));
        $rebit_markup = self::render_rebit_section($post_id);
        $quoted_markup = '';
        if ($quoted_id > 0) {
            $quoted_markup = self::render_nested_quoted_card($quoted_id);
        }
        $raw_content = trim(get_post_field('post_content', $post_id));
        $has_attachments = !empty(get_post_meta($post_id, '_bitstream_attachment_id', true)) || !empty(get_post_meta($post_id, '_bitstream_attachment_ids', true));
        $is_pure_mood = empty($raw_content) && !$has_attachments && ($quoted_id <= 0) && !$is_rebit_card && !empty($mood_emotion);

        ob_start(); ?>
    <article id="bit-<?php echo esc_attr($post_id); ?>" class="bit-card">
        <header class="bit-card-header">
            <div class="bit-avatar">
                <?php echo $avatar; ?>
            </div>
            <div class="bit-meta">
                <span class="bit-author-line">
                    <?php echo esc_html($author_name); ?>
                    <?php if (!empty($mood_emotion) && !$is_pure_mood): ?>
                        <span class="bit-mood-status">
                            is feeling <?php echo esc_html($mood_emoji); ?> <strong><?php echo esc_html($mood_emotion); ?></strong>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="bit-timestamp" tabindex="0"><span class="bit-timestamp-relative"><?php echo esc_html($timestamp); ?></span><span class="bit-timestamp-full" style="display:none;"><span class="bit-timestamp-separator"> | </span><?php echo esc_html(get_post_time(get_option('date_format') . ' ' . get_option('time_format'), false, $post_id)); ?></span></span>
            </div>
        </header>

        <?php if ($is_pure_mood): ?>
            <div class="bit-card-content bit-card-pure-mood">
                <span class="bit-pure-mood-emoji"><?php echo esc_html($mood_emoji); ?></span>
                <span class="bit-pure-mood-text">
                    is feeling <strong><?php echo esc_html($mood_emotion); ?></strong>
                </span>
            </div>
        <?php else: ?>
            <?php if (!empty($raw_content)): ?>
                <div class="bit-card-content">
                    <?php echo $content; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php echo $rebit_markup; ?>

        <?php if (!empty($quoted_markup)): ?>
            <div class="bitstream-quoted-preview" data-permalink="<?php echo esc_url(add_query_arg('highlight_bit', $quoted_id, home_url('/bitstream/'))); ?>">
                <?php echo $quoted_markup; ?>
            </div>
        <?php
        endif; ?>

        <div class="bit-card-watermark">
            <img src="<?php echo esc_url(BITSTREAM_PLUGIN_URL . 'assets/images/logo_192.png'); ?>" alt="" aria-hidden="true">
            <span>BitStream</span>
        </div>

        <?php
        $can_quote = current_user_can('edit_posts');
        $can_edit = current_user_can('edit_post', $post_id);
        $can_delete = is_user_logged_in() && current_user_can('delete_post', $post_id);
        $show_admin_actions = !$options['is_preview'] && ($can_quote || $can_edit || $can_delete);
        ?>
        <footer class="bit-card-footer">
            <div class="bit-card-footer-main-actions">
                <?php if ($options['comment_action'] === 'link'): ?>
                    <a class="bit-comment-preview-link bit-action" href="<?php echo esc_url(add_query_arg([
                        'highlight_bit' => $post_id,
                        'open_comments' => $post_id,
                    ], home_url('/bitstream/'))); ?>" title="View comments">
                        <i class="fas fa-comment-dots"></i> <?php echo esc_html($comments); ?>
                    </a>
                <?php else: ?>
                <button class="bit-comment-toggle bit-action" data-target="comments-<?php echo esc_attr($post_id); ?>" title="View and add comments">
                    <i class="fas fa-comment-dots"></i> <?php echo esc_html($comments); ?>
                </button>
                <?php endif; ?>
                <button class="bit-like bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" title="Like this bit">
                    <i class="fas fa-heart"></i> <span class="bit-like-count"><?php echo esc_html($likes); ?></span>
                </button>

                <?php
                $share_base_url = class_exists('BitStream_Shortcodes') ? BitStream_Shortcodes::get_feed_page_url() : home_url('/bitstream/');
                $share_url      = add_query_arg('highlight_bit', $post_id, $share_base_url);
                ?>
                <button class="bit-share bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" data-url="<?php echo esc_url($share_url); ?>" data-title="<?php echo esc_attr(get_the_title($post_id)); ?>" data-share-image="<?php echo esc_url(get_post_meta($post_id, '_bitstream_share_image_url', true)); ?>" title="Share this bit">
                    <i class="fa-solid fa-share-nodes"></i>
                </button>
            </div>
            <?php if ($show_admin_actions): ?>
                <span class="bit-card-footer-spacer" aria-hidden="true"></span>
                <div class="bit-card-footer-admin-actions">
                    <?php if ($can_quote): ?>
                    <button class="bit-quote bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" title="Quote this bit">
                        <i class="fa-solid fa-retweet"></i>
                    </button>
                    <?php
            endif; ?>
                    <?php if ($can_edit): ?>
                    <button class="bit-edit bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" data-post-type="<?php echo esc_attr(($is_rebit_card && $quoted_id <= 0) ? 'rebit' : 'bit'); ?>" title="Edit this bit">
                        <i class="fa-solid fa-pencil"></i>
                    </button>
                    <?php
            endif; ?>
                    <?php if ($can_delete): ?>
                    <button class="bit-delete bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" title="Delete this bit">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <?php
            endif; ?>
                </div>
            <?php
        endif; ?>
        </footer>

        <div id="comments-<?php echo $post_id; ?>" class="bit-comments">
            <ol class="comment-list">
                <?php wp_list_comments(['style' => 'ol', 'callback' => ['BitStream_Content_Display', 'comment_callback'], 'avatar_size' => 48, 'short_ping' => true, 'max_depth' => 3], get_comments(['post_id' => $post_id, 'status' => 'approve'])); ?>
            </ol>
            <div class="bit-comment-form">
                <?php comment_form([
            'comment_notes_after' => '',
            'title_reply' => 'Leave a Comment',
            'logged_in_as' => '',
            'comment_field' => '<p><textarea name="comment" required></textarea></p>',
        ], $post_id); ?>
            </div>
        </div>
    </article>
    <?php
        return ob_get_clean();
    }

    /**
     * Check if an attachment is still referenced by any post on the site.
     *
     * @param int   $attachment_id The attachment ID to check.
     * @param array $exclude_post_ids Optional array of post IDs to exclude.
     * @return bool
     */
    public static function is_attachment_used($attachment_id, $exclude_post_ids = [])
    {
        global $wpdb;

        $attachment_id = intval($attachment_id);
        if ($attachment_id <= 0) {
            return false;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return true; // Return true as a safeguard if post is not valid attachment
        }

        $exclude_post_ids = is_array($exclude_post_ids) ? $exclude_post_ids : [];
        $exclude_post_ids = array_values(array_unique(array_filter(array_map('intval', $exclude_post_ids))));
        if (empty($exclude_post_ids)) {
            $exclude_post_ids = [0];
        }

        // 1. Check parent post relation
        $parent_id = intval($attachment->post_parent);
        if ($parent_id > 0 && !in_array($parent_id, $exclude_post_ids, true)) {
            $parent = get_post($parent_id);
            if ($parent && !in_array($parent->post_status, ['trash', 'auto-draft'], true)) {
                return true;
            }
        }

        // 2. Check metadata references (e.g. attached via composer postmeta like _bitstream_attachment_ids, etc.)
        $excluded_placeholders = implode(',', array_fill(0, count($exclude_post_ids), '%d'));
        $meta_query = $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_value = %s
               AND pm.post_id NOT IN ({$excluded_placeholders})
               AND p.post_status NOT IN ('trash','auto-draft')
             LIMIT 1",
            array_merge([strval($attachment_id)], $exclude_post_ids)
        );
        $meta_ref = $wpdb->get_var($meta_query);
        if (!empty($meta_ref)) {
            return true;
        }

        // 3. Check inline references inside post_content (combined into one query)
        $clauses = [];
        $args = [];

        $placeholders = implode(',', array_fill(0, count($exclude_post_ids), '%d'));
        $args = array_merge($args, $exclude_post_ids);

        $class_like = '%wp-image-' . $attachment_id . '%';
        $clauses[] = "post_content LIKE %s";
        $args[] = $class_like;

        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            $url_like = '%' . $wpdb->esc_like($attachment_url) . '%';
            $clauses[] = "post_content LIKE %s";
            $args[] = $url_like;
        }

        $clauses_str = implode(' OR ', $clauses);
        
        $query = "SELECT ID FROM {$wpdb->posts} 
                  WHERE ID NOT IN ({$placeholders}) 
                    AND post_status NOT IN ('trash','auto-draft','inherit') 
                    AND ({$clauses_str}) 
                  LIMIT 1";

        $content_ref = $wpdb->get_var($wpdb->prepare($query, $args));

        return !empty($content_ref);
    }
}
