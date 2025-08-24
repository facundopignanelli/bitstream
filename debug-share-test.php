<?php
/**
 * Debug Share Target Test Page
 * Access this page to test share target functionality
 */

// Log all incoming parameters to a file we can check
$log_entry = date('Y-m-d H:i:s') . " - Share Target Debug:\n";
$log_entry .= "GET: " . print_r($_GET, true) . "\n";
$log_entry .= "POST: " . print_r($_POST, true) . "\n";
$log_entry .= "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
$log_entry .= "USER_AGENT: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
$log_entry .= "---\n\n";

// Write to a debug log file
file_put_contents(__DIR__ . '/debug-share.log', $log_entry, FILE_APPEND | LOCK_EX);

?>
<!DOCTYPE html>
<html>
<head>
    <title>BitStream Share Debug</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5; 
        }
        .debug-section { 
            background: white; 
            padding: 15px; 
            margin: 10px 0; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb; 
        }
        .warning { 
            background: #fff3cd; 
            color: #856404; 
            border: 1px solid #ffeaa7; 
        }
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb; 
        }
        pre { 
            background: #f8f9fa; 
            padding: 10px; 
            border-radius: 4px; 
            overflow-x: auto; 
            font-size: 12px;
        }
        .big-text { 
            font-size: 18px; 
            font-weight: bold; 
        }
        .url-found { 
            background: #28a745; 
            color: white; 
            padding: 10px; 
            border-radius: 4px; 
            margin: 5px 0; 
        }
    </style>
</head>
<body>
    <div class="debug-section">
        <h1>🔍 BitStream Share Target Debug</h1>
        <p><strong>Timestamp:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
    
    <?php
    $has_any_params = !empty($_GET) || !empty($_POST);
    $shared_url = isset($_GET['url']) ? $_GET['url'] : '';
    $shared_title = isset($_GET['title']) ? $_GET['title'] : '';
    $shared_text = isset($_GET['text']) ? $_GET['text'] : '';
    $has_share_data = !empty($shared_url) || !empty($shared_title) || !empty($shared_text);
    ?>
    
    <div class="debug-section <?php echo $has_any_params ? 'success' : 'error'; ?>">
        <h2>📊 Share Target Status</h2>
        <?php if ($has_any_params): ?>
            <div class="big-text">✅ SHARE TARGET TRIGGERED!</div>
            <p>Parameters were received from the share action.</p>
        <?php else: ?>
            <div class="big-text">❌ NO PARAMETERS RECEIVED</div>
            <p>Share target was not triggered or no data was sent.</p>
        <?php endif; ?>
    </div>
    
    <?php if ($has_share_data): ?>
    <div class="debug-section success">
        <h2>📋 Shared Content</h2>
        <div class="big-text">✅ CONTENT DETECTED!</div>
        <ul>
            <?php if ($shared_url): ?>
                <li><strong>URL:</strong> <code><?php echo htmlspecialchars($shared_url); ?></code></li>
            <?php endif; ?>
            <?php if ($shared_title): ?>
                <li><strong>Title:</strong> <?php echo htmlspecialchars($shared_title); ?></li>
            <?php endif; ?>
            <?php if ($shared_text): ?>
                <li><strong>Text:</strong> <?php echo htmlspecialchars($shared_text); ?></li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <div class="debug-section">
        <h2>🔗 URL Extraction Test</h2>
        <?php
        $all_content = $shared_url . ' ' . $shared_text . ' ' . $shared_title;
        preg_match_all('/https?:\/\/[^\s]+/', $all_content, $matches);
        $found_urls = $matches[0];
        ?>
        <?php if (!empty($found_urls)): ?>
            <div class="big-text">✅ URLS FOUND: <?php echo count($found_urls); ?></div>
            <?php foreach ($found_urls as $url): ?>
                <div class="url-found">
                    <strong>Extracted URL:</strong><br>
                    <?php echo htmlspecialchars($url); ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="big-text">❌ NO URLS DETECTED</div>
            <p>No URLs found in the shared content.</p>
        <?php endif; ?>
    </div>
    
    <div class="debug-section">
        <h2>🔧 Technical Details</h2>
        <p><strong>Request Method:</strong> <?php echo $_SERVER['REQUEST_METHOD']; ?></p>
        <p><strong>Request URI:</strong> <?php echo $_SERVER['REQUEST_URI']; ?></p>
        <p><strong>User Agent:</strong> <?php echo substr($_SERVER['HTTP_USER_AGENT'], 0, 100); ?>...</p>
    </div>
    
    <div class="debug-section">
        <h2>📱 Raw Data (for debugging)</h2>
        <h3>GET Parameters:</h3>
        <pre><?php print_r($_GET); ?></pre>
        
        <h3>POST Parameters:</h3>
        <pre><?php print_r($_POST); ?></pre>
    </div>
    
    <div class="debug-section">
        <h2>📝 Next Steps</h2>
        <?php if (!$has_any_params): ?>
            <div class="error">
                <p><strong>Issue:</strong> Share target not working</p>
                <p><strong>Solution:</strong> Reinstall the PWA after clearing browser cache</p>
            </div>
        <?php elseif (empty($found_urls)): ?>
            <div class="warning">
                <p><strong>Issue:</strong> No URLs found in shared content</p>
                <p><strong>Check:</strong> The app might be sending URLs in a different format</p>
            </div>
        <?php else: ?>
            <div class="success">
                <p><strong>Status:</strong> Everything looks good!</p>
                <p><strong>Next:</strong> Ready to implement the ReBit functionality</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Log to page for mobile debugging
        console.log('Share Target Debug Page Loaded');
        console.log('Current URL:', window.location.href);
        console.log('URL Parameters:', Object.fromEntries(new URLSearchParams(window.location.search)));
        
        // Show JavaScript info on page
        document.body.innerHTML += '<div class="debug-section"><h2>📱 JavaScript Info</h2><p><strong>Current URL:</strong> ' + window.location.href + '</p><p><strong>Parameters:</strong> ' + JSON.stringify(Object.fromEntries(new URLSearchParams(window.location.search))) + '</p></div>';
    </script>
</body>
</html>
