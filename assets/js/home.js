/**
 * Home page — orchestrates right panel, leaderboards, daily status, calendar
 */

// Five Elements (五行) data
const WU_XING = {
    // 天干五行
    gan: { 甲:'木', 乙:'木', 丙:'火', 丁:'火', 戊:'土', 己:'土', 庚:'金', 辛:'金', 壬:'水', 癸:'水' },
    // 地支五行
    zhi: { 子:'水', 丑:'土', 寅:'木', 卯:'木', 辰:'土', 巳:'火', 午:'火', 未:'土', 申:'金', 酉:'金', 戌:'土', 亥:'水' },
    // 五行颜色
    color: { 木:'#38a169', 火:'#e53e3e', 土:'#d97706', 金:'#ddb100', 水:'#3182ce' },
};

function renderAlmanac(dateStr) {
    const container = document.getElementById('dailyAlmanac');
    if (!container) return;

    try {
        const d = new Date(dateStr + 'T12:00:00');
        const lunar = Lunar.fromDate(d);
        const meta = Calendar.calendarMeta[dateStr] || {};

        const yGan = lunar.getYearGan(), yZhi = lunar.getYearZhi();
        const mGan = lunar.getMonthGan(), mZhi = lunar.getMonthZhi();
        const dGan = lunar.getDayGan(), dZhi = lunar.getDayZhi();
        const shengXiao = lunar.getYearShengXiao ? lunar.getYearShengXiao() : '';

        const gz = (g, z) => {
            const ge = WU_XING.gan[g] || '', ze = WU_XING.zhi[z] || '';
            return `<span style="color:${WU_XING.color[ge]||'#333'}">${g}</span><span style="color:${WU_XING.color[ze]||'#333'}">${z}</span>`;
        };

        const [y, m, day] = dateStr.split('-');
        const weekNames = ['日','一','二','三','四','五','六'];

        container.innerHTML = `
            <div class="almanac-card">
                <div class="almanac-solar">
                    <span class="almanac-year">${y}</span><span class="almanac-sep">年</span>
                    <span class="almanac-month">${parseInt(m)}</span><span class="almanac-sep">月</span>
                    <span class="almanac-day">${parseInt(day)}</span><span class="almanac-sep">日</span>
                    <span class="almanac-weekday">星期${weekNames[d.getDay()]}</span>
                </div>
                <div class="almanac-lunar">
                    <span>农历</span>
                    <span class="almanac-lunar-text">${escapeHtml(meta.lunar_month || lunar.getMonthInChinese())}月${escapeHtml(meta.lunar_day || lunar.getDayInChinese())}</span>
                    ${(() => { let t = meta.solar_term || lunar.getJieQi(); if (!t) { const p = lunar.getPrevJieQi(true); t = p ? p.getName() : ''; } return t ? '<span class="almanac-term">' + escapeHtml(t) + '</span>' : ''; })()}
                </div>
                <div class="almanac-ganzhi">
                    <div class="almanac-gz-row"><span class="almanac-gz-label">年柱</span>${gz(yGan, yZhi)}<span class="almanac-gz-sx">${escapeHtml(shengXiao)}</span></div>
                    <div class="almanac-gz-row"><span class="almanac-gz-label">月柱</span>${gz(mGan, mZhi)}</div>
                    <div class="almanac-gz-row"><span class="almanac-gz-label">日柱</span>${gz(dGan, dZhi)}</div>
                </div>
            </div>`;
    } catch (e) {
        container.innerHTML = '<div class="almanac-card"><div class="no-daily-data">黄历数据加载失败</div></div>';
    }
}

// ===== BAZI PILLARS =====
let baziProfile = null;

async function loadBaziProfile() {
    if (baziProfile) return baziProfile;
    try {
        const res = await API.get('/profile');
        const p = res.data || {};
        if (p.bazi_year && p.bazi_day) {
            baziProfile = p;
        }
    } catch(e) {}
    return baziProfile;
}

