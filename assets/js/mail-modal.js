/**
 * Shared mail UI pieces (loaded on every page):
 *   MailUI.*        — badges, body rendering (sandboxed iframe), analysis card
 *   openMailModal() — tabbed modal: 🤖 AI 分析 (+ shared chat) | 📧 邮件
 */
const MailUI = {
    catLabels: { work: '工作', personal: '个人', notification: '通知', marketing: '营销', spam: '垃圾', other: '其他' },

    priBadge(a) {
        if (!a || a.status === 'error') return '';
        const p = a.priority || 3;
        return `<span class="mail-badge mail-pri mail-pri-${p}" title="优先级 ${p}（1 最高）">P${p}</span>`;
    },
    relBadge(a) {
        if (!a || a.status === 'error') return '';
        const r = a.relevance || 0;
        const cls = r >= 70 ? 'hi' : r >= 40 ? 'mid' : 'lo';
        return `<span class="mail-badge mail-rel mail-rel-${cls}" title="与你的相关度">相关 ${r}%</span>`;
    },
    catBadge(a) {
        if (!a || !a.category || a.status === 'error') return '';
        return `<span class="mail-badge mail-cat">${escapeHtml(this.catLabels[a.category] || a.category)}</span>`;
    },
    fmtDate(s) {
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        const today = new Date();
        const same = d.toDateString() === today.toDateString();
        const pad = n => String(n).padStart(2, '0');
        return same ? `${pad(d.getHours())}:${pad(d.getMinutes())}` : `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    fmtSize(b) { b = b || 0; return b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; },

    /* Highlighting is a reading-app style highlighter over PASSAGES only
       (see addHighlight / markHtml). mail_messages.is_highlighted — which drives
       the 🖍 list marker, the 🖍 filter and search_emails(highlighted_only) — is
       derived from "has at least one passage" by mail_sync_highlight_flag(); it
       is not writable on its own. */
    addr(a) { return a ? escapeHtml(a.name ? `${a.name} <${a.email}>` : (a.email || '')) : ''; },
    md(text) { return (typeof AiChat !== 'undefined' && AiChat._md) ? AiChat._md(text) : `<p>${escapeHtml(text || '')}</p>`; },

    /** Whitespace that HTML treats as interchangeable, including the CJK ideographic space. */
    _WS: /[\s 　]+/g,

    /**
     * Locate every stored snippet inside a flat string.
     *
     * Matching is whitespace-INSENSITIVE: the sender's HTML wraps lines wherever
     * it likes, and the assistant copies from the text rendering, so an exact
     * compare misses passages that are plainly there. We search a normalised
     * copy and map the hit back to offsets in the original.
     *
     * @return {{s:number,e:number,hid:string}[]} inclusive offsets into `flat`,
     *         sorted and non-overlapping (first match wins).
     */
    _findRanges(flat, highlights) {
        if (!highlights || !highlights.length || !flat) return [];
        let norm = '';
        const map = [];
        let prevSpace = false;
        for (let i = 0; i < flat.length; i++) {
            const ch = flat[i];
            if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r' || ch === ' ' || ch === '　') {
                if (prevSpace) continue;
                norm += ' '; map.push(i); prevSpace = true;
            } else {
                norm += ch; map.push(i); prevSpace = false;
            }
        }
        const hits = [];
        for (const h of highlights) {
            const needle = String(h.snippet || '').replace(this._WS, ' ').trim();
            if (needle.length < 2) continue;
            let from = 0, idx;
            while ((idx = norm.indexOf(needle, from)) !== -1) {
                hits.push({ s: map[idx], e: map[idx + needle.length - 1], hid: String(h.id) });
                from = idx + needle.length;
            }
        }
        hits.sort((a, b) => a.s - b.s || b.e - a.e);
        const kept = [];
        let lastEnd = -1;
        for (const r of hits) { if (r.s > lastEnd) { kept.push(r); lastEnd = r.e; } }
        return kept;
    },

    /**
     * Wrap every stored snippet in <mark> by rewriting TEXT NODES of a detached DOM —
     * never by string-replacing in the HTML, which would corrupt tags and attributes.
     * A passage may span several elements, so matches are found against the
     * concatenated text and then split back across the nodes they cover.
     * @return {{html:string, found:number}}
     */
    markHtml(html, highlights) {
        if (!highlights || !highlights.length) return { html, found: 0 };
        let doc;
        try { doc = new DOMParser().parseFromString(html, 'text/html'); }
        catch (e) { return { html, found: 0 }; }
        if (!doc || !doc.body) return { html, found: 0 };

        // Flatten the body's text nodes, remembering where each one starts.
        // <style>/<script> text is markup, not prose — marking it would break the page.
        const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
        const chunks = [];
        let n, total = 0;
        while ((n = walker.nextNode())) {
            const p = n.parentNode;
            if (p && p.closest && p.closest('script,style,noscript,mark')) continue;
            const t = n.nodeValue || '';
            if (!t) continue;
            chunks.push({ node: n, start: total, text: t });
            total += t.length;
        }
        if (!chunks.length) return { html, found: 0 };

        const ranges = this._findRanges(chunks.map(c => c.text).join(''), highlights);
        if (!ranges.length) return { html: doc.body.innerHTML, found: 0 };

        // Group by node first, so a node holding two matches is still replaced once.
        const perNode = new Map();
        for (const r of ranges) {
            for (let i = 0; i < chunks.length; i++) {
                const c = chunks[i], cs = c.start, ce = cs + c.text.length;
                if (ce <= r.s || cs > r.e) continue;
                const from = Math.max(r.s, cs) - cs;
                const to = Math.min(r.e, ce - 1) - cs + 1;
                if (to <= from) continue;
                if (!perNode.has(i)) perNode.set(i, []);
                perNode.get(i).push({ from, to, hid: r.hid });
            }
        }
        for (const [i, segs] of perNode) {
            const c = chunks[i];
            if (!c.node.parentNode) continue;
            segs.sort((a, b) => a.from - b.from);
            const frag = doc.createDocumentFragment();
            let pos = 0;
            for (const seg of segs) {
                if (seg.from > pos) frag.appendChild(doc.createTextNode(c.text.slice(pos, seg.from)));
                const mk = doc.createElement('mark');
                mk.className = 'mail-mark';
                mk.setAttribute('data-hl', seg.hid);
                mk.textContent = c.text.slice(seg.from, seg.to);
                frag.appendChild(mk);
                pos = seg.to;
            }
            if (pos < c.text.length) frag.appendChild(doc.createTextNode(c.text.slice(pos)));
            c.node.parentNode.replaceChild(frag, c.node);
        }
        return { html: doc.body.innerHTML, found: ranges.length };
    },

    /** Same idea for the plain-text body: find in the raw text, escape around the marks. */
    markText(text, highlights) {
        text = String(text || '');
        const ranges = this._findRanges(text, highlights);
        if (!ranges.length) return escapeHtml(text);
        let out = '', pos = 0;
        for (const r of ranges) {
            if (r.s > pos) out += escapeHtml(text.slice(pos, r.s));
            out += `<mark class="mail-mark" data-hl="${r.hid}">${escapeHtml(text.slice(r.s, r.e + 1))}</mark>`;
            pos = r.e + 1;
        }
        return out + escapeHtml(text.slice(pos));
    },

    /** Render the message body into a container (sandboxed iframe for HTML, <pre> for text). */
    renderBody(msg, container, opts = {}) {
        const allowRemote = !!opts.allowRemote;
        const hl = msg.highlights || [];
        if (msg.body_html && msg.body_html.trim()) {
            const imgSrc = allowRemote ? '*' : "data: /api/attachments/";
            const csp = `default-src 'none'; img-src ${imgSrc}; style-src 'unsafe-inline'; font-src data:;`;
            const marked = this.markHtml(msg.body_html, hl);
            const doc = `<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank">` +
                `<style>body{margin:12px;font-family:-apple-system,Segoe UI,Roboto,'PingFang SC','Microsoft YaHei',sans-serif;font-size:14px;line-height:1.6;color:#1f2937;word-break:break-word;}img{max-width:100%;height:auto;}table{max-width:100%;}pre{white-space:pre-wrap;}` +
                `mark.mail-mark{background:#fde68a;color:inherit;padding:0 1px;border-radius:2px;box-shadow:0 0 0 1px rgba(245,158,11,.35);}</style></head><body>${marked.html}</body></html>`;
            container.innerHTML = `<div class="mail-body-tools"><span class="mail-hint mail-sel-hint">选中正文文字即可用荧光笔高亮</span><span style="flex:1"></span><button class="btn btn-ghost btn-sm mail-remote-btn">${allowRemote ? '🔒 隐藏远程图片' : '🖼️ 加载远程图片'}</button></div><iframe class="mail-frame" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>`;
            const frame = container.querySelector('.mail-frame');
            frame.addEventListener('load', () => {
                try {
                    const h = frame.contentDocument.documentElement.scrollHeight;
                    frame.style.height = Math.min(Math.max(h + 24, 120), opts.maxHeight || 1400) + 'px';
                    this._bindSelection(frame.contentDocument, msg, frame);
                } catch (e) {}
            });
            frame.srcdoc = doc;
            container.querySelector('.mail-remote-btn').addEventListener('click', () => this.renderBody(msg, container, { ...opts, allowRemote: !allowRemote }));
        } else {
            const text = msg.body_text || msg.snippet || '(无正文)';
            container.innerHTML = `<div class="mail-body-tools"><span class="mail-hint mail-sel-hint">选中正文文字即可用荧光笔高亮</span></div><pre class="mail-text-body">${this.markText(text, hl)}</pre>`;
            this._bindSelection(document, msg, container.querySelector('.mail-text-body'));
        }
        this.renderExcerpts(msg, container);
    },

    /** Passages that could not be located in the body still need to be visible. */
    renderExcerpts(msg, container) {
        const hl = msg.highlights || [];
        let box = container.parentNode && container.parentNode.querySelector('.mail-excerpts');
        if (!hl.length) { if (box) box.remove(); return; }
        if (!box) {
            box = document.createElement('div');
            box.className = 'mail-excerpts';
            container.parentNode.insertBefore(box, container.nextSibling);
        }
        box.innerHTML = `<div class="mail-excerpts-head">🖍 高亮摘录 <span class="mail-hint">${hl.length} 条</span>
                <button class="td-linkbtn" title="清除全部高亮" onclick="MailUI.clearHighlights(${msg.id})">🗑️</button></div>` +
            hl.map(h => `<div class="mail-excerpt">
                <span class="mail-excerpt-src" title="${h.source === 'ai' ? '助手标注' : '你标注'}">${h.source === 'ai' ? '🤖' : '🖍'}</span>
                <span class="mail-excerpt-text">${escapeHtml(h.snippet)}${h.note ? `<i class="mail-excerpt-note"> — ${escapeHtml(h.note)}</i>` : ''}</span>
                <button class="td-linkbtn" title="取消这条高亮" onclick="MailUI.removeHighlight(${msg.id}, ${h.id})">✕</button>
            </div>`).join('');
    },

    /**
     * Watch for a text selection and offer a highlighter button.
     * Works inside the sandboxed iframe too: it has allow-same-origin, so the
     * parent can read its document and selection.
     */
    _bindSelection(doc, msg, hostEl) {
        if (!doc || doc._mailSelBound === msg.id) return;
        doc._mailSelBound = msg.id;
        const onUp = () => {
            setTimeout(() => {
                const sel = doc.getSelection && doc.getSelection();
                const text = sel ? String(sel).trim() : '';
                if (!sel || sel.isCollapsed || text.length < 2) { this._hideSelBtn(); return; }
                let rect;
                try { rect = sel.getRangeAt(0).getBoundingClientRect(); } catch (e) { return; }
                const host = hostEl.getBoundingClientRect();
                // Inside an iframe the rect is relative to the frame's viewport.
                const inFrame = doc !== document;
                this._showSelBtn(
                    (inFrame ? host.left : 0) + rect.left + rect.width / 2,
                    (inFrame ? host.top : 0) + rect.top - 8,
                    mb => this.addHighlight(msg.id, text)
                );
            }, 10);
        };
        doc.addEventListener('mouseup', onUp);
        doc.addEventListener('touchend', onUp);
        doc.addEventListener('scroll', () => this._hideSelBtn(), true);
    },

    _showSelBtn(x, y, onClick) {
        let btn = document.getElementById('mailSelBtn');
        if (!btn) {
            btn = document.createElement('button');
            btn.id = 'mailSelBtn';
            btn.className = 'mail-sel-btn';
            btn.innerHTML = '🖍 高亮';
            document.body.appendChild(btn);
        }
        btn.onclick = (e) => { e.preventDefault(); this._hideSelBtn(); onClick(); };
        btn.style.left = Math.max(8, Math.min(window.innerWidth - 90, x - 40)) + 'px';
        btn.style.top = Math.max(8, y - 36) + 'px';
        btn.style.display = 'block';
    },

    _hideSelBtn() {
        const btn = document.getElementById('mailSelBtn');
        if (btn) btn.style.display = 'none';
    },

    async addHighlight(id, snippet, note) {
        try { await API.mail.addHighlight(id, snippet, note); Toast.success('🖍 已高亮'); }
        catch (e) { Toast.error(e.message); return; }
        this._afterHighlightChange(id);
    },
    async removeHighlight(id, hid) {
        try { await API.mail.removeHighlight(id, hid); } catch (e) { Toast.error(e.message); return; }
        this._afterHighlightChange(id);
    },
    async clearHighlights(id) {
        if (!confirm('清除这封邮件的全部高亮？')) return;
        try { await API.mail.clearHighlights(id); } catch (e) { Toast.error(e.message); return; }
        this._afterHighlightChange(id);
    },

    /**
     * Re-read the mail and re-render every view currently showing it. Called both
     * by the in-page highlight buttons and by the assistant's refresh_mail action,
     * which is the only way an AI-side change becomes visible without a reload.
     * @return {Promise<boolean>} whether the mail was on screen anywhere.
     */
    async refreshMailViews(id) {
        id = parseInt(id, 10) || 0;
        if (!id) return false;
        let msg;
        try { msg = (await API.mail.message(id)).data; } catch (e) { return false; }
        let shown = false;

        if (typeof Mail !== 'undefined' && Mail.currentId === id && document.getElementById('mailReadPane')) {
            Mail.currentMsg = msg;
            Mail.renderReadPane(msg);
            shown = true;
        }
        if (typeof Mail !== 'undefined' && Array.isArray(Mail.items)) Mail.updateListItem({ id, is_highlighted: msg.is_highlighted });

        // Only touch the modal when it is showing THIS mail, or we would render
        // one mail's body into another's window.
        const modalBody = document.getElementById('mailModalBody');
        if (modalBody && Modal.isOpen && Modal.isOpen() && parseInt(modalBody.dataset.mailId, 10) === id) {
            this.renderBody(msg, modalBody, { maxHeight: 1600 });
            const box = document.getElementById('mailModalAnalysis');
            if (box) box.innerHTML = this.analysisHtml(msg, { refresh: '_mailModalRefreshAnalysis' });
            shown = true;
        }
        if (typeof loadDailyMail === 'function' && typeof App !== 'undefined') loadDailyMail(App.selectedDate, true);
        return shown;
    },

    _afterHighlightChange(id) { return this.refreshMailViews(id); },

    attachmentsHtml(msg) {
        const list = msg.attachments || [];
        if (!list.length) return '';
        return `<div class="mail-attachments">${list.map(a => a.stored
            ? `<a class="mail-att" href="${a.download_url}" title="${escapeHtml(a.file_name)}">📎 ${escapeHtml(a.file_name)} <span class="mail-att-size">${this.fmtSize(a.size)}</span></a>`
            : `<span class="mail-att mail-att-missing" title="超过大小上限，未下载">📎 ${escapeHtml(a.file_name)} <span class="mail-att-size">${this.fmtSize(a.size)} · 未下载</span></span>`).join('')}</div>`;
    },

    headerHtml(msg) {
        const to = (msg.to || []).map(a => this.addr(a)).join(', ');
        const cc = (msg.cc || []).map(a => this.addr(a)).join(', ');
        return `<div class="mail-read-header">
            <h3 class="mail-read-subject">${escapeHtml(msg.subject || '(无主题)')}</h3>
            <div class="mail-read-meta">
                <div><b>发件人：</b>${this.addr({ name: msg.from_name, email: msg.from_email })}</div>
                <div><b>收件人：</b>${to || '—'}</div>
                ${cc ? `<div><b>抄送：</b>${cc}</div>` : ''}
                <div><b>时间：</b>${escapeHtml(msg.msg_date || '')} · <b>文件夹：</b>${escapeHtml(msg.folder_name || '')} · ${escapeHtml(msg.account_email || '')}</div>
            </div></div>`;
    },

    analysisHtml(msg, opts = {}) {
        const a = msg.analysis;
        const id = msg.id;
        if (!a || a.status === 'error') {
            const err = a && a.error ? `<div class="mail-err">上次分析失败：${escapeHtml(a.error)}</div>` : '';
            return `<div class="mail-analysis-empty">${err}<p>这封邮件还没有 AI 分析。</p>
                <button class="btn btn-primary btn-sm" onclick="MailUI.analyze(${id}, ${opts.refresh ? `'${opts.refresh}'` : 'null'})">🤖 立即分析</button></div>`;
        }
        const actions = (a.actions || []).length ? `<div class="mail-analysis-actions"><b>建议行动</b><ul>${a.actions.map(x => `<li>${escapeHtml(x)}</li>`).join('')}</ul></div>` : '';
        return `<div class="mail-analysis">
            <div class="mail-analysis-head">
                ${this.priBadge(a)} ${this.relBadge(a)} ${this.catBadge(a)}
                ${a.needs_reply ? '<span class="mail-badge mail-reply">需回复</span>' : ''}
                ${a.deadline_hint ? `<span class="mail-badge mail-deadline">⏰ ${escapeHtml(a.deadline_hint)}</span>` : ''}
                ${a.user_edited ? '<span class="mail-badge mail-verified" title="已由你校正，不会被自动分析覆盖">✏️ 人工校正</span>' : ''}
                <span style="flex:1"></span>
                <button class="btn btn-ghost btn-sm" title="重新分析" onclick="MailUI.analyze(${id}, ${opts.refresh ? `'${opts.refresh}'` : 'null'}, true)">🔄</button>
            </div>
            <div class="mail-analysis-title">${escapeHtml(a.brief_title || '')}</div>
            <div class="mail-analysis-summary">${escapeHtml(a.summary || '')}</div>
            <div class="mail-analysis-detail markdown-body">${this.md(a.detailed_md || '')}</div>
            ${actions}
            <div class="mail-analysis-meta">${escapeHtml(a.model || '')} · ${escapeHtml(a.analyzed_at || '')}</div>
        </div>`;
    },

    /** Run analysis for one message, then call the named refresh function (global) if provided. */
    async analyze(id, refreshFn, force) {
        Toast.show('⏳ AI 正在分析这封邮件...', 'info', 4000);
        try {
            await API.mail.analyze({ message_id: id, force: !!force });
            Toast.success('分析完成');
        } catch (e) { Toast.error(e.message); }
        if (refreshFn && typeof window[refreshFn] === 'function') window[refreshFn](id);
        if (typeof loadDailyMail === 'function' && typeof App !== 'undefined') loadDailyMail(App.selectedDate, true);
    },
};

