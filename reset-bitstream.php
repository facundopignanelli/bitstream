<?php
/**
 * BitStream Reset/Uninstall Script
 * 
 * Removes all BitStream plugin data, posts, and attachments.
 * Run this manually via WP-CLI or by uploading to wp-content and accessing via browser.
 * 
 * Usage:
 * 1. Via WP-CLI: wp user list to confirm admin ID, then run this script
 * 2. Via browser: Upload to wp-content, access http://yoursite.test/wp-content/reset-bitstream.php?confirm=1
 * 3. Via terminal in WordPress root: php wp-load.php && php wp-content/reset-bitstream.php
 * 
 * WARNING: This will permanently delete all BitStream posts and media!
 */

// Ensure we're in a WordPress environment
if (!defined('ABSPATH')) {
    // Try to load WordPress
    if (file_exists(__DIR__ . '/../../wp-load.php')) {
        require_once __DIR__ . '/../../wp-load.php';
    } elseif (file_exists(__DIR__ . '/wp-load.php')) {
        require_once __DIR__ . '/wp-load.php';
    } else {
        die('Could not find WordPress. Please run this from within a WordPress installation.');
    }
}

// Security: require confirmation parameter and admin capability
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('You must be logged in as an administrator to run this script.');
}

if (php_sapi_name() !== 'cli' && (!isset($_GET['confirm']) || $_GET['confirm'] !== '1')) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>BitStream Reset</title>
        <style>
            body { font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; }
            .warning { background: #fff8dc; border: 1px solid #f0ad4e; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
            .warning h2 { color: #8a6d3b; margin-top: 0; }
            .warning ul { margin: 10px 0; }
            .warning li { margin: 5px 0; }
            button { padding: 10px 20px; font-size: 16px; cursor: pointer; background: #d9534f; color: white; border: none; border-radius: 4px; }
            button:hover { background: #c9302c; }
        </style>
    </head>
    <body>
        <div class="warning">
            <h2>⚠️ BitStream Complete Reset</h2>
            <p><strong>This will permanently delete:</strong></p>
            <ul>
                <li>All BitStream posts (Bits and ReBits)</li>
                <li>All associated media files and attachments</li>
                <li>All BitStream plugin settings and metadata</li>
                <li>Scheduled events and cron jobs</li>
            </ul>
            <p><strong>This action cannot be undone!</strong></p>
            <p>To proceed, click the button below:</p>
            <form method="get">
                <input type="hidden" name="confirm" value="1" />
                <button type="submit">I understand, delete everything</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

echo "Starting BitStream reset...\n\n";

// 1. Delete all BitStream posts and their attachments
echo "1. Deleting all BitStream posts and associated media...\n";

$posts = new WP_Query([
    'post_type' => 'bit',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'post_status' => 'any',
]);

$post_count = 0;
$attachment_count = 0;

foreach ($posts->posts as $post_id) {
    $post_id = intval($post_id);
    
    // Get all attachments for this post
    $args = [
        'post_parent' => $post_id,
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_status' => 'any',
    ];
    $attachments = get_posts($args);
    
    foreach ($attachments as $attachment_id) {
        wp_delete_attachment(intval($attachment_id), true);
        $attachment_count++;
    }
    
    // Delete post
    wp_delete_post($post_id, true);
    $post_count++;
}

echo "  ✓ Deleted {$post_count} posts and {$attachment_count} attachments\n\n";

// 2. Delete orphaned BitStream-related attachments
echo "2. Cleaning up orphaned BitStream media files...\n";

$orphaned = new WP_Query([
    'post_type' => 'attachment',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'post_status' => 'any',
    'meta_query' => [
        [
            'key' => '_bitstream_uploaded_via_poster',
            'compare' => 'EXISTS',
        ],
    ],
]);

$orphan_count = 0;
foreach ($orphaned->posts as $attachment_id) {
    wp_delete_attachment(intval($attachment_id), true);
    $orphan_count++;
}

echo "  ✓ Deleted {$orphan_count} orphaned BitStream attachments\n\n";

// 3. Delete BitStream postmeta entries
echo "3. Removing postmeta entries...\n";

global $wpdb;

$meta_keys = [
    '_bitstream_uploaded_via_poster',
    '_bitstream_upload_created_at',
    '_bitstream_attachment_id',
    '_bitstream_generated_artwork_id',
];

$deleted_meta = 0;
foreach ($meta_keys as $meta_key) {
    $deleted_meta += $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key], ['%s']);
}

echo "  ✓ Deleted {$deleted_meta} postmeta entries\n\n";

// 4. Delete BitStream options
echo "4. Removing plugin settings...\n";

$bitstream_options = [
    'bitstream_permalinks_flushed',
    'bitstream_rebit_mappings',
    'bitstream_last_weekly_media_cleanup',
];

foreach ($bitstream_options as $option) {
    delete_option($option);
}

echo "  ✓ Deleted " . count($bitstream_options) . " plugin options\n\n";

// 5. Delete BitStream artwork directory
echo "5. Removing generated artwork files...\n";

$upload_dir = wp_upload_dir();
$artwork_dir = $upload_dir['basedir'] . '/bitstream-artwork';

if (is_dir($artwork_dir)) {
    $files = glob($artwork_dir . '/*');
    $deleted_files = 0;
    
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            $deleted_files++;
        }
    }
    
    if (rmdir($artwork_dir)) {
        echo "  ✓ Deleted {$deleted_files} artwork files and directory\n\n";
    } else {
        echo "  ⚠ Deleted {$deleted_files} artwork files, but directory still has content\n\n";
    }
} else {
    echo "  ✓ No artwork directory found\n\n";
}

// 6. Clear scheduled cron events
echo "6. Clearing scheduled cleanup events...\n";

$cleared = wp_clear_scheduled_hook('bitstream_weekly_media_cleanup_event');

echo "  ✓ Cleared scheduled cron events\n\n";

// 5. Summary
echo "=== Reset Complete ===\n";
echo "✓ All BitStream posts deleted\n";
echo "✓ All associated media removed\n";
echo "✓ Plugin settings cleared\n";
echo "✓ Scheduled events removed\n\n";

echo "You can now:\n";
echo "1. Deactivate and delete the BitStream plugin from the WordPress admin\n";
echo "2. Or reinstall the plugin and try again from scratch\n";

// For CLI usage, exit cleanly
if (php_sapi_name() === 'cli') {
    echo "\nDone.\n";
    exit(0);
}
?>
