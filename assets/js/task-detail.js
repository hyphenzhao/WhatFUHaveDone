/**
 * Task detail modal — opened by clicking a daily-status task card (outside its buttons).
 * One read shows everything; mail matching is a separate, manual action.
 */
const TaskDetail = {
    taskId: 0,
    data: null,
    matching: false,

    stageLabel: { in_progress: '🔄 进行中', stage_complete: '✅ 阶段性完成', completed: '🎉 已完成', failed: '❌ 失败/放弃' },

    async open(taskId) {
        this.taskId = taskId;
        this.data = null;
        Modal.open({ title: '📌 任务详情', size: 'wide', body: '<div class="td-loading">加载中...</div>', footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>' });
        try {
            this.data = (await API.get('/task_detail/' + taskId)).data;
        } catch (e) { Modal.setBody(`<div class="td-loading">加载失败: ${escapeHtml(e.message)}</div>`); return; }
        if (this.taskId !== taskId) return;
        Modal.setTitle('📌 ' + this.data.name);
        Modal.setBody(this.render());
    },

    stars(n) { n = parseInt(n, 10) || 0; return '★'.repeat(n) + '☆'.repeat(Math.max(0, 5 - n)); },

    render() {
        const t = this.data;
        const tags = (t.tags || []).map(g => `<span class="task-card-tag" style="background:${escapeHtml(g.color)}">${escapeHtml(g.name)}</span>`).join(' ');
        const people = (t.people || []).map(p => `<span class="td-chip">👤 ${escapeHtml(p.name)}${p.relationship ? '（' + escapeHtml(p.relationship) + '）' : ''}</span>`).join(' ');
        const results = (t.results || []).map(r => `<span class="td-chip">🏆 ${escapeHtml(r.name)}${r.level ? ' · ' + escapeHtml(r.level) : ''}</span>`).join(' ');
        const deadline = t.deadline ? (typeof getDeadlineBadge === 'function' ? getDeadlineBadge(t.deadline) : escapeHtml(t.deadline)) : '';
        const s = t.work_log_stats || {};
        const plans = (t.plans || []).map(p => `<span class="td-chip">📅 ${escapeHtml(p.planned_date)}${p.plan_time ? ' ' + escapeHtml(p.plan_time) + (p.plan_end_time ? '-' + escapeHtml(p.plan_end_time) : '') : ''}</span>`).join(' ');
        const resultLogs = (t.result_logs || []).map(r => `<span class="td-chip">🏆 ${escapeHtml(r.log_date)} ${escapeHtml(r.name)}</span>`).join(' ');

        return `
        <div class="td-meta">
            <span class="td-stage">${this.stageLabel[t.stage] || escapeHtml(t.stage)} · 第 ${t.stage_number} 阶段</span>
            ${deadline ? `<span>${deadline}</span>` : ''}
            <span class="td-muted">重要 ${this.stars(t.importance)} · 必要 ${this.stars(t.necessity)}</span>
            ${t.location ? `<span>📍 ${escapeHtml(t.location)}</span>` : ''}
        </div>
        ${t.description ? `<div class="td-desc">${escapeHtml(t.description)}</div>` : ''}
        ${tags ? `<div class="td-row"><span class="td-label">标签</span><div>${tags}</div></div>` : ''}
        ${people ? `<div class="td-row"><span class="td-label">人物</span><div>${people}</div></div>` : ''}
        ${results ? `<div class="td-row"><span class="td-label">成果</span><div>${results}</div></div>` : ''}
        ${plans ? `<div class="td-row"><span class="td-label">计划</span><div>${plans}</div></div>` : ''}
        ${resultLogs ? `<div class="td-row"><span class="td-label">产出</span><div>${resultLogs}</div></div>` : ''}

        <div class="td-section">
            <div class="td-section-head"><h4>📋 工作日志</h4><span class="td-muted">${s.days ? `累计 ${s.days} 天（${escapeHtml(s.first_day || '')} ~ ${escapeHtml(s.last_day || '')}）` : '暂无工作量记录'}</span></div>
            <div class="td-logs">${this.renderLogs(t.work_logs || [])}</div>
        </div>

        <div class="td-section" id="tdMailSection">${this.renderMail()}</div>`;
    },

    renderLogs(logs) {
        if (!logs.length) return '<div class="td-empty">暂无</div>';
        return logs.map(l => `<div class="td-log">
            <div class="td-log-date">${escapeHtml(l.log_date)}${l.duration ? ` <span class="wl-dur-tag" style="cursor:default;">⏱️ ${escapeHtml(l.duration)}</span>` : ''}</div>
            ${(l.notes || []).length ? l.notes.map(n => `<div class="td-note"><span class="td-muted">${escapeHtml((n.created_at || '').substring(11, 16))}</span> ${escapeHtml(n.content)}${n.attachments ? ` <button class="td-linkbtn" onclick="Attach.openModal('worklog_note', ${n.id}, '备注附件')" title="附件">📎${n.attachments}</button>` : ''}</div>`).join('') : '<div class="td-note td-muted">（无备注）</div>'}
        </div>`).join('');
    },

    renderMail() {
        const m = (this.data && this.data.mail) || {};
        if (!m.available) return '';
        const items = m.items || [];
        let action;
        if (!m.ai_configured) action = '<span class="td-muted">请先配置AI</span>';
        else if (this.matching) action = '<button class="btn btn-primary btn-sm" disabled id="tdMatchBtn">⏳ 比对中...</button>';
        else if (m.pending > 0) action = `<button class="btn btn-primary btn-sm" id="tdMatchBtn" onclick="TaskDetail.match()">🔗 匹配邮件（${m.pending} 封待比对）</button>`;
        else action = '<span class="td-muted">已全部比对 ✓</span>';
        const list = items.length ? items.map(it => {
            const strong = it.status === 'confirmed' || it.score >= 75;
            const title = it.brief_title || it.subject || '(无主题)';
            return `<div class="td-mail ${strong ? '' : 'td-mail-weak'}">
                <div class="td-mail-main" onclick="TaskDetail.openMail(${it.message_id})" title="打开邮件">
                    <div class="td-mail-row1"><span class="mail-badge ${it.status === 'confirmed' ? 'mail-rel-hi' : strong ? 'mail-rel-mid' : 'mail-rel-lo'}">${it.status === 'confirmed' ? '已确认' : (strong ? '相关 ' : '可能 ') + it.score}</span>
                        <span class="td-mail-title ${it.is_seen ? '' : 'unseen'}">${escapeHtml(title)}</span><span class="td-muted">${typeof MailUI !== 'undefined' ? MailUI.fmtDate(it.msg_date) : escapeHtml(it.msg_date || '')}</span></div>
                    <div class="td-mail-row2">${escapeHtml(it.from_name || it.from_email || '')}${it.reason ? ' · <i>' + escapeHtml(it.reason) + '</i>' : ''}</div>
                </div>
                <div class="td-mail-actions">
                    ${it.status === 'confirmed' ? '' : `<button class="td-linkbtn" title="确认相关" onclick="TaskDetail.verdict(${it.message_id}, 'confirmed')">✓</button>`}
                    <button class="td-linkbtn" title="不相关，排除" onclick="TaskDetail.verdict(${it.message_id}, 'rejected')">✗</button>
                </div></div>`;
        }).join('') : `<div class="td-empty">${m.pending > 0 ? '还没有匹配过，点击右上角按钮开始（只比对已由 AI 分析过的邮件）。' : '没有相关邮件。'}</div>`;
        return `<div class="td-section-head"><h4>📧 相关邮件</h4><span id="tdMatchStatus" class="td-muted"></span><span style="flex:1"></span>${action}</div>
                <div class="td-mails">${list}</div>`;
    },

    refreshMail() { const el = document.getElementById('tdMailSection'); if (el) el.innerHTML = this.renderMail(); },

    async match() {
        if (this.matching || !this.data) return;
        const taskId = this.taskId;
        this.matching = true; this.refreshMail();
        let checked = 0, matched = 0, rounds = 0;
        try {
            while (rounds++ < 12 && this.taskId === taskId) {
                const r = (await API.post(`/task_detail/${taskId}/match`, {})).data;
                checked += r.checked; matched += r.matched;
                if (this.taskId !== taskId) break;
                this.data.mail.items = r.items; this.data.mail.pending = r.remaining;
                this.refreshMail();
                const st = document.getElementById('tdMatchStatus');
                if (st) st.textContent = `已比对 ${checked} 封，匹配 ${matched} 封${r.remaining ? `，剩余 ${r.remaining}` : ''}`;
                if (!r.remaining || !r.checked) break;
            }
            Toast.success(`比对完成：${checked} 封中匹配 ${matched} 封`);
        } catch (e) { Toast.error(e.message); }
        finally { this.matching = false; if (this.taskId === taskId) this.refreshMail(); }
    },

    /** Open the email modal; closing it returns to this task's detail. */
    async openMail(messageId) {
        const taskId = this.taskId;
        await openMailModal(messageId);
        const prev = Modal._onClose;
        Modal._onClose = (r) => { if (prev) prev(r); TaskDetail.open(taskId); };
    },

    async verdict(messageId, status) {
        try {
            const r = await API.put(`/task_detail/${this.taskId}/mails/${messageId}`, { status });
            this.data.mail.items = r.data.items;
            this.refreshMail();
        } catch (e) { Toast.error(e.message); }
    },
};

// Click anywhere on a daily-status card except its interactive controls → open the detail modal
document.addEventListener('click', (e) => {
    const card = e.target.closest('#dailyStatusCards .daily-card[data-task-id]');
    if (!card) return;
    if (e.target.closest('button, input, select, textarea, a, .wl-dur-tag, .wl-log-btn, .wl-note-add, .wl-note-del, .wl-note-attach')) return;
    if (window.getSelection && String(window.getSelection())) return;   // user is selecting text
    TaskDetail.open(parseInt(card.dataset.taskId, 10));
});
