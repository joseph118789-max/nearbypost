/**
 * admin.js - Laravel-API-powered admin panel JS
 * Replaces localStorage logic from admin.html with fetch() calls
 */

const REQUIRED_PRIMARIES = ["property","transport","crime","sports","business","government","education","health","lifestyle","community","environment","technology","entertainment","jobs","others"];

let primaryCategories = [...REQUIRED_PRIMARIES];
let subCategoriesMap = {};
let waBroadcastGroups = ["Main Group", "Premium Alerts", "Regional News", "VIP Updates"];

let allNews = [];
let allSubscribers = [];
let newsCurrentPage = 1, newsPerPage = 10, newsFilterPrimary = "all", newsFilterSub = "all";
let subCurrentPage = 1, subPerPage = 10;
let subFilters = { userCode: "", mobile: "", waGroup: "all", interestSub: "all", status: "all" };
let selectedPrimaryCategory = "property", selectedSubCategory = null;
let currentNewsId = null;
let activityChart = null;
let intelDetailState = { type: "", rows: [] };
let newsMap = null, newsMarker = null;
const DEFAULT_MAP_COORDS = { lat: 3.1390, lng: 101.6869 };

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API_BASE = '/admin';

const esc = str => (str == null ? "" : String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])));
const fmt = d => { const x = new Date(d); return isNaN(x) ? "" : `${x.getMonth()+1}/${x.getDate()}/${x.getFullYear()} ${String(x.getHours()).padStart(2,'0')}:${String(x.getMinutes()).padStart(2,'0')}`; };
const parseUiDateTime = v => { const d = new Date(v); return isNaN(d) ? new Date() : d; };

async function api(method, url, body = null) {
    const opts = { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken } };
    if (body) opts.body = JSON.stringify(body);
    const r = await fetch(url, opts);
    if (!r.ok) {
        const err = await r.json().catch(() => ({}));
        throw new Error(err.message || `HTTP ${r.status}`);
    }
    return r.json();
}

// --- Settings ---
async function loadSettings() {
    try {
        const [cats, groups] = await Promise.all([
            api('GET', `${API_BASE}/settings/categories`),
            api('GET', `${API_BASE}/settings/wa-groups`),
        ]);
        primaryCategories = [...new Set([...REQUIRED_PRIMARIES, ...(cats.primary_categories || [])])];
        subCategoriesMap = { ...cats.sub_categories_map || {} };
        waBroadcastGroups = groups.wa_groups || ["Main Group"];
    } catch (e) {
        console.warn('Settings load failed, using defaults', e);
        REQUIRED_PRIMARIES.forEach(p => { if (!subCategoriesMap[p]) subCategoriesMap[p] = [`${p}_news`]; });
    }
}

async function saveCategories() {
    await api('POST', `${API_BASE}/settings/categories`, {
        primary_categories: primaryCategories.filter(p => !REQUIRED_PRIMARIES.includes(p)),
        sub_categories_map: subCategoriesMap,
    });
}

async function saveWAGroups() {
    await api('POST', `${API_BASE}/settings/wa-groups`, { groups: waBroadcastGroups });
}

// --- News ---
async function loadNews(page = 1) {
    const params = new URLSearchParams({ page, per_page: newsPerPage });
    if (newsFilterPrimary !== 'all') params.set('primary_cat', newsFilterPrimary);
    if (newsFilterSub !== 'all') params.set('sub_cat', newsFilterSub);
    const data = await api('GET', `${API_BASE}/news?${params}`);
    allNews = data.data || [];
    return data;
}

async function saveNews(payload) {
    if (currentNewsId) {
        return api('PUT', `${API_BASE}/news/${currentNewsId}`, payload);
    } else {
        return api('POST', `${API_BASE}/news`, payload);
    }
}

async function deleteNews(id) {
    return api('DELETE', `${API_BASE}/news/${id}`);
}

// --- Subscribers ---
async function loadSubscribers(page = 1) {
    const params = new URLSearchParams({ page, per_page: subPerPage });
    if (subFilters.waGroup !== 'all') params.set('wa_group', subFilters.waGroup);
    if (subFilters.status !== 'all') params.set('status', subFilters.status);
    if (subFilters.userCode) params.set('user_code', subFilters.userCode);
    if (subFilters.mobile) params.set('mobile', subFilters.mobile);
    if (subFilters.interestSub !== 'all') params.set('interest_sub', subFilters.interestSub);
    const data = await api('GET', `${API_BASE}/subscribers?${params}`);
    allSubscribers = data.data || [];
    return data;
}

async function saveSubscriber(payload) {
    if (currentNewsId) {
        return api('PUT', `${API_BASE}/subscribers/${currentNewsId}`, payload);
    } else {
        return api('POST', `${API_BASE}/subscribers`, payload);
    }
}

async function deleteSubscriber(id) {
    return api('DELETE', `${API_BASE}/subscribers/${id}`);
}

// --- Intel ---
async function loadIntel(preset = '7d', granularity = 'auto') {
    const params = new URLSearchParams({ preset, granularity });
    return api('GET', `${API_BASE}/intel/analytics?${params}`);
}

