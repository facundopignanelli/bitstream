<?php
/**
 * BitStream OG Data Fetcher
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_OG_Fetcher {
    
    public function __construct() {
        // Remove background OG fetching since we now do it immediately via AJAX
        // add_action('save_post_bit', [$this, 'schedule_og_fetch'], 10, 3);
        // add_action('bitstream_fetch_og_data', [$this, 'process_og_data'], 10, 2);
    }
    
    /**
     * Fetch OpenGraph data for a URL
     */
    public function fetch_og_data($url) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $resp = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]
        ]);
        
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return false;
        }
        
        $html = wp_remote_retrieve_body($resp);
        $og_title = $og_desc = $og_img = '';
        
        // Extract OG data
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $og_title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $og_desc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $og_img = $m[1];
        }
        
        // Fallback to title tag if no OG title
        if (empty($og_title) && preg_match('/<title>(.*?)<\/title>/', $html, $m)) {
            $og_title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
        }
        
        // Return the data
        return [
            'title' => $og_title,
            'description' => $og_desc,
            'image' => $og_img,
            'url' => $url
        ];
    }
    
    /**
     * Schedule background OG data fetching
     */
    public function schedule_og_fetch($post_id, $post, $update) {
        if ($post->post_type !== 'bit') return;
        
        $url = get_post_meta($post_id, 'bitstream_rebit_url', true);
        if (empty($url) || get_post_meta($post_id, '_bitstream_og_fetched', true)) return;
        
        // Schedule background OG fetching instead of blocking
        wp_schedule_single_event(time() + 10, 'bitstream_fetch_og_data', [$post_id, $url]);
    }
    
    /**
     * Process OG data fetching in background
     */
    public function process_og_data($post_id, $url) {
        $resp = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return;
        
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
