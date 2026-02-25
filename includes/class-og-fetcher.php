<?php
/**
 * BitStream OG Data Fetcher
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class BitStream_OG_Fetcher
{

    public function __construct()
    {
    // Remove background OG fetching since we now do it immediately via AJAX
    // add_action('save_post_bit', [$this, 'schedule_og_fetch'], 10, 3);
    // add_action('bitstream_fetch_og_data', [$this, 'process_og_data'], 10, 2);
    }

    /**
     * Fetch OpenGraph data for a URL
     */
    public function fetch_og_data($url)
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // 1. Transient Caching
        $cache_key = 'bitstream_og_' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $host = strtolower(wp_parse_url($url, PHP_URL_HOST) ?? '');
        $host = preg_replace('/^www\./', '', $host);

        // 2. Custom Fallbacks (e.g., Twitter/X blocks scraping and WP core dropped their oEmbed)
        if (in_array($host, ['twitter.com', 'x.com'], true)) {
            $result = $this->fetch_twitter_oembed($url);
            if ($result !== false) {
                set_transient($cache_key, $result, HOUR_IN_SECONDS * 24);
                return $result;
            }
        }

        // 3. Harness WordPress core oEmbed registry (covers Vimeo, Spotify, SoundCloud, Reddit, TikTok, etc.)
        require_once ABSPATH . WPINC . '/class-wp-oembed.php';
        $oembed = _wp_oembed_get_object();
        $provider = $oembed->get_provider($url);
        if ($provider) {
            $data = $oembed->fetch($provider, $url);
            if ($data && is_object($data)) {
                $title = sanitize_text_field($data->title ?? '');
                // Try to glean a description, otherwise use the author name 
                $desc = wp_strip_all_tags($data->description ?? ($data->author_name ?? ''));
                if (!empty($data->provider_name) && empty($data->description)) {
                    $desc .= ' on ' . $data->provider_name;
                }

                $result = [
                    'title' => $title,
                    'description' => trim($desc),
                    'image' => esc_url_raw($data->thumbnail_url ?? ''),
                    'url' => $url
                ];

                if (!empty($result['title']) || !empty($result['image'])) {
                    set_transient($cache_key, $result, HOUR_IN_SECONDS * 24);
                    return $result;
                }
            }
        }

        // 4. Fetch HTML with SSRF protection limit
        $args = [
            'timeout' => 10,
            'redirection' => 3,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]
        ];

        $resp = wp_safe_remote_get($url, $args);

        // Retry once on failure
        if (is_wp_error($resp)) {
            $resp = wp_safe_remote_get($url, $args);
        }

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return false;
        }

        $html = wp_remote_retrieve_body($resp);

        // Truncate to head to save memory in parsing
        if (stripos($html, '</head>') !== false) {
            $html = substr($html, 0, stripos($html, '</head>') + 7);
        }

        // Charset decoding for non-UTF-8 sites
        $content_type = wp_remote_retrieve_header($resp, 'content-type');
        if (preg_match('/charset=([^\s;]+)/i', $content_type, $matches) || preg_match('/<meta[^>]+charset=["\']?([^"\'>\s]+)/i', $html, $matches)) {
            $charset = strtoupper(trim($matches[1]));
            if ($charset && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $html = @mb_convert_encoding($html, 'UTF-8', $charset);
            }
        }

        $og_title = $og_desc = $og_img = '';

        // 5. Parse JSON-LD structured data (often richer than OG tags on modern sites)
        if (preg_match_all('/<script type="application\/ld\+json">([^<]+)<\/script>/i', $html, $matches)) {
            foreach ($matches[1] as $json_str) {
                $data = json_decode($json_str, true);
                if (is_array($data)) {
                    // JSON-LD can be a single object or a '@graph' array of objects
                    $items = isset($data['@graph']) ? $data['@graph'] : [$data];
                    foreach ($items as $item) {
                        if (!is_array($item))
                            continue;
                        if (empty($og_title))
                            $og_title = $item['headline'] ?? ($item['title'] ?? ($item['name'] ?? ''));
                        if (empty($og_desc))
                            $og_desc = $item['description'] ?? '';
                        if (empty($og_img) && !empty($item['image'])) {
                            $og_img = is_array($item['image'])
                                ? ($item['image'][0] ?? ($item['image']['url'] ?? ''))
                                : $item['image'];
                        }
                    }
                }
            }
        }

        // 6. Generic Meta Tag parser (order agnostic: handles both `property="..." content="..."` and `content="..." property="..."`)
        if (empty($og_title)) {
            if (preg_match('/<meta[^>]+(?:property|name)=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:title["\']/i', $html, $m)) {
                $og_title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            elseif (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
                // Fallback to title tag
                $og_title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
            }
        }

        if (empty($og_desc)) {
            if (preg_match('/<meta[^>]+(?:property|name)=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:description["\']/i', $html, $m)) {
                $og_desc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            elseif (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $m)) {
                // Fallback to standard description meta
                $og_desc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        if (empty($og_img)) {
            if (preg_match('/<meta[^>]+(?:property|name)=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:image["\']/i', $html, $m)) {
                $og_img = $m[1];
            }
        }

        // 7. OG Image Absolutization (handle relative image paths)
        if (!empty($og_img) && !preg_match('/^https?:\/\//i', $og_img)) {
            if (str_starts_with($og_img, '//')) {
                $og_img = 'https:' . $og_img;
            }
            else {
                $parsed = wp_parse_url($url);
                $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                if (str_starts_with($og_img, '/')) {
                    $og_img = $base . $og_img;
                }
                else {
                    $og_img = rtrim($url, '/') . '/' . $og_img;
                }
            }
        }

        $result = [
            'title' => trim($og_title),
            'description' => trim($og_desc),
            'image' => $og_img,
            'url' => $url
        ];

        set_transient($cache_key, $result, HOUR_IN_SECONDS * 24);
        return $result;
    }

    /**
     * Fetch tweet data via Twitter's public oEmbed API.
     * No API key required. Returns OG-shaped array or false on failure.
     */
    private function fetch_twitter_oembed($url)
    {
        $oembed_endpoint = 'https://publish.twitter.com/oembed?' . http_build_query([
            'url' => $url,
            'omit_script' => 'true',
            'dnt' => 'true',
        ]);

        $resp = wp_remote_get($oembed_endpoint, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0',
        ]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) {
            return false;
        }

        // oEmbed fields: author_name, author_url, html (tweet HTML), provider_name
        $author = sanitize_text_field($data['author_name'] ?? '');
        $author_url = esc_url_raw($data['author_url'] ?? '');
        $tweet_html = $data['html'] ?? '';

        // Extract plain tweet text from the oEmbed HTML (strip tags, keep content)
        $tweet_text = '';
        if (!empty($tweet_html)) {
            // The oEmbed HTML is a <blockquote> — grab text nodes
            $tweet_text = html_entity_decode(wp_strip_all_tags($tweet_html), ENT_QUOTES, 'UTF-8');
            // Clean up whitespace and trailing attribution lines (e.g. "— Author (@handle) date")
            $tweet_text = trim(preg_replace('/\s+/', ' ', $tweet_text));
            // Remove trailing "— Name (@handle) Month Day, Year" attribution
            $tweet_text = preg_replace('/\s*—\s*.+\(@\w+\).+$/', '', $tweet_text);
            $tweet_text = trim($tweet_text);
        }

        // Use author's Twitter avatar as the preview image
        // The oEmbed API doesn't return an image directly, but we can derive
        // the profile picture URL from the author handle in author_url.
        $og_image = '';
        if (!empty($author_url)) {
            // Extract @handle from the author URL e.g. https://twitter.com/handle
            $handle = trim(wp_parse_url($author_url, PHP_URL_PATH), '/');
            if (!empty($handle)) {
                // Use unavatar.io as a reliable, no-auth proxy for Twitter profile pictures
                $og_image = 'https://unavatar.io/twitter/' . rawurlencode($handle);
            }
        }

        return [
            'title' => !empty($author) ? $author . ' on ' . (str_contains($url, 'x.com') ? 'X' : 'Twitter') : '',
            'description' => $tweet_text,
            'image' => $og_image,
            'url' => $url,
        ];
    }

    /**
     * Schedule background OG data fetching
     */
    public function schedule_og_fetch($post_id, $post, $update)
    {
        if ($post->post_type !== 'bit')
            return;

        $url = get_post_meta($post_id, 'bitstream_rebit_url', true);
        if (empty($url) || get_post_meta($post_id, '_bitstream_og_fetched', true))
            return;

        // Schedule background OG fetching instead of blocking
        wp_schedule_single_event(time() + 10, 'bitstream_fetch_og_data', [$post_id, $url]);
    }

    /**
     * Process OG data fetching in background
     */
    public function process_og_data($post_id, $url)
    {
        $resp = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200)
            return;

        $html = wp_remote_retrieve_body($resp);
        $og_title = $og_desc = $og_img = '';

        // Extract OG data
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $og_title = $m[1];
        }
        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $og_desc = $m[1];
        }
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $og_img = $m[1];
        }

        // Fallback to title tag if no OG title
        if (empty($og_title) && preg_match('/<title>(.*?)<\/title>/', $html, $m)) {
            $og_title = $m[1];
        }

        // Save the data
        update_post_meta($post_id, '_bitstream_og_title', sanitize_text_field($og_title));
        update_post_meta($post_id, '_bitstream_og_desc', sanitize_text_field($og_desc));
        update_post_meta($post_id, '_bitstream_og_image', esc_url_raw($og_img));
        update_post_meta($post_id, '_bitstream_og_fetched', time());
    }
}
