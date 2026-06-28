# <img src="assets/images/logo_192.png" alt="BitStream Logo" height="40" align="center"> BitStream
A Modern Microblogging Platform for WordPress.

![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)

**DISCLAIMER: This plugin was primarily created to experiment with AI-generated code. It is unsupported, provided as-is, and I do not recommend using it in production. Because this project was created primarily for experimentation, the code may be incomplete or unstable. Use at your own risk.**

## 🎯 Overview

BitStream transforms WordPress into a powerful microblogging platform with Twitter-like functionality. Share quick thoughts (Bits), reshare external content (ReBits), schedule posts, save drafts, use hashtags, and enjoy a modern Progressive Web App experience with a clear social-app style timeline.

### Key Highlights

- 📱 **Progressive Web App** - Install as native app on mobile/desktop with robust share sheet integration and Web Push Notifications
- 🎨 **Modern Social Timeline** - Clean, responsive feed layout replacing the old masonry grid
- 📝 **Advanced Poster Interface** - Tabbed posting with drafts, scheduling, and rich media
- 🏷️ **Hashtags & Discovery** - Auto-linked tags, trending sidebars, and hashtag feed filtering
- 🔗 **Enhanced ReBit System** - Secure, cached OpenGraph previews with manual overrides
- ⚙️ **Unified Settings** - Centralized management for all your personalization, mapping, and notification needs

## 📋 Table of Contents

- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Features](#-features)
- [Shortcodes](#-shortcodes)
- [Progressive Web App](#-progressive-web-app)
- [ReBit System](#-rebit-system)
- [Administration & Settings](#-administration--settings)
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

Create a new page and add the shortcode to display your timeline:

```
[bitstream]
```

### Posting Interface

BitStream features an integrated Composer directly on your main timeline feed. On desktop, this is embedded at the top of the timeline. On mobile, you can use the Quick Action buttons to open the posting workflow in a modal.

### Install as PWA

On mobile devices, use the "Add to Home Screen" option to install BitStream as a native app for the best experience.

## ✨ Features

### 🎨 Modern Social Timeline

- **Social-App Style Feed** - Clear, single-column reading experience replacing the old masonry layout
- **Adaptive Sidebars** - Left filter links, right Quick Actions rails, and responsive stacking across devices
- **Interactive Cards** - Rich media (including multi-image/video grids with fullscreen lightbox overlays), quoted bits, and inline actions
- **Composer Box** - Instantly post Bits or auto-detected ReBits directly from the sidebar feed
- **In-Feed Deletion** - Instantly delete posts if you have the proper capabilities

### 📝 Composer Interface

- **Unified Feed Composition** - Compose Bits and ReBits directly from the feed page
- **Drafts Support** - Save posts mid-thought, auto-save on page/tab close (`navigator.sendBeacon`), and resume later
- **Robust Scheduling** - Plan ahead with a native datetime picker for future publishing (Bits and ReBits)
- **Rich Media** - Drag-and-drop uploads, attaching up to 10 images or videos per post, custom image cropper, and video support integrated via native `wp.media`
- **In-Feed Management** - Load, preview, edit, or delete drafts and scheduled items directly from their respective modals on the feed page

### � Social & Discovery

- **Hashtags** - Dynamic link generation, searchable tags across your feed, and a "Hashtags" section in the sidebar showing trending terms
- **Likes & Comments** - AJAX-powered likes, nested threaded comments (with visual hierarchy), and an inline reply system
- **Quoted Bits** - Visually rich nested cards providing beautiful context when responding to another post

### � Enhanced ReBit System

- **Secure OpenGraph Fetcher** - Built-in strict SSRF protection (`wp_safe_remote_get`), timeout retries, URL resolution, and JSON-LD parsing
- **Fast Previews** - 24-hour transient caching minimizes external requests for ReBit data
- **Manual Overrides** - Edit the fetched title, description, and image directly in the composer before publishing

### � Progressive Web App (PWA)

- **Installable** - Add to home screen on mobile and desktop
- **Offline Aware** - Service worker caching functionality
- **Advanced Share Parsing** - Better Android share integration, automatically separating text and URLs when combined by specific apps
- **Contextual Routing** - Quick Actions shortcuts map directly to specific composer tabs

### 🔔 Push Notifications

- **PWA Web Push** - Receive push notifications on your device when new Bits or ReBits are published
- **Self-Hosted VAPID** - Authenticated notifications secured with keys generated directly on your server using OpenSSL — no third-party push services required
- **Payload-free Dispatch** - Lightweight notification deliveries that fetch the latest post details dynamically, conserving device resources and avoiding complex payload encryption

#### Setup

1. Go to **Settings → Push Notifications** (or open the `[bitstream_settings]` page and click the **Push Notifications** tab)
2. Click **Generate VAPID Keys** — the plugin uses OpenSSL to create a public/private key pair and stores them in your database
3. Enable push notifications and save

#### Subscribing a Device

Once VAPID keys are configured, a **Get Notifications** button appears in:
- The **right-rail sidebar** on the main feed (desktop)
- The **Quick Actions widget** (mobile / floating menu)
- The **Push Notifications settings tab** ("Subscribe this Device" button)

#### Android / Mobile Permission Prompt

On Android, browsers block notifications by default at the OS level. BitStream handles this with a three-stage permission flow so the system prompt is correctly surfaced:

1. **`default` (never asked)** — clicking Subscribe triggers `Notification.requestPermission()`, which surfaces the native OS/browser allow-or-block dialog
2. **`granted`** — subscribe proceeds immediately without an extra prompt
3. **`denied` (hard-blocked)** — the button changes to **"Notifications Blocked — Enable in Browser Settings"** (shown in orange) with a tooltip explaining how to re-enable them; no silent failure occurs

> **Tip:** If you previously tapped "Block" on the browser prompt, open Chrome → tap the lock icon in the address bar → **Site settings** → **Notifications** → set to **Allow**, then refresh the page.

## 📝 Shortcodes

### `[bitstream]` - Display Feed

The main shortcode for displaying your microblog timeline. For logged-in users with publishing permissions, it also automatically integrates the inline Composer at the top of the feed (on desktop) and enables the floating Quick Actions menu (on mobile) to compose Bits/ReBits, attach media, save drafts, and schedule posts directly from the frontend.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `posts_per_page` | integer | 10 | Number of posts to load per page |
| `limit` | integer | - | Limit total posts (disables pagination) |
| `exact_limit` | integer | - | Render exactly this number of posts in preview mode instead of auto-filling to a cap |
| `mode` | string | - | Set to `"preview"` for a compact 3-column grid without sidebars |
| `infinite_scroll` | boolean | false | Enable infinite scroll |
| `show_load_more` | boolean | true | Show/hide load more button |

### `[bitstream_settings]` - Settings Interface

Renders the tabbed Settings panel on the front-end for logged-in users with the `edit_posts` capability. Enables updating Personalisation options, ReBit Mappings, RSS Feeds, and Advanced tools.



## 🎛️ Administration & Settings

### Unified Settings Dashboard

BitStream 3.0 consolidates all administrative panels into a single, clean **Settings** entry under the WordPress Admin menu:

- **Personalisation**: Theme colors, display preferences, and feed configuration.
- **ReBit Mappings**: Define custom labels and Font Awesome icons for your most shared platforms (e.g., YouTube, Twitter, GitHub) using a responsive card-based layout.
- **RSS Feeds**: Manage dedicated feeds for all bits, original bits only, or ReBits only directly from here.
- **Advanced Tools**: Advanced configuration and tools.

### Housekeeping Improvements

- **Weekly Media Cleanup**: Automatic pruning of unattached orphaned files from incomplete composer uploads (`bitstream_weekly_media_cleanup_event`).

## 🔧 Technical Details

### Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **Font Awesome**: Recommended for icons (free version)

## 🤝 Contributing

**Important:** This project is an experiment in AI-assisted development and does not accept pull requests.

### Why No PRs?

BitStream was created specifically to explore and test AI coding tools like GitHub Copilot and other advanced agentic systems. The entire codebase has been generated through AI-assisted development. Accepting traditional pull requests would compromise the experimental nature and learning goals of this project.

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

## 📜 Changelog

For a detailed list of all changes, please refer to the [changelog.md](changelog.md) file.