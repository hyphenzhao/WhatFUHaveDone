/**
 * Tags Management Page
 */
let tagsData = { active: [], archived: [] };

document.addEventListener('DOMContentLoaded', async () => {
    await loadTags();
    makeSortable('tagsTable', (col, asc) => renderTagsTable(sortData(tagsData.active, col, asc), 'tagsTableBody', false));
});

async function loadTags() {
    try {
        const [active, archived] = await Promise.all([API.tags.list(0), API.tags.list(1)]);
        tagsData = { active: active.data || [], archived: archived.data || [] };
        renderTagsTable(tagsData.active, 'tagsTableBody', false);
        renderTagsTable(tagsData.archived, 'archivedTagsBody', true);
        document.getElementById('archivedTagsSection').style.display = tagsData.archived.length ? 'block' : 'none';
    } catch (e) { Toast.error('加载失败: ' + e.message); }
}

function renderTagsTable(tags, tbodyId, isArchived) {
    const tbody = document.getElementById(tbodyId);
    if (tags.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:var(--color-text-secondary);">暂无数据</td></tr>`;
        return;
    }
    tbody.innerHTML = tags.map(t => `
        <tr>
            <td><span class="color-swatch" style="background:${escapeHtml(t.color)}"></span></td>
            <td><strong>${escapeHtml(t.name)}</strong></td>
            <td><div class="table-actions">
                ${!isArchived ? `<button class="btn btn-outline btn-sm" onclick="editTag(${t.id})">编辑</button><button class="btn btn-ghost btn-sm" onclick="archiveTag(${t.id})">归档</button>` : `<button class="btn btn-outline btn-sm" onclick="restoreTag(${t.id})">恢复</button><button class="btn btn-danger btn-sm" onclick="deleteTag(${t.id})">删除</button>`}
            </div></td>
        </tr>`).join('');
}

async function showTagModal(tagId = null) {
    let tag = null;
    if (tagId) { try { const r = await API.tags.get(tagId); tag = r.data; } catch (e) {} }
    Modal.open({
        title: tag ? '编辑标签' : '新建标签',
        body: `<div class="form-group"><label>标签名称 *</label><input class="form-input" id="tagName" value="${escapeHtml(tag?.name||'')}"></div>
            <div class="form-group"><label>颜色</label><input type="color" class="form-input" id="tagColor" value="${tag?.color||'#3B82F6'}" style="height:40px;padding:4px;"></div>`,
        footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="saveTag">${tag?'保存':'创建'}</button>`,
    });
    document.getElementById('saveTag').addEventListener('click', async () => {
        const name = document.getElementById('tagName').value.trim();
        if (!name) { Toast.error('请输入标签名称'); return; }
        try { if(tagId){await API.tags.update(tagId,{name,color:document.getElementById('tagColor').value})}else{await API.tags.create({name,color:document.getElementById('tagColor').value})} Modal.close(); loadTags(); Toast.success(tagId?'已更新':'已创建'); } catch(e){Toast.error('失败:'+e.message)}
    });
}
function editTag(id) { showTagModal(id); }
async function archiveTag(id) { if(!confirm('确定归档？'))return; try{await API.tags.update(id,{archived:1});loadTags();Toast.success('已归档')}catch(e){Toast.error('失败')} }
async function restoreTag(id) { try{await API.tags.update(id,{archived:0});loadTags();Toast.success('已恢复')}catch(e){Toast.error('失败')} }
async function deleteTag(id) { if(!confirm('确定永久删除？'))return; try{await API.tags.remove(id);loadTags();Toast.success('已删除')}catch(e){Toast.error('失败')} }
