# Quick Test for ReBit Block Population

## Test This URL:

Copy and paste this URL into your browser (replace YOUR_SITE with your domain):

```
https://YOUR_SITE/bitstream/new-rebit/?shared_url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&shared_title=Test%20Video&shared_text=This%20should%20populate%20the%20ReBit%20block
```

## What Should Happen:

1. **Redirect to WordPress admin** - You'll be taken to the new post editor
2. **ReBit block inserted** - A ReBit URL block should appear
3. **URL populated** - The YouTube URL should appear in the ReBit URL field
4. **Content added** - "Sharing: Test Video" and "This should populate the ReBit block" should appear in the post content

## Console Messages to Look For:

Open Developer Tools (F12) → Console tab and look for these messages:

```
BitStream: All URL parameters: [["shared_url", "https://..."], ...]
BitStream: Extracted parameters: {sharedUrl: "https://...", ...}
BitStream: Shared content detected - URL: https://...
BitStream: Attempt 1 to populate ReBit block
BitStream: Found ReBit block: {...}
BitStream: Successfully updated block attributes
BitStream: Successfully updated post meta
```

## If It Still Doesn't Work:

The enhanced code now includes:

1. **Multiple retry attempts** - Tries up to 10 times with increasing delays
2. **Fallback block creation** - Creates a new ReBit block if the original isn't found
3. **Meta field updates** - Sets the database field directly
4. **WordPress data monitoring** - Watches for block editor changes
5. **Immediate script execution** - Runs as soon as the editor loads

## Debug Information:

The console will now show much more detailed information about:
- What URL parameters are received
- When blocks are found/not found
- Any errors that occur during the update process
- The current state of block attributes

Try the test URL and let me know what console messages you see!
