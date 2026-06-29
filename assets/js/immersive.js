/**
 * Immersive Mode — mobile-first dark theme dashboard
 */

// Shared helpers (copied from home.js to avoid loading full dashboard)
function md(text) {
    if (!text) return ''; let html = escapeHtml(text);
    html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    html = html.replace(/^### (.+)$/gm, '<h4>$1</h4>');
    html = html.replace(/^## (.+)$/gm, '<h3>$1</h3>');
    html = html.replace(/^# (.+)$/gm, '<h2>$1</h2>');
    html = html.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');
    html = html.replace(/^---$/gm, '<hr>');
    html = html.replace(/\n\n/g, '</p><p>'); html = html.replace(/\n/g, '<br>');
    html = '<p>' + html + '</p>'; html = html.replace(/<p><\/p>/g, '');
    return html;
}

async function analyzeBazi(dateStr, type, label, gz, ss) {
    try {
        const pRes = await API.get('/profile'); const p = pRes.data || {};
        const prompt = type === 'liuri'
            ? `用户八字: 年${p.bazi_year||''} 月${p.bazi_month||''} 日${p.bazi_day||''} 时${p.bazi_time||''}。当前${label}: ${gz||''}，十神: ${ss||''}。请结合用户八字和当前${label}，用不超过100字简短分析今日能量特点和建议重点工作方向。`
            : `用户八字: 年${p.bazi_year||''} 月${p.bazi_month||''} 日${p.bazi_day||''} 时${p.bazi_time||''}。简历: ${p.resume||'无'}。目标: ${p.goals||'无'}。当前${label}: ${gz||''}，十神: ${ss||''}。请结合用户八字和${label}，用100-200字分析此阶段的能量特点，并给出重点工作方向的建议。`;
        const res = await API.post('/ai/chat', { messages: [{ role: 'user', content: prompt }] });
        if (res.data && res.data.content) {
            await API.post('/bazi_analysis', { date_key: dateStr, type, period_label: label, gan_zhi: gz||'', shi_shen: ss||'', analysis: res.data.content });
        }
    } catch(e) { console.error('analyzeBazi error:', e); }
}

const IM = {
    date: today(),
    clockInterval: null, clockFmt24: false,

    async init() {
        this.date = typeof App !== 'undefined' ? App.selectedDate : today();
        this.render();
        await Promise.all([this.loadWeather(), this.loadBaziButtons(), this.loadTasks()]);
        this.startClock();
    },

    render() {
        const app = document.getElementById('imApp');
        app.innerHTML = `
            <div class="im-container">
                <div class="im-header">
                    <button class="im-back" onclick="window.location='/'">🏠 返回全功能模式</button>
                    <span class="im-title">🕶️ 沉浸模式</span>
                    <span style="font-size:0.8rem;color:var(--im-text-secondary);" id="imDateShort"></span>
                </div>
                <div class="im-main">
                    <div class="im-left-panel">
                        <div class="im-top-row">
                            <div class="im-weather-block" id="imWeather"></div>
                            <div class="im-bazi-block" id="imBaziBtns"></div>
                        </div>
                    </div>
                    <div class="im-right-panel">
                        <div class="im-content" id="imContent"></div>
                    </div>
                </div>
            </div>`;
    },

    async loadWeather() {
        const el = document.getElementById('imWeather');
        try {
            const res = await API.weather.get(this.date);
            const w = res.data;
            if (w) {
                el.innerHTML = `
                    <div class="im-w-icon">${w.emoji}</div>
                    <div class="im-w-info">
                        <div class="im-w-city" onclick="window.location='/'" style="cursor:pointer">📍 ${escapeHtml(w.city||'')}</div>
                        <div class="im-w-temp">${Math.round(w.temp_max)}°<span style="font-size:0.8rem;font-weight:400;"> / ${Math.round(w.temp_min)}°</span></div>
                        <div class="im-w-desc">${w.desc}</div>
                        <div class="im-w-detail">💧${w.humidity}% 🌬️${Math.round(w.wind)}km/h</div>
                    </div>
                    <div>
                        <div class="im-clock" id="imClock">--:--</div>
                        <div class="im-date" id="imDate">--</div>
                        <button onclick="IM.toggleClock()" style="background:none;border:1px solid var(--im-border);color:var(--im-text-secondary);padding:2px 6px;border-radius:4px;font-size:0.65rem;">12h/24h</button>
                    </div>`;
                this.clockTz = w.timezone || 'Asia/Shanghai';
                this.updateClock();
            } else { el.innerHTML = '<div class="im-empty">无天气数据</div>'; }
        } catch(e) { el.innerHTML = '<div class="im-empty">天气加载失败</div>'; }
    },

    toggleClock() { this.clockFmt24 = !this.clockFmt24; this.updateClock(); },
    startClock() { if (this.clockInterval) clearInterval(this.clockInterval); this.updateClock(); this.clockInterval = setInterval(() => this.updateClock(), 1000); },
    updateClock() {
        const clk = document.getElementById('imClock'), date = document.getElementById('imDate');
        if (!clk) return;
        const now = new Date();
        clk.textContent = this.clockFmt24
            ? now.toLocaleTimeString('en-US', { timeZone: this.clockTz, hour12: false, hour:'2-digit', minute:'2-digit', second:'2-digit' })
            : now.toLocaleTimeString('en-US', { timeZone: this.clockTz, hour12: true, hour:'2-digit', minute:'2-digit' }).replace(' AM','').replace(' PM','');
        if (date) date.textContent = this.date;
    },

    async loadBaziButtons() {
        const el = document.getElementById('imBaziBtns');
        const types = [
            { key: 'dayun', label: '大运', icon: '🔟' },
            { key: 'liunian', label: '流年', icon: '📅' },
            { key: 'liuyue', label: '流月', icon: '🌙' },
            { key: 'liuri', label: '流日', icon: '📆' },
        ];
        // Fetch existing analyses
        let analyses = {};
        try { const res = await API.get('/bazi_analysis?date=' + this.date); (res.data||[]).forEach(a => analyses[a.type]=a); } catch(e) {}

        el.innerHTML = '<div class="im-bazi-row">' + types.map(t => {
            const has = !!analyses[t.key];
            return `<button class="im-bazi-btn${has?' has-analysis':''}" id="imBazi-${t.key}" onclick="IM.openBazi('${t.key}','${t.label}')">
                ${t.icon} ${t.label}
            </button>`;
        }).join('') + '</div>';
    },

    async openBazi(type, label) {
        const btn = document.getElementById('imBazi-' + type);
        // Check if analysis exists
        let analyses = {};
        try { const res = await API.get('/bazi_analysis?date=' + this.date); (res.data||[]).forEach(a => analyses[a.type]=a); } catch(e) {}

        if (!analyses[type]) {
            // Auto-generate
            if (btn) { btn.classList.add('loading'); btn.textContent = '⏳'; }
            await analyzeBazi(this.date, type, label, '', '');
            // Reload button state
            this.loadBaziButtons();
            // Re-fetch analysis
            try { const res2 = await API.get('/bazi_analysis?date=' + this.date); (res2.data||[]).forEach(a => analyses[a.type]=a); } catch(e) {}
        }

        const a = analyses[type];
        if (a && a.analysis) {
            Modal.open({
                title: `${label} — 详细解析`,
                body: `<div class="im-bazi-modal-body">${md(a.analysis)}</div>`,
                footer: `<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>
                    <button class="btn btn-primary" onclick="IM.regenerateBazi('${type}','${label}')">🔄 重新分析</button>`,
            });
        } else {
            Toast.error('暂无分析数据，请稍后重试');
        }
    },

    async regenerateBazi(type, label) {
        Modal.close();
        await analyzeBazi(this.date, type, label, '', '');
        this.loadBaziButtons();
    },

    async loadTasks() {
        const el = document.getElementById('imContent');
        try {
            const [dailyRes, tasksRes, wlRes] = await Promise.all([
                API.stats.daily(this.date),
                API.tasks.list(0, 'in_progress'),
                API.worklogs.forDate(this.date),
            ]);
            const daily = dailyRes.data || {};
            const inProgress = tasksRes.data || [];
            const wlTaskIds = new Set((wlRes.data||[]).map(w => w.task_id));

            const renderCard = (t, showWorklog) => {
                const tags = (t.tags||[]).map(tg => `<span class="im-tag-dot" style="background:${escapeHtml(tg.color)}" data-tag="${escapeHtml(tg.name)}"></span>`).join('');
                const wlActive = showWorklog && wlTaskIds.has(t.id);
                const wlBtn = showWorklog
                    ? `<button class="im-btn-worklog${wlActive?' active':''}" onclick="IM.toggleWL(${t.id},'${this.date}')">${wlActive?'😊':'+1'}</button>`
                    : '';
                return `<div class="im-card">
                    <div class="im-card-name">${escapeHtml(t.name)}</div>
                    <div class="im-card-tags">${tags}</div>
                    <div class="im-card-actions">
                        ${wlBtn}
                        <button onclick="IM.addPlan(${t.id})">📅</button>
                        <button onclick="IM.addResult(${t.id})">🏆</button>
                        <select class="im-stage-select" onchange="IM.changeStage(${t.id},this.value)"><option value="">⚙</option><option value="stage_complete">完</option><option value="completed">✓</option><option value="failed">✗</option></select>
                    </div>
                </div>`;
            };

            let html = '<div class="im-col"><div class="im-col-title">📋 今日工作量</div>';
            const workTasks = daily.work_tasks || [];
            const resultTasks = daily.result_tasks || [];
            const planTasks = daily.plan_tasks || [];
            if (workTasks.length === 0 && resultTasks.length === 0 && planTasks.length === 0) {
                html += '<div class="im-empty">暂无记录</div>';
            } else {
                workTasks.forEach(t => { html += renderCard(t, false); });
                resultTasks.forEach(t => {
                    html += `<div class="im-card"><div class="im-card-name">🏆 ${escapeHtml(t.name)}</div><div class="im-card-tags">${(t.tags||[]).map(tg=>`<span class="im-tag-dot" style="background:${escapeHtml(tg.color)}" data-tag="${escapeHtml(tg.name)}"></span>`).join('')}</div></div>`;
                });
                planTasks.forEach(t => {
                    html += `<div class="im-card"><div class="im-card-name">📅 ${escapeHtml(t.name)}</div><div class="im-card-tags">${(t.tags||[]).map(tg=>`<span class="im-tag-dot" style="background:${escapeHtml(tg.color)}" data-tag="${escapeHtml(tg.name)}"></span>`).join('')}</div></div>`;
                });
            }
            html += '</div><div class="im-col"><div class="im-col-title">🔄 进行中</div>';
            if (inProgress.length === 0) {
                html += '<div class="im-empty">暂无进行中任务</div>';
            } else {
                inProgress.forEach(t => { html += renderCard(t, true); });
            }
            html += '</div>';
            el.innerHTML = html;
        } catch(e) { el.innerHTML = '<div class="im-empty">加载失败</div>'; }
    },

    async toggleWL(taskId, date) {
        try { await API.worklogs.toggle(taskId, date); await this.loadTasks(); if (typeof refreshAll==='function') refreshAll(); } catch(e) { Toast.error('操作失败'); }
    },
    async changeStage(taskId, stage) {
        if (!stage) return;
        try { await API.tasks.update(taskId, { stage, stage_number: 1 }); await this.loadTasks(); if (typeof refreshAll==='function') refreshAll(); Toast.success('已更新'); } catch(e) { Toast.error('操作失败'); }
    },
    addPlan(taskId) {
        Modal.open({
            title: '添加计划', body: `<input type="date" class="form-input" id="imPlanDate" value="${this.date}">`,
            footer: '<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="imPlanOk">确认</button>',
        });
        document.getElementById('imPlanOk').addEventListener('click', async () => {
            try { await API.plans.add(taskId, document.getElementById('imPlanDate').value); Modal.close(); await this.loadTasks(); } catch(e) { Toast.error('失败'); }
        });
    },
    async addResult(taskId) {
        let resultsList = [];
        try { resultsList = (await API.results.list()).data || []; } catch(e) {}
        const opts = resultsList.map(r => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join('');
        Modal.open({
            title: '添加成果', body: `<select class="form-select" id="imResultSel"><option value="">--选择--</option>${opts}</select>`,
            footer: '<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="imResultOk">确认</button>',
        });
        document.getElementById('imResultOk').addEventListener('click', async () => {
            const rid = document.getElementById('imResultSel').value;
            if (!rid) { Toast.error('请选择成果'); return; }
            try { await API.resultLogs.add(taskId, parseInt(rid), this.date); Modal.close(); await this.loadTasks(); } catch(e) { Toast.error('失败'); }
        });
    },
};

document.addEventListener('DOMContentLoaded', () => IM.init());
