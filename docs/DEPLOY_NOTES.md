# Deploy Notes — Nearbypost D11–D20

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
| `main` | `f02854bb` | Authoritative source of truth |
| `workspace-d16-d20` | `f02854bb` | Server checkout (= main) |
| `production-legacy` | `ba0b1a20` | Old server main baseline (pre-audit) |

---

## Server Checkout Notes

- Server at `/var/www/nearbypost` should always be on `workspace-d16-d20`
- Deploy: `git pull --ff-only origin workspace-d16-d20`
- **Never commit on the server.** Server is a deployment target, not an authoring environment.
- **Never treat server as canonical state.** Workspace + GitHub is truth.
