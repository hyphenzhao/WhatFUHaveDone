const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const escapeHtml = x => String(x ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const root = path.join(__dirname, '..');
const PlanUI = vm.runInNewContext(fs.readFileSync(path.join(root, 'assets/js/plan-status.js'),'utf8')+'\nPlanUI;', {escapeHtml});
const Calendar = vm.runInNewContext(fs.readFileSync(path.join(root, 'assets/js/calendar.js'),'utf8')+'\nCalendar;', {escapeHtml,PlanUI});
let checks = 0;
function check(ok, message) { assert.ok(ok, message); checks++; }
check(PlanUI.colors('#1e40af').foreground === '#ffffff', 'deep color uses white text');
check(PlanUI.colors('#fde68a').foreground === '#000000', 'pale color uses black text');
check(PlanUI.colors('#fff').background === '#ffffff', 'short hex supported');
check(PlanUI.colors('red;background:url(x)').background === '#3b82f6', 'unsafe CSS color rejected');
function brightness(hex) {
    const channels = hex.match(/[a-f0-9]{2}/gi).map(c => parseInt(c,16)/255).map(v=>v <= 0.04045 ? v/12.92 : Math.pow((v+0.055)/1.055,2.4));
    return channels[0]*0.2126+channels[1]*0.7152+channels[2]*0.0722;
}
for (let i=0; i<256; i++) {
    const c=PlanUI.colors('#'+i.toString(16).padStart(2,'0').repeat(3));
    const a=brightness(c.background), b=brightness(c.foreground);
    check((Math.max(a,b)+0.05)/(Math.min(a,b)+0.05)>=4.5,'readable grayscale '+i);
}
const pending={id:7,plan_id:11,event_type:'plan',name:'资料 <校对>',plan_time:'09:00',plan_end_time:'10:00',is_completed:0,tag:{color:'#1e40af'}};
const done={...pending,plan_id:12,plan_time:'14:00',plan_end_time:'15:00',is_completed:1};
check(!PlanUI.calendarLine(pending).includes('is-completed'), 'pending plan keeps outlined style');
check(PlanUI.calendarLine(done).includes('calendar-plan-check'), 'completion check visible');
check(PlanUI.calendarLine(done).includes('14:00–15:00'), 'schedule remains visible');
check(PlanUI.calendarLine(done).includes('&lt;校对&gt;'), 'task names escaped');
Calendar.calendarData={'2026-10-12':[pending,done,pending]}; Calendar.calendarMeta={};
const html=Calendar._renderDay(12,'2026-10-12',false,1);
check((html.match(/data-plan-id=/g)||[]).length===2,'two plans on same task/day remain distinct');
check((html.match(/class="calendar-plan-check"/g)||[]).length===1,'completion only affects selected plan');
const allDay={...done,plan_id:13,plan_time:'',plan_end_time:''};
check(PlanUI.calendarLine(allDay).includes('is-completed') && PlanUI.calendarLine(allDay).includes('calendar-plan-check'),'all-day completed plans have the same fill and check');
check(!PlanUI.calendarLine(allDay).includes('14:00'),'no fabricated time for all-day plans');
const legacy1={...allDay,plan_id:null,completion_key:'execution:42|09:00|10:00'};
const legacy2={...allDay,plan_id:null,completion_key:'execution:42|14:00|15:00'};
Calendar.calendarData={'2026-10-12':[legacy1,legacy2,legacy1]};
check((Calendar._renderDay(12,'2026-10-12',false,1).match(/class="calendar-plan-check"/g)||[]).length===2,'legacy entries use their own deduplication keys');
console.log(`PASS: ${checks} plan appearance checks`);
