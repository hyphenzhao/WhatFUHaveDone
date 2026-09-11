/**
 * Users Management Page (admin only)
 */
let usersData = [];

document.addEventListener('DOMContentLoaded', loadUsers);

async function loadUsers() {
    try {
        const res = await API.users.list();
        usersData = res.data || [];
        renderUsersTable();
    } catch (e) { Toast.error('加载失败: ' + e.message); }
}

function renderUsersTable() {
    const tbody = document.getElementById('usersTableBody');
    if (usersData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--color-text-secondary);">暂无数据</td></tr>`;
        return;
    }
    const selfId = window.CURRENT_USER ? window.CURRENT_USER.id : 0;
    tbody.innerHTML = usersData.map(u => `
        <tr>
            <td><strong>${escapeHtml(u.username)}</strong>${u.id === selfId ? ' <span style="color:var(--color-text-secondary);font-size:0.8rem;">(我)</span>' : ''}</td>
            <td>${escapeHtml(u.display_name || '')}</td>
            <td>${u.role === 'admin' ? '<span style="color:var(--color-primary);font-weight:600;">管理员</span>' : '普通用户'}</td>
            <td>${escapeHtml((u.created_at || '').slice(0, 16))}</td>
            <td><div class="table-actions">
                <button class="btn btn-outline btn-sm" onclick="editUser(${u.id})">编辑</button>
                ${u.id !== selfId ? `<button class="btn btn-danger btn-sm" onclick="deleteUser(${u.id})">删除</button>` : ''}
            </div></td>
        </tr>`).join('');
}

function showUserModal(userId = null) {
    const user = userId ? usersData.find(u => u.id === userId) : null;
    const isSelf = user && window.CURRENT_USER && user.id === window.CURRENT_USER.id;
    Modal.open({
        title: user ? '编辑用户' : '新建用户',
        body: `
            <div class="form-group"><label>用户名 *</label><input class="form-input" id="userUsername" value="${escapeHtml(user?.username || '')}" ${user ? 'disabled' : ''} placeholder="2-64 位字母、数字、_ . -"></div>
            <div class="form-group"><label>显示名</label><input class="form-input" id="userDisplayName" value="${escapeHtml(user?.display_name || '')}" placeholder="如：赵海丰"></div>
            <div class="form-group"><label>密码 ${user ? '（留空则不修改）' : '*'}</label><input type="password" class="form-input" id="userPassword" autocomplete="new-password" placeholder="至少 6 位"></div>
            <div class="form-group"><label>角色</label>
                <select class="form-input" id="userRole" ${isSelf ? 'disabled' : ''}>
                    <option value="user" ${user?.role === 'user' ? 'selected' : ''}>普通用户</option>
                    <option value="admin" ${user?.role === 'admin' ? 'selected' : ''}>管理员</option>
                </select>
                ${isSelf ? '<div style="font-size:0.8rem;color:var(--color-text-secondary);margin-top:4px;">不能修改自己的角色</div>' : ''}
            </div>`,
        footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="saveUser">${user ? '保存' : '创建'}</button>`,
    });
    document.getElementById('saveUser').addEventListener('click', async () => {
        const password = document.getElementById('userPassword').value;
        const display_name = document.getElementById('userDisplayName').value.trim();
        const role = document.getElementById('userRole').value;
        try {
            if (userId) {
                const payload = { display_name };
                if (!isSelf) payload.role = role;
                if (password) payload.password = password;
                await API.users.update(userId, payload);
            } else {
                const username = document.getElementById('userUsername').value.trim();
                if (!username) { Toast.error('请输入用户名'); return; }
                if (!password) { Toast.error('请输入密码'); return; }
                await API.users.create({ username, display_name, password, role });
            }
            Modal.close();
            loadUsers();
            Toast.success(userId ? '已更新' : '已创建');
        } catch (e) { Toast.error('失败: ' + e.message); }
    });
}

function editUser(id) { showUserModal(id); }

async function deleteUser(id) {
    const user = usersData.find(u => u.id === id);
    if (!confirm(`确定删除用户「${user ? user.username : id}」？\n\n⚠️ 该用户的全部数据（人物/任务/成果/报告等）将被永久删除，不可恢复！`)) return;
    try {
        await API.users.remove(id);
        loadUsers();
        Toast.success('已删除');
    } catch (e) { Toast.error('失败: ' + e.message); }
}
