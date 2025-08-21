<?php
/**
 * BitStream Shortcodes
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Shortcodes {
    
    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
    }
    
    /**
     * Register all shortcodes
     */
    public function register_shortcodes() {
        add_shortcode('bitstream', [$this, 'render_feed']);
    }
    
    /**
     * Render the main BitStream feed
     */
    public function render_feed($atts) {
        $atts = shortcode_atts([
            'posts_per_page' => 10,
            'paged' => get_query_var('paged') ?: 1,
            'limit' => '', // Limit total number of posts (e.g., "3" for latest 3)
            'infinite_scroll' => 'false', // Enable infinite scroll instead of load more button
            'show_load_more' => 'true' // Control whether to show load more button
        ], $atts);
        
        // If limit is set, override posts_per_page and disable pagination
        $posts_per_page = !empty($atts['limit']) ? intval($atts['limit']) : intval($atts['posts_per_page']);
        $paged = !empty($atts['limit']) ? 1 : intval($atts['paged']);
        
        $q = new WP_Query([
            'post_type' => 'bit',
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        $max = $q->max_num_pages;
        $infinite_scroll = ($atts['infinite_scroll'] === 'true' || $atts['infinite_scroll'] === '1');
        $show_load_more = ($atts['show_load_more'] === 'true' || $atts['show_load_more'] === '1');
        $has_limit = !empty($atts['limit']);
        
        ob_start();
        if ($q->have_posts()) {
            $current_page = intval($paged);
            $feed_classes = 'bitstream-feed';
            if ($infinite_scroll) {
                $feed_classes .= ' bitstream-infinite-scroll';
            }
            
            echo '<div class="'.$feed_classes.'" data-page="'.$current_page.'" data-max-page="'.$max.'" data-infinite-scroll="'.($infinite_scroll ? 'true' : 'false').'">';
            while ($q->have_posts()) {
                $q->the_post();
                echo bitstream_render_card(get_the_ID());
            }
            echo '</div>';
            
            // Only show load more button if:
            // - There are more pages
            // - Not using limit parameter (which shows fixed number)
            // - show_load_more is true
            // - Not using infinite scroll (unless explicitly enabled)
            if ($max > 1 && !$has_limit && $show_load_more && !$infinite_scroll) {
                echo '<button id="bitstream-load-more" class="bitstream-load-more">Load More</button>';
            }
            
            // Add infinite scroll trigger if enabled
            if ($infinite_scroll && $max > 1 && !$has_limit) {
                echo '<div class="bitstream-scroll-trigger" style="height: 1px; margin-top: 20px;"></div>';
            }
        } else {
            echo '<p>No Bits found.</p>';
        }
        
        wp_reset_postdata();
        return ob_get_clean();
    }
}
