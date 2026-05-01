# EIAAW Social Media Team

Multi-tenant SaaS that runs autonomous social media management for EIAAW Solutions internally and for external paying clients. Six specialised AI agents (Strategist / Writer / Designer / Scheduler / Community / Compliance) coordinated by a hard compliance gate. Provenance receipts on every post — no fabricated metrics, no hallucinated brand voice, no off-brand visuals.

**Live**:
- Dev: `https://app-dev-c31f.up.railway.app`
- Prod: `https://app-production-91f9.up.railway.app`
- Custom domain: `smt.eiaawsolutions.com` — pending workspace-wildcard resolution (see `memory/followups.md`)

## Stack (locked 2026-04-30)

- **Laravel 11** + PHP 8.3 (Railpack-built on Railway)
- **PostgreSQL 18** with `pgvector` extension (brand-voice corpus uses vector(1024) HNSW indices)
- **Redis** (provisioned but currently bypassed in cache/queue/session — see followups)
- **Filament v5.6** for the agency operator console (Livewire 4)
- **Spatie laravel-permission** (RBAC), **spatie/laravel-medialibrary** (asset model), **Sanctum** (API)
- **Anthropic SDK** (`anthropic-ai/sdk` v0.17), Claude Sonnet 4.6 + Haiku 4.5 routing
- **Blotato API** (publishing), **FAL.AI** (image generation) — wiring planned for next phase
- **Cloudflare R2** (S3-compatible asset disk; configured, not yet exercised)
- **Infisical** secrets bootstrap (per EIAAW Deploy Contract — `SecretsServiceProvider` is the first registered provider)
- **Railway** hosting (project `eiaaw-smt` — id `a8e6c372-b44e-470a-b470-2d6ab36bf9ff`); **dev** + **production** environments

## Local development

This project develops directly against Railway's dev environment Postgres (no local DB install). Local Redis is bypassed because Railway's TCP proxy refuses sustained predis connections from local network — local cache/session/queue use the database driver.

```bash
composer install                            # platform overrides for ext-pcntl/ext-posix preconfigured
php artisan migrate                         # runs against Railway dev Postgres
php artisan serve --port=8000               # local dev server, points at Railway dev DB via public proxy
```

The `.env` file ships pre-pointed at Railway dev. **Do not commit any modifications that change DB_HOST / REDIS_HOST.**

### Required PHP extensions

bcmath, curl, exif, fileinfo, gd, intl, mbstring, openssl, pcntl, pdo, posix, zip. All declared in `composer.json` `require`. On Windows + Laragon ensure `ext-zip` is enabled in `php.ini`.

## Architecture (high-level)

| Layer | What lives here |
|---|---|
| Sources | Client website + IG/FB/LinkedIn/TikTok/X/Threads APIs; CSV uploads; manual entries |
| Data | Postgres (operational + pgvector) + Cloudflare R2 (assets) |
| Integration | Blotato (publishing), FAL.AI (image gen), Anthropic, Playwright (onboarding scrape) |
| Trust | Brand-DNA contract, embargo list, banned phrases, factual grounding (RAG), audit_log (Postgres-trigger immutable), 2FA, RLS, kill switch per workspace |
| Reasoning | RAG over brand voice + historical posts + competitor refs; hand-rolled state machine in `pipeline_runs` table |
| Agents | Strategist / Writer / Designer / Scheduler / Community / Compliance — server-side classes invoked from Horizon jobs |
| Experience | Filament agency console (`/agency`), Filament admin (`/admin`), public marketing site (`/`), client-facing white-label portal (planned) |

## Schema

19 tables across users + multi-tenant workspaces + brands + content pipelines + telemetry. Key tables:

- `workspaces` / `workspace_members` — multi-tenant root with RBAC
- `brands` / `brand_styles` (vector embedding) / `brand_corpus` (vector embedding)
- `platform_connections` (encrypted OAuth tokens), `embargoes`, `banned_phrases`, `autonomy_settings`
- `content_calendars` / `calendar_entries` / `drafts` (heavy provenance fields)
- `compliance_checks` (one row per check per draft — fail = held)
- `scheduled_posts` (Blotato + native API)
- `performance_uploads` (CSV/manual; never auto-generated)
- `ai_costs` (per-call ledger for transparent pass-through)
- `pipeline_runs` (workflow state machine — replaces Inngest)
- `audit_log` (append-only, Postgres trigger blocks UPDATE/DELETE)

Migrations live in `database/migrations/2026_04_30_*`. Run `php artisan migrate:fresh --seed` to reset and reseed.

## Deploy

```bash
railway environment dev                     # or `production`
railway service app
railway up --detach --ci                    # Railpack-driven build using composer.json + npm
```

Build config:
- `railpack.json` — Railway Railpack config (mostly cosmetic; PHP extensions come from composer.json)
- `composer.json` — declares `ext-*` requirements that Railpack auto-installs
- Start command: `php artisan migrate --force && php artisan config:cache && ... && php artisan serve --host=0.0.0.0 --port=$PORT`

Custom domain wiring: see `memory/followups.md`.

## Memory & context

The agent (Claude) maintains project memory in `~/.claude/projects/.../memory/`. Key files:
- `MEMORY.md` — index
- `project_goal.md` — the dual-purpose mandate
- `architecture_decision.md` — Option C (web app + skill wrappers sharing agent logic)
- `truthfulness_contract.md` — provenance / no-hallucination contract
- `stack_decisions.md` — locked stack choices
- `railway_access.md` — Railway CLI & API access details
- `followups.md` — known issues to resolve

## Roadmap

**v1.0 (in flight)**:
- [x] Public landing at smt.eiaawsolutions.com (pending custom-domain DNS) with hard-angle messaging
- [ ] Auth + workspace + brand-creation flow (Filament Resources)
- [ ] Brand onboarding agent (Playwright scrape → Anthropic synthesis → brand_styles row with embedding)
- [ ] Content calendar agent (monthly pillar/format mix)
- [ ] Caption Writer + LinkedIn Writer + Compliance gate (brand voice score, factual grounding, embargo, dedup)
- [ ] Designer agent (FAL.AI Flux Pro + brand-DNA classifier)
- [ ] Publisher (Blotato integration + tiered autonomy lanes)
- [ ] Performance review (CSV upload + manual entry; never auto-predict)
- [ ] Threads / X / Facebook / Instagram writers
- [ ] Community agent (comment reply drafts)
- [ ] Stripe + Billplz billing
- [ ] White-label client portal

**v1.1+**: TikTok writer, stop-motion reels, native LinkedIn API for richer features Blotato lacks, advanced competitor analysis, MMM-lite for clients with paid spend.

## License

Proprietary. © 2026 EIAAW Solutions Sdn Bhd.