/** Tabbed email modal used by the home panel / immersive / anywhere. */
async function openMailModal(id) {
    let msg;
    try { msg = (await API.mail.message(id)).data; }
    catch (e) { Toast.error('加载邮件失败: ' + e.message); return; }
    if (!msg.is_seen) { API.mail.update(id, { is_seen: 1 }).catch(() => {}); msg.is_seen = 1; }

    const hasChat = typeof AiChat !== 'undefined';
    const hasAnalysis = !!(msg.analysis && msg.analysis.status === 'ok');
    Modal.open({
        title: '📧 ' + (msg.analysis && msg.analysis.brief_title ? msg.analysis.brief_title : (msg.subject || '(无主题)')),
        size: 'xl',
        body: `
            <div class="mail-modal-split">
                <div class="mail-modal-left">
                    <div class="right-panel-tabs mail-modal-tabs">
                        <button class="rp-tab rp-tab-active" data-mtab="mail">📧 邮件</button>
                        <button class="rp-tab" data-mtab="ai">🤖 AI 分析${hasAnalysis ? '' : ' <span class="mail-badge mail-pending">未分析</span>'}</button>
                    </div>
                    <div class="mail-modal-pane" id="mailModalMail">
                        ${MailUI.headerHtml(msg)}
                        <div class="mail-read-actions" id="mailModalActions">
                            <a class="btn btn-outline btn-sm" href="/mail?open=${id}">在邮箱中打开</a>
                            <a class="btn btn-outline btn-sm" href="/mail?compose=reply&id=${id}">↩️ 回复</a>
                            <a class="btn btn-outline btn-sm" href="/mail?compose=forward&id=${id}">↪️ 转发</a>
                            <span style="flex:1"></span>
                            <button class="btn btn-primary btn-sm" onclick="MailUI.analyze(${id}, '_mailModalRefreshAnalysis', ${hasAnalysis ? 'true' : 'false'})">🤖 ${hasAnalysis ? '重新分析' : 'AI 分析'}</button>
                        </div>
                        <div id="mailModalBody" class="mail-read-body" data-mail-id="${id}"></div>
                        ${MailUI.attachmentsHtml(msg)}
                    </div>
                    <div class="mail-modal-pane" id="mailModalAi" style="display:none;">
                        <div id="mailModalAnalysis">${MailUI.analysisHtml(msg, { refresh: '_mailModalRefreshAnalysis' })}</div>
                    </div>
                </div>
                ${hasChat ? `<div class="mail-modal-right">
                    <div class="mail-modal-chat-title">💬 与助手讨论这封邮件</div>
                    <div class="mail-modal-chat" id="mailModalChat"></div>
                </div>` : ''}
            </div>`,
        footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>',
        onClose: () => { if (hasChat) AiChat.unmount(); },
    });
    MailUI.renderBody(msg, document.getElementById('mailModalBody'), { maxHeight: 1600 });

    const modalBody = document.getElementById('modalBody');
    const switchTab = (name) => {
        modalBody.querySelectorAll('.mail-modal-tabs .rp-tab').forEach(t => t.classList.toggle('rp-tab-active', t.dataset.mtab === name));
        document.getElementById('mailModalAi').style.display = name === 'ai' ? '' : 'none';
        document.getElementById('mailModalMail').style.display = name === 'mail' ? '' : 'none';
    };
    window._mailModalRefreshAnalysis = async (mid) => {
        try {
            const m = (await API.mail.message(mid)).data;
            const box = document.getElementById('mailModalAnalysis');
            if (box) box.innerHTML = MailUI.analysisHtml(m, { refresh: '_mailModalRefreshAnalysis' });
            if (m.analysis && m.analysis.status === 'ok') switchTab('ai');
        } catch (e) {}
    };
    modalBody.querySelectorAll('.mail-modal-tabs .rp-tab').forEach(tab => tab.addEventListener('click', () => switchTab(tab.dataset.mtab)));

    if (hasChat) {
        const host = document.getElementById('mailModalChat');
        AiChat.mount(host, { context: { type: 'email', id: msg.id, label: msg.subject || ('#' + msg.id) } });
    }
}
