---
name: operational_patterns
description: Operational patterns learned from building the agent-builder app — quality gates, deploy workflow, coherence checking, parallel agent execution
type: feedback
---

## Quality Gate Discipline

Always run quality gates before committing: linting + type check + build. For PHP this means phpcs + php -l + phpunit. For TypeScript it means tsc --noEmit + npm run build. Never commit without verification.

**Why:** Multiple coherence issues (wrong pricing, wrong agent counts, exposed internal caps) were caught only by systematic auditing. Build verification catches template errors that don't surface in type checking alone.

**How to apply:** Run the full quality gate chain before every commit. Document the gates in CLAUDE.md so future sessions follow the same discipline.

## Strategy Document as Single Source of Truth

`../docs/KLAWTY-ECOSYSTEM-STRATEGY.md` governs ALL product decisions. When code contradicts the strategy, the strategy wins. Audit the codebase against the strategy periodically.

**Why:** Pricing was wrong in 28 places, skill counts wrong in 6 places, memory tier count wrong in 4 places — all because the strategy evolved but the code didn't keep up.

**How to apply:** Before shipping any feature, cross-reference the strategy document. After strategy changes, run a coherence audit across all frontends.

## Never Expose Internal Caps

Daily AI caps (Starter 2€, Pro 4€, Business 7€) are internal cost management. Customer-facing messaging should say "AI included" or "All-inclusive" without revealing the daily amount.

**Why:** Exposing caps lets customers infer cost structure and creates anxiety. The value prop is "one price, everything included."

## Parallel Subagent Execution

When implementing independent tasks (e.g., 6 vertical tool files, 72 skill files), dispatch parallel subagents — one per independent unit. This session ran up to 7 agents simultaneously. Works because the tasks don't share state or files.

**How to apply:** Identify independent work units, dispatch in parallel, verify each output, then commit as one batch.

## Architect Agent is Mandatory

Every plan includes 1 Architect agent. Agent counts in marketing should be "5 agents (1 Architect + 4 specialists)" not "4 agents." The Architect is always +1 on top of the operator count. For WP-Claw, the agents are: Architect + Scribe + Sentinel + Commerce + Analyst + Concierge.