function getShiShenLabel(dayGan, targetGan) {
    const map = {
        '甲甲':'比肩','甲乙':'劫财','甲丙':'食神','甲丁':'伤官','甲戊':'偏财','甲己':'正财','甲庚':'七杀','甲辛':'正官','甲壬':'偏印','甲癸':'正印',
        '乙甲':'劫财','乙乙':'比肩','乙丙':'伤官','乙丁':'食神','乙戊':'正财','乙己':'偏财','乙庚':'正官','乙辛':'七杀','乙壬':'正印','乙癸':'偏印',
        '丙甲':'偏印','丙乙':'正印','丙丙':'比肩','丙丁':'劫财','丙戊':'食神','丙己':'伤官','丙庚':'偏财','丙辛':'正财','丙壬':'七杀','丙癸':'正官',
        '丁甲':'正印','丁乙':'偏印','丁丙':'劫财','丁丁':'比肩','丁戊':'伤官','丁己':'食神','丁庚':'正财','丁辛':'偏财','丁壬':'正官','丁癸':'七杀',
        '戊甲':'七杀','戊乙':'正官','戊丙':'偏印','戊丁':'正印','戊戊':'比肩','戊己':'劫财','戊庚':'食神','戊辛':'伤官','戊壬':'偏财','戊癸':'正财',
        '己甲':'正官','己乙':'七杀','己丙':'正印','己丁':'偏印','己戊':'劫财','己己':'比肩','己庚':'伤官','己辛':'食神','己壬':'正财','己癸':'偏财',
        '庚甲':'偏财','庚乙':'正财','庚丙':'七杀','庚丁':'正官','庚戊':'偏印','庚己':'正印','庚庚':'比肩','庚辛':'劫财','庚壬':'食神','庚癸':'伤官',
        '辛甲':'正财','辛乙':'偏财','辛丙':'正官','辛丁':'七杀','辛戊':'正印','辛己':'偏印','辛庚':'劫财','辛辛':'比肩','辛壬':'伤官','辛癸':'食神',
        '壬甲':'食神','壬乙':'伤官','壬丙':'偏财','壬丁':'正财','壬戊':'七杀','壬己':'正官','壬庚':'偏印','壬辛':'正印','壬壬':'比肩','壬癸':'劫财',
        '癸甲':'伤官','癸乙':'食神','癸丙':'正财','癸丁':'偏财','癸戊':'正官','癸己':'七杀','癸庚':'正印','癸辛':'偏印','癸壬':'劫财','癸癸':'比肩',
    };
    return map[dayGan + targetGan] || '';
}

const WX2 = {'木':'#38a169','火':'#e53e3e','土':'#d97706','金':'#ddb100','水':'#3182ce'};
const WX_GAN = {甲:'木',乙:'木',丙:'火',丁:'火',戊:'土',己:'土',庚:'金',辛:'金',壬:'水',癸:'水'};
const WX_ZHI = {子:'水',丑:'土',寅:'木',卯:'木',辰:'土',巳:'火',午:'火',未:'土',申:'金',酉:'金',戌:'土',亥:'水'};

function gzSpan(g, z) {
    return `<span style="color:${WX2[WX_GAN[g]]}">${g}</span><span style="color:${WX2[WX_ZHI[z]]}">${z}</span>`;
}

