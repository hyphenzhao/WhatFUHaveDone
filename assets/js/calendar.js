/**
 * Calendar date picker component
 */
const Calendar = {
    currentYear: new Date().getFullYear(),
    currentMonth: new Date().getMonth() + 1, // 1-12
    calendarData: {}, // date -> [events]
    calendarMeta: {}, // date -> { lunar_month, lunar_day, solar_term, holiday_name, is_holiday, is_workday }
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
            const [calRes, metaRes] = await Promise.all([
                API.stats.calendar(monthStr),
                API.calendarMeta.month(monthStr),
            ]);
            this.calendarData = calRes.data?.days || {};
            this.calendarMeta = metaRes.data?.days || {};
            App.currentMonth = monthStr;
        } catch (e) {
            this.calendarData = {};
            this.calendarMeta = {};
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
        let html = dayHeaders.map((hdr, i) => `<div class="calendar-day-header${i === 0 || i === 6 ? ' weekend-header' : ''}">${hdr}</div>`).join('');

        // Previous month fill
        const prevLastDay = new Date(year, month - 1, 0).getDate();
        for (let i = startDow - 1; i >= 0; i--) {
            const d = prevLastDay - i;
            const dow = startDow - 1 - i;
            const dateStr = `${year}-${String(month - 1 || 12).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            html += this._renderDay(d, dateStr, true, dow);
        }

        // Current month
        for (let d = 1; d <= daysInMonth; d++) {
            const dow = (startDow + d - 1) % 7;
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            html += this._renderDay(d, dateStr, false, dow);
        }

        // Next month fill
        const remaining = 42 - (startDow + daysInMonth); // 6 rows × 7 cols
        for (let d = 1; d <= remaining; d++) {
            const dow = (startDow + daysInMonth + d - 1) % 7;
            const nextMonth = month === 12 ? 1 : month + 1;
            const nextYear = month === 12 ? year + 1 : year;
            const dateStr = `${nextYear}-${String(nextMonth).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            html += this._renderDay(d, dateStr, true, dow);
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

    _renderDay(dayNum, dateStr, isOtherMonth, dow) {
        const otherClass = isOtherMonth ? ' other-month' : ' current-month';
        const weekendClass = (dow === 0 || dow === 6) ? ' weekend' : '';
        const sundayClass = (dow === 0) ? ' sunday' : '';
        const events = this.calendarData[dateStr] || [];
        const meta = this.calendarMeta[dateStr] || {};

        // Deduplicate by task id
        const seen = new Set();
        const uniqueEvents = events.filter(e => {
            const key = `${e.id}-${e.event_type}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });

        let dotsHtml = '';
        const planEvents = [];
        uniqueEvents.forEach(e => {
            const color = e.tag?.color || '#94a3b8';
            const title = `${escapeHtml(e.name)} (${e.event_type === 'work' ? '工作量' : e.event_type === 'result' ? '成果' : '计划'})`;
            if (e.event_type === 'plan') {
                planEvents.push(e);
            } else {
                dotsHtml += `<span class="calendar-dot" style="background:${color}" title="${title}" data-task-id="${e.id}"></span>`;
            }
        });

        // Plan text lines, sorted by time
        let planLinesHtml = '';
        if (planEvents.length > 0) {
            planEvents.sort((a, b) => {
                const ta = a.plan_time || '99:99', tb = b.plan_time || '99:99';
                return ta.localeCompare(tb);
            });
            planLinesHtml = '<div class="calendar-plans">' + planEvents.map(e => {
                const time = e.plan_time ? e.plan_time.substring(0,5) + (e.plan_end_time ? '-' + e.plan_end_time.substring(0,5) : '') : '';
                const label = time ? time + ' ' + escapeHtml(e.name) : escapeHtml(e.name);
                return '<div class="calendar-plan-line" title="' + escapeHtml(e.name) + '">' + label + '</div>';
            }).join('') + '</div>';
        }

        // Check if there are work logs for this date
        const hasWork = uniqueEvents.some(e => e.event_type === 'work');
        const hasResult = uniqueEvents.some(e => e.event_type === 'result');
        let bgStyle = '';
        if (hasWork && hasResult) bgStyle = 'background:linear-gradient(135deg, rgba(59,130,246,0.08), rgba(245,158,11,0.08));';

        // Lunar date
        let lunarHtml = '';
        if (meta.lunar_day) {
            const lunarText = meta.lunar_day === '初一' ? (meta.lunar_month || '') + '月' : meta.lunar_day;
            lunarHtml = `<span class="lunar-date">${escapeHtml(lunarText)}</span>`;
        }

        // Solar term badge
        let termHtml = '';
        if (meta.solar_term) {
            termHtml = `<div class="solar-term">${escapeHtml(meta.solar_term)}</div>`;
        }

        // Holiday / festival badge
        let holidayHtml = '';
        if (meta.is_holiday || meta.holiday_name) {
            const label = meta.is_holiday ? '【假期】' : '';
            const name = meta.holiday_name || '';
            holidayHtml = `<div class="holiday-badge">${label}${escapeHtml(name)}</div>`;
        }

        // Adjusted workday badge
        let workdayHtml = '';
        if (meta.is_workday) {
            workdayHtml = '<div class="workday-badge">【班】</div>';
        }

        return `
            <div class="calendar-day${otherClass}${weekendClass}${sundayClass}" data-date="${dateStr}" style="${bgStyle}">
                <div class="day-row">
                    <span class="day-number">${dayNum}</span>
                    ${lunarHtml}
                </div>
                ${termHtml}
                ${holidayHtml}
                ${workdayHtml}
                ${planLinesHtml}
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
