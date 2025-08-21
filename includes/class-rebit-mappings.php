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