async function renderBaziPillars(dateStr) {
    const grid = document.getElementById('baziPillarsGrid');
    const dateLabel = document.getElementById('baziPillarsDate');
    if (!grid) return;
    dateLabel.textContent = dateStr;

    const p = await loadBaziProfile();
    if (!p) {
        grid.innerHTML = '<div class="no-daily-data">请先在<a href="/profile">个人侧写</a>中设置生辰八字</div>';
        return;
    }

    // Parse user's BaZi
    const dayGan = p.bazi_day ? p.bazi_day[0] : '';

    // Selected date
    const selDate = new Date(dateStr + 'T12:00:00');
    const selLunar = Lunar.fromDate(selDate);
    const selEc = selLunar.getEightChar();

    // 流日 (from selected date's day pillar)
    const liuRiGz = selEc.getDayGan() + selEc.getDayZhi();
    const lrG = liuRiGz[0], lrZ = liuRiGz[1];
    const lrSS = getShiShenLabel(dayGan, lrG);

    let daYunGz = '', lnGz = '', lyGz = '';
    let dySS = '', lnSS = '', lySS = '';
    let daYunPeriod = '', lnPeriod = '', lyPeriod = '';
    let hasYun = false;

    // Compute 大运/流年/流月 (requires birth_date)
    if (p.birth_date) {
        const bd = new Date(p.birth_date + 'T12:00:00');
        const bt = p.birth_time !== '' ? (parseInt(p.birth_time) * 2 + 1) % 24 : 12;
        bd.setHours(bt, 0, 0, 0);
        const birthLunar = Lunar.fromDate(bd);
        const birthEc = birthLunar.getEightChar();
        const gender = p.gender === '女' ? 0 : 1;
        const yun = birthEc.getYun(gender);
        const ageYears = (selDate - bd) / (365.25 * 24 * 3600 * 1000);
        const startSolar = yun.getStartSolar();
        const startDate = new Date(startSolar.getYear(), startSolar.getMonth() - 1, startSolar.getDay());
        const startAge = (startDate - bd) / (365.25 * 24 * 3600 * 1000);

        const daYuns = yun.getDaYun();
        let daYunIdx = -1;
        for (let i = 0; i < daYuns.length; i++) {
            const dyStart = daYuns[i].getStartAge();
            if (ageYears >= dyStart && ageYears < dyStart + 10) { daYunIdx = i; break; }
        }
        if (daYunIdx < 0) daYunIdx = Math.min(daYuns.length - 1, Math.max(0, Math.floor((ageYears - daYuns[0].getStartAge()) / 10)));
        const daYun = daYuns[daYunIdx];
        daYunGz = daYun.getGanZhi();
        daYunPeriod = daYunGz + ' (' + Math.floor(daYun.getStartAge()) + '-' + Math.floor(daYun.getEndAge()) + '岁)';
        dySS = getShiShenLabel(dayGan, daYunGz[0]);
        hasYun = true;

        const liuNians = daYun.getLiuNian();
        let liuNianIdx = -1;
        for (let i = 0; i < liuNians.length; i++) {
            if (liuNians[i].getYear() === selDate.getFullYear()) { liuNianIdx = i; break; }
        }
        if (liuNianIdx < 0) liuNianIdx = Math.max(0, Math.floor(ageYears - daYun.getStartAge()));
        const liuNian = liuNians[liuNianIdx];
        lnGz = liuNian.getGanZhi();
        lnPeriod = lnGz + ' (' + selDate.getFullYear() + '年)';
        lnSS = getShiShenLabel(dayGan, lnGz[0]);

        const liuYues = liuNian.getLiuYue();
        const selMonth = selDate.getMonth() + 1;
        const liuYue = liuYues[selMonth - 1];
        if (liuYue) {
            lyGz = liuYue.getGanZhi();
            lyPeriod = lyGz + ' (' + selDate.getFullYear() + '年' + selMonth + '月)';
            lySS = getShiShenLabel(dayGan, lyGz[0]);
        }
    }

    // Fetch existing analyses
    let analyses = {};
    try {
        const res = await API.get('/bazi_analysis?date=' + dateStr);
        (res.data || []).forEach(a => { analyses[a.type] = a; });
    } catch(e) {}

    // Build cards
    const cards = [];
    if (hasYun) {
        cards.push({ type:'dayun',   label:'大运', gz:daYunGz, ss:dySS, period: daYunPeriod, icon:'🔟' });
        cards.push({ type:'liunian', label:'流年', gz:lnGz, ss:lnSS, period: lnPeriod, icon:'📅' });
        if (lyGz) cards.push({ type:'liuyue', label:'流月', gz:lyGz, ss:lySS, period: lyPeriod, icon:'🌙' });
    }

    grid.innerHTML = cards.map(c => {
        const existing = analyses[c.type];
        const g = c.gz[0], z = c.gz[1];
        return `<div class="bazi-pillar-card">
            <div class="bazi-card-header">
                <span class="bazi-card-icon">${c.icon}</span>
                <strong>${c.label}</strong>
                <span class="bazi-card-period">${c.period}</span>
            </div>
            <div class="bazi-card-ganzhi">${gzSpan(g, z)} ${c.ss ? '<span class="bazi-card-shishen">'+c.ss+'</span>' : ''}</div>
            ${existing && existing.analysis
                ? `<div class="bazi-card-analysis">${escapeHtml(existing.analysis)}<br><button class="btn btn-ghost btn-sm" onclick="analyzeBazi('${dateStr}','${c.type}','${c.label}','${c.gz}','${c.ss}')" style="margin-top:4px;">🔄 重新分析</button></div>`
                : `<button class="btn btn-ghost btn-sm" onclick="analyzeBazi('${dateStr}','${c.type}','${c.label}','${c.gz}','${c.ss}')">🤖 AI 解析</button>`}
        </div>`;
    }).join('');

    // Render 流日 in daily status
    renderLiuriCard(dateStr, liuRiGz, lrSS, analyses['liuri']);
}

