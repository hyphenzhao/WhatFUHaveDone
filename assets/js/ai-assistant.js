/**
 * AI Assistant — chat UI with tool call confirmation
 */
const AiChat = {
    messages: [],
    element: null,
    messagesEl: null,
    inputEl: null,
    sendBtn: null,
    isWaiting: false,
    pendingCalls: null,

    init(container) {
        this.element = container;
        container.innerHTML = `
            <div class="ai-chat-container">
                <div class="ai-messages" id="aiMessages"></div>
                <div class="ai-input-area">
                    <textarea class="ai-input" id="aiInput" rows="1" placeholder="输入消息... (Enter 发送)"></textarea>
                    <button class="ai-send-btn" id="aiSendBtn">发送</button>
                </div>
            </div>`;
        this.messagesEl = container.querySelector('#aiMessages');
        this.inputEl = container.querySelector('#aiInput');
        this.sendBtn = container.querySelector('#aiSendBtn');

        this.sendBtn.addEventListener('click', () => this.send());
        this.inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
        });
        this._addWelcome();
    },

    _addWelcome() {
        this._addMsg('assistant', '你好！我是你的工作日志智能助手。\n\n我可以帮你：\n- **查询**：任务、人员、标签、成果、工作量等\n- **分析**：本月工作量分布、成果排名、日历概览\n- **操作**：创建/更新任务、记录工作量等（需你确认后执行）\n\n请问有什么可以帮你的？');
    },

    async send(text) {
        if (this.isWaiting) return;
        text = text || this.inputEl.value.trim();
        if (!text) return;

        this._addMsg('user', text);
        this.messages.push({ role: 'user', content: text });
        this.inputEl.value = '';
        this.isWaiting = true;
        this.sendBtn.disabled = true;
        this._showTyping();

        try {
            const res = await API.post('/ai/chat', { messages: this.messages });
            this._hideTyping();

            if (res.data.type === 'text') {
                this._addMsg('assistant', res.data.content);
                this.messages.push(res.data.message || { role: 'assistant', content: res.data.content });
            } else if (res.data.type === 'confirmation') {
                this.pendingCalls = res.data.pending_calls;
                this.messages.push(res.data.message);
                this._showToolCalls(res.data.pending_calls);
                this._showConfirmation(res.data.pending_calls);
            }
        } catch (e) {
            this._hideTyping();
            this._addMsg('assistant', '⚠️ 请求失败: ' + (e.message || '未知错误'));
        } finally {
            this.isWaiting = false;
            this.sendBtn.disabled = false;
        }
    },

    _showConfirmation(calls) {
        const items = calls.map(c => {
            const icons = { create_task:'➕', update_task:'✏️', delete_task:'🗑️', create_person:'➕', update_person:'✏️', create_tag:'➕', update_tag:'✏️', toggle_worklog:'📝', add_plan:'📅', add_result_log:'🏆' };
            const names = { create_task:'创建任务', update_task:'更新任务', delete_task:'删除任务', create_person:'创建人物', update_person:'更新人物', create_tag:'创建标签', update_tag:'更新标签', toggle_worklog:'切换工作量', add_plan:'添加计划', add_result_log:'添加成果记录' };
            return `<div class="confirm-item">
                <div class="confirm-item-icon">${icons[c.name] || '🔧'}</div>
                <div class="confirm-item-detail">
                    <div class="confirm-item-name">${names[c.name] || c.name}</div>
                    <div class="confirm-item-args">${this._fmtArgs(c.name, c.arguments)}</div>
                </div>
            </div>`;
        }).join('');

        Modal.open({
            title: '🤖 AI 操作确认',
            body: `<p style="margin-bottom:12px;color:var(--color-text-secondary);">智能助手请求执行以下操作，请确认：</p>
                <div class="ai-confirm-list">${items}</div>`,
            footer: `<button class="btn btn-ghost" id="aiReject">拒绝</button>
                <button class="btn btn-primary" id="aiApprove">确认执行</button>`,
        });
        document.getElementById('aiApprove').addEventListener('click', () => this._onConfirm(true));
        document.getElementById('aiReject').addEventListener('click', () => this._onConfirm(false));
    },

    async _onConfirm(approved) {
        Modal.close();
        this.isWaiting = true;
        this._showTyping();
        try {
            const res = await API.post('/ai/confirm', {
                messages: this.messages.slice(0, -1),
                message: this.messages[this.messages.length - 1],
                confirmations: this.pendingCalls.map(c => ({ id: c.id, action: approved ? 'confirm' : 'reject' })),
            });
            this._hideTyping();
            if (res.data.type === 'text') {
                this._addMsg('assistant', res.data.content);
                this.messages.push(res.data.message || { role: 'assistant', content: res.data.content });
                if (approved && typeof refreshAll === 'function') refreshAll();
            }
        } catch (e) {
            this._hideTyping();
            this._addMsg('assistant', '⚠️ 操作失败: ' + (e.message || '未知错误'));
        } finally {
            this.isWaiting = false;
            this.pendingCalls = null;
            this.sendBtn.disabled = false;
        }
    },

    // --- UI helpers ---
    _addMsg(role, content) {
        const div = document.createElement('div');
        div.className = `ai-message ai-message-${role}`;
        div.innerHTML = `<div class="ai-avatar">${role === 'user' ? '👤' : '🤖'}</div>
            <div class="ai-bubble">${this._renderContent(content)}</div>`;
        this.messagesEl.appendChild(div);
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    },

    _renderContent(text) {
        if (!text) return '';
        return escapeHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    },

    _showTyping() {
        const div = document.createElement('div');
        div.className = 'ai-message ai-message-assistant ai-typing';
        div.id = 'aiTyping';
        div.innerHTML = `<div class="ai-avatar">🤖</div><div class="ai-bubble"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>`;
        this.messagesEl.appendChild(div);
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    },

    _hideTyping() { const el = document.getElementById('aiTyping'); if (el) el.remove(); },

    _showToolCalls(calls) {
        const names = { create_task:'创建任务', update_task:'更新任务', delete_task:'删除任务', create_person:'创建人物', update_person:'更新人物', create_tag:'创建标签', update_tag:'更新标签', toggle_worklog:'切换工作量', add_plan:'添加计划', add_result_log:'添加成果记录' };
        calls.forEach(c => {
            const div = document.createElement('div');
            div.className = 'ai-tool-call';
            div.innerHTML = `<span>🔧</span><span>${names[c.name] || c.name}</span><span class="ai-tool-status pending">等待确认</span>`;
            this.messagesEl.appendChild(div);
        });
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
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
        return p.join('<br>') || '(无参数)';
    },

    clear() {
        this.messages = [];
        this.messagesEl.innerHTML = '';
        this._addWelcome();
    },
};
