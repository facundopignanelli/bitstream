<?php
/**
 * BitStream Post Type Handler
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Post_Type {
    
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('save_post', [$this, 'auto_generate_title']);
        add_filter('wp_insert_post_data', [$this, 'enable_comments'], 10, 2);
    }
    
    /**
     * Register the Bit custom post type
     */
    public function register_post_type() {
        $labels = [
            'name'               => _x('Bits', 'Post Type General Name', 'bitstream'),
            'singular_name'      => _x('Bit', 'Post Type Singular Name', 'bitstream'),
            'menu_name'          => __('BitStream', 'bitstream'),
            'name_admin_bar'     => __('Bit', 'bitstream'),
            'add_new_item'       => __('Add New Bit', 'bitstream'),
            'edit_item'          => __('Edit Bit', 'bitstream'),
            'new_item'           => __('New Bit', 'bitstream'),
            'view_item'          => __('View Bit', 'bitstream'),
            'all_items'          => __('All Bits', 'bitstream'),
            'search_items'       => __('Search Bits', 'bitstream'),
        ];
        
        $args = [
            'label'               => __('Bit', 'bitstream'),
            'labels'              => $labels,
            'supports'            => ['editor', 'author', 'custom-fields', 'comments'],
            'public'              => true,
            'show_in_menu'        => true,
            'menu_position'       => 5,
            'menu_icon'           => 'dashicons-format-status',
            'has_archive'         => true,
            'rewrite'             => ['slug' => 'bitstream'],
            'show_in_rest'        => true,
        ];
        
        register_post_type('bit', $args);
    }
    
    /**
     * Auto-generate Bit titles with optimized caching
     */
    public function auto_generate_title($post_id) {
        if (get_post_type($post_id) !== 'bit') return;
        
        remove_action('save_post', [$this, 'auto_generate_title']);
        
        $post_date = get_post_field('post_date', $post_id);
        $date = new DateTime($post_date);
        $date_str = $date->format('Y-m-d');
        
        // Use transient caching for daily count
        $cache_key = 'bitstream_daily_count_' . $date_str;
        $count = get_transient($cache_key);
        
        if (false === $count) {
            // Only query when cache expires
            $bits = get_posts([
                'post_type'      => 'bit',
                'post_status'    => 'publish',
                'date_query'     => [[
                    'year'  => $date->format('Y'),
                    'month' => $date->format('m'),
                    'day'   => $date->format('d'),
                ]],
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ]);
            $count = count($bits);
            set_transient($cache_key, $count, DAY_IN_SECONDS);
        }
        
        $count++;
        set_transient($cache_key, $count, DAY_IN_SECONDS);
        $count_str = str_pad($count, 3, '0', STR_PAD_LEFT);
        $new_title = 'Bit #' . $date_str . ':' . $count_str;
        
        // Create a SEO-friendly slug
        $slug = 'bit-' . $date_str . '-' . $count_str;
        
        wp_update_post([
            'ID' => $post_id, 
            'post_title' => $new_title,
            'post_name' => $slug
        ]);
        
        add_action('save_post', [$this, 'auto_generate_title']);
    }
    
    /**
     * Enable comments by default on new Bits
     */
    public function enable_comments($data, $postarr) {
        if ($data['post_type'] === 'bit' && empty($postarr['ID'])) {
            $data['comment_status'] = 'open';
        }
        return $data;
    }
}

new BitStream_Post_Type();
