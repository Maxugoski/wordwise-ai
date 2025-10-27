(function(){
    function el(id){return document.getElementById(id);}
    const send = el('wwai-send');
    const input = el('wwai-input');
    const messages = el('wwai-messages');
    const historyEl = el('wwai-history');
    const newBtn = el('wwai-new');
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
                addMessage('ai', 'Error: ' + (data.data && data.data.message ? data.data.message : 'Unknown'));
            }
        } catch (e) {
            addMessage('ai', 'Request failed: ' + e.message);
        }
    });

    newBtn.addEventListener('click', function(){ input.value=''; input.focus(); });

    renderHistory();
    input.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); send.click(); } });
})();