/**
 * Calendar date picker component
 */
const Calendar = {
    currentYear: new Date().getFullYear(),
    currentMonth: new Date().getMonth() + 1, // 1-12
    calendarData: {}, // date -> [events]
    _eventsBound: false,

    async init() {
        await this.loadMonth();
        this.render();
        if (!this._eventsBound) {
            this.bindEvents();
            this._eventsBound = true;
        }
    },

    async loadMonth() {
        const monthStr = `${this.currentYear}-${String(this.currentMonth).padStart(2, '0')}`;
        try {
            const res = await API.stats.calendar(monthStr);
            this.calendarData = res.data?.days || {};
            App.currentMonth = monthStr;
        } catch (e) {
            this.calendarData = {};
            console.error('Failed to load calendar:', e);
        }
    },

    render() {
        const container = document.getElementById('calendarContainer');
        if (!container) return;

        const year = this.currentYear;
        const month = this.currentMonth;
        const firstDay = new Date(year, month - 1, 1);
        const lastDay = new Date(year, month, 0);
        const daysInMonth = lastDay.getDate();
        const startDow = firstDay.getDay(); // 0=Sun
        const todayStr = today();

        // Month label
        document.getElementById('calMonthLabel').textContent =
            `${year}年 ${month}月`;

        // Day headers
        const dayHeaders = ['日', '一', '二', '三', '四', '五', '六'];
        let html = dayHeaders.map(d => `<div class="calendar-day-header">${d}</div>`).join('');

        // Previous month fill
        const prevLastDay = new Date(year, month - 1, 0).getDate();
        for (let i = startDow - 1; i >= 0; i--) {
            const d = prevLastDay - i;
            const dateStr = `${year}-${String(month - 1 || 12).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            html += this._renderDay(d, dateStr, true);
        }

        // Current month
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            html += this._renderDay(d, dateStr, false);
        }

        // Next month fill
        const remaining = 42 - (startDow + daysInMonth); // 6 rows × 7 cols
        for (let d = 1; d <= remaining; d++) {
            const nextMonth = month === 12 ? 1 : month + 1;
            const nextYear = month === 12 ? year + 1 : year;
            const dateStr = `${nextYear}-${String(nextMonth).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            html += this._renderDay(d, dateStr, true);
        }

        document.getElementById('calendarGrid').innerHTML = html;

        // Bind click events
        document.querySelectorAll('.calendar-day.current-month').forEach(el => {
            el.addEventListener('click', () => {
                const dateStr = el.dataset.date;
                document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected'));
                el.classList.add('selected');
                App.setDate(dateStr);
                if (typeof loadDailyStatus === 'function') loadDailyStatus(dateStr);
                if (typeof loadRightPanel === 'function') loadRightPanel();
            });
        });

        // Highlight today
        const todayEl = document.querySelector(`.calendar-day[data-date="${todayStr}"]`);
        if (todayEl) todayEl.classList.add('today');

        // Highlight selected date
        const selectedEl = document.querySelector(`.calendar-day[data-date="${App.selectedDate}"]`);
        if (selectedEl) selectedEl.classList.add('selected');
    },

    _renderDay(dayNum, dateStr, isOtherMonth) {
        const otherClass = isOtherMonth ? ' other-month' : ' current-month';
        const events = this.calendarData[dateStr] || [];

        // Deduplicate by task id
        const seen = new Set();
        const uniqueEvents = events.filter(e => {
            const key = `${e.id}-${e.event_type}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });

        let dotsHtml = '';
        uniqueEvents.forEach(e => {
            const color = e.tag?.color || '#94a3b8';
            const title = `${escapeHtml(e.name)} (${e.event_type === 'work' ? '工作量' : e.event_type === 'result' ? '成果' : '计划'})`;
            dotsHtml += `<span class="calendar-dot" style="background:${color}" title="${title}" data-task-id="${e.id}"></span>`;
        });

        // Check if there are work logs for this date
        const hasWork = uniqueEvents.some(e => e.event_type === 'work');
        const hasResult = uniqueEvents.some(e => e.event_type === 'result');
        const hasPlan = uniqueEvents.some(e => e.event_type === 'plan');
        let bgStyle = '';
        if (hasWork && hasResult) bgStyle = 'background:linear-gradient(135deg, rgba(59,130,246,0.08), rgba(245,158,11,0.08));';

        return `
            <div class="calendar-day${otherClass}" data-date="${dateStr}" style="${bgStyle}">
                <div class="day-number">${dayNum}</div>
                <div class="calendar-dots">${dotsHtml}</div>
            </div>
        `;
    },

    bindEvents() {
        document.getElementById('calPrev').addEventListener('click', async () => {
            this.currentMonth--;
            if (this.currentMonth < 1) {
                this.currentMonth = 12;
                this.currentYear--;
            }
            await this.loadMonth();
            this.render();
        });

        document.getElementById('calNext').addEventListener('click', async () => {
            this.currentMonth++;
            if (this.currentMonth > 12) {
                this.currentMonth = 1;
                this.currentYear++;
            }
            await this.loadMonth();
            this.render();
        });

        document.getElementById('calToday').addEventListener('click', async () => {
            this.currentYear = new Date().getFullYear();
            this.currentMonth = new Date().getMonth() + 1;
            App.setDate(today());
            await this.loadMonth();
            this.render();
            if (typeof loadDailyStatus === 'function') loadDailyStatus(today());
            if (typeof loadRightPanel === 'function') loadRightPanel();
        });
    },
};
