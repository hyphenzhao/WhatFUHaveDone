/** Configuration form state tests with fake API responses; never uses real keys. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const markup = fs.readFileSync(path.join(root,'pages/ai-admin.php'),'utf8');
class Field {
    value=''; disabled=false; placeholder=''; textContent=''; innerHTML=''; handlers=new Map();
    addEventListener(event, fn) { this.handlers.set(event,fn); }
    replaceChildren(...options) { this.options=options; }
    emit(event) { return this.handlers.get(event)?.(); }
}
const fields=Object.fromEntries([...markup.matchAll(/id="([^"]+)"/g)].map(m=>[m[1],new Field()]));
const presets=Object.fromEntries(['deepseek','openai','anthropic','openai_compatible','ollama'].map(p=>[p,{label:p,endpoint:'https://'+p+'.invalid/v1',model:p==='deepseek'?'fixture-ds':'',hint:'Fixture '+p}]));
const saved={provider:'deepseek',endpoint:presets.deepseek.endpoint,model:'fixture-ds',has_api_key:true,api_key:'***seek',providers:presets};
let failSave=false;const calls=[],errors=[];
const ctx=vm.createContext({Map,Option:class{constructor(text,value){this.text=text;this.value=value;}},
    document:{getElementById:id=>{assert.ok(fields[id],'missing form field '+id);return fields[id];},addEventListener:()=>{}},
    API:{get:async()=>({data:saved}),post:async(url,body)=>{calls.push({url,body});if(url==='/ai/config/test')return {message:'<unsafe fixture text>'};if(failSave)throw Error('Fixture validation error');return {data:{provider:body.provider,endpoint:body.endpoint,model:body.model,has_api_key:true}};}},
    Toast:{error:msg=>errors.push(msg),success:()=>{}},
    escapeHtml:x=>String(x).replace(/</g,'&lt;').replace(/>/g,'&gt;'),
    localStorage:{setItem(){throw Error('API keys must not enter persistent browser storage');}}
});
vm.runInContext(fs.readFileSync(path.join(root,'assets/js/ai-admin.js'),'utf8'),ctx);
const admin=vm.runInContext('AIAdmin',ctx);
(async()=>{
    await admin.init();
    assert.equal(fields.aiProvider.value,'deepseek');assert.equal(fields.aiEndpoint.value,saved.endpoint);
    assert.equal(fields.aiModel.value,'fixture-ds');assert.equal(fields.aiApiKey.value,'');
    assert.match(fields.aiApiKey.placeholder,/保留/);assert.equal(fields.aiProvider.options.length,5);assert.equal(calls.length,0);
    fields.aiProvider.value='anthropic';fields.aiProvider.emit('change');
    assert.equal(fields.aiEndpoint.value,presets.anthropic.endpoint);assert.equal(fields.aiApiKey.value,'');assert.equal(fields.aiModel.value,'');
    fields.aiApiKey.value='fixture-anthropic-typed';fields.aiModel.value='fixture-claude';
    fields.aiProvider.value='deepseek';fields.aiProvider.emit('change');
    assert.equal(fields.aiEndpoint.value,saved.endpoint);assert.equal(fields.aiApiKey.value,'');assert.equal(fields.aiModel.value,'fixture-ds');
    fields.aiProvider.value='anthropic';fields.aiProvider.emit('change');
    assert.equal(fields.aiApiKey.value,'fixture-anthropic-typed');assert.equal(fields.aiModel.value,'fixture-claude');
    await admin.test();
    assert.equal(calls.length,1);assert.equal(calls[0].url,'/ai/config/test');assert.equal(calls[0].body.provider,'anthropic');
    assert.equal(admin.saved.provider,'deepseek');assert.match(fields.testResult.innerHTML,/&lt;unsafe/);assert.ok(!fields.testResult.innerHTML.includes('<unsafe'));
    failSave=true;await admin.save();
    assert.equal(admin.saved.provider,'deepseek');assert.equal(fields.aiApiKey.value,'fixture-anthropic-typed');assert.equal(fields.saveAiConfig.disabled,false);assert.equal(errors.length,1);
    failSave=false;await admin.save();
    assert.equal(admin.saved.provider,'anthropic');assert.equal(fields.aiApiKey.value,'');assert.match(fields.aiApiKey.placeholder,/保留/);
    assert.equal(calls[2].body.api_key,'fixture-anthropic-typed');
    fields.aiProvider.value='openai';fields.aiProvider.emit('change');assert.equal(fields.aiApiKey.value,'');assert.equal(fields.aiEndpoint.value,presets.openai.endpoint);
    fields.aiProvider.value='anthropic';fields.aiProvider.emit('change');assert.equal(fields.aiApiKey.value,'');assert.match(fields.aiApiKey.placeholder,/保留/);
    console.log('PASS: form loads without overwriting settings, provider drafts keep keys separate, tests do not switch active config, failed saves preserve typed key, successful saves clear it.');
})().catch(e=>{console.error(e);process.exitCode=1;});
