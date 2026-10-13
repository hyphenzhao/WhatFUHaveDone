/** Reconcile meeting status while a page is open; the server cron also runs offline. */
const MeetingUI = {
    busy: false,
    revision: null,
    async tick() {
        if (this.busy || document.hidden) return;
        this.busy = true;
        try {
            const data = (await API.post('/plans/complete-due', {})).data;
            const changed = data.completed > 0 || (this.revision !== null && data.revision !== this.revision);
            this.revision = data.revision;
            if (!changed) return;
            if (typeof IM !== 'undefined' && document.getElementById('imApp')) {
                ['tasks:', 'stats:', 'worklogs:', 'notes:'].forEach(p => DayCache.clear(p));
                await IM.loadTasks();
            } else if (document.body.dataset.page === 'home' && typeof refreshAll === 'function') {
                await refreshAll();
            } else if (document.body.dataset.page === 'tasks' && typeof loadTasks === 'function') {
                await loadTasks();
            }
        } catch (e) { console.error('Meeting status refresh failed:', e); }
        finally { this.busy = false; }
    }
};
document.addEventListener('DOMContentLoaded', () => {
    MeetingUI.tick();
    setInterval(() => MeetingUI.tick(), 60000);
});
document.addEventListener('visibilitychange', () => { if (!document.hidden) MeetingUI.tick(); });
