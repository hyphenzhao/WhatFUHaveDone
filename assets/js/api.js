/**
 * API client — fetch wrapper for all backend calls
 */
const API = {
    base: '/api',

    async request(method, path, body = null) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json' },
        };
        if (body !== null) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(this.base + path, opts);
        if (res.status === 401) {
            window.location.href = '/login';
            throw new Error('未登录');
        }
        const data = await res.json();
        if (!res.ok || data.error) {
            throw new Error(data.message || 'Request failed');
        }
        return data;
    },

    get(path) { return this.request('GET', path); },
    post(path, body) { return this.request('POST', path, body); },
    put(path, body) { return this.request('PUT', path, body); },
    delete(path, body) { return this.request('DELETE', path, body); },

    // multipart/form-data upload (does NOT set JSON Content-Type)
    async upload(path, formData) {
        const res = await fetch(this.base + path, { method: 'POST', body: formData });
        if (res.status === 401) {
            window.location.href = '/login';
            throw new Error('未登录');
        }
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.message || 'Upload failed');
        return data;
    },

    // --- Auth ---
    auth: {
        login(data) { return API.post('/auth/login', data); },
        logout() { return API.post('/auth/logout'); },
        me() { return API.get('/auth/me'); },
    },

    // --- Users (admin) ---
    users: {
        list() { return API.get('/users'); },
        create(data) { return API.post('/users', data); },
        update(id, data) { return API.put(`/users/${id}`, data); },
        remove(id) { return API.delete(`/users/${id}`); },
    },

    // --- People ---
    people: {
        list(archived = 0) { return API.get(`/people?archived=${archived}`); },
        get(id) { return API.get(`/people/${id}`); },
        create(data) { return API.post('/people', data); },
        update(id, data) { return API.put(`/people/${id}`, data); },
        remove(id) { return API.delete(`/people/${id}`); },
    },

    // --- Tags ---
    tags: {
        list(archived = 0) { return API.get(`/tags?archived=${archived}`); },
        get(id) { return API.get(`/tags/${id}`); },
        create(data) { return API.post('/tags', data); },
        update(id, data) { return API.put(`/tags/${id}`, data); },
        remove(id) { return API.delete(`/tags/${id}`); },
    },

    // --- Results ---
    results: {
        list(archived = 0) { return API.get(`/results?archived=${archived}`); },
        get(id) { return API.get(`/results/${id}`); },
        create(data) { return API.post('/results', data); },
        update(id, data) { return API.put(`/results/${id}`, data); },
        remove(id) { return API.delete(`/results/${id}`); },
    },

    // --- Tasks ---
    tasks: {
        list(archived = 0, stage = null, sort = 'priority') {
            let q = `?archived=${archived}&sort=${sort}`;
            if (stage) q += `&stage=${stage}`;
            return API.get(`/tasks${q}`);
        },
        reorder(ids) { return API.put('/tasks/reorder', { ids }); },
        get(id) { return API.get(`/tasks/${id}`); },
        create(data) { return API.post('/tasks', data); },
        update(id, data) { return API.put(`/tasks/${id}`, data); },
        remove(id) { return API.delete(`/tasks/${id}`); },
    },

    // --- Work Logs ---
    worklogs: {
        toggle(taskId, date) { return API.post('/worklogs', { task_id: taskId, date }); },
        forDate(date) { return API.get(`/worklogs?date=${date}`); },
        forTask(taskId) { return API.get(`/worklogs?task_id=${taskId}`); },
    },

    // --- Plans ---
    plans: {
        add(taskId, plannedDate, planTime, planEndTime) { return API.post('/plans', { task_id: taskId, planned_date: plannedDate, plan_time: planTime || '', plan_end_time: planEndTime || '' }); },
        remove(id) { return API.delete(`/plans/${id}`); },
        forTask(taskId) { return API.get(`/plans?task_id=${taskId}`); },
        forDate(date) { return API.get(`/plans?date=${date}`); },
    },

    // --- Result Logs ---
    resultLogs: {
        add(taskId, resultId, date) { return API.post('/result_logs', { task_id: taskId, result_id: resultId, date }); },
        remove(id) { return API.delete(`/result_logs/${id}`); },
        forDate(date) { return API.get(`/result_logs?date=${date}`); },
        forTask(taskId) { return API.get(`/result_logs?task_id=${taskId}`); },
    },

    // --- Stats ---
    stats: {
        workload(period, refDate, bounded) { return API.get(`/stats?type=workload${period && period !== 'all' ? '&period=' + period + '&ref_date=' + (refDate || today()) + (bounded ? '&bounded=1' : '') : ''}`); },
        results(period, refDate, bounded) { return API.get(`/stats?type=results${period && period !== 'all' ? '&period=' + period + '&ref_date=' + (refDate || today()) + (bounded ? '&bounded=1' : '') : ''}`); },
        calendar(month) { return API.get(`/stats?type=calendar&month=${month}`); },
        daily(date) { return API.get(`/stats?type=daily&date=${date}`); },
        workloadDetail(tagId, period, refDate) { return API.get(`/stats?type=workload_detail&tag_id=${tagId}${period && period !== 'all' ? '&period=' + period + '&ref_date=' + (refDate || today()) : ''}`); },
        resultsDetail(tagId, period, refDate) { return API.get(`/stats?type=results_detail&tag_id=${tagId}${period && period !== 'all' ? '&period=' + period + '&ref_date=' + (refDate || today()) : ''}`); },
    },

    // --- Worklog Notes ---
    worklogNotes: {
        list(worklogId) { return API.get(`/worklog_notes?worklog_id=${worklogId}`); },
        listAll() { return API.get('/worklog_notes?latest_all=1'); },
        forTask(taskId) { return API.get(`/worklog_notes?task_id=${taskId}`); },
        add(worklogId, content) { return API.post('/worklog_notes', { worklog_id: worklogId, content }); },
        remove(id) { return API.delete(`/worklog_notes/${id}`); },
    },

    // --- Weather ---
    weather: {
        get(date, city, lat, lon) {
            let q = `?date=${date}`;
            if (city) q += `&city=${encodeURIComponent(city)}`;
            if (lat) q += `&lat=${lat}`;
            if (lon) q += `&lon=${lon}`;
            return API.get(`/weather${q}`);
        },
        fetch(date, city, lat, lon) {
            let q = `?action=fetch&date=${date}`;
            if (city) q += `&city=${encodeURIComponent(city)}`;
            if (lat) q += `&lat=${lat}`;
            if (lon) q += `&lon=${lon}`;
            return API.post(`/weather${q}`);
        },
    },

    // --- Calendar Meta ---
    calendarMeta: {
        month(month) { return API.get(`/calendar_meta?month=${month}`); },
        save(dates) { return API.post('/calendar_meta', { dates }); },
    },

    // --- Relationships ---
    relationships: {
        get() { return API.get('/relationships'); },
    },

    // --- Attachments ---
    attachments: {
        list(entityType, entityId) { return API.get(`/attachments?entity_type=${encodeURIComponent(entityType)}&entity_id=${entityId}`); },
        upload(entityType, entityId, file) {
            const fd = new FormData();
            fd.append('entity_type', entityType);
            fd.append('entity_id', entityId);
            fd.append('file', file);
            return API.upload('/attachments', fd);
        },
        remove(id) { return API.delete(`/attachments/${id}`); },
        downloadUrl(id) { return `/api/attachments/${id}/download`; },
        inlineUrl(id) { return `/api/attachments/${id}/download?inline=1`; },
    },

    // --- Reports ---
    reports: {
        list() { return API.get('/reports'); },
        get(id) { return API.get(`/reports/${id}`); },
        byPeriod(periodType, periodKey) { return API.get(`/reports?period_type=${encodeURIComponent(periodType)}&period_key=${encodeURIComponent(periodKey)}`); },
        generate(periodType, periodKey, extraNote) { return API.post('/reports', { period_type: periodType, period_key: periodKey, note: extraNote || '' }); },
        remove(id) { return API.delete(`/reports/${id}`); },
    },

    // --- AI conversations (shared active conversation) ---
    ai: {
        active() { return API.get('/ai/conversations/active'); },
        setActive(id) { return API.put('/ai/conversations/active', { id: id || 0 }); },
        config() { return API.get('/ai/config'); },
    },

    // --- Profile documents (authoritative identity docs) ---
    profileDocs: {
        list() { return API.get('/profile_docs'); },
        upload(file, kind, title) {
            const fd = new FormData();
            fd.append('file', file);
            if (kind) fd.append('kind', kind);
            if (title) fd.append('title', title);
            return API.upload('/profile_docs', fd);
        },
        text(id) { return API.get(`/profile_docs/${id}/text`); },
        update(id, data) { return API.put(`/profile_docs/${id}`, data); },
        reextract(id) { return API.post(`/profile_docs/${id}/reextract`, {}); },
        remove(id) { return API.delete(`/profile_docs/${id}`); },
        downloadUrl(id) { return `/api/profile_docs/${id}/download`; },
    },

    // --- AI impressions (secondary memory about the user) ---
    impressions: {
        list() { return API.get('/impressions'); },
        create(data) { return API.post('/impressions', data); },
        update(id, data) { return API.put(`/impressions/${id}`, data); },
        remove(id) { return API.delete(`/impressions/${id}`); },
        clear() { return API.delete('/impressions?all=1'); },
        snapshots() { return API.get('/impressions/snapshots'); },
        snapshot(id) { return API.get(`/impressions/snapshots/${id}`); },
        generate(kind) { return API.post('/impressions/snapshots', { kind }); },
        removeSnapshot(id) { return API.delete(`/impressions/snapshots/${id}`); },
    },

    // --- Mail ---
    mail: {
        status() { return API.get('/mail/status'); },
        accounts: {
            list() { return API.get('/mail/accounts'); },
            create(data) { return API.post('/mail/accounts', data); },
            update(id, data) { return API.put(`/mail/accounts/${id}`, data); },
            remove(id) { return API.delete(`/mail/accounts/${id}`); },
            test(data) { return API.post('/mail/accounts/test', data); },
        },
        sync(accountId, budgetSec) { return API.post('/mail/sync', { account_id: accountId || 0, budget_sec: budgetSec || 25 }); },
        folders(accountId) { return API.get('/mail/folders' + (accountId ? `?account_id=${accountId}` : '')); },
        messages(params) {
            const qs = Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '')
                .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
            return API.get('/mail/messages' + (qs ? `?${qs}` : ''));
        },
        message(id) { return API.get(`/mail/messages/${id}`); },
        update(id, data) { return API.put(`/mail/messages/${id}`, data); },
        remove(id) { return API.delete(`/mail/messages/${id}`); },
        replyTemplate(id, mode) { return API.get(`/mail/messages/${id}/reply-template?mode=${encodeURIComponent(mode || 'reply')}`); },
        highlights(id) { return API.get(`/mail/messages/${id}/highlights`); },
        addHighlight(id, snippet, note) { return API.post(`/mail/messages/${id}/highlights`, { snippet, note: note || '' }); },
        removeHighlight(id, hid) { return API.delete(`/mail/messages/${id}/highlights/${hid}`); },
        clearHighlights(id) { return API.delete(`/mail/messages/${id}/highlights`); },
        send(formData) { return API.upload('/mail/send', formData); },
        analyze(body) { return API.post('/mail/analyze', body); },
        daily(date) { return API.get(`/mail/daily?date=${encodeURIComponent(date)}`); },
    },
};
