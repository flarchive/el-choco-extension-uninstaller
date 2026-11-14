const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

function translations(language) {
  const result = {};
  const yaml = fs.readFileSync(path.join(__dirname, '../locale/' + language + '.yml'), 'utf8');
  for (const line of yaml.split(/\r?\n/)) { const match = line.match(/^    ([a-z_]+): (.+)$/); if (match) result[match[1]] = JSON.parse(match[2]); }
  return result;
}
function environment(version, language = 'de') {
  let Page;
  const requests = [], initializers = [], beforeMount = [], timers = [];
  const dictionary = translations(language);
  const row = {name:'acme/example',title:'Example Extension',description:'A useful extension.',version:'1.0.0',enabled:true,direct:true,protected:false,dependents:[]};
  const plan = {id:'b'.repeat(32),package:row.name,mode:'keep',extension:row,expires:Date.now()/1000+600,removed:[{name:row.name,version:'1.0.0'}],migrationFiles:['001.php']};
  const state = {job:{id:'a'.repeat(32),status:'succeeded',seconds:.4,output:'<script>alert(1)</script>',result:plan},failPost:false};
  const registry = {for:()=>registry,registerPage:value=>{Page=value;}};
  const app = {
    translator:{trans:(key,args={})=>{
      const short=key.replace('el-choco-extension-uninstaller.admin.','');
      let text=dictionary[short]||key;for(const [k,v]of Object.entries(args))text=text.replace('{'+k+'}',String(v));return version===1?[text]:text;
    }},
    forum:{attribute:()=>'/api'},
    initializers:{add:(_,fn)=>initializers.push(fn)},beforeMount:fn=>beforeMount.push(fn),
    extensionData:registry,registry,
    request:async request=>{
      requests.push(request);
      if(request.method==='POST') {if(state.failPost)throw Error('Connection lost');return{id:request.body.requestId};}
      if(request.url.includes('?job='))return state.job;
      return{extensions:[row],history:[],activeJob:null,workerSeen:Date.now()/1000};
    }
  };
  class Admin {page(page){this.pageClass=page;return this;}extend(application){application.beforeMount(()=>application.registry.registerPage(this.pageClass));}}
  const modules={'admin/app':app,'admin/components/ExtensionPage':class{oninit(){}},'common/extenders/Admin':Admin};
  const m=(...args)=>args;m.redraw=()=>{};
  const flarum=version===1?{core:{compat:modules}}:{reg:{get:(_ns,key)=>modules[key],add(){}}};
  flarum.extensions={};
  const context={module:{exports:{}},flarum,m,window:{crypto:crypto.webcrypto},Uint8Array,console,clearTimeout(){},setTimeout:fn=>{timers.push(fn);return timers.length;}};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../js/dist/admin-v'+version+'.js'),'utf8'),context);
  const exported=context.module.exports;
  assert.ok(exported.extend);
  for(const extender of exported.extend.flat(Infinity))extender.extend(app,{name:'el-choco-extension-uninstaller'});
  initializers.forEach(fn=>fn());beforeMount.forEach(fn=>fn());
  const page=new Page();page.oninit({});
  return{page,requests,state,row,plan,timers,app};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function nodes(tree, predicate) {
  const found=[];
  function walk(node){if(!Array.isArray(node))return;if(typeof node[0]==='string'&&predicate(node))found.push(node);for(const item of node)if(Array.isArray(item))walk(item);}
  walk(tree);return found;
}
if (require.main === module) for(const version of [1,2]) {
  test('Flarum '+version+': registers, translates and renders protected actions',async()=>{
    const e=environment(version);await tick();
    let tree=e.page.content();
    assert.equal(nodes(tree,n=>n[0]==='h2.eu-eyebrow')[0][1],'el-choco · Erweiterungen Verwalten');
    assert.equal(nodes(tree,n=>n[0]==='h2').length,0);
    e.page.choose(e.row);tree=e.page.content();
    assert.equal(nodes(tree,n=>n[0]==='label.eu-mode').length,3);
    e.page.choose({...e.row,protected:true});tree=e.page.content();
    assert.equal(nodes(tree,n=>n[0]==='label.eu-mode').length,0);
    assert.ok(JSON.stringify(tree).includes('Dieses Paket ist geschützt'));
  });
  test('Flarum '+version+': preview is explicit and uses a durable request ID',async()=>{
    const e=environment(version);await tick();e.page.choose(e.row);
    await e.page.submit({action:'preview',package:e.row.name,mode:'keep'});await tick();
    const post=e.requests.find(r=>r.method==='POST');
    assert.match(post.body.requestId,/^[a-f0-9]{32}$/);
    assert.equal(post.body.action,'preview');
    assert.equal(e.page.plan.id,e.plan.id);
    const button=nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0];
    assert.equal(button[1].disabled,true);
    e.page.confirmation=e.row.name;
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,true);
    e.page.confirmation='DELETE';
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,false);
  });
  test('Flarum '+version+': purge needs a separate checkbox and DELETE',async()=>{
    const e=environment(version);await tick();e.page.choose(e.row);
    e.page.plan={...e.plan,mode:'purge'};e.page.mode='purge';e.page.confirmation='DELETE';
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,true);
    e.page.purgeConfirmed=true;
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,false);
    e.page.plan.expires=0;
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,true);
  });
  test('Flarum '+version+': disabling uses DISABLE and versions are separate',async()=>{
    const e=environment(version);await tick();e.page.choose(e.row);
    e.page.plan={...e.plan,mode:'disable'};e.page.mode='disable';e.page.confirmation='DELETE';
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,true);
    e.page.confirmation=' DISABLE ';
    assert.equal(nodes(e.page.content(),n=>n[0]==='button.Button.eu-execute')[0][1].disabled,false);
    e.page.plan={...e.plan,mode:'keep'};e.page.mode='keep';
    const tree=e.page.content();
    assert.equal(nodes(tree,n=>n[0]==='span.eu-plan-version')[0][1],'Version: 1.0.0');
    assert.equal(nodes(tree,n=>n[0]==='input.FormControl'&&n[1]?.placeholder==='DELETE').length,1);
  });
  test('Flarum '+version+': request retry retains the same ID and output remains text',async()=>{
    const e=environment(version,'en');await tick();e.state.failPost=true;
    await e.page.submit({action:'preview',package:e.row.name,mode:'keep'});
    const first=e.requests.find(r=>r.method==='POST').body.requestId;
    e.state.failPost=false;await e.page.retry();await tick();
    assert.equal(e.requests.filter(r=>r.method==='POST')[1].body.requestId,first);
    const output=nodes(e.page.content(),n=>n[0]==='pre.eu-output')[0];
    assert.equal(output[2],'<script>alert(1)</script>');
    assert.equal(nodes(e.page.content(),n=>n[0]==='h2.eu-eyebrow')[0][1],'el-choco · Extension Management');
  });
  test('Flarum '+version+': polling resumes jobs without submitting again',async()=>{
    const e=environment(version);await tick();e.state.job={...e.state.job,status:'running',result:null};
    await e.page.watch(e.state.job.id);
    assert.equal(e.page.busy,true);assert.equal(e.timers.length,1);
    assert.equal(e.requests.filter(r=>r.method==='POST').length,0);
    e.state.job={...e.state.job,status:'interrupted'};await e.page.watch(e.state.job.id);
    assert.equal(e.page.busy,false);
    assert.ok(JSON.stringify(e.page.content()).includes('Kein automatischer Wiederholungsversuch'));
  });
}
module.exports={environment,nodes,translations};
