<?php
/**
 * BitStream Post Type Handler
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class BitStream_Post_Type
{

    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('save_post', [$this, 'auto_generate_title']);
        add_filter('wp_insert_post_data', [$this, 'enable_comments'], 10, 2);

        // Search filters to include ReBit metadata
        add_filter('posts_join', [$this, 'search_join'], 10, 2);
        add_filter('posts_search', [$this, 'search_posts'], 10, 2);
        add_filter('posts_distinct', [$this, 'search_distinct'], 10, 2);
    }

    /**
     * Determine whether a query is a Bit search query.
     */
    private function is_bit_search_query($query)
    {
        if (!$query || !method_exists($query, 'is_search') || !$query->is_search()) {
            return false;
        }

        $post_type = $query->get('post_type');
        return $post_type === 'bit' || (is_array($post_type) && in_array('bit', $post_type, true));
    }

    /**
     * Join postmeta table for searching ReBit metadata
     */
    public function search_join($join, $query)
    {
        if ($this->is_bit_search_query($query)) {
            global $wpdb;
            $join .= " LEFT JOIN {$wpdb->postmeta} AS bitstream_search_meta ON ({$wpdb->posts}.ID = bitstream_search_meta.post_id) ";
        }
        return $join;
    }

    /**
     * Include ReBit metadata fields in search query without regex manipulation.
     */
    public function search_posts($search, $query)
    {
        if (!$this->is_bit_search_query($query)) {
            return $search;
        }

        global $wpdb;

        $search_terms = $query->get('search_terms');
        if (empty($search_terms)) {
            $raw_term = (string) $query->get('s');
            if ($raw_term !== '') {
                $search_terms = [$raw_term];
            }
        }

        if (empty($search_terms) || !is_array($search_terms)) {
            return $search;
        }

        $exact = (bool) $query->get('exact');
        $wildcard = $exact ? '' : '%';

        $meta_conditions = [];
        foreach ($search_terms as $term) {
            if ($term === '') {
                continue;
            }

            $like = $wildcard . $wpdb->esc_like($term) . $wildcard;
            $meta_conditions[] = $wpdb->prepare(
                "(bitstream_search_meta.meta_key IN (%s, %s, %s) AND bitstream_search_meta.meta_value LIKE %s)",
                '_bitstream_og_title',
                '_bitstream_og_desc',
                'bitstream_rebit_url',
                $like
            );
        }

        if (empty($meta_conditions)) {
            return $search;
        }

        $meta_search_sql = '(' . implode(' AND ', $meta_conditions) . ')';

        if (!empty($search)) {
            $trimmed_search = rtrim($search);
            if (substr($trimmed_search, -1) === ')') {
                return substr($trimmed_search, 0, -1) . " OR {$meta_search_sql})";
            }
            return $trimmed_search . " OR {$meta_search_sql}";
        }

        return " AND {$meta_search_sql} ";
    }

    /**
     * Ensure DISTINCT results to prevent duplicates from metadata search
     */
    public function search_distinct($distinct, $query)
    {
        if ($this->is_bit_search_query($query)) {
            return "DISTINCT";
        }
        return $distinct;
    }

    /**
     * Register the Bit custom post type
     */
    public function register_post_type()
    {
        $labels = [
            'name' => _x('Bits', 'Post Type General Name', 'bitstream'),
            'singular_name' => _x('Bit', 'Post Type Singular Name', 'bitstream'),
            'menu_name' => __('BitStream', 'bitstream'),
            'name_admin_bar' => __('Bit', 'bitstream'),
            'add_new_item' => __('Add New Bit', 'bitstream'),
            'edit_item' => __('Edit Bit', 'bitstream'),
            'new_item' => __('New Bit', 'bitstream'),
            'view_item' => __('View Bit', 'bitstream'),
            'all_items' => __('All Bits', 'bitstream'),
            'search_items' => __('Search Bits', 'bitstream'),
        ];

        $args = [
            'label' => __('Bit', 'bitstream'),
            'labels' => $labels,
            'supports' => ['editor', 'author', 'custom-fields', 'comments'],
            'public' => true,
            'show_in_menu' => true,
            'menu_position' => 5,
            'menu_icon' => 'dashicons-format-status',
            'has_archive' => true,
            'rewrite' => ['slug' => 'bitstream'],
            'show_in_rest' => true,
        ];

        register_post_type('bit', $args);
    }

    /**
     * Auto-generate Bit titles with optimized caching
     */
    public function auto_generate_title($post_id)
    {
        if (get_post_type($post_id) !== 'bit')
            return;

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
                'post_type' => 'bit',
                'post_status' => 'publish',
                'date_query' => [[
                        'year' => $date->format('Y'),
                        'month' => $date->format('m'),
                        'day' => $date->format('d'),
                    ]],
                'fields' => 'ids',
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
    public function enable_comments($data, $postarr)
    {
        if ($data['post_type'] === 'bit' && empty($postarr['ID'])) {
            $data['comment_status'] = 'open';
        }
        return $data;
    }
}
