/** Interactive SVG graph. Physics settles; dragging changes this view only. */
const Graph = {
    mode: 'workload', selectedId: null, hoverId: null, frame: 0, alpha: 0,
    camera: { x: 0, y: 0, k: 1 }, size: { w: 800, h: 520 }, paused: false,
    palette: [
        ['#7379ce', '#a7b0ed'], ['#4f9f9b', '#88c9c0'], ['#c38a61', '#e5b892'],
        ['#ab7ca7', '#d4afd0'], ['#668dad', '#a6c2db'], ['#9c9a5f', '#cece9a']
    ],
    async init() {
        if (this.initialized) return;
        this.svg = document.getElementById('graphSvg');
        if (!this.svg) return;
        this.initialized = true;
        this.stage = document.getElementById('graphStage');
        this.tooltip = document.getElementById('graphTooltip');
        this.models = new Map(); this.pointers = new Map();
        this.people = []; this.links = []; this.query = ''; this.relation = '';
        this.motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.reduced = this.motionQuery.matches;
        this.motionQuery.addEventListener('change', e => { this.reduced = e.matches; this.allowMotion = false; this._reheat(); this._motionButton(); });
        this._bind(); this._resize(); this._detail(); this._motionButton();
        this.observer = new ResizeObserver(() => this._resize()); this.observer.observe(this.stage);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) this._stop();
            else if (this.alpha > .02 && !this.paused && !this._usesReduced()) this._schedule();
        });
        window.addEventListener('pagehide', () => { this._stop(); this.observer.disconnect(); });
        await this.load();
    },
    _bind() {
        document.getElementById('toggleWorkload').addEventListener('click', () => this.switchMode('workload'));
        document.getElementById('toggleResults').addEventListener('click', () => this.switchMode('results'));
        document.getElementById('graphSearch').addEventListener('input', e => {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => { this.query = e.target.value.trim().toLocaleLowerCase(); this.interacted = false; this.render(); }, 140);
        });
        document.getElementById('graphRelation').addEventListener('change', e => { this.relation = e.target.value; this.interacted = false; this.render(); });
        document.getElementById('graphRefresh').addEventListener('click', () => this.load());
        document.getElementById('graphMotion').addEventListener('click', () => {
            if (this._usesReduced()) { this.allowMotion = true; this.paused = false; }
            else this.paused = !this.paused;
            this._motionButton(); this._reheat();
        });
        document.getElementById('graphZoomIn').addEventListener('click', () => this.zoom(1.22));
        document.getElementById('graphZoomOut').addEventListener('click', () => this.zoom(1 / 1.22));
        document.getElementById('graphFit').addEventListener('click', () => { this.interacted = true; this.fit(); });
        document.getElementById('graphReset').addEventListener('click', () => { this.models.clear(); this.interacted = false; this.render(); });
        document.getElementById('graphPeopleList').addEventListener('click', e => {
            const button = e.target.closest('[data-person-id]'); if (button) this.select(Number(button.dataset.personId), true);
        });
        document.getElementById('graphDetail').addEventListener('click', e => {
            if (e.target.closest('[data-graph-focus]')) this.focus(this.selectedId);
            if (e.target.closest('[data-graph-clear]')) this.select(null);
        });
        this.svg.addEventListener('pointerdown', e => this._pointerDown(e));
        this.svg.addEventListener('pointermove', e => this._pointerMove(e));
        this.svg.addEventListener('pointerup', e => this._pointerUp(e));
        this.svg.addEventListener('pointercancel', e => this._pointerUp(e, true));
        this.svg.addEventListener('lostpointercapture', e => this._pointerUp(e, true));
        this.svg.addEventListener('pointerleave', () => { if (!this.drag) { this.hoverId = null; this._highlight(); this.hideTooltip(); } });
        this.svg.addEventListener('wheel', e => { e.preventDefault(); this.zoom(Math.exp(-e.deltaY * .0015), this._screen(e)); }, { passive: false });
        this.svg.addEventListener('dblclick', e => { if (!e.target.closest('[data-node-id]')) this.fit(); });
        this.svg.addEventListener('focusin', e => { const n = e.target.closest('[data-node-id]'); if (n) { this.hoverId = Number(n.dataset.nodeId) || null; this._highlight(); } });
        this.svg.addEventListener('focusout', () => { this.hoverId = null; this._highlight(); });
        this.svg.addEventListener('keydown', e => {
            if (e.key === 'Escape') { this.select(null); return; }
            const g = e.target.closest('[data-node-id]'); if (!g) return;
            const id = Number(g.dataset.nodeId);
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.select(id || null); }
            const delta = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
            const n = this.models.get(id);
            if (delta && n && id) { e.preventDefault(); n.pinned = true; n.x += delta[0] * 15 / this.camera.k; n.y += delta[1] * 15 / this.camera.k; this.interacted = true; this._paint(); }
        });
    },
    async load() {
        const button = document.getElementById('graphRefresh'); button.disabled = true;
        this._status('读取数据…');
        try {
            const res = await API.relationships.get();
            this.setData(res.data || {});
            this.render();
        } catch (e) {
            this._status('加载失败，请点击刷新重试');
            if (!this.people.length) { const empty = document.getElementById('graphEmpty'); empty.hidden = false; empty.textContent = '关系数据暂时无法读取，请点击右上角刷新重试。'; }
            Toast.error('加载关系数据失败');
        } finally { button.disabled = false; }
    },
    setData(data) {
        const raw = Array.isArray(data.nodes) ? data.nodes : [];
        const seen = new Set();
        this.me = raw.find(n => n.type === 'me') || { id: 0, name: '我' };
        this.people = raw.filter(n => n.type === 'person' && Number.isInteger(Number(n.id)) && Number(n.id) > 0).filter(n => {
            const id = Number(n.id); if (seen.has(id)) return false; seen.add(id); return true;
        }).map(n => ({ ...n, id: Number(n.id), name: String(n.name || '未命名'), relationship: String(n.relationship || '').trim(), workload: this._value(n, 'workload'), results: this._value(n, 'results') }));
        this.edges = (Array.isArray(data.edges) ? data.edges : []).filter(e => Number(e.source) === 0 && seen.has(Number(e.target)) && (this._value(e, 'workload') > 0 || this._value(e, 'results') > 0));
        this.connected = new Set(this.edges.map(e => Number(e.target)));
        this.groups = [...new Set(this.people.map(n => this._relation(n)))].sort((a, b) => a.localeCompare(b, 'zh-CN'));
        if (!this.people.some(n => n.id === this.selectedId)) this.selectedId = null;
        if (this.relation && !this.groups.includes(this.relation)) this.relation = '';
        const select = document.getElementById('graphRelation');
        if (select) { select.innerHTML = '<option value="">全部关系</option>' + this.groups.map(r => `<option value="${escapeHtml(r)}">${escapeHtml(r)}</option>`).join(''); select.value = this.relation; }
    },
    _value(n, field = this.mode) { const v = Number(n[field]); return Number.isFinite(v) ? Math.max(0, v) : 0; },
    _relation(n) { return n.relationship || '未填写关系'; },
    _colors(n) {
        const index = this.groups?.indexOf(this._relation(n)) ?? 0;
        return this.palette[Math.max(0, index) % this.palette.length];
    },
    _initials(name) {
        if (/^[a-z\s.-]+$/i.test(name)) { const words = name.trim().split(/\s+/); return (words[0][0] + (words.length > 1 ? words[words.length - 1][0] : (words[0][1] || ''))).toUpperCase(); }
        return Array.from(name.replace(/(?:老师|医生|教授|博士|同学|研究员)$/u, '') || name).slice(-2).join('');
    },
    visiblePeople() {
        return this.people.filter(n => (!this.query || `${n.name} ${n.relationship}`.toLocaleLowerCase().includes(this.query)) && (!this.relation || this._relation(n) === this.relation))
            .sort((a, b) => this._value(b) - this._value(a) || a.name.localeCompare(b.name, 'zh-CN') || a.id - b.id);
    },
    switchMode(mode) {
        if (!['workload', 'results'].includes(mode)) return;
        this.mode = mode;
        for (const [id, value] of [['toggleWorkload', 'workload'], ['toggleResults', 'results']]) {
            const button = document.getElementById(id); button.classList.toggle('active', mode === value); button.setAttribute('aria-pressed', String(mode === value));
        }
        this.render();
    },
    _resize() {
        const rect = this.stage.getBoundingClientRect();
        const size = { w: Math.max(280, rect.width), h: Math.max(320, rect.height) };
        if (Math.abs(size.w - this.size.w) < 1 && Math.abs(size.h - this.size.h) < 1) return;
        const old = this.size; this.size = size;
        for (const n of this.models.values()) { n.x *= size.w / old.w; n.y *= size.h / old.h; }
        this.interacted = false;
        if (this.people.length) this.render();
        else this.svg.setAttribute('viewBox', `0 0 ${size.w} ${size.h}`);
    },
    _seed(people) {
        const max = Math.max(1, ...this.people.map(n => this._value(n)));
        this.simNodes = people.map((p, i) => {
            const angle = i * 2.3999632297 - Math.PI / 2;
            const distance = 114 + Math.sqrt(i) * 39;
            const tx = this.size.w / 2 + Math.cos(angle) * distance;
            const ty = this.size.h / 2 + Math.sin(angle) * distance * .85;
            const targetRadius = 22 + 18 * Math.sqrt(this._value(p) / max);
            const old = this.models.get(p.id);
            const node = { ...p, x: old?.x ?? tx, y: old?.y ?? ty, vx: 0, vy: 0, tx, ty, radius: old?.radius ?? targetRadius, targetRadius, pinned: old?.pinned || false };
            this.models.set(p.id, node); return node;
        });
    },
    _step(alpha) {
        const ns = this.simNodes, cx = this.size.w / 2, cy = this.size.h / 2;
        for (const n of ns) {
            n.radius += (n.targetRadius - n.radius) * .22;
            if (!n.pinned) { n.vx += (n.tx - n.x) * .025 * alpha; n.vy += (n.ty - n.y) * .025 * alpha; }
        }
        for (let i = 0; i < ns.length; i++) {
            const a = ns[i];
            for (let j = i + 1; j < ns.length; j++) {
                const b = ns[j]; let dx = a.x - b.x, dy = a.y - b.y;
                if (Math.abs(dx) + Math.abs(dy) < .01) { dx = .1 + (i % 3) * .03; dy = .11; }
                const d = Math.hypot(dx, dy);
                const gap = a.targetRadius + b.targetRadius + 44;
                const force = (d < gap ? (gap - d) * .23 : 330 / (d * d)) * alpha;
                const fx = dx / d * force, fy = dy / d * force;
                if (!a.pinned) { a.vx += fx * (b.pinned ? 2 : 1); a.vy += fy * (b.pinned ? 2 : 1); }
                if (!b.pinned) { b.vx -= fx * (a.pinned ? 2 : 1); b.vy -= fy * (a.pinned ? 2 : 1); }
            }
            const dx = a.x - cx, dy = a.y - cy, d = Math.max(1, Math.hypot(dx, dy));
            const safe = a.targetRadius + 76;
            if (d < safe && !a.pinned) { a.vx += dx / d * (safe - d) * .35 * alpha; a.vy += dy / d * (safe - d) * .35 * alpha; }
        }
        for (const n of ns) {
            if (n.pinned) { n.vx = n.vy = 0; continue; }
            n.vx = Math.max(-8, Math.min(8, n.vx * .72)); n.vy = Math.max(-8, Math.min(8, n.vy * .72));
            n.x += n.vx; n.y += n.vy;
        }
    },
    _svg(tag, attrs = {}, text) {
        const e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, String(v));
        if (text !== undefined) e.textContent = text;
        return e;
    },
    render() {
        if (!this.svg) return;
        this._stop(); this.hideTooltip(); this.hoverId = null;
        const people = this.visiblePeople(); this._seed(people);
        if (this.selectedId && !people.some(n => n.id === this.selectedId)) this.selectedId = null;
        this.svg.setAttribute('viewBox', `0 0 ${this.size.w} ${this.size.h}`);
        const defs = this._svg('defs');
        const dot = this._svg('pattern', { id: 'rg-dot', width: 24, height: 24, patternUnits: 'userSpaceOnUse' });
        dot.appendChild(this._svg('circle', { cx: 1, cy: 1, r: .65, fill: '#bac6db', opacity: .45 })); defs.appendChild(dot);
        for (const n of [{ id: 0 }, ...people]) {
            const colors = n.id ? this._colors(n) : ['#646bc7', '#9099eb'];
            const grad = this._svg('linearGradient', { id: `rg-gradient-${n.id}`, x1: 0, y1: 0, x2: 1, y2: 1 });
            grad.append(this._svg('stop', { offset: 0, 'stop-color': colors[1] }), this._svg('stop', { offset: 1, 'stop-color': colors[0] })); defs.appendChild(grad);
        }
        this.viewport = this._svg('g', { class: 'rg-viewport' });
        const orbit = this._svg('g', { fill: 'none', stroke: '#dce3f1', 'stroke-width': 1, 'stroke-dasharray': '3 7', opacity: .5 });
        for (const r of [110, 205, 300]) orbit.appendChild(this._svg('ellipse', { cx: this.size.w / 2, cy: this.size.h / 2, rx: r, ry: r * .85 }));
        this.viewport.appendChild(orbit);
        this.links = [];
        for (const n of this.simNodes.filter(n => this.connected.has(n.id))) {
            const el = this._svg('g', { class: 'rg-link', style: `--rg-accent:${this._colors(n)[0]}`, 'data-link-id': n.id });
            const path = this._svg('path', { class: 'rg-link-path', 'stroke-width': 1.2 + 3 * Math.sqrt(this._value(n) / Math.max(1, ...this.people.map(p => this._value(p)))) });
            if (!this._value(n)) path.setAttribute('stroke-dasharray', '4 5');
            const label = this._svg('text', { class: 'rg-link-label' }, `${this.mode === 'workload' ? '工作记录' : '成果记录'} ${this._value(n)} ${this.mode === 'workload' ? '次' : '条'}`);
            el.append(path, label); this.viewport.appendChild(el); this.links.push({ n, el, path, label });
        }
        for (const n of this.simNodes) this.viewport.appendChild(this._node(n));
        const me = { id: 0, name: '我', radius: 38, x: this.size.w / 2, y: this.size.h / 2 };
        this.centerNode = me;
        this.meElement = this._node(me); this.viewport.appendChild(this.meElement);
        this.svg.replaceChildren(defs, this._svg('rect', { width: '100%', height: '100%', fill: 'url(#rg-dot)' }), this.viewport);
        const empty = document.getElementById('graphEmpty'); empty.hidden = people.length > 0;
        empty.textContent = this.people.length ? '没有匹配的人物，试试其他姓名或关系。' : '还没有人物资料，请先在人物管理中添加。';
        this.meElement.style.opacity = people.length ? '1' : '.25';
        this._list(people); this._detail(); this._legend(people); this._highlight(); this._paint();
        if (!this.interacted) this.fit();
        this._reheat();
    },
    _node(n) {
        const isMe = n.id === 0, color = isMe ? '#6971ca' : this._colors(n)[0];
        const g = this._svg('g', { class: `rg-node${isMe ? ' rg-node-me' : ''}`, 'data-node-id': n.id, tabindex: 0, role: 'button', 'aria-label': isMe ? '我，返回关系概览' : `${n.name}，${this._relation(n)}，工作记录${n.workload}次，成果${n.results}条`, style: `--rg-accent:${color}` });
        g.appendChild(this._svg('title', {}, isMe ? String(this.me?.subtitle || '我') : n.name));
        n.halo = this._svg('circle', { class: 'rg-node-halo', r: n.radius + 9 });
        n.disc = this._svg('circle', { class: 'rg-node-disc', r: n.radius, fill: `url(#rg-gradient-${n.id})` });
        n.initial = this._svg('text', { class: 'rg-node-initial', y: 5, 'font-size': isMe ? 20 : 14 }, isMe ? '我' : this._initials(n.name));
        n.label = this._svg('text', { class: 'rg-node-name', y: n.radius + 20 });
        const name = Array.from(isMe ? String(this.me?.subtitle || '关系网络') : n.name);
        for (let i = 0; i < Math.min(2, Math.ceil(name.length / 8)); i++) n.label.appendChild(this._svg('tspan', { x: 0, dy: i ? 15 : 0 }, name.slice(i * 8, i * 8 + 8).join('') + (i === 1 && name.length > 16 ? '…' : '')));
        g.append(n.halo, n.disc, n.initial, n.label); n.el = g;
        g.setAttribute('transform', `translate(${n.x} ${n.y})`); return g;
    },
    _paint() {
        for (const n of this.simNodes || []) {
            n.el?.setAttribute('transform', `translate(${n.x.toFixed(2)} ${n.y.toFixed(2)})`);
            n.disc?.setAttribute('r', n.radius.toFixed(2)); n.halo?.setAttribute('r', (n.radius + 9).toFixed(2)); n.label?.setAttribute('y', (n.radius + 20).toFixed(2));
        }
        const cx = this.size.w / 2, cy = this.size.h / 2;
        for (const { n, path, label } of this.links) {
            const dx = n.x - cx, dy = n.y - cy, distance = Math.max(1, Math.hypot(dx, dy));
            const ax = cx + dx / distance * 42, ay = cy + dy / distance * 42;
            const bx = n.x - dx / distance * (n.radius + 3), by = n.y - dy / distance * (n.radius + 3);
            const curve = (n.id % 2 ? 1 : -1) * Math.min(28, distance * .12);
            const mx = (ax + bx) / 2 - dy / distance * curve, my = (ay + by) / 2 + dx / distance * curve;
            path.setAttribute('d', `M ${ax} ${ay} Q ${mx} ${my} ${bx} ${by}`);
            label.setAttribute('x', (ax + bx) / 2 - dy / distance * curve / 2); label.setAttribute('y', (ay + by) / 2 + dx / distance * curve / 2 - 6);
        }
    },
    _reheat() {
        this._stop(); if (!this.simNodes) return;
        this.alpha = .92;
        if (this._usesReduced()) {
            for (let i = 0; i < 110; i++) this._step(Math.max(.15, .92 * Math.pow(.958, i)));
            this.alpha = 0; this._paint(); if (!this.interacted) this.fit(); this._status('静态布局');
        } else if (this.paused) {
            for (const n of this.simNodes) n.radius = n.targetRadius;
            this._paint(); this._status('已暂停');
        } else if (!this.simNodes.length) { this.alpha = 0; this._status('暂无匹配人物'); }
        else { this._status('正在自然展开…'); this.lastFrame = 0; this._schedule(); }
    },
    _usesReduced() { return this.reduced && !this.allowMotion; },
    _schedule() { if (!this.frame && !document.hidden && !this.paused && !this._usesReduced()) this.frame = requestAnimationFrame(t => this._tick(t)); },
    _tick(time) {
        this.frame = 0;
        if (document.hidden || this.paused || this._usesReduced()) return;
        if (time - this.lastFrame >= 16) { this.lastFrame = time; this._step(this.alpha); this._paint(); this.alpha *= .958; }
        if (this.alpha > .018) this._schedule();
        else { this.alpha = 0; if (!this.interacted) this.fit(); this._status('布局就绪'); }
    },
    _stop() { if (this.frame) cancelAnimationFrame(this.frame); this.frame = 0; },
    _status(text) { const e = document.getElementById('graphStatus'); if (e) e.textContent = text; },
    _motionButton() {
        const button = document.getElementById('graphMotion');
        button.textContent = this._usesReduced() || this.paused ? '开启动态' : '暂停动态';
        button.title = this._usesReduced() ? '跟随系统偏好默认静态显示，可点击手动开启动态布局' : '暂停或恢复节点自动布局'; button.setAttribute('aria-pressed', String(!this.paused && !this._usesReduced()));
    },
    _list(people) {
        const metric = this.mode === 'workload' ? '次工作记录' : '条成果记录';
        document.getElementById('graphPeopleList').innerHTML = people.length ? people.map(n => `<button type="button" class="rg-person" data-person-id="${n.id}" style="--rg-accent:${this._colors(n)[0]}" aria-label="查看${escapeHtml(n.name)}，${this._value(n)}${metric}"><i class="rg-person-dot"></i><span class="rg-person-info"><strong>${escapeHtml(n.name)}</strong><small>${escapeHtml(this._relation(n))}</small></span><span class="rg-person-value">${this._value(n).toLocaleString('zh-CN')}</span></button>`).join('') : '<p class="rg-help">暂无匹配人物</p>';
        document.getElementById('graphListCount').textContent = `${people.length} 位 · 按${this.mode === 'workload' ? '工作量' : '成果'}排序`;
        const active = people.filter(n => this.connected.has(n.id)).length;
        document.getElementById('graphSummary').innerHTML = `<span><strong>${people.length}</strong>${people.length === this.people.length ? '位人物' : `/ ${this.people.length} 位人物`}</span><span><strong>${active}</strong>位有共同记录</span>`;
    },
    _detail() {
        const n = this.people.find(p => p.id === this.selectedId);
        const container = document.getElementById('graphDetail'); if (!container) return;
        if (!n) { container.style.removeProperty('--rg-accent'); container.innerHTML = `<div class="rg-detail-kicker">关系概览</div><div class="rg-avatar">我</div><h3>${escapeHtml(String(this.me?.subtitle || '以你为中心'))}</h3><p>选择图中的人物，或从列表中找到名字，看看你们的共同投入与成果。</p><div class="rg-detail-actions"><a href="${appUrl('/people')}">人物管理</a></div>`; return; }
        container.style.setProperty('--rg-accent', this._colors(n)[0]);
        const rating = (field, label) => { const value = Math.max(0, Math.min(5, Math.round(Number(n[field]) || 0))); return `<div class="rg-detail-rating"><span>${label}</span><b aria-label="${label}${value}分，共5分">${'●'.repeat(value)}${'○'.repeat(5 - value)}</b></div>`; };
        container.innerHTML = `<div class="rg-detail-kicker">人物详情</div><div class="rg-avatar">${escapeHtml(this._initials(n.name))}</div><h3>${escapeHtml(n.name)}</h3><p>${escapeHtml(this._relation(n))}</p><div class="rg-detail-metrics"><div><strong>${n.workload.toLocaleString('zh-CN')}</strong><span>工作记录 · 次</span></div><div><strong>${n.results.toLocaleString('zh-CN')}</strong><span>成果记录 · 条</span></div></div>${rating('importance', '重要度')}${rating('usefulness', '有用性')}${rating('closeness', '亲近度')}<p>${this.connected.has(n.id) ? '来自与你关联的任务记录，包含已归档任务。' : '目前没有共同工作或成果记录，暂不绘制连线。'}</p><div class="rg-detail-actions"><button type="button" data-graph-focus>定位人物</button><button type="button" data-graph-clear>取消选择</button></div>`;
    },
    _legend(people) {
        const groups = [...new Set(people.map(n => this._relation(n)))];
        document.getElementById('graphLegend').innerHTML = groups.slice(0, 6).map(r => `<span style="--rg-accent:${this._colors({ relationship: r })[0]}"><i></i>${escapeHtml(r)}</span>`).join('') + (groups.length > 6 ? `<span>另 ${groups.length - 6} 种关系 · 可筛选</span>` : '');
    },
    select(id, center = false) {
        this.selectedId = id && this.simNodes.some(n => n.id === id) ? id : null;
        this._detail(); this._highlight(); this.hideTooltip(); if (center && this.selectedId) this.focus(this.selectedId);
    },
    _highlight() {
        const target = this.hoverId || this.selectedId;
        for (const n of this.simNodes || []) {
            n.el?.classList.toggle('is-muted', !!target && n.id !== target);
            n.el?.classList.toggle('is-highlighted', n.id === target);
            n.el?.classList.toggle('is-selected', n.id === this.selectedId);
            n.el?.setAttribute('aria-pressed', String(n.id === this.selectedId));
        }
        for (const link of this.links) { link.el.classList.toggle('is-muted', !!target && link.n.id !== target); link.el.classList.toggle('is-highlighted', link.n.id === target); }
        document.querySelectorAll('#graphPeopleList [data-person-id]').forEach(e => { const selected = Number(e.dataset.personId) === this.selectedId; e.classList.toggle('is-selected', selected); e.setAttribute('aria-pressed', String(selected)); });
    },
    fit() {
        const ns = this.simNodes || [];
        const cx = this.size.w / 2, cy = this.size.h / 2;
        const left = Math.min(cx - 55, ...ns.map(n => n.x - n.targetRadius - 28));
        const right = Math.max(cx + 55, ...ns.map(n => n.x + n.targetRadius + 28));
        const top = Math.min(cy - 55, ...ns.map(n => n.y - n.targetRadius - 18));
        const bottom = Math.max(cy + 65, ...ns.map(n => n.y + n.targetRadius + 46));
        const k = Math.max(.12, Math.min(1.25, (this.size.w - 60) / (right - left), (this.size.h - 135) / (bottom - top)));
        this.camera = { k, x: this.size.w / 2 - (left + right) / 2 * k, y: (this.size.h - 10) / 2 - (top + bottom) / 2 * k }; this._camera();
    },
    focus(id) { const n = this.models.get(id); if (!n) return; const k = Math.max(1, Math.min(1.7, this.camera.k)); this.camera = { k, x: this.size.w / 2 - n.x * k, y: this.size.h / 2 - n.y * k }; this.interacted = true; this._camera(); },
    zoom(factor, point = { x: this.size.w / 2, y: this.size.h / 2 }) {
        const k = Math.max(.12, Math.min(3, this.camera.k * factor));
        const ratio = k / this.camera.k;
        this.camera = { k, x: point.x - (point.x - this.camera.x) * ratio, y: point.y - (point.y - this.camera.y) * ratio }; this.interacted = true; this._camera(); this.hideTooltip();
    },
    _camera() {
        this.viewport?.setAttribute('transform', `translate(${this.camera.x} ${this.camera.y}) scale(${this.camera.k})`);
        const label = document.getElementById('graphZoomLabel'); if (label) label.textContent = `${Math.round(this.camera.k * 100)}%`;
        for (const n of [...(this.simNodes || []), this.centerNode].filter(Boolean)) {
            if (n.label) n.label.style.fontSize = `${Math.min(26, Math.max(12, 10.5 / this.camera.k))}px`;
            if (n.initial) n.initial.style.fontSize = `${Math.min(20, Math.max(14, 12 / this.camera.k))}px`;
        }
    },
    _screen(e) { const p = new DOMPoint(e.clientX, e.clientY).matrixTransform(this.svg.getScreenCTM().inverse()); return { x: p.x, y: p.y }; },
    _world(p) { return { x: (p.x - this.camera.x) / this.camera.k, y: (p.y - this.camera.y) / this.camera.k }; },
    _pointerDown(e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        e.preventDefault(); this.svg.setPointerCapture(e.pointerId); this.pointers.set(e.pointerId, this._screen(e)); this.hideTooltip(); this.interacted = true;
        if (this.pointers.size === 2) {
            const previous = this.drag && this.models.get(this.drag.id);
            if (previous) { previous.pinned = this.drag.wasPinned; previous.x = this.drag.original.x; previous.y = this.drag.original.y; this._paint(); }
            const [a, b] = [...this.pointers.values()]; const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
            this.gesture = { distance: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)), mid, camera: { ...this.camera } }; this.drag = null; return;
        }
        const target = e.target.closest('[data-node-id]'); const id = target ? Number(target.dataset.nodeId) : 0;
        const point = this._screen(e), n = this.models.get(id), world = this._world(point);
        this.drag = { pointerId: e.pointerId, id, start: point, camera: { ...this.camera }, offset: n ? { x: n.x - world.x, y: n.y - world.y } : null, original: n ? { x: n.x, y: n.y } : null, wasPinned: n?.pinned || false, moved: false };
        if (n) { n.pinned = true; n.vx = n.vy = 0; }
        this.svg.classList.add('is-dragging');
    },
    _pointerMove(e) {
        if (!this.pointers.has(e.pointerId)) {
            if (e.pointerType === 'touch') return;
            const g = e.target.closest('[data-node-id]'), id = g ? Number(g.dataset.nodeId) : null;
            if (this.hoverId !== id) { this.hoverId = id || null; this._highlight(); }
            if (id) this.showTooltip(e, this.models.get(id)); else this.hideTooltip(); return;
        }
        const point = this._screen(e); this.pointers.set(e.pointerId, point);
        if (this.gesture && this.pointers.size === 2) {
            const [a, b] = [...this.pointers.values()], g = this.gesture;
            const k = Math.max(.12, Math.min(3, g.camera.k * Math.hypot(a.x - b.x, a.y - b.y) / g.distance));
            const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
            this.camera = { k, x: mx - (g.mid.x - g.camera.x) / g.camera.k * k, y: my - (g.mid.y - g.camera.y) / g.camera.k * k }; this._camera(); return;
        }
        if (!this.drag || this.drag.pointerId !== e.pointerId) return;
        const d = this.drag; d.moved ||= Math.hypot(point.x - d.start.x, point.y - d.start.y) > 4;
        const n = this.models.get(d.id);
        if (n) { const w = this._world(point); n.x = w.x + d.offset.x; n.y = w.y + d.offset.y; this._paint(); }
        else { this.camera.x = d.camera.x + point.x - d.start.x; this.camera.y = d.camera.y + point.y - d.start.y; this._camera(); }
    },
    _pointerUp(e, cancelled = false) {
        if (!this.pointers.has(e.pointerId)) return;
        const d = this.drag; this.pointers.delete(e.pointerId);
        const n = d && this.models.get(d.id);
        if (n && (!d.moved || cancelled)) n.pinned = d.wasPinned;
        if (this.svg.hasPointerCapture(e.pointerId)) this.svg.releasePointerCapture(e.pointerId);
        if (!this.gesture && d && d.pointerId === e.pointerId && !d.moved && !cancelled) this.select(d.id === this.selectedId ? null : d.id || null);
        if (this.gesture && this.pointers.size === 1) {
            const [pointerId, start] = [...this.pointers.entries()][0]; this.gesture = null; this.drag = { pointerId, id: 0, start, camera: { ...this.camera }, moved: true }; return;
        }
        if (!this.pointers.size) {
            this.gesture = null; this.drag = null; this.svg.classList.remove('is-dragging');
            if (n && d.moved) this._reheat();
        }
    },
    showTooltip(e, n) {
        if (!n || this.drag) return;
        this.tooltip.innerHTML = `<strong>${escapeHtml(n.name)}</strong><span>${escapeHtml(this._relation(n))} · ${this.mode === 'workload' ? '工作记录' : '成果记录'} ${this._value(n)} ${this.mode === 'workload' ? '次' : '条'}</span>`;
        this.tooltip.hidden = false;
        const box = this.stage.getBoundingClientRect(), tip = this.tooltip.getBoundingClientRect();
        this.tooltip.style.left = Math.max(10, Math.min(box.width - tip.width - 10, e.clientX - box.left + 14)) + 'px';
        this.tooltip.style.top = Math.max(48, Math.min(box.height - tip.height - 60, e.clientY - box.top - tip.height - 12)) + 'px';
    },
    hideTooltip() { if (this.tooltip) this.tooltip.hidden = true; }
};
