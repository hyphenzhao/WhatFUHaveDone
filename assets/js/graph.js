/**
 * SVG Relationship Graph
 */
const Graph = {
    svg: null,
    tooltip: null,
    data: null,
    mode: 'workload', // 'workload' | 'results'

    async init() {
        this.svg = document.getElementById('graphSvg');
        this.tooltip = document.getElementById('graphTooltip');
        await this.load();
        this.render();
    },

    async load() {
        try {
            const res = await API.relationships.get();
            this.data = res.data || { nodes: [], edges: [] };
        } catch (e) {
            this.data = { nodes: [], edges: [] };
            Toast.error('加载关系数据失败');
        }
    },

    switchMode(mode) {
        this.mode = mode;
        document.getElementById('toggleWorkload').classList.toggle('active', mode === 'workload');
        document.getElementById('toggleResults').classList.toggle('active', mode === 'results');
        this.render();
    },

    render() {
        if (!this.svg || !this.data) return;
        const svg = this.svg;
        const W = 800, H = 500;
        const cx = W / 2, cy = H / 2;

        let html = '';

        // Background
        html += `<rect width="${W}" height="${H}" fill="#f8fafc" rx="8"/>`;

        const nodes = this.data.nodes || [];
        const edges = this.data.edges || [];
        const meNode = nodes.find(n => n.type === 'me');
        const peopleNodes = nodes.filter(n => n.type === 'person');

        if (peopleNodes.length === 0) {
            html += `<text x="${cx}" y="${cy}" text-anchor="middle" fill="#94a3b8" font-size="14">暂无关系数据</text>`;
            svg.innerHTML = html;
            return;
        }

        // Get max value for scaling
        const maxVal = Math.max(1, ...peopleNodes.map(n => this.mode === 'workload' ? n.workload : n.results));
        const minR = 18, maxR = 55;

        // Layout: circle around center
        const centerR = 160;
        const angleStep = (2 * Math.PI) / peopleNodes.length;

        // Draw edges first (behind nodes)
        peopleNodes.forEach((person, i) => {
            const val = this.mode === 'workload' ? person.workload : person.results;
            const angle = i * angleStep - Math.PI / 2;
            const px = cx + centerR * Math.cos(angle);
            const py = cy + centerR * Math.sin(angle);

            if (val > 0) {
                const sw = Math.max(1, Math.min(6, (val / maxVal) * 6));
                html += `<line x1="${cx}" y1="${cy}" x2="${px}" y2="${py}" class="graph-edge" stroke-width="${sw}" stroke="#cbd5e1"/>`;

                // Label on the line (midpoint)
                const mx = (cx + px) / 2, my = (cy + py) / 2;
                const label = this.mode === 'workload'
                    ? `💪${person.workload} 🏆${person.results}`
                    : `🏆${person.results} 💪${person.workload}`;
                html += `<text x="${mx}" y="${my - 8}" class="graph-label" font-size="9">${label}</text>`;
            }
        });

        // Draw person nodes
        peopleNodes.forEach((person, i) => {
            const val = this.mode === 'workload' ? person.workload : person.results;
            const r = val > 0 ? minR + (val / maxVal) * (maxR - minR) : minR;
            const angle = i * angleStep - Math.PI / 2;
            const px = cx + centerR * Math.cos(angle);
            const py = cy + centerR * Math.sin(angle);

            html += `<circle cx="${px}" cy="${py}" r="${r}" class="graph-node-person"
                data-id="${person.id}" data-name="${escapeHtml(person.name)}"
                data-workload="${person.workload}" data-results="${person.results}"
                data-relationship="${escapeHtml(person.relationship || '')}"
                onmouseenter="Graph.showTooltip(event, this)" onmouseleave="Graph.hideTooltip()"
                fill="${this._getColor(person)}" stroke="#fff" stroke-width="2"/>`;

            // Name label
            const fontSize = Math.max(9, Math.min(12, r * 0.55));
            html += `<text x="${px}" y="${py + r + 14}" text-anchor="middle" font-size="${fontSize}" fill="#475569" font-weight="500">${escapeHtml(person.name)}</text>`;
        });

        // Draw "Me" node (center)
        const meR = 28;
        html += `<circle cx="${cx}" cy="${cy}" r="${meR}" class="graph-node-me" fill="#3B82F6" stroke="#fff" stroke-width="3"/>`;
        html += `<text x="${cx}" y="${cy + 4}" text-anchor="middle" fill="#fff" font-size="14" font-weight="700">我</text>`;
        if (meNode && meNode.subtitle) {
            html += `<text x="${cx}" y="${cy + meR + 14}" text-anchor="middle" fill="#94a3b8" font-size="10">${escapeHtml(meNode.subtitle)}</text>`;
        }

        // Legend
        html += `<text x="20" y="485" font-size="10" fill="#94a3b8">圆圈大小 = ${this.mode === 'workload' ? '工作量' : '成果量'}</text>`;

        svg.innerHTML = html;
    },

    _getColor(person) {
        const val = this.mode === 'workload' ? person.workload : person.results;
        if (val === 0) return '#e2e8f0';
        // Color gradient from light to saturated based on value
        const colors = ['#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8'];
        const maxNodes = Math.max(1, ...this.data.nodes.filter(n=>n.type==='person').map(n=>n.workload||n.results||1));
        const idx = Math.min(colors.length - 1, Math.floor((val / Math.max(1, maxNodes)) * colors.length));
        return colors[idx] || colors[0];
    },

    showTooltip(event, circle) {
        const name = circle.dataset.name;
        const workload = circle.dataset.workload;
        const results = circle.dataset.results;
        const relationship = circle.dataset.relationship;
        this.tooltip.innerHTML = `
            <strong>${escapeHtml(name)}</strong>
            ${relationship ? `<div style="color:var(--color-text-secondary);">${escapeHtml(relationship)}</div>` : ''}
            <div>💪 工作量: ${workload}</div>
            <div>🏆 成果: ${results}</div>
        `;
        this.tooltip.style.display = 'block';
        this.tooltip.style.left = (event.pageX + 12) + 'px';
        this.tooltip.style.top = (event.pageY - 10) + 'px';
    },

    hideTooltip() {
        this.tooltip.style.display = 'none';
    },
};
