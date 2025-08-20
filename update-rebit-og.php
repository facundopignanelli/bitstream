<?php
/**
 * Temporary script to update OpenGraph data for existing ReBit posts
 * Run this once to populate OG data for posts created before the new system
 */

// Load WordPress
require_once('../../../../../../../wordpress/wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied');
}

// Find all ReBit posts that have a URL but no OG data
$posts = get_posts([
    'post_type' => 'bit',
    'numberposts' => -1,
    'meta_query' => [
        'relation' => 'AND',
        [
            'key' => 'bitstream_rebit_url',
            'value' => '',
            'compare' => '!='
        ],
        [
            'key' => '_bitstream_og_title',
            'compare' => 'NOT EXISTS'
        ]
    ]
]);

echo "Found " . count($posts) . " ReBit posts that need OG data update.\n";

foreach ($posts as $post) {
    $rebit_url = get_post_meta($post->ID, 'bitstream_rebit_url', true);
    echo "Updating post {$post->ID} with URL: {$rebit_url}\n";
    
    // Trigger the save action to fetch OG data
    do_action('save_post_bit', $post->ID, $post, false);
    
    // Small delay to be nice to external servers
    sleep(1);
}

echo "Done!\n";
?>
