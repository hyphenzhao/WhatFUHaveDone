/**
 * Home page — orchestrates right panel, leaderboards, daily status, calendar
 */

// Global refresh function called by task-card actions
async function refreshAll() {
    await Promise.all([
        loadRightPanel(),
        loadLeaderboards(),
        loadDailyStatus(App.selectedDate),
    ]);
    await Calendar.loadMonth();
    Calendar.render();
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

        renderLeaderboard('workloadLeaderboard', '💪 工作量排行', workload.data || []);
        renderLeaderboard('resultsLeaderboard', '🏆 成果排行', results.data || []);
    } catch (e) {
        console.error('Leaderboard load failed:', e);
    }
}

function renderLeaderboard(containerId, title, items) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const maxVal = items.length > 0 ? Math.max(...items.map(i => i.total_workload || i.total_results || 0)) : 1;

    const html = items.map((item, idx) => {
        const count = item.total_workload || item.total_results || 0;
        const pct = maxVal > 0 ? (count / maxVal * 100) : 0;
        const color = item.color || '#3B82F6';
        return `
            <div class="leaderboard-item">
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
}

async function loadDailyStatus(date) {
    const container = document.getElementById('dailyStatusCards');
    const dateDisplay = document.getElementById('dailyDateDisplay');
    if (!container) return;

    dateDisplay.textContent = formatDate(date);

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
let calendarInitialized = false;
document.addEventListener('DOMContentLoaded', async () => {
    Calendar.init(); // bind events once
    await refreshAll();
});
