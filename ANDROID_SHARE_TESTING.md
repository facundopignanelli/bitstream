# Android Share Sheet Testing Guide

This document explains how to test the new Android share sheet integration with BitStream PWA.

## Setup Requirements

1. **Install the PWA**: Visit `/bitstream/` on your WordPress site from an Android device and install the PWA when prompted
2. **Login**: Make sure you're logged into WordPress with post editing permissions
3. **Enable PWA features**: Ensure the BitStream plugin is active and PWA features are enabled

## Testing the Share Target Feature

### Method 1: From Another App
1. Open any app that supports sharing (Chrome, YouTube, Twitter, etc.)
2. Navigate to a webpage or content you want to share
3. Tap the **Share** button
4. Look for **BitStream** in the share menu
5. Tap **BitStream**
6. The PWA should open with the ReBit form pre-populated with:
   - URL of the shared content
   - Title of the page/content (if available)
   - Selected text or description (if available)

### Method 2: Direct URL Testing
You can test the functionality directly by visiting:
```
/bitstream/new-rebit/?url=https://example.com&title=Example%20Page&text=This%20is%20a%20test
```

This should:
1. Redirect you to the WordPress admin (if not logged in, to login first)
2. Open the new post editor with `post_type=bit&rebit=1`
3. Automatically insert a ReBit URL block
4. Pre-populate the block with the shared URL
5. Add any shared title/text as post content

## Expected Behavior

### Visual Feedback
- A green notification should appear briefly saying "Content shared to BitStream!"
- The ReBit URL field should be automatically populated
- Any shared title or description should appear in the post content area

### URL Parameter Handling
The share target accepts these parameters:
- `url`: The URL being shared
- `title`: Title of the content being shared  
- `text`: Additional text or description

These get transformed to:
- `shared_url`: Sanitized URL for the ReBit
- `shared_title`: Sanitized title for post content
- `shared_text`: Sanitized description for post content

## Troubleshooting

### Share Target Not Appearing
- Ensure the PWA is properly installed
- Check that the manifest.json includes the share_target configuration
- Verify the service worker is registered correctly
- Check browser console for any errors

### Shared Content Not Pre-populated
- Check browser console for JavaScript errors
- Verify the URL parameters are being passed correctly
- Ensure you have edit_posts capability in WordPress
- Check that the block editor JavaScript is loading properly

### Service Worker Issues
- Clear browser cache and reinstall the PWA
- Check that the service worker version has been updated (v2.3.0+)
- Look for console messages starting with "BitStream SW:"

## Debugging

Enable debugging by:
1. Opening browser dev tools
2. Going to the Console tab
3. Looking for messages starting with "BitStream:"
4. Checking the Network tab for failed requests

The service worker logs share target requests as:
```
BitStream SW: Share target request detected: [URL]
```

## Technical Details

The share target uses:
- **Action**: `/bitstream/new-rebit/`
- **Method**: GET
- **Parameters**: Maps `url`→`url`, `title`→`title`, `text`→`text`

The WordPress rewrite system captures these and forwards them to the admin interface with the `shared_*` prefixes for security.
