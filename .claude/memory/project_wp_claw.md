---
name: wp_claw_project
description: WP-Claw WordPress plugin — AI operating layer replacing 10-15 plugins. Connects WP to Klawty agents via REST.
type: project
---

WP-Claw (wp-claw.ai) is a WordPress plugin by dcode technologies that replaces 10-15 individual plugins with one AI-powered operating layer connected to a Klawty agent instance.

**Why:** WordPress sites need Yoast + Wordfence + WP Rocket + WPForms + FluentCRM + MonsterInsights + UpdraftPlus + Buffer = 700-3000 EUR/yr + complexity. WP-Claw replaces all of them with 6 AI agents (1 Architect + 5 operators) for 99-449 EUR/mo.

**How to apply:** Plugin is PHP-only, lightweight, GPL-2.0. All AI processing happens on the Klawty backend. Plugin communicates via REST/HTTP with HMAC-SHA256 signed webhooks. Agents interact with WordPress exclusively through WP REST API endpoints — never direct DB or file access.

## URLs
- Free plugin: https://github.com/dcode-tec/wpclaw
- Product site: https://wp-claw.ai
- Subscribe (buy managed): https://wp-claw.ai/subscribe
- WordPress vertical on agent-builder: https://ai-agent-builder.ai/solutions/wordpress (CTAs link to wp-claw.ai)

## Agent Team (6 agents: 1 Architect + 5 operators)

| Agent | Role | Modules |
|-------|------|---------|
| **Architect** | Main agent — orchestrates, custom dev, dashboard config | Forms, system-wide |
| **Scribe** | Content, SEO, social media | SEO, Content, Social |
| **Sentinel** | Security, backups, file integrity | Security, Backup |
| **Commerce** | WooCommerce, CRM, leads | Commerce, CRM |
| **Analyst** | Analytics, performance, reports | Analytics, Performance |
| **Concierge** | Customer-facing chat widget | Chat (only customer-visible agent) |

## Key Constraints
- WP-Claw is NOT in the ai-agent-builder.ai wizard — separate purchase flow at wp-claw.ai/subscribe
- No custom apps/plugins on WP-Claw instances — Architect works ONLY on the WordPress site (SEO, content, forms, dashboard config)
- Architect cannot: create new agents, build plugins, modify system architecture, access filesystem beyond WP APIs
- The Architect's scope is WEBSITE OPERATIONS, not system customization

## Key Decisions
- Free plugin on GitHub: github.com/dcode-tec/wpclaw (drives installs, funnel to managed Klawty)
- 11 modules (SEO, Security, Content, CRM, Commerce, Performance, Forms, Analytics, Backup, Social, Chat), each mapped to 1 of 6 agents
- WordPress Coding Standards (WPCS) mandatory — must pass phpcs
- sodium_crypto_secretbox for API key encryption
- Action allowlist enforced at REST API bridge level
- 3 custom DB tables: tasks, proposals, analytics
- WP-Cron for scheduled agent triggers (hourly to weekly)
- WooCommerce integration is conditional (class_exists check)
- i18n from day one (EN, FR, DE)
- 7th vertical in solutions (WordPress/Digital Business)
- Daily AI caps NEVER shown to customer — "All-inclusive" messaging only
- Architect is always included — free tier = 1 Architect, managed = Architect + operators