function renderLiuriCard(dateStr, gz, ss, existing) {
    const container = document.getElementById('dailyLiuri');
    if (!container) return;
    const g = gz[0], z = gz[1];
    container.innerHTML = `<div class="liuri-card">
        <div class="liuri-header">📆 流日 <span class="liuri-ganzhi">${gzSpan(g, z)}</span> ${ss ? '<span class="bazi-card-shishen">'+ss+'</span>' : ''}</div>
        ${existing && existing.analysis
            ? `<div class="liuri-analysis">${escapeHtml(existing.analysis)}<br><button class="btn btn-ghost btn-sm" onclick="analyzeBazi('${dateStr}','liuri','流日','${gz}','${ss}')" style="margin-top:4px;font-size:0.7rem;">🔄 重新分析</button></div>`
            : `<button class="btn btn-ghost btn-sm" onclick="analyzeBazi('${dateStr}','liuri','流日','${gz}','${ss}')">🤖 AI 解析</button>`}
    </div>`;
}

async function analyzeBazi(dateStr, type, label, gz, ss) {
    const p = await loadBaziProfile();
    if (!p) return;
    Toast.success('AI 解析中...');
    try {
        const prompt = type === 'liuri'
            ? `用户八字: 年${p.bazi_year} 月${p.bazi_month} 日${p.bazi_day} 时${p.bazi_time}。当前${label}: ${gz}，十神: ${ss}。请结合用户八字和当前${label}，用不超过100字简短分析今日能量特点和建议重点工作方向。`
            : `用户八字: 年${p.bazi_year} 月${p.bazi_month} 日${p.bazi_day} 时${p.bazi_time}。简历: ${p.resume||'无'}。目标: ${p.goals||'无'}。当前${label}: ${gz}，十神: ${ss}。请结合用户八字和${label}，用100-200字分析此阶段的能量特点，并给出重点工作方向的建议。`;

        const res = await API.post('/ai/chat', { messages: [{ role: 'user', content: prompt }] });
        if (res.data.type === 'text') {
            await API.post('/bazi_analysis', {
                date_key: dateStr, type: type, period_label: label, gan_zhi: gz, shi_shen: ss, analysis: res.data.content
            });
            renderBaziPillars(dateStr);
            Toast.success(label + '解析完成');
        }
    } catch(e) { Toast.error('解析失败: ' + e.message); }
}

async function loadRightPanel() {
    const panel = document.getElementById('rightPanelBody');
    if (!panel) return;

    try {
        const [inProgress, stageComplete, completed, failed] = await Promise.all([
            API.tasks.list(0, 'in_progress'),
            API.tasks.list(0, 'stage_complete'),
            API.tasks.list(0, 'completed'),
            API.tasks.list(0, 'failed'),
        ]);

        // Get work logs for selected date to determine button states
        let workLogsForDate = [];
        try {
            const wl = await API.worklogs.forDate(App.selectedDate);
            workLogsForDate = wl.data || [];
        } catch (e) { workLogsForDate = []; }
        const workLogTaskIds = new Set(workLogsForDate.map(w => w.task_id));

        const renderSection = (title, tasks, icon, stageClass) => {
            const cardsHtml = tasks.map(t =>
                TaskCard.render(t, { date: App.selectedDate, workLogActive: workLogTaskIds.has(t.id) })
            ).join('');

            return `
                <div class="task-section">
                    <div class="task-section-header" onclick="toggleSection(this)">
                        <span class="toggle-arrow">▼</span>
                        <span>${icon} ${title}</span>
                        <span class="section-count">${tasks.length}</span>
                    </div>
                    <div class="task-list">
                        ${stageClass === 'in_progress' ? `
                            <div class="add-task-bar" onclick="showAddTaskModal()">
                                <span>＋</span> 添加新任务
                            </div>
                        ` : ''}
                        ${cardsHtml || '<div style="padding:8px;color:var(--color-text-secondary);font-size:0.8rem;text-align:center;">暂无任务</div>'}
                    </div>
                </div>
            `;
        };

        panel.innerHTML =
            renderSection('进行中', inProgress.data || [], '🔄', 'in_progress') +
            renderSection('阶段性完成', stageComplete.data || [], '✅', 'stage_complete') +
            renderSection('已完成', completed.data || [], '🎉', 'completed') +
            renderSection('失败/放弃', failed.data || [], '❌', 'failed');

    } catch (e) {
        panel.innerHTML = '<div style="padding:16px;color:var(--color-danger);">加载失败: ' + escapeHtml(e.message) + '</div>';
    }
}

