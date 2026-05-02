# Phase 2 — Nearbypost Operational Stabilization

> Stabilize operations, improve geo quality. No architecture reopening.

---

## Priority 1: Queue Worker Reality Check

**Goal:** Confirm whether queued refresh jobs are actually consumed.

**Tasks:**
- [ ] Inspect queue connection in `.env` (currently `redis`)
- [ ] Check Redis queue depths / DB jobs table for backlog
- [ ] Verify if a persistent `queue:work` process exists on server
- [ ] Decide: stand up proper worker vs accept cache-aside-only for now

**Outcome:** Written status: worker state (running/missing/backlog size). Decision to act or defer.

---

## Priority 2: Geo Data Quality Audit — CONFIRMED DEFECT

**Elevated from "audit" to confirmed defect investigation.**

### Confirmed defect symptoms
- All `feed_ready_items` rows have `lat=3.139, lng=101.6869` (KL center — same coords)
- All `distance_km` values are `0`
- All `location_label` values are `null`
- No effective geographic discrimination in feed results
- Upstream geo enrichment produces homogeneous coordinates

### Root cause (suspected)
One of: AI enrichment defaults to KL center, alias enrichment collapses to single place, geocoding falls back to a default, or location label never propagates to `feed_ready_items`.

### Tasks
- [ ] Count distinct `lat/lng` pairs in `feed_ready_items`
- [ ] Count rows with null `location_label`, `precision_type`, `coverage_type`
- [ ] Sample 3–5 feed rows and trace backward: `feed_ready_items` → `news_items` → AI/enrichment fields → promotion step
- [ ] Identify where KL center default enters

### Status
**Confirmed defect.** Not a code bug — product-level geo enrichment failure.

**Outcome:** Metrics snapshot + root cause summary of sparsity.

---

## Priority 3: Precision Restoration Plan

**Goal:** Define the path back to precision-based geo eligibility (not a same-day change).

**Tasks:**
- [ ] Define minimum thresholds for `precision_type` coverage before re-enabling strict gate
- [ ] Identify data fixes required: which rows need backfill, which pipeline stages need fixing
- [ ] Document the go/no-go criteria for restoring `precision_type IN (...)` in nearby/filter queries
- [ ] Set a target date or metric milestone for restoration

**Outcome:** Decision doc: when and how to restore strict precision gating.

---

## Priority 4: Production Branch Normalization

**Goal:** Stop serving prod from `workspace-d16-d20-geo-fix`. Serve from `main`.

**Tasks:**
- [ ] Document the cutover plan to `main` (branch switch + hard reset + cache clear)
- [ ] Ensure rollback procedure is documented
- [ ] Prune leftover branches (`workspace-d16-d20-clean`, etc.) only after stable on `main`
- [ ] Update deploy documentation to reflect `main` as deploy target

**Outcome:** Deploy/branch normalization checklist.

---

## Priority 5: Admin/Pipeline Boundary Hardening

**Goal:** Reduce manual edits to pipeline-owned fields.

**Tasks:**
- [ ] Review which fields are admin-editable vs pipeline-owned in current model
- [ ] Decide whether `precision_type` should remain human-editable
- [ ] Lock or document fields that should not be manually overwritten
- [ ] Identify which pipeline stages have explicit fail-safe vs silent fallback behavior

**Outcome:** Short backlog of non-urgent hardening tasks.

---

## Execution Order

1. Queue worker reality check
2. Geo data audit
3. Precision restoration plan
4. Branch normalization
5. Admin boundary hardening

---

## Deliverables for Phase 2

| Deliverable | Description |
|------------|-------------|
| Queue worker status note | Worker state, backlog size, decision |
| Geo quality metrics snapshot | Row counts, sparsity root cause |
| Precision restoration decision doc | Thresholds, backfill plan, go/no-go criteria |
| Deploy/branch normalization checklist | Step-by-step cutover to `main` |
| Hardening backlog | Non-urgent field-protection tasks |

---

## Phase 1 Summary (what was done)

- D11–D20 architecture audit completed
- Geo precision bug fixed (`precision_type` → `relevance_mode` proxy rule)
- Cache-aside implementation (D16–D18) deployed
- Explicit invalidation jobs (D17) dispatched but queue worker status unknown
- Source-of-truth repaired: workspace → GitHub → server clean chain
- Temporary geo policy documented (not tribal knowledge)
- Server: clean worktree on `workspace-d16-d20-geo-fix` at `f02854b`
