# BitStream Share Target Debugging Guide

## Quick Test Steps

1. **Try the manual test first:**
   - Copy this URL and paste it into your browser address bar:
   ```
   [YOUR_SITE]/bitstream/new-rebit/?url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&title=Test%20Video&text=This%20is%20a%20test
   ```
   - Replace `[YOUR_SITE]` with your actual domain
   - This should redirect you to the WordPress admin with a new ReBit post

2. **Check the browser console:**
   - Open Developer Tools (F12)
   - Go to the Console tab
   - Look for messages starting with "BitStream:"

3. **Check WordPress error log:**
   - Look for messages containing "BitStream Share Debug"

## If the manual test works but YouTube sharing doesn't:

The issue is likely that the PWA isn't properly recognized as a share target. Here are some things to check:

### 1. PWA Installation
- Make sure you installed the BitStream PWA on your Android device
- Go to `/bitstream/` on your site
- Look for the "Add to Home Screen" prompt
- Install the app

### 2. Manifest Validation
- Open your browser dev tools
- Go to Application > Manifest
- Check that the `share_target` section appears correctly

### 3. Force Refresh
- Clear your browser cache
- Uninstall and reinstall the PWA
- The manifest changes need to be picked up

## Common Issues:

### Issue 1: Share Target Not Appearing in Android Share Menu
**Cause:** PWA not properly installed or manifest not updated
**Solution:** 
- Reinstall the PWA
- Clear browser cache
- Check that the manifest.json file includes the share_target section

### Issue 2: ReBit URL Field Not Pre-populated
**Cause:** JavaScript not running or parameters not being passed
**Solution:**
- Check browser console for errors
- Verify the URL parameters are present in the browser address bar
- The new debugging code should help identify where it's failing

### Issue 3: Redirect Loop or 404 Errors
**Cause:** Rewrite rules not updated
**Solution:**
- Go to WordPress Admin > Settings > Permalinks
- Click "Save Changes" to flush rewrite rules
- Or add `?bitstream_debug_share=1` to see what's happening

## Test URLs:

Try these test URLs one by one:

1. **Basic test:**
   ```
   /bitstream/new-rebit/?url=https://example.com
   ```

2. **Full test:**
   ```
   /bitstream/new-rebit/?url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&title=Test%20Video&text=Testing%20share%20target
   ```

3. **Debug test:**
   ```
   /bitstream/new-rebit/?bitstream_debug_share=1&url=https://example.com
   ```

## Expected Console Output:

When working correctly, you should see these console messages:

```
BitStream: All URL parameters: [["shared_url", "https://..."], ["shared_title", "..."], ...]
BitStream: Extracted parameters: {sharedUrl: "https://...", sharedTitle: "...", sharedText: "..."}
BitStream: Shared content detected - URL: https://...
BitStream: All blocks: [{name: "bitstream/rebit-url", clientId: "..."}]
BitStream: Found ReBit block, setting shared URL: https://...
```

If you see different messages or errors, that will help identify the problem.
