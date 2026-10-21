/** Actual drag handlers and save flow with synthetic rows and a stubbed server. */
const fs=require('node:fs'), path=require('node:path'), vm=require('node:vm'), assert=require('node:assert/strict');
let checks=0; const check=(ok,label)=>{assert.ok(ok,label);checks++;};
class Node {
    handlers={}; classes=new Set(); isConnected=true;
    classList={add:c=>this.classes.add(c),remove:c=>this.classes.delete(c)};
    addEventListener(type,handler){this.handlers[type]=handler;}
    emit(type,extra={}){return this.handlers[type]?.({preventDefault(){},...extra});}
}
const tasks=[{id:1,priority:10},{id:2,priority:30},{id:3,priority:70}];
let rows=[],requests=[],errors=[],reloads=0,heldResolve=null,fail=false;
const tbody={
    querySelectorAll(selector){return selector==='tr.draggable'?rows:rows.filter(r=>r.classes.has('task-drop-target'));},
    insertBefore(row,before){rows=rows.filter(r=>r!==row);const i=before?rows.indexOf(before):rows.length;rows.splice(i,0,row);}
};
function createRows(ids){
    rows=ids.map(id=>{const row=new Node(),handle=new Node();row.dataset={id:String(id)};row.querySelector=()=>handle;row.handle=handle;
        Object.defineProperty(row,'nextSibling',{get:()=>rows[rows.indexOf(row)+1]||null});return row;});
}
const ctx=vm.createContext({console,Map,Set,document:{addEventListener(){},getElementById:()=>tbody,querySelector:()=>null},
    API:{tasks:{async reorder(ids){requests.push([...ids]);if(heldResolve!==null)await new Promise(resolve=>{heldResolve=resolve;});if(fail)throw Error('fixture server error');}}},
    Toast:{success(){},error:message=>errors.push(message)}});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/js/tasks.js'),'utf8'),ctx);
ctx.initial=tasks;vm.runInContext("allTasksData={active:initial,archived:[]};tasksData={active:[...initial],archived:[]};isPrioritySort=true;renderTasks=()=>{};loadTasks=async()=>{reloadNotice();};",ctx);
ctx.reloadNotice=()=>reloads++;
const read=code=>vm.runInContext(code,ctx), tick=()=>new Promise(resolve=>setImmediate(resolve));
const transfer=()=>({effectAllowed:'',dropEffect:'',data:null,setData(type,value){this.data=[type,value];},setDragImage(){}});
function start(id){const row=rows.find(r=>Number(r.dataset.id)===id),dataTransfer=transfer();row.handle.emit('dragstart',{dataTransfer});return {row,dataTransfer};}
(async()=>{
    createRows([1,2,3]);read('initDragDrop()');
    let drag=start(3);
    check(drag.dataTransfer.effectAllowed==='move' && drag.dataTransfer.data[1]==='3','native drag sets transferable data required by browsers');
    rows[0].emit('dragover',{dataTransfer:drag.dataTransfer});check(rows[0].classes.has('task-drop-target'),'target row gives visual drop feedback');
    rows[0].emit('drop');await tick();
    check(JSON.stringify(requests)==='[[3,1,2]]','last-to-first drop saves complete order in one request');
    check(tasks.find(t=>t.id===3).priority===1 && tasks.find(t=>t.id===1).priority===2 && tasks.find(t=>t.id===2).priority===3,'priorities start at one, not the previous priority of the new first task');
    drag.row.handle.emit('dragend');await tick();check(requests.length===1,'dragend never sends a duplicate save');
    read('tasksData.active=[initial[2],initial[0],initial[1]]');createRows([3,1,2]);read('initDragDrop()');
    drag=start(1);drag.row.handle.emit('dragend');await tick();check(requests.length===1,'cancelled drag does not change saved priorities');
    drag=start(1);drag.row.emit('drop');drag.row.handle.emit('dragend');await tick();check(requests.length===1,'dropping on self does not save');
    let externalAccepted=false;rows[0].emit('dragover',{preventDefault:()=>{externalAccepted=true;}});check(!externalAccepted,'external text drags do not reorder tasks');
    await read('moveTaskPriority(2,-1)');check(JSON.stringify(requests[1])==='[3,2,1]','step controls use the same complete-order save');
    read('tasksData.active=[initial[2],initial[1],initial[0]]');createRows([3,2,1]);read('initDragDrop()');
    rows[1].handle.emit('keydown',{key:'ArrowUp'});await tick();check(JSON.stringify(requests[2])==='[2,3,1]','keyboard arrows on the handle change priorities');
    const count=requests.length;await read('moveTaskPriority(3,0)');await read('saveTaskOrder([1,1,2])');check(requests.length===count,'invalid steps and duplicate IDs never reach the server');
    read('isPrioritySort=false');await read('moveTaskPriority(3,-1)');let prevented=false;rows[0].handle.emit('dragstart',{preventDefault:()=>{prevented=true;}});
    check(requests.length===count && prevented,'search/sort transition cancels an existing drag safely');read('isPrioritySort=true');
    createRows([3,2,1]);read('initDragDrop()');drag=start(1);drag.row.isConnected=false;rows[0].emit('drop');await tick();
    check(requests.length===count,'refresh removing the source row cannot reinsert a stale task');
    fail=true;const before=tasks.map(t=>t.priority).join();await read('saveTaskOrder([1,2,3])');
    check(errors.length===1 && reloads===1 && tasks.map(t=>t.priority).join()===before && !read('taskReorderSaving'),'save failure reloads server state without accepting an unsaved order');
    fail=false;heldResolve=()=>{};const pending=read('saveTaskOrder([1,2,3])');await tick();const inflight=requests.length;
    await read('saveTaskOrder([1,3,2])');check(requests.length===inflight,'a second reorder cannot overlap a pending save');heldResolve();await pending;
    check(!read('taskReorderSaving'),'save lock is released after success');
    console.log(`PASS: ${checks} native drag / keyboard / priority persistence checks`);
})().catch(error=>{console.error(error);process.exitCode=1;});
