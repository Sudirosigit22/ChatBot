document.addEventListener('DOMContentLoaded', () => {
  const $=id=>document.getElementById(id), box=$('chatBox'), form=$('chatForm'), input=$('messageInput');
  const send=form.querySelector('button'), csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const list=$('conversationList'), empty=$('sidebarEmpty'), sidebar=$('sidebar'), search=$('conversationSearch'), thinkSelect=$('thinkSelect'), responseDepthSelect=$('responseDepthSelect');
  const modelSelect=$('modelSelect'), modelInfoBtn=$('modelInfoBtn'), modelInfoPanel=$('modelInfoPanel');
  const topActions=$('topActions'), mobileMenuToggle=$('mobileMenuToggle');
  let activeId=null, messages=[], lastQuestion=null, controller=null, models=[], modelsById={};
  marked.setOptions({gfm:true,breaks:true});

  const fileInput=$('fileInput'), attachBtn=$('attachBtn'), attachmentsBar=$('attachmentsBar');
  const MAX_FILES=5;
  const MAX_FILE_BYTES=8*1024*1024;
  const MAX_CHARS_PER_FILE=100000;
  const MAX_IMAGES=4;
  const MAX_IMAGE_BYTES=5*1024*1024;
  const TEXTY_EXT=['txt','md','markdown','csv','tsv','json','log','xml','html','htm','css','js','mjs','ts','py','java','c','cpp','h','hpp','cs','php','rb','go','rs','sql','yaml','yml','ini','conf','cfg','sh','bat','srt','vtt','tex'];
  const OFFICE_EXT=['pdf','docx','doc','xlsx','xls','xlsm','ods'];
  const IMAGE_EXT=['png','jpg','jpeg','webp','gif'];
  const ACCEPT_DOCS='.txt,.md,.markdown,.csv,.tsv,.json,.log,.xml,.html,.htm,.css,.js,.mjs,.ts,.py,.java,.c,.cpp,.h,.hpp,.cs,.php,.rb,.go,.rs,.sql,.yaml,.yml,.ini,.conf,.cfg,.sh,.bat,.srt,.vtt,.tex,.pdf,.docx,.doc,.xlsx,.xls,.xlsm,.ods';
  const ACCEPT_IMAGES=',image/png,image/jpeg,image/jpg,image/webp,image/gif';
  let pendingAttachments=[];
  const genId=()=>(crypto.randomUUID?crypto.randomUUID():'id-'+Date.now()+'-'+Math.random().toString(16).slice(2));

  let toastTimer=null;
  function showToast(message, type='info'){
    let el=document.getElementById('appToast');
    if(!el){
      el=document.createElement('div');
      el.id='appToast';
      el.setAttribute('role','status');
      el.setAttribute('aria-live','polite');
      document.body.appendChild(el);
    }
    el.className='app-toast app-toast-'+type+' app-toast-show';
    el.textContent=message;
    if(toastTimer)clearTimeout(toastTimer);
    toastTimer=setTimeout(()=>{el.classList.remove('app-toast-show');},4200);
  }

  const formatBytes=n=>{
    if(n<1024)return n+' B';
    if(n<1024*1024)return (n/1024).toFixed(1).replace(/\.0$/,'')+' KB';
    return (n/(1024*1024)).toFixed(1).replace(/\.0$/,'')+' MB';
  };
  const extOf=name=>{const m=/\.([a-z0-9]+)$/i.exec(name||'');return m?m[1].toLowerCase():'';};
  const currentModelSupportsVision=()=>{
    const m=modelsById[modelSelect.value];
    return !!(m&&m.supports_vision);
  };

  function looksLikeBinary(text){
    if(!text)return false;
    const sample=text.slice(0,4000);
    let bad=0;
    for(let i=0;i<sample.length;i++){
      const c=sample.charCodeAt(i);
      if(c===0xFFFD)bad++;
      else if(c<9&&c!==0)bad++;
    }
    return bad>Math.max(5,sample.length*0.01);
  }

  function readFileAsText(file){
    return new Promise((resolve,reject)=>{
      const reader=new FileReader();
      reader.onload=()=>resolve(String(reader.result||''));
      reader.onerror=()=>reject(reader.error||new Error('Gagal membaca file'));
      reader.readAsText(file);
    });
  }
  function readFileAsArrayBuffer(file){
    return new Promise((resolve,reject)=>{
      const reader=new FileReader();
      reader.onload=()=>resolve(reader.result);
      reader.onerror=()=>reject(reader.error||new Error('Gagal membaca file'));
      reader.readAsArrayBuffer(file);
    });
  }
  function readFileAsDataURL(file){
    return new Promise((resolve,reject)=>{
      const reader=new FileReader();
      reader.onload=()=>resolve(String(reader.result||''));
      reader.onerror=()=>reject(reader.error||new Error('Gagal membaca file'));
      reader.readAsDataURL(file);
    });
  }

  async function extractPdfText(file){
    if(!window.pdfjsLib)throw new Error('PDF.js belum dimuat');
    const buf=await readFileAsArrayBuffer(file);
    const pdf=await pdfjsLib.getDocument({data:buf}).promise;
    const parts=[];
    const maxPages=Math.min(pdf.numPages,40);
    for(let i=1;i<=maxPages;i++){
      const page=await pdf.getPage(i);
      const content=await page.getTextContent();
      const pageText=content.items.map(it=>it.str||'').join(' ');
      parts.push(`--- Halaman ${i} ---\n${pageText}`);
    }
    if(pdf.numPages>maxPages)parts.push(`\n[... ${pdf.numPages-maxPages} halaman berikutnya tidak diekstrak ...]`);
    return parts.join('\n\n');
  }

  async function extractDocxText(file){
    if(!window.mammoth)throw new Error('Mammoth.js belum dimuat');
    const buf=await readFileAsArrayBuffer(file);
    const result=await mammoth.extractRawText({arrayBuffer:buf});
    return String(result.value||'').trim();
  }

  async function extractExcelText(file){
    if(!window.XLSX)throw new Error('SheetJS belum dimuat');
    const buf=await readFileAsArrayBuffer(file);
    const wb=XLSX.read(buf,{type:'array',cellDates:true});
    const parts=[];
    const sheetNames=wb.SheetNames.slice(0,8);
    for(const name of sheetNames){
      const sheet=wb.Sheets[name];
      if(!sheet)continue;
      const csv=XLSX.utils.sheet_to_csv(sheet,{FS:'\t',blankrows:false});
      parts.push(`--- Sheet: ${name} ---\n${csv}`);
    }
    if(wb.SheetNames.length>sheetNames.length){
      parts.push(`\n[... ${wb.SheetNames.length-sheetNames.length} sheet lain tidak diekstrak ...]`);
    }
    return parts.join('\n\n');
  }

  function renderAttachments(){
    attachmentsBar.innerHTML='';
    attachmentsBar.hidden=pendingAttachments.length===0;
    pendingAttachments.forEach(a=>{
      const chip=document.createElement('span');
      chip.className='attachment-chip'+(a.kind==='image'?' is-image':'');
      const icon=a.kind==='image'?'🖼️':(a.ext==='pdf'?'📕':(a.ext==='docx'||a.ext==='doc'?'📘':(a.ext==='xlsx'||a.ext==='xls'||a.ext==='xlsm'||a.ext==='ods'?'📗':'📄')));
      chip.title=a.kind==='image'?a.name:(a.truncated?`${a.name} (dipotong ke ${MAX_CHARS_PER_FILE.toLocaleString('id-ID')} karakter pertama)`:a.name);
      chip.innerHTML=`<span class="chip-icon">${icon}</span><span class="chip-name"></span><span class="chip-size"></span>`;
      chip.querySelector('.chip-name').textContent=a.name;
      chip.querySelector('.chip-size').textContent=formatBytes(a.size)+(a.truncated?' · dipotong':'');
      if(a.kind==='image'&&a.dataUrl){
        const thumb=document.createElement('img');
        thumb.src=a.dataUrl;thumb.alt='';thumb.className='chip-thumb';
        chip.insertBefore(thumb,chip.firstChild);
      }
      const rm=document.createElement('button');
      rm.type='button';rm.className='chip-remove';rm.setAttribute('aria-label','Hapus lampiran '+a.name);rm.textContent='✕';
      rm.onclick=()=>{pendingAttachments=pendingAttachments.filter(x=>x.id!==a.id);renderAttachments();};
      chip.append(rm);
      attachmentsBar.append(chip);
    });
  }

  async function addFiles(fileList){
    const files=[...fileList];
    const visionOk=currentModelSupportsVision();
    for(const file of files){
      if(pendingAttachments.length>=MAX_FILES){showToast('Maksimal '+MAX_FILES+' file lampiran per pesan.','warn');break;}
      const ext=extOf(file.name);
      const isImage=IMAGE_EXT.includes(ext)||(file.type||'').startsWith('image/');
      const isOffice=OFFICE_EXT.includes(ext);
      const isText=TEXTY_EXT.includes(ext);

      if(isImage){
        if(!visionOk){
          showToast('Upload gambar hanya tersedia saat model Gemma 4 (vision) dipilih.','warn');
          continue;
        }
        const imageCount=pendingAttachments.filter(a=>a.kind==='image').length;
        if(imageCount>=MAX_IMAGES){showToast('Maksimal '+MAX_IMAGES+' gambar per pesan.','warn');continue;}
        if(file.size>MAX_IMAGE_BYTES){showToast('"'+file.name+'" dilewati: ukuran gambar melebihi '+formatBytes(MAX_IMAGE_BYTES)+'.','warn');continue;}
        let dataUrl;
        try{dataUrl=await readFileAsDataURL(file);}catch(_){showToast('Gagal membaca gambar "'+file.name+'".','error');continue;}
        pendingAttachments.push({id:genId(),name:file.name,size:file.size,kind:'image',ext,dataUrl});
        continue;
      }

      if(!isText&&!isOffice){
        showToast('"'+file.name+'" dilewati: format tidak didukung. Didukung: teks, PDF, DOCX, Excel'+(visionOk?', dan gambar (PNG/JPG/WebP/GIF)':'')+'.','warn');
        continue;
      }
      if(file.size>MAX_FILE_BYTES){showToast('"'+file.name+'" dilewati: ukuran melebihi '+formatBytes(MAX_FILE_BYTES)+'.','warn');continue;}

      let text='';
      try{
        if(ext==='pdf'){
          text=await extractPdfText(file);
        }else if(ext==='docx'||ext==='doc'){
          text=await extractDocxText(file);
        }else if(ext==='xlsx'||ext==='xls'||ext==='xlsm'||ext==='ods'){
          text=await extractExcelText(file);
        }else{
          text=await readFileAsText(file);
          if(looksLikeBinary(text)){showToast('"'+file.name+'" tampak bukan file teks murni dan dilewati.','warn');continue;}
        }
      }catch(err){
        showToast('Gagal mengekstrak "'+file.name+'": '+(err&&err.message?err.message:'error tidak diketahui'),'error');
        continue;
      }
      text=String(text||'').trim();
      if(!text){showToast('"'+file.name+'" tidak berisi teks yang bisa diekstrak.','warn');continue;}
      const truncated=text.length>MAX_CHARS_PER_FILE;
      pendingAttachments.push({
        id:genId(),
        name:file.name,
        size:file.size,
        kind:'text',
        ext,
        content:truncated?text.slice(0,MAX_CHARS_PER_FILE):text,
        originalLength:text.length,
        truncated,
      });
    }
    renderAttachments();
  }

  function clearAttachments(){pendingAttachments=[];renderAttachments();}

  function buildMessageWithAttachments(text){
    const docs=pendingAttachments.filter(a=>a.kind==='text');
    if(!docs.length)return text;
    const fence='````';
    const blocks=docs.map(a=>{
      const note=a.truncated?`\n[...dipotong: menampilkan ${a.content.length.toLocaleString('id-ID')} dari ${a.originalLength.toLocaleString('id-ID')} karakter total...]`:'';
      return `📎 **Dokumen terlampir: ${a.name}** (${formatBytes(a.size)})\n${fence}\n${a.content}${note}\n${fence}`;
    }).join('\n\n');
    return text ? `${blocks}\n\n${text}` : `${blocks}\n\nTolong pelajari dan analisa dokumen di atas.`;
  }

  function buildDisplayText(userTyped){
    const docs=pendingAttachments.filter(a=>a.kind==='text');
    const imgs=pendingAttachments.filter(a=>a.kind==='image');
    const parts=[];
    if(userTyped)parts.push(userTyped);
    if(docs.length){
      const names=docs.map(a=>a.name).join(', ');
      parts.push(`📎 ${docs.length} dokumen terlampir: ${names}`);
    }
    if(imgs.length){
      parts.push(`🖼️ ${imgs.length} gambar terlampir`);
    }
    return parts.join('\n\n')||'(lampiran)';
  }

  function getPendingImages(){
    return pendingAttachments.filter(a=>a.kind==='image').map(a=>a.dataUrl).filter(Boolean);
  }

  function updateAttachButtonState(){
    const vision=currentModelSupportsVision();
    attachBtn.title=vision
      ?'Lampirkan dokumen (PDF/DOCX/Excel/teks) atau gambar'
      :'Lampirkan dokumen (PDF/DOCX/Excel/teks). Pilih Gemma 4 untuk upload gambar.';
    if(fileInput){
      fileInput.accept=vision ? (ACCEPT_DOCS+ACCEPT_IMAGES) : ACCEPT_DOCS;
    }
    if(!vision){
      const before=pendingAttachments.length;
      pendingAttachments=pendingAttachments.filter(a=>a.kind!=='image');
      if(pendingAttachments.length!==before){
        renderAttachments();
        showToast('Gambar dihapus dari lampiran karena model saat ini tidak mendukung vision.','info');
      }
    }
  }

  attachBtn.onclick=()=>{if(!controller)fileInput.click();};
  fileInput.onchange=async()=>{
    if(fileInput.files&&fileInput.files.length)await addFiles(fileInput.files);
    fileInput.value='';
  };

  const dropZone=form.closest('.input-container')||form;
  ['dragenter','dragover'].forEach(evt=>dropZone.addEventListener(evt,e=>{
    if(controller)return;
    e.preventDefault();e.stopPropagation();dropZone.classList.add('drag-over');
  }));
  ['dragleave','drop'].forEach(evt=>dropZone.addEventListener(evt,e=>{
    e.preventDefault();e.stopPropagation();dropZone.classList.remove('drag-over');
  }));
  dropZone.addEventListener('drop',async e=>{
    if(controller)return;
    const files=e.dataTransfer?.files;
    if(files&&files.length)await addFiles(files);
  });

  const pasteTarget = input;
  pasteTarget.addEventListener('paste', async (e) => {
    if (controller) return;

    const items = e.clipboardData?.items;
    const filesFromItems = [];
    if (items) {
      for (const item of items) {
        if (item.kind === 'file') {
          const file = item.getAsFile();
          if (file) filesFromItems.push(file);
        }
      }
    }

    const filesFromList = e.clipboardData?.files ? [...e.clipboardData.files] : [];
    const files = filesFromItems.length ? filesFromItems : filesFromList;

    if (!files.length) return;

    e.preventDefault();
    await addFiles(files);
  });

  const STORE_KEY='sigit-chat-store-v1';
  function loadStore(){try{const raw=localStorage.getItem(STORE_KEY);const d=raw?JSON.parse(raw):{};return (d&&typeof d==='object')?d:{};}catch(_){return {};}}
  function saveStore(store){try{localStorage.setItem(STORE_KEY,JSON.stringify(store));}catch(_){}}
  function getConv(id){return id?loadStore()[id]:null;}
  function upsertConv(conv){const s=loadStore();s[conv.id]=conv;saveStore(s);}
  function removeConv(id){const s=loadStore();delete s[id];saveStore(s);}
  function listConvs(q){
    const s=loadStore();
    let rows=Object.values(s);
    if(q){const needle=q.toLowerCase();rows=rows.filter(c=>c.title.toLowerCase().includes(needle)||c.messages.some(m=>(m.content||'').toLowerCase().includes(needle)));}
    rows.sort((a,b)=>(b.updated_at||'').localeCompare(a.updated_at||''));
    return rows.map(c=>({id:c.id,title:c.title,model:c.model,created_at:c.created_at,updated_at:c.updated_at}));
  }
  function makeTitleFromMessage(text){
    const t=(text||'').replace(/\s+/g,' ').trim();
    if(!t)return 'Percakapan Baru';
    return t.length>45?t.slice(0,45)+'…':t;
  }
  function persistConversation(){
    if(!activeId)return;
    const now=new Date().toISOString();
    const existing=getConv(activeId);
    upsertConv({
      id:activeId,
      title: existing?.title || makeTitleFromMessage(messages.find(m=>m.role==='user')?.content||''),
      model: modelSelect.value,
      created_at: existing?.created_at || now,
      updated_at: now,
      messages: messages.map(m=>({id:m.id,role:m.role,content:m.content})),
    });
  }

  const scroll=()=>{box.scrollTop=box.scrollHeight;};
  async function copyToClipboard(text,btn,idleLabel){
    let ok=true;
    try{
      if(navigator.clipboard&&window.isSecureContext){
        await navigator.clipboard.writeText(text);
      }else{
        const ta=document.createElement('textarea');
        ta.value=text;ta.style.position='fixed';ta.style.opacity='0';ta.style.top='0';ta.style.left='0';
        document.body.append(ta);ta.focus();ta.select();
        try{ok=document.execCommand('copy');}catch(_){ok=false;}
        ta.remove();
      }
    }catch(_){ok=false;}
    if(btn){
      btn.textContent=ok?'✓ Disalin':'⚠️ Gagal';
      btn.disabled=true;
      clearTimeout(btn._copyResetTimer);
      btn._copyResetTimer=setTimeout(()=>{btn.textContent=idleLabel;btn.disabled=false;},1400);
    }
    return ok;
  }
  function addCodeCopyButtons(el){
    const stillGenerating=el.closest('.message-wrapper')?.dataset.generating==='1';
    el.querySelectorAll('pre').forEach(pre=>{
      const codeEl=pre.querySelector('code');
      const wrap=document.createElement('div');
      wrap.className='code-wrap';
      pre.replaceWith(wrap);
      wrap.append(pre);
      const btn=document.createElement('button');
      btn.type='button';
      btn.className='copy-btn';
      btn.textContent='⧉ Copy';
      btn.disabled=stillGenerating;
      btn.onclick=e=>{e.stopPropagation();copyToClipboard(codeEl?codeEl.innerText:pre.innerText,btn,'⧉ Copy');};
      wrap.append(btn);
    });
  }
  function protectMath(text){const blocks=[];const save=(latex,display)=>{blocks.push({latex:latex.trim(),display});return `MATHBLOCK${blocks.length-1}ENDBLOCK`;};return {text:text.replace(/\$\$([\s\S]+?)\$\$/g,(_,x)=>save(x,true)).replace(/\\\[([\s\S]+?)\\\]/g,(_,x)=>save(x,true)).replace(/\\\(([\s\S]+?)\\\)/g,(_,x)=>save(x,false)).replace(/(^|[^\\$])\$([^\n$]+?)\$(?!\$)/g,(_,p,x)=>p+save(x,false)),blocks};}
  function render(root=document){root.querySelectorAll('.markdown-content').forEach(el=>{const math=protectMath(el.dataset.raw||'');let html=marked.parse(math.text);math.blocks.forEach((b,i)=>{let out=b.latex;try{out=katex.renderToString(b.latex,{throwOnError:false,displayMode:b.display});}catch(_){}html=html.split(`MATHBLOCK${i}ENDBLOCK`).join(out);});el.innerHTML=html;el.querySelectorAll('pre code').forEach(x=>hljs.highlightElement(x));addCodeCopyButtons(el);});}
  function bubble(role,text='',id=null,opts={}){const w=document.createElement('div');w.className='message-wrapper '+role;if(id)w.dataset.messageId=id;if(opts.generating)w.dataset.generating='1';const b=document.createElement('div');b.className='bubble markdown-content';b.dataset.raw=text;w.append(b);if(!opts.skipActions){const a=document.createElement('div');a.className='message-actions';const copyBtn=document.createElement('button');copyBtn.type='button';copyBtn.className='copy-message-btn';copyBtn.textContent='⧉ Copy';copyBtn.disabled=!!opts.generating;copyBtn.onclick=()=>copyToClipboard(b.dataset.raw||'',copyBtn,'⧉ Copy');w.copyBtn=copyBtn;if(role==='user'){const e=document.createElement('button');e.type='button';e.className='edit-message-btn';e.textContent='✎ Edit pertanyaan';e.disabled=!id;const bind=messageId=>{w.dataset.messageId=messageId;e.disabled=false;e.onclick=()=>editQuestion(messageId,text);};if(id)bind(id);a.append(e,copyBtn);w.bindMessageId=bind;}else{a.append(copyBtn);}w.append(a);}box.append(w);render(w);scroll();return w;}
  // Badge kecil "jenis pertanyaan terdeteksi", diisi dari hasil classifier
  // Naive Bayes (includes/ml/) yang dikirim server lewat event SSE 'meta'.
  // Hanya dirender bila model tersedia (intent.available===true); kalau
  // model belum dilatih, badge tidak ditampilkan sama sekali (silent fallback).
  const INTENT_META={matematika:['🧮','Matematika'],pencarian_web:['🌐','Pencarian Web'],umum:['💬','Umum']};
  function intentBadge(intent){
    if(!intent||!intent.available)return null;
    const meta=INTENT_META[intent.label]||['🤖',intent.label];
    const pct=(intent.confidence!=null&&!isNaN(intent.confidence))?Math.round(intent.confidence*100)+'%':null;
    const el=document.createElement('div');
    el.className='intent-badge';
    el.textContent=meta[0]+' Terdeteksi: '+meta[1]+(pct?' ('+pct+')':'');
    el.title='Klasifikasi otomatis oleh model Naive Bayes internal (membantu agen memutuskan tool mana yang perlu dipakai).';
    return el;
  }
  function attachIntentBadge(wrap,intent){
    if(!wrap)return;
    const badge=intentBadge(intent);
    if(badge)wrap.insertBefore(badge,wrap.firstChild);
  }
  function greeting(){box.innerHTML='';bubble('assistant',`Halo!
Saya **Sigit AI**.
Tanyakan apa saja dan mari kita mulai.`,null,{skipActions:true});}
  function typing(){const w=document.createElement('div');w.id='typing';w.className='message-wrapper assistant';w.innerHTML='<div class="bubble"><div class="typing"><span></span><span></span><span></span></div></div>';box.append(w);scroll();}
  const hideTyping=()=>$('typing')?.remove();
  async function get(url){return (await fetch(url,{headers:{'X-CSRF-Token':csrf}})).json();}
  function generating(on){send.disabled=on;$('regenBtn').disabled=on;$('stopBtn').hidden=!on;attachBtn.disabled=on;}
  const escHtml=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  async function loadModels(){
    try{
      const d=await get('api/models.php');
      models=d.models||[];
      modelsById=Object.fromEntries(models.map(m=>[m.id,m]));
      modelSelect.innerHTML='';
      models.forEach(m=>{const o=document.createElement('option');o.value=m.id;o.textContent=m.label;modelSelect.append(o);});
      const saved=sessionStorage.getItem('sigit-model');
      const fallback=(d.default_model&&modelsById[d.default_model])?d.default_model:(models[0]&&models[0].id)||'';
      modelSelect.value=(saved&&modelsById[saved])?saved:fallback;
      applyThinkModeForModel(modelSelect.value);
      updateAttachButtonState();
      updateActiveKeyTag(d);
    }catch(_){}
  }

  function updateActiveKeyTag(data){
    const tag=$('activeKeyTag');
    if(!tag)return;
    const name=(data&&data.active_key_name)||'';
    const hasKey=!!(data&&data.has_api_key);
    if(!hasKey){
      tag.hidden=false;
      tag.textContent='🔑 No key';
      tag.classList.add('key-tag-warn');
      tag.title='Belum ada API key yang dikonfigurasi. Edit secrets.php atau set OLLAMA_API_KEY.';
      return;
    }
    tag.classList.remove('key-tag-warn');
    const label=name==='(env)'?'🔑 env':(name?`🔑 ${name}`:'🔑 key');
    tag.textContent=label;
    tag.hidden=false;
    const available=(data&&data.available_keys)||[];
    tag.title=available.length?`API key aktif: ${name}`:`API key aktif: ${name}`;
  }

  function applyThinkModeForModel(modelId){
    const m=modelsById[modelId], wrap=thinkSelect.closest('.think-select-wrap');
    if(!m||m.think_mode==='none'){if(wrap)wrap.hidden=true;return;}
    if(wrap)wrap.hidden=false;
    const savedKey='sigit-think-'+modelId;
    if(m.think_mode==='boolean'){
      thinkSelect.innerHTML='<option value="off">Off (hemat token)</option><option value="on">On (lebih teliti)</option>';
      thinkSelect.value=sessionStorage.getItem(savedKey)||(m.think_default===false?'off':'on');
    }else{
      thinkSelect.innerHTML='<option value="low">Low (hemat token)</option><option value="medium">Medium</option><option value="high">High (paling teliti)</option>';
      thinkSelect.value=sessionStorage.getItem(savedKey)||m.think_default||'medium';
    }
  }

  const formatCtx=n=>{
    if(!n||n<=0)return null;
    if(n%1048576===0)return (n/1048576)+'.000.000';
    if(n>=1048576)return (n/1048576).toFixed(1).replace(/\.0$/,'')+'.000.000';
    if(n%1024===0)return (n/1024)+'.000';
    return Math.round(n/1024)+'.000';
  };

  function renderModelInfo(){
    const m=modelsById[modelSelect.value];
    if(!m){modelInfoPanel.hidden=true;return;}
    const ctxLabel=formatCtx(m.context_window);
    const nativeLabel=formatCtx(m.context_window_native);
    const tags=[
      m.think_mode==='level'?'🧠 Thinking: Low / Medium / High':m.think_mode==='boolean'?'🧠 Thinking: On / Off':'🧠 Tanpa mode thinking',
      m.supports_tools?'🛠 Tool aktif':'🛠 Tanpa tool calling',
      '📄 Support Teks & dokumen',
    ];
    if(m.supports_vision){
      tags.push('👁️ Support Vision (analisis gambar)')
    };
    if(ctxLabel){
      tags.push('📏 Konteks: '+ctxLabel+((nativeLabel&&nativeLabel!==ctxLabel)?' s/d '+nativeLabel+' token':' token'));
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
    sessionStorage.setItem('sigit-model',modelSelect.value);
    applyThinkModeForModel(modelSelect.value);
    updateAttachButtonState();
    if(!modelInfoPanel.hidden)renderModelInfo();
  };

  function loadConversations(select){
    const rows=listConvs(search.value.trim());
    if(select!==undefined)activeId=select;
    list.querySelectorAll('.conversation-item').forEach(x=>x.remove());
    empty.style.display=rows.length?'none':'block';
    rows.forEach(c=>{
      const item=document.createElement('div');
      item.className='conversation-item'+(String(c.id)===String(activeId)?' active':'');
      item.dataset.id=c.id;
      const title=document.createElement('span');title.className='conv-title';title.textContent=c.title;item.append(title);
      const actions=document.createElement('span');actions.className='conv-actions';
      const rename=document.createElement('button');rename.textContent='Rename';rename.type='button';
      rename.onclick=e=>{e.stopPropagation();const t=prompt('Nama baru:',c.title);if(t?.trim()){const conv=getConv(c.id);if(conv){conv.title=t.trim().slice(0,80);upsertConv(conv);}loadConversations(activeId);}};
      const del=document.createElement('button');del.textContent='Delete';del.type='button';
      del.onclick=e=>{e.stopPropagation();if(confirm('Hapus percakapan ini?')){removeConv(c.id);if(String(c.id)===String(activeId))newChat();else loadConversations(activeId);}};
      actions.append(rename,del);item.append(actions);
      item.onclick=()=>openConversation(c.id);
      list.append(item);
    });
    return rows;
  }
  function openConversation(id){
    if(controller)return;
    const conv=getConv(id);
    if(!conv){newChat();return;}
    activeId=id;
    messages=(conv.messages||[]).map(m=>({id:m.id,role:m.role,content:m.content}));
    lastQuestion=[...messages].reverse().find(m=>m.role==='user')?.content||null;
    box.innerHTML='';
    messages.length?messages.forEach(m=>bubble(m.role,m.content,m.id)):greeting();
    loadConversations(id);
    if(conv.model&&modelsById[conv.model]){modelSelect.value=conv.model;applyThinkModeForModel(conv.model);}
    if(innerWidth<=768)sidebar.classList.add('collapsed');
  }
  function newChat(){if(controller)return;activeId=null;messages=[];lastQuestion=null;greeting();loadConversations(null);input.focus();}

  function editQuestion(id,old){if(controller)return;const wrap=box.querySelector(`[data-message-id="${id}"]`),bubble=wrap?.querySelector('.bubble');if(!bubble)return;bubble.classList.remove('markdown-content');bubble.innerHTML='';const editor=document.createElement('textarea');editor.className='inline-edit-input';editor.value=old;editor.rows=Math.max(2,old.split('\n').length);const actions=document.createElement('div');actions.className='inline-edit-actions';const cancel=document.createElement('button');cancel.type='button';cancel.className='inline-cancel-btn';cancel.textContent='Batal';cancel.onclick=()=>{bubble.classList.add('markdown-content');bubble.dataset.raw=old;render(wrap);};const save=document.createElement('button');save.type='button';save.className='inline-save-btn';save.textContent='Kirim';save.onclick=()=>{const text=editor.value.trim();if(text)sendMessage(text,{editId:id});};editor.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();save.click();}if(e.key==='Escape')cancel.click();};actions.append(cancel,save);bubble.append(editor,actions);editor.focus();editor.setSelectionRange(editor.value.length,editor.value.length);}

  async function sendMessage(text,opts={}){
    if(controller)return;const regen=!!opts.regen, editId=opts.editId||0;let userWrap=null;
    const displayText=opts.displayText!=null?opts.displayText:(text||'');
    const apiContent=opts.apiContent!=null?opts.apiContent:text;
    const imagesForSend=(!regen&&!editId&&currentModelSupportsVision())?getPendingImages():[];
    if(regen){if(messages.at(-1)?.role==='assistant'){messages.pop();box.lastElementChild?.remove();}}
    else if(editId){const i=messages.findIndex(m=>String(m.id)===String(editId));if(i<0)return;messages=messages.slice(0,i);box.innerHTML='';messages.forEach(m=>bubble(m.role,m.content,m.id));const edited={id:genId(),role:'user',content:text};messages.push(edited);userWrap=bubble('user',text,messages.at(-1).id);}
    else{
      if(!messages.length)box.innerHTML='';
      const uiText=displayText||(imagesForSend.length?'(gambar terlampir)':'');
      const userMsg={id:genId(),role:'user',content:uiText};
      if(imagesForSend.length)userMsg.images=imagesForSend;
      if(apiContent&&apiContent!==uiText)userMsg._apiContent=apiContent;
      messages.push(userMsg);
      userWrap=bubble('user',uiText,messages.at(-1).id);
    }
    lastQuestion=apiContent||displayText;const isNewConversation=!activeId;if(isNewConversation)activeId=genId();
    const assistant={id:genId(),role:'assistant',content:''};messages.push(assistant);typing();let wrap=null, content=null, paintTimer=null, lastPaint=0, pendingIntent=null;
    const paint=()=>{if(!content||paintTimer)return;const delay=Math.max(0,500-(performance.now()-lastPaint));paintTimer=setTimeout(()=>{paintTimer=null;lastPaint=performance.now();content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);scroll();},delay);};
    generating(true);controller=new AbortController();
    const history=messages.slice(0,-1).map((m,idx,arr)=>{
      const entry={role:m.role,content:(m._apiContent!=null?m._apiContent:m.content)};
      if(idx===arr.length-1&&m.role==='user'&&m.images&&m.images.length)entry.images=m.images;
      return entry;
    });
    try{
      const body={history:JSON.stringify(history),conversation_id:activeId,think_level:thinkSelect.value,model:modelSelect.value,response_depth:responseDepthSelect?.value||'adaptive'};
      if(isNewConversation)body.need_title='1';
      const res=await fetch('api/main.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-Token':csrf},body:new URLSearchParams(body),signal:controller.signal});
      if(!res.ok||!res.body)throw Error('Server tidak dapat memulai jawaban (HTTP '+res.status+')');
      const reader=res.body.getReader(),decoder=new TextDecoder();let buffer='',doneTitle=null;
      const event=block=>{let type='message',data='';block.split(/\r?\n/).forEach(line=>{if(line.startsWith('event:'))type=line.slice(6).trim();if(line.startsWith('data:'))data+=line.slice(5).trim();});if(!data)return;const p=JSON.parse(data);if(type==='meta'){activeId=p.conversation_id||activeId;
        if(p.model&&modelsById[p.model]&&modelSelect.value!==p.model)modelSelect.value=p.model;
        pendingIntent=p.intent||null;
        }else if(type==='token'){if(!content){hideTyping();wrap=bubble('assistant','',null,{generating:true});attachIntentBadge(wrap,pendingIntent);content=wrap.querySelector('.bubble');}assistant.content+=p.text;paint();}else if(type==='done'){doneTitle=p.title||null;}else if(type==='error')throw Error(p.message);};
      while(true){const {done,value}=await reader.read();buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});let pos;while((pos=buffer.indexOf('\n\n'))>=0){event(buffer.slice(0,pos));buffer=buffer.slice(pos+2);}if(done)break;}
      hideTyping();if(paintTimer){clearTimeout(paintTimer);paintTimer=null;}if(!content){wrap=bubble('assistant','');attachIntentBadge(wrap,pendingIntent);content=wrap.querySelector('.bubble');}if(!assistant.content)assistant.content='Tidak ada jawaban yang diterima.';delete wrap.dataset.generating;content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);if(wrap.copyBtn)wrap.copyBtn.disabled=false;
      persistConversation();
      if(isNewConversation&&doneTitle){const conv=getConv(activeId);if(conv){conv.title=doneTitle;upsertConv(conv);}}
      await loadConversations(activeId);
    }catch(err){
      hideTyping();
      if(err.name==='AbortError'){if(!assistant.content){wrap?.remove();messages.pop();}else if(content){delete wrap.dataset.generating;content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);if(wrap.copyBtn)wrap.copyBtn.disabled=false;}}
      else{if(!content){wrap=bubble('assistant','');attachIntentBadge(wrap,pendingIntent);content=wrap.querySelector('.bubble');}assistant.content='⚠️ '+(err.message||'Server error');delete wrap.dataset.generating;content.classList.add('markdown-content');content.dataset.raw=assistant.content;render(wrap);if(wrap.copyBtn)wrap.copyBtn.disabled=false;}
      if(messages.at(-1)?.content)persistConversation();
      loadConversations(activeId);
    }
    finally{controller=null;generating(false);scroll();}
  }
  form.onsubmit=e=>{
    e.preventDefault();
    if(controller)return;
    const text=input.value.trim();
    if(!text&&!pendingAttachments.length)return;
    const fullContent=buildMessageWithAttachments(text);
    const displayText=buildDisplayText(text);
    input.value='';input.style.height='auto';
    sendMessage(fullContent,{displayText,apiContent:fullContent});
    clearAttachments();
  };input.oninput=()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,200)+'px';};input.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}};
  $('regenBtn').onclick=()=>lastQuestion&&!controller&&sendMessage(lastQuestion,{regen:true});$('stopBtn').onclick=()=>controller?.abort();$('newChatBtn').onclick=newChat;
  $('sidebarToggle').onclick=()=>{sidebar.classList.toggle('collapsed');if(!sidebar.classList.contains('collapsed'))closeMobileMenu();};
  $('clearBtn').onclick=()=>{if(activeId&&!controller&&confirm('Hapus percakapan ini?')){removeConv(activeId);newChat();}};

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
  function theme(dark){document.body.classList.toggle('dark-theme',dark);$('themeBtn').textContent=dark?'☀️':'🌙';sessionStorage.setItem('sigit-theme',dark?'dark':'light');}$('themeBtn').onclick=()=>theme(!document.body.classList.contains('dark-theme'));theme(sessionStorage.getItem('sigit-theme')==='dark');
  thinkSelect.onchange=()=>sessionStorage.setItem('sigit-think-'+modelSelect.value,thinkSelect.value);
  if(responseDepthSelect){responseDepthSelect.value=sessionStorage.getItem('sigit-response-depth')||'adaptive';responseDepthSelect.onchange=()=>sessionStorage.setItem('sigit-response-depth',responseDepthSelect.value);}
  window.onload=async()=>{
    if(innerWidth<=768)sidebar.classList.add('collapsed');
    const [,rows]=await Promise.all([loadModels(),Promise.resolve(loadConversations())]);
    rows.length?openConversation(rows[0].id):greeting();
    input.focus();
  };
});
