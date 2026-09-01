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
            if (in_array($host, ['twitter.com', 'x.com'], true) && empty($cached['embed_html'])) {
                // Stale cache: refresh
            } elseif (($host === 'instagram.com' || strpos($host, 'instagram.com') !== false) && (empty($cached['avatar']) || strpos($cached['avatar'], 'unavatar.io') !== false)) {
                // Stale Instagram cache with broken unavatar.io avatar: refresh
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
        $clean_url = preg_replace('/\?.*$/', '', $url);
        $fallback_html = '<blockquote class="twitter-tweet" data-dnt="true"><a href="' . esc_url($clean_url) . '">' . esc_html($clean_url) . '</a></blockquote>';

        $oembed_endpoint = 'https://publish.twitter.com/oembed?' . http_build_query([
            'url' => $clean_url,
            'omit_script' => 'true',
            'dnt' => 'true',
        ]);

        $resp = wp_remote_get($oembed_endpoint, [
            'timeout' => 4,
            'user-agent' => 'Mozilla/5.0',
        ]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return [
                'title' => 'Post on ' . (strpos($url, 'x.com') !== false ? 'X' : 'Twitter'),
                'description' => '',
                'image' => '',
                'url' => $url,
                'embed_html' => $fallback_html,
                'is_embeddable' => true,
                'embed_type' => 'twitter',
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['html'])) {
            return [
                'title' => 'Post on ' . (strpos($url, 'x.com') !== false ? 'X' : 'Twitter'),
                'description' => '',
                'image' => '',
                'url' => $url,
                'embed_html' => $fallback_html,
                'is_embeddable' => true,
                'embed_type' => 'twitter',
            ];
        }

        // oEmbed fields: author_name, author_url, html (tweet HTML), provider_name
        $author = sanitize_text_field($data['author_name'] ?? '');
        $author_url = esc_url_raw($data['author_url'] ?? '');
        $tweet_html = $data['html'] ?? $fallback_html;

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
        $og_image = '';
        if (!empty($author_url)) {
            $handle = trim(wp_parse_url($author_url, PHP_URL_PATH), '/');
            if (!empty($handle)) {
                $og_image = 'https://unavatar.io/twitter/' . rawurlencode($handle);
            }
        }

        return [
            'title' => !empty($author) ? $author . ' on ' . (strpos($url, 'x.com') !== false ? 'X' : 'Twitter') : 'Post on ' . (strpos($url, 'x.com') !== false ? 'X' : 'Twitter'),
            'description' => $tweet_text,
            'image' => $og_image,
            'url' => $url,
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
            } else {
                $username = $segments[0];
            }
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
                    if (empty($username) && preg_match('/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']https?:\/\/(?:www\.)?instagram\.com\/([a-zA-Z0-9._]+)\//i', $html, $um)) {
                        if (!in_array($um[1], ['p', 'reel', 'reels', 'stories', 'tv'], true)) {
                            $username = $um[1];
                        }
                    }
                }
            }
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

        // Fetch author profile page to resolve actual profile avatar & display name
        if (!empty($username)) {
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
            'instagram_type' => $type,
            'url' => $clean_url,
            'is_embeddable' => true,
            'embed_type' => 'instagram',
        ];
    }

}
