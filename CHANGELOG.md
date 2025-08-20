# Changelog

All notable changes to the BitStream WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.1] - 2025-08-20

### Added
- Floating QuickBit button for easy access to post creation and full editor
- PWA shortcuts for quick access from Android home screen (Add New Bit, Add New ReBit)
- Custom PWA shortcut icons matching website theme (green #2c6e49 with Font Awesome icons)
- RSS feed support with three dedicated feeds: all content, bits only, and rebits only
- Comprehensive quote functionality with visual context in block editor
- Theme-integrated post metadata display with both relative and absolute timestamps
- Enhanced Service Worker debugging tools with dedicated endpoint
- **Enhanced ReBit Mappings Interface** with modern card-based layout and improved UX
- **Visual Icon Picker** for Font Awesome icons with live preview and search functionality
- **Quick Presets** for 20+ popular platforms (Twitter/X, YouTube, GitHub, LinkedIn, etc.)
- **Complete Font Awesome Integration** - automatically detects all available icons from FA plugin
- **Smart Icon Categories** with filters for Brands, Solid, Regular, and All icons
- **Real-time Preview** showing exactly how icons and labels will appear on ReBits
- **Comprehensive Fallback Icon Library** with 600+ Font Awesome icons for reliable icon selection
- **Optimized Page Layout** - ReBit Mappings page now uses 1200px width for better space utilization
- **RSS Feeds Admin Page** - dedicated interface for accessing and subscribing to all available feeds
- **Admin Menu Reorganization** - logical workflow order for better user experience

### Changed
- Simplified PWA structure to single "BitStream" app (removed redundant QuickPost PWA)
- **Completely redesigned ReBit mappings admin page** with card-based interface
- **Replaced basic table layout** with modern, responsive design and visual feedback
- **Enhanced icon selection** from manual typing to visual picker with search
- **Improved mapping removal** with undo functionality instead of confusing checkboxes
- **Reorganized admin interface** - Current Mappings moved to top, adding options moved to bottom
- **Side-by-side layout** for Quick Add and Add New Mapping sections for better workflow
- **Admin Menu Structure** - reordered to: Add New Bit → Add New ReBit → All Bits → ReBit Mappings → RSS Feeds

### Enhanced
- **ReBit Mappings UX**: Visual icon picker eliminates need to memorize Font Awesome class names
- **Platform Recognition**: Preset library includes major social media and tech platforms
- **Icon Discovery**: Browse hundreds of icons by category with instant visual feedback
- **Accessibility**: Better keyboard navigation and screen reader support for mappings interface
- **Space Efficiency**: Wider content area (1200px) makes better use of available screen space
- **Content Hierarchy**: Logical flow from viewing existing mappings to adding new ones
- **RSS Feed Discovery**: Comprehensive interface with copy-to-clipboard functionality and subscription service integration
- **Admin Navigation**: Workflow-optimized menu order from content creation to configuration to distribution
- Updated Service Worker cache management for better performance
- Improved single bit page styling with masonry layout compatibility

### Fixed
- JavaScript errors preventing quote functionality (template literals, jQuery references)
- Service Worker scope issues causing 404 errors and registration failures
- Single bit page display conflicts with masonry layouts
- Deprecated Apple PWA meta tags causing browser warnings
- Missing bit titles and metadata on individual bit pages
- Quote detection system with multiple URL-based fallbacks
- **Font Awesome CORS issues** with enhanced CSS parsing and local detection
- **Icon library limitations** with comprehensive fallback system ensuring icon availability

### Removed
- QuickBit shortcode functionality (replaced by floating button)
- Redundant QuickPost PWA and related assets
- Media library integration for QuickBit form
- Unused QuickBit form CSS styling

## [2.1.0] - 2025-08-20

### Added
- **Enhanced Shortcode Parameters**: Added new parameters to `[bitstream]` shortcode for flexible display options:
  - `limit` - Show a fixed number of posts (e.g., `limit="3"` for latest 3 posts)
  - `infinite_scroll` - Enable automatic infinite scroll (`infinite_scroll="true"`)
  - `show_load_more` - Control load more button visibility (`show_load_more="false"`)
- **Responsive Column Layout**: Implemented automatic responsive grid layout for posts:
  - Mobile (< 768px): 1 column
  - Tablet (768px - 1023px): 2 columns
  - Desktop (1024px - 1399px): 3 columns
  - Large Desktop (1400px+): 4 columns
- **Improved JavaScript**: Updated infinite scroll to use Intersection Observer API for better performance
- **Parameter-Based Display**: Single shortcode now handles all display scenarios
- **CSS Grid Implementation**: Automatic, flexible column layout with responsive spacing

### Removed
- **Deprecated Shortcode**: Removed `[bitstream_latest]` shortcode (use `[bitstream limit="3"]` instead)
- **Redundant Code**: Eliminated duplicate functionality in favor of parameter-based approach

### Changed
- **Simplified API**: Consolidated all feed display options into the main `[bitstream]` shortcode
- **Better Performance**: More efficient scroll handling with intersection observer
- **Enhanced Responsive Design**: Cards automatically adapt to screen size and available space
- **Improved Mobile Experience**: Optimized spacing, typography, and layout for mobile devices
- **Grid-Compatible Layout**: Load more buttons and scroll triggers work seamlessly with column layout
- **Enhanced Documentation**: Updated README with comprehensive shortcode parameter examples

## [2.0.4] - 2025-08-19

### Fixed
- **Single Bit Template**: Fixed single bit posts to properly use theme's header and footer instead of custom template override
- **PWA Installation**: Resolved PWA installation prompts not appearing on mobile and desktop Chrome
- **PWA Scope Conflicts**: Improved PWA conflict resolution between QuickPost and Feed PWAs

### Added
- Content filtering integration for single bit posts using `the_content` filter
- `beforeinstallprompt` event listeners for better PWA installation detection
- Apple meta tags for iOS PWA support
- Enhanced PWA loading logic with better URL detection

### Changed
- Updated PWA manifest start URLs to more practical paths
- Simplified service worker registration logic
- Improved PWA asset caching strategy
- Enhanced console logging for PWA debugging

## [2.0.3] - 2025-08-19

### Fixed
- **PWA Scope Conflicts**: Resolved service worker conflicts between QuickPost and Feed PWAs
- **Manifest Separation**: Fixed overlapping scopes that caused browser confusion
- **Service Worker Isolation**: Each PWA now operates in its own dedicated scope

### Added
- Separate PWA scopes: QuickPost (`/bitstream/quickbit/`) and Feed (`/bitstream/feed/`)
- Enhanced service worker registration with proper scope checking
- Improved PWA asset loading logic to prevent conflicts

### Changed
- Updated QuickPost PWA scope from `/bitstream/` to `/bitstream/quickbit/`
- Modified Feed PWA scope to `/bitstream/feed/`
- Enhanced service worker fetch handlers for better scope isolation

## [2.0.2] - 2025-08-19

### Fixed
- **Permalink Issues**: Fixed 404 errors when clicking the copy permalink button
- **Auto-Generated Slugs**: Improved SEO-friendly URL generation (now uses `bit-YYYY-MM-DD-001` format)
- **Single Post Display**: Added proper template handling for individual Bit posts
- **Rewrite Rules**: Automatic flush on plugin activation to prevent permalink issues

### Added
- Activation/deactivation hooks with automatic permalink setup
- Admin notice system for permalink troubleshooting with "Fix Permalinks" button
- Custom single post template handling via `template_redirect` hook
- Enhanced debugging with permalink URL tooltips
- PERMALINK-FIX.md instructions for users

### Changed
- Post slug generation now creates clean URLs without special characters
- Individual Bit posts now display properly at their permalink URLs
- Improved error handling in permalink generation

## [2.0.1] - 2025-08-19

### Added
- Version consistency across all plugin files
- Enhanced PWA support with improved service worker caching strategy
- JavaScript debouncing to prevent spam clicking on action buttons
- Comprehensive error logging system with admin debug interface
- Enhanced security with post existence validation in AJAX handlers

### Changed
- Updated all version references from "RC 1.1" to "2.0.1"
- Improved PWA manifest with orientation, scope, and category metadata
- Enhanced service worker with better offline support and cache management
- Consolidated CSS rules and removed duplicates for better performance

### Fixed
- Version mismatch between plugin header and constant definition
- Duplicate CSS animations and redundant styling rules
- Missing error handling in AJAX requests

## [2.0] - 2025-08-19

### Added
- **Modular Architecture**: Complete refactor to class-based, modular design
- **Security Enhancements**: AJAX nonce verification for all endpoints
- **Performance Optimizations**: Cached auto-title generation with WordPress transients
- **Background Processing**: Asynchronous OG data fetching using wp_cron
- **Error Logging**: Debug logging system for development and troubleshooting

### Security
- Enhanced capability checks for all admin functions
- Improved input sanitization for all form inputs
- CSRF protection on all forms with WordPress nonces
- Post validation in AJAX handlers

### Performance
- Conditional asset loading (media library scripts only when needed)
- Database query optimization with reduced unnecessary calls
- Transient caching for daily post counts (24-hour cache)
- Consolidated CSS and JavaScript files

### Changed
- **File Structure**: Organized into professional structure with `includes/` and `assets/` directories
- **Code Quality**: Split monolithic code into specialized classes
- **CSS Consolidation**: Removed duplicate rules and streamlined styling
- **JavaScript Optimization**: Eliminated code duplication and improved efficiency

### Files Added
- `includes/class-post-type.php` - Post type registration and auto-titles
- `includes/class-ajax-handlers.php` - Secure AJAX endpoints
- `includes/class-shortcodes.php` - All shortcode functionality  
- `includes/class-og-fetcher.php` - Background OG data fetching
- `assets/css/bitstream.css` - Consolidated styles
- `assets/js/bitstream.js` - Optimized JavaScript

## Version History Summary

- **2.0.4** - Single bit template integration and PWA installation fixes
- **2.0.3** - PWA scope separation and conflict resolution
- **2.0.2** - Permalink fixes and single post display
- **2.0.1** - Version consistency and PWA enhancements  
- **2.0** - Major architecture overhaul with security and performance improvements

## Contributing

When adding new releases to this changelog:

1. Add new version at the top following the format above
2. Use semantic versioning (MAJOR.MINOR.PATCH)
3. Include sections: Added, Changed, Deprecated, Removed, Fixed, Security
4. Date format: YYYY-MM-DD
5. Keep entries concise but descriptive

## Links

- [WordPress Plugin Repository](https://wordpress.org/plugins/bitstream/) (if applicable)
- [GitHub Repository](https://github.com/facundopignanelli/bitstream)
- [Documentation](README.md)
