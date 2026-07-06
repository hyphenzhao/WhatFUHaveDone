/**
 * Main application — shared state and initialization
 */
const App = {
    selectedDate: today(), // YYYY-MM-DD
    currentMonth: '',

    init() {
        Modal.init();
        Toast.init();

        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                document.querySelector('.app-layout').classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed',
                    document.querySelector('.app-layout').classList.contains('sidebar-collapsed'));
            });
            // Restore state
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                document.querySelector('.app-layout').classList.add('sidebar-collapsed');
            }
        }

        // Right panel toggle (home page)
        const rightPanelToggle = document.getElementById('rightPanelToggle');
        if (rightPanelToggle) {
            rightPanelToggle.addEventListener('click', () => {
                document.querySelector('.app-layout').classList.toggle('right-collapsed');
                localStorage.setItem('rightCollapsed',
                    document.querySelector('.app-layout').classList.contains('right-collapsed'));
            });
            if (localStorage.getItem('rightCollapsed') === 'true') {
                document.querySelector('.app-layout').classList.add('right-collapsed');
            }
        }

        // Panel resize handle
        const handle = document.getElementById('panelResizeHandle');
        if (handle) {
            let savedWidth = localStorage.getItem('rightPanelWidth');
            if (savedWidth) {
                document.documentElement.style.setProperty('--right-panel-width', savedWidth + 'px');
            }
            let dragging = false, startX, startWidth;
            handle.addEventListener('mousedown', (e) => {
                dragging = true; startX = e.clientX;
                startWidth = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--right-panel-width'));
                document.body.style.cursor = 'col-resize'; document.body.style.userSelect = 'none';
            });
            document.addEventListener('mousemove', (e) => {
                if (!dragging) return;
                const diff = startX - e.clientX;
                const newWidth = Math.max(280, Math.min(800, startWidth + diff));
                document.documentElement.style.setProperty('--right-panel-width', newWidth + 'px');
            });
            document.addEventListener('mouseup', () => {
                if (dragging) {
                    dragging = false;
                    document.body.style.cursor = ''; document.body.style.userSelect = '';
                    const w = getComputedStyle(document.documentElement).getPropertyValue('--right-panel-width');
                    localStorage.setItem('rightPanelWidth', parseInt(w));
                }
            });
        }
    },

    setDate(dateStr) {
        this.selectedDate = dateStr;
        document.dispatchEvent(new CustomEvent('app:datechange', { detail: { date: dateStr } }));
    },
};

// Shared tag-chip selectors
function tagSelectHtml(id, items, selectedIds, type) {
    return items.map(item => {
        const sel = selectedIds.includes(item.id);
        if (type === 'people') {
            return `<span class="tag-chip tag-chip-people${sel?' selected':''}" data-id="${item.id}" data-group="${id}" onclick="toggleTagChip(this)" title="${escapeHtml(item.relationship||'')}">${escapeHtml(item.name)}</span>`;
        }
        const color = item.color || '#3B82F6';
        return `<span class="tag-chip tag-chip-tag${sel?' selected':''}" style="--chip-color:${color};color:${sel?'#fff':color};border-color:${color};${sel?'background:'+color+';':''}" data-id="${item.id}" data-group="${id}" onclick="toggleTagChip(this)">${escapeHtml(item.name)}</span>`;
    }).join('');
}
function getSelectedTagIds(groupId) {
    return Array.from(document.querySelectorAll(`.tag-chip.selected[data-group="${groupId}"]`)).map(el => parseInt(el.dataset.id));
}
function toggleTagChip(el) { el.classList.toggle('selected'); }

function starHtml(name, val) {
    const id = 'star_' + name;
    let h = `<span class="star-rating" id="${id}">`;
    for (let i = 1; i <= 5; i++) h += `<span class="star${i <= val ? ' filled' : ''}" data-v="${i}" onclick="setStar('${id}',${i})">★</span>`;
    return h + '</span>';
}
function getStarVal(id) { return document.querySelectorAll('#' + id + ' .star.filled').length; }
function setStar(id, v) { const el = document.getElementById(id); if (!el) return; el.querySelectorAll('.star').forEach((s, i) => s.classList.toggle('filled', i < v)); }

function getDeadlineBadge(deadline) {
    if (!deadline) return '';
    if (deadline === '自由') return '<span class="deadline-badge deadline-free">自由</span>';
    if (deadline === '尽快') return '<span class="deadline-badge deadline-asap">尽快</span>';
    const due = new Date(deadline + 'T23:59:59');
    const now = new Date();
    const hours = (due - now) / (3600 * 1000);
    if (hours < 0) return `<span class="deadline-badge deadline-overdue">${deadline}</span>`;
    if (hours < 24) return `<span class="deadline-badge deadline-urgent">${deadline}</span>`;
    if (hours < 72) return `<span class="deadline-badge deadline-warn">${deadline}</span>`;
    if (hours < 168) return `<span class="deadline-badge deadline-info">${deadline}</span>`;
    return `<span class="deadline-badge deadline-normal">${deadline}</span>`;
}

function today() {
    const d = new Date();
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('-');
    return `${y}年${parseInt(m)}月${parseInt(d)}日`;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Star display helper
function renderStars(count) {
    let html = '';
    for (let i = 0; i < 5; i++) {
        html += `<span class="${i < count ? 'star filled' : 'star'}">★</span>`;
    }
    return html;
}

// Debounce helper
function debounce(fn, delay = 300) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

// Table sorting helper
function makeSortable(tableId, renderFn) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let sortCol = null, sortAsc = true;

    table.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.style.userSelect = 'none';
        th.addEventListener('click', () => {
            const col = th.dataset.sort;
            if (sortCol === col) {
                sortAsc = !sortAsc;
            } else {
                sortCol = col;
                sortAsc = true;
            }
            // Update arrows
            table.querySelectorAll('th[data-sort]').forEach(h => {
                const arrow = h.querySelector('.sort-arrow');
                if (arrow) arrow.remove();
            });
            th.insertAdjacentHTML('beforeend', `<span class="sort-arrow"> ${sortAsc ? '▲' : '▼'}</span>`);
            renderFn(sortCol, sortAsc);
        });
    });
}

function sortData(data, col, asc) {
    return [...data].sort((a, b) => {
        let va = a[col], vb = b[col];
        if (va == null) va = '';
        if (vb == null) vb = '';
        if (typeof va === 'string') va = va.toLowerCase();
        if (typeof vb === 'string') vb = vb.toLowerCase();
        if (va < vb) return asc ? -1 : 1;
        if (va > vb) return asc ? 1 : -1;
        return 0;
    });
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => App.init());
