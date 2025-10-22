# <img src="assets/images/bitstream.svg" alt="BitStream Logo" height="40" align="center"> BitStream

**Version 2.0.0** - A Modern Microblogging Platform for WordPress

![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)

**DISCLAIMER: This plugin was primarily created to experiment with AI-generated code. It is unsupported, provided as-is, and I do not recommend using it in production. Because this project was created primarily for experimentation, the code may be incomplete or unstable. Use at your own risk.**

## 🎯 Overview

BitStream transforms WordPress into a powerful microblogging platform with Twitter-like functionality. Share quick thoughts (Bits), reshare external content (ReBits), interact through likes and comments, and enjoy a modern Progressive Web App experience.

### Key Highlights

- 📱 **Progressive Web App** - Install as native app on mobile/desktop
- 🎨 **Beautiful Masonry Layout** - Pinterest-style responsive grid
- 🔄 **ReBit System** - Share links with automatic OpenGraph previews
- 💬 **Social Features** - Likes, comments, quotes, and more
- 🚀 **Performance Optimized** - Smart caching, lazy loading, efficient queries
- 🔒 **Security First** - Nonces, sanitization, capability checks throughout
- 🎨 **Theme Agnostic** - Works with any properly coded WordPress theme
- 🌐 **Translation Ready** - Fully internationalized and RTL-ready

## 📋 Table of Contents

- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Features](#-features)
- [Shortcodes](#-shortcodes)
- [Progressive Web App](#-progressive-web-app)
- [ReBit System](#-rebit-system)
- [Admin Features](#-admin-features)
- [Technical Details](#-technical-details)
- [Contributing](#-contributing)
- [Changelog](#-changelog)

## 🚀 Installation

### From GitHub

1. Download the latest release from the [releases page](https://github.com/facundopignanelli/bitstream/releases)
2. Upload the `bitstream` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Visit Settings → Permalinks and click "Save Changes" to flush rewrite rules
5. Create a page and add the `[bitstream]` shortcode

### Manual Installation

1. Clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/facundopignanelli/bitstream.git
   ```
2. Activate the plugin in WordPress
3. Flush permalinks (Settings → Permalinks → Save Changes)

## ⚡ Quick Start

### Display the Feed

Create a new page and add the shortcode:

```
[bitstream]
```

This will display a masonry grid of bits with load more functionality.

### Install as PWA

On mobile devices, use the "Add to Home Screen" option to install BitStream as a native app.

## ✨ Features

### 📱 Progressive Web App (PWA)

- **Installable App** - Add to home screen on mobile and desktop
- **Offline Support** - Service worker caches content for offline access
- **App-Like Feel** - Standalone display mode with custom theme colors
- **Android Share Sheet** - Share links from any app directly to BitStream
- **Quick Actions** - Home screen shortcuts for "Add New Bit" and "Add New ReBit"
- **Push Notifications Ready** - Infrastructure in place for future push notifications
- **Smart Caching** - Automatic cache management with version control

### 🎨 Modern Masonry Layout

- **Pinterest-Style Grid** - Dynamic height-based card positioning
- **Responsive Columns** - 1 column (mobile), 2 columns (tablet), 3 columns (desktop)
- **Zero Overlaps** - Intelligent height calculation prevents card overlap
- **Smooth Animations** - CSS transitions for card positioning
- **Dynamic Adjustments** - Automatically recalculates on content changes
- **Image Load Detection** - Waits for images before finalizing layout
- **Performance Optimized** - 60fps animations using requestAnimationFrame

### 💬 Social Features

- **Likes** - AJAX-powered like/unlike with localStorage persistence
- **Comments** - Native WordPress comments with custom styling
- **Nested Comments** - Full threading support with visual hierarchy
- **Quote Bits** - Quote and respond to other bits
- **Real-time Updates** - No page reloads needed for interactions
- **AJAX Toggling** - Smooth expand/collapse for comments

### 🔄 ReBit System

- **Share External Links** - Post links from anywhere on the web
- **Auto OpenGraph** - Automatically fetches title, description, and image
- **Custom Mappings** - Configure icons and labels for 20+ popular platforms
- **Visual Admin** - Modern interface for managing domain mappings
- **Icon Picker** - Browse 600+ Font Awesome icons with live preview
- **Quick Presets** - One-click setup for Twitter, YouTube, GitHub, etc.

### 📝 Content Management

- **Custom Post Type** - Dedicated "Bit" post type with REST API support
- **Automatic Titles** - Smart title generation with 24-hour caching
- **Block Editor Support** - Custom ReBit URL block for Gutenberg
- **Media Library Integration** - Full WordPress media uploader support
- **Quick Post Form** - Frontend posting with `[bitstream_quick_post]` shortcode
- **Draft Support** - Save and preview before publishing

### 📡 RSS Feed System

- **Multiple Feeds** - Three dedicated feeds:
  - `/bitstream/feed/` - All content
  - `/bitstream/feed/bits/` - Original bits only
  - `/bitstream/feed/rebits/` - ReBits only
- **Auto-Discovery** - RSS links in HTML head
- **Full Content** - Complete bit content in feed items
- **Admin Interface** - Dedicated page for feed management

### 🎯 Display Options

- **Load More Button** - Traditional pagination with AJAX loading
- **Infinite Scroll** - Automatic loading as user scrolls
- **Fixed Display** - Show specific number of posts (e.g., latest 3)
- **Customizable Post Count** - Control posts per page
- **Mobile Optimized** - Single column on small screens

## 📝 Shortcodes

### `[bitstream]` - Display Feed

The main shortcode for displaying a feed of bits with masonry layout.

#### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `posts_per_page` | integer | 10 | Number of posts to load per page |
| `limit` | integer | - | Limit total posts (disables pagination) |
| `infinite_scroll` | boolean | false | Enable infinite scroll |
| `show_load_more` | boolean | true | Show/hide load more button |

#### Examples

**Basic Feed**
```
[bitstream]
```
Displays masonry grid with 10 posts per page and load more button.

**Limited Posts**
```
[bitstream limit="3"]
```
Shows only the 3 most recent bits, no pagination.

**Infinite Scroll**
```
[bitstream infinite_scroll="true"]
```
Automatically loads more posts as user scrolls.

**Custom Page Size**
```
[bitstream posts_per_page="20"]
```
Shows 20 posts per page with load more button.

**Combined Options**
```
[bitstream posts_per_page="15" infinite_scroll="true"]
```
15 posts per page with infinite scroll enabled.

**Static Display**
```
[bitstream limit="5" show_load_more="false"]
```
Shows exactly 5 posts, no pagination controls.

#### Responsive Behavior

- **Mobile (< 768px)**: Single column layout
- **Tablet (768px - 1023px)**: Two column layout
- **Desktop (≥ 1024px)**: Three column layout

## 📱 Progressive Web App

### Installation on Mobile

1. Visit your BitStream page on Android Chrome or iOS Safari
2. Tap the menu (⋮) and select "Add to Home Screen"
3. The BitStream app icon will appear on your home screen
4. Launch it for a full-screen, app-like experience

### Features

- **Standalone Mode**: Opens without browser UI
- **Offline Access**: Cached content available offline
- **Quick Actions**: Long-press icon for shortcuts
- **Share Target**: Share from other apps directly to BitStream

### Android Share Sheet

1. In any Android app, tap the Share button
2. Select "BitStream" from the share sheet
3. The shared link automatically populates the ReBit form
4. Add your commentary and publish

## 🔄 ReBit System

### What are ReBits?

ReBits let you share external links with your commentary. When you add a URL, BitStream automatically fetches the page title, description, and image for a rich preview.

### Managing Domain Mappings

1. Go to **Bits → ReBit Mappings** in WordPress admin
2. Use **Quick Presets** for popular platforms (Twitter, YouTube, GitHub, etc.)
3. Or create custom mappings:
   - Enter domain (e.g., `medium.com`)
   - Choose label (e.g., "shared an article")
   - Select icon from visual picker
4. Mappings apply to all bits containing that domain

### Supported Platforms

Pre-configured presets for:
- Social: Twitter/X, LinkedIn, Facebook, Instagram, TikTok, Reddit
- Development: GitHub, StackOverflow, Dev.to
- Media: YouTube, Spotify, Twitch
- Content: Medium, Wikipedia, News sites
- And more...

## 🎛️ Admin Features

### Floating Action Button

Quick access button (visible on BitStream pages) provides:
- Add New Bit
- Add New ReBit
- ReBit Mappings
- RSS Feeds

### ReBit Mappings Interface

- **Card-Based Design**: Visual, modern interface
- **Icon Picker**: Browse 600+ Font Awesome icons
- **Live Preview**: See exactly how mappings will look
- **Category Filters**: Brands, Solid, Regular icons
- **Undo Removal**: Easy removal with undo option
- **Quick Presets**: One-click setup for 20+ platforms

### RSS Feeds Manager

- View all available feeds
- Copy feed URLs
- Subscribe via feed reader services
- Auto-discovery links for feed readers

### Bit Management

- **Quote Action**: Quote any bit from the bits list
- **Bulk Actions**: Quote multiple bits at once
- **Quick Edit**: Fast inline editing
- **Search & Filter**: Find bits quickly

## 🔧 Technical Details

### Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher
- **Font Awesome**: Recommended for icons (free version)

### Debug Mode
If WP_DEBUG is enabled, access **BitStream → Debug Logs** in admin to view error logs and troubleshoot issues.

## 🤝 Contributing

**Important:** This project is an experiment in AI-assisted development and does not accept pull requests.

### Why No PRs?

BitStream was created specifically to explore and test AI coding tools like GitHub Copilot. The entire codebase has been generated through AI-assisted development as part of this ongoing experiment. Accepting traditional pull requests would compromise the experimental nature and learning goals of this project.

### But You Can Still Contribute!

While we don't accept PRs, you're absolutely **welcome and encouraged** to:

- **Fork the repository** and create your own version
- **Add features** you'd like to see
- **Experiment** with your own modifications
- **Share your fork** with the community
- **Report bugs** via GitHub Issues (we appreciate bug reports!)
- **Suggest features** through GitHub Discussions

### Using This Code

This project is licensed under GPL-2.0+, which means:
- ✅ You can fork and modify the code freely
- ✅ You can use it in your own projects
- ✅ You can redistribute your modified versions
- ✅ You must maintain the same license (GPL-2.0+)
- ✅ You must document changes you make