// --- Render: News ---
function renderNewsTable(news, pagination) {
    const tbody = document.getElementById('tableBody');
    if (!tbody) return;
    tbody.innerHTML = (news || allNews).map(n => `
        <tr data-news-id="${n.id}">
            <td>${esc(fmt(n.published_at))}</td>
            <td><strong>${esc(n.title || n.headline || '')}</strong></td>
            <td class="summary-preview">${esc(n.summary || '—')}</td>
            <td><span class="category-badge">${esc(n.primary_category || '')}</span></td>
            <td><span class="subcat-badge">${esc(n.secondary_category || '')}</span></td>
            <td><span class="status-badge ${n.status === 'active' ? 'status-active' : 'status-inactive'}">${esc(n.status || 'inactive')}</span></td>
            <td><span class="mode-badge">${esc(n.relevance_mode || 'hybrid')}</span></td>
            <td><span class="precision-badge">${esc(n.precision_type || 'approximate_area')}</span></td>
            <td>${esc(n.location_label || n.main_place_text || '—')}</td>
            <td>${n.url || n.source ? '🔗' : '—'}</td>
            <td><span class="click-count-badge">${n.click_count || 0}</span></td>
        </tr>`).join('');

    document.querySelectorAll('#tableBody tr[data-news-id]').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', () => openNewsModal(parseInt(row.dataset.newsId, 10)));
    });

    renderPager('newsPaginationControls', 'newsPaginationInfo',
        pagination?.current_page || 1,
        pagination?.last_page || 1,
        pagination?.total || 0,
        newsPerPage,
        i => { newsCurrentPage = i; refreshNews(); }
    );
}

async function refreshNews() {
    try {
        const data = await loadNews(newsCurrentPage);
        renderNewsTable(data.data, data);
    } catch (e) { console.error('Load news failed', e); }
}

// --- Render: Subscribers ---
function renderSubscribersTable(subs, pagination) {
    const tbody = document.getElementById('subscriberBody');
    if (!tbody) return;
    tbody.innerHTML = (subs || allSubscribers).map(s => `
        <tr data-sub-id="${s.id}">
            <td>${esc(fmt(s.join_date))}</td>
            <td>${esc(s.user_code || '')}</td>
            <td>${esc(s.mobile || '')}</td>
            <td><span class="category-badge">${esc(s.wa_group || '')}</span></td>
            <td><span class="interest-badge">${esc(s.interest_sub_cat || 'None')}</span></td>
            <td><span class="status-badge ${s.status === 'active' ? 'status-active' : 'status-inactive'}">${esc(s.status || 'inactive')}</span></td>
            <td><span class="location-badge">📍 ${esc(s.location_name || 'Unknown')}</span></td>
        </tr>`).join('');

    document.querySelectorAll('#subscriberBody tr[data-sub-id]').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', () => openSubModal(parseInt(row.dataset.subId, 10)));
    });

    const activeCount = (subs || allSubscribers).filter(s => s.status === 'active').length;
    const stats = {};
    waBroadcastGroups.forEach(g => stats[g] = (subs || allSubscribers).filter(s => s.wa_group === g && s.status === 'active').length);
    let html = `<div class="stat-card total-card"><h4>Total Active</h4><div class="count">${activeCount}</div></div>`;
    waBroadcastGroups.forEach(g => html += `<div class="stat-card"><h4>${esc(g)}</h4><div class="count">${stats[g] || 0}</div></div>`);
    const container = document.getElementById('groupStatsContainer');
    if (container) container.innerHTML = html;

    renderPager('subPaginationControls', 'subPaginationInfo',
        pagination?.current_page || 1,
        pagination?.last_page || 1,
        pagination?.total || 0,
        subPerPage,
        i => { subCurrentPage = i; refreshSubscribers(); }
    );
}

async function refreshSubscribers() {
    try {
        const data = await loadSubscribers(subCurrentPage);
        renderSubscribersTable(data.data, data);
    } catch (e) { console.error('Load subscribers failed', e); }
}

// --- Render: Intel ---
async function renderIntelDashboard() {
    try {
        const preset = document.getElementById('intelRangePreset')?.value || '7d';
        const granularity = document.getElementById('intelGranularity')?.value || 'auto';
        const intel = await loadIntel(preset, granularity);
        const s = intel.summary;

        document.getElementById('intelRangeSummary').innerHTML = `<strong>${esc(intel.range.label)}</strong> · ${intel.detail.event_count} tracked clicks`;
        document.getElementById('intelStatsGrid').innerHTML = `
            <div class="intel-stat-card"><h4>Total Users</h4><div class="big-number">${s.total_users}</div></div>
            <div class="intel-stat-card"><h4>Active Today</h4><div class="big-number">${s.active_today}</div></div>
            <div class="intel-stat-card"><h4>Total Clicks</h4><div class="big-number">${s.total_clicks}</div></div>
            <div class="intel-stat-card"><h4>Top Category</h4><div class="big-number" style="font-size:1.5rem;">${esc(s.top_category)}</div></div>
            <div class="intel-stat-card"><h4>Peak ${intel.granularity === 'hour' ? 'Hour' : 'Day'}</h4><div class="big-number" style="font-size:1.15rem;">${esc(s.peak_hour)}</div></div>
        `;

        document.getElementById('locationTableContainer').innerHTML = renderIntelTable(
            ['Location','Users','Clicks','Top Category'],
            (intel.locations || []).slice(0,10).map(x => [
                `<span class="location-badge">📍 ${esc(x.location_name)}</span>`,
                x.user_count, `<span class="click-count-badge">${x.click_count} clicks</span>`,
                `<span class="category-badge">${esc(x.top_category)}</span>`
            ])
        );

        document.getElementById('categoryTableContainer').innerHTML = renderIntelTable(
            ['Primary','Secondary','Clicks'],
            (intel.categories || []).slice(0,10).map(x => [
                `<span class="category-badge">${esc(x.primary_category)}</span>`,
                x.secondary_category ? `<span class="subcat-badge">${esc(x.secondary_category)}</span>` : '—',
                `<span class="click-count-badge">${x.click_count} clicks</span>`
            ])
        );

        document.getElementById('topNewsTableContainer').innerHTML = renderIntelTable(
            ['News ID','Title','Primary','Clicks'],
            (intel.top_news || []).slice(0,10).map(x => [
                x.news_item_id, `<strong>${esc(x.title)}</strong>`,
                `<span class="category-badge">${esc(x.primary_category)}</span>`,
                `<span class="click-count-badge">${x.click_count} clicks</span>`
            ])
        );

        document.getElementById('intelTimeMeta').innerHTML = `
            <div class="intel-chip"><strong>Range</strong> ${esc(intel.range.label)}</div>
            <div class="intel-chip"><strong>Granularity</strong> ${esc(intel.granularity)}</div>
            <div class="intel-chip"><strong>Visible Buckets</strong> ${intel.activity?.length || 0}</div>
        `;

        const ctx = document.getElementById('activityChart');
        if (!ctx) return;
        if (activityChart) activityChart.destroy();
        activityChart = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: (intel.activity || []).map(x => x.label),
                datasets: [{ label: 'Clicks', data: (intel.activity || []).map(x => x.click_count), backgroundColor: '#1c5a7f', borderRadius: 8 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }, y: { beginAtZero: true } }
            }
        });
    } catch (e) { console.error('Intel load failed', e); }
}

