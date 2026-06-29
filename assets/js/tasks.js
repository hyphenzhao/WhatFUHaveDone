/**
 * Task Management Page
 */
const STAGE_LABELS = { in_progress: '🔄 进行中', stage_complete: '✅ 阶段性完成', completed: '🎉 已完成', failed: '❌ 失败/放弃' };
let tasksData = { active: [], archived: [] };

document.addEventListener('DOMContentLoaded', async () => {
    await loadTasks();
    makeSortable('tasksTable', (col, asc) => renderTasksTable(sortData(tasksData.active, col, asc), 'tasksTableBody', false));
});

async function loadTasks() {
    try {
        const [active, archived] = await Promise.all([API.tasks.list(0), API.tasks.list(1)]);
        tasksData = { active: active.data || [], archived: archived.data || [] };
        renderTasksTable(tasksData.active, 'tasksTableBody', false);
        renderTasksTable(tasksData.archived, 'archivedTasksBody', true);
        document.getElementById('archivedTasksSection').style.display = tasksData.archived.length ? 'block' : 'none';
    } catch (e) { Toast.error('加载失败: ' + e.message); }
}

function renderTasksTable(tasks, tbodyId, isArchived) {
    const tbody = document.getElementById(tbodyId);
    if (tasks.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${isArchived ? 4 : 6}" style="text-align:center;color:var(--color-text-secondary);">暂无数据</td></tr>`;
        return;
    }
    tbody.innerHTML = tasks.map(t => {
        const peopleNames = (t.people || []).map(p => escapeHtml(p.name)).join(', ') || '-';
        const tagSpans = (t.tags || []).map(tg => `<span class="task-card-tag" style="background:${tg.color}">${escapeHtml(tg.name)}</span>`).join(' ') || '-';
        if (isArchived) return `<tr><td><strong>${escapeHtml(t.name)}</strong></td><td>${STAGE_LABELS[t.stage] || t.stage}</td><td>${t.stage_number}</td><td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="restoreTask(${t.id})">恢复</button><button class="btn btn-danger btn-sm" onclick="deleteTask(${t.id})">删除</button></div></td></tr>`;
        return `<tr><td><strong>${escapeHtml(t.name)}</strong></td><td>${STAGE_LABELS[t.stage] || t.stage}</td><td>${t.stage_number}</td><td>${peopleNames}</td><td>${tagSpans}</td><td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="editTask(${t.id})">编辑</button><button class="btn btn-ghost btn-sm" onclick="archiveTask(${t.id})">归档</button></div></td></tr>`;
    }).join('');
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
    const pOpts = tagSelectHtml('people', peopleList, selPeopleIds, 'people');
    const tOpts = tagSelectHtml('tags', tagsList, selTagIds, 'tag');
    Modal.open({
        title: task ? '编辑任务' : '新建任务',
        body: `<div class="form-group"><label>任务名称 *</label><input class="form-input" id="taskName" value="${escapeHtml(task?.name||'')}"></div>
            <div class="form-group"><label>描述</label><textarea class="form-textarea" id="taskDesc">${escapeHtml(task?.description||'')}</textarea></div>
            <div class="form-group"><label>阶段</label><select class="form-select" id="taskStage">${Object.entries(STAGE_LABELS).map(([k,v])=>`<option value="${k}" ${task?.stage===k?'selected':''}>${v}</option>`).join('')}</select></div>
            <div class="form-group"><label>阶段编号</label><input type="number" class="form-input" id="taskStageNum" value="${task?.stage_number||1}" min="1"></div>
            <div class="form-group"><label>受益人</label><div class="tag-chip-group" id="tagGroup_people">${pOpts}</div></div>
            <div class="form-group"><label>标签</label><div class="tag-chip-group" id="tagGroup_tags">${tOpts}</div></div>`,
        footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="saveTask">${task?'保存':'创建'}</button>`,
    });
    document.getElementById('saveTask').addEventListener('click', async () => {
        const name = document.getElementById('taskName').value.trim();
        if (!name) { Toast.error('请输入任务名称'); return; }
        const data = { name, description: document.getElementById('taskDesc').value.trim(), stage: document.getElementById('taskStage').value, stage_number: parseInt(document.getElementById('taskStageNum').value)||1, people_ids: getSelectedTagIds('people'), tag_ids: getSelectedTagIds('tags') };
        try { if(taskId){await API.tasks.update(taskId,data)}else{await API.tasks.create(data)} Modal.close(); loadTasks(); Toast.success(taskId?'已更新':'已创建'); } catch(e){Toast.error('失败:'+e.message)}
    });
}
function editTask(id) { showTaskModal(id); }
async function archiveTask(id) { if(!confirm('确定归档？'))return; try{await API.tasks.update(id,{archived:1});loadTasks();Toast.success('已归档')}catch(e){Toast.error('失败')} }
async function restoreTask(id) { try{await API.tasks.update(id,{archived:0});loadTasks();Toast.success('已恢复')}catch(e){Toast.error('失败')} }
async function deleteTask(id) { if(!confirm('确定永久删除？'))return; try{await API.tasks.remove(id);loadTasks();Toast.success('已删除')}catch(e){Toast.error('失败')} }
