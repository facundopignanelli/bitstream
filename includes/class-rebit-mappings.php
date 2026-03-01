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
            'twitter' => ['domain' => 'twitter.com', 'label' => 'shared a Tweet', 'icon' => 'fab fa-twitter'],
            'x' => ['domain' => 'x.com', 'label' => 'shared a post', 'icon' => 'fab fa-x-twitter'],
            'youtube' => ['domain' => 'youtube.com', 'label' => 'shared a video', 'icon' => 'fab fa-youtube'],
            'github' => ['domain' => 'github.com', 'label' => 'shared a repository', 'icon' => 'fab fa-github'],
            'linkedin' => ['domain' => 'linkedin.com', 'label' => 'shared a post', 'icon' => 'fab fa-linkedin'],
            'facebook' => ['domain' => 'facebook.com', 'label' => 'shared a post', 'icon' => 'fab fa-facebook'],
            'instagram' => ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fab fa-instagram'],
            'tiktok' => ['domain' => 'tiktok.com', 'label' => 'shared a video', 'icon' => 'fab fa-tiktok'],
            'reddit' => ['domain' => 'reddit.com', 'label' => 'shared a post', 'icon' => 'fab fa-reddit'],
            'medium' => ['domain' => 'medium.com', 'label' => 'shared an article', 'icon' => 'fab fa-medium'],
            'dev' => ['domain' => 'dev.to', 'label' => 'shared an article', 'icon' => 'fab fa-dev'],
            'hackernews' => ['domain' => 'news.ycombinator.com', 'label' => 'shared a story', 'icon' => 'fab fa-hacker-news'],
            'stackoverflow' => ['domain' => 'stackoverflow.com', 'label' => 'shared a question', 'icon' => 'fab fa-stack-overflow'],
            'wikipedia' => ['domain' => 'wikipedia.org', 'label' => 'shared an article', 'icon' => 'fab fa-wikipedia-w'],
            'bbc' => ['domain' => 'bbc.com', 'label' => 'shared a news article', 'icon' => 'fas fa-newspaper'],
            'cnn' => ['domain' => 'cnn.com', 'label' => 'shared a news article', 'icon' => 'fas fa-newspaper'],
            'nytimes' => ['domain' => 'nytimes.com', 'label' => 'shared a news article', 'icon' => 'fas fa-newspaper'],
            'spotify' => ['domain' => 'spotify.com', 'label' => 'shared a song', 'icon' => 'fab fa-spotify'],
            'twitch' => ['domain' => 'twitch.tv', 'label' => 'shared a stream', 'icon' => 'fab fa-twitch'],
            'discord' => ['domain' => 'discord.com', 'label' => 'shared a message', 'icon' => 'fab fa-discord'],
        ];
    }
    
    /**
     * Import default mappings if none exist (called on plugin activation)
     */
    public static function import_default_mappings() {
        $existing_mappings = self::get_all_mappings();
        
        // Only import if no mappings exist
        if (empty($existing_mappings)) {
            $default_mappings = [
                ['domain' => 'twitter.com', 'label' => 'shared a Tweet', 'icon' => 'fab fa-twitter'],
                ['domain' => 'x.com', 'label' => 'shared a post', 'icon' => 'fab fa-x-twitter'],
                ['domain' => 'youtube.com', 'label' => 'shared a video', 'icon' => 'fab fa-youtube'],
                ['domain' => 'github.com', 'label' => 'shared a repository', 'icon' => 'fab fa-github'],
                ['domain' => 'linkedin.com', 'label' => 'shared a post', 'icon' => 'fab fa-linkedin'],
                ['domain' => 'facebook.com', 'label' => 'shared a post', 'icon' => 'fab fa-facebook'],
                ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fab fa-instagram'],
                ['domain' => 'reddit.com', 'label' => 'shared a post', 'icon' => 'fab fa-reddit'],
                ['domain' => 'medium.com', 'label' => 'shared an article', 'icon' => 'fab fa-medium'],
            ];
            
            update_option(self::OPTION_KEY, $default_mappings);
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
    public static function get_mapping_for_domain($domain) {
        $mappings = self::get_all_mappings();
        
        foreach ($mappings as $mapping) {
            if (stripos($domain, $mapping['domain']) !== false) {
                return $mapping;
            }
        }
        
        return null;
    }
    
    /**
     * Add a new mapping
     */
    public static function add_mapping($domain, $label, $icon) {
        $domain = sanitize_text_field($domain);
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
        $new_domain = sanitize_text_field($new_domain);
        $label = sanitize_text_field($label);
        $icon = sanitize_text_field($icon);
        
        if (empty($new_domain) || empty($label) || empty($icon)) {
            return false;
        }
        
        $mappings = self::get_all_mappings();
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
            $domain = sanitize_text_field($mapping['domain'] ?? '');
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
