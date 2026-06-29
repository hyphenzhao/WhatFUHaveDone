/**
 * Task Management Page
 */
const STAGE_LABELS = { in_progress: '🔄 进行中', stage_complete: '✅ 阶段性完成', completed: '🎉 已完成', failed: '❌ 失败/放弃' };
let tasksData = { active: [], archived: [] };
let sortBy = 'priority';
let filterStage = 'all';
let isPrioritySort = true;
let sortAsc = true;

document.addEventListener('DOMContentLoaded', async () => {
    document.getElementById('stageFilter').addEventListener('change', (e) => {
        filterStage = e.target.value;
        loadTasks();
    });
    document.getElementById('sortSelect').addEventListener('change', (e) => {
        sortBy = e.target.value;
        isPrioritySort = (sortBy === 'priority');
        loadTasks();
    });
    document.getElementById('sortDir').addEventListener('click', () => {
        sortAsc = !sortAsc;
        document.getElementById('sortDir').textContent = sortAsc ? '▲' : '▼';
        loadTasks();
    });
    await loadTasks();
});

async function loadTasks() {
    try {
        let stageParam = filterStage === 'all' ? '' : '&stage=' + filterStage;
        const sortParam = '&sort=' + sortBy;
        const [active, archived] = await Promise.all([
            API.tasks.list(0), // always get all active, filter client-side for simplicity
            API.tasks.list(1)
        ]);
        let activeList = active.data || [];
        if (filterStage !== 'all') {
            activeList = activeList.filter(t => t.stage === filterStage);
        }
        if (sortBy === 'priority') {
            activeList.sort((a, b) => sortAsc ? (a.priority||999) - (b.priority||999) : (b.priority||999) - (a.priority||999));
        } else {
            activeList.sort((a, b) => sortAsc ? a.id - b.id : b.id - a.id);
        }
        tasksData = { active: activeList, archived: archived.data || [] };
        renderTasksTable(tasksData.active, 'tasksTableBody', false);
        renderTasksTable(tasksData.archived, 'archivedTasksBody', true);
        document.getElementById('archivedTasksSection').style.display = tasksData.archived.length ? 'block' : 'none';
        if (isPrioritySort) initDragDrop();
    } catch (e) { Toast.error('加载失败: ' + e.message); }
}

function starHtml(name, val) {
    const id = 'star_' + name;
    let h = `<span class="star-rating" id="${id}">`;
    for (let i = 1; i <= 5; i++) {
        h += `<span class="star${i <= val ? ' filled' : ''}" data-v="${i}" onclick="setStar('${id}',${i})">★</span>`;
    }
    h += '</span>';
    return h;
}
function getStarVal(id) { return document.querySelectorAll('#' + id + ' .star.filled').length; }
function setStar(id, v) {
    const el = document.getElementById(id);
    if (!el) return;
    el.querySelectorAll('.star').forEach((s, i) => s.classList.toggle('filled', i < v));
}

function renderTasksTable(tasks, tbodyId, isArchived) {
    const tbody = document.getElementById(tbodyId);
    const cols = isArchived ? 4 : 8;
    if (tasks.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;color:var(--color-text-secondary);">暂无数据</td></tr>`;
        return;
    }
    tbody.innerHTML = tasks.map(t => {
        const peopleNames = (t.people || []).map(p => escapeHtml(p.name)).join(', ') || '-';
        const tagSpans = (t.tags || []).map(tg => `<span class="task-card-tag" style="background:${tg.color}">${escapeHtml(tg.name)}</span>`).join(' ') || '-';
        if (isArchived) return `<tr data-id="${t.id}"><td><strong>${escapeHtml(t.name)}</strong></td><td>${STAGE_LABELS[t.stage] || t.stage}</td><td>${t.stage_number}</td><td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="restoreTask(${t.id})">恢复</button><button class="btn btn-danger btn-sm" onclick="deleteTask(${t.id})">删除</button></div></td></tr>`;
        return `<tr data-id="${t.id}" class="${isPrioritySort?'draggable':''}">
            <td><span class="drag-handle" style="cursor:${isPrioritySort?'grab':'default'};margin-right:4px;">${isPrioritySort?'⋮⋮':''}</span><strong>${escapeHtml(t.name)}</strong></td>
            <td>${t.priority || '-'}</td>
            <td>${STAGE_LABELS[t.stage] || t.stage}</td>
            <td>${t.stage_number}</td>
            <td>${getDeadlineBadge(t.deadline)}</td>
            <td>${peopleNames}</td>
            <td>${tagSpans}</td>
            <td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="editTask(${t.id})">编辑</button><button class="btn btn-ghost btn-sm" onclick="archiveTask(${t.id})">归档</button></div></td></tr>`;
    }).join('');
}

function initDragDrop() {
    const tbody = document.getElementById('tasksTableBody');
    if (!tbody) return;
    let draggedRow = null;
    tbody.querySelectorAll('tr.draggable').forEach(row => {
        row.addEventListener('dragstart', (e) => { draggedRow = row; row.style.opacity = '0.5'; });
        row.addEventListener('dragend', async (e) => {
            row.style.opacity = '1';
            if (!draggedRow) return;
            const rows = Array.from(tbody.querySelectorAll('tr.draggable'));
            const ids = rows.map(r => parseInt(r.dataset.id));
            // Get the priority of the first visible task as baseline
            const firstPri = tasksData.active.find(t => t.id === ids[0])?.priority || 1;
            // Build update list: each task gets firstPri + index
            const updates = ids.map((id, i) => ({ id, priority: firstPri + i }));
            try {
                for (const u of updates) {
                    await API.tasks.update(u.id, { priority: u.priority });
                }
                Toast.success('优先级已更新');
                loadTasks();
            } catch(e) { Toast.error('更新失败'); }
            draggedRow = null;
        });
        row.addEventListener('dragover', (e) => { e.preventDefault(); });
        row.addEventListener('drop', (e) => {
            e.preventDefault();
            if (!draggedRow || draggedRow === row) return;
            const rows = Array.from(tbody.querySelectorAll('tr.draggable'));
            const fromIdx = rows.indexOf(draggedRow);
            const toIdx = rows.indexOf(row);
            if (fromIdx < toIdx) {
                tbody.insertBefore(draggedRow, row.nextSibling);
            } else {
                tbody.insertBefore(draggedRow, row);
            }
        });
        row.setAttribute('draggable', 'true');
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
            <div class="form-group"><label>阶段</label><select class="form-select" id="taskStage">${Object.entries(STAGE_LABELS).map(([k,v])=>`<option value="${k}" ${task?.stage===k?'selected':''}>${v}</option>`).join('')}</select></div>
            <div class="form-row-2">
                <div class="form-group"><label>重要度</label>${starHtml('importance', imp)}</div>
                <div class="form-group"><label>必要度</label>${starHtml('necessity', nec)}</div>
            </div>
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
            people_ids: getSelectedTagIds('people'), tag_ids: getSelectedTagIds('tags'),
            importance: getStarVal('star_importance'), necessity: getStarVal('star_necessity'),
            deadline: (() => { const t = document.getElementById('taskDeadlineType').value; return t === 'date' ? document.getElementById('taskDeadlineDate').value : t; })(),
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
