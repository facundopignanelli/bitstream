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
        add_action('init', [$this, 'handle_quick_post_submission']);
    }
    
    /**
     * Register all shortcodes
     */
    public function register_shortcodes() {
        add_shortcode('bitstream', [$this, 'render_feed']);
        add_shortcode('bitstream_quick_post', [$this, 'render_quick_post']);
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
    
    /**
     * Render the quick post form
     */
    public function render_quick_post() {
        global $bitstream_quick_post_page;
        $bitstream_quick_post_page = true;

        if (!is_user_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<div class="wp-block-button bitstream-login-button"><a class="wp-block-button__link wp-element-button" href="'.esc_url($login).'"><strong>Log in to post</strong></a></div>';
        }

        ob_start();
        echo '<form method="post" class="bitstream-form">';
        wp_nonce_field('bitstream_quick_new','bitstream_nonce');
        echo '<input type="hidden" name="bitstream_quick_post_submit" value="1" />';
        echo '<p><label>Content<br><textarea name="bit_content" rows="5" class="bitstream-content" style="width:100%;"></textarea></label></p>';
        echo '<p><label>ReBit URL<br><input type="url" name="bit_rebit_url" class="bitstream-rebit-url" style="width:100%;"></label></p>';
        echo '<p><label>Image<br>';
        echo '<input type="hidden" name="bit_image_id" id="bit_image_id" value="">';
        echo '<button type="button" id="bitstream-select-image" class="button">Select Image from Media Library</button>';
        echo '<span id="bitstream-image-preview" style="display:block;margin-top:8px;"></span>';
        echo '</label></p>';
        echo '<div class="wp-block-button bitstream-post-button"><button type="submit" class="wp-block-button__link wp-element-button"><strong>Post Bit</strong></button></div>';
        echo '</form>';
        echo '<div class="wp-block-button bitstream-full-editor" style="margin-top:13px;text-align:center;"><a class="wp-block-button__link wp-element-button" href="'.esc_url(admin_url('post-new.php?post_type=bit')).'"><strong>Open Full Editor</strong></a></div>';
        
        return ob_get_clean();
    }
    
    /**
     * Handle quick post form submission with security checks
     */
    public function handle_quick_post_submission() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bitstream_quick_post_submit']) && check_admin_referer('bitstream_quick_new','bitstream_nonce')) {
            if (!is_user_logged_in()) {
                wp_die(__('You must be logged in to post','bitstream'));
            }
            
            // Additional capability check
            if (!current_user_can('edit_posts')) {
                wp_die(__('You do not have permission to create posts','bitstream'));
            }
            
            $content   = wp_kses_post($_POST['bit_content'] ?? '');
            $rebit_url = isset($_POST['bit_rebit_url']) ? esc_url_raw($_POST['bit_rebit_url']) : '';
            $has_image = !empty($_POST['bit_image_id']) && wp_get_attachment_url(intval($_POST['bit_image_id']));
            
            if ($content === '' && $rebit_url === '' && !$has_image) {
                wp_die(__('Content, ReBit URL, or an Image is required', 'bitstream'));
            }
            
            $post_id = wp_insert_post([
                'post_type'   => 'bit',
                'post_status' => 'publish',
                'post_title'  => '',
                'post_content'=> $content,
            ]);
            
            if ($post_id && !is_wp_error($post_id)) {
                if ($rebit_url) {
                    update_post_meta($post_id,'bitstream_rebit_url',$rebit_url);
                    // Schedule background OG fetching
                    wp_schedule_single_event(time() + 10, 'bitstream_fetch_og_data', [$post_id, $rebit_url]);
                }
                
                // Use selected image from media library
                if (!empty($_POST['bit_image_id'])) {
                    $img_id = intval($_POST['bit_image_id']);
                    $img_url = wp_get_attachment_url($img_id);
                    if ($img_url) {
                        $content .= "\n<img src='".esc_url($img_url)."' alt='' style='max-width:100%;height:auto;display:block;margin:0.5em auto;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,0.07);' />";
                        wp_update_post(['ID'=>$post_id,'post_content'=>$content]);
                    }
                }
                
                wp_redirect(home_url('/bitstream/'));
                exit;
            }
        }
    }
}

new BitStream_Shortcodes();
