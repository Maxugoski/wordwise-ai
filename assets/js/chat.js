(function(){
    function el(id){return document.getElementById(id);}
    const send = el('wwai-send');
    const input = el('wwai-input');
    const messages = el('wwai-messages');
    const historyEl = el('wwai-history');
    const newBtn = el('wwai-new');
    const templateBtn = el('wwai-template-btn');
    const templateForm = el('wwai-template-form');
    const blogTitle = el('wwai-blog-title');
    const blogKeywords = el('wwai-blog-keywords');
    const blogTone = el('wwai-blog-tone');
    const blogLength = el('wwai-blog-length');
    const blogGenerate = el('wwai-blog-generate');
    const blogCancel = el('wwai-blog-cancel');
    let history = JSON.parse(localStorage.getItem('wwai_history')||'[]');

    function renderHistory(){
        historyEl.innerHTML='';
        history.slice(0,10).forEach((h,i)=>{
            const b = document.createElement('button');
            b.className='w-full text-left px-2 py-1 rounded hover:bg-gray-100';
            b.textContent = h.prompt.substring(0,60);
            b.onclick = ()=>{ input.value = h.prompt; };
            historyEl.appendChild(b);
        });
    }
    function addMessage(role, text){
        const wrap = document.createElement('div');
        wrap.className = role==='user' ? 'text-right' : 'text-left';
        const bubble = document.createElement('div');
        bubble.className = role==='user' ? 'inline-block bg-green-100 text-gray-800 px-3 py-2 rounded ml-auto' : 'inline-block bg-white text-gray-800 px-3 py-2 rounded';
        bubble.style.maxWidth='80%';
        bubble.textContent = text;
        wrap.appendChild(bubble);
        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    send.addEventListener('click', async function(){
        const prompt = input.value.trim();
        if (!prompt) return;
        addMessage('user', prompt);
        input.value='';
        addMessage('system', 'Thinking...');
        try {
            const form = new URLSearchParams();
            form.append('action','wwai_send_prompt');
            form.append('prompt', prompt);
            const res = await fetch(WordWiseAI.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:form.toString() });
            const data = await res.json();
            if (data.success) {
                addMessage('ai', data.data.text);
                history.unshift({prompt:prompt, response:data.data.text, time:Date.now()});
                localStorage.setItem('wwai_history', JSON.stringify(history.slice(0,50)));
                renderHistory();
            } else {
                // Show a clearer error and include raw server response when available for debugging
                console.warn('WordWise AI response error:', data);
                let msg = data.data && data.data.message ? data.data.message : 'Unknown';
                if (data.data && data.data.raw) {
                    // Try to present a short raw snippet instead of jumbo payload
                    try {
                        const rawStr = typeof data.data.raw === 'string' ? data.data.raw : JSON.stringify(data.data.raw);
                        msg += ' — Raw: ' + rawStr.substring(0, 500) + (rawStr.length > 500 ? '…' : '');
                    } catch (e) {
                        // ignore stringify errors
                    }
                }
                addMessage('ai', 'Error: ' + msg);
            }
        } catch (e) {
            addMessage('ai', 'Request failed: ' + e.message);
        }
    });

    // Template UI handlers
    if (templateBtn && templateForm) {
        templateBtn.addEventListener('click', function(){
            templateForm.style.display = templateForm.style.display === 'none' ? 'block' : 'none';
        });
    }
    if (blogCancel && templateForm) {
        blogCancel.addEventListener('click', function(e){ e.preventDefault(); templateForm.style.display='none'; });
    }
    if (blogGenerate) {
        blogGenerate.addEventListener('click', async function(e){
            e.preventDefault();
            const title = (blogTitle && blogTitle.value || '').trim();
            if (!title) { addMessage('ai','Error: Title is required for blog template.'); return; }
            const keywords = blogKeywords ? blogKeywords.value.trim() : '';
            const tone = blogTone ? blogTone.value : 'Informative';
            const length = blogLength ? blogLength.value : 'medium';
            templateForm.style.display='none';
            addMessage('user', `Generate blog post: ${title}`);
            addMessage('system','Generating outline...');
            try {
                const outlineForm = new URLSearchParams();
                outlineForm.append('action','wwai_generate_blog_outline');
                outlineForm.append('title', title);
                outlineForm.append('keywords', keywords);
                outlineForm.append('tone', tone);
                outlineForm.append('length', length);
                const outRes = await fetch(WordWiseAI.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:outlineForm.toString() });
                const outData = await outRes.json();
                if (!outData.success) {
                    let msg = outData.data && outData.data.message ? outData.data.message : 'Failed to generate outline';
                    addMessage('ai','Error: '+msg);
                    return;
                }
                addMessage('ai','Outline generated. Generating sections...');
                const headings = outData.data.headings || [];
                let assembled = '# ' + title + '\n\n';
                // generate each section sequentially
                for (let i=0;i<headings.length;i++) {
                    const h = headings[i];
                    addMessage('system', `Generating section: ${h}`);
                    const secForm = new URLSearchParams();
                    secForm.append('action','wwai_generate_blog_section');
                    secForm.append('title', title);
                    secForm.append('heading', h);
                    secForm.append('keywords', keywords);
                    secForm.append('tone', tone);
                    const secRes = await fetch(WordWiseAI.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:secForm.toString() });
                    const secData = await secRes.json();
                    if (!secData.success) {
                        addMessage('ai', `Error generating section ${h}: ` + (secData.data && secData.data.message ? secData.data.message : 'Unknown'));
                        assembled += '## ' + h + '\n\n' + `[Failed to generate section: ${h}]` + '\n\n';
                        continue;
                    }
                    const txt = secData.data.text || '';
                    assembled += '## ' + h + '\n\n' + txt + '\n\n';
                    // append section to chat as it arrives
                    addMessage('ai', '## ' + h + '\n\n' + txt);
                }
                addMessage('system','Generating SEO meta...');
                const metaForm = new URLSearchParams();
                metaForm.append('action','wwai_generate_blog_meta');
                metaForm.append('title', title);
                metaForm.append('keywords', keywords);
                metaForm.append('tone', tone);
                metaForm.append('post_text', assembled);
                const metaRes = await fetch(WordWiseAI.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:metaForm.toString() });
                const metaData = await metaRes.json();
                let metaText = '';
                if (metaData.success) metaText = metaData.data.meta || '';
                // Offer Save to Draft button
                addMessage('ai', 'Blog generation complete. Review SEO meta below before saving as a draft.');
                // show meta review panel
                const panel = document.createElement('div');
                panel.style.background = '#fff';
                panel.style.border = '1px solid #ddd';
                panel.style.padding = '10px';
                panel.style.marginTop = '8px';
                const metaLabel = document.createElement('div'); metaLabel.textContent = 'SEO Meta (edit before saving)'; metaLabel.style.fontWeight='600'; panel.appendChild(metaLabel);
                const metaArea = document.createElement('textarea'); metaArea.style.width='100%'; metaArea.style.minHeight='120px'; metaArea.style.marginTop='6px';
                metaArea.placeholder = 'Meta JSON or raw meta text...';
                if (metaText) {
                    // if structured meta returned, show JSON
                    if (typeof metaText === 'object') {
                        metaArea.value = JSON.stringify(metaText, null, 2);
                    } else {
                        metaArea.value = metaText;
                    }
                }
                panel.appendChild(metaArea);
                const saveBtn = document.createElement('button');
                saveBtn.className = 'px-3 py-1 bg-indigo-600 text-white rounded';
                saveBtn.textContent = 'Save to Draft';
                saveBtn.style.marginTop='8px';
                panel.appendChild(saveBtn);
                const wrap = document.createElement('div'); wrap.className='text-left'; wrap.appendChild(panel); messages.appendChild(wrap);
                messages.scrollTop = messages.scrollHeight;
                saveBtn.addEventListener('click', async function(){
                    saveBtn.disabled = true; saveBtn.textContent = 'Saving...';
                    const saveForm = new URLSearchParams();
                    saveForm.append('action','wwai_save_draft');
                    saveForm.append('title', title);
                    saveForm.append('content', assembled);
                    // short excerpt - first 160 chars of assembled text (plain)
                    const excerpt = assembled.replace(/[#\*]/g,'').replace(/\n+/g,' ').trim().substring(0,160);
                    saveForm.append('excerpt', excerpt);
                    // include meta JSON if possible
                    let metaPayload = metaArea.value.trim();
                    // If looks like JSON, send as meta_json
                    try {
                        JSON.parse(metaPayload);
                        saveForm.append('meta_json', metaPayload);
                    } catch (e) {
                        // not JSON - include as meta_raw
                        saveForm.append('meta_json', JSON.stringify({raw: metaPayload}));
                    }
                    const sRes = await fetch(WordWiseAI.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:saveForm.toString() });
                    const sData = await sRes.json();
                    if (sData.success) {
                        addMessage('ai', 'Draft saved. Edit it here: ' + (sData.data.edit_link || ('/wp-admin/post.php?post='+sData.data.post_id+'&action=edit')));
                        saveBtn.remove();
                        metaArea.disabled = true;
                    } else {
                        addMessage('ai', 'Failed to save draft: ' + (sData.data && sData.data.message ? sData.data.message : 'Unknown'));
                        saveBtn.disabled = false; saveBtn.textContent = 'Save to Draft';
                    }
                });

                history.unshift({prompt:`Blog: ${title}`, response:assembled, time:Date.now()});
                localStorage.setItem('wwai_history', JSON.stringify(history.slice(0,50)));
                renderHistory();
            } catch (e) {
                addMessage('ai', 'Request failed: ' + e.message);
            }
        });
    }

    newBtn.addEventListener('click', function(){ input.value=''; input.focus(); });

    renderHistory();
    input.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); send.click(); } });
})();