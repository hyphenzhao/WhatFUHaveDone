/** Shared completed-plan appearance for the calendar and daily cards. */
const PlanUI = {
    isCompleted(plan) { return Number(plan.is_completed) === 1; },
    colors(value) {
        let hex = String(value || '').trim();
        if (/^#[0-9a-f]{3}$/i.test(hex)) hex = '#' + [...hex.slice(1)].map(c => c + c).join('');
        if (!/^#[0-9a-f]{6}$/i.test(hex)) hex = '#3b82f6';
        const rgb = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255)
            .map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
        const luminance = rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
        return { background: hex, foreground: (luminance + .05) / .05 >= 1.05 / (luminance + .05) ? '#000000' : '#ffffff' };
    },
    style(plan) {
        const c = this.colors(plan.tag?.color || plan.tags?.[0]?.color);
        return `--plan-color:${c.background};--plan-foreground:${c.foreground};`;
    },
    badge() { return '<span class="plan-completed-badge"><span aria-hidden="true">✓</span> 已完成</span>'; },
    calendarLine(plan) {
        const done = this.isCompleted(plan);
        const time = plan.plan_time ? plan.plan_time.slice(0, 5) + (plan.plan_end_time ? '–' + plan.plan_end_time.slice(0, 5) : '') : '';
        const label = (time ? time + ' ' : '') + (plan.name || '');
        return `<div class="calendar-plan-line${done ? ' is-completed' : ''}" data-plan-id="${Number(plan.plan_id) || 0}" style="${this.style(plan)}" title="${escapeHtml(label)} · ${done ? '已完成' : '待完成'}">${done ? '<span class="calendar-plan-check" role="img" aria-label="已完成">✓</span>' : ''}<span class="calendar-plan-label">${escapeHtml(label)}</span></div>`;
    }
};
