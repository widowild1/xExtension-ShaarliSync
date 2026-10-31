# xExtension-ShaarliSync

[![FreshRSS Extension](https://img.shields.io/badge/FreshRSS-Extension-orange.svg)](https://freshrss.org/)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Native [FreshRSS](https://freshrss.org/) extension to automatically synchronize favorite RSS articles (⭐) to [Shaarli](https://github.com/shaarli/Shaarli), with optional AI-powered summarization and tagging (OpenAI or Google Gemini), interactive [ntfy](https://ntfy.sh/) push notifications, and multilingual i18n support (English, French, German, Spanish, Chinese, Arabic, Italian, Portuguese).

---

## Features

* **Non-blocking Background Processing:** Uses `fastcgi_finish_request()` so starring an article returns immediately in FreshRSS while AI, Shaarli, and ntfy jobs execute asynchronously in the background.
* **Optional Multilingual AI Engine:**
  * Supports **Google Gemini** (`gemini-3.5-flash-lite`, `gemini-3.8-flash`) and **OpenAI** (`gpt-4o-mini`, etc.).
  * Generates clean summaries and thematic tags in 8 languages (FR, EN, DE, ES, ZH, AR, IT, PT) or in the article's source language.
  * Configurable summary tone: TL;DR, Bullet points, Technical, or Simplified.
* **Smart Content Handling:**
  * Merges default static tags, native RSS categories, and AI-generated keywords.
  * Optional full-text webpage scraper for truncated RSS feeds (< 400 characters).
  * Optional permanent archive link to the [Wayback Machine](https://archive.org/).
* **Interactive ntfy Push Notifications:**
  * Action buttons (*"Read article"* & *"View on Shaarli"*).
  * Automatically attaches lead article images.
  * Supports Bearer token authentication for private topics.
* **Built-in Self-Diagnostics:** One-click test button in configuration to check Shaarli JWT authentication, AI credentials, and ntfy connectivity.

---

## Directory Structure

```text
xExtension-ShaarliSync/
├── configure.phtml
├── extension.php
├── metadata.json
├── LICENSE
├── README.md
└── i18n/
    ├── ar/ext.php
    ├── de/ext.php
    ├── en/ext.php
    ├── es/ext.php
    ├── fr/ext.php
    ├── it/ext.php
    ├── pt-br/ext.php
    └── zh-cn/ext.php
```

---

## Installation

1. Copy or clone this folder into your FreshRSS extensions directory:
   ```bash
   cd /opt/freshrss/extensions/
   git clone https://github.com/your-username/xExtension-ShaarliSync.git
   ```

2. Set proper ownership and permissions for your web server:
   ```bash
   sudo chown -R www-data:www-data /opt/freshrss/extensions/xExtension-ShaarliSync
   sudo chmod -R 755 /opt/freshrss/extensions/xExtension-ShaarliSync
   ```

3. Enable the extension in **FreshRSS** under **Settings > Extensions**.
4. Configure your Shaarli API URL, Secret, and optional AI/ntfy preferences.

---

## License

Released under the [MIT License](LICENSE).
