/**
 * Results Management Page
 */
let resultsData = { active: [], archived: [] };

document.addEventListener('DOMContentLoaded', async () => {
    await loadResults();
    makeSortable('resultsTable', (col, asc) => renderResultsTable(sortData(resultsData.active, col, asc), 'resultsTableBody', false));
});

async function loadResults() {
    try {
        const [active, archived] = await Promise.all([API.results.list(0), API.results.list(1)]);
        resultsData = { active: active.data || [], archived: archived.data || [] };
        renderResultsTable(resultsData.active, 'resultsTableBody', false);
        renderResultsTable(resultsData.archived, 'archivedResultsBody', true);
        document.getElementById('archivedResultsSection').style.display = resultsData.archived.length ? 'block' : 'none';
    } catch (e) { Toast.error('加载失败: ' + e.message); }
}

function renderResultsTable(results, tbodyId, isArchived) {
    const tbody = document.getElementById(tbodyId);
    if (results.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${isArchived ? 4 : 5}" style="text-align:center;color:var(--color-text-secondary);">暂无数据</td></tr>`;
        return;
    }
    tbody.innerHTML = results.map(r => {
        const tagSpans = (r.tags || []).map(t => `<span class="task-card-tag" style="background:${t.color}">${escapeHtml(t.name)}</span>`).join(' ') || '-';
        if (isArchived) return `<tr><td><strong>${escapeHtml(r.name)}</strong></td><td>${r.quantity||1}</td><td>${escapeHtml(r.level||'-')}</td><td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="restoreResult(${r.id})">恢复</button><button class="btn btn-danger btn-sm" onclick="deleteResult(${r.id})">删除</button></div></td></tr>`;
        return `<tr><td><strong>${escapeHtml(r.name)}</strong></td><td>${tagSpans}</td><td>${r.quantity||1}</td><td>${escapeHtml(r.level||'-')}</td><td><div class="table-actions"><button class="btn btn-outline btn-sm" onclick="editResult(${r.id})">编辑</button><button class="btn btn-ghost btn-sm" onclick="archiveResult(${r.id})">归档</button></div></td></tr>`;
    }).join('');
}

async function showResultModal(resultId = null) {
    let result = null, tagsList = [];
    try { const [r, tg] = await Promise.all([resultId ? API.results.get(resultId) : null, API.tags.list()]); if (r) result = r.data; tagsList = tg.data || []; } catch (e) {}
    const tOpts = tagsList.map(t => `<option value="${t.id}" ${(result?.tags||[]).some(rt=>rt.id===t.id)?'selected':''}>${escapeHtml(t.name)}</option>`).join('');
    Modal.open({
        title: result ? '编辑成果' : '新建成果',
        body: `<div class="form-group"><label>成果名称 *</label><input class="form-input" id="resultName" value="${escapeHtml(result?.name||'')}"></div>
            <div class="form-group"><label>标签 (Ctrl+Click 多选)</label><select class="form-select" id="resultTags" multiple size="3">${tOpts}</select></div>
            <div class="form-group"><label>数量</label><input type="number" class="form-input" id="resultQty" value="${result?.quantity||1}" min="1"></div>
            <div class="form-group"><label>等级</label><input class="form-input" id="resultLevel" value="${escapeHtml(result?.level||'')}"></div>`,
        footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="saveResult">${result?'保存':'创建'}</button>`,
    });
    document.getElementById('saveResult').addEventListener('click', async () => {
        const name = document.getElementById('resultName').value.trim();
        if (!name) { Toast.error('请输入成果名称'); return; }
        const data = { name, tag_ids: Array.from(document.getElementById('resultTags').selectedOptions).map(o=>parseInt(o.value)), quantity: parseInt(document.getElementById('resultQty').value)||1, level: document.getElementById('resultLevel').value.trim() };
        try { if(resultId){await API.results.update(resultId,data)}else{await API.results.create(data)} Modal.close(); loadResults(); Toast.success(resultId?'已更新':'已创建'); } catch(e){Toast.error('失败:'+e.message)}
    });
}
function editResult(id) { showResultModal(id); }
async function archiveResult(id) { if(!confirm('确定归档？'))return; try{await API.results.update(id,{archived:1});loadResults();Toast.success('已归档')}catch(e){Toast.error('失败')} }
async function restoreResult(id) { try{await API.results.update(id,{archived:0});loadResults();Toast.success('已恢复')}catch(e){Toast.error('失败')} }
async function deleteResult(id) { if(!confirm('确定永久删除？'))return; try{await API.results.remove(id);loadResults();Toast.success('已删除')}catch(e){Toast.error('失败')} }
