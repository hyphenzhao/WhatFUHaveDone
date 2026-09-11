/**
 * Reusable attachment widget — list / upload / download / delete files for an entity.
 * Depends on globals: API, Modal, Toast, escapeHtml.
 *
 * Usage:  Attach.openModal('result', 12, '成果名称')
 *         Attach.openModal('worklog_note', 34, '备注')
 * Optional: set Attach.onChange = (type, id) => {...} to react after upload/delete.
 */
const Attach = {
    onChange: null,

    _fmtSize(b) {
        b = +b || 0;
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(1) + ' MB';
    },

    _icon(mime, name) {
        const ext = String(name || '').split('.').pop().toLowerCase();
        if (/^(png|jpg|jpeg|gif|webp|bmp)$/.test(ext)) return '🖼️';
        if (ext === 'pdf') return '📕';
        if (/^(doc|docx)$/.test(ext)) return '📘';
        if (/^(xls|xlsx|csv)$/.test(ext)) return '📗';
        if (/^(txt|md|json|log)$/.test(ext)) return '📄';
        return '📎';
    },

    async openModal(entityType, entityId, label) {
        Modal.open({
            title: '📎 附件' + (label ? ' — ' + label : ''),
            body: '<div id="attachBody" style="min-height:80px;">加载中...</div>',
            footer: `<label class="btn btn-primary" style="cursor:pointer;">⬆️ 上传文件
                    <input type="file" id="attachFileInput" style="display:none;" onchange="Attach._onPick('${entityType}',${entityId})">
                </label>
                <button class="btn btn-ghost" onclick="Modal.close()">关闭</button>`,
        });
        await this._renderList(entityType, entityId);
    },

    async _renderList(entityType, entityId) {
        const body = document.getElementById('attachBody');
        if (!body) return;
        try {
            const list = (await API.attachments.list(entityType, entityId)).data || [];
            if (!list.length) {
                body.innerHTML = '<div style="color:var(--color-text-secondary);text-align:center;padding:16px;">暂无附件，点击下方「上传文件」</div>';
                return;
            }
            body.innerHTML = list.map(a => `
                <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--color-border);">
                    <span style="font-size:1.1rem;">${this._icon(a.mime, a.file_name)}</span>
                    <a href="${API.attachments.inlineUrl(a.id)}" target="_blank" rel="noopener"
                       style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                       title="${escapeHtml(a.file_name)}">${escapeHtml(a.file_name)}</a>
                    <span style="font-size:0.7rem;color:var(--color-text-secondary);white-space:nowrap;">${this._fmtSize(a.size)}${(+a.has_text) ? ' · 📝' : ''}</span>
                    <a class="btn btn-ghost btn-sm" href="${API.attachments.downloadUrl(a.id)}" title="下载">⬇️</a>
                    <button class="btn btn-danger btn-sm" onclick="Attach._del(${a.id},'${entityType}',${entityId})" title="删除">删除</button>
                </div>`).join('');
        } catch (e) {
            body.innerHTML = '<div style="color:var(--color-danger);">加载失败: ' + escapeHtml(e.message) + '</div>';
        }
    },

    async _onPick(entityType, entityId) {
        const input = document.getElementById('attachFileInput');
        if (!input || !input.files || !input.files[0]) return;
        const file = input.files[0];
        const body = document.getElementById('attachBody');
        if (body) body.innerHTML = '<div style="text-align:center;padding:16px;">⏳ 上传中: ' + escapeHtml(file.name) + '</div>';
        try {
            await API.attachments.upload(entityType, entityId, file);
            input.value = '';
            Toast.success('已上传');
            await this._renderList(entityType, entityId);
            if (typeof this.onChange === 'function') this.onChange(entityType, entityId);
        } catch (e) {
            Toast.error('上传失败: ' + e.message);
            await this._renderList(entityType, entityId);
        }
    },

    async _del(id, entityType, entityId) {
        if (!confirm('确定删除该附件？')) return;
        try {
            await API.attachments.remove(id);
            await this._renderList(entityType, entityId);
            if (typeof this.onChange === 'function') this.onChange(entityType, entityId);
        } catch (e) {
            Toast.error('删除失败');
        }
    },
};
