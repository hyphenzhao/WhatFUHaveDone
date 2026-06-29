/**
 * Immersive Mode — mobile-first dark theme dashboard
 * Requires: home.js (for analyzeBazi, md, renderBaziPillars, WX maps)
 */
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
        console.log('immersive render start, imApp:', app);
        app.innerHTML = `
            <div class="im-container">
                <div class="im-header">
                    <button class="im-back" onclick="window.location='/'">🏠 返回</button>
                    <button class="im-back" onclick="IM.openAiChat()">🤖 AI 助手</button>
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
        console.log('immersive render done, imWeather:', document.getElementById('imWeather'), 'imBaziBtns:', document.getElementById('imBaziBtns'));
    },

    async loadWeather() {
        const el = document.getElementById('imWeather');
        try {
            const res = await API.weather.get(this.date);
            const w = res.data;
            // Compute pillars for display
            let pillarHtml = '';
            try {
                const d = new Date(this.date + 'T12:00:00');
                const ec = Lunar.fromDate(d).getEightChar();
                const WX_G = {甲:'木',乙:'木',丙:'火',丁:'火',戊:'土',己:'土',庚:'金',辛:'金',壬:'水',癸:'水'};
                const WX_Z = {子:'水',丑:'土',寅:'木',卯:'木',辰:'土',巳:'火',午:'火',未:'土',申:'金',酉:'金',戌:'土',亥:'水'};
                const WC = {木:'#38a169',火:'#e53e3e',土:'#d97706',金:'#ddb100',水:'#3182ce'};
                const gzSpan = (g,z) => `<span style="color:${WC[WX_G[g]]||'#ccc'}">${g}</span><span style="color:${WC[WX_Z[z]]||'#ccc'}">${z}</span>`;
                pillarHtml = `<div class="im-pillars">
                    <div class="im-pillar-row"><span class="im-pillar-label">年柱</span>${gzSpan(ec.getYearGan(),ec.getYearZhi())}</div>
                    <div class="im-pillar-row"><span class="im-pillar-label">月柱</span>${gzSpan(ec.getMonthGan(),ec.getMonthZhi())}</div>
                    <div class="im-pillar-row"><span class="im-pillar-label">日柱</span>${gzSpan(ec.getDayGan(),ec.getDayZhi())}</div>
                </div>`;
            } catch(e) {}
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
                    </div>
                    ${pillarHtml}`;
                this.clockTz = w.timezone || 'Asia/Shanghai';
                this.updateClock();
            } else { el.innerHTML = pillarHtml || '<div class="im-empty">无天气数据</div>'; }
        } catch(e) { el.innerHTML = '<div class="im-empty">天气加载失败</div>'; }
    },

    toggleClock() { this.clockFmt24 = !this.clockFmt24; this.updateClock(); },
    toggleSort() { const cur = localStorage.getItem('taskSort') || 'priority'; localStorage.setItem('taskSort', cur === 'priority' ? 'deadline' : 'priority'); this.loadTasks(); },
    startClock() { if (this.clockInterval) clearInterval(this.clockInterval); this.updateClock(); this.clockInterval = setInterval(() => this.updateClock(), 1000); },
    updateClock() {
        const clk = document.getElementById('imClock'), date = document.getElementById('imDate');
        if (!clk) return;
        const now = new Date();
        clk.textContent = this.clockFmt24
            ? now.toLocaleTimeString('en-US', { timeZone: this.clockTz, hour12: false, hour:'2-digit', minute:'2-digit', second:'2-digit' })
            : now.toLocaleTimeString('en-US', { timeZone: this.clockTz, hour12: true, hour:'2-digit', minute:'2-digit', second:'2-digit' });
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

    async getPillarInfo() {
        // Compute BaZi pillars for current date, matching renderBaziPillars logic
        const p = await loadBaziProfile();
        if (!p) return null;
        const selDate = new Date(this.date + 'T12:00:00');
        const selEc = Lunar.fromDate(selDate).getEightChar();
        const dayGan = p.bazi_day ? p.bazi_day[0] : '';
        const info = {
            liuri: { gz: selEc.getDayGan()+selEc.getDayZhi(), ss: '' },
            liunian: { gz: selEc.getYearGan()+selEc.getYearZhi(), ss: '' },
            liuyue: { gz: selEc.getMonthGan()+selEc.getMonthZhi(), ss: '' },
            dayun: { gz: '', ss: '' },
        };
        if (p.birth_date && p.gender) {
            const bd = new Date(p.birth_date + 'T12:00:00');
            const bt = p.birth_time !== '' ? (parseInt(p.birth_time)*2+1)%24 : 12;
            bd.setHours(bt,0,0,0);
            const yun = Lunar.fromDate(bd).getEightChar().getYun(p.gender==='女'?0:1);
            const xuAge = selDate.getFullYear() - bd.getFullYear() + 1;
            const daYuns = yun.getDaYun();
            for (let i=0;i<daYuns.length;i++) { if (xuAge>=daYuns[i].getStartAge()&&xuAge<daYuns[i].getEndAge()){ info.dayun.gz = daYuns[i].getGanZhi(); break; } }
        }
        if (dayGan && typeof getShiShenLabel !== 'undefined') {
            for (const k of Object.keys(info)) {
                if (info[k].gz) info[k].ss = getShiShenLabel(dayGan, info[k].gz[0]) + ' / ' + getShiShenLabel(dayGan, info[k].gz[1]);
            }
        }
        return info;
    },

    async getExistingAnalysis() {
        // Same lookup as full mode: check current date + nearby dates
        let analyses = {};
        try { const res = await API.get('/bazi_analysis?date=' + this.date); (res.data||[]).forEach(a => analyses[a.type]=a); } catch(e) {}
        // Also search nearby dates for 大运/流年/流月
        if (!analyses['dayun'] || !analyses['liunian'] || !analyses['liuyue']) {
            const base = new Date(this.date + 'T12:00:00');
            for (let back = 1; back <= 31; back++) {
                const prev = new Date(base); prev.setDate(base.getDate() - back);
                const prevStr = prev.toISOString().split('T')[0];
                try { const r = await API.get('/bazi_analysis?date=' + prevStr); (r.data||[]).forEach(a => { if (a.type !== 'liuri' && !analyses[a.type]) analyses[a.type] = a; }); } catch(e) {}
                if (analyses['dayun'] && analyses['liunian'] && analyses['liuyue']) break;
            }
        }
        return analyses;
    },

    async openBazi(type, label) {
        const btn = document.getElementById('imBazi-' + type);
        // Check if analysis already exists (same DB as full mode)
        let analyses = await this.getExistingAnalysis();
        if (!analyses[type]) {
            // Auto-generate
            if (btn) { btn.classList.add('loading'); btn.textContent = '⏳'; }
            const info = await this.getPillarInfo();
            const pillar = info ? info[type] : null;
            await analyzeBazi(this.date, type, label, pillar?.gz || '', pillar?.ss || '');
            this.loadBaziButtons();
            analyses = await this.getExistingAnalysis();
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
            const wlNotes = {};
            try {
                const notesRes = await API.worklogNotes.listAll();
                (notesRes.data||[]).forEach(n => { if (n.latest_note) wlNotes[n.task_id] = n.latest_note; });
            } catch(e) {}

            const renderCard = (t, showWorklog) => {
                const note = wlNotes[t.id] || '';
                const tags = (t.tags||[]).map(tg => `<span class="im-tag-dot" style="background:${escapeHtml(tg.color)}" data-tag="${escapeHtml(tg.name)}"></span>`).join('');
                const wlActive = showWorklog && wlTaskIds.has(t.id);
                const wlBtn = showWorklog
                    ? `<button class="im-btn-worklog${wlActive?' active':''}" onclick="IM.toggleWL(${t.id},'${this.date}')">${wlActive?'😊':'+1'}</button>`
                    : '';
                const noteHtml = note ? `<div class="im-card-note">📝 ${escapeHtml(note)}</div>` : '';
                const deadlineHtml = typeof getDeadlineBadge !== 'undefined' ? getDeadlineBadge(t.deadline) : '';
                return `<div class="im-card">
                    <div class="im-card-name">${escapeHtml(t.name)}${deadlineHtml}</div>
                    <div class="im-card-tags">${tags}</div>
                    ${noteHtml}
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
            html += '</div><div class="im-col"><div class="im-col-title">🔄 进行中 <button class="im-add-task-btn" onclick="IM.showAddTask()">＋</button> <button class="btn btn-ghost btn-sm" onclick="IM.toggleSort()" style="font-size:0.65rem;">${(localStorage.getItem('taskSort')||'priority')==='deadline'?'📅截止':'🔢优先级'}</button></div>';
            inProgress.sort((a, b) => {
                const sort = localStorage.getItem('taskSort') || 'priority';
                if (sort === 'deadline') {
                    const ds = dl => {
                        if (!dl) return 999; if (dl === '自由') return 900;
                        if (/^\d{4}-\d{2}-\d{2}$/.test(dl)) {
                            const h = (new Date(dl + 'T23:59:59') - new Date()) / 3600000;
                            if (h < 0) return 0; if (h < 24) return 10; if (h < 72) return 300; if (h < 168) return 700; return 800 + Math.abs(h);
                        }
                        if (dl === '尽快') return 200; return 500;
                    };
                    return ds(a.deadline) - ds(b.deadline);
                }
                return (a.priority||999) - (b.priority||999);
            });
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
    openAiChat() {
        Modal.open({
            title: '🤖 AI 助手',
            body: '<div id="imAiChatContainer" style="height:60vh;display:flex;flex-direction:column;"></div>',
            footer: '',
        });
        // Initialize AiChat in the modal container
        setTimeout(() => {
            const container = document.getElementById('imAiChatContainer');
            if (container && typeof AiChat !== 'undefined') {
                AiChat.init(container);
            }
        }, 100);
    },

    showAddTask() {
        // Reuse full-mode showAddTaskModal (same form, same DB)
        if (typeof showAddTaskModal === 'function') {
            showAddTaskModal();
        } else {
            Toast.error('加载失败，请刷新页面');
        }
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
