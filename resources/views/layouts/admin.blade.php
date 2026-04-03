{{-- resources/views/layouts/admin.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'ProHub Admin')</title>

  <link rel="stylesheet" href="{{ asset('css/app.css') }}">
  <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
  <link rel="stylesheet" href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}">
  <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
  @stack('styles')
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #eef2f5; font-family: Inter, system-ui, sans-serif; padding: 12px; color: #0a2a3b; min-height: 100vh; }
    body.modal-open { overflow: hidden; position: fixed; width: 100%; height: 100%; }
    .dashboard { max-width: 1800px; margin: 0 auto; background: #ffffff; border-radius: 28px; box-shadow: 0 20px 40px -12px rgba(0,0,0,0.12); min-height: calc(100vh - 24px); display: flex; flex-direction: column; overflow: hidden; }

    /* Tab Navigation */
    .tab-navigation { display: flex; flex-wrap: wrap; padding: 0 20px; background: #fff; border-bottom: 1px solid #e6edf4; justify-content: space-between; align-items: center; gap: 12px; flex-shrink: 0; min-height: 60px; }
    .tabs-left { display: flex; gap: 8px; flex-wrap: wrap; }
    .admin-settings-btn { background: linear-gradient(135deg, #1c5a7f, #2c8cb0); border: none; border-radius: 40px; padding: 8px 20px; font-weight: 600; font-size: 0.85rem; cursor: pointer; color: white; }
    .tab-btn { background: transparent; border: none; padding: 12px 20px; font-weight: 600; font-size: 0.95rem; color: #5f7f9a; cursor: pointer; transition: 0.2s; }
    .tab-btn.active { color: #1c5a7f; border-bottom: 3px solid #1c5a7f; }

    /* Tab Content */
    .tab-content { display: none; flex: 1; overflow-y: auto; }
    .tab-content.active { display: flex; flex-direction: column; }

    /* Header Section */
    .header-section { padding: 20px 24px 12px 24px; flex-shrink: 0; }
    .title-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    h1 { font-size: 1.5rem; font-weight: 700; color: #1c5a7f; }

    /* Filters */
    .filter-bar, .filter-row { display: flex; flex-wrap: wrap; gap: 10px; background: #f8fafc; padding: 12px 20px; border-radius: 60px; margin: 8px 0 12px 0; align-items: flex-end; }
    .filter-input, select.filter-input { background: white; border: 1px solid #d4e2ef; border-radius: 40px; padding: 8px 18px; font-size: 0.85rem; flex: 1 1 auto; min-width: 130px; }
    .btn { border: none; font-weight: 600; padding: 8px 20px; border-radius: 40px; cursor: pointer; background: #f0f4f9; color: #1f5679; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; }
    .btn-primary { background: #1c5a7f; color: white; }
    .btn-danger { background: #fff3f0; color: #bc4e2c; border: 1px solid #f0cfc0; }

    /* Tables */
    .table-wrapper { overflow-x: auto; margin: 8px 20px 20px 20px; border-radius: 20px; border: 1px solid #eef2f8; background: white; }
    .news-table, .subscriber-table, .intel-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 800px; }
    .news-table th, .news-table td, .subscriber-table th, .subscriber-table td, .intel-table th, .intel-table td { padding: 14px 10px; border-bottom: 1px solid #eff3f9; text-align: center; vertical-align: middle; }

    /* Stats Cards */
    .group-stats-container { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; background: #f8fafc; padding: 12px 20px; border-radius: 28px; }
    .stat-card { background: white; border-radius: 24px; padding: 10px 20px; min-width: 120px; flex: 1 0 auto; text-align: center; border: 1px solid #e2edf6; }
    .stat-card.total-card { background: #1c5a7f; }
    .stat-card.total-card h4 { color: rgba(255,255,255,0.8); }
    .stat-card.total-card .count { color: white; }
    .stat-card h4 { font-size: 0.75rem; color: #5f7f9a; margin-bottom: 6px; text-transform: uppercase; }
    .stat-card .count { font-size: 1.6rem; font-weight: 800; color: #1c5a7f; }

    /* Badges */
    .category-badge, .subcat-badge, .interest-badge, .location-badge { background: #e9f0f6; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; display: inline-block; }
    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
    .status-active { background: #e0f5e9; color: #1f7840; }
    .status-inactive { background: #ffe6e2; color: #bc4e2c; }
    .click-count-badge { background: #fef5e8; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 700; color: #e67e22; }
    .mode-badge, .precision-badge { display: inline-block; background: #f0f4f9; color: #36566d; padding: 4px 10px; border-radius: 30px; font-size: 0.7rem; }
    .pagination-area { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; padding: 16px 24px; border-top: 1px solid #edf2f7; }
    .page-btn { background: white; border: 1px solid #d4e2ef; padding: 6px 14px; border-radius: 30px; cursor: pointer; margin: 0 3px; }
    .page-btn.active { background: #1c5a7f; color: white; }

    /* Intel Dashboard */
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
    .empty-state { padding: 24px; text-align: center; color: #6b8498; }
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
    .detail-kpis { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
    .detail-kpi { background:#f8fbfe; border:1px solid #e2edf6; border-radius:18px; padding:10px 14px; min-width:140px; }
    .detail-kpi .label { display:block; font-size:0.72rem; color:#6b8498; margin-bottom:4px; text-transform:uppercase; }
    .detail-kpi .value { font-size:1.2rem; font-weight:800; color:#1c5a7f; }

    /* Modals */
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
    .form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top: 6px; }

    /* Leaflet Map */
    .leaflet-map-wrap { border: 1px solid #d9e6f2; border-radius: 24px; overflow: hidden; background: #f7fbff; }
    #newsMap { height: 260px; width: 100%; }

    /* Summary preview */
    .summary-preview { max-width: 280px; text-align: left; }

    @media (max-width: 980px) { .intel-grid-2 { grid-template-columns: 1fr; } }
    @media (max-width: 768px) {
      .stats-grid { grid-template-columns: 1fr; }
      .category-grid { flex-direction: column; }
      .primary-panel, .sub-panel { min-width: 100%; }
      .table-wrapper { margin-left: 12px; margin-right: 12px; }
      .header-section { padding-left: 16px; padding-right: 16px; }
    }
  </style>
</head>
<body>
<div class="dashboard">
  @section('tab-navigation')
  <div class="tab-navigation">
    <div class="tabs-left">
      <button class="tab-btn active" data-tab="news">📰 News</button>
      <button class="tab-btn" data-tab="subscriber">👥 Subscribers</button>
      <button class="tab-btn" data-tab="intel">📊 Intel Analytics</button>
    </div>
    <button id="adminSettingsBtn" class="admin-settings-btn">⚙️ Settings</button>
    <button id="logoutBtn" class="admin-settings-btn" style="background:#bc4e2c;">🚪 Logout</button>
  </div>
  @show

  @yield('tab-content')
</div>

{{-- Modals --}}
@yield('modals')

<script src="{{ asset('vendor/flatpickr/flatpickr.js') }}"></script>
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
@stack('scripts')
</body>
</html>
