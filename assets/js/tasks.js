/**
 * Task Management Page
 */
const STAGE_LABELS = { in_progress: '🔄 进行中', stage_complete: '✅ 阶段性完成', completed: '🎉 已完成', failed: '❌ 失败/放弃' };
let tasksData = { active: [], archived: [] };
let sortBy = 'priority';
let filterStage = 'all';
let filterType = 'all';
let isPrioritySort = true;
let sortAsc = true;
let allTasksData = null, taskSearch = null, taskLoadVersion = 0;
let taskReorderSaving = false;

document.addEventListener('DOMContentLoaded', async () => {
    taskSearch = TaskSearch.mount(document.getElementById('tasksSearch'), {
        id: 'taskSearchInput', placeholder: '搜索任务、人物、标签…（含已归档）', controls: 'tasksTable archivedTasksSection', onChange: renderTasks
    });
    document.getElementById('typeFilter').addEventListener('change', e => {
        filterType = e.target.value;
        renderTasks();
    });
    document.getElementById('stageFilter').addEventListener('change', (e) => {
        filterStage = e.target.value;
        renderTasks();
    });
    document.getElementById('sortSelect').addEventListener('change', (e) => {
        sortBy = e.target.value;
        renderTasks();
    });
    document.getElementById('sortDir').addEventListener('click', () => {
        sortAsc = !sortAsc;
        document.getElementById('sortDir').textContent = sortAsc ? '▲' : '▼';
        renderTasks();
    });
    document.getElementById('resetTaskFilters').addEventListener('click', () => {
        filterType = filterStage = 'all';
        document.getElementById('typeFilter').value = document.getElementById('stageFilter').value = 'all';
        renderTasks();
    });
    document.getElementById('enableTaskReorder').addEventListener('click', enableTaskReorder);
    await loadTasks();
});

async function loadTasks() {
    const version = ++taskLoadVersion;
    try {
        const [active, archived] = await Promise.all([
            API.tasks.list(0),
            API.tasks.list(1)
        ]);
        if (version !== taskLoadVersion) return;
        allTasksData = { active: active.data || [], archived: archived.data || [] };
        renderTasks();
    } catch (e) {
        if (version !== taskLoadVersion) return;
        taskSearch.status('加载失败，请刷新页面重试。');
        if (!allTasksData) document.getElementById('tasksTableBody').innerHTML = '<tr><td colspan="8">加载失败，请刷新页面重试。</td></tr>';
        Toast.error('加载失败: ' + e.message);
    }
}

function renderTasks() {
    if (!allTasksData) return;
    const terms = taskSearch.terms();
    const filtered = filterType !== 'all' || filterStage !== 'all';
    // Never let a search result subset overwrite hidden tasks' priorities.
    isPrioritySort = !taskReorderSaving && sortBy === 'priority' && sortAsc && !filtered && !terms.length;
    const visible = list => list.filter(t => (filterType === 'all' || t.task_type === filterType)
        && (filterStage === 'all' || t.stage === filterStage) && TaskSearch.matches(t, terms));
    const activeList = visible(allTasksData.active), archivedList = visible(allTasksData.archived);
    const compare = sortBy === 'priority'
        ? (a, b) => sortAsc ? (a.priority||999) - (b.priority||999) : (b.priority||999) - (a.priority||999)
        : (a, b) => {
            const cmp = String(a.updated_at || '').localeCompare(String(b.updated_at || ''));
            return sortAsc ? cmp : -cmp;
        };
    activeList.sort(compare); archivedList.sort(compare);
    tasksData = { active: activeList, archived: archivedList };
    renderTasksTable(tasksData.active, 'tasksTableBody', false);
    renderTasksTable(tasksData.archived, 'archivedTasksBody', true);
    document.getElementById('archivedTasksSection').style.display = tasksData.archived.length ? 'block' : 'none';
    const total = activeList.length + archivedList.length;
    taskSearch.status(`${terms.length || filtered ? '找到' : '共'} ${total} 项任务 · 未归档 ${activeList.length} · 已归档 ${archivedList.length}`);
    document.getElementById('taskFilterHint').textContent = filtered ? '当前分类和阶段筛选同时适用于已归档任务。' : '';
    document.getElementById('resetTaskFilters').hidden = !filtered;
    document.getElementById('taskReorderHint').textContent = taskReorderSaving ? '正在保存优先级…'
        : isPrioritySort ? '拖动任务左侧的 ⋮⋮ 调整优先级，也可使用 ↑ ↓ 微调。'
        : '搜索、筛选或其他排序下暂不调整顺序；点击“调整优先级”回到完整列表。';
    const reorderButton = document.getElementById('enableTaskReorder');
    reorderButton.hidden = isPrioritySort; reorderButton.disabled = taskReorderSaving;
    if (isPrioritySort) initDragDrop();
}

