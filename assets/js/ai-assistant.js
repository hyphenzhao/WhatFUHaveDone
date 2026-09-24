/**
 * AI Assistant — single, system-wide chat instance.
 *
 * One AiChat root element is created once and physically MOVED between hosts
 * (home right panel, mail page drawer, email modal). The current conversation
 * is stored server-side as the user's "active conversation", so every page and
 * every mount shows the same thread. Optional context (e.g. the email being
 * viewed) is attached to requests so the server can inject it into the prompt.
 */
const AiChat = {
    convId: null,
    messages: [],
    rootEl: null,          // .ai-chat-container (created once)
    element: null,         // current host container
    homeContainer: null,   // first host; unmount() returns the chat here
    messagesEl: null,
    inputEl: null,
    sendBtn: null,
    isWaiting: false,
    pendingCalls: null,
    typingTimer: null,
    requestController: null,
    pendingFile: null,     // { name, content } for attached file
    context: null,         // { type:'email', id, label } | null
    _activeUpdatedAt: null,
    _lastSyncAt: 0,
    _listenersBound: false,

    /** Mount (or move) the chat into a host container. */
    async mount(container, opts = {}) {
        if (!container) return;
        const first = !this.rootEl;
        if (first) {
            this._build();
            this.homeContainer = container;
        }
        if (this.rootEl.parentNode !== container) container.appendChild(this.rootEl);
        this.element = container;
        if (opts.context !== undefined) this.setContext(opts.context);
        if (first) {
            await this._loadConvList();
            await this._syncActive(true);
            this._bindGlobalListeners();
        } else if (opts.sync !== false) {
            await this._syncActive(false);
        }
        this._scrollBottom();
    },

    /** Backwards-compatible alias. */
    async init(container) { return this.mount(container); },

    /** Detach from the current host; return to the first host if it still exists. */
    unmount() {
        if (!this.rootEl) return;
        this.setContext(null);
        const home = this.homeContainer && document.body.contains(this.homeContainer) ? this.homeContainer : null;
        if (home && home !== this.rootEl.parentNode) home.appendChild(this.rootEl);
        else if (!home) this.rootEl.remove();
        this.element = home;
    },

    _build() {
        const root = document.createElement('div');
        root.className = 'ai-chat-container';
        root.innerHTML = `
            <div class="ai-conv-bar">
                <select class="ai-conv-select"><option value="">+ 新对话</option></select>
                <button class="ai-conv-del" title="删除当前对话">🗑️</button>
            </div>
            <div class="ai-messages"></div>
            <div class="ai-context-chip" style="display:none;"><span class="ai-context-label"></span><button class="ai-context-clear" title="不再针对该邮件">✕</button></div>
            <div class="ai-file-tag" style="display:none;">
                <span class="ai-file-name"></span>
                <button class="ai-file-remove" title="移除文件">✕</button>
            </div>
            <div class="ai-input-area">
                <input type="file" class="ai-file-input" accept=".pdf,.docx,.txt,.md,.json,.png,.jpg,.jpeg" style="display:none;">
                <button class="ai-attach-btn" title="上传文件">📎</button>
                <textarea class="ai-input" rows="1" placeholder="输入消息... (Enter 发送)"></textarea>
                <button class="ai-send-btn">发送</button>
            </div>`;
        this.rootEl = root;
        this.messagesEl = root.querySelector('.ai-messages');
        this.inputEl = root.querySelector('.ai-input');
        this.sendBtn = root.querySelector('.ai-send-btn');

        this.sendBtn.addEventListener('click', () => {
            if (this.isWaiting && this.requestController) this.requestController.abort();
            else this.send();
        });
        this.inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
        });
        this.inputEl.addEventListener('input', () => {
            this.inputEl.style.height = 'auto';
            this.inputEl.style.height = Math.min(this.inputEl.scrollHeight, 140) + 'px';
        });
        root.querySelector('.ai-conv-select').addEventListener('change', () => this._switchConv());
        root.querySelector('.ai-conv-del').addEventListener('click', () => this._deleteConv());
        root.querySelector('.ai-context-clear').addEventListener('click', () => this.setContext(null));

        const fileInput = root.querySelector('.ai-file-input');
        root.querySelector('.ai-attach-btn').addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => this._onFileSelected(fileInput));
        root.querySelector('.ai-file-remove').addEventListener('click', () => this._removeFile());
    },

    _bindGlobalListeners() {
        if (this._listenersBound) return;
        this._listenersBound = true;
        const onWake = () => { if (document.visibilityState === 'visible') this._syncActive(false); };
        document.addEventListener('visibilitychange', onWake);
        window.addEventListener('focus', onWake);
    },

    _q(sel) { return this.rootEl ? this.rootEl.querySelector(sel) : null; },
    _scrollBottom() { if (this.messagesEl) this.messagesEl.scrollTop = this.messagesEl.scrollHeight; },

    // ===== CONTEXT (e.g. the email being viewed) =====
    setContext(ctx) {
        this.context = ctx && ctx.type ? { type: ctx.type, id: ctx.id, label: ctx.label || '' } : null;
        const chip = this._q('.ai-context-chip');
        if (!chip) return;
        if (this.context) {
            chip.style.display = 'flex';
            chip.querySelector('.ai-context-label').textContent = (this.context.type === 'email' ? '📧 正在讨论: ' : '📌 上下文: ') + (this.context.label || ('#' + this.context.id));
        } else {
            chip.style.display = 'none';
        }
    },

    // ===== SHARED ACTIVE CONVERSATION =====
    async _syncActive(force) {
        if (this.isWaiting) return;
        const now = Date.now();
        if (!force && now - this._lastSyncAt < 3000) return;
        this._lastSyncAt = now;
        try {
            const res = await API.ai.active();
            const conv = res.data;
            if (conv && conv.id) {
                const changed = String(conv.id) !== String(this.convId) || conv.updated_at !== this._activeUpdatedAt;
                if (changed) {
                    this.convId = conv.id;
                    this._activeUpdatedAt = conv.updated_at;
                    this._renderMessages(conv.messages || []);
                    const sel = this._q('.ai-conv-select');
                    if (sel) sel.value = String(conv.id);
                }
            } else if (!this.convId && this.messages.length === 0) {
                this._startNew(false);
            }
        } catch (e) { if (!this.convId && this.messages.length === 0) this._startNew(false); }
    },

    _renderMessages(msgs) {
        this.messages = msgs;
        this.messagesEl.innerHTML = '';
        if (!msgs.length) { this._addWelcome(); return; }
        msgs.forEach(m => {
            if ((m.role === 'user' || m.role === 'assistant') && m.content) this._addMsg(m.role, m.content, true);
        });
        this._scrollBottom();
    },

    // ===== CONVERSATION MANAGEMENT =====
    async _loadConvList() {
        try {
            const res = await API.get('/ai/conversations');
            const convs = res.data || [];
            const sel = this._q('.ai-conv-select');
            if (!sel) return;
            const cur = this.convId ? String(this.convId) : '';
            sel.innerHTML = '<option value="">+ 新对话</option>' +
                convs.map(c => `<option value="${c.id}">${escapeHtml(c.title)}</option>`).join('');
            sel.value = cur;
        } catch (e) { /* ignore */ }
    },

    async _switchConv() {
        const id = this._q('.ai-conv-select').value;
        if (!id) { this._startNew(true); return; }
        try {
            const detail = await API.get('/ai/conversations/' + id);
            this.convId = id;
            this._activeUpdatedAt = detail.data?.updated_at || null;
            this._renderMessages(detail.data?.messages || []);
            await API.ai.setActive(id);
        } catch (e) { this._startNew(true); }
    },

    async _deleteConv() {
        if (!this.convId) { Toast.error('没有选中的对话'); return; }
        if (!confirm('确定删除此对话？')) return;
        try {
            await API.delete('/ai/conversations/' + this.convId);
            this._startNew(true);
            await this._loadConvList();
            Toast.success('对话已删除');
        } catch (e) { Toast.error('删除失败'); }
    },

    _startNew(clearActive) {
        this.convId = null;
        this._activeUpdatedAt = null;
        this.messages = [];
        this.messagesEl.innerHTML = '';
        const sel = this._q('.ai-conv-select');
        if (sel) sel.value = '';
        this._addWelcome();
        if (clearActive) API.ai.setActive(0).catch(() => {});
    },

    // ===== FILE UPLOAD =====
    async _onFileSelected(input) {
        const file = input.files[0];
        if (!file) return;
        const tag = this._q('.ai-file-tag');
        const nameEl = tag.querySelector('.ai-file-name');
        tag.style.display = 'flex';
        nameEl.textContent = '⏳ ' + file.name + ' 上传中...';
        try {
            const formData = new FormData();
            formData.append('file', file);
            const res = await fetch('/api/upload', { method: 'POST', body: formData }).then(r => r.json());
            if (res.error) throw new Error(res.message);
            this.pendingFile = { name: res.data.name, content: res.data.content };
            nameEl.textContent = '📄 ' + res.data.name + ' (' + (res.data.content.length > 200 ? '已提取文本' : res.data.content.length + ' 字符') + ')';
        } catch (e) {
            nameEl.textContent = '❌ 上传失败: ' + (e.message || '未知错误');
            this.pendingFile = null;
        }
        input.value = '';
    },

    _removeFile() {
        this.pendingFile = null;
        const tag = this._q('.ai-file-tag');
        if (tag) tag.style.display = 'none';
    },

    async _saveConv(title) {
        try {
            if (this.convId) {
                const res = await API.put('/ai/conversations/' + this.convId, { title: title || undefined, messages: this.messages, set_active: true });
                this._activeUpdatedAt = res.data?.updated_at || this._activeUpdatedAt;
            } else {
                const res = await API.post('/ai/conversations', { title: title || '新对话', messages: this.messages, set_active: true });
                this.convId = res.data.id;
                const act = await API.ai.active().catch(() => null);
                this._activeUpdatedAt = act?.data?.updated_at || null;
            }
            await this._loadConvList();
        } catch (e) { /* ignore save errors */ }
    },

    _requestBody() {
        const body = { messages: this.messages };
        if (this.convId) body.conversation_id = parseInt(this.convId, 10);   // lets the server keep a running summary of old turns
        if (this.context) body.context = { type: this.context.type, id: this.context.id };
        if (typeof App !== 'undefined' && App.selectedDate) {
            body.selected_date = App.selectedDate;
            let meta = {};
            if (typeof Calendar !== 'undefined' && Calendar.calendarMeta) meta = Calendar.calendarMeta[App.selectedDate] || {};
            if (!meta.lunar_day && typeof Lunar !== 'undefined') {
                try {
                    const d = new Date(App.selectedDate + 'T12:00:00');
                    const lunar = Lunar.fromDate(d);
                    meta = { lunar_month: lunar.getMonthInChinese(), lunar_day: lunar.getDayInChinese(), solar_term: lunar.getJieQi() || (lunar.getPrevJieQi(true)||{}).getName?.() || '' };
                } catch (e) {}
            }
            if (meta.lunar_month || meta.lunar_day) {
                body.almanac = { solar_date: App.selectedDate, lunar_date: `${meta.lunar_month || ''}月${meta.lunar_day || ''}`, solar_term: meta.solar_term || '' };
            }
        }
        return body;
    },

    _isLongTask(text) {
        return !!this.context || /八字|命理|紫微|运势|侧写|简历|生肖|生辰|五行|流年|流月|大运|奇门|风水|邮件|邮箱|email|mail|附件|逐封|逐个|汇总|整理|相关度/i.test(text || '');
    },

    // ===== SENDING =====
    async send(text) {
        if (this.isWaiting) return;
        text = text || this.inputEl.value.trim();
        if (!text && !this.pendingFile) return;

        let fullContent = text || '';
        let displayContent = text || '';
        if (this.pendingFile) {
            const fileBlock = `[上传文件: ${this.pendingFile.name}]\n文件内容:\n${this.pendingFile.content}\n\n---\n`;
            fullContent = fileBlock + (text ? `用户消息: ${text}` : '请分析以上文件内容');
            displayContent = `📎 ${this.pendingFile.name}\n${text || '请分析文件内容'}`;
        }

        this._addMsg('user', displayContent, true);
        this.messages.push({ role: 'user', content: fullContent });
        this._removeFile();
        this.inputEl.value = '';
        this.inputEl.style.height = 'auto';
        this.isWaiting = true;
        this.sendBtn.disabled = false;
        this.sendBtn.textContent = '停止';
        this._showTyping();

        const thinkingStep = document.createElement('div');
        thinkingStep.className = 'ai-step';
        thinkingStep.innerHTML = '<span class="ai-step-icon">🤔</span> <span>AI 分析中，请稍候…</span>';
        this.messagesEl.appendChild(thinkingStep);
        this._scrollBottom();

        try {
            const ctrl = new AbortController();
            this.requestController = ctrl;
            const timeout = setTimeout(() => ctrl.abort(), this._isLongTask(text) ? 310000 : 100000);
            const res = await fetch('/api/ai/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this._requestBody()),
                signal: ctrl.signal,
            }).then(r => r.json());
            clearTimeout(timeout);
            if (thinkingStep.parentNode) thinkingStep.remove();
            if (res.error) throw new Error(res.message);
            this._hideTyping();
            await this._handleResponse(res.data, this.messages.length <= 1 ? this._genTitle(fullContent) : undefined);
        } catch (e) {
            if (thinkingStep.parentNode) thinkingStep.remove();
            this._hideTyping();
            const msg = e.name === 'AbortError' ? '请求超时，AI 响应时间过长。请尝试简化问题或检查 API 配置。' : '⚠️ 请求失败: ' + (e.message || '未知错误');
            this._addMsg('assistant', msg, true);
        } finally {
            this.isWaiting = false;
            this.requestController = null;
            this.sendBtn.disabled = false;
            this.sendBtn.textContent = '发送';
        }
    },

    /** Shared handling for chat + confirm responses. */
    async _handleResponse(data, title) {
        if (data.type === 'text' || data.type === 'steps') {
            for (const step of (data.steps || [])) {
                await this._showStep(step);
                await new Promise(r => setTimeout(r, 150));
            }
            this.messages.push(data.message || { role: 'assistant', content: data.content });
            await this._typeText(data.content);
            this._showMeta(data);
            await this._saveConv(title);
            this._runActions(data.steps);
            return false;
        }
        if (data.type === 'confirmation') {
            this.pendingCalls = Array.isArray(data.pending_calls) ? data.pending_calls : [];
            this.messages.push(data.message);
            for (const step of (data.steps || [])) { await this._showStep(step); await new Promise(r => setTimeout(r, 100)); }
            this._showConfirmation(this.pendingCalls);
            // No-confirmation tools can run in the SAME turn as one awaiting approval
            // (e.g. open_email beside highlight_text) — their UI actions still apply.
            this._runActions(data.steps);
            return true;
        }
        return false;
    },

    /**
     * UI actions a tool asked for (step.action), run once the reply has rendered.
     * Only the last open_mail wins — the assistant may have looked at several.
     */
    _runActions(steps) {
        let openId = 0;
        for (const s of (steps || [])) {
            if (s && s.action && s.action.type === 'open_mail') openId = parseInt(s.action.id, 10) || 0;
        }
        if (!openId) return;
        // Don't reopen the mail whose modal we are already chatting inside.
        if (this.context && this.context.type === 'email' && this.context.id === openId) return;
        // On the mailbox page the reading pane IS the mail view — selecting there is
        // more natural (and more visible) than stacking a modal on top of it.
        if (typeof Mail !== 'undefined' && document.getElementById('mailReadPane')) {
            Mail.openMessage(openId);
            document.getElementById('mailReadPane').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (typeof openMailModal === 'function') openMailModal(openId);
    },

    _genTitle(text) {
        text = (text || '').replace(/\n/g, ' ');
        return text.substring(0, 20) + (text.length > 20 ? '…' : '');
    },

    // ===== TYPING ANIMATION =====
    async _typeText(text) {
        if (!text) return;
        const div = document.createElement('div');
        div.className = 'ai-message ai-message-assistant';
        div.innerHTML = `<div class="ai-avatar">🤖</div><div class="ai-bubble"></div>`;
        this.messagesEl.appendChild(div);
        const bubble = div.querySelector('.ai-bubble');
        const rendered = this._md(text);
        if (text.length > 600 || text.includes('```') || /(?:^|\n)\|.+\|/.test(text)) {
            bubble.innerHTML = rendered;
            this._scrollBottom();
            return;
        }
        let i = 0;
        const speed = 12;
        return new Promise(resolve => {
            let finished = false;
            let watchdog = null;
            const finish = () => {
                if (finished) return;
                finished = true;
                if (this.typingTimer) clearTimeout(this.typingTimer);
                if (watchdog) clearTimeout(watchdog);
                this.typingTimer = null;
                bubble.innerHTML = rendered;
                this._scrollBottom();
                resolve();
            };
            const tick = () => {
                if (i >= text.length) { finish(); return; }
                i = Math.min(i + 6, text.length);
                bubble.innerHTML = this._md(text.substring(0, i)) + '<span class="typing-cursor">|</span>';
                this._scrollBottom();
                this.typingTimer = setTimeout(tick, speed);
            };
            watchdog = setTimeout(finish, 5000);
            tick();
        });
    },

    // ===== CONFIRMATION (inline card, so it works inside modals too) =====
    _toolIcons: { create_task:'➕', update_task:'✏️', delete_task:'🗑️', create_person:'➕', update_person:'✏️', create_tag:'➕', update_tag:'✏️',
                  toggle_worklog:'📝', add_plan:'📅', add_result_log:'🏆', update_worklog_duration:'⏱️', add_worklog_note:'📝', save_bazi_analysis:'🔮',
                  mark_email:'🏷️', send_email:'📤', add_mail_account:'📮', update_mail_account:'📮', remove_mail_account:'🗑️',
                  update_email_analysis:'✏️', highlight_text:'🖍', clear_text_highlights:'🗑️' },
    _toolNames: { create_task:'创建任务', update_task:'更新任务', delete_task:'删除任务', create_person:'创建人物', update_person:'更新人物',
                  create_tag:'创建标签', update_tag:'更新标签', toggle_worklog:'切换工作量', add_plan:'添加计划', add_result_log:'添加成果记录',
                  update_worklog_duration:'更新工作时长', add_worklog_note:'添加工作备注', save_bazi_analysis:'保存八字分析',
                  mark_email:'标记邮件', send_email:'发送邮件', add_mail_account:'添加邮箱账户', update_mail_account:'修改邮箱账户', remove_mail_account:'删除邮箱账户',
                  update_email_analysis:'校正邮件分析', highlight_text:'荧光笔高亮正文', clear_text_highlights:'清除正文高亮' },

    /** Tools whose confirmation card needs a secret typed by the user (never sent through the LLM). */
    _secretFor(call) {
        const a = call.arguments || {};
        if (call.name === 'add_mail_account') return { key: '_password', label: '密码 / 授权码', required: true, hint: '直接提交到服务器加密保存，不会进入对话记录或发给 AI' };
        if (call.name === 'update_mail_account' && a.change_password) return { key: '_password', label: '新密码 / 授权码', required: true, hint: '留空则不修改' };
        if (call.name === 'update_mail_account') return { key: '_password', label: '新密码 / 授权码（可选）', required: false, hint: '留空则不修改' };
        return null;
    },

    _showConfirmation(calls) {
        calls = Array.isArray(calls) ? calls : [];
        if (calls.length === 0) { this._addMsg('assistant', '⚠️ 没有可确认的操作，请重新发送请求。', true); return; }
        const items = calls.map(c => {
            const secret = this._secretFor(c);
            const secretHtml = secret ? `<div class="ai-secret-row"><label>🔑 ${secret.label}${secret.required ? ' *' : ''}</label>
                <input type="password" class="form-input ai-secret-input" data-call-id="${escapeHtml(c.id)}" data-key="${secret.key}" data-required="${secret.required ? 1 : 0}" autocomplete="new-password" placeholder="${escapeHtml(secret.hint)}"></div>` : '';
            return `<div class="confirm-item">
            <div class="confirm-item-icon">${this._toolIcons[c.name] || '🔧'}</div>
            <div class="confirm-item-detail"><div class="confirm-item-name">${this._toolNames[c.name] || c.name}</div>
            <div class="confirm-item-args">${this._fmtArgs(c.name, c.arguments || {})}</div>${secretHtml}</div></div>`;
        }).join('');
        const card = document.createElement('div');
        card.className = 'ai-confirm-card';
        card.innerHTML = `<div class="ai-confirm-title">🤖 智能助手请求执行以下操作，请确认：</div>
            <div class="ai-confirm-list">${items}</div>
            <div class="ai-confirm-actions"><button class="btn btn-ghost btn-sm ai-reject">拒绝</button><button class="btn btn-primary btn-sm ai-approve">确认执行</button></div>`;
        this.messagesEl.appendChild(card);
        this._scrollBottom();
        const firstSecret = card.querySelector('.ai-secret-input');
        if (firstSecret) setTimeout(() => firstSecret.focus(), 50);
        const done = (approved) => {
            const secrets = {};
            if (approved) {
                let missing = false;
                card.querySelectorAll('.ai-secret-input').forEach(inp => {
                    const v = inp.value;
                    if (inp.dataset.required === '1' && !v) { missing = true; inp.classList.add('ai-secret-missing'); inp.focus(); return; }
                    if (v) { secrets[inp.dataset.callId] = secrets[inp.dataset.callId] || {}; secrets[inp.dataset.callId][inp.dataset.key] = v; }
                });
                if (missing) { Toast.error('请先填写密码 / 授权码'); return; }
            }
            card.querySelectorAll('input').forEach(i => { i.value = ''; i.disabled = true; });
            card.querySelectorAll('button').forEach(b => b.disabled = true);
            card.querySelector('.ai-confirm-actions').innerHTML = `<span class="ai-confirm-result">${approved ? '✅ 已确认' : '🚫 已拒绝'}</span>`;
            this._onConfirm(approved, secrets);
        };
        card.querySelector('.ai-approve').addEventListener('click', () => done(true));
        card.querySelector('.ai-reject').addEventListener('click', () => done(false));
        card.querySelectorAll('.ai-secret-input').forEach(inp => inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); done(true); } }));
    },

    async _onConfirm(approved, secrets = {}) {
        const pendingCalls = Array.isArray(this.pendingCalls) ? [...this.pendingCalls] : [];
        if (pendingCalls.length === 0) { this._addMsg('assistant', '⚠️ 待确认操作已失效，请重新发送请求。', true); return; }
        this.isWaiting = true;
        this.sendBtn.disabled = false;
        this.sendBtn.textContent = '停止';
        this._showTyping();
        let hasNext = false;
        try {
            const ctrl = new AbortController();
            this.requestController = ctrl;
            const timeout = setTimeout(() => ctrl.abort(), 310000);
            const body = this._requestBody();
            body.messages = this.messages.slice(0, -1);
            body.message = this.messages[this.messages.length - 1];
            body.confirmations = pendingCalls.map(c => ({ id: c.id, action: approved ? 'confirm' : 'reject' }));
            if (approved && secrets && Object.keys(secrets).length) body.secrets = secrets;
            const response = await fetch('/api/ai/confirm', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), signal: ctrl.signal });
            clearTimeout(timeout);
            const res = await response.json();
            if (!response.ok || res.error) throw new Error(res.message || '请求失败');
            this._hideTyping();
            hasNext = await this._handleResponse(res.data);
            if (!hasNext && approved) {
                if (typeof refreshAll === 'function') await refreshAll();
                if (typeof Mail !== 'undefined' && Mail.refreshAfterAi) Mail.refreshAfterAi();
                Toast.success('操作已完成');
            }
        } catch (e) {
            this._hideTyping();
            const message = e.name === 'AbortError' ? '请求已停止或等待超时。' : (e.message || '未知错误');
            this._addMsg('assistant', '⚠️ 操作失败: ' + message, true);
        } finally {
            this.isWaiting = false;
            this.requestController = null;
            if (!hasNext) this.pendingCalls = null;
            this.sendBtn.disabled = false;
            this.sendBtn.textContent = '发送';
        }
    },

    // ===== STEP DISPLAY =====
    async _showStep(step) {
        const div = document.createElement('div');
        div.className = 'ai-step';
        const names = { list_tasks:'拉取任务列表', get_task:'获取任务详情', list_people:'拉取人员信息', get_person:'获取人物详情', list_tags:'拉取标签',
            list_results:'拉取成果', get_worklogs_by_date:'拉取工作量', get_workload_stats:'拉取工作量统计',
            get_results_stats:'拉取成果统计', get_calendar_data:'拉取日历数据', get_daily_status:'拉取当日状态',
            get_relationships:'拉取人际关系', create_task:'创建任务', update_task:'更新任务', delete_task:'删除任务', toggle_worklog:'记录工作量',
            add_plan:'添加计划', add_result_log:'记录成果', get_weather:'读取天气', get_worklog_notes:'读取工作备注',
            add_worklog_note:'添加工作备注', update_worklog_duration:'更新工作时长', get_user_profile:'读取个人侧写', get_bazi_analysis:'读取八字分析',
            save_bazi_analysis:'保存八字分析', get_calendar_meta:'读取黄历', create_person:'创建人物', update_person:'更新人物', create_tag:'创建标签', update_tag:'更新标签',
            get_profile_documents:'读取身份文档', get_profile_document_text:'读取文档内容', list_impressions:'读取侧写印象',
            remember_about_user:'更新侧写', forget_impression:'删除侧写条目',
            list_mail_accounts:'读取邮箱账户', search_emails:'搜索邮件', get_email:'读取邮件', get_email_analysis:'读取邮件分析',
            analyze_email:'AI 分析邮件', analyze_emails_by_date:'批量分析邮件', mark_email:'标记邮件', send_email:'发送邮件',
            get_mail_presets:'读取邮箱预设', add_mail_account:'添加邮箱账户', update_mail_account:'修改邮箱账户', test_mail_account:'测试邮箱连接',
            sync_mail_account:'收取邮件', remove_mail_account:'删除邮箱账户',
            open_email:'打开邮件', update_email_analysis:'校正邮件分析',
            highlight_text:'荧光笔高亮正文', list_text_highlights:'读取正文高亮', clear_text_highlights:'清除正文高亮' };
        if (step.type === 'think') {
            div.innerHTML = `<span class="ai-step-icon">💭</span> <span>${escapeHtml(step.content)}</span>`;
        } else if (step.type === 'tool') {
            const statusIcon = step.status === 'done' ? '✅' : step.status === 'error' ? '❌' : '⏳';
            const label = names[step.name] || step.name;
            const notice = step.notice ? ` <span class="ai-step-notice">${escapeHtml(step.notice)}</span>` : '';
            div.innerHTML = `<span class="ai-step-icon">${statusIcon}</span> <span>${escapeHtml(label)}</span>${notice}`;
            div.className += ' ai-step-tool';
        }
        this.messagesEl.appendChild(div);
        this._scrollBottom();
    },

    // ===== UI HELPERS =====
    _addMsg(role, content, instant) {
        const div = document.createElement('div');
        div.className = `ai-message ai-message-${role}`;
        div.innerHTML = `<div class="ai-avatar">${role === 'user' ? '👤' : '🤖'}</div>
            <div class="ai-bubble">${instant ? this._md(content) : ''}</div>`;
        this.messagesEl.appendChild(div);
        this._scrollBottom();
    },

    _addWelcome() {
        this._addMsg('assistant', '你好！我是你的科研工作智能助手，在主页、邮箱、报告等各处都是同一个我。\n\n我可以帮你：\n- **查询/分析**：任务、人员、成果、工作量、日历\n- **邮件**：读取、整理邮件与附件，判断与你的相关度，起草回复\n- **记忆**：在交流中逐步了解你的职称、职位与工作重心（可在“个人侧写”查看）\n- **操作**：创建/更新任务、记录工作量、发送邮件等（需你确认后执行）\n\n请问有什么可以帮你的？', true);
    },

    _showTyping() {
        const div = document.createElement('div');
        div.className = 'ai-message ai-message-assistant ai-typing';
        div.innerHTML = `<div class="ai-avatar">🤖</div><div class="ai-bubble"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>`;
        this.messagesEl.appendChild(div);
        this._scrollBottom();
    },

    _showMeta(data) {
        const lastMsg = this.messagesEl.querySelector('.ai-message-assistant:last-of-type .ai-bubble');
        if (!lastMsg) return;
        const model = data.model || '';
        const usage = data.usage || {};
        const parts = [];
        if (model) parts.push(model);
        if (usage.prompt_tokens) {
            const ctx = usage.prompt_tokens + (usage.completion_tokens || 0);
            const max = 65536;
            parts.push('CTX: ' + ctx + '/' + max + ' (' + Math.round(ctx / max * 100) + '%)');
        }
        if (parts.length) {
            const meta = document.createElement('div');
            meta.className = 'ai-meta';
            meta.textContent = parts.join(' · ');
            lastMsg.appendChild(meta);
        }
    },

    _hideTyping() {
        const el = this.messagesEl ? this.messagesEl.querySelector('.ai-typing') : null;
        if (el) el.remove();
        if (this.typingTimer) { clearTimeout(this.typingTimer); this.typingTimer = null; }
    },

    _fmtArgs(name, args) {
        const p = [];
        if (args.name) p.push(`名称: ${escapeHtml(args.name)}`);
        if (args.description) p.push(`描述: ${escapeHtml(args.description)}`);
        if (args.stage) { const m={in_progress:'进行中',stage_complete:'阶段性完成',completed:'已完成',failed:'失败/放弃'}; p.push(`阶段: ${m[args.stage]||args.stage}`); }
        if (args.archived !== undefined) p.push(`归档: ${args.archived?'是':'否'}`);
        if (args.color) p.push(`颜色: <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${args.color};vertical-align:middle;"></span> ${args.color}`);
        if (args.task_id) p.push(`任务ID: ${args.task_id}`);
        if (args.date) p.push(`日期: ${args.date}`);
        if (args.planned_date) p.push(`计划日期: ${args.planned_date}`);
        if (args.relationship) p.push(`关系: ${escapeHtml(args.relationship)}`);
        if (args.id && (name === 'mark_email')) p.push(`邮件ID: ${args.id}`);
        if (args.seen !== undefined) p.push(`已读: ${args.seen ? '是' : '否'}`);
        if (args.flagged !== undefined) p.push(`星标: ${args.flagged ? '是' : '否'}`);
        if (args.to) p.push(`收件人: ${escapeHtml(Array.isArray(args.to) ? args.to.join(', ') : String(args.to))}`);
        if (args.cc && (Array.isArray(args.cc) ? args.cc.length : args.cc)) p.push(`抄送: ${escapeHtml(Array.isArray(args.cc) ? args.cc.join(', ') : String(args.cc))}`);
        if (args.subject) p.push(`主题: ${escapeHtml(args.subject)}`);
        if (args.body) p.push(`正文: <div class="confirm-body-preview">${escapeHtml(String(args.body))}</div>`);
        if (args.in_reply_to_id) p.push(`回复邮件ID: ${args.in_reply_to_id}`);
        if (name === 'update_email_analysis') {
            const fields = [
                ['relevance', '相关度', v => v + '%'], ['priority', '优先级', v => 'P' + v],
                ['deadline_hint', '截止时间', v => v === '' ? '(清除)' : v],
                ['brief_title', '简明标题', v => v], ['category', '类别', v => v],
                ['needs_reply', '需要回复', v => v ? '是' : '否'],
                ['summary', '摘要', v => v], ['detailed_md', '详细分析', () => '(重写)'],
                ['actions', '建议行动', v => (Array.isArray(v) ? v.length : 0) + ' 条'],
            ];
            p.length = 0;   // this tool's own, clearer rendering
            p.push(`邮件ID: ${args.id}`);
            for (const [key, label, fmt] of fields) {
                if (args[key] === undefined) continue;
                p.push(`<b>${label}</b> → ${escapeHtml(String(fmt(args[key])))}`);
            }
            p.push('<span class="mail-hint">确认后该分析会标记为人工校正，不再被自动分析覆盖</span>');
        }
        if (name === 'add_mail_account' || name === 'update_mail_account' || name === 'remove_mail_account') {
            if (args.id) p.push(`账户ID: ${args.id}`);
            if (args.email) p.push(`邮箱: ${escapeHtml(args.email)}`);
            if (args.preset) p.push(`预设: ${escapeHtml(args.preset)}`);
            if (args.imap_host) p.push(`IMAP: ${escapeHtml(args.imap_host)}${args.imap_port ? ':' + args.imap_port : ''}${args.imap_ssl ? ' (' + escapeHtml(args.imap_ssl) + ')' : ''}`);
            if (args.smtp_host) p.push(`SMTP: ${escapeHtml(args.smtp_host)}${args.smtp_port ? ':' + args.smtp_port : ''}${args.smtp_ssl ? ' (' + escapeHtml(args.smtp_ssl) + ')' : ''}`);
            if (args.username) p.push(`用户名: ${escapeHtml(args.username)}`);
            if (args.enabled !== undefined) p.push(`启用: ${args.enabled ? '是' : '否'}`);
            if (!args.imap_host && args.email && name === 'add_mail_account') p.push('<span class="mail-hint">服务器参数将按邮箱域名预设自动填充</span>');
        }
        return p.join('<br>') || '(无参数)';
    },

    // ===== MARKDOWN =====
    _md(text) {
        if (!text) return '';
        let html = escapeHtml(text);
        html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
        html = html.replace(/^### (.+)$/gm, '<h4>$1</h4>');
        html = html.replace(/^## (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^# (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
        html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
        html = html.replace(/^---$/gm, '<hr>');
        html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
        html = html.replace(/((?:^\|.+\|\n?)+)/gm, function(match) {
            const lines = match.trim().split('\n');
            if (lines.length < 2) return match;
            const rows = lines.filter(l => !/^\|[\s\-:]+\|/.test(l));
            if (rows.length === 0) return match;
            const thead = '<thead><tr>' + rows[0].split('|').filter(c => c.trim()).map(c => '<th>' + c.trim() + '</th>').join('') + '</tr></thead>';
            const tbody = rows.length > 1 ? '<tbody>' + rows.slice(1).map(row => '<tr>' + row.split('|').filter(c => c.trim()).map(c => '<td>' + c.trim() + '</td>').join('') + '</tr>').join('') + '</tbody>' : '';
            return '<table>' + thead + tbody + '</table>';
        });
        html = html.replace(/\n\n/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');
        html = '<p>' + html + '</p>';
        html = html.replace(/<p><\/p>/g, '');
        html = html.replace(/<p>(<[huol])/g, '$1');
        html = html.replace(/(<\/[huol]>|<\/li>)<\/p>/g, '$1');
        return html;
    },
};
