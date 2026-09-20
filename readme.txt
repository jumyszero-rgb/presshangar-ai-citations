=== PressHangar AI Citations ===
Contributors: presshangar
Tags: llms.txt, GPTBot, AI crawler, robots.txt, AI SEO
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate llms.txt and control AI crawlers (GPTBot, ChatGPT) via robots.txt and IndexNow — so your content is found and cited by AI search.

== Description ==

PressHangar AI Citations helps your content get found and cited by AI search. Generate an llms.txt file, allow or block AI crawlers such as GPTBot and ChatGPT through robots.txt, and ping IndexNow — a simple AI SEO / GEO toolkit for WordPress.

Billions of people use AI assistants every day. Many get useful information from your WordPress site—but few ever know it came from you. PressHangar AI Citations changes that.

Most WordPress plugins block AI crawlers or ignore them. PressHangar AI Citations takes the opposite approach: it optimizes your site to be cited, not just crawled. Whether you want to appear in ChatGPT's responses, show up in AI search results, or earn attribution in Perplexity answers, PressHangar AI Citations gives you the tools to make it happen.

The plugin includes four powerful features:

**1. AI Crawler Manager**
Control how ChatGPT, Gemini, Perplexity, Claude, Apple Intelligence, and other AI systems access your site. Set per-bot Allow/Block preferences for GPTBot, Google-Extended, PerplexityBot, ClaudeBot, and 10+ others. PressHangar AI Citations includes a unique physical robots.txt mode that works where other robots.txt plugins fail—ensuring your preferences are never silently overridden by other plugins.

**2. FAQ Schema Auto-Generation**
Automatically detect Q&A patterns in your posts and generate valid FAQPage JSON-LD schema. AI assistants use structured data to understand and cite your content. PressHangar AI Citations generates it for you, with smart duplicate guards to prevent conflicts with Yoast or Rank Math.

**3. llms.txt Generator**
Create and maintain an llms.txt file—an emerging standard that some AI systems are beginning to recognize. While the ecosystem is still developing, llms.txt can help newer models discover and understand your site's purpose.

**4. Measurement Links**
Quick links to Bing AI Performance, Google Search Console, and the Rich Result Test—so you can track whether your optimization efforts are working.

**Built on Honesty**
We designed PressHangar AI Citations with a principle: tell you what actually works and what's experimental. ChatGPT citations aren't guaranteed, even with perfect setup—Google and OpenAI control their systems. But the evidence shows that sites following these practices *do* get cited more often. We flag emerging tactics like llms.txt with honest disclaimers, so you know what to expect.

PressHangar AI Citations is free, open source, and created by PressHangar—the team behind WordPress solutions that actually help your business. PressHangar is a brand of Musubiemu LLC.

== Installation ==

1. Go to **Plugins > Add New** in your WordPress admin.
2. Search for "PressHangar AI Citations" and click **Install Now**.
3. Click **Activate** and then visit **Settings > PressHangar AI Citations** to configure.

**Or install manually:**
1. Download the plugin from WordPress.org.
2. Upload the `presshangar-ai-citations` folder to `/wp-content/plugins/`.
3. Activate from the **Plugins** page.
4. Configure at **Settings > PressHangar AI Citations**.

**Getting Started:**
- Go to the **AI Crawlers** tab and review the defaults. Allow the AI assistants you want, block the rest.
- Check the **FAQ Schema** tab if your posts use Q&A format—PressHangar AI Citations can auto-generate schema for you.
- Visit the **llms.txt** tab to create your site's llms.txt file (optional, still experimental).

No configuration is required—PressHangar AI Citations works out of the box with sensible defaults.

== Frequently Asked Questions ==

= Will this guarantee my site appears in ChatGPT? =

No, and we won't pretend it will. OpenAI, Google, and other AI companies control what their systems cite. What we *know* from real-world data: sites that are crawlable, well-structured, and discoverable get cited more often than sites that block AI crawlers or have poor content structure. PressHangar AI Citations helps you be discoverable and well-structured. The rest is up to the AI companies.

= Why do I need a physical robots.txt mode? =

Most WordPress robots.txt solutions use virtual modes—they filter the output through hooks. This usually works fine, but we've seen cases where other plugins override or ignore these hooks, especially under load or with certain hosting setups. Our physical mode writes directly to a robots.txt file in your site root, which always wins. Virtual is the default and works for 99% of sites. Physical is there when you need it.

= Does PressHangar AI Citations conflict with Yoast SEO or Rank Math FAQ schema? =

No. PressHangar AI Citations has a duplicate-schema guard built in. If it detects that Yoast or Rank Math is already generating FAQ schema on a page, PressHangar AI Citations skips it. You won't end up with duplicate JSON-LD blocks.

