# FreshRSS Broad Site Icon Fetcher

[简体中文](README.zh-CN.md)

<p align="center">
  <img src="https://freshrss.org/images/icon.svg" alt="FreshRSS" width="60" />
</p>

<p align="center"><strong>Discover feed icons from RSS metadata, YouTube channel pages, websites, manifests, and more.</strong></p>

<p align="center">
  <a href="https://github.com/bowencool/xExtension-BroadIconFetcher/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/bowencool/xExtension-BroadIconFetcher/ci.yml?branch=main&label=CI" alt="CI" /></a>
  <a href="https://github.com/bowencool/xExtension-BroadIconFetcher/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue" alt="AGPL-3.0 License" /></a>
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF?logo=php&logoColor=white" alt="PHP >= 8.1" />
  <img src="https://img.shields.io/badge/FreshRSS-Extension-green?logo=rss&logoColor=white" alt="FreshRSS Extension" />
</p>

---

### Features

- **RSS/Atom and JSON Feed icons, including RSSHub** — Reads RSSHub's `channel.image.url` (`channel/image/url` in XML), Atom `<logo>`, and JSON Feed `icon` / `favicon` before checking the website.
- **YouTube channel avatars** — Resolves YouTube video feeds to their channel page and uses the channel avatar rather than YouTube's generic favicon.
- **Broad HTML discovery** — Supports `icon`, `shortcut icon`, `apple-touch-icon`, `mask-icon`, `fluid-icon`, and `image_src` links.
- **Modern metadata** — Supports Web App Manifest icons, Open Graph, Twitter Cards, and JSON-LD image/logo/icon fields.
- **Safe fallback chain** — Falls back to `/favicon.ico` and validates downloaded image content through FreshRSS.
- **Automatic, single-feed, and bulk workflows** — Fetches icons for new feeds, refreshes one feed from FreshRSS's favicon dialog, and supports bulk fetch, refresh, and reset actions.
- **User-icon protection** — Never replaces a favicon explicitly uploaded by the user.
- **Native FreshRSS integration** — Reuses FreshRSS HTTP, proxy, cache, favicon storage, and extension hooks.

### Icon source priority

1. Feed-provided image: RSS/RSSHub `channel.image.url`, Atom `<logo>`, or JSON Feed `icon` / `favicon`
2. YouTube channel avatar for `youtube.com/feeds/videos.xml?channel_id=...`
3. HTML link icons
4. Web App Manifest `icons`
5. Open Graph, Twitter Card, and generic image metadata
6. JSON-LD image/logo/icon fields
7. `/favicon.ico`

Relative URLs, protocol-relative URLs, HTML `<base>` elements, and JSON/XML feed responses are supported.

### Demonstration

```text
Subscribe to a feed
        |
        v
Read feed-provided icon from RSS/RSSHub, Atom, or JSON Feed
        |
        +--> For YouTube video feeds, add the channel avatar as a candidate
        +--> Inspect website HTML and metadata
        +--> Finally try /favicon.ico
        |
        v
Store the icon using FreshRSS's native favicon system
```

### Screenshots and animated demo

#### Single-feed action

![Refreshing an icon for one feed in FreshRSS](screenshots/one.gif)

#### Bulk actions

![Bulk icon actions in FreshRSS](screenshots/bulk.png)

### Installation

#### From Git

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/bowencool/xExtension-BroadIconFetcher.git
```

#### Manual

1. Download the [latest release](https://github.com/bowencool/xExtension-BroadIconFetcher/releases) or the repository ZIP.
2. Extract it into FreshRSS's `extensions/` directory.
3. Rename the folder to `xExtension-BroadIconFetcher` if needed.

#### Enable

1. Open **Configuration → Extensions** in FreshRSS.
2. Enable **Broad Site Icon Fetcher**.
3. Open the extension settings to change automatic fetching or run bulk actions.

### Configuration and icon actions

| Action | Description |
| --- | --- |
| **Automatically fetch an icon when a new feed is added** | Enables the new-feed hook. Enabled by default. |
| **Refresh one feed** | In the feed's favicon dialog, use the extension action to re-fetch only that feed's icon. |
| **Fetch missing icons** | Processes feeds without an icon file managed by this extension. |
| **Refresh all extension icons** | Re-fetches and replaces icons managed by this extension. |
| **Reset extension icons** | Removes only icons set by this extension; user-uploaded icons are preserved. |

### How It Works

```text
FreshRSS feed hook
        |
        v
Fetch feed URL and inspect its RSS/Atom/JSON Feed icon fields
        |
        +--> If valid, download and store the channel image
        +--> For YouTube video feeds, add the channel-page avatar as a candidate
        +--> Inspect HTML, Manifest, metadata, JSON-LD, then favicon.ico
        |
        v
FreshRSS validates and stores the custom favicon
```

### Development

No dependency installation or build step is required. Requirements: PHP 8.1+, FreshRSS, and PHP `curl`, `dom`, and `fileinfo` extensions.

GitHub Actions runs the same checks on every push. Pushing a new `v*` tag creates a GitHub Release automatically after lint succeeds.

```bash
php -l extension.php
php -l configure.phtml
php -l i18n/en/ext.php
php -l i18n/zh-cn/ext.php
node --check static/icon-fetcher.js
python3 -c 'import json; json.load(open("metadata.json")); print("metadata.json: valid")'
```

### Project Structure

```text
xExtension-BroadIconFetcher/
├── extension.php              # Entrypoint and icon discovery
├── configure.phtml            # Configuration and bulk actions
├── metadata.json              # FreshRSS metadata
├── static/icon-fetcher.js     # Bulk-action frontend
├── screenshots/               # README screenshots and demo media
├── i18n/                      # English and Simplified Chinese translations
├── .github/workflows/ci.yml  # CI and tag-release workflow
├── LICENSE
└── README.md
```

### Contributing

Please fork the repository, create a feature branch, run the validation commands above, and submit a pull request with a clear description.

### License

This project is licensed under the [GNU Affero General Public License v3.0](LICENSE).