function renderIntelTable(headers, rows) {
    if (!rows || !rows.length) return '<div class="empty-state">No analytics available for this time period.</div>';
    return `<div class="table-wrapper" style="margin:0; border:none;"><table class="intel-table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${(Array.isArray(r) ? r : Object.values(r)).map(c=>`<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}

function renderPager(controlId, infoId, current, pages, total, perPage, onClick) {
    const container = document.getElementById(controlId);
    if (!container) return;
    container.innerHTML = '';
    for (let i = 1; i <= Math.min(pages, 5); i++) {
        const btn = document.createElement('button');
        btn.textContent = i; btn.className = `page-btn ${i === current ? 'active' : ''}`;
        btn.onclick = () => onClick(i); container.appendChild(btn);
    }
    const info = document.getElementById(infoId);
    if (info) info.textContent = total === 0 ? '0 of 0' : `${Math.min(total, (current-1)*perPage + 1)}-${Math.min(current*perPage, total)} of ${total}`;
}

// --- Dropdowns ---
function updateAllDropdowns() {
    ensureCategoryIntegrity();
    const primFilter = document.getElementById('primaryCategoryFilter');
    if (primFilter) primFilter.innerHTML = '<option value="all">All Categories</option>' + primaryCategories.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');

    const allSubs = [...new Set(Object.values(subCategoriesMap).flat())];
    const subFilter = document.getElementById('subCategoryFilter');
    if (subFilter) subFilter.innerHTML = '<option value="all">All Sub-Categories</option>' + allSubs.map(s => `<option>${esc(s)}</option>`).join('');

    const interestFilter = document.getElementById('filterInterestSub');
    if (interestFilter) interestFilter.innerHTML = '<option value="all">All Interests</option>' + allSubs.map(s => `<option>${esc(s)}</option>`).join('');

    const waFilter = document.getElementById('filterWAGroup');
    if (waFilter) waFilter.innerHTML = '<option value="all">All Groups</option>' + waBroadcastGroups.map(g => `<option>${esc(g)}</option>`).join('');

    const newsPrim = document.getElementById('newsPrimaryCat');
    if (newsPrim) {
        newsPrim.innerHTML = primaryCategories.map(c => `<option>${esc(c)}</option>`).join('');
        newsPrim.onchange = () => {
            const subs = subCategoriesMap[newsPrim.value] || [];
            const newsSub = document.getElementById('newsSubCat');
            if (newsSub) { newsSub.innerHTML = subs.map(s => `<option>${esc(s)}</option>`).join(''); }
        };
    }

    const interestCat = document.getElementById('subInterestCat');
    if (interestCat) interestCat.innerHTML = '<option value="">-- None --</option>' + allSubs.map(s => `<option>${esc(s)}</option>`).join('');

    const waGroupSelect = document.getElementById('subWAGroup');
    if (waGroupSelect) waGroupSelect.innerHTML = waBroadcastGroups.map(g => `<option>${esc(g)}</option>`).join('');
}

function ensureCategoryIntegrity() {
    primaryCategories = [...new Set([...REQUIRED_PRIMARIES, ...primaryCategories])];
    primaryCategories.forEach(p => { if (!subCategoriesMap[p] || !subCategoriesMap[p].length) subCategoriesMap[p] = [`${p}_news`]; });
    Object.keys(subCategoriesMap).forEach(k => { if (!primaryCategories.includes(k)) delete subCategoriesMap[k]; });
}

