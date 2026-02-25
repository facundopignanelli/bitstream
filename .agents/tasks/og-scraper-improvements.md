# OG Scraper Improvements

Backlog of improvements to `includes/class-og-fetcher.php` identified on 2026-02-25.

---

## 🔌 Service-Specific oEmbed / API Fallbacks

Each of these adds a private `fetch_*_oembed()` method in `BitStream_OG_Fetcher`, called from `fetch_og_data()` before the generic HTML scrape, following the same pattern as the existing `fetch_twitter_oembed()`.

- [ ] **YouTube** — `https://www.youtube.com/oembed?url=...` — free, no auth. Returns title, author, thumbnail.
- [ ] **Vimeo** — `https://vimeo.com/api/oembed.json?url=...` — free, no auth. Rich metadata + hi-res thumbnail.
- [ ] **Spotify** — `https://open.spotify.com/oembed?url=...` — free, no auth. Works for tracks, albums, playlists, podcasts.
- [ ] **SoundCloud** — `https://soundcloud.com/oembed?url=...` — free, no auth.
- [ ] **Reddit** — `https://www.reddit.com/oembed?url=...` — free. Returns post title + thumbnail.
- [ ] **TikTok** — `https://www.tiktok.com/oembed?url=...` — free, no auth. Returns video title + thumbnail.
- [ ] **Mastodon** — `https://{instance}/api/oembed?url=...` — free. Parse the instance domain from the URL.
- [ ] **GitHub repos/issues/PRs** — GitHub REST API `https://api.github.com/repos/{owner}/{repo}` — free (60 req/hr unauthenticated). Returns description, star count, primary language, owner avatar.
- [ ] **Instagram** — Meta oEmbed `https://graph.facebook.com/v18.0/instagram_oembed?url=...` — requires a free Meta App token (stored in WP options).

---

## 🧠 General Scraper Improvements

These improve quality for all sites, not just specific ones.

- [ ] **Attribute-order-agnostic OG regex** — current regex assumes `property=` before `content=`; many sites put `content=` first. Fix with a two-pass or order-agnostic pattern.
- [ ] **JSON-LD extraction** — parse `<script type="application/ld+json">` blocks; map `Article`, `VideoObject`, `Product`, etc. to title/description/image. Covers many modern news/blog/e-commerce sites that skip OG tags.
- [ ] **`<meta name="description">` fallback** — fall back to the standard description meta when `og:description` is absent.
- [ ] **OG image absolutization** — if `og:image` is a relative URL (`/images/hero.jpg`) convert it to an absolute URL against the page's base.
- [ ] **Read only `<head>`** — stop reading the response body after `</head>` is encountered; OG tags are always in `<head>` so reading multi-MB pages is wasteful.
- [ ] **Charset-aware decoding** — detect charset from `Content-Type` response header and convert non-UTF-8 pages before regex parsing.
- [ ] **Canonical URL resolution** — after following redirects, store the final destination URL (important for `t.co`, `bit.ly`, etc.).
- [ ] **Improved User-Agent rotation** — cycle between a small set of realistic browser UA strings to reduce bot-detection blocks.

---

## ⚡ Architectural Improvements

- [ ] **Per-URL transient cache** — cache fetch results by URL hash (e.g. 24 h) so repeated fetches of the same URL are instant.
- [ ] **Timeout + single retry** — on network timeout, retry once before failing; avoids false negatives from transient network issues.
- [ ] **Tap WordPress core oEmbed registry** — use `WP_oEmbed::discover()` so any provider WordPress already knows about gets oEmbed handling automatically, reducing the custom-provider maintenance burden.

---

## 🔒 Security / Reliability

- [ ] **SSRF protection** — validate the URL does not resolve to a private/loopback IP range (`127.x`, `10.x`, `192.168.x`, `169.254.x`, `::1`) before making the server-side request.
- [ ] **Maximum response size cap** — reject or truncate responses larger than a reasonable threshold (e.g. 500 KB) to prevent memory issues.
