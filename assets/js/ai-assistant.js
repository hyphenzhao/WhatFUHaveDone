/**
 * AI Assistant — chat UI with conversation history & typing animation
 */
const AiChat = {
    convId: null,
    messages: [],
    element: null,
    messagesEl: null,
    inputEl: null,
    sendBtn: null,
    isWaiting: false,
    pendingCalls: null,
    typingTimer: null,

    async init(container) {
        this.element = container;
        container.innerHTML = `
            <div class="ai-chat-container">
                <div class="ai-conv-bar">
                    <select class="ai-conv-select" id="aiConvSelect"><option value="">+ 新对话</option></select>
                    <button class="ai-conv-del" id="aiConvDel" title="删除当前对话">🗑️</button>
                </div>
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

        document.getElementById('aiConvSelect').addEventListener('change', () => this._switchConv());
        document.getElementById('aiConvDel').addEventListener('click', () => this._deleteConv());

        await this._loadConvList();
        this._startNew();
    },

    // ===== CONVERSATION MANAGEMENT =====
    async _loadConvList() {
        try {
            const res = await API.get('/ai/conversations');
            const convs = res.data || [];
            const sel = document.getElementById('aiConvSelect');
            sel.innerHTML = '<option value="">+ 新对话</option>' +
                convs.map(c => `<option value="${c.id}">${escapeHtml(c.title)}</option>`).join('');
        } catch (e) { /* ignore */ }
    },

    async _switchConv() {
        const id = document.getElementById('aiConvSelect').value;
        if (!id) { this._startNew(); return; }
        // Load conversation messages from server
        try {
            const res = await API.get('/ai/conversations');
            const conv = (res.data || []).find(c => c.id == id);
            if (conv) {
                // We need full messages — fetch via a detail endpoint or store in the list
                // For now, reload the full conversation via another call
                const detail = await API.get('/ai/conversations/' + id);
                this.convId = id;
                this.messages = [];
                this.messagesEl.innerHTML = '';
                const msgs = detail.data?.messages || [];
                msgs.forEach(m => {
                    if (m.role === 'user' || m.role === 'assistant') {
                        this._addMsg(m.role, m.content, true);
                    }
                });
                this.messages = msgs;
            }
        } catch (e) { this._startNew(); }
    },

    async _deleteConv() {
        if (!this.convId) { Toast.error('没有选中的对话'); return; }
        if (!confirm('确定删除此对话？')) return;
        try {
            await API.delete('/ai/conversations/' + this.convId);
            this._startNew();
            await this._loadConvList();
            Toast.success('对话已删除');
        } catch (e) { Toast.error('删除失败'); }
    },

    _startNew() {
        this.convId = null;
        this.messages = [];
        this.messagesEl.innerHTML = '';
        document.getElementById('aiConvSelect').value = '';
        this._addWelcome();
    },

    async _saveConv(title) {
        try {
            if (this.convId) {
                await API.put('/ai/conversations/' + this.convId, { title: title || undefined, messages: this.messages });
            } else {
                const res = await API.post('/ai/conversations', { title: title || '新对话', messages: this.messages });
                this.convId = res.data.id;
            }
            await this._loadConvList();
            document.getElementById('aiConvSelect').value = this.convId || '';
        } catch (e) { /* ignore save errors */ }
    },

    // ===== SENDING =====
    async send(text) {
        if (this.isWaiting) return;
        text = text || this.inputEl.value.trim();
        if (!text) return;

        this._addMsg('user', text, true);
        this.messages.push({ role: 'user', content: text });
        this.inputEl.value = '';
        this.isWaiting = true;
        this.sendBtn.disabled = true;
        this._showTyping();

        // Add a thinking indicator step
        const thinkingStep = document.createElement('div');
        thinkingStep.className = 'ai-step';
        thinkingStep.innerHTML = '<span class="ai-step-icon">🤔</span> <span>AI 分析中，请稍候…</span>';
        this.messagesEl.appendChild(thinkingStep);
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;

        try {
            const ctrl = new AbortController();
            const timeout = setTimeout(() => ctrl.abort(), 120000); // 2 min timeout
            const res = await fetch('/api/ai/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messages: this.messages }),
                signal: ctrl.signal,
            }).then(r => r.json());
            clearTimeout(timeout);
            if (thinkingStep.parentNode) thinkingStep.remove();
            if (res.error) throw new Error(res.message);
            this._hideTyping();

            if (res.data.type === 'text' || res.data.type === 'steps') {
                const steps = res.data.steps || [];
                // Show each step with delay
                for (const step of steps) {
                    await this._showStep(step);
                    await new Promise(r => setTimeout(r, 200));
                }
                this.messages.push(res.data.message || { role: 'assistant', content: res.data.content });
                await this._typeText(res.data.content);
                const title = this.messages.length <= 2
                    ? this._genTitle(this.messages[0].content)
                    : undefined;
                await this._saveConv(title);
            } else if (res.data.type === 'confirmation') {
                this.pendingCalls = res.data.pending_calls;
                this.messages.push(res.data.message);
                // Show plan steps if any
                const steps = res.data.steps || [];
                for (const step of steps) {
                    await this._showStep(step);
                    await new Promise(r => setTimeout(r, 100));
                }
                this._showToolCalls(res.data.pending_calls);
                this._showConfirmation(res.data.pending_calls);
            }
        } catch (e) {
            if (thinkingStep.parentNode) thinkingStep.remove();
            this._hideTyping();
            const msg = e.name === 'AbortError' ? '请求超时，AI 响应时间过长。请尝试简化问题或检查 API 配置。' : '⚠️ 请求失败: ' + (e.message || '未知错误');
            this._addMsg('assistant', msg, true);
        } finally {
            this.isWaiting = false;
            this.sendBtn.disabled = false;
        }
    },

    _genTitle(text) {
        return text.replace(/\n/g, ' ').substring(0, 20) + (text.length > 20 ? '…' : '');
    },

    // ===== TYPING ANIMATION =====
    async _typeText(text) {
        if (!text) return;
        // Create the bubble with empty content
        const div = document.createElement('div');
        div.className = 'ai-message ai-message-assistant';
        div.innerHTML = `<div class="ai-avatar">🤖</div><div class="ai-bubble"></div>`;
        this.messagesEl.appendChild(div);
        const bubble = div.querySelector('.ai-bubble');

        // Render markdown first, then animate character by character
        const rendered = this._md(text);
        // Use innerHTML manipulation: reveal progressively
        // Simpler approach: type raw text, then snap to rendered HTML at the end
        let i = 0;
        const speed = 15; // ms per char
        return new Promise(resolve => {
            const tick = () => {
                if (i >= text.length) {
                    bubble.innerHTML = rendered;
                    this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
                    resolve();
                    return;
                }
                i = Math.min(i + 3, text.length); // 3 chars per tick
                const partial = text.substring(0, i);
                bubble.innerHTML = this._md(partial) + '<span class="typing-cursor">|</span>';
                this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
                this.typingTimer = setTimeout(tick, speed);
            };
            tick();
        });
    },

    // ===== CONFIRMATION =====
    _showConfirmation(calls) {
        const icons = { create_task:'➕', update_task:'✏️', delete_task:'🗑️', create_person:'➕', update_person:'✏️', create_tag:'➕', update_tag:'✏️', toggle_worklog:'📝', add_plan:'📅', add_result_log:'🏆' };
        const names = { create_task:'创建任务', update_task:'更新任务', delete_task:'删除任务', create_person:'创建人物', update_person:'更新人物', create_tag:'创建标签', update_tag:'更新标签', toggle_worklog:'切换工作量', add_plan:'添加计划', add_result_log:'添加成果记录' };
        const items = calls.map(c => `<div class="confirm-item">
            <div class="confirm-item-icon">${icons[c.name] || '🔧'}</div>
            <div class="confirm-item-detail"><div class="confirm-item-name">${names[c.name] || c.name}</div>
            <div class="confirm-item-args">${this._fmtArgs(c.name, c.arguments)}</div></div></div>`).join('');

        Modal.open({
            title: '🤖 AI 操作确认',
            body: `<p style="margin-bottom:12px;color:var(--color-text-secondary);">智能助手请求执行以下操作，请确认：</p><div class="ai-confirm-list">${items}</div>`,
            footer: `<button class="btn btn-ghost" id="aiReject">拒绝</button><button class="btn btn-primary" id="aiApprove">确认执行</button>`,
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
            if (res.data.type === 'text' || res.data.type === 'steps') {
                const steps = res.data.steps || [];
                for (const step of steps) {
                    await this._showStep(step);
                    await new Promise(r => setTimeout(r, 150));
                }
                this.messages.push(res.data.message || { role: 'assistant', content: res.data.content });
                await this._typeText(res.data.content);
                if (approved && typeof refreshAll === 'function') refreshAll();
                await this._saveConv();
            }
        } catch (e) {
            this._hideTyping();
            this._addMsg('assistant', '⚠️ 操作失败: ' + (e.message || '未知错误'), true);
        } finally {
            this.isWaiting = false;
            this.pendingCalls = null;
            this.sendBtn.disabled = false;
        }
    },

    // ===== STEP DISPLAY =====
    async _showStep(step) {
        const div = document.createElement('div');
        div.className = 'ai-step';
        const names = { list_tasks:'拉取任务列表', get_task:'获取任务详情', list_people:'拉取人员信息', list_tags:'拉取标签',
            list_results:'拉取成果', get_worklogs_by_date:'拉取工作量', get_workload_stats:'拉取工作量统计',
            get_results_stats:'拉取成果统计', get_calendar_data:'拉取日历数据', get_daily_status:'拉取当日状态',
            get_relationships:'拉取人际关系', create_task:'创建任务', update_task:'更新任务', toggle_worklog:'记录工作量',
            add_plan:'添加计划', add_result_log:'记录成果' };
        if (step.type === 'think') {
            div.innerHTML = `<span class="ai-step-icon">💭</span> <span>${escapeHtml(step.content)}</span>`;
        } else if (step.type === 'tool') {
            const statusIcon = step.status === 'done' ? '✅' : step.status === 'error' ? '❌' : step.status === 'confirm' ? '⏳' : '⏳';
            const label = names[step.name] || step.name;
            div.innerHTML = `<span class="ai-step-icon">${statusIcon}</span> <span>${escapeHtml(label)}</span>`;
            div.className += ' ai-step-tool';
        }
        this.messagesEl.appendChild(div);
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    },

    // ===== UI HELPERS =====
    _addMsg(role, content, instant) {
        const div = document.createElement('div');
        div.className = `ai-message ai-message-${role}`;
        div.innerHTML = `<div class="ai-avatar">${role === 'user' ? '👤' : '🤖'}</div>
            <div class="ai-bubble">${instant ? this._md(content) : ''}</div>`;
        this.messagesEl.appendChild(div);
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    },

    _addWelcome() {
        this._addMsg('assistant', '你好！我是你的工作日志智能助手。\n\n我可以帮你：\n- **查询**：任务、人员、标签、成果、工作量等\n- **分析**：本月工作量分布、成果排名、日历概览\n- **操作**：创建/更新任务、记录工作量等（需你确认后执行）\n\n请问有什么可以帮你的？', true);
    },

    _showTyping() {
        const div = document.createElement('div');
        div.className = 'ai-message ai-message-assistant ai-typing';
        div.id = 'aiTyping';
        div.innerHTML = `<div class="ai-avatar">🤖</div><div class="ai-bubble"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>`;
        this.messagesEl.appendChild(div);
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    },

    _hideTyping() {
        const el = document.getElementById('aiTyping'); if (el) el.remove();
        if (this.typingTimer) { clearTimeout(this.typingTimer); this.typingTimer = null; }
    },

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
