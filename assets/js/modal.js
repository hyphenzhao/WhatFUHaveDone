/**
 * Modal dialog system
 */
const Modal = {
    overlay: null,
    titleEl: null,
    bodyEl: null,
    footerEl: null,
    closeBtn: null,
    _resolve: null,

    init() {
        this.overlay = document.getElementById('modalOverlay');
        this.titleEl = document.getElementById('modalTitle');
        this.bodyEl = document.getElementById('modalBody');
        this.footerEl = document.getElementById('modalFooter');
        this.closeBtn = document.getElementById('modalClose');

        this.closeBtn.addEventListener('click', () => this.close());
        this.overlay.addEventListener('click', (e) => {
            if (e.target === this.overlay) this.close();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.close();
        });
    },

    open({ title = '', body = '', footer = '', onClose = null } = {}) {
        this.titleEl.textContent = title;
        this.bodyEl.innerHTML = body;
        this.footerEl.innerHTML = footer;
        this.overlay.style.display = 'flex';
        this._onClose = onClose;
    },

    close(result = null) {
        this.overlay.style.display = 'none';
        if (this._onClose) this._onClose(result);
        this._onClose = null;
    },

    setBody(html) { this.bodyEl.innerHTML = html; },
    setFooter(html) { this.footerEl.innerHTML = html; },
    setTitle(title) { this.titleEl.textContent = title; },
};

/**
 * Toast notifications
 */
const Toast = {
    container: null,

    init() {
        this.container = document.getElementById('toastContainer');
    },

    show(message, type = 'success', duration = 2500) {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = message;
        this.container.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s';
            setTimeout(() => el.remove(), 300);
        }, duration);
    },

    success(msg) { this.show(msg, 'success'); },
    error(msg) { this.show(msg, 'error'); },
};
