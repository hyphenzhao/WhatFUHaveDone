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
        add(taskId, plannedDate) { return API.post('/plans', { task_id: taskId, planned_date: plannedDate }); },
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
        workload() { return API.get('/stats?type=workload'); },
        results() { return API.get('/stats?type=results'); },
        calendar(month) { return API.get(`/stats?type=calendar&month=${month}`); },
        daily(date) { return API.get(`/stats?type=daily&date=${date}`); },
        workloadDetail(tagId) { return API.get(`/stats?type=workload_detail&tag_id=${tagId}`); },
        resultsDetail(tagId) { return API.get(`/stats?type=results_detail&tag_id=${tagId}`); },
    },

    // --- Worklog Notes ---
    worklogNotes: {
        list(worklogId) { return API.get(`/worklog_notes?worklog_id=${worklogId}`); },
        listAll() { return API.get('/worklog_notes?latest_all=1'); },
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
};
