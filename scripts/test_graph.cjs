/* Offline geometry/interaction invariants; no browser or user records. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const src = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/graph.js'), 'utf8');
const frames = new Map(); let nextFrame = 0;
const doc = { hidden: false, getElementById: () => null };
const graph = vm.runInNewContext(src + '\nGraph;', { document: doc, console,
    requestAnimationFrame: fn => { const id = ++nextFrame; frames.set(id, fn); return id; },
    cancelAnimationFrame: id => frames.delete(id)
});
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const people = Array.from({ length: 45 }, (_, i) => ({ type: 'person', id: i + 1, name: `人物${i + 1}`, relationship: ['同事', '导师', '朋友'][i % 3], workload: i ? (i * 7) % 51 : 0, results: (i * 13) % 23 }));
graph.models = new Map(); graph.mode = 'workload'; graph.query = ''; graph.relation = '';
graph.setData({ nodes: [{ type: 'me', id: 0 }, ...people], edges: people.filter(n => n.workload || n.results).map(n => ({ source: 0, target: n.id, workload: n.workload, results: n.results })) });
check(graph.people.length === 45 && graph.connected.size === 44, 'zero-record person has no invented edge');
check(new Set(graph.groups.map(r => graph._colors({ relationship: r })[0])).size === 3, 'distinct colors for small set of relations');
graph.size = { w: 800, h: 520 }; graph._seed(graph.visiblePeople());
for (let i = 0; i < 160; i++) graph._step(Math.max(.15, .92 * .958 ** i));
check(graph.simNodes.every(n => Number.isFinite(n.x) && Number.isFinite(n.y)), 'finite layout');
let overlaps = 0;
for (let i = 0; i < graph.simNodes.length; i++) for (let j = i + 1; j < graph.simNodes.length; j++) {
    const a = graph.simNodes[i], b = graph.simNodes[j]; if (Math.hypot(a.x - b.x, a.y - b.y) < a.radius + b.radius + 5) overlaps++;
}
check(overlaps === 0, 'settled node discs do not overlap');
check(graph.simNodes.every(n => Math.hypot(n.x - 400, n.y - 260) >= n.radius + 38), 'nodes clear centre');
const pinned = graph.simNodes[0]; pinned.pinned = true; const position = [pinned.x, pinned.y];
for (let i = 0; i < 30; i++) graph._step(.6);
check(pinned.x === position[0] && pinned.y === position[1], 'manual placement survives subsequent layout');
graph.query = '人物12'; check(graph.visiblePeople().length === 1 && graph.visiblePeople()[0].id === 12, 'literal name search');
graph.query = ''; graph.relation = '导师'; check(graph.visiblePeople().length === 15, 'relationship filter');
graph.relation = ''; graph.mode = 'results';
const original = graph.people.map(n => `${n.id}:${n.workload}:${n.results}`).join();
graph._seed(graph.visiblePeople());
check(graph.people.map(n => `${n.id}:${n.workload}:${n.results}`).join() === original, 'changing size metric preserves facts');
check(graph.simNodes.find(n => n.results === 0).targetRadius === 22, 'zero metric retains usable node');
check(graph.simNodes.every(n => n.targetRadius >= 22 && n.targetRadius <= 40), 'bounded metric scale');
graph.camera = { k: 1, x: 10, y: 20 }; graph.zoom(2, { x: 100, y: 100 });
const world = graph._world({ x: 100, y: 100 });
check(world.x === 90 && world.y === 80, 'zoom holds anchor under pointer');
graph.zoom(1e10); check(graph.camera.k === 3, 'upper zoom bound');
graph.zoom(1e-10); check(graph.camera.k === .12, 'lower zoom bound');
check(graph._value({ workload: Infinity }) === 0 && graph._value({ results: -2 }, 'results') === 0, 'invalid counts sanitised');
graph.models.clear(); graph.size = { w: 340, h: 440 }; graph._seed(graph.visiblePeople());
for (let i = 0; i < 160; i++) graph._step(Math.max(.15, .92 * .958 ** i));
check(graph.simNodes.every(n => Number.isFinite(n.x) && Number.isFinite(n.y)), 'mobile geometry remains finite');
check(graph.simNodes.length === 45, 'no silent person truncation');
graph._paint = () => {}; graph._status = () => {}; graph.fit = () => {};
graph.reduced = false; graph.paused = false; graph.frame = 0;
graph._reheat(); let ticks = 0;
while (frames.size && ticks < 250) { const [id, callback] = frames.entries().next().value; frames.delete(id); callback(++ticks * 17); }
check(ticks > 1 && ticks < 150 && graph.alpha === 0 && !frames.size, 'animation settles without an idle frame loop');
graph.reduced = true; graph.allowMotion = false; graph._reheat();
check(!frames.size && graph.alpha === 0, 'reduced motion computes a static layout');
graph.allowMotion = true; graph._reheat(); check(frames.size === 1, 'explicit motion opt-in schedules animation'); graph._stop();
doc.hidden = true; graph._schedule(); check(!frames.size, 'hidden page does not schedule animation'); doc.hidden = false;
graph.paused = true; graph._reheat(); check(!frames.size, 'pause disables physics animation');
graph.pointers = new Map(); graph.selectedId = null; graph._detail = () => {}; graph._highlight = () => {}; graph.hideTooltip = () => {};
const captures = new Set(); graph.svg = { setPointerCapture: id => captures.add(id), hasPointerCapture: id => captures.has(id), releasePointerCapture: id => captures.delete(id), classList: { add() {}, remove() {} } };
graph._screen = e => ({ x: e.x, y: e.y }); graph.camera = { k: 1, x: 0, y: 0 };
const person = graph.simNodes.find(n => !n.pinned), start = { x: person.x, y: person.y };
const pointer = (id, x, y, nodeId = 0) => ({ pointerId: id, pointerType: 'touch', x, y, preventDefault() {}, target: { closest: () => nodeId ? { dataset: { nodeId: String(nodeId) } } : null } });
graph._pointerDown(pointer(1, start.x, start.y, person.id));
graph._pointerDown(pointer(2, start.x + 100, start.y));
graph._pointerMove(pointer(2, start.x + 200, start.y));
check(Math.abs(graph.camera.k - 2) < 1e-8 && !person.pinned, 'pinch zoom does not pin the first touched person');
graph._pointerUp(pointer(2, start.x + 200, start.y)); graph._pointerUp(pointer(1, start.x, start.y));
check(!graph.pointers.size && graph.selectedId === null && !graph.drag, 'pinch release does not become a ghost click or drag');
graph._pointerDown(pointer(3, start.x, start.y, person.id)); graph._pointerUp(pointer(3, start.x, start.y));
check(graph.selectedId === person.id && !person.pinned, 'click selects without pinning layout');
console.log(`PASS: ${checks} graph geometry checks`);