// --- Modals ---
function openModal(id) { document.getElementById(id)?.classList.add('active'); document.body.classList.add('modal-open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); if (!document.querySelector('.modal-overlay.active')) document.body.classList.remove('modal-open'); }

async function openNewsModal(id = null) {
    currentNewsId = id;
    document.getElementById('deleteNewsSection').style.display = id ? 'block' : 'none';
    document.getElementById('newsModalTitle').textContent = id ? 'Edit News' : 'Add News';
    document.getElementById('editNewsId').value = id || '';
    updateAllDropdowns();

    if (id) {
        const n = allNews.find(x => x.id === id);
        if (n) {
            document.getElementById('newsDatetime').value = fmt(n.published_at || n.datetime);
            document.getElementById('newsHeadline').value = n.title || '';
            document.getElementById('newsSummary').value = n.summary || '';
            document.getElementById('newsPrimaryCat').value = n.primary_category || primaryCategories[0];
            document.getElementById('newsPrimaryCat').onchange?.();
            setTimeout(() => { document.getElementById('newsSubCat').value = n.secondary_category || ''; }, 0);
            document.getElementById('newsStatus').value = n.status || 'active';
            document.getElementById('newsRelevanceMode').value = n.relevance_mode || 'hybrid';
            document.getElementById('newsPrecisionType').value = n.precision_type || 'approximate_area';
            document.getElementById('newsMainPlaceText').value = n.location_label || '';
            document.getElementById('newsSourceName').value = n.source_name || '';
            document.getElementById('newsSource').value = n.url || n.source || '';
            syncCoordInputs(n.lat || DEFAULT_MAP_COORDS.lat, n.lng || DEFAULT_MAP_COORDS.lng, true);
        }
    } else {
        document.getElementById('newsDatetime').value = fmt(new Date());
        document.getElementById('newsHeadline').value = '';
        document.getElementById('newsSummary').value = '';
        document.getElementById('newsPrimaryCat').value = primaryCategories[0];
        document.getElementById('newsPrimaryCat').onchange?.();
        document.getElementById('newsStatus').value = 'active';
        document.getElementById('newsRelevanceMode').value = 'hybrid';
        document.getElementById('newsPrecisionType').value = 'approximate_area';
        document.getElementById('newsMainPlaceText').value = '';
        document.getElementById('newsSourceName').value = '';
        document.getElementById('newsSource').value = '';
        syncCoordInputs(DEFAULT_MAP_COORDS.lat, DEFAULT_MAP_COORDS.lng, true);
    }

    openModal('newsModal');
    ensureNewsMap();
    setTimeout(() => {
        if (newsMap) {
            newsMap.invalidateSize();
            newsMap.setView([Number(document.getElementById('newsLat').value || DEFAULT_MAP_COORDS.lat), Number(document.getElementById('newsLng').value || DEFAULT_MAP_COORDS.lng)], 11);
        }
    }, 80);

    if (window.newsPicker) window.newsPicker.destroy();
    window.newsPicker = flatpickr('#newsDatetime', { enableTime: true, dateFormat: 'm/d/Y H:i', time_24hr: true });
}

async function openSubModal(id = null) {
    currentNewsId = id;
    document.getElementById('deleteSubSection').style.display = id ? 'block' : 'none';
    document.getElementById('subModalTitle').textContent = id ? 'Edit Subscriber' : 'Add Subscriber';
    document.getElementById('editSubId').value = id || '';
    updateAllDropdowns();

    if (id) {
        const s = allSubscribers.find(x => x.id === id);
        if (s) {
            document.getElementById('subJoinDate').value = fmt(s.join_date);
            document.getElementById('subUserCode').value = s.user_code || '';
            document.getElementById('subMobile').value = s.mobile || '';
            document.getElementById('subWAGroup').value = s.wa_group || waBroadcastGroups[0];
            document.getElementById('subInterestCat').value = s.interest_sub_cat || '';
            document.getElementById('subStatus').value = s.status || 'active';
            document.getElementById('subLocationName').value = s.location_name || '';
        }
    } else {
        document.getElementById('subJoinDate').value = fmt(new Date());
        document.getElementById('subUserCode').value = '';
        document.getElementById('subMobile').value = '';
        document.getElementById('subWAGroup').value = waBroadcastGroups[0];
        document.getElementById('subInterestCat').value = '';
        document.getElementById('subStatus').value = 'active';
        document.getElementById('subLocationName').value = '';
    }

    openModal('subscriberModal');
    if (window.subPicker) window.subPicker.destroy();
    window.subPicker = flatpickr('#subJoinDate', { enableTime: true, dateFormat: 'm/d/Y H:i', time_24hr: true });
}

// --- Save / Delete ---
async function saveNewsAction() {
    const payload = {
        headline: document.getElementById('newsHeadline').value.trim(),
        summary: document.getElementById('newsSummary').value.trim(),
        primary_cat: document.getElementById('newsPrimaryCat').value,
        sub_cat: document.getElementById('newsSubCat').value,
        status: document.getElementById('newsStatus').value,
        relevance_mode: document.getElementById('newsRelevanceMode').value,
        precision_type: document.getElementById('newsPrecisionType').value,
        main_place_text: document.getElementById('newsMainPlaceText').value.trim(),
        source_name: document.getElementById('newsSourceName').value.trim(),
        source: document.getElementById('newsSource').value.trim(),
        lat: parseFloat(document.getElementById('newsLat').value) || 0,
        lng: parseFloat(document.getElementById('newsLng').value) || 0,
        datetime: parseUiDateTime(document.getElementById('newsDatetime').value).toISOString(),
    };
    if (!payload.headline) return alert('Headline is required');
    try {
        await saveNews(payload);
        closeModal('newsModal');
        await refreshNews();
        await renderIntelDashboard();
    } catch (e) { alert('Save failed: ' + e.message); }
}

async function deleteNewsAction() {
    if (!currentNewsId) return;
    if (!confirm('Delete this news?')) return;
    try {
        await deleteNews(currentNewsId);
        closeModal('newsModal');
        await refreshNews();
        await renderIntelDashboard();
    } catch (e) { alert('Delete failed: ' + e.message); }
}

async function saveSubAction() {
    const payload = {
        user_code: document.getElementById('subUserCode').value.trim(),
        mobile: document.getElementById('subMobile').value.trim(),
        wa_group: document.getElementById('subWAGroup').value,
        interest_sub_cat: document.getElementById('subInterestCat').value,
        status: document.getElementById('subStatus').value,
        location_name: document.getElementById('subLocationName').value.trim() || 'Unknown',
        join_date: parseUiDateTime(document.getElementById('subJoinDate').value).toISOString(),
    };
    if (!payload.user_code || !payload.mobile) return alert('User Code and Mobile are required');
    try {
        await saveSubscriber(payload);
        closeModal('subscriberModal');
        await refreshSubscribers();
        await renderIntelDashboard();
    } catch (e) { alert('Save failed: ' + e.message); }
}

async function deleteSubAction() {
    if (!currentNewsId) return;
    if (!confirm('Delete this subscriber?')) return;
    try {
        await deleteSubscriber(currentNewsId);
        closeModal('subscriberModal');
        await refreshSubscribers();
    } catch (e) { alert('Delete failed: ' + e.message); }
}

// --- Map ---
function ensureNewsMap() {
    if (!newsMap) {
        newsMap = L.map('newsMap');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(newsMap);
        newsMarker = L.marker([DEFAULT_MAP_COORDS.lat, DEFAULT_MAP_COORDS.lng], { draggable: true }).addTo(newsMap);
        newsMarker.on('dragend', () => {
            const ll = newsMarker.getLatLng();
            syncCoordInputs(ll.lat, ll.lng, true);
        });
        newsMap.on('click', e => syncCoordInputs(e.latlng.lat, e.latlng.lng, true));
    }
}

function syncCoordInputs(lat, lng, updateMap = false) {
    lat = Number(lat || 0); lng = Number(lng || 0);
    const latEl = document.getElementById('newsLat');
    const lngEl = document.getElementById('newsLng');
    if (latEl) latEl.value = lat.toFixed(6);
    if (lngEl) lngEl.value = lng.toFixed(6);
    const coordEl = document.getElementById('coordDisplay');
    if (coordEl) coordEl.textContent = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
    if (newsMarker) newsMarker.setLatLng([lat, lng]);
    if (newsMap && updateMap) newsMap.setView([lat, lng], newsMap.getZoom() < 8 ? 11 : newsMap.getZoom());
}

// --- Settings ---
function renderPrimaryList() {
    const container = document.getElementById('primaryCatList');
    if (!container) return;
    container.innerHTML = primaryCategories.map(cat => {
        const isSystem = REQUIRED_PRIMARIES.includes(cat);
        const isSelected = selectedPrimaryCategory === cat;
        return `<div class="cat-item ${isSelected ? 'selected' : ''}" data-cat="${esc(cat)}">
            <div><span>📁 ${esc(cat)}</span>${isSystem ? '<span class="system-badge">system</span>' : ''}</div>
            ${isSystem ? '<button disabled style="background:none; opacity:0.5;">✖</button>' : `<button class="danger-icon-btn delete-cat-btn" data-cat="${esc(cat)}">✖</button>`}
        </div>`;
    }).join('');

    document.querySelectorAll('.cat-item').forEach(row => {
        if (!row.querySelector('.delete-cat-btn')) return;
        row.addEventListener('click', e => {
            if (!e.target.classList.contains('delete-cat-btn')) {
                selectedPrimaryCategory = row.dataset.cat;
                renderPrimaryList();
                updatePrimarySelect();
            }
        });
    });
    document.querySelectorAll('.delete-cat-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); deletePrimaryCategory(btn.dataset.cat); }));
}

function updatePrimarySelect() {
    const select = document.getElementById('subPrimarySelect');
    if (!select) return;
    select.innerHTML = primaryCategories.map(c => `<option>${esc(c)}</option>`).join('');
    if (!primaryCategories.includes(selectedPrimaryCategory)) selectedPrimaryCategory = primaryCategories[0];
    select.value = selectedPrimaryCategory;
    document.getElementById('selectedPrimaryName').textContent = `(current: ${selectedPrimaryCategory})`;
    select.onchange = () => { selectedPrimaryCategory = select.value; renderPrimaryList(); renderSubList(); };
    renderSubList();
}

function renderSubList() {
    const container = document.getElementById('subCatList');
    if (!container) return;
    let subs = subCategoriesMap[selectedPrimaryCategory] || [];
    container.innerHTML = subs.map(sub => `
        <div class="sub-item ${selectedSubCategory === sub ? 'selected' : ''}" data-sub="${esc(sub)}">
            <span>🔹 ${esc(sub)}</span>
            <button class="danger-icon-btn delete-sub-btn" data-sub="${esc(sub)}">✖</button>
        </div>`).join('');
    document.querySelectorAll('.sub-item').forEach(el => el.addEventListener('click', e => { if (!e.target.classList.contains('delete-sub-btn')) { selectedSubCategory = el.dataset.sub; renderSubList(); } }));
    document.querySelectorAll('.delete-sub-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); deleteSubCategory(btn.dataset.sub); }));
}

function deletePrimaryCategory(cat) {
    if (REQUIRED_PRIMARIES.includes(cat)) return alert(`"${cat}" is a system category and cannot be deleted.`);
    if (!confirm(`Delete "${cat}"?`)) return;
    primaryCategories = primaryCategories.filter(c => c !== cat);
    delete subCategoriesMap[cat];
    if (selectedPrimaryCategory === cat) selectedPrimaryCategory = primaryCategories[0];
    saveCategories().catch(console.error);
    updateAllDropdowns(); renderPrimaryList(); updatePrimarySelect(); refreshNews();
}

function deleteSubCategory(sub) {
    if (!sub) return;
    let arr = subCategoriesMap[selectedPrimaryCategory];
    if (!arr || arr.length <= 1) return alert('At least one sub-category must remain.');
    subCategoriesMap[selectedPrimaryCategory] = arr.filter(s => s !== sub);
    if (selectedSubCategory === sub) selectedSubCategory = null;
    saveCategories().catch(console.error);
    renderSubList(); updateAllDropdowns(); refreshNews();
}

function renderWAGroups() {
    const container = document.getElementById('waGroupList');
    if (!container) return;
    container.innerHTML = waBroadcastGroups.map((g, idx) => `
        <div class="wa-group-item">
            <span>💬 ${esc(g)}</span>
            <div>
                <button class="danger-icon-btn edit-wa-btn" data-idx="${idx}" data-name="${esc(g)}">✏️ Edit</button>
                <button class="danger-icon-btn delete-wa-btn" data-idx="${idx}">🗑️ Delete</button>
            </div>
        </div>`).join('');

    document.querySelectorAll('.edit-wa-btn').forEach(btn => btn.addEventListener('click', () => {
        let newName = prompt('Edit group name:', btn.dataset.name);
        if (newName && newName.trim()) {
            waBroadcastGroups[btn.dataset.idx] = newName.trim();
            saveWAGroups().catch(console.error);
            updateAllDropdowns(); refreshSubscribers(); renderWAGroups();
        }
    }));
    document.querySelectorAll('.delete-wa-btn').forEach(btn => btn.addEventListener('click', () => {
        if (waBroadcastGroups.length <= 1) return alert('At least one group must remain.');
        waBroadcastGroups.splice(parseInt(btn.dataset.idx, 10), 1);
        saveWAGroups().catch(console.error);
        updateAllDropdowns(); refreshSubscribers(); renderWAGroups();
    }));
}

// --- Intel Detail Modal ---
function openIntelDetail(type) {
    intelDetailState.type = type;
    document.getElementById('intelDetailTitle').textContent = 'Intel Details — Loading...';
    loadIntel(document.getElementById('intelRangePreset')?.value || '7d').then(intel => {
        let title = 'Intel Details', rows = [], headers = [], kpis = [];
        if (type === 'locations') {
            title = `Location Analytics · ${intel.range.label}`;
            headers = ['Location','Users','Clicks','Top Category'];
            rows = intel.locations.map(x => ({ values:[x.location_name, x.user_count, x.click_count, x.top_category], primary_category:x.top_category||'', secondary_category:'', clicks:x.click_count, title:x.location_name }));
            kpis = [{label:'Locations',value:intel.locations.length},{label:'Users',value:intel.summary.total_users},{label:'Clicks',value:intel.summary.total_clicks}];
        } else if (type === 'categories') {
            title = `Category Distribution · ${intel.range.label}`;
            headers = ['Primary Category','Secondary Category','Clicks'];
            rows = intel.categories.map(x => ({ values:[x.primary_category, x.secondary_category||'—', x.click_count], primary_category:x.primary_category, secondary_category:x.secondary_category||'', clicks:x.click_count, title:`${x.primary_category} ${x.secondary_category||''}`.trim() }));
            kpis = [{label:'Primary Categories',value:new Set(intel.categories.map(x=>x.primary_category)).size},{label:'Rows',value:intel.categories.length},{label:'Top Category',value:intel.summary.top_category}];
        } else if (type === 'top_news') {
            title = `Top News · ${intel.range.label}`;
            headers = ['News ID','Title','Primary Category','Secondary Category','Clicks'];
            rows = intel.top_news.map(x => ({ values:[x.news_item_id,x.title,x.primary_category,x.secondary_category||'—',x.click_count], primary_category:x.primary_category,secondary_category:x.secondary_category||'',clicks:x.click_count,title:x.title}));
            kpis = [{label:'News Rows',value:intel.top_news.length},{label:'Top Clicks',value:intel.top_news[0]?.click_count||0},{label:'Tracked Clicks',value:intel.summary.total_clicks}];
        } else if (type === 'activity') {
            title = `Time Analysis · ${intel.range.label}`;
            const labelTitle = intel.granularity === 'hour' ? 'Hour' : intel.granularity === 'day' ? 'Day' : 'Month';
            headers = [labelTitle, 'Clicks'];
            rows = intel.activity.map(x => ({ values:[x.label,x.click_count], primary_category:'',secondary_category:'',clicks:x.click_count,title:x.label}));
            kpis = [{label:'Granularity',value:intel.granularity},{label:'Buckets',value:intel.activity.length},{label:'Peak',value:intel.summary.peak_hour}];
        }
        intelDetailState.rows = rows;
        document.getElementById('intelDetailTitle').textContent = title;
        document.getElementById('intelDetailSearch').value = '';
        document.getElementById('intelDetailMinClicks').value = '';
        document.getElementById('intelDetailSort').value = 'clicks_desc';
        document.getElementById('intelDetailSummary').innerHTML = `<strong>${rows.length} rows</strong>`;
        document.getElementById('intelDetailKpis').innerHTML = kpis.map(k => `<div class="detail-kpi"><span class="label">${esc(k.label)}</span><span class="value">${esc(k.value)}</span></div>`).join('');
        renderIntelDetailTable();
        openModal('intelDetailModal');
    });
}

function renderIntelDetailTable() {
    const term = (document.getElementById('intelDetailSearch')?.value || '').toLowerCase();
    const minClicks = Number(document.getElementById('intelDetailMinClicks')?.value || 0);
    const sortBy = document.getElementById('intelDetailSort')?.value || 'clicks_desc';
    let rows = intelDetailState.rows.filter(r => {
        const searchOk = !term || Object.values(r).some(c => String(c).toLowerCase().includes(term));
        const clicksOk = (r.clicks || 0) >= minClicks;
        return searchOk && clicksOk;
    });
    rows.sort((a,b) => {
        if (sortBy === 'clicks_asc') return (a.clicks||0) - (b.clicks||0);
        if (sortBy === 'clicks_desc') return (b.clicks||0) - (a.clicks||0);
        if (sortBy === 'title_asc') return String(a.title||'').localeCompare(String(b.title||''));
        if (sortBy === 'title_desc') return String(b.title||'').localeCompare(String(a.title||''));
        return 0;
    });
    document.getElementById('intelDetailSummary').innerHTML = `<strong>${rows.length} rows</strong>`;
    document.getElementById('intelDetailTableWrap').innerHTML = renderIntelTable(intelDetailState.headers || [], rows.map(r => r.values));
}

// --- Init Events ---
function initEvents() {
    // Tab navigation
    document.querySelectorAll('.tab-btn').forEach(btn => btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab + 'Tab')?.classList.add('active');
        if (btn.dataset.tab === 'intel') renderIntelDashboard();
        if (btn.dataset.tab === 'news') refreshNews();
        if (btn.dataset.tab === 'subscriber') refreshSubscribers();
    }));

    // Settings
    document.getElementById('adminSettingsBtn')?.addEventListener('click', () => {
        renderPrimaryList(); updatePrimarySelect(); renderWAGroups(); openModal('adminModal');
    });
    document.getElementById('closeAdminBtn')?.addEventListener('click', () => closeModal('adminModal'));

    // News
    document.getElementById('addRowBtn')?.addEventListener('click', () => openNewsModal());
    document.getElementById('cancelNewsBtn')?.addEventListener('click', () => closeModal('newsModal'));
    document.getElementById('saveNewsBtn')?.addEventListener('click', saveNewsAction);
    document.getElementById('deleteNewsBtn')?.addEventListener('click', deleteNewsAction);

    // Subscribers
    document.getElementById('addSubscriberBtn')?.addEventListener('click', () => openSubModal());
    document.getElementById('cancelSubBtn')?.addEventListener('click', () => closeModal('subscriberModal'));
    document.getElementById('saveSubBtn')?.addEventListener('click', saveSubAction);
    document.getElementById('deleteSubBtn')?.addEventListener('click', deleteSubAction);

    // Category management
    document.getElementById('addPrimaryCatBtn')?.addEventListener('click', () => {
        const val = document.getElementById('newPrimaryCat')?.value.trim().toLowerCase();
        if (val && !primaryCategories.includes(val)) {
            primaryCategories.push(val);
            subCategoriesMap[val] = ['general'];
            selectedPrimaryCategory = val;
            saveCategories().catch(console.error);
            updateAllDropdowns(); renderPrimaryList(); updatePrimarySelect();
            document.getElementById('newPrimaryCat').value = '';
        } else alert('Invalid or duplicate category');
    });
    document.getElementById('deletePrimaryCatBtn')?.addEventListener('click', () => {
        if (selectedPrimaryCategory) deletePrimaryCategory(selectedPrimaryCategory);
        else alert('Select a primary category first');
    });
    document.getElementById('addSubCatBtn')?.addEventListener('click', () => {
        const newSub = document.getElementById('newSubCat')?.value.trim();
        if (newSub && !subCategoriesMap[selectedPrimaryCategory]?.includes(newSub)) {
            subCategoriesMap[selectedPrimaryCategory].push(newSub);
            selectedSubCategory = newSub;
            saveCategories().catch(console.error);
            renderSubList(); updateAllDropdowns(); refreshNews();
            document.getElementById('newSubCat').value = '';
        }
    });
    document.getElementById('deleteSubCatBtn')?.addEventListener('click', () => {
        if (selectedSubCategory) deleteSubCategory(selectedSubCategory);
        else alert('Select a sub-category first');
    });

    // WA Groups
    document.getElementById('addWAGroupBtn')?.addEventListener('click', () => {
        const val = document.getElementById('newWAGroupName')?.value.trim();
        if (val && !waBroadcastGroups.includes(val)) {
            waBroadcastGroups.push(val);
            saveWAGroups().catch(console.error);
            renderWAGroups(); updateAllDropdowns(); refreshSubscribers();
            document.getElementById('newWAGroupName').value = '';
        } else alert('Invalid group name');
    });

    // Intel
    document.getElementById('intelRangePreset')?.addEventListener('change', e => {
        const isCustom = e.target.value === 'custom';
        document.getElementById('intelCustomRange').style.display = isCustom ? 'inline-flex' : 'none';
        renderIntelDashboard();
    });
    document.getElementById('intelGranularity')?.addEventListener('change', renderIntelDashboard);
    document.getElementById('intelResetRangeBtn')?.addEventListener('click', () => {
        document.getElementById('intelRangePreset').value = '7d';
        document.getElementById('intelCustomRange').value = '';
        document.getElementById('intelCustomRange').style.display = 'none';
        document.getElementById('intelGranularity').value = 'auto';
        renderIntelDashboard();
    });
    document.getElementById('closeIntelDetailBtn')?.addEventListener('click', () => closeModal('intelDetailModal'));
    document.getElementById('intelDetailSearch')?.addEventListener('input', renderIntelDetailTable);
    document.getElementById('intelDetailSort')?.addEventListener('change', renderIntelDetailTable);
    document.getElementById('intelDetailMinClicks')?.addEventListener('input', renderIntelDetailTable);
    document.querySelectorAll('[data-intel-detail]').forEach(btn => btn.addEventListener('click', () => openIntelDetail(btn.dataset.intelDetail)));

    // Map
    document.getElementById('resetMapPin')?.addEventListener('click', () => syncCoordInputs(DEFAULT_MAP_COORDS.lat, DEFAULT_MAP_COORDS.lng, true));
    document.getElementById('newsLat')?.addEventListener('input', () => syncCoordInputs(document.getElementById('newsLat').value, document.getElementById('newsLng').value));
    document.getElementById('newsLng')?.addEventListener('input', () => syncCoordInputs(document.getElementById('newsLat').value, document.getElementById('newsLng').value));

    // News filters
    document.getElementById('primaryCategoryFilter')?.addEventListener('change', e => { newsFilterPrimary = e.target.value; newsCurrentPage = 1; refreshNews(); });
    document.getElementById('subCategoryFilter')?.addEventListener('change', e => { newsFilterSub = e.target.value; newsCurrentPage = 1; refreshNews(); });
    document.getElementById('clearNewsFilterBtn')?.addEventListener('click', () => {
        newsFilterPrimary = 'all'; newsFilterSub = 'all'; newsCurrentPage = 1;
        document.getElementById('primaryCategoryFilter').value = 'all';
        document.getElementById('subCategoryFilter').value = 'all';
        refreshNews();
    });

    // Subscriber filters
    document.getElementById('filterUserCode')?.addEventListener('input', e => { subFilters.userCode = e.target.value; subCurrentPage = 1; refreshSubscribers(); });
    document.getElementById('filterMobile')?.addEventListener('input', e => { subFilters.mobile = e.target.value; subCurrentPage = 1; refreshSubscribers(); });
    document.getElementById('filterWAGroup')?.addEventListener('change', e => { subFilters.waGroup = e.target.value; subCurrentPage = 1; refreshSubscribers(); });
    document.getElementById('filterInterestSub')?.addEventListener('change', e => { subFilters.interestSub = e.target.value; subCurrentPage = 1; refreshSubscribers(); });
    document.getElementById('filterStatus')?.addEventListener('change', e => { subFilters.status = e.target.value; subCurrentPage = 1; refreshSubscribers(); });
    document.getElementById('clearSubFiltersBtn')?.addEventListener('click', () => {
        subFilters = { userCode:'', mobile:'', waGroup:'all', interestSub:'all', status:'all' }; subCurrentPage = 1;
        document.getElementById('filterUserCode').value = '';
        document.getElementById('filterMobile').value = '';
        document.getElementById('filterWAGroup').value = 'all';
        document.getElementById('filterInterestSub').value = 'all';
        document.getElementById('filterStatus').value = 'all';
        refreshSubscribers();
    });

    // Modal overlay close
    ['adminModal','newsModal','subscriberModal','intelDetailModal'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', e => { if (e.target === document.getElementById(id)) closeModal(id); });
    });

    // Settings tabs
    document.querySelectorAll('.settings-tab-btn').forEach(btn => btn.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('settingsCategories').style.display = btn.dataset.settingsTab === 'categories' ? 'block' : 'none';
        document.getElementById('settingsWAGroups').style.display = btn.dataset.settingsTab === 'wagroups' ? 'block' : 'none';
    }));


    // Logout button in tab nav
    document.getElementById('logoutBtn')?.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
            const f = document.createElement('form'); f.method='POST'; f.action='/admin/logout'; document.body.appendChild(f); f.submit();
        }
    });

    // Flatpickr
    if (typeof flatpickr !== 'undefined') {
        flatpickr('#filterDateRange', { mode: 'range', dateFormat: 'm/d/Y' });
        flatpickr('#intelCustomRange', { mode: 'range', dateFormat: 'Y-m-d', onClose: renderIntelDashboard });
    }
}

// Boot
async function boot() {
    // Load settings non-blocking — errors shouldn't break the UI
    loadSettings().then(() => {
        updateAllDropdowns();
        renderPrimaryList();
        updatePrimarySelect();
    }).catch(err => {
        console.warn('Settings load failed, using defaults', err);
        ensureCategoryIntegrity();
        updateAllDropdowns();
        renderPrimaryList();
        updatePrimarySelect();
    });

    // Init events immediately — don't wait for settings
    initEvents();

    // Load data in background
    refreshNews().catch(err => console.warn('News load failed', err));
    refreshSubscribers().catch(err => console.warn('Subscribers load failed', err));
    renderIntelDashboard().catch(err => console.warn('Intel load failed', err));
}

boot();