function toggleSection(header) {
    header.parentElement.classList.toggle('collapsed');
}

async function loadLeaderboards() {
    try {
        const [workload, results] = await Promise.all([
            API.stats.workload(),
            API.stats.results(),
        ]);

        renderLeaderboard('workloadLeaderboard', '💪 工作量排行', workload.data || [], true);
        renderLeaderboard('resultsLeaderboard', '🏆 成果排行', results.data || [], false);
    } catch (e) {
        console.error('Leaderboard load failed:', e);
    }
}

function renderLeaderboard(containerId, title, items, isWorkload) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const maxVal = items.length > 0 ? Math.max(...items.map(i => i.total_workload || i.total_results || 0)) : 1;
    const type = isWorkload ? 'workload' : 'results';

    const html = items.map((item, idx) => {
        const count = item.total_workload || item.total_results || 0;
        const pct = maxVal > 0 ? (count / maxVal * 100) : 0;
        const color = item.color || '#3B82F6';
        return `
            <div class="leaderboard-item" data-detail-type="${type}" data-tag-id="${item.id}" data-tag-name="${escapeHtml(item.name)}" data-tag-color="${color}" title="点击查看详情">
                <span class="leaderboard-rank">#${idx + 1}</span>
                <span class="leaderboard-tag">
                    <span class="leaderboard-tag-color" style="background:${color}"></span>
                    ${escapeHtml(item.name)}
                </span>
                <div class="leaderboard-bar">
                    <div class="leaderboard-bar-fill" style="width:${pct}%;background:${color};"></div>
                </div>
                <span class="leaderboard-count">${count}</span>
            </div>
        `;
    }).join('') || '<div class="no-daily-data">暂无数据</div>';

    container.innerHTML = `<h3>${title}</h3>${html}`;

    // Delegated click handler
    container.querySelectorAll('.leaderboard-item').forEach(el => {
        el.addEventListener('click', () => {
            const tagId = parseInt(el.dataset.tagId);
            const tagName = el.dataset.tagName;
            const tagColor = el.dataset.tagColor;
            if (el.dataset.detailType === 'workload') {
                showWorkloadDetail(tagId, tagName, tagColor);
            } else {
                showResultsDetail(tagId, tagName);
            }
        });
    });
}

// --- Leaderboard Detail Modals ---

function lightenColor(hex, factor) {
    // Convert hex to HSL, increase lightness, return hex
    let r = parseInt(hex.slice(1, 3), 16);
    let g = parseInt(hex.slice(3, 5), 16);
    let b = parseInt(hex.slice(5, 7), 16);
    r = Math.min(255, Math.round(r + (255 - r) * factor));
    g = Math.min(255, Math.round(g + (255 - g) * factor));
    b = Math.min(255, Math.round(b + (255 - b) * factor));
    return '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('');
}

