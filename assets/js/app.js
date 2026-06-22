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
    },

    setDate(dateStr) {
        this.selectedDate = dateStr;
    },
};

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
