document.addEventListener('DOMContentLoaded', () => {
  const $=id=>document.getElementById(id), box=$('chatBox'), form=$('chatForm'), input=$('messageInput');
  const send=form.querySelector('button'), csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const list=$('conversationList'), empty=$('sidebarEmpty'), sidebar=$('sidebar'), search=$('conversationSearch'), thinkSelect=$('thinkSelect');
  const modelSelect=$('modelSelect'), modelInfoBtn=$('modelInfoBtn'), modelInfoPanel=$('modelInfoPanel');
  const topActions=$('topActions'), mobileMenuToggle=$('mobileMenuToggle');
  let activeId=null, messages=[], lastQuestion=null, controller=null, models=[], modelsById={};
  marked.setOptions({gfm:true,breaks:true});

  const scroll=()=>{box.scrollTop=box.scrollHeight;};
  function protectMath(text){const blocks=[];const save=(latex,display)=>{blocks.push({latex:latex.trim(),display});return `MATHBLOCK${blocks.length-1}ENDBLOCK`;};return {text:text.replace(/\$\$([\s\S]+?)\$\$/g,(_,x)=>save(x,true)).replace(/\\\[([\s\S]+?)\\\]/g,(_,x)=>save(x,true)).replace(/\\\(([\s\S]+?)\\\)/g,(_,x)=>save(x,false)).replace(/(^|[^\\$])\$([^\n$]+?)\$(?!\$)/g,(_,p,x)=>p+save(x,false)),blocks};}
  function render(root=document){root.querySelectorAll('.markdown-content').forEach(el=>{const math=protectMath(el.dataset.raw||'');let html=marked.parse(math.text);math.blocks.forEach((b,i)=>{let out=b.latex;try{out=katex.renderToString(b.latex,{throwOnError:false,displayMode:b.display});}catch(_){}html=html.split(`MATHBLOCK${i}ENDBLOCK`).join(out);});el.innerHTML=html;el.querySelectorAll('pre code').forEach(x=>hljs.highlightElement(x));});}
  function bubble(role,text='',id=null){const w=document.createElement('div');w.className='message-wrapper '+role;if(id)w.dataset.messageId=id;const b=document.createElement('div');b.className='bubble markdown-content';b.dataset.raw=text;w.append(b);if(role==='user'){const a=document.createElement('div');a.className='message-actions';const e=document.createElement('button');e.type='button';e.className='edit-message-btn';e.textContent='✎ Edit pertanyaan';e.disabled=!id;const bind=messageId=>{w.dataset.messageId=messageId;e.disabled=false;e.onclick=()=>editQuestion(messageId,text);};if(id)bind(id);a.append(e);w.append(a);w.bindMessageId=bind;}box.append(w);render(w);scroll();return w;}
  function greeting(){box.innerHTML='';bubble('assistant',`Halo!
Saya **Sigit AI**.
Tanyakan apa saja dan mari kita mulai.`);}
  function typing(){const w=document.createElement('div');w.id='typing';w.className='message-wrapper assistant';w.innerHTML='<div class="bubble"><div class="typing"><span></span><span></span><span></span></div></div>';box.append(w);scroll();}
  const hideTyping=()=>$('typing')?.remove();
  async function get(url){return (await fetch(url,{headers:{'X-CSRF-Token':csrf}})).json();}
  async function post(url,data){return (await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-Token':csrf},body:new URLSearchParams(data)})).json();}
  function generating(on){send.disabled=on;$('regenBtn').disabled=on;$('stopBtn').hidden=!on;}
  const escHtml=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  async function loadModels(){
    try{
      const d=await get('api/models.php');
      models=d.models||[];
      modelsById=Object.fromEntries(models.map(m=>[m.id,m]));
      modelSelect.innerHTML='';
      models.forEach(m=>{const o=document.createElement('option');o.value=m.id;o.textContent=m.label;modelSelect.append(o);});
      const saved=localStorage.getItem('sigit-model');
      const fallback=(d.default_model&&modelsById[d.default_model])?d.default_model:(models[0]&&models[0].id)||'';
      modelSelect.value=(saved&&modelsById[saved])?saved:fallback;
      applyThinkModeForModel(modelSelect.value);
    }catch(_){}
  }

  function applyThinkModeForModel(modelId){
    const m=modelsById[modelId], wrap=thinkSelect.closest('.think-select-wrap');
    if(!m||m.think_mode==='none'){if(wrap)wrap.hidden=true;return;}
    if(wrap)wrap.hidden=false;
    const savedKey='sigit-think-'+modelId;
    if(m.think_mode==='boolean'){
      thinkSelect.innerHTML='<option value="off">Off (hemat token)</option><option value="on">On (lebih teliti)</option>';
      thinkSelect.value=localStorage.getItem(savedKey)||(m.think_default===false?'off':'on');
    }else{
      thinkSelect.innerHTML='<option value="low">Low (hemat token)</option><option value="medium">Medium</option><option value="high">High (paling teliti)</option>';
      thinkSelect.value=localStorage.getItem(savedKey)||m.think_default||'medium';
    }
  }

  const formatCtx=n=>{
    if(!n||n<=0)return null;
    if(n%1048576===0)return (n/1048576)+'M';
    if(n>=1048576)return (n/1048576).toFixed(1).replace(/\.0$/,'')+'M';
    if(n%1024===0)return (n/1024)+'K';
    return Math.round(n/1024)+'K';
  };

  function renderModelInfo(){
    const m=modelsById[modelSelect.value];
    if(!m){modelInfoPanel.hidden=true;return;}
    const ctxLabel=formatCtx(m.context_window);
    const nativeLabel=formatCtx(m.context_window_native);
    const tags=[
      m.think_mode==='level'?'🧠 Thinking: Low / Medium / High':m.think_mode==='boolean'?'🧠 Thinking: On / Off':'🧠 Tanpa mode thinking',
      m.supports_tools?'🛠 Tool aktif (kalkulator & pencarian web)':'🛠 Tanpa tool calling',
    ];
    if(ctxLabel){
      tags.push('📏 Konteks: '+ctxLabel+' token'+((nativeLabel&&nativeLabel!==ctxLabel)?' (kapasitas asli s/d '+nativeLabel+')':''));
    }
    modelInfoPanel.innerHTML=`<h4>${escHtml(m.label)}</h4><p>${escHtml(m.description||'Tidak ada deskripsi untuk model ini.')}</p><div class="model-info-tags">${tags.map(t=>`<span class="model-info-tag">${escHtml(t)}</span>`).join('')}</div>`;
  }
  modelInfoBtn.onclick=()=>{
    if(!modelInfoPanel.hidden){modelInfoPanel.hidden=true;return;}
    renderModelInfo();modelInfoPanel.hidden=false;
  };
  document.addEventListener('click',e=>{
    if(!modelInfoPanel.hidden&&!modelInfoPanel.contains(e.target)&&e.target!==modelInfoBtn)modelInfoPanel.hidden=true;
  });
  modelSelect.onchange=()=>{
    localStorage.setItem('sigit-model',modelSelect.value);
    applyThinkModeForModel(modelSelect.value);
    if(!modelInfoPanel.hidden)renderModelInfo();
  };

  async function loadConversations(select){const data=await get('api/conversations.php?q='+encodeURIComponent(search.value.trim())), rows=data.conversations||[];if(select!==undefined)activeId=select;list.querySelectorAll('.conversation-item').forEach(x=>x.remove());empty.style.display=rows.length?'none':'block';rows.forEach(c=>{const item=document.createElement('div');item.className='conversation-item'+(String(c.id)===String(activeId)?' active':'');item.dataset.id=c.id;const title=document.createElement('span');title.className='conv-title';title.textContent=c.title;item.append(title);const actions=document.createElement('span');actions.className='conv-actions';const rename=document.createElement('button');rename.textContent='Rename';rename.type='button';rename.onclick=async e=>{e.stopPropagation();const t=prompt('Nama baru:',c.title);if(t?.trim()){await post('api/conversations.php',{action:'rename',id:c.id,title:t.trim()});loadConversations(activeId);}};const del=document.createElement('button');del.textContent='Delete';del.type='button';del.onclick=async e=>{e.stopPropagation();if(confirm('Hapus percakapan ini?')){await post('api/conversations.php',{action:'delete',id:c.id});if(String(c.id)===String(activeId))newChat();else loadConversations(activeId);}};actions.append(rename,del);item.append(actions);item.onclick=()=>openConversation(c.id);list.append(item);});return rows;}
  async function openConversation(id){if(controller)return;activeId=id;const d=await get('api/messages.php?conversation_id='+encodeURIComponent(id));messages=d.messages||[];lastQuestion=[...messages].reverse().find(m=>m.role==='user')?.content||null;box.innerHTML='';messages.length?messages.forEach(m=>bubble(m.role,m.content,m.id)):greeting();loadConversations(id);
    if(d.model&&modelsById[d.model]){modelSelect.value=d.model;applyThinkModeForModel(d.model);}
    if(innerWidth<=768)sidebar.classList.add('collapsed');}
  function newChat(){if(controller)return;activeId=null;messages=[];lastQuestion=null;greeting();loadConversations(null);input.focus();}
  function editQuestion(id,old){if(controller)return;const wrap=box.querySelector(`[data-message-id="${id}"]`),bubble=wrap?.querySelector('.bubble');if(!bubble)return;bubble.classList.remove('markdown-content');bubble.innerHTML='';const editor=document.createElement('textarea');editor.className='inline-edit-input';editor.value=old;editor.rows=Math.max(2,old.split('\n').length);const actions=document.createElement('div');actions.className='inline-edit-actions';const cancel=document.createElement('button');cancel.type='button';cancel.className='inline-cancel-btn';cancel.textContent='Batal';cancel.onclick=()=>{bubble.classList.add('markdown-content');bubble.dataset.raw=old;render(wrap);};const save=document.createElement('button');save.type='button';save.className='inline-save-btn';save.textContent='Kirim';save.onclick=()=>{const text=editor.value.trim();if(text)sendMessage(text,{editId:id});};editor.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();save.click();}if(e.key==='Escape')cancel.click();};actions.append(cancel,save);bubble.append(editor,actions);editor.focus();editor.setSelectionRange(editor.value.length,editor.value.length);}

  async function sendMessage(text,opts={}){
    if(controller)return;const regen=!!opts.regen, editId=opts.editId||0;let userWrap=null;
    if(regen){if(messages.at(-1)?.role==='assistant'){messages.pop();box.lastElementChild?.remove();}}
    else if(editId){const i=messages.findIndex(m=>String(m.id)===String(editId));if(i<0)return;messages=messages.slice(0,i);box.innerHTML='';messages.forEach(m=>bubble(m.role,m.content,m.id));messages.push({role:'user',content:text});userWrap=bubble('user',text);}
    else{if(!messages.length)box.innerHTML='';messages.push({role:'user',content:text});userWrap=bubble('user',text);}
    lastQuestion=text;const assistant={role:'assistant',content:''};messages.push(assistant);typing();let wrap=null, content=null, paintTimer=null, lastPaint=0;
    const paint=()=>{if(!content||paintTimer)return;const delay=Math.max(0,500-(performance.now()-lastPaint));paintTimer=setTimeout(()=>{paintTimer=null;lastPaint=performance.now();content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);scroll();},delay);};
    generating(true);controller=new AbortController();
    try{const body={Message:text,think_level:thinkSelect.value,model:modelSelect.value};if(activeId)body.conversation_id=activeId;if(regen)body.regenerate='1';if(editId)body.edit_message_id=editId;const res=await fetch('api/main.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-Token':csrf},body:new URLSearchParams(body),signal:controller.signal});if(!res.ok||!res.body)throw Error('Server tidak dapat memulai jawaban (HTTP '+res.status+')');const reader=res.body.getReader(),decoder=new TextDecoder();let buffer='';const event=block=>{let type='message',data='';block.split(/\r?\n/).forEach(line=>{if(line.startsWith('event:'))type=line.slice(6).trim();if(line.startsWith('data:'))data+=line.slice(5).trim();});if(!data)return;const p=JSON.parse(data);if(type==='meta'){activeId=p.conversation_id;if(userWrap&&p.message_id){userWrap.bindMessageId(p.message_id);messages[messages.length-2].id=p.message_id;}
      if(p.model&&modelsById[p.model]&&modelSelect.value!==p.model)modelSelect.value=p.model;
      }else if(type==='token'){if(!content){hideTyping();wrap=bubble('assistant','');content=wrap.querySelector('.bubble');}assistant.content+=p.text;paint();}else if(type==='error')throw Error(p.message);};while(true){const {done,value}=await reader.read();buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});let pos;while((pos=buffer.indexOf('\n\n'))>=0){event(buffer.slice(0,pos));buffer=buffer.slice(pos+2);}if(done)break;}hideTyping();if(paintTimer){clearTimeout(paintTimer);paintTimer=null;}if(!content){wrap=bubble('assistant','');content=wrap.querySelector('.bubble');}if(!assistant.content)assistant.content='Tidak ada jawaban yang diterima.';content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);await loadConversations(activeId);
    }catch(err){hideTyping();if(err.name==='AbortError'){if(!assistant.content){wrap?.remove();messages.pop();}else if(content){content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);}}else{if(!content){wrap=bubble('assistant','');content=wrap.querySelector('.bubble');}assistant.content='⚠️ '+(err.message||'Server error');content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);}}
    finally{controller=null;generating(false);scroll();}
  }
  form.onsubmit=e=>{e.preventDefault();const text=input.value.trim();if(text&&!controller){input.value='';input.style.height='auto';sendMessage(text);}};input.oninput=()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,200)+'px';};input.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}};
  $('regenBtn').onclick=()=>lastQuestion&&!controller&&sendMessage(lastQuestion,{regen:true});$('stopBtn').onclick=()=>controller?.abort();$('newChatBtn').onclick=newChat;
  $('sidebarToggle').onclick=()=>{sidebar.classList.toggle('collapsed');if(!sidebar.classList.contains('collapsed'))closeMobileMenu();};
  $('clearBtn').onclick=()=>{if(activeId&&!controller&&confirm('Hapus percakapan ini?'))post('api/conversations.php',{action:'delete',id:activeId}).then(newChat);};

  const closeMobileMenu=()=>topActions.classList.remove('open');
  mobileMenuToggle.onclick=()=>{
    topActions.classList.toggle('open');
    if(topActions.classList.contains('open'))sidebar.classList.add('collapsed');
  };
  document.addEventListener('click',e=>{
    if(topActions.classList.contains('open')&&!topActions.contains(e.target)&&e.target!==mobileMenuToggle)closeMobileMenu();
    if(innerWidth<=768&&!sidebar.classList.contains('collapsed')&&!sidebar.contains(e.target)&&e.target!==$('sidebarToggle'))sidebar.classList.add('collapsed');
  });
  addEventListener('resize',()=>{if(innerWidth>768)closeMobileMenu();});
  let timer;search.oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>loadConversations(activeId),250);};$('exportBtn').onclick=()=>{if(!activeId)return;const title=(document.querySelector('.conversation-item.active .conv-title')?.textContent||'chat').replace(/[\\/:*?"<>|]/g,'-');const text='# '+title+'\n\n'+messages.map(m=>'## '+(m.role==='user'?'Anda':'Sigit AI')+'\n\n'+m.content).join('\n\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([text],{type:'text/markdown'}));a.download=title+'.md';a.click();URL.revokeObjectURL(a.href);};
  $('exportPdfBtn').onclick=()=>{
    if(!activeId)return;
    const title=(document.querySelector('.conversation-item.active .conv-title')?.textContent||'chat');
    const sections=[...box.querySelectorAll('.message-wrapper')].map(w=>{
      const isUser=w.classList.contains('user');
      const html=w.querySelector('.bubble')?.innerHTML||'';
      return `<section class="pdf-msg ${isUser?'pdf-user':'pdf-assistant'}"><h3>${isUser?'Anda':'Sigit AI'}</h3><div class="pdf-content">${html}</div></section>`;
    }).join('');
    const esc=s=>s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
    const doc='<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'+esc(title)+'</title>'
      +'<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.9/katex.min.css">'
      +'<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">'
      +'<style>'
      +'body{font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;max-width:800px;margin:24px auto;line-height:1.55;padding:0 16px}'
      +'h1.pdf-title{font-size:20px;margin:0 0 4px}'
      +'.pdf-meta{color:#666;font-size:12px;margin-bottom:24px}'
      +'section.pdf-msg{margin-bottom:22px;page-break-inside:avoid}'
      +'section.pdf-msg h3{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666;margin:0 0 6px;border-bottom:1px solid #ddd;padding-bottom:4px}'
      +'.pdf-user .pdf-content{background:#f5f5f7;border-radius:8px;padding:10px 14px;white-space:pre-wrap}'
      +'.pdf-assistant .pdf-content{padding:0 2px}'
      +'table{border-collapse:collapse;width:100%;margin:10px 0;font-size:13px}'
      +'th,td{border:1px solid #ccc;padding:5px 8px;text-align:left}'
      +'pre{background:#f5f5f7;padding:10px;border-radius:6px;overflow-x:auto;font-size:12px;white-space:pre-wrap}'
      +'code{font-family:Consolas,Menlo,monospace}'
      +'img{max-width:100%}'
      +'</style></head><body>'
      +'<h1 class="pdf-title">'+esc(title)+'</h1>'
      +'<div class="pdf-meta">Diekspor dari Sigit AI &middot; '+esc(new Date().toLocaleString('id-ID'))+'</div>'
      +sections
      +'</body></html>';
    const win=window.open('','_blank');
    if(!win){alert('Popup diblokir browser. Izinkan popup untuk situs ini untuk ekspor PDF.');return;}
    win.document.open();win.document.write(doc);win.document.close();
    const doPrint=()=>{try{win.focus();win.print();}catch(_){}};
    win.onload=doPrint;
    setTimeout(doPrint,500);
  };
  function theme(dark){document.body.classList.toggle('dark-theme',dark);$('themeBtn').textContent=dark?'☀':'☾';localStorage.setItem('sigit-theme',dark?'dark':'light');}$('themeBtn').onclick=()=>theme(!document.body.classList.contains('dark-theme'));theme(localStorage.getItem('sigit-theme')==='dark');
  thinkSelect.onchange=()=>localStorage.setItem('sigit-think-'+modelSelect.value,thinkSelect.value);
  window.onload=async()=>{
    if(innerWidth<=768)sidebar.classList.add('collapsed');
    const [,rows]=await Promise.all([loadModels(),loadConversations()]);
    rows.length?openConversation(rows[0].id):greeting();
    input.focus();
  };
});
