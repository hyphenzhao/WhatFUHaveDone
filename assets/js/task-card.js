/**
 * Task Card component — renders task cards based on stage
 */
const TaskCard = {
    /**
     * Render a task card for the given stage
     * @param {Object} task - Task object from API
     * @param {Object} options - {date: string, workLogActive: bool}
     */
    render(task, options = {}) {
        const stage = task.stage;
        const date = options.date || App.selectedDate;
        const workLogActive = options.workLogActive || false;
        const latestNote = options.latestNote || '';

        const tagsHtml = (task.tags || []).map(t =>
            `<span class="task-card-tag" style="background:${escapeHtml(t.color)}">${escapeHtml(t.name)}</span>`
        ).join('');

        let actionsHtml = '';

        if (stage === 'in_progress') {
            actionsHtml = this._renderInProgressActions(task, date, workLogActive);
        } else if (stage === 'stage_complete') {
            actionsHtml = this._renderStageCompleteActions(task);
        } else if (stage === 'completed') {
            actionsHtml = this._renderCompletedActions(task);
        } else if (stage === 'failed') {
            actionsHtml = this._renderFailedActions(task);
        }

        const noteHtml = latestNote ? `<div class="task-card-note">📝 ${escapeHtml(latestNote)}</div>` : '';
        const deadlineHtml = getDeadlineBadge(task.deadline);

        return `
            <div class="task-card" data-task-id="${task.id}" data-stage="${stage}">
                <div class="task-card-name">${escapeHtml(task.name)}</div>
                <div class="task-card-tags"><span class="task-tags-left">${tagsHtml}</span><span class="task-tags-right">${deadlineHtml}</span></div>
                ${task.stage_number > 1 ? `<div style="font-size:0.7rem;color:var(--color-text-secondary);margin-bottom:4px;">阶段 ${task.stage_number}${task.stage_changed_at ? ' · ' + timeAgo(task.stage_changed_at) : ''}</div>` : ''}
                ${noteHtml}
                <div class="task-card-actions">
                    ${actionsHtml}
                </div>
            </div>
        `;
    },

    _renderInProgressActions(task, date, workLogActive) {
        const worklogClass = workLogActive ? 'btn-worklog active' : 'btn-worklog';
        const worklogText = workLogActive ? '😊' : '+1';
        const worklogTitle = workLogActive ? '取消今日工作量' : '增加今日工作量';

        return `
            <div class="task-card-actions-left">
                <button class="btn-plan" onclick="TaskCard.addPlan(${task.id})" title="添加计划">📅 计划</button>
            </div>
            <div class="task-card-actions-right">
                <select class="stage-select" onchange="TaskCard.changeStage(${task.id}, this.value)" title="更改状态">
                    <option value="">⚙</option>
                    <option value="stage_complete">阶段性完成</option>
                    <option value="completed">已完成</option>
                    <option value="failed">失败/放弃</option>
                </select>
                <button class="${worklogClass}" onclick="TaskCard.toggleWorklog(${task.id}, '${date}')" title="${worklogTitle}">
                    ${worklogText}
                </button>
                <button class="btn-trophy" onclick="TaskCard.addResult(${task.id}, '${date}')" title="添加产出成果">
                    🏆+1
                </button>
            </div>
        `;
    },

    _renderStageCompleteActions(task) {
        return `
            <div class="task-card-actions-left">
                <button class="btn-plan" onclick="TaskCard.addPlan(${task.id})" title="添加计划">📅 计划</button>
            </div>
            <div class="task-card-actions-right">
                <select class="stage-select" onchange="TaskCard.changeStage(${task.id}, this.value)" title="移动到">
                    <option value="">↳</option>
                    <option value="in_progress">🔄 进行中</option>
                    <option value="completed">🎉 已完成</option>
                    <option value="failed">❌ 失败/放弃</option>
                </select>
                <button class="btn-primary btn-sm" onclick="TaskCard.nextStage(${task.id})">
                    下一阶段 ▶
                </button>
            </div>
        `;
    },

    _renderCompletedActions(task) {
        return `
            <div class="task-card-actions-left">
                <button class="btn-continue" onclick="TaskCard.continueTask(${task.id})">
                    🔄 继续任务
                </button>
            </div>
            <div class="task-card-actions-right">
                <select class="stage-select" onchange="TaskCard.changeStage(${task.id}, this.value)" title="移动到">
                    <option value="">↳</option>
                    <option value="in_progress">🔄 进行中</option>
                    <option value="stage_complete">✅ 阶段性完成</option>
                    <option value="failed">❌ 失败/放弃</option>
                </select>
            </div>
        `;
    },

    _renderFailedActions(task) {
        return `
            <div class="task-card-actions-left">
                <button class="btn-ghost btn-sm" onclick="TaskCard.changeStage(${task.id}, 'in_progress')">重新开始</button>
            </div>
            <div class="task-card-actions-right">
                <select class="stage-select" onchange="TaskCard.changeStage(${task.id}, this.value)" title="移动到">
                    <option value="">↳</option>
                    <option value="in_progress">🔄 进行中</option>
                    <option value="stage_complete">✅ 阶段性完成</option>
                    <option value="completed">🎉 已完成</option>
                </select>
            </div>
        `;
    },

    // --- Actions ---

    async toggleWorklog(taskId, date) {
        try {
            const res = await API.worklogs.toggle(taskId, date);
            // Refresh the right panel and daily status
            if (typeof refreshAll === 'function') await refreshAll();
            Toast.success(res.data.active ? '已记录工作量 +1' : '已取消工作量');
        } catch (e) {
            Toast.error('操作失败: ' + e.message);
        }
    },

    async changeStage(taskId, newStage) {
        if (!newStage) return;
        try {
            const task = await API.tasks.get(taskId);
            const data = { name: task.data.name, description: task.data.description || '', stage: newStage, stage_number: task.data.stage_number };
            // Clear deadline when completing
            if (newStage === 'completed' || newStage === 'stage_complete') {
                data.deadline = '';
            }
            await API.tasks.update(taskId, data);
            if (typeof refreshAll === 'function') await refreshAll();
            Toast.success('状态已更新');
        } catch (e) {
            Toast.error('操作失败: ' + e.message);
        }
    },

    async nextStage(taskId) {
        try {
            const task = await API.tasks.get(taskId);
            await API.tasks.update(taskId, {
                name: task.data.name,
                stage: 'in_progress',
                stage_number: task.data.stage_number + 1,
            });
            if (typeof refreshAll === 'function') await refreshAll();
            Toast.success('已进入下一阶段');
        } catch (e) {
            Toast.error('操作失败: ' + e.message);
        }
    },

    continueTask(taskId) {
        Modal.open({
            title: '继续任务',
            body: `
                <p style="margin-bottom:12px;">选择继续方式：</p>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-primary" id="continueRestart">从头开始（阶段1）</button>
                    <button class="btn btn-outline" id="continueResume">继续当前阶段</button>
                </div>
            `,
        });
        document.getElementById('continueRestart').addEventListener('click', async () => {
            try {
                const task = await API.tasks.get(taskId);
                await API.tasks.update(taskId, {
                    name: task.data.name,
                    stage: 'in_progress',
                    stage_number: 1,
                });
                Modal.close();
                if (typeof refreshAll === 'function') await refreshAll();
                Toast.success('任务已重新开始');
            } catch (e) { Toast.error('操作失败: ' + e.message); }
        });
        document.getElementById('continueResume').addEventListener('click', async () => {
            try {
                const task = await API.tasks.get(taskId);
                await API.tasks.update(taskId, {
                    name: task.data.name,
                    stage: 'in_progress',
                    stage_number: task.data.stage_number,
                });
                Modal.close();
                if (typeof refreshAll === 'function') await refreshAll();
                Toast.success('任务已继续');
            } catch (e) { Toast.error('操作失败: ' + e.message); }
        });
    },

    addPlan(taskId) {
        Modal.open({
            title: '添加计划',
            body: `
                <div class="form-group"><label>计划日期</label><input type="date" class="form-input" id="planDateInput" value="${App.selectedDate}"></div>
                <div class="form-row-2">
                    <div class="form-group"><label>开始时间</label><input type="time" class="form-input" id="planTimeInput"></div>
                    <div class="form-group"><label>结束时间（可选）</label><input type="time" class="form-input" id="planEndTimeInput"></div>
                </div>
                <div style="font-size:0.72rem;color:var(--color-text-secondary);">留空=全天任务</div>
            `,
            footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="confirmPlan">确认添加</button>`,
        });
        document.getElementById('confirmPlan').addEventListener('click', async () => {
            const plannedDate = document.getElementById('planDateInput').value;
            if (!plannedDate) { Toast.error('请选择日期'); return; }
            try {
                await API.plans.add(taskId, plannedDate, document.getElementById('planTimeInput').value, document.getElementById('planEndTimeInput').value);
                Modal.close();
                if (typeof refreshAll === 'function') await refreshAll();
                Toast.success('计划已添加');
            } catch (e) { Toast.error('操作失败: ' + e.message); }
        });
    },

    async addResult(taskId, date) {
        // Fetch results for selection
        let resultsList = [];
        try {
            const res = await API.results.list();
            resultsList = res.data || [];
        } catch (e) { resultsList = []; }

        const resultsOptions = resultsList.map(r =>
            `<option value="${r.id}">${escapeHtml(r.name)} (${r.quantity || 1}个, ${escapeHtml(r.level || '')})</option>`
        ).join('');

        Modal.open({
            title: '添加产出成果',
            body: `
                <div class="form-group">
                    <label>选择已有成果</label>
                    <select class="form-select" id="resultSelect">
                        <option value="">-- 选择成果 --</option>
                        ${resultsOptions}
                    </select>
                </div>
                <div style="text-align:center;color:var(--color-text-secondary);margin:8px 0;">— 或者 —</div>
                <div class="form-group">
                    <label>新建成果名称</label>
                    <input type="text" class="form-input" id="newResultName" placeholder="输入新成果名称">
                </div>
                <div class="form-group">
                    <label>数量</label>
                    <input type="number" class="form-input" id="newResultQty" value="1" min="1">
                </div>
                <div class="form-group">
                    <label>等级</label>
                    <input type="text" class="form-input" id="newResultLevel" placeholder="如: A, B, C">
                </div>
                <div class="form-group">
                    <label>耗时（可选）</label>
                    <input type="text" class="form-input" id="resultDuration" placeholder="如: 2h / 30m / 1.5h">
                </div>
            `,
            footer: `
                <button class="btn btn-ghost" onclick="Modal.close()">取消</button>
                <button class="btn btn-primary" id="confirmResult">确认</button>
            `,
        });

        document.getElementById('confirmResult').addEventListener('click', async () => {
            const selectVal = document.getElementById('resultSelect').value;
            const newName = document.getElementById('newResultName').value.trim();

            try {
                let resultId;

                if (newName) {
                    // Create new result first
                    const newRes = await API.results.create({
                        name: newName,
                        quantity: parseInt(document.getElementById('newResultQty').value) || 1,
                        level: document.getElementById('newResultLevel').value.trim(),
                    });
                    resultId = newRes.data.id;
                } else if (selectVal) {
                    resultId = parseInt(selectVal);
                } else {
                    Toast.error('请选择或新建成果');
                    return;
                }

                // Log the result via result_logs API
                await fetch('/api/result_logs', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        task_id: taskId,
                        result_id: resultId,
                        date: date,
                        duration: document.getElementById('resultDuration').value.trim(),
                    }),
                });

                Modal.close();
                if (typeof refreshAll === 'function') await refreshAll();
                Toast.success('成果已记录');
            } catch (e) {
                Toast.error('操作失败: ' + e.message);
            }
        });
    },
};
