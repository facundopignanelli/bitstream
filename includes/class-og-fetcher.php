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
    }

    /**
     * Fetch OpenGraph data for a URL
     */
    public function fetch_og_data($url)
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower(wp_parse_url($url, PHP_URL_HOST) ?? '');
        $host = preg_replace('/^www\./', '', $host);

        // 1. Transient Caching
        $cache_key = 'bitstream_og_' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            if (in_array($host, ['twitter.com', 'x.com'], true) && (empty($cached['embed_html']) || !isset($cached['avatar']))) {
                // Stale cache: refresh
            } elseif (($host === 'instagram.com' || strpos($host, 'instagram.com') !== false) && (
                empty($cached['avatar']) ||
                strpos($cached['avatar'], 'unavatar.io') !== false ||
                (!empty($cached['image']) && strpos($cached['image'], 'stp=c') !== false) ||
                (empty($cached['image']) && empty($cached['embed_html'])) ||
                in_array($cached['title'] ?? '', ['Instagram Reel', 'Instagram Post', 'Instagram Story'], true)
            )) {
                // Stale Instagram cache with broken avatar, cropped image, or missing media: refresh
            } else {
                return $cached;
            }
        }

        // 2. Custom Fallbacks (e.g., Twitter/X and Instagram)
        if (in_array($host, ['twitter.com', 'x.com'], true)) {
            $result = $this->fetch_twitter_oembed($url);
            if ($result !== false) {
                set_transient($cache_key, $result, HOUR_IN_SECONDS * 24);
                return $result;
            }
        } elseif ($host === 'instagram.com' || strpos($host, 'instagram.com') !== false) {
            $result = $this->fetch_instagram_data($url);
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
            if (strpos($og_img, '//') === 0) {
                $og_img = 'https:' . $og_img;
            }
            else {
                $parsed = wp_parse_url($url);
                $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                if (strpos($og_img, '/') === 0) {
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
            'image' => esc_url_raw($og_img),
            'url' => $url
        ];

        if (!empty($result['title']) || !empty($result['image'])) {
            set_transient($cache_key, $result, HOUR_IN_SECONDS * 24);
            return $result;
        }

        return false;
    }

    /**
     * Fetch tweet data via public bridge APIs (vxtwitter / fxtwitter) and official oEmbed fallback.
     * Extracts author details, profile avatar, tweet text, and media images.
     */
    private function fetch_twitter_oembed($url)
    {
        $clean_url = preg_replace('/\?.*$/', '', $url);
        $status_id = '';
        if (preg_match('#/(?:status|statuses)/(\d+)#i', $clean_url, $sm)) {
            $status_id = $sm[1];
        }

        $handle = '';
        if (preg_match('#(?:twitter\.com|x\.com)/([a-zA-Z0-9_]+)#i', $clean_url, $um)) {
            if (!in_array(strtolower($um[1]), ['i', 'status', 'statuses'], true)) {
                $handle = $um[1];
            }
        }

        $fallback_html = '<blockquote class="twitter-tweet" data-dnt="true"><a href="' . esc_url($clean_url) . '">' . esc_html($clean_url) . '</a></blockquote>';
        $author = '';
        $tweet_text = '';
        $avatar = '';
        $images = [];
        $date_epoch = null;
        $tweet_html = $fallback_html;

        // 1. Primary bridge: api.vxtwitter.com
        if (!empty($status_id)) {
            $vx_url = $handle ? "https://api.vxtwitter.com/{$handle}/status/{$status_id}" : "https://api.vxtwitter.com/status/{$status_id}";
            $vx_resp = wp_remote_get($vx_url, [
                'timeout' => 4,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BitStream/1.0',
                'headers' => ['Accept' => 'application/json'],
                'redirection' => 2,
            ]);

            if (!is_wp_error($vx_resp) && wp_remote_retrieve_response_code($vx_resp) === 200) {
                $vx_data = json_decode(wp_remote_retrieve_body($vx_resp), true);
                if (is_array($vx_data) && (!empty($vx_data['tweetID']) || !empty($vx_data['text']) || !empty($vx_data['user_name']))) {
                    $author = sanitize_text_field($vx_data['user_name'] ?? '');
                    if (!empty($vx_data['user_screen_name'])) {
                        $handle = sanitize_text_field($vx_data['user_screen_name']);
                    }
                    $tweet_text = sanitize_textarea_field($vx_data['text'] ?? '');
                    $avatar = esc_url_raw($vx_data['user_profile_image_url'] ?? '');
                    if (!empty($avatar)) {
                        $avatar = str_replace('_normal.', '_200x200.', $avatar);
                    }
                    $date_epoch = $vx_data['date_epoch'] ?? null;
                    if (!empty($vx_data['mediaURLs']) && is_array($vx_data['mediaURLs'])) {
                        foreach ($vx_data['mediaURLs'] as $m_url) {
                            if (is_string($m_url) && !empty($m_url)) {
                                $images[] = esc_url_raw($m_url);
                            }
                        }
                    }
                }
            }
        }

        // 2. Secondary bridge: api.fxtwitter.com
        if (!empty($status_id) && empty($author) && empty($tweet_text)) {
            $fx_url = $handle ? "https://api.fxtwitter.com/{$handle}/status/{$status_id}" : "https://api.fxtwitter.com/status/{$status_id}";
            $fx_resp = wp_remote_get($fx_url, [
                'timeout' => 4,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BitStream/1.0',
                'headers' => ['Accept' => 'application/json'],
                'redirection' => 2,
            ]);

            if (!is_wp_error($fx_resp) && wp_remote_retrieve_response_code($fx_resp) === 200) {
                $fx_data = json_decode(wp_remote_retrieve_body($fx_resp), true);
                if (is_array($fx_data) && !empty($fx_data['tweet'])) {
                    $tw = $fx_data['tweet'];
                    $author = sanitize_text_field($tw['author']['name'] ?? '');
                    if (!empty($tw['author']['screen_name'])) {
                        $handle = sanitize_text_field($tw['author']['screen_name']);
                    }
                    $tweet_text = sanitize_textarea_field($tw['text'] ?? '');
                    $avatar = esc_url_raw($tw['author']['avatar_url'] ?? '');
                    $date_epoch = $tw['created_timestamp'] ?? null;
                    if (!empty($tw['media']['photos']) && is_array($tw['media']['photos'])) {
                        foreach ($tw['media']['photos'] as $p) {
                            if (!empty($p['url'])) {
                                $images[] = esc_url_raw($p['url']);
                            }
                        }
                    } elseif (!empty($tw['media']['mosaic']['formats']['jpeg'])) {
                        $images[] = esc_url_raw($tw['media']['mosaic']['formats']['jpeg']);
                    }
                }
            }
        }

        // 3. Fallback to Twitter / X oEmbed API
        if (empty($author) || empty($tweet_text)) {
            $oembed_endpoint = 'https://publish.x.com/oembed?' . http_build_query([
                'url' => $clean_url,
                'omit_script' => 'true',
                'dnt' => 'true',
            ]);

            $oembed_resp = wp_remote_get($oembed_endpoint, [
                'timeout' => 4,
                'user-agent' => 'Mozilla/5.0',
                'redirection' => 3,
            ]);

            if (is_wp_error($oembed_resp) || wp_remote_retrieve_response_code($oembed_resp) !== 200) {
                // Try legacy publish.twitter.com endpoint
                $legacy_endpoint = 'https://publish.twitter.com/oembed?' . http_build_query([
                    'url' => $clean_url,
                    'omit_script' => 'true',
                    'dnt' => 'true',
                ]);
                $oembed_resp = wp_remote_get($legacy_endpoint, [
                    'timeout' => 4,
                    'user-agent' => 'Mozilla/5.0',
                    'redirection' => 3,
                ]);
            }

            if (!is_wp_error($oembed_resp) && wp_remote_retrieve_response_code($oembed_resp) === 200) {
                $oembed_data = json_decode(wp_remote_retrieve_body($oembed_resp), true);
                if (is_array($oembed_data) && !empty($oembed_data['html'])) {
                    if (empty($author)) {
                        $author = sanitize_text_field($oembed_data['author_name'] ?? '');
                    }
                    $tweet_html = $oembed_data['html'];
                    if (empty($tweet_text)) {
                        $tweet_text = html_entity_decode(wp_strip_all_tags($tweet_html), ENT_QUOTES, 'UTF-8');
                        $tweet_text = trim(preg_replace('/\s+/', ' ', $tweet_text));
                        $tweet_text = preg_replace('/\s*—\s*.+\(@\w+\).+$/', '', $tweet_text);
                        $tweet_text = trim($tweet_text);
                    }
                    if (empty($handle) && !empty($oembed_data['author_url'])) {
                        $handle = trim(wp_parse_url($oembed_data['author_url'], PHP_URL_PATH), '/');
                    }
                }
            }
        }

        // 4. Crawl metadata fallback for images if bridge did not provide them
        if (empty($images) && !empty($status_id)) {
            $x_resp = wp_remote_get($clean_url, [
                'timeout' => 3,
                'user-agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                'redirection' => 2,
            ]);
            if (!is_wp_error($x_resp) && wp_remote_retrieve_response_code($x_resp) === 200) {
                $x_html = wp_remote_retrieve_body($x_resp);
                if (preg_match('#<link[^>]+rel=["\']preload["\'][^>]+as=["\']image["\'][^>]+href=["\'](https://pbs\.twimg\.com/media/[^"\'\s>]+)#i', $x_html, $pm)) {
                    $images[] = esc_url_raw(html_entity_decode($pm[1], ENT_QUOTES, 'UTF-8'));
                }
            }
        }

        // 5. Fallback avatar if still empty
        if (empty($avatar) && !empty($handle)) {
            $avatar = 'https://unavatar.io/twitter/' . rawurlencode($handle);
        }

        // 6. Format timestamp
        $tweet_time_date = '';
        if (!empty($date_epoch)) {
            $tweet_time_date = gmdate('g:i A · M j, Y', intval($date_epoch));
        } elseif (!empty($status_id)) {
            $ts = (floatval($status_id) / 4194304) + 1288834974657;
            if ($ts > 1288834974657) {
                $tweet_time_date = gmdate('g:i A · M j, Y', intval($ts / 1000));
            }
        }

        $primary_image = !empty($images) ? $images[0] : '';

        return [
            'title' => !empty($author) ? $author . ' on ' . (strpos($url, 'x.com') !== false ? 'X' : 'Twitter') : 'Post on ' . (strpos($url, 'x.com') !== false ? 'X' : 'Twitter'),
            'description' => $tweet_text,
            'image' => $primary_image,
            'images' => $images,
            'avatar' => $avatar,
            'handle' => $handle,
            'author' => $author,
            'tweet_time_date' => $tweet_time_date,
            'url' => $clean_url,
            'embed_html' => $tweet_html,
            'is_embeddable' => true,
            'embed_type' => 'twitter',
        ];
    }

    /**
     * Parse and fetch Instagram post, reel, or story data.
     */
    private function fetch_instagram_data($url)
    {
        $clean_url = preg_replace('/\?.*$/', '', $url);
        $parsed = parse_url($clean_url);
        $path = trim($parsed['path'] ?? '', '/');
        $segments = explode('/', $path);

        $type = 'Post';
        $shortcode = '';
        $username = '';

        if (!empty($segments[0])) {
            if ($segments[0] === 'p' && !empty($segments[1])) {
                $type = 'Post';
                $shortcode = $segments[1];
            } elseif (in_array($segments[0], ['reel', 'reels'], true) && !empty($segments[1])) {
                $type = 'Reel';
                $shortcode = $segments[1];
            } elseif ($segments[0] === 'stories' && !empty($segments[1])) {
                $type = 'Story';
                $username = $segments[1];
                $shortcode = $segments[2] ?? '';
            } elseif (!empty($segments[1]) && $segments[1] === 'p' && !empty($segments[2])) {
                $username = $segments[0];
                $type = 'Post';
                $shortcode = $segments[2];
            } elseif (!empty($segments[1]) && in_array($segments[1], ['reel', 'reels'], true) && !empty($segments[2])) {
                $username = $segments[0];
                $type = 'Reel';
                $shortcode = $segments[2];
            } else {
                $username = $segments[0];
            }
        }

        // Canonicalize URL to ensure standard Instagram format (e.g. /reels/ -> /reel/)
        if ($type === 'Reel' && !empty($shortcode)) {
            $clean_url = 'https://www.instagram.com/reel/' . $shortcode . '/';
        } elseif ($type === 'Post' && !empty($shortcode)) {
            $clean_url = 'https://www.instagram.com/p/' . $shortcode . '/';
        }

        $args = [
            'timeout' => 8,
            'redirection' => 3,
            'user-agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]
        ];

        $title = '';
        $description = '';
        $image = '';
        $avatar = '';
        $author_name = '';
        $img_width = 0;
        $img_height = 0;
        $embed_html = '';

        // For non-story URLs, fetch target page OG tags
        if ($type !== 'Story') {
            $resp = wp_safe_remote_get($clean_url, $args);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $html = wp_remote_retrieve_body($resp);
                if (!empty($html)) {
                    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                        $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                    }
                    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                        $description = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                    }
                    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                        $image = esc_url_raw(html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8'));
                    }

                    // Extract full uncropped image from embedded JSON image_versions2 if available (bypasses pre-cropped og:image with stp=c...)
                    if (preg_match('/"image_versions2":\s*\{[^{]*"candidates":\s*\[\s*\{[^{]*"url":\s*"([^"]+)"/i', $html, $im)) {
                        $decoded_image = json_decode('"' . $im[1] . '"') ?: stripcslashes($im[1]);
                        if (!empty($decoded_image)) {
                            $image = esc_url_raw($decoded_image);
                        }
                    }
                    if (empty($username) && preg_match('/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']https?:\/\/(?:www\.)?instagram\.com\/([a-zA-Z0-9._]+)\//i', $html, $um)) {
                        if (!in_array($um[1], ['p', 'reel', 'reels', 'stories', 'tv'], true)) {
                            $username = $um[1];
                        }
                    }
                    if (empty($username) && preg_match('/"username":\s*"([a-zA-Z0-9._]+)"/i', $html, $jum)) {
                        if (!in_array($jum[1], ['p', 'reel', 'reels', 'stories', 'tv'], true)) {
                            $username = $jum[1];
                        }
                    }
                    if (empty($username) && preg_match('/@([a-zA-Z0-9._]+)/', $title, $tum)) {
                        $username = $tum[1];
                    }

                    // Extract profile picture directly from embedded JSON in post HTML
                    if (empty($avatar) && preg_match('/"(?:profile_pic_url_hd|profile_pic_url)":\s*"([^"]+)"/i', $html, $pam)) {
                        $decoded_avatar = json_decode('"' . $pam[1] . '"') ?: stripcslashes($pam[1]);
                        if (!empty($decoded_avatar)) {
                            $avatar = esc_url_raw($decoded_avatar);
                        }
                    }

                    // Extract original image width & height for aspect-ratio
                    if (preg_match('/"original_width":\s*(\d+)/i', $html, $owm) && preg_match('/"original_height":\s*(\d+)/i', $html, $ohm)) {
                        $img_width = intval($owm[1]);
                        $img_height = intval($ohm[1]);
                    }
                }
            }
        }

        // Fallback: If image or description could not be scraped directly, query Meta tokenless oEmbed API
        if ($type !== 'Story' && (empty($image) || empty($description))) {
            $oembed_endpoint = 'https://graph.facebook.com/v25.0/instagram_oembed?url=' . rawurlencode($clean_url) . '&omitscript=true';
            $oembed_resp = wp_remote_get($oembed_endpoint, [
                'timeout' => 5,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
                'redirection' => 2,
            ]);
            if (!is_wp_error($oembed_resp) && wp_remote_retrieve_response_code($oembed_resp) === 200) {
                $oembed_body = wp_remote_retrieve_body($oembed_resp);
                $oembed_data = json_decode($oembed_body, true);
                if (is_array($oembed_data)) {
                    if (!empty($oembed_data['html'])) {
                        $embed_html = $oembed_data['html'];
                    }
                    if (empty($title) && !empty($oembed_data['title'])) {
                        $title = sanitize_text_field($oembed_data['title']);
                    }
                    if (empty($author_name) && !empty($oembed_data['author_name'])) {
                        $author_name = sanitize_text_field($oembed_data['author_name']);
                    }
                    if (empty($username) && !empty($oembed_data['author_url'])) {
                        $username = trim(wp_parse_url($oembed_data['author_url'], PHP_URL_PATH), '/');
                    }
                    if (empty($image) && !empty($oembed_data['thumbnail_url'])) {
                        $image = esc_url_raw($oembed_data['thumbnail_url']);
                    }
                }
            }
        }

        // Prevent caching completely broken / empty scrape results
        if (empty($image) && empty($description) && empty($embed_html)) {
            return false;
        }

        // Extract clean author display name
        if (!empty($title)) {
            if (preg_match('/^Watch this story by\s+(.+?)(?:\s+on Instagram.*)?$/i', $title, $sm)) {
                $author_name = trim($sm[1]);
            } elseif (preg_match('/^(.*?)\s+on Instagram/i', $title, $anm)) {
                $author_name = trim($anm[1]);
            } else {
                $author_name = trim(preg_replace('/\s*•.*$/', '', $title));
            }
        }

        // Extract posted date and clean caption from description
        $posted_date = '';
        if (!empty($description)) {
            // Extract date e.g. "bbcnews on August 10, 2026: ..." or "on September 1, 2026"
            if (preg_match('/\bon\s+([A-Za-z]+\s+\d{1,2}(?:,\s+\d{4})?)/i', $description, $dm)) {
                $posted_date = trim($dm[1]);
            }
            // Extract clean caption after colon & quotes, stripping likes/comments/author prefix
            if (preg_match('/:\s*["\']?(.*?)["\']?\s*$/s', $description, $cm)) {
                $description = trim($cm[1]);
                $description = preg_replace('/^["\']|["\']$/', '', $description);
            }
        }

        // Fallback: Fetch author profile page only if avatar was not found in post HTML
        if (empty($avatar) && !empty($username)) {
            $prof_url = 'https://www.instagram.com/' . rawurlencode($username) . '/';
            $prof_resp = wp_safe_remote_get($prof_url, $args);
            $p_code = wp_remote_retrieve_response_code($prof_resp);
            if (!is_wp_error($prof_resp) && $p_code >= 200 && $p_code < 400) {
                $prof_html = wp_remote_retrieve_body($prof_resp);
                if (!empty($prof_html)) {
                    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $prof_html, $pm)) {
                        $avatar = esc_url_raw(html_entity_decode(trim($pm[1]), ENT_QUOTES, 'UTF-8'));
                    }
                    if (empty($author_name) || $author_name === $username) {
                        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $prof_html, $ptm)) {
                            $pt = html_entity_decode(trim($ptm[1]), ENT_QUOTES, 'UTF-8');
                            $clean_pt = trim(preg_replace('/\s*•.*$/', '', $pt));
                            $clean_pt = trim(preg_replace('/\s*\(@[a-zA-Z0-9._]+\)/', '', $clean_pt));
                            if (!empty($clean_pt)) {
                                $author_name = $clean_pt;
                            }
                        }
                    }
                }
            }
        }

        if (empty($author_name)) {
            $author_name = $username ? '@' . $username : 'Instagram ' . $type;
        }

        if ($type === 'Story' && empty($description)) {
            $description = $username ? 'View @' . $username . '\'s story on Instagram.' : 'View story on Instagram.';
        }

        if (empty($title)) {
            $title = $author_name;
        }

        return [
            'title' => $title,
            'description' => $description,
            'posted_date' => $posted_date,
            'image' => $image,
            'avatar' => $avatar,
            'username' => $username,
            'image_width' => $img_width,
            'image_height' => $img_height,
            'instagram_type' => $type,
            'url' => $clean_url,
            'embed_html' => $embed_html,
            'is_embeddable' => true,
            'embed_type' => 'instagram',
        ];
    }

}
