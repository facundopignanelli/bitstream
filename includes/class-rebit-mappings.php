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
    
    public function __construct() {
        // This class is mainly used for organization
        // The admin interface is handled by BitStream_Admin_Interface
    }
    
    /**
     * Get preset ReBit mappings for popular sites
     */
    public static function get_rebit_presets() {
        return [
            'twitter' => ['domain' => 'twitter.com', 'label' => 'shared from Twitter', 'icon' => 'fab fa-twitter'],
            'x' => ['domain' => 'x.com', 'label' => 'shared from X', 'icon' => 'fab fa-x-twitter'],
            'youtube' => ['domain' => 'youtube.com', 'label' => 'shared from YouTube', 'icon' => 'fab fa-youtube'],
            'github' => ['domain' => 'github.com', 'label' => 'shared from GitHub', 'icon' => 'fab fa-github'],
            'linkedin' => ['domain' => 'linkedin.com', 'label' => 'shared from LinkedIn', 'icon' => 'fab fa-linkedin'],
            'facebook' => ['domain' => 'facebook.com', 'label' => 'shared from Facebook', 'icon' => 'fab fa-facebook'],
            'instagram' => ['domain' => 'instagram.com', 'label' => 'shared from Instagram', 'icon' => 'fab fa-instagram'],
            'tiktok' => ['domain' => 'tiktok.com', 'label' => 'shared from TikTok', 'icon' => 'fab fa-tiktok'],
            'reddit' => ['domain' => 'reddit.com', 'label' => 'shared from Reddit', 'icon' => 'fab fa-reddit'],
            'medium' => ['domain' => 'medium.com', 'label' => 'shared from Medium', 'icon' => 'fab fa-medium'],
            'dev' => ['domain' => 'dev.to', 'label' => 'shared from DEV', 'icon' => 'fab fa-dev'],
            'hackernews' => ['domain' => 'news.ycombinator.com', 'label' => 'shared from Hacker News', 'icon' => 'fab fa-hacker-news'],
            'stackoverflow' => ['domain' => 'stackoverflow.com', 'label' => 'shared from Stack Overflow', 'icon' => 'fab fa-stack-overflow'],
            'wikipedia' => ['domain' => 'wikipedia.org', 'label' => 'shared from Wikipedia', 'icon' => 'fab fa-wikipedia-w'],
            'bbc' => ['domain' => 'bbc.com', 'label' => 'shared from BBC', 'icon' => 'fas fa-newspaper'],
            'cnn' => ['domain' => 'cnn.com', 'label' => 'shared from CNN', 'icon' => 'fas fa-newspaper'],
            'nytimes' => ['domain' => 'nytimes.com', 'label' => 'shared from NY Times', 'icon' => 'fas fa-newspaper'],
            'spotify' => ['domain' => 'spotify.com', 'label' => 'shared from Spotify', 'icon' => 'fab fa-spotify'],
            'twitch' => ['domain' => 'twitch.tv', 'label' => 'shared from Twitch', 'icon' => 'fab fa-twitch'],
            'discord' => ['domain' => 'discord.com', 'label' => 'shared from Discord', 'icon' => 'fab fa-discord'],
        ];
    }
    
    /**
     * Get mapping for a specific domain
     */
    public static function get_mapping_for_domain($domain) {
        $mappings = get_option('bitstream_rebit_mappings', []);
        
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
        $mappings = get_option('bitstream_rebit_mappings', []);
        
        // Check if domain already exists
        foreach ($mappings as $mapping) {
            if ($mapping['domain'] === $domain) {
                return false; // Domain already exists
            }
        }
        
        $mappings[] = [
            'domain' => sanitize_text_field($domain),
            'label' => sanitize_text_field($label),
            'icon' => sanitize_text_field($icon)
        ];
        
        return update_option('bitstream_rebit_mappings', $mappings);
    }
    
    /**
     * Remove a mapping by domain
     */
    public static function remove_mapping($domain) {
        $mappings = get_option('bitstream_rebit_mappings', []);
        
        $mappings = array_filter($mappings, function($mapping) use ($domain) {
            return $mapping['domain'] !== $domain;
        });
        
        return update_option('bitstream_rebit_mappings', array_values($mappings));
    }
    
    /**
     * Get all mappings
     */
    public static function get_all_mappings() {
        return get_option('bitstream_rebit_mappings', []);
    }
}