function renderTasksTable(tasks, tbodyId, isArchived) {
    const tbody = document.getElementById(tbodyId);
    const cols = isArchived ? 4 : 8;
    const terms = taskSearch.terms();
    const text = value => TaskSearch.highlight(value, terms);
    if (tasks.length === 0) {
        const searching = terms.length || filterType !== 'all' || filterStage !== 'all';
        const archivedMatches = !isArchived && tasksData.archived.length;
        tbody.innerHTML = `<tr><td colspan="${cols}"><div class="task-search-empty"><strong>${archivedMatches ? '匹配的任务都在下方“已归档任务”中' : searching ? '没有找到匹配的任务' : '还没有任务'}</strong><p>${archivedMatches ? '可以直接查看详情，或点击“恢复”继续处理。' : searching ? '试试更短的关键词，或清空搜索、重置筛选。' : '点击右上方“新建任务”开始记录。'}</p></div></td></tr>`;
        return;
    }
    tbody.innerHTML = tasks.map((t, index) => {
        const peopleNames = (t.people || []).map(p => text(p.name)).join(', ') || '-';
        const tagSpans = (t.tags || []).map(tg => `<span class="task-card-tag" style="background:${escapeHtml(tg.color)}">${text(tg.name)}</span>`).join(' ') || '-';
        const taskNameAttr = escapeHtml(t.name).replace(/"/g, '&quot;');
        const title = `<div class="task-table-title"><button type="button" class="task-search-open" onclick="TaskDetail.open(${t.id})" title="查看任务详情">${text(t.name)}</button> ${taskTypeBadge(t)}${TaskSearch.context(t, terms)}</div>`;
        if (isArchived) return `<tr data-id="${t.id}"><td>${title}</td><td>${STAGE_LABELS[t.stage] || escapeHtml(t.stage)}</td><td>${t.stage_number}</td><td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="restoreTask(${t.id})">恢复</button><button class="btn btn-danger btn-sm" onclick="deleteTask(${t.id})">删除</button></div></td></tr>`;
        return `<tr data-id="${t.id}" class="${isPrioritySort?'draggable':''}">
            <td><div class="task-table-name">${isPrioritySort ? `<span class="drag-handle task-drag-handle" draggable="true" role="button" tabindex="0" aria-label="拖动调整 ${taskNameAttr} 的优先级" title="拖动调整顺序，或聚焦后按上下方向键">⋮⋮</span>` : ''}${title}</div></td>
            <td><div class="task-priority-cell"><span>${t.priority || '-'}</span>${isPrioritySort ? `<span class="task-priority-buttons"><button type="button" aria-label="上移 ${taskNameAttr}" title="上移一位" onclick="moveTaskPriority(${t.id}, -1)" ${index === 0 ? 'disabled' : ''}>↑</button><button type="button" aria-label="下移 ${taskNameAttr}" title="下移一位" onclick="moveTaskPriority(${t.id}, 1)" ${index === tasks.length - 1 ? 'disabled' : ''}>↓</button></span>` : ''}</div></td>
            <td>${STAGE_LABELS[t.stage] || escapeHtml(t.stage)}</td>
            <td>${t.stage_number}</td>
            <td>${getDeadlineBadge(t.deadline)}</td>
            <td>${peopleNames}</td>
            <td>${tagSpans}</td>
            <td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="editTask(${t.id})">编辑</button><button class="btn btn-ghost btn-sm" onclick="archiveTask(${t.id})">归档</button></div></td></tr>`;
    }).join('');
}

function enableTaskReorder() {
    if (taskReorderSaving) return;
    filterType = filterStage = 'all'; sortBy = 'priority'; sortAsc = true;
    document.getElementById('typeFilter').value = document.getElementById('stageFilter').value = 'all';
    document.getElementById('sortSelect').value = 'priority'; document.getElementById('sortDir').textContent = '▲';
    taskSearch.clear();
}

async function saveTaskOrder(ids) {
    if (!isPrioritySort || taskReorderSaving) return;
    const before = tasksData.active.map(t => Number(t.id));
    if (before.length !== ids.length || ids.some(id => !before.includes(id)) || new Set(ids).size !== ids.length) return;
    if (before.every((id, i) => id === ids[i])) return;
    taskReorderSaving = true; renderTasks();
    try {
        await API.tasks.reorder(ids);
        const priorities = new Map(ids.map((id, i) => [id, i + 1]));
        allTasksData.active.forEach(task => { if (priorities.has(Number(task.id))) task.priority = priorities.get(Number(task.id)); });
        Toast.success('优先级已保存');
    } catch (e) {
        Toast.error('保存优先级失败：' + e.message);
        await loadTasks();
    } finally { taskReorderSaving = false; renderTasks(); }
}

async function moveTaskPriority(id, direction) {
    if (!isPrioritySort || taskReorderSaving) return;
    const ids = tasksData.active.map(t => Number(t.id)), from = ids.indexOf(id), to = from + direction;
    if (from < 0 || to < 0 || to >= ids.length || ![-1, 1].includes(direction)) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];
    await saveTaskOrder(ids);
    document.querySelector(`#tasksTableBody tr[data-id="${id}"] .task-drag-handle`)?.focus();
}

