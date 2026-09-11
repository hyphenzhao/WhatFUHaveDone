/**
 * Periodic Reports page controller (calendar-centric).
 * Depends on globals: API, Calendar, Attach, Modal, Toast, escapeHtml, App, today().
 */

// Self-contained Markdown renderer (mirrors home.js `md`; reports page doesn't load home.js).
function md(text) {
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
    html = html.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>');
    html = html.replace(/^---$/gm, '<hr>');
    html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
    html = html.replace(/((?:^\|.+\|\n?)+)/gm, function (match) {
        const lines = match.trim().split('\n');
        if (lines.length < 2) return match;
        const rows = lines.filter(l => !/^\|[\s\-:]+\|/.test(l));
        if (rows.length === 0) return match;
        const thead = '<thead><tr>' + rows[0].split('|').filter(c => c.trim()).map(c => '<th>' + c.trim() + '</th>').join('') + '</tr></thead>';
        const tbody = rows.length > 1 ? '<tbody>' + rows.slice(1).map(row => '<tr>' + row.split('|').filter(c => c.trim()).map(c => '<td>' + c.trim() + '</td>').join('') + '</tr>').join('') + '</tbody>' : '';
        return '<table class="report-table">' + thead + tbody + '</table>';
    });
    html = html.replace(/\n\n/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');
    html = '<p>' + html + '</p>';
    html = html.replace(/<p><\/p>/g, '');
    return html;
}

const Reports = {
    gran: 'monthly',

    async init() {
        if (!App.selectedDate) App.setDate(today());
        Calendar.onSelect = (d) => this.onDate(d);
        await Calendar.init();
        document.querySelectorAll('#reportGranTabs .period-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.gran = btn.dataset.gran;
                document.querySelectorAll('#reportGranTabs .period-btn').forEach(b => b.classList.toggle('active', b === btn));
                this.loadStats();
            });
        });
        this.updateHeader();
        this.loadStats();
    },

    date() { return App.selectedDate || today(); },

    onDate(d) {
        this.updateHeader();
        this.loadStats();
    },

    updateHeader() {
        const el = document.getElementById('reportSelDate');
        if (el) el.textContent = '📅 ' + this.date();
    },

    // ---- period key from the selected date ----
    _key(type) {
        const d = this.date();
        if (type === 'monthly') return d.substring(0, 7);
        if (type === 'yearly') return d.substring(0, 4);
        return d; // daily & weekly (backend normalizes weekly to that week's Monday)
    },

    // ================= Report popup =================

    async openReport(type) {
        const labels = { daily: '日报', weekly: '周报', monthly: '月报', yearly: '年报' };
        Modal.open({ title: '📊 ' + labels[type], body: '<div class="report-loading">加载中…</div>', footer: '' });
        await this._showReport(type);
    },

    async _showReport(type) {
        try {
            const r = (await API.reports.byPeriod(type, this._key(type))).data;
            if (r && r.exists) {
                this._lastMd = r.content_md || '';
                const attN = r.attachment_count ? ` (${r.attachment_count})` : '';
                Modal.setTitle('📊 ' + r.title);
                Modal.setBody(`<div class="report-content markdown-body">${md(r.content_md || '')}</div>`);
                Modal.setFooter(`
                    <button class="btn btn-ghost btn-sm" onclick="Reports.attach(${r.id},'${type}')">📎 参考文件${attN}</button>
                    <button class="btn btn-ghost btn-sm" onclick="Reports.copyMd()">📋 复制</button>
                    <button class="btn btn-danger btn-sm" onclick="Reports.removeReport(${r.id},'${type}')">🗑️ 删除</button>
                    <button class="btn btn-outline" onclick="Reports.generate('${type}')">🔄 重新生成</button>
                    <button class="btn btn-ghost" onclick="Modal.close()">关闭</button>`);
            } else {
                Modal.setTitle('📊 ' + (r ? r.title : labelFallback(type)));
                Modal.setBody(`
                    <div class="report-empty" style="padding:18px 8px;">本周期（${r ? r.period_start + ' ~ ' + r.period_end : ''}）暂无报告。</div>
                    <div class="form-group"><label>给 AI 的额外说明（可选）</label>
                        <textarea id="genNote" class="form-input" rows="2" placeholder="例如：本期重点关注 X 项目"></textarea></div>`);
                Modal.setFooter(`
                    <button class="btn btn-primary" onclick="Reports.generate('${type}')">✨ 生成报告</button>
                    <button class="btn btn-ghost" onclick="Modal.close()">关闭</button>`);
            }
        } catch (e) {
            Modal.setBody('<div class="report-empty" style="color:var(--color-danger);">加载失败: ' + escapeHtml(e.message) + '</div>');
        }
        function labelFallback(t) { return ({ daily: '日报', weekly: '周报', monthly: '月报', yearly: '年报' })[t]; }
    },

    async generate(type) {
        const note = (document.getElementById('genNote') || {}).value || '';
        Modal.setBody('<div class="report-loading">⏳ 正在统计数据并生成 AI 分析，请稍候…</div>');
        Modal.setFooter('');
        try {
            const res = await API.reports.generate(type, this._key(type), note);
            if (res.data && res.data.ai_error) Toast.error('AI 分析失败: ' + res.data.ai_error);
            else Toast.success('报告已生成');
            await this._showReport(type);
        } catch (e) {
            Toast.error('生成失败: ' + e.message);
            await this._showReport(type);
        }
    },

    attach(id, type) {
        Attach.openModal('report', id, '报告参考文件');
        // Reopen the report popup after the attachment sub-modal closes so the user can regenerate.
        Modal._onClose = () => { this.openReport(type); };
    },

    copyMd() {
        if (!this._lastMd) return;
        navigator.clipboard.writeText(this._lastMd).then(
            () => Toast.success('已复制 Markdown'),
            () => Toast.error('复制失败')
        );
    },

    async removeReport(id, type) {
        if (!confirm('确定删除该报告？（不影响任务/成果数据）')) return;
        try {
            await API.reports.remove(id);
            Toast.success('已删除');
            await this._showReport(type);
        } catch (e) { Toast.error('删除失败'); }
    },

    // ================= Statistics =================

    async loadStats() {
        const body = document.getElementById('reportStatsBody');
        const label = document.getElementById('reportRangeLabel');
        if (label) label.textContent = this._rangeLabel();
        if (!body) return;
        body.innerHTML = '<div class="no-daily-data">加载中...</div>';
        try {
            if (this.gran === 'daily') {
                const daily = (await API.stats.daily(this.date())).data || {};
                body.innerHTML = this._renderDaily(daily);
            } else {
                const period = { weekly: 'week', monthly: 'month', yearly: 'year' }[this.gran];
                const [wl, rs] = await Promise.all([
                    API.stats.workload(period, this.date(), true),
                    API.stats.results(period, this.date(), true),
                ]);
                body.innerHTML = `
                    <div class="leaderboards" style="margin-bottom:0;">
                        <div class="leaderboard-panel"><h3>💪 工作量（按标签）</h3>${this._bars(wl.data || [], true)}</div>
                        <div class="leaderboard-panel"><h3>🏆 成果（按标签）</h3>${this._bars(rs.data || [], false)}</div>
                    </div>`;
            }
        } catch (e) {
            body.innerHTML = '<div class="no-daily-data" style="color:var(--color-danger);">加载失败: ' + escapeHtml(e.message) + '</div>';
        }
    },

    _bars(items, isWorkload) {
        if (!items.length) return '<div class="no-daily-data">暂无数据</div>';
        const max = Math.max(...items.map(i => i.total_workload || i.total_results || 0), 1);
        return items.map((it, i) => {
            const n = it.total_workload || it.total_results || 0;
            const pct = max > 0 ? (n / max * 100) : 0;
            const c = it.color || '#3B82F6';
            return `<div class="leaderboard-item" style="cursor:default;">
                <span class="leaderboard-rank">#${i + 1}</span>
                <span class="leaderboard-tag"><span class="leaderboard-tag-color" style="background:${c}"></span>${escapeHtml(it.name)}</span>
                <div class="leaderboard-bar"><div class="leaderboard-bar-fill" style="width:${pct}%;background:${c};"></div></div>
                <span class="leaderboard-count">${n}</span>
            </div>`;
        }).join('');
    },

    _renderDaily(daily) {
        const work = daily.work_tasks || [];
        const results = daily.result_tasks || [];
        const plans = daily.plan_tasks || [];
        const tagDots = t => (t.tags || []).map(g => `<span class="report-tag-dot" style="background:${escapeHtml(g.color)}" title="${escapeHtml(g.name)}"></span>`).join('');

        let html = '<div class="report-day-panels">';

        html += '<div class="leaderboard-panel"><h3>💪 当日工作量 (' + work.length + ')</h3>';
        if (!work.length) html += '<div class="no-daily-data">当日无工作量记录</div>';
        else html += work.map(t => `
            <div class="report-day-item" onclick="Reports.showWorklog(${t.id})" title="点击查看工作日志">
                <span class="rdi-name">${escapeHtml(t.name)}</span>
                <span class="rdi-tags">${tagDots(t)}</span>
                ${t.duration ? '<span class="rdi-dur">⏱️ ' + escapeHtml(t.duration) + '</span>' : ''}
                <span class="rdi-log">📋 日志</span>
            </div>`).join('');
        html += '</div>';

        html += '<div class="leaderboard-panel"><h3>🏆 当日成果 (' + results.length + ')</h3>';
        if (!results.length) html += '<div class="no-daily-data">当日无成果</div>';
        else html += results.map(t => `
            <div class="report-day-item" style="cursor:default;">
                <span class="rdi-name">🏆 ${escapeHtml(t.result_name || t.name)}</span>
                <span class="rdi-tags">${tagDots(t)}</span>
                <span style="color:var(--color-text-secondary);font-size:0.75rem;">来自：${escapeHtml(t.name)}</span>
            </div>`).join('');
        html += '</div>';

        if (plans.length) {
            html += '<div class="leaderboard-panel"><h3>📅 当日计划 (' + plans.length + ')</h3>';
            html += plans.map(t => {
                const time = t.plan_time ? t.plan_time.substring(0, 5) + (t.plan_end_time ? '-' + t.plan_end_time.substring(0, 5) : '') : '全天';
                return `<div class="report-day-item" style="cursor:default;"><span class="rdi-name">📅 ${escapeHtml(t.name)}</span><span class="rdi-tags">${tagDots(t)}</span><span class="rdi-dur">⏰ ${escapeHtml(time)}</span></div>`;
            }).join('');
            html += '</div>';
        }
        html += '</div>';
        return html;
    },

    async showWorklog(taskId) {
        Modal.open({ title: '📋 工作日志', body: '<div class="report-loading">加载中…</div>', footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>' });
        try {
            const notes = (await API.worklogNotes.forTask(taskId)).data || [];
            if (!notes.length) { Modal.setBody('<div class="no-daily-data">暂无备注记录</div>'); return; }
            const groups = {};
            notes.forEach(n => { (groups[n.log_date] = groups[n.log_date] || []).push(n); });
            let html = '';
            Object.keys(groups).sort().reverse().forEach(date => {
                html += `<div style="font-weight:700;margin:8px 0 4px;font-size:0.85rem;">📅 ${date}</div>`;
                groups[date].forEach(n => {
                    const time = (n.created_at || '').substring(11, 16);
                    html += `<div style="padding:4px 0;border-bottom:1px solid var(--color-border);font-size:0.82rem;">
                        <span style="color:var(--color-text-secondary);font-size:0.7rem;">${time}</span> ${escapeHtml(n.content)}
                        <button class="wl-note-attach" onclick="Attach.openModal('worklog_note',${n.id},'备注附件')" title="附件">📎</button></div>`;
                });
            });
            Modal.setBody(html);
        } catch (e) { Modal.setBody('<div class="no-daily-data">加载失败</div>'); }
    },

    _rangeLabel() {
        const d = this.date();
        if (this.gran === 'daily') return d;
        if (this.gran === 'yearly') return d.substring(0, 4) + '年';
        if (this.gran === 'monthly') return d.substring(0, 7);
        // weekly: Monday ~ Sunday of the selected date's week
        const dt = new Date(d + 'T12:00:00');
        const offset = (dt.getDay() + 6) % 7; // 0 = Monday
        const mon = new Date(dt); mon.setDate(dt.getDate() - offset);
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        const fmt = x => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
        return fmt(mon) + ' ~ ' + fmt(sun);
    },
};

document.addEventListener('DOMContentLoaded', () => Reports.init());
