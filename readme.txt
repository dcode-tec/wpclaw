=== WP-Claw ===
Contributors: dcodetec
Donate link: https://wp-claw.ai
Tags: ai, automation, seo, security, woocommerce, analytics, chat, backup, performance, crm
Requires at least: 6.4
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The AI Operating Layer for WordPress — replaces 10-15 plugins with one AI-powered system.

== Description ==

WP-Claw connects your WordPress site to a Klawty AI agent team that handles SEO, security, content creation, e-commerce, analytics, CRM, forms, backups, performance, social media, and live chat — autonomously.

This is not an AI chatbot plugin. This is an operating system layer for your entire WordPress site.

**What WP-Claw Replaces:**

* Yoast / RankMath / AIOSEO (SEO)
* Wordfence / Sucuri / iThemes Security (Security)
* WP Rocket / W3 Total Cache (Performance)
* Gravity Forms / WPForms / Contact Form 7 (Forms)
* FluentCRM / HubSpot for WordPress (CRM)
* MonsterInsights / Site Kit (Analytics)
* UpdraftPlus / BackupBuddy (Backup)
* Jetpack Social / Buffer (Social Media)
* Tidio / LiveChat / Intercom / Zendesk Chat (Chat)

**6 AI Agents, each with a defined role:**

* **Architect** — orchestration, custom WordPress development, system tuning
* **Scribe** — content creation, SEO optimisation, social media scheduling
* **Sentinel** — security monitoring, backups, file integrity, WAF rules
* **Commerce** — WooCommerce management, CRM, lead capture and scoring
* **Analyst** — privacy-first analytics, performance audits, weekly reports
* **Concierge** — customer-facing live chat widget with product recommendations

**11 Modules — Enable Only What You Need:**

* SEO — automated meta titles, descriptions, schema markup, sitemap, internal linking
* Security — login monitoring, file integrity, malware scan, firewall rules, 2FA
* Content — AI-generated blog posts, product descriptions, landing pages, A/B copy
* CRM — lead capture, pipeline management, follow-up sequences, segmentation
* Commerce — WooCommerce stock alerts, dynamic pricing, abandoned cart recovery
* Performance — cache strategy, image optimisation, Core Web Vitals monitoring, DB cleanup
* Forms — AI-built custom forms as Gutenberg blocks, submission handling, CRM integration
* Analytics — privacy-first tracking (no cookies, GDPR compliant), conversion funnels
* Backup — scheduled DB and file backups, offsite storage, restore verification
* Social — auto-generate and schedule social posts from published content
* Chat — floating AI chat widget for visitors with product knowledge and order tracking

**Proposal System — You Stay in Control:**

High-impact actions (publishing posts, sending emails, modifying security rules) require admin approval before execution. Proposals appear in your WP admin dashboard with one-click approve or reject.

**Security-First Architecture:**

* Agents operate through a strict action allowlist — no arbitrary PHP execution
* API keys encrypted with libsodium (sodium_crypto_secretbox)
* All webhooks signed with HMAC-SHA256
* Agents NEVER have direct database access
* Clean uninstall removes all data

**Powered by Klawty:**

WP-Claw is the bridge to Klawty — an open-source AI agent operating system. Connect to a self-hosted Klawty instance or subscribe to a managed instance at ai-agent-builder.ai.

= Connection Modes =

**Managed** — Connect to a Klawty instance hosted by dcode technologies. Full 6-agent team, no server required, AI credits included. Plans from 99 EUR/month.

**Self-hosted** — Run Klawty on your own VPS or server. Connect via localhost. Full control, lower cost. Klawty is free and open-source.

== Installation ==

1. Upload the `wp-claw` folder to `/wp-content/plugins/`
2. Activate the plugin via the Plugins menu in WordPress admin
3. Navigate to **WP-Claw > Settings**
4. Enter your Klawty API key (managed) or configure localhost connection (self-hosted)
5. Enable the modules you need
6. Configure each module from its settings tab

== Frequently Asked Questions ==

= Does WP-Claw work without WooCommerce? =

Yes. The Commerce module requires WooCommerce, but all other 10 modules work on any WordPress site. WP-Claw automatically detects whether WooCommerce is installed.

= Where does the AI processing happen? =

All AI processing happens on your Klawty instance — either the managed service hosted by dcode technologies, or your own self-hosted Klawty server. The WP-Claw plugin is a lightweight bridge.

= Is my data sent to third parties? =

WP-Claw communicates only with your Klawty instance. No site data is sent to any other third party. For managed instances, data is processed on dcode's European servers (Luxembourg).

= Can agents break my site? =

Agents operate through a strict action allowlist. No arbitrary code is executed. High-impact actions (publishing content, modifying settings, sending emails) enter the proposal queue and require your approval before anything changes.

= Is the chat widget GDPR compliant? =

Yes. The chat widget does not set cookies or track visitors without consent. Conversation data is stored on your Klawty instance only.

= What happens if I deactivate the plugin? =

Deactivating clears the cron schedule and disconnects the agents. Your WordPress site continues to function normally. All WP-Claw data is preserved in the database. Uninstalling removes all data.

= Can I use WP-Claw without any AI subscription? =

You need a Klawty instance to use the agents. You can self-host Klawty for free (open-source, bring your own LLM API keys) or subscribe to a managed instance.

= Does the Concierge chat widget slow down my site? =

The chat widget loads asynchronously and does not block page rendering. The script is approximately 12 KB minified. It is only loaded on the frontend when the Chat module is enabled.

= How do I get a Klawty API key? =

Sign up at ai-agent-builder.ai for a managed instance. Your API key is provided in the dashboard. For self-hosted Klawty, the token is in your Klawty configuration file.

== Screenshots ==

1. Dashboard — agent status, active tasks, KPI cards, and recent activity feed
2. Settings — connection configuration, module toggles, and chat widget customisation
3. Proposals — pending agent actions with approve and reject controls
4. Chat widget — customer-facing AI assistant on the frontend

== Changelog ==

= 1.0.0 =
* Initial release
* 11 modules: SEO, Security, Content, CRM, Commerce, Performance, Forms, Analytics, Backup, Social, Chat
* 6 AI agents: Architect, Scribe, Sentinel, Commerce, Analyst, Concierge
* Admin dashboard with agent status, task list, and KPI cards
* Proposal approval system with one-click approve and reject
* Frontend chat widget with product recommendations and order tracking
* Privacy-first analytics — no cookies, GDPR compliant
* WooCommerce integration for Commerce and Concierge modules
* Encrypted API key storage using libsodium
* HMAC-SHA256 signed webhook verification
* WP-Cron scheduled events for health checks, backups, SEO audits, and reports
* Clean uninstall removes all tables, options, transients, cron events, and uploads

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP-Claw.
