/**
 * MobileUI — mobile/portrait shell behaviour.
 *
 * Owns:
 *   - the right-panel tabs (moved here from home.js so ONE owner serves every
 *     page: the panel is now rendered app-wide for the AI assistant)
 *   - the bottom tab bar, the nav drawer and the panel sheet (≤899px)
 *   - the edge tab that pulls the panel out as an overlay (900–1279px)
 *   - data-label on table cells, so .data-table can become cards at ≤560px
 *     without touching any of the seven page scripts
 *
 * Everything here is inert on the ≥1280px desktop: the elements it binds are
 * display:none there, and the tab handler behaves exactly as home.js's did.
 */
const MobileUI = {
    /** ≤899px: bottom tab bar + drawers. */
    isTouchTier() { return window.matchMedia('(max-width: 899px)').matches; },
    /** ≤1279px: the right panel is an overlay rather than a grid column. */
    isOverlayTier() { return window.matchMedia('(max-width: 1279px)').matches; },

    init() {
        this.initPanelTabs();
        this.initDrawers();
        this.initTables();
        this.guardModal();
        this.handleDeepLink();
    },

    // ===== right panel tabs (previously home.js initPanelTabs) =====
    initPanelTabs() {
        document.querySelectorAll('.rp-tab').forEach(tab => {
            tab.addEventListener('click', () => this.selectTab(tab.dataset.tab));
        });
    },

    selectTab(name) {
        const taskBody = document.getElementById('rightPanelBody');
        const aiBody = document.getElementById('rightPanelAi');
        if (!aiBody) return;
        // Off home there is no task list, so the assistant is the only tab.
        if (name === 'tasklist' && !taskBody) name = 'ai-assistant';

        document.querySelectorAll('.rp-tab').forEach(t =>
            t.classList.toggle('rp-tab-active', t.dataset.tab === name));
        if (taskBody) taskBody.classList.toggle('rp-hidden', name !== 'tasklist');
        aiBody.classList.toggle('rp-hidden', name !== 'ai-assistant');

        if (name === 'ai-assistant' && typeof AiChat !== 'undefined') AiChat.mount(aiBody);
    },

    // ===== drawers =====
    initDrawers() {
        const body = document.body;
        const scrim = document.getElementById('mScrim');

        const close = () => {
            body.classList.remove('m-nav-open', 'm-sheet-open');
            document.querySelectorAll('.mtab').forEach(t => t.classList.remove('on'));
        };
        this.closeAll = close;

        const open = (cls, btn) => {
            const already = body.classList.contains(cls);
            close();
            if (!already) {
                body.classList.add(cls);
                if (btn) btn.classList.add('on');
            }
        };

        document.getElementById('mNavBtn')?.addEventListener('click', function () {
            open('m-nav-open', this);
        });

        document.querySelectorAll('.mtab[data-sheet]').forEach(btn => {
            btn.addEventListener('click', () => {
                // Off home, the 任务 tab is a link back to home's panel
                if (btn.dataset.href) { window.location.href = btn.dataset.href; return; }
                this.selectTab(btn.dataset.sheet);
                open('m-sheet-open', btn);
            });
        });

        document.getElementById('mEdgeTab')?.addEventListener('click', () => open('m-sheet-open', null));
        document.getElementById('mSheetClose')?.addEventListener('click', close);
        scrim?.addEventListener('click', close);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        document.querySelectorAll('.sidebar-link').forEach(a => a.addEventListener('click', close));

        // On the overlay tiers the ▶ collapse button would toggle a class whose
        // effect we neutralise in CSS, so make it close the overlay instead.
        const toggle = document.getElementById('rightPanelToggle');
        if (toggle) {
            toggle.addEventListener('click', e => {
                if (this.isOverlayTier()) { e.stopImmediatePropagation(); close(); }
            }, true);
        }
    },

    // ===== tables → cards (CSS does the layout; this supplies the labels) =====
    initTables() {
        document.querySelectorAll('table.data-table').forEach(table => {
            this.labelCells(table);
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            // Every page re-renders with tbody.innerHTML = ..., so re-label on change.
            new MutationObserver(() => this.labelCells(table)).observe(tbody, { childList: true });
        });
    },

    labelCells(table) {
        const heads = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
        if (!heads.length) return;
        table.querySelectorAll('tbody tr').forEach(tr => {
            if (tr.children.length !== heads.length) return;   // colspan rows ("加载中…")
            [...tr.children].forEach((td, i) => {
                if (heads[i] && td.dataset.label !== heads[i]) td.dataset.label = heads[i];
            });
        });
    },

    // ===== a modal must not leave the sheet open behind it =====
    guardModal() {
        if (typeof Modal === 'undefined' || Modal._mobileGuarded) return;
        const open = Modal.open.bind(Modal);
        Modal.open = (opts) => {
            // Opening a modal can move the AiChat singleton into it, which would
            // leave the sheet showing an empty container.
            if (document.body.classList.contains('m-sheet-open')) this.closeAll?.();
            return open(opts);
        };
        Modal._mobileGuarded = true;
    },

    // ===== /?panel=tasks — the 任务 tab from a non-home page =====
    handleDeepLink() {
        const panel = new URLSearchParams(location.search).get('panel');
        if (!panel) return;
        // Only act where the panel is actually an overlay. On the desktop tier a
        // non-home right panel is display:none, and mounting AiChat into a hidden
        // container would strand the singleton there (see the mail.js guard).
        if (!this.isOverlayTier()) return;
        const name = panel === 'tasks' ? 'tasklist' : 'ai-assistant';
        this.selectTab(name);
        document.body.classList.add('m-sheet-open');
        document.querySelector(`.mtab[data-sheet="${name}"]`)?.classList.add('on');
    },
};

document.addEventListener('DOMContentLoaded', () => MobileUI.init());
