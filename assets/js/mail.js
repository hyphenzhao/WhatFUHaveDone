/**
 * Mailbox page — three-pane (folders | list | reading) + AI drawer.
 */
const Mail = {
    status: null,
    tree: [],
    current: { folderId: 0, accountId: 0, kind: '' },
    filters: { q: '', unread: false, flagged: false },
    page: 1,
    hasMore: false,
    loading: false,
    items: [],
    currentId: 0,
    currentMsg: null,
    observer: null,
    _searchTimer: null,

    async init() {
        try {
            this.status = (await API.mail.status()).data;
        } catch (e) { Toast.error('加载邮箱状态失败: ' + e.message); return; }
        const banner = document.getElementById('mailBanner');
        if (!this.status.imap_ext) {
            banner.innerHTML = `<div class="mail-banner mail-banner-warn">⚠️ ${escapeHtml(this.status.imap_hint || 'PHP imap 扩展未安装')}</div>`;
        } else if (!this.status.accounts.length) {
            banner.innerHTML = `<div class="mail-banner">还没有邮箱账户，<a href="/mail-admin">前往邮箱管理添加</a>。</div>`;
        } else if (!this.status.ai_configured) {
            banner.innerHTML = `<div class="mail-banner">🤖 请先配置AI（<a href="/ai-admin">AI 配置</a>），之后可对邮件做自动分析与讨论。</div>`;
        }
        await this.loadFolders();

        // Filters / search
        document.querySelectorAll('.mail-filter-btn').forEach(b => b.addEventListener('click', () => {
            const f = b.dataset.filter;
            this.filters[f] = !this.filters[f];
            b.classList.toggle('active', this.filters[f]);
            this.loadList(true);
        }));
        document.getElementById('mailSearch').addEventListener('input', (e) => {
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => { this.filters.q = e.target.value.trim(); this.loadList(true); }, 300);
        });

        // AI drawer. Below 1100px the drawer is display:none (mail.css), and mounting
        // there would park the chat singleton in an invisible container AND make it
        // AiChat.homeContainer forever — leaving no way to reach the assistant.
        const drawerBody = document.getElementById('mailAiBody');
        if (typeof AiChat !== 'undefined' && drawerBody && window.innerWidth > 1100) AiChat.mount(drawerBody);
        if (localStorage.getItem('mailAiDrawer') === 'collapsed') this.toggleDrawer(true);

        // Deep links: /mail?open=ID  /mail?compose=reply&id=ID
        const params = new URLSearchParams(location.search);
        if (params.get('open')) this.openMessage(parseInt(params.get('open'), 10));
        if (params.get('compose')) this.compose(params.get('compose'), parseInt(params.get('id') || '0', 10));
    },

    // ---------- folders ----------
    async loadFolders() {
        const box = document.getElementById('mailFolderTree');
        try { this.tree = (await API.mail.folders()).data || []; }
        catch (e) { box.innerHTML = `<div class="mail-muted">加载失败: ${escapeHtml(e.message)}</div>`; return; }
        if (!this.tree.length) { box.innerHTML = '<div class="mail-muted">暂无账户</div>'; return; }
        const kindIcon = { inbox: '📥', sent: '📤', drafts: '📝', trash: '🗑️', junk: '🚫', archive: '📦', other: '📁' };
        const unifiedUnread = this.tree.reduce((s, t) => s + t.folders.filter(f => f.kind === 'inbox').reduce((x, f) => x + f.unread_count, 0), 0);
        let html = `<div class="mail-folder-item mail-unified ${this.current.kind === 'inbox' && !this.current.folderId ? 'active' : ''}" onclick="Mail.selectKind('inbox')">
            <span>📬 全部收件箱</span>${unifiedUnread ? `<span class="unread-badge">${unifiedUnread}</span>` : ''}</div>`;
        for (const t of this.tree) {
            html += `<div class="mail-account-title" title="${escapeHtml(t.account.email)}">${escapeHtml(t.account.name || t.account.email)}${t.account.last_error ? ' <span class="mail-err-dot" title="' + escapeHtml(t.account.last_error) + '">⚠️</span>' : ''}</div>`;
            if (!t.folders.length) html += '<div class="mail-muted" style="padding:2px 12px;">尚未同步</div>';
            for (const f of t.folders) {
                html += `<div class="mail-folder-item ${this.current.folderId === f.id ? 'active' : ''}" onclick="Mail.selectFolder(${f.id}, ${t.account.id})" title="${escapeHtml(f.path)}${f.backfill_done ? '' : '（历史邮件同步中）'}">
                    <span>${kindIcon[f.kind] || '📁'} ${escapeHtml(f.display_name)}${f.backfill_done ? '' : ' <span class="mail-syncing">…</span>'}</span>
                    ${f.unread_count ? `<span class="unread-badge">${f.unread_count}</span>` : ''}</div>`;
            }
        }
        box.innerHTML = html;
        if (!this.current.folderId && !this.current.kind) this.selectKind('inbox');
    },

    selectKind(kind) {
        this.current = { folderId: 0, accountId: 0, kind };
        this.loadFolders();
        this.loadList(true);
    },
    selectFolder(folderId, accountId) {
        this.current = { folderId, accountId, kind: '' };
        this.loadFolders();
        this.loadList(true);
    },

    // ---------- list ----------
    async loadList(reset) {
        if (this.loading) return;
        const list = document.getElementById('mailList');
        if (reset) { this.page = 1; this.items = []; list.innerHTML = '<div class="mail-muted" style="padding:16px;">加载中...</div>'; }
        this.loading = true;
        try {
            const params = { page: this.page, per_page: 50, q: this.filters.q, unread: this.filters.unread ? 1 : '', flagged: this.filters.flagged ? 1 : '' };
            if (this.current.folderId) params.folder_id = this.current.folderId;
            else if (this.current.kind) params.kind = this.current.kind;
            const d = (await API.mail.messages(params)).data;
            this.hasMore = d.has_more;
            this.items = reset ? d.items : this.items.concat(d.items);
            this.renderList(reset);
        } catch (e) { list.innerHTML = `<div class="mail-muted" style="padding:16px;">加载失败: ${escapeHtml(e.message)}</div>`; }
        finally { this.loading = false; }
    },

    renderList(reset) {
        const list = document.getElementById('mailList');
        if (!this.items.length) { list.innerHTML = '<div class="mail-muted" style="padding:16px;">没有邮件</div>'; return; }
        const html = this.items.map(m => this.itemHtml(m)).join('') +
            (this.hasMore ? '<div class="mail-list-sentinel" id="mailListSentinel">加载更多…</div>' : '<div class="mail-muted" style="padding:10px;text-align:center;">— 到底了 —</div>');
        list.innerHTML = html;
        if (this.observer) this.observer.disconnect();
        const sentinel = document.getElementById('mailListSentinel');
        if (sentinel) {
            this.observer = new IntersectionObserver(entries => {
                if (entries.some(e => e.isIntersecting) && this.hasMore && !this.loading) { this.page++; this.loadList(false); }
            }, { root: list, rootMargin: '200px' });
            this.observer.observe(sentinel);
        }
    },

    itemHtml(m) {
        const a = m.analysis && m.analysis.status === 'ok' ? m.analysis : null;
        return `<div class="mail-list-item ${m.is_seen ? '' : 'unseen'} ${this.currentId === m.id ? 'selected' : ''}" data-id="${m.id}" onclick="Mail.openMessage(${m.id})">
            <div class="mail-li-row"><span class="mail-li-from">${escapeHtml(m.from_name || m.from_email)}</span><span class="mail-li-time">${MailUI.fmtDate(m.msg_date)}</span></div>
            <div class="mail-li-subject">${m.is_flagged ? '⭐ ' : ''}${m.has_attachments ? '📎 ' : ''}${escapeHtml(m.subject || '(无主题)')}</div>
            <div class="mail-li-row"><span class="mail-li-snippet">${escapeHtml(a ? a.brief_title : (m.snippet || ''))}</span>${a ? `<span class="mail-li-badges">${MailUI.priBadge(a)}${MailUI.relBadge(a)}</span>` : ''}</div>
        </div>`;
    },

    updateListItem(m) {
        const idx = this.items.findIndex(x => x.id === m.id);
        if (idx >= 0) {
            this.items[idx] = { ...this.items[idx], ...m };
            const el = document.querySelector(`.mail-list-item[data-id="${m.id}"]`);
            if (el) el.outerHTML = this.itemHtml(this.items[idx]);
        }
    },

    // ---------- reading pane ----------
    async openMessage(id) {
        const pane = document.getElementById('mailReadPane');
        this.currentId = id;
        document.querySelectorAll('.mail-list-item').forEach(el => el.classList.toggle('selected', parseInt(el.dataset.id, 10) === id));
        pane.innerHTML = '<div class="mail-read-empty">加载中...</div>';
        let msg;
        try { msg = (await API.mail.message(id)).data; }
        catch (e) { pane.innerHTML = `<div class="mail-read-empty">加载失败: ${escapeHtml(e.message)}</div>`; return; }
        if (this.currentId !== id) return;
        this.currentMsg = msg;
        if (!msg.is_seen) {
            API.mail.update(id, { is_seen: 1 }).then(() => { msg.is_seen = 1; this.updateListItem({ id, is_seen: 1 }); this.bumpUnread(msg.folder_id, -1); }).catch(() => {});
        }
        this.renderReadPane(msg);
        if (typeof AiChat !== 'undefined') AiChat.setContext({ type: 'email', id: msg.id, label: msg.subject || ('#' + msg.id) });
    },

    renderReadPane(msg) {
        const pane = document.getElementById('mailReadPane');
        pane.innerHTML = `
            ${MailUI.headerHtml(msg)}
            <div class="mail-read-actions">
                <button class="btn btn-outline btn-sm" onclick="Mail.compose('reply', ${msg.id})">↩️ 回复</button>
                <button class="btn btn-outline btn-sm" onclick="Mail.compose('reply_all', ${msg.id})">↩️↩️ 全部回复</button>
                <button class="btn btn-outline btn-sm" onclick="Mail.compose('forward', ${msg.id})">↪️ 转发</button>
                <button class="btn btn-ghost btn-sm" onclick="Mail.toggleFlag(${msg.id})">${msg.is_flagged ? '★ 取消星标' : '☆ 星标'}</button>
                <button class="btn btn-ghost btn-sm" onclick="Mail.markUnread(${msg.id})">✉️ 标为未读</button>
                <button class="btn btn-ghost btn-sm" onclick="Mail.remove(${msg.id})">🗑️ 删除</button>
                <span style="flex:1"></span>
                <button class="btn btn-primary btn-sm" onclick="MailUI.analyze(${msg.id}, 'mailRefreshAnalysis', ${msg.analysis && msg.analysis.status === 'ok' ? 'true' : 'false'})">🤖 ${msg.analysis && msg.analysis.status === 'ok' ? '重新分析' : 'AI 分析'}</button>
            </div>
            <div class="right-panel-tabs mail-read-tabs">
                <button class="rp-tab rp-tab-active" data-rtab="mail">📧 邮件</button>
                <button class="rp-tab" data-rtab="ai">🤖 AI 分析</button>
            </div>
            <div id="mailReadMail">
                <div id="mailReadBody" class="mail-read-body"></div>
                ${MailUI.attachmentsHtml(msg)}
            </div>
            <div id="mailReadAi" style="display:none;">${MailUI.analysisHtml(msg, { refresh: 'mailRefreshAnalysis' })}</div>`;
        MailUI.renderBody(msg, document.getElementById('mailReadBody'), { maxHeight: 2000 });
        pane.querySelectorAll('.mail-read-tabs .rp-tab').forEach(tab => tab.addEventListener('click', () => {
            pane.querySelectorAll('.mail-read-tabs .rp-tab').forEach(t => t.classList.remove('rp-tab-active'));
            tab.classList.add('rp-tab-active');
            document.getElementById('mailReadMail').style.display = tab.dataset.rtab === 'mail' ? '' : 'none';
            document.getElementById('mailReadAi').style.display = tab.dataset.rtab === 'ai' ? '' : 'none';
        }));
        if (msg.analysis && msg.analysis.status === 'ok' && !msg.is_seen_before_open) {
            // If analysis exists, show it by default in the AI tab but keep the mail tab first for reading.
        }
    },

    async refreshAnalysis(id) {
        if (this.currentId !== id) return;
        try {
            const msg = (await API.mail.message(id)).data;
            this.currentMsg = msg;
            const box = document.getElementById('mailReadAi');
            if (box) box.innerHTML = MailUI.analysisHtml(msg, { refresh: 'mailRefreshAnalysis' });
            this.updateListItem({ id, analysis: msg.analysis ? { ...msg.analysis, status: msg.analysis.status } : null });
            const aiTab = document.querySelector('.mail-read-tabs .rp-tab[data-rtab="ai"]');
            if (aiTab) aiTab.click();
        } catch (e) {}
    },

    bumpUnread(folderId, delta) {
        for (const t of this.tree) for (const f of t.folders) if (f.id === folderId) f.unread_count = Math.max(0, f.unread_count + delta);
        this.loadFoldersFromCache();
    },
    loadFoldersFromCache() { const cur = this.tree; this.tree = cur; /* re-render without refetch */ this._renderTreeOnly(); },
    _renderTreeOnly() { const t = this.tree; const fetchBackup = API.mail.folders; API.mail.folders = async () => ({ data: t }); this.loadFolders().finally(() => { API.mail.folders = fetchBackup; }); },

    async toggleFlag(id) {
        const m = this.currentMsg;
        if (!m) return;
        try {
            await API.mail.update(id, { is_flagged: m.is_flagged ? 0 : 1 });
            m.is_flagged = m.is_flagged ? 0 : 1;
            this.updateListItem({ id, is_flagged: m.is_flagged });
            this.renderReadPane(m);
        } catch (e) { Toast.error(e.message); }
    },
    async markUnread(id) {
        try { await API.mail.update(id, { is_seen: 0 }); this.updateListItem({ id, is_seen: 0 }); if (this.currentMsg) { this.bumpUnread(this.currentMsg.folder_id, 1); } Toast.success('已标为未读'); }
        catch (e) { Toast.error(e.message); }
    },
    async remove(id) {
        if (!confirm('删除这封邮件？（会移动到服务器的已删除文件夹）')) return;
        try {
            await API.mail.remove(id);
            this.items = this.items.filter(x => x.id !== id);
            this.renderList(false);
            document.getElementById('mailReadPane').innerHTML = '<div class="mail-read-empty">已删除</div>';
            if (typeof AiChat !== 'undefined') AiChat.setContext(null);
            this.currentId = 0; this.currentMsg = null;
            Toast.success('已删除');
        } catch (e) { Toast.error(e.message); }
    },

    // ---------- sync ----------
    async syncNow() {
        const btn = document.getElementById('mailSyncNow');
        const st = document.getElementById('mailSyncStatus');
        btn.disabled = true; btn.textContent = '⏳ 收取中...';
        let rounds = 0, gained = 0, err = '';
        try {
            while (rounds++ < 20) {
                const d = (await API.mail.sync(0, 25)).data;
                for (const r of d.results || []) {
                    gained += (r.new || 0) + (r.backfilled || 0);
                    if (r.error) err = r.error;
                }
                st.textContent = `已收取 ${gained} 封…`;
                if (!d.more) break;
                if (rounds % 3 === 0) { await this.loadFolders(); }
            }
            Toast.success(`收取完成，新增 ${gained} 封` + (err ? '（部分账户出错）' : ''));
            if (err) Toast.error(err);
        } catch (e) { Toast.error('收取失败: ' + e.message); }
        finally {
            btn.disabled = false; btn.textContent = '🔄 立即收取';
            st.textContent = '';
            await this.loadFolders();
            this.loadList(true);
        }
    },

    async analyzeVisible() {
        const ids = this.items.filter(m => !(m.analysis && m.analysis.status === 'ok')).map(m => m.id).slice(0, 30);
        if (!ids.length) { Toast.show('本页邮件都已分析', 'info'); return; }
        const btn = document.getElementById('mailAnalyzePage');
        btn.disabled = true; btn.textContent = `⏳ 0/${ids.length}`;
        let done = 0;
        for (const id of ids) {
            try { await API.mail.analyze({ message_id: id }); done++; }
            catch (e) { if (/请先配置AI/.test(e.message)) { Toast.error(e.message); break; } }
            btn.textContent = `⏳ ${done}/${ids.length}`;
        }
        btn.disabled = false; btn.textContent = '🤖 分析本页';
        Toast.success(`已分析 ${done} 封`);
        this.loadList(true);
        if (this.currentId) this.refreshAnalysis(this.currentId);
    },

    refreshAfterAi() { this.loadList(true); if (this.currentId) this.refreshAnalysis(this.currentId); },

    // ---------- compose ----------
    async compose(mode, id) {
        const accounts = (this.status && this.status.accounts || []).filter(a => a.enabled);
        if (!accounts.length) { Toast.error('请先添加邮箱账户'); return; }
        let tpl = { to: [], cc: [], subject: '', quoted_text: '', account_id: accounts[0].id, in_reply_to_id: 0, attachment_ids: [] };
        if (mode !== 'new' && id) {
            try { tpl = (await API.mail.replyTemplate(id, mode)).data; }
            catch (e) { Toast.error(e.message); return; }
        }
        const accOpts = accounts.map(a => `<option value="${a.id}" ${a.id === tpl.account_id ? 'selected' : ''}>${escapeHtml(a.name)} &lt;${escapeHtml(a.email)}&gt;</option>`).join('');
        const titles = { new: '✏️ 写邮件', reply: '↩️ 回复', reply_all: '↩️↩️ 全部回复', forward: '↪️ 转发' };
        Modal.open({
            title: titles[mode] || '✏️ 写邮件',
            size: 'wide',
            body: `
                <div class="form-group"><label>发件账户</label><select class="form-select" id="mcAccount">${accOpts}</select></div>
                <div class="form-group"><label>收件人</label><input class="form-input" id="mcTo" value="${escapeHtml(tpl.to.join(', '))}" placeholder="多个地址用逗号分隔"></div>
                <div class="form-row-2">
                    <div class="form-group"><label>抄送</label><input class="form-input" id="mcCc" value="${escapeHtml(tpl.cc.join(', '))}"></div>
                    <div class="form-group"><label>密送</label><input class="form-input" id="mcBcc"></div>
                </div>
                <div class="form-group"><label>主题</label><input class="form-input" id="mcSubject" value="${escapeHtml(tpl.subject)}"></div>
                <div class="form-group"><label>正文</label><textarea class="form-textarea mail-compose-body" id="mcBody" rows="12">${escapeHtml(tpl.quoted_text || '')}</textarea></div>
                <div class="form-group"><label>附件</label><input type="file" id="mcFiles" multiple>
                    ${tpl.attachment_ids && tpl.attachment_ids.length ? `<div class="mail-hint">将随转发一并附上原邮件的 ${tpl.attachment_ids.length} 个附件</div>` : ''}</div>
                <div class="mail-hint">💡 可以在右侧/下方的智能助手中让 AI 帮你起草或润色，再粘贴到这里。</div>`,
            footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="mcSend">📤 发送</button>`,
        });
        const body = document.getElementById('mcBody');
        body.focus(); body.setSelectionRange(0, 0);
        document.getElementById('mcSend').addEventListener('click', async () => {
            const btn = document.getElementById('mcSend');
            btn.disabled = true; btn.textContent = '⏳ 发送中...';
            try {
                const fd = new FormData();
                fd.append('account_id', document.getElementById('mcAccount').value);
                fd.append('to', document.getElementById('mcTo').value);
                fd.append('cc', document.getElementById('mcCc').value);
                fd.append('bcc', document.getElementById('mcBcc').value);
                fd.append('subject', document.getElementById('mcSubject').value);
                fd.append('body_text', document.getElementById('mcBody').value);
                if (tpl.in_reply_to_id) fd.append('in_reply_to_id', tpl.in_reply_to_id);
                if (tpl.attachment_ids && tpl.attachment_ids.length) fd.append('forward_attachment_ids', tpl.attachment_ids.join(','));
                for (const f of document.getElementById('mcFiles').files) fd.append('files[]', f);
                const r = await API.mail.send(fd);
                Modal.close();
                Toast.success('邮件已发送' + (r.data && r.data.sent_copy ? '，已保存到已发送' : ''));
                if (tpl.in_reply_to_id) this.updateListItem({ id: tpl.in_reply_to_id, is_answered: 1 });
            } catch (e) { Toast.error(e.message); btn.disabled = false; btn.textContent = '📤 发送'; }
        });
    },

    toggleDrawer(forceCollapse) {
        const d = document.getElementById('mailAiDrawer');
        const collapsed = forceCollapse === true ? true : !d.classList.contains('collapsed');
        d.classList.toggle('collapsed', collapsed);
        document.getElementById('mailAiToggle').textContent = collapsed ? '◀' : '▶';
        localStorage.setItem('mailAiDrawer', collapsed ? 'collapsed' : 'open');
    },
};

function mailRefreshAnalysis(id) { Mail.refreshAnalysis(id); }

document.addEventListener('DOMContentLoaded', () => Mail.init());
