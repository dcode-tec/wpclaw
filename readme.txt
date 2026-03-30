=== Claw Agent ===
Contributors: dcodetechnologies
Donate link: https://wp-claw.ai
Tags: ai, automation, seo, security, woocommerce
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace 10+ WordPress plugins with 6 AI agents. SEO, security, content, commerce, analytics, chat.

== Description ==

Claw connects your WordPress site to a managed team of AI agents that handle SEO, security, content, e-commerce, analytics, CRM, forms, backups, performance, social media, and live customer chat — autonomously, 24/7.

**This is not a chatbot plugin.** This is an operating layer — six specialized agents that work on your site the way a dev team would, but continuously and at a fraction of the cost.

= What Claw Replaces =

* Yoast / RankMath / AIOSEO — SEO optimization
* Wordfence / Sucuri — Security monitoring
* WP Rocket / W3 Total Cache — Performance
* Gravity Forms / WPForms / CF7 — Form management
* FluentCRM / HubSpot — CRM and lead scoring
* MonsterInsights / Site Kit — Analytics
* UpdraftPlus / BackupBuddy — Backups
* Jetpack Social / Buffer — Social scheduling
* Tidio / LiveChat / Intercom — Live chat

= 6 AI Agents =

* **Architect (Karim)** — Site operations, orchestration, custom development
* **Scribe (Lina)** — SEO optimization, content creation, social media
* **Sentinel (Bastien)** — Security monitoring, backups, file integrity
* **Commerce (Hugo)** — WooCommerce automation, CRM, lead scoring
* **Analyst (Selma)** — Privacy-first analytics, performance audits
* **Concierge** — Live chat widget with product knowledge and order tracking

= 11 Modules =

Each module can be enabled or disabled independently:

* **SEO** — Meta titles, descriptions, schema markup, sitemap, internal linking
* **Security** — Login monitoring, brute-force protection, IP blocking, security headers
* **Content** — Blog post drafting, page creation, translations, excerpts
* **CRM & Leads** — Lead capture from WPForms/Gravity/CF7, scoring, pipeline
* **Commerce** — WooCommerce stock alerts, order monitoring, coupon management
* **Performance** — Core Web Vitals monitoring, database cleanup, cache strategy
* **Forms** — Custom form creation, submission tracking, GDPR-compliant storage
* **Analytics** — Privacy-first pageview tracking (no cookies), 90-day retention
* **Backup** — Scheduled database exports, gzip compression, retention policy
* **Social** — Auto-generate social posts from published content
* **Chat** — Floating AI chat widget with product recommendations and lead capture

= Proposal System — You Stay in Control =

High-impact actions require your approval before execution. Proposals appear in WP admin with one-click approve or reject. Every action is logged with full audit trail.

= Security Architecture =

* Agents operate through a strict action allowlist — no arbitrary code execution
* API keys encrypted at rest with libsodium (AES-256-CBC fallback)
* All communication signed with HMAC-SHA256 (5-minute replay window)
* Agents never have direct database access
* Circuit breaker with exponential backoff prevents cascading failures
* Clean uninstall removes all data (tables, options, cron, transients, capabilities)

= External Service =

Claw requires a connection to a Klawty AI agent instance to function. The plugin communicates with:

