// jsdom only: no real credentials, network, or production data.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/settings/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');
const {AiWorkerPanel}=await import('../src/components/AiWorkerPanel');
const requests:Array<{url:string;method:string;body:any}>=[];
const rows=[
  {id:'gemini',label:'Gemini',model:'gemini-test',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:''},
  {id:'groq',label:'Groq',model:'groq-test',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:''},
  {id:'openrouter',label:'OpenRouter',model:'openrouter/free',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:''},
];
let secret='';
globalThis.fetch=async(input,options={})=>{
  const url=String(input),method=options.method||'GET',body=options.body?JSON.parse(String(options.body)):null;
  requests.push({url,method,body});
  const json=(data:any,status=200)=>new Response(JSON.stringify(data),{status,headers:{'content-type':'application/json'}});
  if(url==='/api/v1/ai/providers/manage')return json({ok:true,schema_ready:true,providers:rows});
  if(url==='/api/v1/ai/article/providers')return json({ok:true,schema_ready:true,providers:rows.map(({id,label,model,available})=>({id,label,model,available}))});
  if(url==='/api/v1/ai/providers/save'){
    const p=rows.find(x=>x.id===body.provider)!;
    if(body.enabled&&!body.free_tier_confirmed)return json({ok:false,error:'سهمیه را تأیید کنید'},422);
    if(body.api_key)secret=body.api_key;
    Object.assign(p,{model:body.model,enabled:body.enabled,free_tier_confirmed:body.free_tier_confirmed,key_configured:!!secret,available:body.enabled&&!!secret,source:'panel',version:p.version+1});
    return json({ok:true,message:'ذخیره شد'});
  }
  if(url==='/api/v1/ai/providers/test')return json({ok:true,message:'اتصال موفق بود'});
  if(url==='/api/v1/ai/providers/remove'){
    const p=rows.find(x=>x.id===body.provider)!;secret='';Object.assign(p,{enabled:false,key_configured:false,available:false,version:p.version+1});
    return json({ok:true,message:'کلید حذف شد'});
  }
  throw new Error('Unexpected request: '+url);
};
const pause=()=>new Promise(r=>setTimeout(r,0));
function card(id:string){const c=document.querySelector(`[aria-label="تنظیمات ${id}"]`);assert(c);return c;}
function button(scope:ParentNode,text:string){const b=[...scope.querySelectorAll('button')].find(x=>x.textContent?.trim()===text);assert(b,'Missing '+text);return b;}
async function click(b:HTMLElement){await act(async()=>{b.click();await pause();});}
async function input(el:HTMLInputElement,value:string){await act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(el,value);el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});}
const root=createRoot(document.getElementById('root')!);
try {
  await act(async()=>{root.render(<AiWorkerPanel csrfToken="fixture-csrf" isAdmin/>);await pause();});
  const gemini=card('Gemini');const field=gemini.querySelector('[aria-label="کلید API Gemini"]') as HTMLInputElement;
  assert.equal(field.type,'password');await input(field,'synthetic-ui-key');
  const checks=[...gemini.querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];assert.equal(checks.length,2);assert(checks[0].checked,'entering a new key enables the service');
  await click(button(gemini,'ذخیره تنظیمات'));assert(gemini.textContent?.includes('سهمیه را تأیید کنید'));
  assert(!requests.some(r=>r.url==='/api/v1/ai/providers/test'));
  await click(checks[1]);dom.window.confirm=()=>false;
  const before=requests.length;await click(button(gemini,'ذخیره و تست اتصال'));assert.equal(requests.length,before,'cancelled test cannot save or call provider');
  dom.window.confirm=()=>true;await click(button(gemini,'ذخیره و تست اتصال'));
  const saved=requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!;assert.equal(saved.body.api_key,'synthetic-ui-key');
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/test').length,1);assert.equal(field.value,'','secret must be cleared after saving');
  assert(gemini.textContent?.includes('اتصال موفق بود'));
  const model=gemini.querySelector('[aria-label="مدل Gemini"]') as HTMLInputElement;await input(model,'new-model');await click(button(gemini,'ذخیره تنظیمات'));
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!.body.api_key,'','blank field preserves key');
  await click(button(gemini,'حذف کلید'));assert(requests.some(r=>r.url==='/api/v1/ai/providers/remove'));assert(!gemini.textContent?.includes('synthetic-ui-key'));
  await act(async()=>{root.render(<AiWorkerPanel csrfToken="fixture-csrf" isAdmin={false}/>);await pause();});
  assert.equal(document.querySelector('[aria-label="کلید API Gemini"]'),null,'editor must not see management controls');
  assert(!requests.some(r=>r.url.includes('/articles')||r.url.includes('/generate')),'settings never publishes or generates content');
  console.log('PASS: admin key input, save/test consent, rotation, removal and editor-only status UI.');
} finally {await act(async()=>root.unmount());dom.window.close();}
