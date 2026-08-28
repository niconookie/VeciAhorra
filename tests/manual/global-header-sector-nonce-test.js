'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm');
const source=fs.readFileSync(path.join(__dirname,'../../assets/frontend/js/global-header.js'),'utf8');
let assertions=0;function ok(v,n){assertions++;if(!v)throw new Error(n);}
class E{constructor(){this.listeners={};this.dataset={};this.hidden=false;this.disabled=false;this.value='';this.textContent='';this.elements={};this.classList={contains:()=>false,remove:()=>{},toggle:()=>{}};}addEventListener(n,f){this.listeners[n]=f;}fire(n){if(this.listeners[n])this.listeners[n]({preventDefault(){},target:this});}setAttribute(){}focus(){}append(){}replaceChildren(){}querySelector(){return null;}contains(){return false;}}
const response=(body,good=true)=>({ok:good,text:()=>Promise.resolve(body)});
async function run(src,nonce,mode='success'){
 const calls=[],errors=[];let reloads=0;
 const menu=new E(),nav=new E(),button=new E(),panel=new E(),select=new E(),message=new E(),scope=new E(),input=new E(),submit=new E(),context=new E(),form=new E();
 panel.hidden=true;scope.value='products';form.elements={search:input};form.dataset={servicesUrl:'/services',productsUrl:'/products',currentCommune:''};form.querySelector=s=>({'[data-va-header-search-scope]':scope,'[type="submit"]':submit,'[data-va-header-search-context]':context}[s]||null);
 const root=new E();root.dataset={restUrl:'https://example.invalid/wp-json/veciahorra/v1/',restNonce:nonce};root.querySelector=s=>({'.va-global-header__menu-toggle':menu,'#va-global-navigation':nav,'.va-global-header__sector':button,'#va-global-sector-panel':panel,'[data-va-header-sector-select]':select,'[data-va-header-sector-message]':message,'.va-global-header__account-toggle':null,'#va-global-account-menu':null,'[data-va-header-search]':form}[s]||null);
 const document={querySelectorAll:()=>[root],querySelector:()=>null,addEventListener:()=>{}};
 const window={location:{search:'',href:'https://example.invalid/',reload:()=>reloads++,assign:()=>{}},setTimeout,fetch:(url,options)=>{calls.push({url,options:JSON.parse(JSON.stringify(options))});if(options.method==='POST'){if(mode==='non_ok')return Promise.resolve(response('{"success":false,"error":{"message":"closed"}}',false));if(mode==='invalid_json')return Promise.resolve(response('{invalid'));return Promise.resolve(response('{"success":true,"data":{"id":17}}'));}return Promise.resolve(response(url.endsWith('/sectors')?'{"success":true,"data":[]}':'{"success":true,"data":null}'));}};
 try{vm.runInNewContext(src,{window,document,Option:function(){},URL,URLSearchParams,console,setTimeout,clearTimeout},{filename:'global-header.js'});}catch(e){errors.push(e);}
 button.fire('click');await new Promise(r=>setTimeout(r,0));select.value='17';select.fire('change');await new Promise(r=>setTimeout(r,0));await new Promise(r=>setTimeout(r,0));return{calls,errors,reloads,disabled:select.disabled};
}
function verify(r,nonce,mode='success'){
 ok(r.errors.length===0,'JS_ERRORS');const g=r.calls.find(c=>c.url.endsWith('/sector/current')),p=r.calls.find(c=>c.options.method==='POST');ok(g&&p,'CALLS');
 ok(g.url==='https://example.invalid/wp-json/veciahorra/v1/sector/current','GET_URL');ok(g.options.method==='GET'&&g.options.credentials==='same-origin','GET_TRANSPORT');ok(g.options.headers.Accept==='application/json','GET_ACCEPT');ok(!Object.hasOwn(g.options.headers,'Content-Type'),'GET_CONTENT_TYPE');
 ok(p.url==='https://example.invalid/wp-json/veciahorra/v1/sector/current/17','POST_URL');ok(p.options.method==='POST'&&p.options.credentials==='same-origin','POST_TRANSPORT');ok(p.options.headers.Accept==='application/json'&&p.options.headers['Content-Type']==='application/json','POST_HEADERS');ok(p.options.body===JSON.stringify({sector_id:'17'}),'POST_BODY');
 if(String(nonce||'').trim()){ok(g.options.headers['X-WP-Nonce']===String(nonce).trim(),'GET_NONCE');ok(p.options.headers['X-WP-Nonce']===String(nonce).trim(),'POST_NONCE');}else{ok(!Object.hasOwn(g.options.headers,'X-WP-Nonce'),'GET_VISITOR_NONCE');ok(!Object.hasOwn(p.options.headers,'X-WP-Nonce'),'POST_VISITOR_NONCE');}
 ok(mode==='success'?r.reloads===1:r.reloads===0&&r.disabled===false,'RESPONSE_BEHAVIOR');
}
function mutation(src,a,b){ok(src.split(a).length===2,'MUTATION_NOT_UNITARY');return src.replace(a,b);}
(async()=>{
 verify(await run(source,' nonce-exact '),'nonce-exact');for(const n of['','   ',null])verify(await run(source,n),n);verify(await run(source,'nonce-exact','non_ok'),'nonce-exact','non_ok');verify(await run(source,'nonce-exact','invalid_json'),'nonce-exact','invalid_json');
 const cases=[["if (restNonce) headers['X-WP-Nonce'] = restNonce;","if (restNonce && method !== 'GET') headers['X-WP-Nonce'] = restNonce;",false],["if (restNonce) headers['X-WP-Nonce'] = restNonce;","if (restNonce && method !== 'POST') headers['X-WP-Nonce'] = restNonce;",false],["if (restNonce) headers['X-WP-Nonce'] = restNonce;","headers['X-WP-Nonce'] = restNonce;",true],["headers['Content-Type'] = 'application/json';",'',false],["if (body !== undefined) {","headers['Content-Type'] = 'application/json';\n            if (body !== undefined) {",false],["credentials:'same-origin'","credentials:'omit'",false],["restOptions('POST', {sector_id: sectorSelect.value})","restOptions('GET', {sector_id: sectorSelect.value})",false],["'sector/current/' + encodeURIComponent(sectorSelect.value)","'sector/changed/' + encodeURIComponent(sectorSelect.value)",false],["{sector_id: sectorSelect.value}","{sector: sectorSelect.value}",false]];
 let rejected=0;for(const[c,d,empty]of cases){try{verify(await run(mutation(source,c,d),empty?'':'nonce-exact'),empty?'':'nonce-exact');}catch(_){rejected++;}}ok(rejected===cases.length,'MUTANTS');
 let old=false;try{verify(await run(source.replace("headers['Content-Type'] = 'application/json';",''),'nonce-exact'),'nonce-exact');}catch(_){old=true;}ok(old,'OLD_CODE');
 console.log('FUNCTIONAL_FETCH_CAPTURE=PASS\nMUTATION_CONTROLS='+rejected+'/9\nOLD_CODE_REJECTED=PASS\nEXTERNAL_REQUESTS=0\nJS_ERRORS=0\nASSERTIONS='+assertions+'\nGLOBAL_HEADER_SECTOR_NONCE=PASS');
})().catch(e=>{console.error('GLOBAL_HEADER_SECTOR_NONCE=FAIL:'+e.message);process.exitCode=1;});
