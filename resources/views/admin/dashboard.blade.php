@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #eef2f5; font-family: Inter, system-ui, sans-serif; padding: 12px; color: #0a2a3b; min-height: 100vh; }
body.modal-open { overflow: hidden; position: fixed; width: 100%; height: 100%; }
.dashboard { max-width: 1800px; margin: 0 auto; background: #ffffff; border-radius: 28px; box-shadow: 0 20px 40px -12px rgba(0,0,0,0.12); min-height: calc(100vh - 24px); display: flex; flex-direction: column; overflow: hidden; }
.tab-navigation { display: flex; flex-wrap: wrap; padding: 0 20px; background: #fff; border-bottom: 1px solid #e6edf4; justify-content: space-between; align-items: center; gap: 12px; flex-shrink: 0; min-height: 60px; }
.tabs-left { display: flex; gap: 8px; flex-wrap: wrap; }
.admin-settings-btn { background: linear-gradient(135deg, #1c5a7f, #2c8cb0); border: none; border-radius: 40px; padding: 8px 20px; font-weight: 600; font-size: 0.85rem; cursor: pointer; color: white; }
.tab-btn { background: transparent; border: none; padding: 12px 20px; font-weight: 600; font-size: 0.95rem; color: #5f7f9a; cursor: pointer; transition: 0.2s; }
.tab-btn.active { color: #1c5a7f; border-bottom: 3px solid #1c5a7f; }
.tab-content { display: none; flex: 1; overflow-y: auto; }
.tab-content.active { display: flex; flex-direction: column; }
.header-section { padding: 20px 24px 12px 24px; flex-shrink: 0; }
.title-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
h1 { font-size: 1.5rem; font-weight: 700; color: #1c5a7f; }
.filter-bar, .filter-row { display: flex; flex-wrap: wrap; gap: 10px; background: #f8fafc; padding: 12px 20px; border-radius: 60px; margin: 8px 0 12px 0; align-items: flex-end; }
.filter-input, select.filter-input { background: white; border: 1px solid #d4e2ef; border-radius: 40px; padding: 8px 18px; font-size: 0.85rem; flex: 1 1 auto; min-width: 130px; }
.btn { border: none; font-weight: 600; padding: 8px 20px; border-radius: 40px; cursor: pointer; background: #f0f4f9; color: #1f5679; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; }
.btn-primary { background: #1c5a7f; color: white; }
.btn-danger { background: #fff3f0; color: #bc4e2c; border: 1px solid #f0cfc0; }
.table-wrapper { overflow-x: auto; margin: 8px 20px 20px 20px; border-radius: 20px; border: 1px solid #eef2f8; background: white; }
.news-table, .subscriber-table, .intel-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 800px; }
.news-table th, .news-table td, .subscriber-table th, .subscriber-table td, .intel-table th, .intel-table td { padding: 14px 10px; border-bottom: 1px solid #eff3f9; text-align: center; vertical-align: middle; }
.group-stats-container { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; background: #f8fafc; padding: 12px 20px; border-radius: 28px; }
.stat-card { background: white; border-radius: 24px; padding: 10px 20px; min-width: 120px; flex: 1 0 auto; text-align: center; border: 1px solid #e2edf6; }
.stat-card.total-card { background: #1c5a7f; }
.stat-card.total-card h4 { color: rgba(255,255,255,0.8); }
.stat-card.total-card .count { color: white; }
.stat-card h4 { font-size: 0.75rem; color: #5f7f9a; margin-bottom: 6px; text-transform: uppercase; }
.stat-card .count { font-size: 1.6rem; font-weight: 800; color: #1c5a7f; }
.summary-preview { max-width: 280px; text-align: left; }
.category-badge, .subcat-badge, .interest-badge, .location-badge { background: #e9f0f6; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; display: inline-block; }
.status-badge { display: inline-block; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
.status-active { background: #e0f5e9; color: #1f7840; }
.status-inactive { background: #ffe6e2; color: #bc4e2c; }
.click-count-badge { background: #fef5e8; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 700; color: #e67e22; }
.mode-badge, .precision-badge { display: inline-block; background: #f0f4f9; color: #36566d; padding: 4px 10px; border-radius: 30px; font-size: 0.7rem; }
.pagination-area { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; padding: 16px 24px; border-top: 1px solid #edf2f7; }
.page-btn { background: white; border: 1px solid #d4e2ef; padding: 6px 14px; border-radius: 30px; cursor: pointer; margin: 0 3px; }
.page-btn.active { background: #1c5a7f; color: white; }

.intel-dashboard { padding: 24px; background: #f8fafc; min-height: 100%; }
.intel-block { background: white; border-radius: 24px; padding: 24px; margin-bottom: 24px; border: 1px solid #e2edf6; }
.intel-block h3 { font-size: 1.05rem; margin-bottom: 18px; color: #1c5a7f; border-left: 4px solid #1c5a7f; padding-left: 14px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 28px; }
.intel-stat-card { background: white; border-radius: 24px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); border: 1px solid #e2edf6; }
.intel-stat-card h4 { font-size: 0.78rem; color: #5f7f9a; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.8px; }
.intel-stat-card .big-number { font-size: 2.1rem; font-weight: 800; color: #1c5a7f; }
.intel-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.chart-wrap { height: 300px; }
.intel-note { margin-top: 10px; color: #6b8498; font-size: 0.78rem; }
.intel-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.intel-toolbar-left, .intel-toolbar-right { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.intel-chip { display: inline-flex; align-items: center; gap: 8px; background: white; border: 1px solid #d4e2ef; border-radius: 999px; padding: 8px 14px; font-size: 0.82rem; color: #35586f; }
.intel-chip strong { color: #1c5a7f; }
.intel-block-header { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.intel-block-header h3 { margin-bottom:0; }
.intel-meta { color:#6b8498; font-size:0.78rem; }
.intel-detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
.intel-mini-note { margin-top: 10px; color:#6b8498; font-size:0.78rem; }
.intel-summary-bar { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
.intel-clickable-row tr, .clickable-row { cursor:pointer; }
.leaflet-map-wrap { border: 1px solid #d9e6f2; border-radius: 24px; overflow: hidden; background: #f7fbff; }
#newsMap { height: 260px; width: 100%; }
.coords-box { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; background:#f7fafc; border:1px solid #d9e6f2; border-radius:18px; padding:10px 14px; }
.coords-box span { font-weight:600; color:#1c5a7f; }
.form-inline-two { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.detail-modal-body { padding: 20px 24px 24px; overflow-y: auto; }
.detail-toolbar { display:flex; flex-wrap:wrap; gap:10px; justify-content:space-between; align-items:center; margin-bottom:16px; }
.detail-toolbar .left, .detail-toolbar .right { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.modal-card.detail-card { max-width: none; width: min(1600px, 98vw); height: 92vh; max-height: 92vh; padding: 0; }
.detail-modal-body { display:flex; flex-direction:column; min-height:0; }
#intelDetailTableWrap { flex:1; min-height:0; }
#intelDetailTableWrap .table-wrapper { height:100%; }
#intelDetailTableWrap .intel-table { min-width: 980px; }
.detail-filter-multi { min-width: 220px; min-height: 120px; border-radius: 18px; padding: 10px 12px; border: 1px solid #d4e2ef; background: white; }
.detail-advanced-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:12px; }
.detail-advanced-filters .filter-stack { display:flex; flex-direction:column; gap:6px; min-width:180px; }
.detail-advanced-filters .filter-stack label { font-size:0.75rem; color:#5f7f9a; font-weight:700; text-transform:uppercase; }
#subPrimarySelect { height: 44px; min-height: 44px; max-height: 44px; line-height: 1.2; padding-top: 0; padding-bottom: 0; display: block; }
.detail-kpis { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.detail-kpi { background:#f8fbfe; border:1px solid #e2edf6; border-radius:18px; padding:10px 14px; min-width:140px; }
.detail-kpi .label { display:block; font-size:0.72rem; color:#6b8498; margin-bottom:4px; text-transform:uppercase; }
.detail-kpi .value { font-size:1.2rem; font-weight:800; color:#1c5a7f; }

.modal-overlay { position: fixed; inset: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; z-index: 2000; visibility: hidden; opacity: 0; pointer-events: none; transition: 0.25s; padding: 20px; }
.modal-overlay.active { visibility: visible; opacity: 1; pointer-events: auto; }
.modal-card { background: white; border-radius: 32px; width: 100%; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
.modal-card.full-height { height: 90vh; max-height: 90vh; width: 95%; max-width: 1400px; }
.modal-card:not(.full-height) { max-width: 620px; max-height: 85vh; overflow-y: auto; padding: 24px; }
.settings-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 28px 16px 28px; border-bottom: 2px solid #eef2f8; flex-shrink: 0; background: white; }
.settings-header h2 { font-size: 1.6rem; font-weight: 700; color: #1c5a7f; }
.close-settings-btn { background: #f0f4f9; border: none; width: 44px; height: 44px; border-radius: 44px; font-size: 1.3rem; cursor: pointer; }
.settings-tabs { display: flex; gap: 12px; padding: 16px 28px; background: #f9fbfd; border-bottom: 1px solid #eef2f8; flex-shrink: 0; flex-wrap: wrap; }
.settings-tab-btn { background: transparent; border: none; padding: 10px 28px; font-weight: 600; font-size: 0.9rem; cursor: pointer; border-radius: 60px; color: #5f7f9a; }
.settings-tab-btn.active { background: #1c5a7f; color: white; }
.settings-panel-wrapper { flex: 1; overflow-y: auto; padding: 24px 28px; min-height: 0; }
.settings-panel { display: none; height: 100%; }
.settings-panel.active-panel { display: block; height: 100%; }
.category-grid { display: flex; gap: 28px; flex-wrap: wrap; height: 100%; min-height: 450px; }
.primary-panel, .sub-panel { flex: 1; min-width: 320px; background: #ffffff; border-radius: 28px; border: 1px solid #e6edf4; padding: 20px; display: flex; flex-direction: column; height: 100%; }
.primary-panel h4, .sub-panel h4 { font-size: 1.1rem; font-weight: 700; color: #1c5a7f; margin-bottom: 16px; flex-shrink: 0; }
.cat-list, .wa-group-list { flex: 1; overflow-y: auto; margin: 12px 0; min-height: 280px; background: #fafcff; border-radius: 20px; padding: 8px; }
.cat-item, .sub-item, .wa-group-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: white; border-radius: 60px; margin-bottom: 8px; border: 1px solid #eef3f9; cursor: pointer; }
.cat-item.selected, .sub-item.selected { border-color: #1c5a7f; background: #eef6fb; }
.system-badge { display: inline-block; padding: 2px 10px; border-radius: 30px; font-size: 0.6rem; background: #edf2f7; color: #5f7f9a; margin-left: 10px; }
.inline-add { display: flex; gap: 12px; margin-top: 16px; flex-shrink: 0; flex-wrap: wrap; }
.inline-add input { flex: 2; min-width: 160px; padding: 10px 16px; border-radius: 60px; border: 1px solid #cfdfed; font-size: 0.85rem; }
.danger-icon-btn { background: none; border: none; cursor: pointer; font-size: 1rem; padding: 6px 14px; border-radius: 30px; color: #c26a5a; }
.form-group { margin-bottom: 1rem; }
.form-group label { font-weight: 600; display: block; margin-bottom: 6px; font-size: 0.85rem; color: #1d4e6e; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 16px; border: 1px solid #cfdfed; border-radius: 28px; font-size: 0.9rem; }
textarea { border-radius: 18px !important; resize: vertical; }
.form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top: 6px; }

@media (max-width: 980px) { .intel-grid-2 { grid-template-columns: 1fr; } }
@media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .category-grid { flex-direction: column; } .primary-panel, .sub-panel { min-width: 100%; } .table-wrapper { margin-left: 12px; margin-right: 12px; } .header-section { padding-left: 16px; padding-right: 16px; } }
</style>
@endpush

@section('content')
<div class="dashboard">
  <div class="tab-navigation">
    <div class="tabs-left">
      <button class="tab-btn active" data-tab="news">📰 News</button>
      <button class="tab-btn" data-tab="subscriber">👥 Subscribers</button>
      <button class="tab-btn" data-tab="intel">📊 Intel Analytics</button>
    </div>
    <button id="adminSettingsBtn" class="admin-settings-btn">⚙️ Settings</button>
    <button id="logoutBtn" class="admin-settings-btn" style="background:#bc4e2c;">🚪 Logout</button>
  </div>

  {{-- NEWS TAB --}}
  <div id="newsTab" class="tab-content active">
    <div class="header-section">
      <div class="title-row"><h1>📰 News Management</h1><button id="addRowBtn" class="btn btn-primary">➕ Add News</button></div>
      <div class="filter-bar">
        <select id="primaryCategoryFilter" class="filter-input"><option value="all">All Categories</option></select>
        <select id="subCategoryFilter" class="filter-input"><option value="all">All Sub-Categories</option></select>
        <button id="clearNewsFilterBtn" class="btn">Clear</button>
      </div>
    </div>
    <div class="table-wrapper">
      <table class="news-table">
        <thead><tr><th>Date</th><th>Headline</th><th>Summary</th><th>Primary</th><th>Sub</th><th>Status</th><th>Mode</th><th>Precision</th><th>Location</th><th>Source</th><th>Clicks</th></tr></thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
    <div class="pagination-area"><div id="newsPaginationControls"></div><div id="newsPaginationInfo"></div></div>
  </div>

  {{-- SUBSCRIBERS TAB --}}
  <div id="subscriberTab" class="tab-content">
    <div class="header-section">
      <div class="title-row"><h1>👥 Subscribers</h1><button id="addSubscriberBtn" class="btn btn-primary">➕ Add</button></div>
      <div id="groupStatsContainer" class="group-stats-container"></div>
      <div class="filter-row">
        <input type="text" id="filterUserCode" class="filter-input" placeholder="User Code">
        <input type="text" id="filterMobile" class="filter-input" placeholder="Mobile">
        <select id="filterWAGroup" class="filter-input"><option value="all">All Groups</option></select>
        <select id="filterInterestSub" class="filter-input"><option value="all">All Interests</option></select>
        <select id="filterStatus" class="filter-input"><option value="all">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        <button id="clearSubFiltersBtn" class="btn">Clear</button>
      </div>
    </div>
    <div class="table-wrapper">
      <table class="subscriber-table">
        <thead><tr><th>Join Date</th><th>User Code</th><th>Mobile</th><th>WA Group</th><th>Interest</th><th>Status</th><th>Location</th></tr></thead>
        <tbody id="subscriberBody"></tbody>
      </table>
    </div>
    <div class="pagination-area"><div id="subPaginationControls"></div><div id="subPaginationInfo"></div></div>
  </div>

  {{-- INTEL TAB --}}
  <div id="intelTab" class="tab-content">
    <div class="intel-dashboard">
      <div class="intel-toolbar">
        <div class="intel-toolbar-left">
          <div class="intel-chip"><strong>Time Period</strong></div>
          <select id="intelRangePreset" class="filter-input" style="min-width:170px;">
            <option value="24h">Last 24 Hours</option><option value="7d" selected>Last 7 Days</option>
            <option value="30d">Last 30 Days</option><option value="6m">Last 6 Months</option>
            <option value="1y">Last 1 Year</option><option value="all">All Time</option>
            <option value="custom">Custom Range</option>
          </select>
          <input type="text" id="intelCustomRange" class="filter-input" placeholder="Select custom range" style="display:none; min-width:220px;">
          <select id="intelGranularity" class="filter-input" style="min-width:160px;">
            <option value="auto" selected>Time Analysis: Auto</option>
            <option value="hour">Time Analysis: By Hour</option><option value="day">Time Analysis: By Day</option>
            <option value="month">Time Analysis: By Month</option>
          </select>
        </div>
        <div class="intel-toolbar-right">
          <div id="intelRangeSummary" class="intel-chip"><strong>Loading...</strong></div>
          <button id="intelResetRangeBtn" class="btn">Reset</button>
        </div>
      </div>
      <div class="stats-grid" id="intelStatsGrid"></div>
      <div class="intel-grid-2">
        <div class="intel-block">
          <div class="intel-block-header"><h3>📍 Location Analytics</h3><div class="intel-detail-actions"><button class="btn" data-intel-detail="locations">View Details</button></div></div>
          <div id="locationTableContainer"></div>
          <div class="intel-note">Use the detail popup for deeper breakdown.</div>
        </div>
        <div class="intel-block">
          <div class="intel-block-header"><h3>🏷️ Category Distribution</h3><div class="intel-detail-actions"><button class="btn" data-intel-detail="categories">View Full Distribution</button></div></div>
          <div id="categoryTableContainer"></div>
          <div class="intel-note">Full breakdown lives in the popup.</div>
        </div>
      </div>
      <div class="intel-grid-2">
        <div class="intel-block">
          <div class="intel-block-header"><h3>🖱️ Top News</h3><div class="intel-detail-actions"><button class="btn" data-intel-detail="top_news">View All Top News</button></div></div>
          <div id="topNewsTableContainer"></div>
        </div>
        <div class="intel-block">
          <div class="intel-block-header"><h3>⏱️ Time Analysis</h3><div class="intel-detail-actions"><button class="btn" data-intel-detail="activity">Open Time Detail</button></div></div>
          <div class="intel-summary-bar" id="intelTimeMeta"></div>
          <div class="chart-wrap"><canvas id="activityChart"></canvas></div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- INTEL DETAIL MODAL --}}
<div id="intelDetailModal" class="modal-overlay">
  <div class="modal-card detail-card">
    <div class="settings-header"><h2 id="intelDetailTitle">Intel Details</h2><button id="closeIntelDetailBtn" class="close-settings-btn">✕</button></div>
    <div class="detail-modal-body">
      <div class="detail-toolbar">
        <div class="left"><input type="text" id="intelDetailSearch" class="filter-input" placeholder="Search within details" style="min-width:280px;"></div>
        <div class="right"><div id="intelDetailSummary" class="intel-chip"><strong>0 rows</strong></div></div>
      </div>
      <div class="detail-advanced-filters">
        <div class="filter-stack"><label>Sort By</label>
          <select id="intelDetailSort" class="filter-input">
            <option value="clicks_desc">Clicks: High to Low</option><option value="clicks_asc">Clicks: Low to High</option>
            <option value="title_asc">Title: A to Z</option><option value="title_desc">Title: Z to A</option>
          </select>
        </div>
        <div class="filter-stack"><label>Minimum Clicks</label><input type="number" id="intelDetailMinClicks" class="filter-input" placeholder="0" min="0"></div>
      </div>
      <div class="detail-kpis" id="intelDetailKpis"></div>
      <div id="intelDetailTableWrap"></div>
    </div>
  </div>
</div>

{{-- SETTINGS MODAL --}}
<div id="adminModal" class="modal-overlay">
  <div class="modal-card full-height">
    <div class="settings-header"><h2>⚙️ System Configuration</h2><button id="closeAdminBtn" class="close-settings-btn">✕</button></div>
    <div class="settings-tabs">
      <button class="settings-tab-btn active" data-settings-tab="categories">📂 Categories</button>
      <button class="settings-tab-btn" data-settings-tab="wagroups">💬 WhatsApp Groups</button>
    </div>
    <div class="settings-panel-wrapper">
      <div id="settingsCategories" class="settings-panel active-panel">
        <div class="category-grid">
          <div class="primary-panel">
            <h4>📁 Primary Categories</h4>
            <div id="primaryCatList" class="cat-list"></div>
            <div class="inline-add">
              <input type="text" id="newPrimaryCat" placeholder="New category">
              <button id="addPrimaryCatBtn" class="btn btn-primary">+ Add</button>
              <button id="deletePrimaryCatBtn" class="btn btn-danger">Delete Selected</button>
            </div>
          </div>
          <div class="sub-panel">
            <h4>🔖 Sub-Categories <span id="selectedPrimaryName" style="font-size:0.7rem;"></span></h4>
            <select id="subPrimarySelect" class="filter-input" style="width:100%;"></select>
            <div id="subCatList" class="cat-list"></div>
            <div class="inline-add">
              <input type="text" id="newSubCat" placeholder="New sub-category">
              <button id="addSubCatBtn" class="btn btn-primary">+ Add</button>
              <button id="deleteSubCatBtn" class="btn btn-danger">Delete Selected</button>
            </div>
          </div>
        </div>
      </div>
      <div id="settingsWAGroups" class="settings-panel">
        <h4>💬 WhatsApp Broadcast Groups</h4>
        <div id="waGroupList" class="wa-group-list" style="flex:1; overflow-y:auto; margin:16px 0;"></div>
        <div class="inline-add">
          <input type="text" id="newWAGroupName" placeholder="New group name">
          <button id="addWAGroupBtn" class="btn btn-primary">+ Add Group</button>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- NEWS MODAL --}}
<div id="newsModal" class="modal-overlay">
  <div class="modal-card">
    <h3 id="newsModalTitle" style="font-size:1.3rem;font-weight:700;color:#1c5a7f;margin-bottom:16px;">Add News</h3>
    <input type="hidden" id="editNewsId">
    <div class="form-group"><label>Date & Time</label><input type="text" id="newsDatetime"></div>
    <div class="form-group"><label>Headline</label><textarea id="newsHeadline" rows="2"></textarea></div>
    <div class="form-group"><label>Summary</label><textarea id="newsSummary" rows="2"></textarea></div>
    <div class="form-group"><label>Primary Category</label><select id="newsPrimaryCat"></select></div>
    <div class="form-group"><label>Sub-Category</label><select id="newsSubCat"></select></div>
    <div class="form-group"><label>Status</label><select id="newsStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="form-group"><label>Relevance Mode</label><select id="newsRelevanceMode"><option value="hybrid">Hybrid</option><option value="location_only">Location Only</option><option value="category_only">Category Only</option></select></div>
    <div class="form-group"><label>Precision Type</label><select id="newsPrecisionType"><option value="exact_area">Exact Area</option><option value="approximate_area">Approximate Area</option><option value="state_center">State Center</option><option value="region">Region</option><option value="country">Country</option><option value="national">National</option><option value="unresolved">Unresolved</option></select></div>
    <div class="form-group"><label>Location Name</label><input type="text" id="newsMainPlaceText"></div>
    <div class="form-group"><label>OpenStreetMap Pin</label><div class="leaflet-map-wrap"><div id="newsMap"></div></div></div>
    <div class="form-group"><label>Coordinates</label><div class="coords-box"><span id="coordDisplay">3.13900, 101.68690</span><button type="button" id="resetMapPin" class="btn">Reset</button></div></div>
    <div class="form-inline-two">
      <div class="form-group"><label>Latitude</label><input type="number" step="0.000001" id="newsLat"></div>
      <div class="form-group"><label>Longitude</label><input type="number" step="0.000001" id="newsLng"></div>
    </div>
    <div class="form-group"><label>Source Name</label><input type="text" id="newsSourceName"></div>
    <div class="form-group"><label>Source URL</label><input type="url" id="newsSource"></div>
    <div class="form-actions"><button id="cancelNewsBtn" class="btn">Cancel</button><button id="saveNewsBtn" class="btn btn-primary">Save</button></div>
    <div id="deleteNewsSection" style="display:none; margin-top: 10px;"><button id="deleteNewsBtn" class="btn btn-danger">Delete</button></div>
  </div>
</div>

{{-- SUBSCRIBER MODAL --}}
<div id="subscriberModal" class="modal-overlay">
  <div class="modal-card">
    <h3 id="subModalTitle" style="font-size:1.3rem;font-weight:700;color:#1c5a7f;margin-bottom:16px;">Subscriber</h3>
    <input type="hidden" id="editSubId">
    <div class="form-group"><label>Join Date</label><input type="text" id="subJoinDate"></div>
    <div class="form-group"><label>User Code</label><input type="text" id="subUserCode"></div>
    <div class="form-group"><label>Mobile</label><input type="text" id="subMobile"></div>
    <div class="form-group"><label>WA Group</label><select id="subWAGroup"></select></div>
    <div class="form-group"><label>Interest</label><select id="subInterestCat"></select></div>
    <div class="form-group"><label>Status</label><select id="subStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="form-group"><label>Location</label><input type="text" id="subLocationName"></div>
    <div class="form-actions"><button id="cancelSubBtn" class="btn">Cancel</button><button id="saveSubBtn" class="btn btn-primary">Save</button></div>
    <div id="deleteSubSection" style="display:none; margin-top: 10px;"><button id="deleteSubBtn" class="btn btn-danger">Delete</button></div>
  </div>
</div>
@endsection

