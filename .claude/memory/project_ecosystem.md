---
name: klawty_ecosystem
description: Full Klawty ecosystem context — 5 projects, 4-layer revenue, 6 verticals, portal, ARCA, license protection, Inscape production reference
type: project
---

WP-Claw is one of 5 interconnected projects in the Klawty ecosystem by dcode technologies (Luxembourg).

**Why:** Understanding the full ecosystem prevents building features that conflict with or duplicate other projects. WP-Claw is the WordPress entry point into the managed Klawty hosting funnel.

**How to apply:** WP-Claw must always funnel to ai-agent-builder.ai for managed hosting subscriptions. Never build payment processing into the WP plugin itself. Never duplicate portal features — WP-Claw provides WP-native admin views, not a full portal.

## 5 Projects

1. **Klawty OS** (`../klawty/`) — open-source agent runtime, MIT, klawty.ai, GitHub: dcode-tec/Klawty. Port 2508.
2. **AI Agent Builder** (`../app/`) — commercial storefront, ai-agent-builder.ai, Stripe + Supabase, Hetzner VPS.
3. **Customer Portal** (`../portal/`) — management dashboard, 7 variants, ai-agent-builder.eu subdomains.
4. **WP-Claw** (`./`) — WordPress plugin, wp-claw.ai, free on wordpress.org.
5. **Inscape System** (production reference) — 8 agents, 1,000+ tasks/month, approximately 37 EUR AI spend.

## Current Pricing (2026-03-21)

Managed: 99/249/449 EUR/mo (Starter/Pro/Business). Annual: 2mo free.
Self-hosted: 49/149/299 EUR (Solo/Team/Fleet). Updates: 9.90 EUR/mo.
Every plan = 1 Architect + N operators. Architect is always the main agent.

## Verticals: 6 existing + WP-Claw = 7th

restaurants, real-estate, construction, resellers, accounting, law-firms, + wordpress (WP-Claw)

Each vertical has a source-of-truth JSON at `../verticals/{slug}.json` containing complete agent definitions (tools, skills, schedules, message types, business rules). Total: 23 agents, 188 native tools, 66 message types, 37 business rules, 72 skill files.

Architecture spec: `../klawty-app/VERTICAL-AGENT-ARCHITECTURE.md`
Pipeline spec: `../VERTICAL-GENERATION-PIPELINE.md`

## Architect Agent (CRITICAL)

Every plan includes 1 Architect agent — the main agent that orchestrates all operators. It is NOT a preset, NOT optional. Pre-fitted verticals ship with 5 agents (1 Architect + 4 operators). The Architect customizes dashboards, tunes agents, builds plugins, and manages the system.

## Founding Member Program

First 10 managed customers: 30-day free trial (no CC), locked pricing at 79/199/399€/mo (vs regular 99/249/449), badge, Discord DM with Islem, roadmap input, name on site. Live at ai-agent-builder.ai/#founding.

## Internal AI Caps (NEVER show to customer)

Strategy says: "Internal daily AI caps (never shown to customer)." Starter 2€/day, Pro 4€/day, Business 7€/day. Enforced by throttling cycle frequency, not hard stops. 5-tier routing keeps actual spend well below caps.

## Strategy Document

Master reference: `../docs/KLAWTY-ECOSYSTEM-STRATEGY.md` (845 lines, all decisions)