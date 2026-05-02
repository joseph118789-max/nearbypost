# Deploy Notes — Nearbypost D11–D20

## Post-D20 Remaining Debts

### 1. Async Queue Worker (D17)
- `RefreshHomeFeedCache`, `RefreshCategoryFeedCache` jobs exist in codebase
- Queue connection configured for `redis` in `.env`
- No active queue worker process confirmed on server
- Cache correctness falls back safely to miss-as-cache-invalid behavior
- **Not a blocker** but prevents async refresh from running

### 2. Geo Data Quality
- Code serves results; geo behavior is operational
- `precision_type` population too sparse for reliable production gate (~3690/3692 NULL)
- `relevance_mode != 'category_only'` used as working proxy rule
- Future: tighten back to `precision_type IN (...)` once pipeline enrichment improves `precision_type` population

### 3. Production Branch Strategy
- Server currently tracks `workspace-d16-d20-geo-fix` (named deploy branch)
- Live should eventually run from `main` (normalized, not special-purpose)
- Not urgent; normalize after queue worker is stable

---

## Geo Feed Rule (D16/D18)

**Current rule:** `relevance_mode != 'category_only'`

**Why not `precision_type IN ('exact_area', 'approximate_area')`:**
- `precision_type` is not populated enough to serve as a reliable production gate
- `precision_type` is NULL for ~3690 of 3692 rows in `feed_ready_items` — only 2 rows have values
- `relevance_mode` is the only field currently carrying usable coverage semantics at scale
- Live system needs working geo results more than theoretical strictness

**This is a conscious temporary policy, not an accidental permanent truth.**

**Future restoration:** Once enrichment coverage improves and `precision_type` is populated more densely, restore the stricter `precision_type IN (...)` gate.

**Reference commits:** `0ef71e7` (geo WHERE fix), `f02854b` (filter SELECT lat/lng fix)

---

## Deployment Branches

| Branch | SHA | Purpose |
|--------|-----|---------|
| `main` | `dd5fb13` | Authoritative source of truth |
| `workspace-d16-d20` | `f02854bb` | Server checkout (= main minus deploy docs) |
| `workspace-d16-d20-geo-fix` | `f02854bb` | Server current worktree |
| `production-legacy` | `ba0b1a20` | Old server main baseline (pre-audit) |

---

## Server Checkout Notes

- Server at `/var/www/nearbypost` currently on `workspace-d16-d20-geo-fix`
- Deploy: `git pull --ff-only origin workspace-d16-d20`
- **Never commit on the server.** Server is a deployment target, not an authoring environment.
- **Never treat server as canonical state.** Workspace + GitHub is truth.
- Medium-term: normalize production to track `main`
