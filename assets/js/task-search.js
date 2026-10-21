/** Shared, local task search. No requests or persistent search history. */
const TaskSearch = {
    tokens(query) { return [...new Set(String(query || '').trim().toLowerCase().split(/\s+/).filter(Boolean))]; },

    fields(task) {
        return [task.name, task.description, task.location,
            ...(task.people || []).map(p => p.name), ...(task.tags || []).map(t => t.name),
            ...(task.results || []).map(r => r.name)].map(s => String(s || '').toLowerCase());
    },

    matches(task, terms) {
        if (!terms.length) return true;
        const fields = this.fields(task);
        return terms.every(term => fields.some(field => field.includes(term)));
    },

    // Match plain text before escaping; never replace inside HTML or attributes.
    highlight(text, terms) {
        text = String(text || '');
        if (!terms.length) return escapeHtml(text);
        const pattern = [...terms].sort((a, b) => b.length - a.length)
            .map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
        const re = new RegExp(pattern, 'gi');
        let html = '', start = 0;
        for (const match of text.matchAll(re)) {
            html += escapeHtml(text.slice(start, match.index)) + '<mark class="task-search-mark">' + escapeHtml(match[0]) + '</mark>';
            start = match.index + match[0].length;
        }
        return html + escapeHtml(text.slice(start));
    },

    context(task, terms) {
        if (!terms.length) return '';
        const fields = [['描述', task.description], ['地点', task.location],
            ['人物', (task.people || []).map(p => p.name).join('、')],
            ['成果', (task.results || []).map(r => r.name).join('、')]];
        return fields.filter(([, text]) => text && terms.some(t => String(text).toLowerCase().includes(t)))
            .slice(0, 2).map(([label, value]) => {
                const text = String(value), lower = text.toLowerCase();
                const first = Math.min(...terms.map(t => lower.indexOf(t)).filter(i => i >= 0));
                const start = Math.max(0, first - 28), end = Math.min(text.length, start + 130);
                const excerpt = (start ? '…' : '') + text.slice(start, end) + (end < text.length ? '…' : '');
                return `<div class="task-search-context"><span>${label}：</span>${this.highlight(excerpt, terms)}</div>`;
            }).join('');
    },

    mount(host, { id, placeholder, controls, onChange }) {
        host.innerHTML = `<label class="task-search-label" for="${id}">搜索任务</label>
            <div class="task-search-field"><span aria-hidden="true">⌕</span>
                <input type="search" id="${id}" placeholder="${placeholder}" maxlength="160" autocomplete="off" enterkeyhint="search" aria-controls="${controls}" aria-describedby="${id}Help">
                <button type="button" class="task-search-clear" aria-label="清空搜索" title="清空搜索（Esc）" hidden>×</button>
            </div>
            <p class="task-search-help" id="${id}Help">支持名称、描述、人物、标签、地点和成果；多个词用空格分开。</p>
            <p class="task-search-status" role="status" aria-live="polite" aria-atomic="true">正在加载任务…</p>`;
        const input = host.querySelector('input'), clear = host.querySelector('button'), status = host.querySelector('[role="status"]');
        let timer, composing = false;
        const notify = () => { clearTimeout(timer); clear.hidden = !input.value; onChange(); };
        input.addEventListener('input', e => {
            clear.hidden = !input.value;
            if (composing || e.isComposing) return;
            clearTimeout(timer); timer = setTimeout(notify, 120);
        });
        input.addEventListener('compositionstart', () => { composing = true; clearTimeout(timer); });
        input.addEventListener('compositionend', () => { composing = false; notify(); });
        input.addEventListener('keydown', e => {
            if (composing || e.isComposing) return;
            if (e.key === 'Escape' && input.value) { e.preventDefault(); e.stopPropagation(); input.value = ''; notify(); }
            if (e.key === 'Enter') { e.preventDefault(); notify(); }
        });
        clear.addEventListener('click', () => { input.value = ''; notify(); input.focus(); });
        return { terms: () => this.tokens(input.value), query: () => input.value.trim(), clear: () => { input.value = ''; notify(); },
            status: message => { status.textContent = message; } };
    }
};
