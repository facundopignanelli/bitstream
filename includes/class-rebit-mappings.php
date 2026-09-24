<?php
/**
 * BitStream ReBit Mappings Manager
 * 
 * Handles ReBit domain mappings and configuration interface
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_ReBit_Mappings {
    
    const OPTION_KEY = 'bitstream_rebit_mappings';
    
    public function __construct() {
        // This class is mainly used for organization
        // All mappings are stored as options via static methods
    }
    
    /**
     * Get preset ReBit mappings for popular sites (single source of truth)
     */
    public static function get_rebit_presets() {
        return [
            'twitter'       => ['domain' => 'twitter.com', 'label' => 'shared a Tweet', 'icon' => 'fa-brands fa-twitter'],
            'x'             => ['domain' => 'x.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-x-twitter'],
            'youtube'       => ['domain' => 'youtube.com', 'label' => 'shared a video', 'icon' => 'fa-brands fa-youtube'],
            'youtube_short' => ['domain' => 'youtu.be', 'label' => 'shared a video', 'icon' => 'fa-brands fa-youtube'],
            'github'        => ['domain' => 'github.com', 'label' => 'shared a repository', 'icon' => 'fa-brands fa-github'],
            'linkedin'      => ['domain' => 'linkedin.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-linkedin'],
            'facebook'      => ['domain' => 'facebook.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-facebook'],
            'instagram'     => ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fa-brands fa-instagram'],
            'tiktok'        => ['domain' => 'tiktok.com', 'label' => 'shared a video', 'icon' => 'fa-brands fa-tiktok'],
            'reddit'        => ['domain' => 'reddit.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-reddit'],
            'spotify'       => ['domain' => 'spotify.com', 'label' => 'shared a song', 'icon' => 'fa-brands fa-spotify'],
            'twitch'        => ['domain' => 'twitch.tv', 'label' => 'shared a stream', 'icon' => 'fa-brands fa-twitch'],
            'discord'       => ['domain' => 'discord.com', 'label' => 'shared a message', 'icon' => 'fa-brands fa-discord'],
            'bluesky'       => ['domain' => 'bsky.app', 'label' => 'shared a post', 'icon' => 'fa-brands fa-bluesky'],
            'threads'       => ['domain' => 'threads.net', 'label' => 'shared a post', 'icon' => 'fa-brands fa-threads'],
            'mastodon'      => ['domain' => 'mastodon.social', 'label' => 'shared a toot', 'icon' => 'fa-brands fa-mastodon'],
            'pinterest'     => ['domain' => 'pinterest.com', 'label' => 'shared a pin', 'icon' => 'fa-brands fa-pinterest'],
            'medium'        => ['domain' => 'medium.com', 'label' => 'shared an article', 'icon' => 'fa-brands fa-medium'],
            'dev'           => ['domain' => 'dev.to', 'label' => 'shared an article', 'icon' => 'fa-brands fa-dev'],
            'hackernews'    => ['domain' => 'news.ycombinator.com', 'label' => 'shared a story', 'icon' => 'fa-brands fa-hacker-news'],
            'stackoverflow' => ['domain' => 'stackoverflow.com', 'label' => 'shared a question', 'icon' => 'fa-brands fa-stack-overflow'],
            'wikipedia'     => ['domain' => 'wikipedia.org', 'label' => 'shared an article', 'icon' => 'fa-brands fa-wikipedia-w'],
            'soundcloud'    => ['domain' => 'soundcloud.com', 'label' => 'shared a track', 'icon' => 'fa-brands fa-soundcloud'],
            'steam'         => ['domain' => 'steampowered.com', 'label' => 'shared a game', 'icon' => 'fa-brands fa-steam'],
            'bbc'           => ['domain' => 'bbc.com', 'label' => 'shared a news article', 'icon' => 'fa-solid fa-newspaper'],
            'cnn'           => ['domain' => 'cnn.com', 'label' => 'shared a news article', 'icon' => 'fa-solid fa-newspaper'],
            'nytimes'       => ['domain' => 'nytimes.com', 'label' => 'shared a news article', 'icon' => 'fa-solid fa-newspaper'],
        ];
    }
    
    /**
     * Normalize a domain string (removes protocol, path, slashes, trailing elements)
     */
    public static function normalize_domain($domain) {
        $domain = trim(strval($domain));
        if (empty($domain)) {
            return '';
        }
        if (strpos($domain, '://') !== false) {
            $parsed = parse_url($domain, PHP_URL_HOST);
            if (!empty($parsed)) {
                $domain = $parsed;
            }
        }
        $domain = preg_replace('~[/\\\\?#].*$~', '', $domain);
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('/^www\./i', '', $domain);
        return strtolower(trim($domain));
    }

    /**
     * Reset mappings to factory defaults
     */
    public static function reset_default_mappings() {
        $default_mappings = [
            ['domain' => 'twitter.com', 'label' => 'shared a Tweet', 'icon' => 'fa-brands fa-twitter'],
            ['domain' => 'x.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-x-twitter'],
            ['domain' => 'youtube.com', 'label' => 'shared a video', 'icon' => 'fa-brands fa-youtube'],
            ['domain' => 'youtu.be', 'label' => 'shared a video', 'icon' => 'fa-brands fa-youtube'],
            ['domain' => 'github.com', 'label' => 'shared a repository', 'icon' => 'fa-brands fa-github'],
            ['domain' => 'linkedin.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-linkedin'],
            ['domain' => 'facebook.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-facebook'],
            ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fa-brands fa-instagram'],
            ['domain' => 'reddit.com', 'label' => 'shared a post', 'icon' => 'fa-brands fa-reddit'],
            ['domain' => 'medium.com', 'label' => 'shared an article', 'icon' => 'fa-brands fa-medium'],
            ['domain' => 'spotify.com', 'label' => 'shared a song', 'icon' => 'fa-brands fa-spotify'],
            ['domain' => 'discord.com', 'label' => 'shared a message', 'icon' => 'fa-brands fa-discord'],
        ];
        update_option(self::OPTION_KEY, $default_mappings);
        return $default_mappings;
    }

    /**
     * Import default mappings if none exist (called on plugin activation)
     */
    public static function import_default_mappings() {
        $existing_mappings = self::get_all_mappings();
        
        // Only import if no mappings exist
        if (empty($existing_mappings)) {
            self::reset_default_mappings();
        }
    }
    
    /**
     * Import all default presets (admin action to restore/add defaults)
     */
    public static function import_all_presets() {
        $existing_mappings = self::get_all_mappings();
        $existing_domains = array_column($existing_mappings, 'domain');
        
        $presets = self::get_rebit_presets();
        
        // Add any preset not already present
        foreach ($presets as $preset) {
            if (!in_array($preset['domain'], $existing_domains)) {
                $existing_mappings[] = $preset;
            }
        }
        
        update_option(self::OPTION_KEY, $existing_mappings);
    }
    
    /**
     * Get all mappings
     */
    public static function get_all_mappings() {
        return get_option(self::OPTION_KEY, []);
    }
    
    /**
     * Get mapping for a specific domain
     */
    public static function get_mapping_for_domain($domain, $url = '') {
        $mappings = self::get_all_mappings();

        // Dynamic path-based mapping for Instagram URLs (posts, reels, stories)
        if (!empty($url) && stripos($domain, 'instagram.com') !== false) {
            $parsed_path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');
            $segments = explode('/', $parsed_path);
            if (!empty($segments[0])) {
                if (in_array($segments[0], ['reel', 'reels'], true)) {
                    return ['domain' => 'instagram.com', 'label' => 'shared a reel', 'icon' => 'fab fa-instagram'];
                } elseif ($segments[0] === 'stories') {
                    return ['domain' => 'instagram.com', 'label' => 'shared a story', 'icon' => 'fab fa-instagram'];
                } elseif ($segments[0] === 'p') {
                    return ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fab fa-instagram'];
                }
            }
        }
        
        foreach ($mappings as $mapping) {
            if (stripos($domain, $mapping['domain']) !== false) {
                return $mapping;
            }
        }
        
        // Fallback domain normalization for YouTube (e.g. youtu.be / youtube-nocookie.com -> youtube.com)
        $normalized_domain = null;
        if (stripos($domain, 'youtu.be') !== false || stripos($domain, 'youtube-nocookie.com') !== false) {
            $normalized_domain = 'youtube.com';
        }
        
        if ($normalized_domain) {
            foreach ($mappings as $mapping) {
                if (stripos($normalized_domain, $mapping['domain']) !== false) {
                    return $mapping;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Add a new mapping
     */
    public static function add_mapping($domain, $label, $icon) {
        $domain = self::normalize_domain(sanitize_text_field($domain));
        $label = sanitize_text_field($label);
        $icon = sanitize_text_field($icon);
        
        if (empty($domain) || empty($label) || empty($icon)) {
            return false;
        }
        
        $mappings = self::get_all_mappings();
        
        // Check if domain already exists
        foreach ($mappings as $mapping) {
            if ($mapping['domain'] === $domain) {
                return false; // Domain already exists
            }
        }
        
        $mappings[] = [
            'domain' => $domain,
            'label' => $label,
            'icon' => $icon
        ];
        
        return update_option(self::OPTION_KEY, $mappings);
    }
    
    /**
     * Update an existing mapping by domain
     */
    public static function update_mapping($old_domain, $new_domain, $label, $icon) {
        $old_domain = self::normalize_domain(sanitize_text_field($old_domain));
        $new_domain = self::normalize_domain(sanitize_text_field($new_domain));
        $label = sanitize_text_field($label);
        $icon = sanitize_text_field($icon);
        
        if (empty($new_domain) || empty($label) || empty($icon)) {
            return false;
        }
        
        $mappings = self::get_all_mappings();
        
        // Check for collision if changing domain
        if ($new_domain !== $old_domain) {
            foreach ($mappings as $m) {
                if ($m['domain'] === $new_domain) {
                    return false;
                }
            }
        }

        $found = false;
        foreach ($mappings as &$mapping) {
            if ($mapping['domain'] === $old_domain) {
                $mapping['domain'] = $new_domain;
                $mapping['label'] = $label;
                $mapping['icon'] = $icon;
                $found = true;
                break;
            }
        }
        
        if ($found) {
            return update_option(self::OPTION_KEY, $mappings);
        }
        
        return false;
    }
    
    /**
     * Remove a mapping by domain
     */
    public static function remove_mapping($domain) {
        $domain = self::normalize_domain(sanitize_text_field($domain));
        $mappings = self::get_all_mappings();
        
        $original_count = count($mappings);
        $mappings = array_filter($mappings, function($mapping) use ($domain) {
            return $mapping['domain'] !== $domain;
        });
        
        if (count($mappings) < $original_count) {
            return update_option(self::OPTION_KEY, array_values($mappings));
        }
        
        return false;
    }
    
    /**
     * Save all mappings (bulk update from admin form)
     */
    public static function save_mappings($mappings) {
        if (!is_array($mappings)) {
            return false;
        }
        
        $sanitized = [];
        foreach ($mappings as $mapping) {
            $domain = self::normalize_domain(sanitize_text_field($mapping['domain'] ?? ''));
            $label = sanitize_text_field($mapping['label'] ?? '');
            $icon = sanitize_text_field($mapping['icon'] ?? '');
            
            if (!empty($domain) && !empty($label) && !empty($icon)) {
                // Skip duplicates
                $is_duplicate = false;
                foreach ($sanitized as $existing) {
                    if ($existing['domain'] === $domain) {
                        $is_duplicate = true;
                        break;
                    }
                }
                
                if (!$is_duplicate) {
                    $sanitized[] = [
                        'domain' => $domain,
                        'label' => $label,
                        'icon' => $icon,
                    ];
                }
            }
        }
        
        return update_option(self::OPTION_KEY, $sanitized);
    }
}