function initDragDrop() {
    const tbody = document.getElementById('tasksTableBody');
    if (!tbody) return;
    let draggedRow = null;
    tbody.querySelectorAll('tr.draggable').forEach(row => {
        const handle = row.querySelector('.task-drag-handle');
        handle.addEventListener('dragstart', e => {
            if (!isPrioritySort || taskReorderSaving) { e.preventDefault(); return; }
            draggedRow = row; row.classList.add('task-row-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.dataset.id);
            e.dataTransfer.setDragImage(row, 20, 20);
        });
        handle.addEventListener('dragend', () => {
            row.classList.remove('task-row-dragging'); draggedRow = null;
            tbody.querySelectorAll('.task-drop-target').forEach(r => r.classList.remove('task-drop-target'));
        });
        handle.addEventListener('keydown', e => {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            e.preventDefault(); moveTaskPriority(Number(row.dataset.id), e.key === 'ArrowUp' ? -1 : 1);
        });
        row.addEventListener('dragover', e => {
            if (!draggedRow || !draggedRow.isConnected || !isPrioritySort || taskReorderSaving) return;
            e.preventDefault(); e.dataTransfer.dropEffect = 'move';
            tbody.querySelectorAll('.task-drop-target').forEach(r => r.classList.remove('task-drop-target'));
            if (draggedRow !== row) row.classList.add('task-drop-target');
        });
        row.addEventListener('drop', (e) => {
            e.preventDefault();
            row.classList.remove('task-drop-target');
            if (!draggedRow || !draggedRow.isConnected || draggedRow === row || !isPrioritySort || taskReorderSaving) return;
            const rows = Array.from(tbody.querySelectorAll('tr.draggable'));
            const fromIdx = rows.indexOf(draggedRow);
            const toIdx = rows.indexOf(row);
            if (fromIdx < toIdx) {
                tbody.insertBefore(draggedRow, row.nextSibling);
            } else {
                tbody.insertBefore(draggedRow, row);
            }
            const ids = Array.from(tbody.querySelectorAll('tr.draggable')).map(r => Number(r.dataset.id));
            draggedRow.classList.remove('task-row-dragging'); draggedRow = null;
            saveTaskOrder(ids);
        });
    });
}

async function showTaskModal(taskId = null) {
    let task = null, peopleList = [], tagsList = [];
    try {
        const [t, p, tg] = await Promise.all([taskId ? API.tasks.get(taskId) : null, API.people.list(), API.tags.list()]);
        if (t) task = t.data;
        peopleList = p.data || []; tagsList = tg.data || [];
    } catch (e) {}
    const selPeopleIds = (task?.people||[]).map(p => p.id);
    const selTagIds = (task?.tags||[]).map(t => t.id);
    const imp = task?.importance || 3;
    const nec = task?.necessity || 3;
    Modal.open({
        title: task ? '编辑任务' : '新建任务',
        body: `<div class="form-group"><label>任务名称 *</label><input class="form-input" id="taskName" value="${escapeHtml(task?.name||'')}"></div>
            <div class="form-group"><label>描述</label><textarea class="form-textarea" id="taskDesc">${escapeHtml(task?.description||'')}</textarea></div>
            <div class="form-group"><label>任务分类</label><select class="form-select" id="taskType"><option value="general" ${task?.task_type!=='meeting'?'selected':''}>一般任务</option><option value="meeting" ${task?.task_type==='meeting'?'selected':''}>会议</option></select><small class="task-type-hint">会议计划结束后自动完成；未填结束时间则在当天结束后完成。</small></div>
            <div class="form-group"><label>阶段</label><select class="form-select" id="taskStage">${Object.entries(STAGE_LABELS).map(([k,v])=>`<option value="${k}" ${task?.stage===k?'selected':''}>${v}</option>`).join('')}</select></div>
            <div class="form-row-2">
                <div class="form-group"><label>重要度</label>${starHtml('importance', imp)}</div>
                <div class="form-group"><label>必要度</label>${starHtml('necessity', nec)}</div>
            </div>
            <div class="form-group"><label>地点</label><input type="text" class="form-input" id="taskLocation" value="${escapeHtml(task?.location||'')}" placeholder="可选"></div>
            <div class="form-group"><label>截止日期</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <select class="form-select" id="taskDeadlineType" onchange="document.getElementById('taskDeadlineDate').style.display=this.value==='date'?'':'none';if(this.value!=='date')document.getElementById('taskDeadlineDate').value=this.value;" style="width:auto;">
                        ${(() => { const dl = task?.deadline||''; const sel = dl === '尽快' ? '尽快' : dl === '自由' ? '自由' : /^\d{4}-\d{2}-\d{2}$/.test(dl) ? 'date' : ''; return `<option value="" ${!sel?'selected':''}>无截止</option><option value="date" ${sel==='date'?'selected':''}>📅 指定日期</option><option value="尽快" ${sel==='尽快'?'selected':''}>⚡ 尽快</option><option value="自由" ${sel==='自由'?'selected':''}>🆓 自由</option>`; })()}
                    </select>
                    <input type="date" class="form-input" id="taskDeadlineDate" value="${/^\d{4}-\d{2}-\d{2}$/.test(task?.deadline||'')?task.deadline:''}" style="flex:1;${/^\d{4}-\d{2}-\d{2}$/.test(task?.deadline||'')?'':'display:none'}">
                </div></div>
            <div class="form-group"><label>受益人</label><div class="tag-chip-group" id="tagGroup_people">${tagSelectHtml('people', peopleList, selPeopleIds, 'people')}</div></div>
            <div class="form-group"><label>标签</label><div class="tag-chip-group" id="tagGroup_tags">${tagSelectHtml('tags', tagsList, selTagIds, 'tag')}</div></div>`,
        footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="saveTask">${task?'保存':'创建'}</button>`,
    });
    document.getElementById('saveTask').addEventListener('click', async () => {
        const name = document.getElementById('taskName').value.trim();
        if (!name) { Toast.error('请输入任务名称'); return; }
        const data = {
            name, description: document.getElementById('taskDesc').value.trim(),
            stage: document.getElementById('taskStage').value,
            task_type: document.getElementById('taskType').value,
            people_ids: getSelectedTagIds('people'), tag_ids: getSelectedTagIds('tags'),
            importance: getStarVal('star_importance'), necessity: getStarVal('star_necessity'),
            deadline: (() => { const t = document.getElementById('taskDeadlineType').value; return t === 'date' ? document.getElementById('taskDeadlineDate').value : t; })(),
            location: document.getElementById('taskLocation').value.trim(),
        };
        try {
            if (taskId) { await API.tasks.update(taskId, data); }
            else { await API.tasks.create(data); }
            Modal.close(); loadTasks();
            Toast.success(taskId ? '已更新' : '已创建');
        } catch(e) { Toast.error('失败:' + e.message); }
    });
}
function editTask(id) { showTaskModal(id); }
async function archiveTask(id) { if(!confirm('确定归档？'))return; try{await API.tasks.update(id,{archived:1});loadTasks();Toast.success('已归档')}catch(e){Toast.error('失败')} }
async function restoreTask(id) { try{await API.tasks.update(id,{archived:0});loadTasks();Toast.success('已恢复')}catch(e){Toast.error('失败')} }
async function deleteTask(id) { if(!confirm('确定永久删除？'))return; try{await API.tasks.remove(id);loadTasks();Toast.success('已删除')}catch(e){Toast.error('失败')} }