function renderPieChart(tasks, baseColor) {
    const total = tasks.reduce((s, t) => s + parseInt(t.work_days), 0);
    if (total === 0) return '<div style="text-align:center;color:var(--color-text-secondary);">无数据</div>';

    const r = 80;
    const circ = 2 * Math.PI * r; // ~502.65
    let offset = 0;
    const slices = tasks.map((t, i) => {
        const pct = parseInt(t.work_days) / total;
        const dashLen = pct * circ;
        const color = tasks.length === 1 ? baseColor : lightenColor(baseColor, i * 0.7 / (tasks.length - 1 || 1));
        const slice = `<circle r="${r}" cx="100" cy="100" fill="none"
            stroke="${color}" stroke-width="30"
            stroke-dasharray="${dashLen} ${circ - dashLen}"
            stroke-dashoffset="${-offset}"
            transform="rotate(-90 100 100)" />`;
        offset += dashLen;
        return { html: slice, color, pct, name: t.name, days: t.work_days };
    });

    const svg = `<svg viewBox="0 0 200 200" width="200" height="200">
        ${slices.map(s => s.html).join('')}
        <text x="100" y="95" text-anchor="middle" font-size="22" font-weight="700" fill="var(--color-text)">${total}</text>
        <text x="100" y="114" text-anchor="middle" font-size="11" fill="var(--color-text-secondary)">总天数</text>
    </svg>`;

    const legend = slices.map(s => `
        <div class="pie-legend-item">
            <span class="pie-legend-swatch" style="background:${s.color}"></span>
            <span class="pie-legend-label" title="${escapeHtml(s.name)}">${escapeHtml(s.name)}</span>
            <span class="pie-legend-pct">${Math.round(s.pct * 100)}%</span>
        </div>
    `).join('');

    return `
        ${svg}
        <div class="pie-legend">${legend}</div>
    `;
}

async function showWorkloadDetail(tagId, tagName, tagColor) {
    Modal.open({
        title: `💪 ${tagName} — 工作量详情`,
        body: '<div style="text-align:center;padding:20px;">加载中...</div>',
    });
    try {
        const res = await API.stats.workloadDetail(tagId);
        const tasks = res.data?.tasks || [];

        let taskListHtml;
        if (tasks.length === 0) {
            taskListHtml = '<div class="no-daily-data">暂无任务数据</div>';
        } else {
            taskListHtml = tasks.map(t => `
                <div class="detail-task-row">
                    <div class="detail-task-info">
                        <div class="detail-task-name">${escapeHtml(t.name)}</div>
                        <div class="detail-task-people">${escapeHtml(t.people_names || '无受益人')}</div>
                    </div>
                    <span class="detail-task-days">${t.work_days} 天</span>
                </div>
            `).join('');
        }

        Modal.setBody(`
            <div class="detail-layout">
                <div class="detail-task-list">
                    <h4>📋 任务列表</h4>
                    ${taskListHtml}
                </div>
                <div class="detail-pie">
                    <h4 style="margin-bottom:10px;">📊 占比</h4>
                    ${tasks.length > 0 ? renderPieChart(tasks, tagColor) : '<div class="no-daily-data">无数据</div>'}
                </div>
            </div>
        `);
    } catch (e) {
        Modal.setBody('<div class="no-daily-data" style="color:var(--color-danger);">加载失败: ' + escapeHtml(e.message) + '</div>');
    }
}

async function showResultsDetail(tagId, tagName) {
    Modal.open({
        title: `🏆 ${tagName} — 成果详情`,
        body: '<div style="text-align:center;padding:20px;">加载中...</div>',
    });
    try {
        const res = await API.stats.resultsDetail(tagId);
        const results = res.data?.results || [];

        let html;
        if (results.length === 0) {
            html = '<div class="no-daily-data">暂无成果数据</div>';
        } else {
            html = `<div class="detail-results-list"><h4>📋 成果列表（按等级升序）</h4>`;
            html += results.map(r => `
                <div class="detail-result-row">
                    <span class="detail-result-level">${escapeHtml(r.level || '-')}</span>
                    <span class="detail-result-name">${escapeHtml(r.name)}</span>
                    <span class="detail-result-meta">${escapeHtml(r.task_name)} · ${r.quantity || 1}个 · ${r.log_date}</span>
                </div>
            `).join('');
            html += '</div>';
        }

        Modal.setBody(html);
    } catch (e) {
        Modal.setBody('<div class="no-daily-data" style="color:var(--color-danger);">加载失败: ' + escapeHtml(e.message) + '</div>');
    }
}

