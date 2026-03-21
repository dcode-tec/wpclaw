---
name: inscape_production_reference
description: Inscape Interiors production system — 8 agents, 1,000+ tasks/month, 37 EUR AI spend, running since late 2025. The proof that Klawty works.
type: reference
---

The Inscape system is the production reference for all Klawty products. It proves the architecture works at scale.

## Stats
- 8 agents: Nour (Chief of Staff), Leila (Client Ops), Raph (Dev), Zara (Marketing), Sentinel (Safety), Falco (Finance), Mira (BI), Sami (Sales)
- 1,000+ tasks/month completed autonomously
- approximately 37 EUR/month AI spend (5-tier routing)
- 150+ tools, 39 skills
- 250+ Qdrant memory vectors
- Mac mini in Luxembourg, 24/7 operation
- 4 SQLite databases (tasks, CRM, tracker, Qdrant)

## Why It Matters for WP-Claw
- Proves multi-agent coordination works (agent delegation, proposals, discovery)
- Proves 5-tier routing keeps AI costs at approximately 4.6 EUR/agent/month
- Proves 4-layer dedup prevents spam
- Proves circuit breaker + health monitor keeps system stable
- Production metrics used in marketing ("100+ emails/day", "150+ invoices/month")
- Architecture patterns (task-executor, agent-brain, tool-runner) are the same patterns WP-Claw agents use