<?php
/**
 * BitStream Share Debug Tool
 * 
 * Add this to the end of your functions.php to enable detailed logging
 * of share target requests
 */

// Enable debug logging for share targets
add_action('init', function() {
    if (isset($_GET['bitstream_debug_share'])) {
        error_log('=== BitStream Share Debug ===');
        error_log('Request URI: ' . $_SERVER['REQUEST_URI']);
        error_log('GET parameters: ' . print_r($_GET, true));
        error_log('POST parameters: ' . print_r($_POST, true));
        error_log('User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Not set'));
        error_log('Referer: ' . ($_SERVER['HTTP_REFERER'] ?? 'Not set'));
        
        // Check if this looks like a share target request
        if (strpos($_SERVER['REQUEST_URI'], '/bitstream/new-rebit/') !== false) {
            error_log('This appears to be a share target request');
            
            // Check for share parameters
            $share_params = [];
            if (isset($_GET['url'])) $share_params['url'] = $_GET['url'];
            if (isset($_GET['title'])) $share_params['title'] = $_GET['title'];
            if (isset($_GET['text'])) $share_params['text'] = $_GET['text'];
            
            if (!empty($share_params)) {
                error_log('Share parameters found: ' . print_r($share_params, true));
            } else {
                error_log('No share parameters found - this might be the issue');
            }
        }
        
        // Don't actually process the request, just log and stop
        wp_die('Debug info logged to error log. Check your WordPress error log file.');
    }
});

// Add debug parameter detection to all BitStream requests
add_action('template_redirect', function() {
    if (strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false) {
        error_log('BitStream request detected: ' . $_SERVER['REQUEST_URI']);
        if (!empty($_GET)) {
            error_log('BitStream request parameters: ' . print_r($_GET, true));
        }
    }
}, 1);
?>