async function loadDailyStatus(date) {
    const container = document.getElementById('dailyStatusCards');
    const dateDisplay = document.getElementById('dailyDateDisplay');
    if (!container) return;

    dateDisplay.textContent = formatDate(date);
    renderAlmanac(date);
    renderBaziPillars(date);

    try {
        const res = await API.stats.daily(date);
        const data = res.data || {};
        const workTasks = data.work_tasks || [];
        const resultTasks = data.result_tasks || [];
        const planTasks = data.plan_tasks || [];

        let html = '';

        if (workTasks.length === 0 && resultTasks.length === 0 && planTasks.length === 0) {
            html = '<div class="no-daily-data">📭 当日暂无记录</div>';
        }

        workTasks.forEach(t => {
            const tags = (t.tags || []).map(tg => `<span class="task-card-tag" style="background:${tg.color}">${tg.name}</span>`).join('');
            html += `<div class="daily-card"><h4>💪 ${escapeHtml(t.name)}</h4>${tags ? tags : ''}<div class="daily-card-meta">工作量 +1</div></div>`;
        });

        resultTasks.forEach(t => {
            const tags = (t.tags || []).map(tg => `<span class="task-card-tag" style="background:${tg.color}">${tg.name}</span>`).join('');
            html += `<div class="daily-card"><h4>🏆 ${escapeHtml(t.name)}</h4>${tags ? tags : ''}<div class="daily-card-meta">产出: ${escapeHtml(t.result_name || '')}</div></div>`;
        });

        planTasks.forEach(t => {
            const tags = (t.tags || []).map(tg => `<span class="task-card-tag" style="background:${tg.color}">${tg.name}</span>`).join('');
            html += `<div class="daily-card"><h4>📅 ${escapeHtml(t.name)}</h4>${tags ? tags : ''}<div class="daily-card-meta">计划任务</div></div>`;
        });

        container.innerHTML = html || '<div class="no-daily-data">📭 当日暂无记录</div>';

    } catch (e) {
        container.innerHTML = '<div class="no-daily-data">加载失败</div>';
    }
}

// --- Add Task Modal ---
async function showAddTaskModal() {
    let peopleList = [], tagsList = [];
    try {
        const [p, t] = await Promise.all([API.people.list(), API.tags.list()]);
        peopleList = p.data || [];
        tagsList = t.data || [];
    } catch (e) {}

    const peopleOptions = peopleList.map(p =>
        `<option value="${p.id}">${escapeHtml(p.name)} (${escapeHtml(p.relationship || '')})</option>`
    ).join('');

    const tagOptions = tagsList.map(t =>
        `<option value="${t.id}">${escapeHtml(t.name)}</option>`
    ).join('');

    Modal.open({
        title: '添加新任务',
        body: `
            <div class="form-group">
                <label>任务名称 *</label>
                <input type="text" class="form-input" id="taskName" placeholder="输入任务名称">
            </div>
            <div class="form-group">
                <label>描述</label>
                <textarea class="form-textarea" id="taskDesc" placeholder="任务描述（可选）"></textarea>
            </div>
            <div class="form-group">
                <label>受益人</label>
                <select class="form-select" id="taskPeople" multiple size="3">
                    ${peopleOptions}
                </select>
                <button class="btn btn-ghost btn-sm" style="margin-top:4px;" onclick="showQuickAddPerson()" type="button">+ 添加新人</button>
            </div>
            <div class="form-group">
                <label>标签</label>
                <select class="form-select" id="taskTags" multiple size="3">
                    ${tagOptions}
                </select>
                <button class="btn btn-ghost btn-sm" style="margin-top:4px;" onclick="showQuickAddTag()" type="button">+ 添加新标签</button>
            </div>
        `,
        footer: `
            <button class="btn btn-ghost" onclick="Modal.close()">取消</button>
            <button class="btn btn-primary" id="confirmAddTask">确认添加</button>
        `,
    });

    document.getElementById('confirmAddTask').addEventListener('click', async () => {
        const name = document.getElementById('taskName').value.trim();
        if (!name) { Toast.error('请输入任务名称'); return; }

        const peopleSelect = document.getElementById('taskPeople');
        const peopleIds = Array.from(peopleSelect.selectedOptions).map(o => parseInt(o.value));

        const tagSelect = document.getElementById('taskTags');
        const tagIds = Array.from(tagSelect.selectedOptions).map(o => parseInt(o.value));

        try {
            await API.tasks.create({
                name,
                description: document.getElementById('taskDesc').value.trim(),
                people_ids: peopleIds,
                tag_ids: tagIds,
                stage: 'in_progress',
            });
            Modal.close();
            await refreshAll();
            Toast.success('任务已创建');
        } catch (e) {
            Toast.error('创建失败: ' + e.message);
        }
    });
}

