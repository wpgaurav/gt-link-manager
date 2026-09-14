// Exercise real clipboard handling without reading or changing the user's clipboard.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../assets/js/admin.js', import.meta.url), 'utf8');
for (const mode of ['modern','fallback','denied']) {
 const handlers = {}; const writes = []; let selected = null; let focused = null;
 const prompt = {value:'Read the guide.\n\nAsk only for missing details.',focus(){focused='prompt';},select(){selected=this.value;}};
 const status = {textContent:''}; const button = {dataset:{copied:'Copied',failed:'Failed'},focus(){focused='button';}};
 const document = {
  querySelector(){return null;},querySelectorAll(){return [];},
  getElementById(id){return id==='gtlm-ai-prompt'?prompt:id==='gtlm-ai-prompt-status'?status:null;},
  addEventListener(type,fn){(handlers[type]??=[]).push(fn);},
  createElement(){return {value:'',style:{},setAttribute(){},select(){selected=this.value;}};},
  body:{appendChild(){},removeChild(){}},execCommand(){return mode==='fallback';}
 };
 const clipboard={writeText(text){writes.push(text);return mode==='modern'?Promise.resolve():Promise.reject(new Error('Fixture denial'));}};
 const window={gtlmAdmin:{prefix:'go',i18n:{}},navigator:{clipboard},isSecureContext:true,addEventListener(){},setTimeout};
 vm.runInNewContext(source,{window,document,Promise,URL,setTimeout,clearTimeout});
 const event={target:{closest(selector){return selector==='#gtlm-copy-ai-prompt'?button:null;}},preventDefault(){}};
 for(const fn of handlers.click??[])fn(event);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(writes[0],prompt.value);
 assert.equal(status.textContent,mode==='denied'?'Failed':'Copied');
 assert.equal(focused,mode==='denied'?'prompt':'button');
 if(mode!=='modern')assert.equal(selected,prompt.value);
}
console.log('Clipboard success, fallback and failure handling passed');
