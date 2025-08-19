# BitStream Permalink Fix Instructions

If you're experiencing 404 errors when clicking the copy permalink button, follow these steps:

## Automatic Fix
1. Go to your WordPress admin dashboard
2. Navigate to **BitStream → All Bits**
3. Look for a yellow warning notice about permalink issues
4. Click the **"Fix Permalinks"** button in the notice

## Manual Fix
If the automatic fix doesn't work:

1. Go to **Settings → Permalinks** in your WordPress admin
2. Simply click **"Save Changes"** without changing anything
3. This will flush the rewrite rules and fix the permalink structure

## Version 2.0.1 Improvements
- Added automatic permalink fixing on plugin activation
- Improved auto-generated post slugs (now uses format: `bit-YYYY-MM-DD-001`)
- Added single post template handling for individual Bit display
- Enhanced admin notices for permalink troubleshooting

## What Was Fixed
- **Rewrite Rules**: Plugin now properly flushes rewrite rules on activation
- **Post Slugs**: Auto-generated titles now create SEO-friendly slugs without special characters
- **Single Post Display**: Individual Bit posts now display correctly when accessed via permalink
- **Template Handling**: Added custom template handling for single Bit posts

## Technical Details
Individual Bit posts are now accessible at:
- `/bitstream/bit-2025-08-19-001/` (example)
- Uses clean, SEO-friendly URLs
- Displays the full Bit card with comments and interactions
- Includes back navigation to main BitStream feed

If you continue to experience issues after trying both fixes above, please deactivate and reactivate the BitStream plugin.