= What about llms.txt? Do major AI assistants actually read it? =

llms.txt is an emerging convention. Some newer models are beginning to recognize it, but there's no guarantee ChatGPT, Gemini, or Perplexity will read yours. Think of it as early-stage optimization—potentially useful, but not a core strategy. We show you this honestly so you can decide if it's worth enabling.

= Does this plugin slow down my site? =

No meaningful impact. The AI Crawler Manager works at the robots.txt level. FAQ Schema is generated once per page load via a footer hook. llms.txt is generated once when you save settings. No database calls, no external requests, no performance tax.

= What if I have questions about a specific AI system? =

Visit the **PressHangar AI Citations** plugin page at presshangar.com/presshangar-ai-citations for guides on ChatGPT, Gemini, Perplexity, Claude, and other AI systems—including what you can and can't control.

= Does PressHangar AI Citations support multisite? =

Version 0.1.0 supports single-site installations. Multisite support is planned for a future release.

== Screenshots ==

1. AI Crawler Manager—toggle each AI bot on, off, or "no rules."
2. FAQ Schema tab—preview detected Q&A and enable auto-generation.
3. llms.txt builder—create your site's discovery file.
4. Real robots.txt preview and backup history.

== External services ==

This plugin can connect to the IndexNow API (api.indexnow.org) to notify participating search engines (such as Microsoft Bing and Yandex) when your content changes, so they can discover and re-crawl it faster.

IndexNow is optional. It is used only when you enable it and generate an IndexNow key on the plugin's IndexNow settings screen. If you do not enable it, the plugin does not contact any external service.

What is sent, and when: while IndexNow is enabled, the plugin sends a request to api.indexnow.org each time you publish, update, or delete a post or page, and when you use the "manual submit" button on the IndexNow screen. Each request contains only: your site's host name, your IndexNow key, the URL of the key file hosted on your own site, and the list of your own site's URLs that changed. No personal data and no visitor data is sent.

The shared endpoint at api.indexnow.org is operated by the participating search engines. Please review their terms and privacy information:

* IndexNow: https://www.indexnow.org/
* IndexNow documentation: https://www.indexnow.org/documentation
* IndexNow terms: https://www.indexnow.org/terms
* Microsoft Privacy Statement (Bing operates the shared endpoint): https://privacy.microsoft.com/privacystatement

== Changelog ==

= 0.3.8 =
* Re-release to publish the search-keyword readme optimization and refresh "Tested up to". The previous tag did not update the directory listing. No functional or data changes.

= 0.3.7 =
* Added a gentle, dismissible review request that appears only after you've used the plugin (no incentives). readme search-keyword optimization. No functional or data changes.

= 0.3.6 =
* Added bundled translations for French, Spanish, German, Brazilian Portuguese, and Italian, so the full admin interface (including the Getting started panel) is localized out of the box in seven languages alongside English and Japanese.

= 0.3.5 =
* Added a state-aware "Getting started" panel that guides first-time setup, clearer intro copy, and Japanese translations for the new strings.

= 0.3.4 =
* Add: Japanese translation (bundled) + text-domain loading (Domain Path: /languages).

= 0.3.3 =
* Documented the IndexNow external service in the readme (External services section), with terms and privacy links
* robots.txt Disallow/Allow paths are now derived from admin_url() instead of a hardcoded /wp-admin/ path
* Plugin URI now points to the plugin's dedicated page at presshangar.com

= 0.3.1 =
* Passes Plugin Check with zero errors and warnings: removed the Update URI header and sanitized the IndexNow URL input

= 0.3.0 =
* Renamed plugin to PressHangar AI Citations; unique prefix (phcite_) for all functions, options, and constants
* Removed bundled translations and load_plugin_textdomain() — translations are served from translate.wordpress.org (WordPress 4.6+)

= 0.2.0 =
* Add IndexNow support: key hosting, auto-submit on publish, manual submit, submission log

= 0.1.2 =
* WordPress.org submission fixes: slug/textdomain alignment, tested up to 7.0

= 0.1.1 =
* WP_Filesystem-based file operations; readme tags cleanup; author update

= 0.1.0 =
* Initial release
* AI Crawler Manager with 13+ bot profiles and physical robots.txt mode
* Automatic FAQPage JSON-LD schema generation with duplicate guards
* llms.txt generator
* Measurement links for tracking AI citations
* Support for WordPress 6.0+
* Full i18n support (English and Japanese)

== Credits ==

PressHangar AI Citations is made by **PressHangar** (https://presshangar.com). We build WordPress plugins that help your site get discovered and cited.

== Support ==

For support, questions, or feature requests, visit https://presshangar.com/presshangar-ai-citations or check the plugin's GitHub repository.