* **wp-claw.ai** — Connection proxy and customer dashboard ([Terms of Service](https://wp-claw.ai/terms), [Privacy Policy](https://wp-claw.ai/privacy))
* **Your Klawty instance** — AI agent runtime (managed at ai-agent-builder.ai or self-hosted)

Data transmitted: site URL, WordPress version, PHP version, plugin version, task payloads (post titles, form submissions, chat messages), and site state snapshots. All communication is encrypted via HTTPS with HMAC-SHA256 signatures. No data is shared with third parties.

= Connection Modes =

* **Managed** — Connect to a Klawty instance hosted by dcode technologies. Plans from 99 EUR/month at [wp-claw.ai](https://wp-claw.ai).
* **Self-hosted** — Run [Klawty OS](https://klawty.ai) on your own server. Free, open-source (MIT license). Bring your own LLM API keys.

= Built by dcode technologies =

Claw is built by [dcode technologies](https://d-code.lu), an agentic AI systems integrator based in Luxembourg. Powered by [Klawty OS](https://klawty.ai).

== Installation ==

= Managed Mode (Recommended) =

1. Subscribe at [wp-claw.ai](https://wp-claw.ai)
2. Upload the `wp-claw` folder to `/wp-content/plugins/` or install from WordPress admin
3. Activate the plugin via the Plugins menu
4. Go to **Claw > Settings** and paste your connection token
5. Click **Verify & Connect** — agents activate within 60 seconds
6. Enable the modules you need from the Modules tab

= Self-Hosted Mode =

1. Install [Klawty OS](https://klawty.ai) on your server
2. Upload the `wp-claw` folder to `/wp-content/plugins/`
3. Activate the plugin
4. Go to **Claw > Settings**, set connection mode to **Self-hosted**
5. Enter your Klawty instance URL (e.g., `http://localhost:2508`)

= Requirements =

* PHP 7.4 or higher
* WordPress 6.4 or higher
* WooCommerce 8.0+ (optional, for Commerce module only)
* SSL certificate (required for managed mode)

== Frequently Asked Questions ==

= Does Claw work without WooCommerce? =

Yes. The Commerce module requires WooCommerce, but all other 10 modules work on any WordPress site. Claw automatically detects whether WooCommerce is installed and hides the Commerce module if it is not.

= Where does the AI processing happen? =

All AI processing happens on your Klawty instance — either the managed service hosted by dcode technologies in Luxembourg, or your own self-hosted server. The Claw plugin is a lightweight bridge that sends tasks and receives results.

= Is my data sent to third parties? =

Claw communicates only with wp-claw.ai (connection proxy) and your Klawty instance. No site data is sent to any other third party. For managed instances, data is processed on European servers in Luxembourg.

= Can agents break my site? =

Agents operate through a strict action allowlist. No arbitrary code is executed. High-impact actions enter the proposal queue and require your explicit approval before anything changes on your site.

= Is the chat widget GDPR compliant? =

Yes. The chat widget does not set cookies or track visitors. Conversation data is stored on your Klawty instance only. The analytics module uses no cookies and respects Do Not Track headers.

= What happens if I deactivate the plugin? =

Deactivating clears scheduled events and disconnects agents. Your WordPress site continues normally. All Claw data is preserved in the database. Uninstalling removes all data (tables, options, cron events, transients, capabilities, backup directory).

= Can I use Claw without any AI subscription? =

You need a Klawty instance for the agents to function. You can self-host Klawty for free (open-source, MIT license) and provide your own LLM API keys, or subscribe to a managed instance at wp-claw.ai.

= Does the chat widget slow down my site? =

The chat widget loads asynchronously and does not block page rendering. The script is approximately 12 KB minified and only loads when the Chat module is enabled.

= How do I get support? =

Email hello@wp-claw.ai or visit the [documentation](https://wp-claw.ai/docs) for setup guides, API reference, and troubleshooting.

== Screenshots ==

1. Dashboard — agent status cards, KPI metrics, recent task feed
2. Settings — connection configuration, API key management, module toggles
3. Proposals — pending agent actions with one-click approve and reject
4. Modules — per-module settings with allowed actions display
5. Agents — per-agent status with current task and uptime
6. Chat widget — AI-powered customer assistant on the frontend

== Changelog ==

= 1.0.3 =
* Security: Fixed all 21 $wpdb->prepare() SQL injection issues across 9 files
* Uses WordPress 6.2+ %i identifier placeholder for table names
* PHPCS WordPress.DB.PreparedSQL now reports 0 errors

= 1.0.2 =
* Code quality: WPCS WordPress-Extra compliance — 2,969 violations auto-fixed
* Consistent indentation, spacing, Yoda conditions across all PHP, JS, CSS

= 1.0.1 =
* Infrastructure: Git repository setup, internal docs removed from tracking

= 1.0.0 =
* Initial release
* 11 modules: SEO, Security, Content, CRM, Commerce, Performance, Forms, Analytics, Backup, Social, Chat
* 6 AI agents: Architect, Scribe, Sentinel, Commerce, Analyst, Concierge
* Admin dashboard with 5 pages (Dashboard, Agents, Proposals, Modules, Settings)
* Command Center with 7-layer security (capability, PIN, IP allowlist, rate limit, input validation, encrypted audit, Sentinel review)
* Frontend chat widget with product recommendations and order tracking
* Privacy-first analytics (no cookies, GDPR compliant by default)
* WooCommerce integration for Commerce and Concierge modules
* Encrypted API key storage (libsodium with AES-256-CBC fallback)
* HMAC-SHA256 signed webhook verification with 5-minute replay window
* Circuit breaker with exponential backoff
* 9 WP-Cron scheduled events (health, backup, SEO, security, analytics, performance, content, commerce, state sync)
* 7 custom capabilities across administrator and editor roles
* Clean uninstall (tables, options, transients, capabilities, cron, backup directory)

== Upgrade Notice ==

= 1.0.3 =
Security fix: resolves SQL injection surface area in 9 plugin files. Update recommended.

= 1.0.0 =
Initial release of Claw — the AI operating layer for WordPress.
