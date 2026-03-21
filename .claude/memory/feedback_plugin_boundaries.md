---
name: plugin_boundaries
description: WP-Claw plugin boundaries — what it does vs what other ecosystem projects handle. Prevents feature duplication.
type: feedback
---

WP-Claw is a BRIDGE, not a platform. Keep the plugin lightweight.

**Why:** The Klawty runtime, portal, and storefront are separate projects. If WP-Claw duplicates their features, we maintain the same logic in two places and confuse the product boundaries.

**How to apply:**

DO in WP-Claw (WordPress-native):
- WP admin dashboard pages (agent status, tasks, proposals)
- WP REST API bridge (action execution, state sync)
- WP-Cron scheduled triggers (health, SEO, backup, analytics)
- WP hook integration (post_save, login, WooCommerce events)
- Frontend chat widget (Concierge agent)
- Module enable/disable toggles
- Settings page (API key, connection mode, module config)
- WP admin bar badge (agent status indicator)

DO NOT in WP-Claw (handled by other projects):
- Full portal dashboard (that's the portal project at ../portal/)
- Payment processing (that's ai-agent-builder.ai with Stripe)
- Agent configuration/creation (that's the wizard at ai-agent-builder.ai/build)
- Runtime execution engine (that's Klawty OS at ../klawty/)
- License management (that's the runtime's license-guard)
- Vertical agent definitions (that's ../verticals/*.json)
- Blog/marketing content (that's ai-agent-builder.ai)