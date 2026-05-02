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

## Priority 2: Geo Data Quality Audit

**Goal:** Understand why `precision_type` is sparse and why nearby relies on `relevance_mode` as proxy.

**Tasks:**
- [ ] Measure row counts for `precision_type`, `coverage_type`, `latitude/longitude`, `relevance_mode` across `feed_ready_items`
- [ ] Sample rows with good vs missing geo metadata
- [ ] Trace which pipeline stage should populate `precision_type` and identify the gap
- [ ] Identify what percentage of rows would pass a strict precision gate

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
