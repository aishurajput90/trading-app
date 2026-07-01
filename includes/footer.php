    </div><!-- /page-content -->
</div><!-- /main-wrapper -->

<!-- ── Trading Assistant Chat Widget ──────────────────────────────────────── -->
<style>
#chat-bubble{position:fixed;bottom:24px;right:24px;width:52px;height:52px;border-radius:16px;background:var(--accent,#6366f1);box-shadow:0 4px 20px rgba(99,102,241,.45);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:9999;transition:transform .15s,box-shadow .15s}
#chat-bubble:hover{transform:scale(1.08);box-shadow:0 6px 28px rgba(99,102,241,.6)}
#chat-bubble i{color:#fff;font-size:20px}
#chat-unread{position:absolute;top:-5px;right:-5px;width:18px;height:18px;background:#ef4444;border-radius:50%;font-size:10px;font-weight:800;color:#fff;display:none;align-items:center;justify-content:center;border:2px solid var(--bg-surface,#fff)}

#chat-panel{position:fixed;bottom:88px;right:24px;width:360px;height:520px;background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;box-shadow:0 8px 40px rgba(0,0,0,.22);display:none;flex-direction:column;z-index:9998;overflow:hidden;transition:opacity .15s,transform .15s;opacity:0;transform:translateY(10px) scale(.98)}
#chat-panel.open{display:flex;opacity:1;transform:translateY(0) scale(1)}

#chat-head{padding:14px 16px;background:var(--accent,#6366f1);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
#chat-head .ch-title{font-weight:700;font-size:13px;color:#fff}
#chat-head .ch-sub{font-size:10px;color:rgba(255,255,255,.72);margin-top:1px}
#chat-head button{background:none;border:none;color:rgba(255,255,255,.8);font-size:20px;line-height:1;cursor:pointer;padding:0;display:flex;align-items:center}
#chat-head button:hover{color:#fff}

#chat-msgs{flex:1;overflow-y:auto;padding:14px 12px;display:flex;flex-direction:column;gap:8px;scroll-behavior:smooth}
#chat-msgs::-webkit-scrollbar{width:4px}
#chat-msgs::-webkit-scrollbar-track{background:transparent}
#chat-msgs::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}

.chat-msg{max-width:88%;padding:9px 12px;border-radius:14px;font-size:12.5px;line-height:1.55;word-break:break-word;animation:msgIn .15s ease}
.chat-msg.bot{background:var(--bg-base);color:var(--text-primary);border-bottom-left-radius:4px;align-self:flex-start}
.chat-msg.user{background:var(--accent,#6366f1);color:#fff;border-bottom-right-radius:4px;align-self:flex-end}
.chat-msg strong{font-weight:700}
@keyframes msgIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

.chat-typing{display:flex;gap:4px;padding:10px 12px;background:var(--bg-base);border-radius:14px;border-bottom-left-radius:4px;align-self:flex-start;width:52px}
.chat-typing span{width:7px;height:7px;background:var(--text-muted);border-radius:50%;animation:bounce 1.2s infinite}
.chat-typing span:nth-child(2){animation-delay:.2s}
.chat-typing span:nth-child(3){animation-delay:.4s}
@keyframes bounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-5px)}}

#chat-foot{padding:10px 12px;border-top:1px solid var(--border);display:flex;gap:8px;flex-shrink:0}
#chat-input{flex:1;background:var(--bg-base);border:1px solid var(--border);border-radius:10px;padding:8px 12px;font-size:13px;color:var(--text-primary);outline:none;transition:border-color .15s}
#chat-input:focus{border-color:var(--accent,#6366f1)}
#chat-input::placeholder{color:var(--text-muted)}
#chat-send{width:36px;height:36px;background:var(--accent,#6366f1);border:none;border-radius:10px;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s}
#chat-send:hover{opacity:.88}
#chat-send i{font-size:13px}
</style>

<!-- Bubble -->
<div id="chat-bubble" onclick="chatToggle()" title="Trading Assistant">
    <i class="fas fa-comments"></i>
    <div id="chat-unread"></div>
</div>

<!-- Panel -->
<div id="chat-panel">
    <div id="chat-head">
        <div>
            <div class="ch-title"><i class="fas fa-robot" style="margin-right:6px"></i>Trading Assistant</div>
            <div class="ch-sub">Answers questions about your trades</div>
        </div>
        <button onclick="chatToggle()" aria-label="Close chat">&times;</button>
    </div>
    <div id="chat-msgs"></div>
    <div id="chat-foot">
        <input id="chat-input" type="text" placeholder="Ask about your trades…" autocomplete="off" maxlength="200">
        <button id="chat-send" onclick="chatSend()" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<script>
(function(){
    const ENDPOINT = '<?= ($rootPath ?? '../') ?>pages/chatbot.php';
    const panel    = document.getElementById('chat-panel');
    const msgs     = document.getElementById('chat-msgs');
    const input    = document.getElementById('chat-input');
    const unread   = document.getElementById('chat-unread');
    let   opened   = false;
    let   busy     = false;

    // Greet once per session
    if (!sessionStorage.getItem('chat_greeted')) {
        sessionStorage.setItem('chat_greeted', '1');
        unread.style.display = 'flex';
        unread.textContent   = '1';
        setTimeout(() => addMsg('bot', "Hi! 👋 I'm your trading assistant — ask me anything about your trades, P/L, win rate, risk, or symbols. Type **help** to see what I know."), 400);
    }

    window.chatToggle = function() {
        opened = !opened;
        if (opened) {
            panel.classList.add('open');
            unread.style.display = 'none';
            input.focus();
            scrollBottom();
        } else {
            panel.classList.remove('open');
        }
    };

    window.chatSend = function() {
        const text = input.value.trim();
        if (!text || busy) return;
        input.value = '';
        addMsg('user', text);
        busy = true;
        const dot = addTyping();
        fetch(ENDPOINT, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'message='+encodeURIComponent(text)})
            .then(r => r.json())
            .then(d => { dot.remove(); addMsg('bot', d.reply || '…'); busy = false; })
            .catch(() => { dot.remove(); addMsg('bot', '⚠️ Something went wrong. Please try again.'); busy = false; });
    };

    input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(); } });

    function addMsg(role, text) {
        const el  = document.createElement('div');
        el.className = 'chat-msg ' + role;
        el.innerHTML = md(text);
        msgs.appendChild(el);
        scrollBottom();
        return el;
    }

    function addTyping() {
        const el = document.createElement('div');
        el.className = 'chat-typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        msgs.appendChild(el);
        scrollBottom();
        return el;
    }

    function scrollBottom() {
        msgs.scrollTop = msgs.scrollHeight;
    }

    // Minimal markdown: **bold** and newlines
    function md(str) {
        return str
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }
})();
</script>
<!-- ── / Chat Widget ───────────────────────────────────────────────────────── -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/app.js?v=1.0.2"></script>
</body>
</html>
