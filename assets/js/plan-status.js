/** Completed calendar entries, including all-day and legacy execution records. */
const PlanUI = {
    isCompleted(plan) { return Number(plan.is_completed) === 1; },
    colors(value) {
        let hex = String(value || '').trim();
        if (/^#[0-9a-f]{3}$/i.test(hex)) hex = '#' + [...hex.slice(1)].map(c => c + c).join('');
        if (!/^#[0-9a-f]{6}$/i.test(hex)) hex = '#3b82f6';
        const rgb = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16));
        const toHex = channels => '#' + channels.map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
        const luminance = channels => channels.map(v => v / 255)
            .map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4)
            .reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
        // Keep the tag hue in a soft tint; darken its text only as needed for legibility.
        const background = rgb.map(v => Math.round((v * 14 + 255 * 86) / 100));
        let foreground = rgb;
        while ((luminance(background) + .05) / (luminance(foreground) + .05) < 4.5) {
            foreground = foreground.map(v => Math.floor(v * .9));
        }
        return { accent: hex, background: toHex(background), foreground: toHex(foreground) };
    },
    style(plan) {
        const c = this.colors(plan.tag?.color || plan.tags?.[0]?.color);
        return `--plan-color:${c.accent};--plan-background:${c.background};--plan-foreground:${c.foreground};`;
    },
    badge() { return '<span class="plan-completed-badge"><span aria-hidden="true">✓</span> 已完成</span>'; },
    calendarLine(plan) {
        const done = this.isCompleted(plan);
        const time = plan.plan_time ? plan.plan_time.slice(0, 5) + (plan.plan_end_time ? '–' + plan.plan_end_time.slice(0, 5) : '') : '';
        const label = (time ? time + ' ' : '') + (plan.name || '');
        return `<div class="calendar-plan-line${done ? ' is-completed' : ''}" data-plan-id="${Number(plan.plan_id) || 0}" style="${this.style(plan)}" title="${escapeHtml(label)} · ${done ? '已完成' : '待完成'}">${done ? '<span class="calendar-plan-check" role="img" aria-label="已完成">✓</span>' : ''}<span class="calendar-plan-label">${escapeHtml(label)}</span></div>`;
    }
};