async function showQuickAddPerson() {
    const currentBody = Modal.bodyEl.innerHTML;
    Modal.setBody(`
        <div class="form-group">
            <label>人名 *</label>
            <input type="text" class="form-input" id="quickPersonName" placeholder="输入人名">
        </div>
        <div class="form-group">
            <label>关系</label>
            <input type="text" class="form-input" id="quickPersonRel" placeholder="如: 同事, 朋友">
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-outline btn-sm" id="backToTask">← 返回</button>
            <button class="btn btn-primary btn-sm" id="saveQuickPerson">保存</button>
        </div>
    `);

    document.getElementById('backToTask').addEventListener('click', () => {
        Modal.setBody(currentBody);
        // Re-bind task creation
        showAddTaskModal();
    });

    document.getElementById('saveQuickPerson').addEventListener('click', async () => {
        const name = document.getElementById('quickPersonName').value.trim();
        if (!name) { Toast.error('请输入人名'); return; }
        try {
            await API.people.create({
                name,
                relationship: document.getElementById('quickPersonRel').value.trim(),
            });
            Toast.success('人物已添加');
            Modal.close();
            showAddTaskModal(); // Re-open with refreshed data
        } catch (e) { Toast.error('保存失败: ' + e.message); }
    });
}

async function showQuickAddTag() {
    const currentBody = Modal.bodyEl.innerHTML;
    Modal.setBody(`
        <div class="form-group">
            <label>标签名称 *</label>
            <input type="text" class="form-input" id="quickTagName" placeholder="输入标签名">
        </div>
        <div class="form-group">
            <label>颜色</label>
            <input type="color" class="form-input" id="quickTagColor" value="#3B82F6">
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-outline btn-sm" id="backToTask2">← 返回</button>
            <button class="btn btn-primary btn-sm" id="saveQuickTag">保存</button>
        </div>
    `);

    document.getElementById('backToTask2').addEventListener('click', () => {
        Modal.setBody(currentBody);
        showAddTaskModal();
    });

    document.getElementById('saveQuickTag').addEventListener('click', async () => {
        const name = document.getElementById('quickTagName').value.trim();
        if (!name) { Toast.error('请输入标签名'); return; }
        try {
            await API.tags.create({
                name,
                color: document.getElementById('quickTagColor').value,
            });
            Toast.success('标签已添加');
            Modal.close();
            showAddTaskModal();
        } catch (e) { Toast.error('保存失败: ' + e.message); }
    });
}

// --- Init ---
let _refreshing = false;
let _refreshPending = false;

// Global refresh with lock — queues a re-refresh if called while busy
async function refreshAll() {
    if (_refreshing) {
        _refreshPending = true;
        return;
    }
    _refreshing = true;
    try {
        do {
            _refreshPending = false;
            await Promise.all([
                loadRightPanel(),
                loadLeaderboards(),
                loadDailyStatus(App.selectedDate),
            ]);
            await Calendar.loadMonth();
            Calendar.render();
        } while (_refreshPending);
    } finally {
        _refreshing = false;
    }
}

// --- Right panel tab switching ---
function initPanelTabs() {
    document.querySelectorAll('.rp-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const tabName = tab.dataset.tab;
            document.querySelectorAll('.rp-tab').forEach(t => t.classList.remove('rp-tab-active'));
            tab.classList.add('rp-tab-active');

            const taskBody = document.getElementById('rightPanelBody');
            const aiBody = document.getElementById('rightPanelAi');
            if (tabName === 'tasklist') {
                taskBody.classList.remove('rp-hidden');
                if (aiBody) aiBody.classList.add('rp-hidden');
            } else {
                taskBody.classList.add('rp-hidden');
                if (aiBody) aiBody.classList.remove('rp-hidden');
                if (!window._aiChatInit) {
                    AiChat.init(aiBody);
                    window._aiChatInit = true;
                }
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    await Calendar.init(); // wait for initial calendar load before refreshing
    await refreshAll();
    initPanelTabs();
});
