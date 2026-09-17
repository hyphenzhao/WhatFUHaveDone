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
    addr(a) { return a ? escapeHtml(a.name ? `${a.name} <${a.email}>` : (a.email || '')) : ''; },
    md(text) { return (typeof AiChat !== 'undefined' && AiChat._md) ? AiChat._md(text) : `<p>${escapeHtml(text || '')}</p>`; },

    /** Render the message body into a container (sandboxed iframe for HTML, <pre> for text). */
    renderBody(msg, container, opts = {}) {
        const allowRemote = !!opts.allowRemote;
        if (msg.body_html && msg.body_html.trim()) {
            const imgSrc = allowRemote ? '*' : "data: /api/attachments/";
            const csp = `default-src 'none'; img-src ${imgSrc}; style-src 'unsafe-inline'; font-src data:;`;
            const doc = `<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank">` +
                `<style>body{margin:12px;font-family:-apple-system,Segoe UI,Roboto,'PingFang SC','Microsoft YaHei',sans-serif;font-size:14px;line-height:1.6;color:#1f2937;word-break:break-word;}img{max-width:100%;height:auto;}table{max-width:100%;}pre{white-space:pre-wrap;}</style></head><body>${msg.body_html}</body></html>`;
            container.innerHTML = `<div class="mail-body-tools"><button class="btn btn-ghost btn-sm mail-remote-btn">${allowRemote ? '🔒 隐藏远程图片' : '🖼️ 加载远程图片'}</button></div><iframe class="mail-frame" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>`;
            const frame = container.querySelector('.mail-frame');
            frame.addEventListener('load', () => {
                try {
                    const h = frame.contentDocument.documentElement.scrollHeight;
                    frame.style.height = Math.min(Math.max(h + 24, 120), opts.maxHeight || 1400) + 'px';
                } catch (e) {}
            });
            frame.srcdoc = doc;
            container.querySelector('.mail-remote-btn').addEventListener('click', () => this.renderBody(msg, container, { ...opts, allowRemote: !allowRemote }));
        } else {
            const text = msg.body_text || msg.snippet || '(无正文)';
            container.innerHTML = `<pre class="mail-text-body">${escapeHtml(text)}</pre>`;
        }
    },

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
        if (typeof loadDailyMail === 'function' && typeof App !== 'undefined') loadDailyMail(App.selectedDate);
    },
};

/** Tabbed email modal used by the home panel / immersive / anywhere. */
async function openMailModal(id) {
    let msg;
    try { msg = (await API.mail.message(id)).data; }
    catch (e) { Toast.error('加载邮件失败: ' + e.message); return; }
    if (!msg.is_seen) { API.mail.update(id, { is_seen: 1 }).catch(() => {}); msg.is_seen = 1; }

    const hasChat = typeof AiChat !== 'undefined';
    Modal.open({
        title: '📧 ' + (msg.analysis && msg.analysis.brief_title ? msg.analysis.brief_title : (msg.subject || '(无主题)')),
        size: 'wide',
        body: `
            <div class="right-panel-tabs mail-modal-tabs">
                <button class="rp-tab rp-tab-active" data-mtab="ai">🤖 AI 分析</button>
                <button class="rp-tab" data-mtab="mail">📧 邮件</button>
            </div>
            <div class="mail-modal-pane" id="mailModalAi">
                <div id="mailModalAnalysis">${MailUI.analysisHtml(msg, { refresh: '_mailModalRefreshAnalysis' })}</div>
                ${hasChat ? '<div class="mail-modal-chat-title">💬 与助手讨论这封邮件</div><div class="mail-modal-chat" id="mailModalChat"></div>' : ''}
            </div>
            <div class="mail-modal-pane" id="mailModalMail" style="display:none;">
                ${MailUI.headerHtml(msg)}
                <div class="mail-read-actions">
                    <a class="btn btn-outline btn-sm" href="/mail?open=${id}">在邮箱中打开</a>
                    <a class="btn btn-outline btn-sm" href="/mail?compose=reply&id=${id}">↩️ 回复</a>
                    <a class="btn btn-outline btn-sm" href="/mail?compose=forward&id=${id}">↪️ 转发</a>
                </div>
                <div id="mailModalBody" class="mail-read-body"></div>
                ${MailUI.attachmentsHtml(msg)}
            </div>`,
        footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>',
        onClose: () => { if (hasChat) AiChat.unmount(); },
    });

    window._mailModalRefreshAnalysis = async (mid) => {
        try {
            const m = (await API.mail.message(mid)).data;
            const box = document.getElementById('mailModalAnalysis');
            if (box) box.innerHTML = MailUI.analysisHtml(m, { refresh: '_mailModalRefreshAnalysis' });
        } catch (e) {}
    };

    const modalBody = document.getElementById('modalBody');
    modalBody.querySelectorAll('.mail-modal-tabs .rp-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            modalBody.querySelectorAll('.mail-modal-tabs .rp-tab').forEach(t => t.classList.remove('rp-tab-active'));
            tab.classList.add('rp-tab-active');
            document.getElementById('mailModalAi').style.display = tab.dataset.mtab === 'ai' ? '' : 'none';
            document.getElementById('mailModalMail').style.display = tab.dataset.mtab === 'mail' ? '' : 'none';
            if (tab.dataset.mtab === 'mail' && !modalBody.querySelector('#mailModalBody').childElementCount) {
                MailUI.renderBody(msg, document.getElementById('mailModalBody'), { maxHeight: 700 });
            }
        });
    });

    if (hasChat) {
        const host = document.getElementById('mailModalChat');
        AiChat.mount(host, { context: { type: 'email', id: msg.id, label: msg.subject || ('#' + msg.id) } });
    }
}
