/**
 * People Management Page
 */
let peopleData = { active: [], archived: [] };

document.addEventListener('DOMContentLoaded', async () => {
    await loadPeople();
    makeSortable('peopleTable', (col, asc) => renderPeopleTable(sortData(peopleData.active, col, asc), 'peopleTableBody', false));
});

async function loadPeople() {
    try {
        const [active, archived] = await Promise.all([
            API.people.list(0),
            API.people.list(1),
        ]);
        peopleData = { active: active.data || [], archived: archived.data || [] };
        renderPeopleTable(peopleData.active, 'peopleTableBody', false);
        renderPeopleTable(peopleData.archived, 'archivedPeopleBody', true);
        document.getElementById('archivedPeopleSection').style.display = peopleData.archived.length ? 'block' : 'none';
    } catch (e) {
        Toast.error('加载失败: ' + e.message);
    }
}

function renderPeopleTable(people, tbodyId, isArchived) {
    const tbody = document.getElementById(tbodyId);
    if (people.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--color-text-secondary);">暂无数据</td></tr>`;
        return;
    }
    tbody.innerHTML = people.map(p => {
        const isMe = p.is_me == 1;
        return `
        <tr${isMe ? ' style="background:rgba(59,130,246,0.04);"' : ''}>
            <td><strong>${escapeHtml(p.name)}</strong>${isMe ? ' <span style="background:var(--color-primary);color:#fff;padding:1px 6px;border-radius:10px;font-size:0.65rem;">我</span>' : ''}</td>
            <td>${escapeHtml(p.relationship || '-')}</td>
            <td><span class="star-display">${renderStars(p.importance)}</span></td>
            <td><span class="star-display">${renderStars(p.usefulness)}</span></td>
            <td><span class="star-display">${renderStars(p.closeness || 0)}</span></td>
            <td>
                <div class="table-actions">
                    ${!isArchived ? `
                        <button class="btn btn-outline btn-sm" onclick="editPerson(${p.id})">编辑</button>
                        ${!isMe ? `<button class="btn btn-ghost btn-sm" onclick="archivePerson(${p.id})">归档</button>` : ''}
                    ` : `
                        <button class="btn btn-outline btn-sm" onclick="restorePerson(${p.id})">恢复</button>
                        <button class="btn btn-danger btn-sm" onclick="deletePerson(${p.id})">删除</button>
                    `}
                </div>
            </td>
        </tr>`;
    }).join('');
}

async function showPersonModal(personId = null) {
    let person = null;
    if (personId) {
        try { const r = await API.people.get(personId); person = r.data; } catch (e) {}
    }

    Modal.open({
        title: person ? '编辑人物' : '新建人物',
        body: `
            <div class="form-group">
                <label>姓名 *</label>
                <input type="text" class="form-input" id="personName" value="${escapeHtml(person?.name || '')}">
            </div>
            <div class="form-group">
                <label>关系</label>
                <input type="text" class="form-input" id="personRel" value="${escapeHtml(person?.relationship || '')}">
            </div>
            <div class="form-group">
                <label>重要度</label>
                <div class="star-rating" id="importanceStars">
                    ${[1,2,3,4,5].map(i => `<span class="star ${i <= (person?.importance || 0) ? 'filled' : ''}" data-val="${i}">★</span>`).join('')}
                </div>
                <input type="hidden" id="importanceVal" value="${person?.importance || 0}">
            </div>
            <div class="form-group">
                <label>有用度</label>
                <div class="star-rating" id="usefulnessStars">
                    ${[1,2,3,4,5].map(i => `<span class="star ${i <= (person?.usefulness || 0) ? 'filled' : ''}" data-val="${i}">★</span>`).join('')}
                </div>
                <input type="hidden" id="usefulnessVal" value="${person?.usefulness || 0}">
            </div>
            <div class="form-group">
                <label>亲密度</label>
                <div class="star-rating" id="closenessStars">
                    ${[1,2,3,4,5].map(i => `<span class="star ${i <= (person?.closeness || 0) ? 'filled' : ''}" data-val="${i}">★</span>`).join('')}
                </div>
                <input type="hidden" id="closenessVal" value="${person?.closeness || 0}">
            </div>
        `,
        footer: `
            <button class="btn btn-ghost" onclick="Modal.close()">取消</button>
            <button class="btn btn-primary" id="savePerson">${person ? '保存修改' : '创建'}</button>
        `,
    });

    ['importanceStars', 'usefulnessStars', 'closenessStars'].forEach(id => {
        document.getElementById(id).querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', () => {
                const val = parseInt(star.dataset.val);
                document.getElementById(id.replace('Stars', 'Val')).value = val;
                document.getElementById(id).querySelectorAll('.star').forEach((s, i) => {
                    s.classList.toggle('filled', i < val);
                });
            });
        });
    });

    document.getElementById('savePerson').addEventListener('click', async () => {
        const name = document.getElementById('personName').value.trim();
        if (!name) { Toast.error('请输入姓名'); return; }
        const data = {
            name,
            relationship: document.getElementById('personRel').value.trim(),
            importance: parseInt(document.getElementById('importanceVal').value) || 0,
            usefulness: parseInt(document.getElementById('usefulnessVal').value) || 0,
            closeness: parseInt(document.getElementById('closenessVal').value) || 0,
        };
        try {
            if (personId) { await API.people.update(personId, data); }
            else { await API.people.create(data); }
            Modal.close(); loadPeople(); Toast.success(personId ? '已更新' : '已创建');
        } catch (e) { Toast.error('操作失败: ' + e.message); }
    });
}

function editPerson(id) { showPersonModal(id); }

async function archivePerson(id) {
    if (!confirm('确定归档此人物？')) return;
    try { await API.people.update(id, { archived: 1 }); loadPeople(); Toast.success('已归档'); } catch (e) { Toast.error('失败'); }
}
async function restorePerson(id) {
    try { await API.people.update(id, { archived: 0 }); loadPeople(); Toast.success('已恢复'); } catch (e) { Toast.error('失败'); }
}
async function deletePerson(id) {
    if (!confirm('确定永久删除？')) return;
    try { await API.people.remove(id); loadPeople(); Toast.success('已删除'); } catch (e) { Toast.error('失败'); }
}
