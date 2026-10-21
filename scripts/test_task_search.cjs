/** Task search interaction regressions. Synthetic records only; no server or API writes. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const source = name => fs.readFileSync(path.join(root, 'assets/js', name), 'utf8');
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let checks = 0;
function check(ok, message) { assert.ok(ok, message); checks++; }
class Element {
    value = ''; innerHTML = ''; textContent = ''; hidden = false; style = {}; handlers = {}; childrenBySelector = {};
    addEventListener(event, handler) { this.handlers[event] = handler; }
    emit(event, values = {}) { return this.handlers[event]?.({target:this, preventDefault(){}, stopPropagation(){}, ...values}); }
    querySelector(selector) { return this.childrenBySelector[selector] ||= new Element(); }
    querySelectorAll() { return []; }
    focus() { this.focused = true; }
}
function environment() {
    const nodes = new Map(), ready = [], timers = new Map(); let timerId = 0;
    const document = {getElementById(id) { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id); },
        addEventListener(event, handler) { if (event === 'DOMContentLoaded') ready.push(handler); }};
    const ctx = vm.createContext({document, Map, Set, Date, console, escapeHtml, appUrl: p => '/calendar' + p,
        localStorage:{getItem:() => null}, getDeadlineBadge:() => '', taskTypeBadge:t => t.task_type === 'meeting' ? '会议' : '',
        taskStageBadge:t => t.stage, timeAgo:() => '', Toast:{error:message => { throw Error(message); }},
        setTimeout:fn => { timers.set(++timerId, fn); return timerId; }, clearTimeout:id => timers.delete(id)});
    vm.runInContext(source('task-search.js'), ctx);
    return {ctx, document, ready, read:expression => vm.runInContext(expression, ctx),
        flush() { for (const [id, fn] of timers) { timers.delete(id); fn(); } }};
}
const fixtures = [
    {id:1, name:'论文 A+B <初稿>', description:'准备实验图表', location:'上海', people:[{name:'王老师'}], tags:[{name:'科研',color:'#123456'}], results:[{name:'SCI 稿件'}], stage:'in_progress', task_type:'general', priority:3},
    {id:2, name:'导师讨论', description:'核对实验记录', location:'会议室', people:[{name:'王老师'}], tags:[{name:'科研',color:'#123456'}], stage:'in_progress', task_type:'meeting', priority:1},
    {id:3, name:'旧项目', description:'备份设备手册', tags:[{name:'科研',color:'#123456'}], stage:'completed', task_type:'meeting', priority:2},
    {id:4, name:'已归档合作', description:'准备实验材料', people:[{name:'王老师'}], stage:'completed', task_type:'general', priority:4},
];

(async () => {
    const basic = environment(), search = basic.read('TaskSearch');
    check(JSON.stringify(search.tokens(' SCI   sci 王老师 ')) === '["sci","王老师"]', 'case-insensitive unique space-separated terms');
    check(search.matches(fixtures[0], search.tokens('SCI 王老师 实验')), 'all terms may match different metadata fields');
    check(!search.matches(fixtures[0], search.tokens('SCI 不存在')), 'every keyword must match');
    check(search.matches(fixtures[0], ['a+b']), 'punctuation is literal, not a regex');
    check(!search.matches({name:'a',description:'b'}, ['ab']), 'keyword cannot cross unrelated field boundaries');
    check(search.matches({name:null}, []), 'empty query includes tasks with missing optional fields');
    const highlighted = search.highlight('<script>A+B & SCI</script>', ['a+b','sci']);
    check(!highlighted.includes('<script>') && highlighted.includes('&lt;script&gt;') && highlighted.includes('&amp;'), 'highlighting escapes HTML and ampersands');
    check(highlighted.includes('>A+B</mark>') && highlighted.includes('>SCI</mark>'), 'literal punctuation and case differences highlighted');
    check(search.highlight('实验图表', ['实验','实验图表']).includes('>实验图表</mark>'), 'overlapping terms produce one longest highlight');
    check(search.context({description:'前'.repeat(200) + '关键字' + '后'.repeat(200)}, ['关键字']).includes('>关键字</mark>'), 'excerpt includes match far into a long description');

    let changes = 0;
    const host = new Element(), widget = search.mount(host, {id:'fixture',placeholder:'搜索',controls:'results',onChange:() => changes++});
    const input = host.querySelector('input'), clear = host.querySelector('button');
    input.value = '科研'; input.emit('compositionstart'); input.emit('input', {isComposing:true}); basic.flush();
    check(changes === 0, 'Chinese composition does not filter incomplete input');
    input.emit('compositionend'); check(changes === 1 && widget.terms()[0] === '科研', 'Chinese composition end filters immediately');
    input.value = 'SCI'; input.emit('input'); input.value = 'SCI 稿件'; input.emit('input'); basic.flush();
    check(changes === 2 && widget.terms().length === 2, 'rapid input batches one local update');
    clear.emit('click'); check(changes === 3 && !input.value && input.focused && clear.hidden, 'clear immediately restores results and input focus');
    let stopped = false; input.value = '论文'; input.emit('keydown', {key:'Escape',stopPropagation:() => {stopped=true;}});
    check(!input.value && stopped, 'Escape clears search without closing the mobile task drawer');

    const management = environment(); let requests = 0;
    management.ctx.API = {tasks:{list:async archived => { requests++; return {data: archived ? [fixtures[3]] : fixtures.slice(0,3)}; }}};
    vm.runInContext(source('tasks.js'), management.ctx);
    await management.ready[0]();
    const managementHost = management.document.getElementById('tasksSearch'), taskInput = managementHost.querySelector('input');
    const setManagementQuery = query => {taskInput.value=query; taskInput.emit('input'); management.flush();};
    check(requests === 2 && management.read('isPrioritySort'), 'initial list loads active and archived once, full list remains reorderable');
    setManagementQuery('王老师 实验');
    check(management.read('tasksData.active.length') === 2 && management.read('tasksData.archived.length') === 1, 'search includes active and archived descriptions and people');
    check(management.read('isPrioritySort'), 'search results keep their drag controls');
    management.document.getElementById('stageFilter').value='completed'; management.document.getElementById('stageFilter').emit('change');
    check(management.read('tasksData.active.length') === 0 && management.read('tasksData.archived.length') === 1, 'stage filter applies to archives too');
    check(management.document.getElementById('tasksTableBody').innerHTML.includes('都在下方'), 'archive-only match has helpful guidance');
    management.document.getElementById('resetTaskFilters').emit('click');
    check(taskInput.value === '王老师 实验' && management.read('tasksData.active.length') === 2, 'reset filters preserves the query');
    setManagementQuery('论文');
    check(management.document.getElementById('tasksTableBody').innerHTML.includes('task-search-mark') && management.document.getElementById('tasksTableBody').innerHTML.includes('TaskDetail.open(1)'), 'matching name is highlighted and opens task details');
    setManagementQuery('不存在');
    check(management.document.getElementById('tasksTableBody').innerHTML.includes('没有找到匹配') && management.document.getElementById('archivedTasksSection').style.display === 'none', 'no-match state does not leave stale archived results');
    managementHost.querySelector('button').emit('click');
    check(management.read('isPrioritySort') && management.read('tasksData.active.length') === 3, 'clear restores original tasks and sorting');
    management.document.getElementById('sortDir').emit('click');
    check(management.read('isPrioritySort'), 'descending priority view remains reorderable');
    setManagementQuery('论文');
    management.document.getElementById('typeFilter').value='meeting'; management.document.getElementById('typeFilter').emit('change');
    management.document.getElementById('sortSelect').value='updated_at'; management.document.getElementById('sortSelect').emit('change');
    check(!management.document.getElementById('enableTaskReorder').hidden, 'updated-time view offers priority ordering');
    management.document.getElementById('enableTaskReorder').emit('click');
    check(taskInput.value === '论文' && management.read("sortBy === 'priority' && sortAsc && filterType === 'meeting' && filterStage === 'all' && isPrioritySort"), 'switching to priority ordering preserves query and filters');
    managementHost.querySelector('button').emit('click'); management.document.getElementById('resetTaskFilters').emit('click');
    management.document.getElementById('stageFilter').value='in_progress'; management.document.getElementById('stageFilter').emit('change');
    check(management.read('isPrioritySort && tasksData.active.length === 2') && management.document.getElementById('tasksTableBody').innerHTML.includes('draggable="true"'), 'in-progress filter directly supports dragging');
    check(management.document.getElementById('tasksTableBody').innerHTML.includes('draggable="true"') && management.document.getElementById('tasksTableBody').innerHTML.includes('moveTaskPriority('), 'full task list has explicit native drag handles and step controls');
    check(management.document.getElementById('taskReorderHint').textContent.includes('拖动'), 'reorder availability is explained on the page');
    check(requests === 2, 'typing, filtering, sorting and clearing never request task data again');

    const home = environment(); let homeRequests = 0;
    home.ctx.App = {selectedDate:'2026-10-21'};
    home.ctx.API = {tasks:{list:async (_,stage) => {homeRequests++; return {data:fixtures.slice(0,3).filter(t=>t.stage===stage)};}},
        worklogs:{forDate:async()=>{homeRequests++;return {data:[{task_id:1}]};}}, worklogNotes:{listAll:async()=>{homeRequests++;return {data:[]};}}};
    vm.runInContext(source('task-card.js'), home.ctx); vm.runInContext(source('home.js'), home.ctx);
    await home.read('loadRightPanel()');
    const homeInput = home.document.getElementById('rightTaskSearch').querySelector('input');
    const homeResults = () => home.document.getElementById('rightPanelTaskSections').innerHTML;
    const setHomeQuery = query => {homeInput.value=query;homeInput.emit('input');home.flush();};
    check(/task-section collapsed[^>]*data-task-section="completed"/.test(homeResults()), 'completed group starts collapsed');
    home.read("taskSectionState.set('meeting', false)");
    setHomeQuery('科研');
    check(!homeResults().includes('task-section collapsed') && homeResults().includes('data-task-id="3"'), 'matching completed tasks are visible without expanding groups manually');
    check(!homeResults().includes('data-task-section="failed"') && !homeResults().includes('data-task-section="stage_complete"'), 'empty groups are omitted while searching');
    setHomeQuery('旧项目');
    check(homeResults().includes('data-task-section="completed"') && !homeResults().includes('data-task-section="meeting"'), 'completed meeting remains in completed group');
    setHomeQuery('不存在');
    check(homeResults().includes('没有找到匹配') && homeResults().includes('/calendar/tasks'), 'empty search links to archive search with reverse-proxy prefix');
    home.document.getElementById('rightTaskSearch').querySelector('button').emit('click');
    check(/task-section collapsed[^>]*data-task-section="completed"/.test(homeResults()) && /task-section" data-task-section="meeting"/.test(homeResults()), 'clear restores saved expansion choices');
    check(homeRequests === 6, 'homepage search reuses already loaded data without calls');
    setHomeQuery('论文'); home.ctx.App.selectedDate='2026-10-22'; await home.read('loadRightPanel()');
    check(homeInput.value === '论文' && homeResults().includes('data-task-id="1"'), 'query and input survive date refresh');
    check(homeRequests === 7, 'date refresh only fetches new daily work logs, not task lists');
    console.log(`PASS: ${checks} task search / IME / filters / archives / group state checks`);
})().catch(error => {console.error(error);process.exitCode=1;});
