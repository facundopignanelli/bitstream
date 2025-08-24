<?php
/**
 * Debug Log Viewer
 * View the share target debug logs
 */

$log_file = __DIR__ . '/debug-share.log';
?>
<!DOCTYPE html>
<html>
<head>
    <title>BitStream Share Debug Logs</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .log-entry { 
            background: #f8f9fa; 
            padding: 15px; 
            margin: 10px 0; 
            border-radius: 8px; 
            border-left: 4px solid #007cba; 
        }
        .clear-btn { 
            background: #dc3545; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
        }
        .refresh-btn { 
            background: #28a745; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            margin-right: 10px; 
        }
        pre { white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
    <h1>🔍 BitStream Share Debug Logs</h1>
    
    <div>
        <button class="refresh-btn" onclick="location.reload()">🔄 Refresh</button>
        <button class="clear-btn" onclick="clearLogs()">🗑️ Clear Logs</button>
        <p><strong>Last updated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
    
    <?php if (file_exists($log_file)): ?>
        <div class="log-entry">
            <h2>📄 Debug Log Contents:</h2>
            <pre><?php echo htmlspecialchars(file_get_contents($log_file)); ?></pre>
        </div>
    <?php else: ?>
        <div class="log-entry">
            <p>No debug log file found. Try sharing something to BitStream first.</p>
        </div>
    <?php endif; ?>
    
    <script>
        function clearLogs() {
            if (confirm('Clear all debug logs?')) {
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=clear', {method: 'POST'})
                    .then(() => location.reload());
            }
        }
    </script>
</body>
</html>

<?php
// Handle log clearing
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    if (file_exists($log_file)) {
        unlink($log_file);
    }
    exit('Logs cleared');
}
?>
