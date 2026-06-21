<?php
/**
 * WealthDash — t330: AI Chatbot Page
 * File: pages/ai/chatbot.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Chat Assistant'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🤖 AI Chat Assistant</h1><p class="page-subtitle">Ask anything about your investments — Hinglish mein.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="CB.newChat()">+ New Chat</button>
    <button class="btn btn-ghost btn-sm" onclick="CB.clearChat()">🗑 Clear</button>
  </div>
</div>

<div style="display:grid;grid-template-columns:240px 1fr;gap:20px;height:calc(100vh - 200px);min-height:500px;" class="responsive-grid-1col">

  <!-- Sidebar: suggested questions + sessions -->
  <div>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><span class="card-title" style="font-size:12px;">💡 Quick Questions</span></div>
      <div class="card-body" style="padding:8px;">
        <?php
        $quickQ = [
            "Mera portfolio kaisa chal raha hai?",
            "Mujhe kaunsa SIP step-up karna chahiye?",
            "LTCG tax kaise calculate karte hain?",
            "Market gira — kya SIP band karni chahiye?",
            "Mera portfolio rebalance karna chahiye?",
            "Emergency fund kitna hona chahiye?",
            "ELSS vs PPF — kaunsa better hai?",
        ];
        foreach ($quickQ as $q): ?>
          <button class="btn btn-ghost btn-sm" style="width:100%;text-align:left;margin-bottom:4px;font-size:12px;white-space:normal;line-height:1.4;" onclick="CB.sendQuick('<?= htmlspecialchars($q, ENT_QUOTES) ?>')"><?= htmlspecialchars($q) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Chat area -->
  <div class="card" style="display:flex;flex-direction:column;overflow:hidden;">
    <!-- Messages -->
    <div id="cb-messages" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;">
      <div class="cb-msg cb-assistant">
        <div class="cb-bubble">
          Namaste! 🙏 Main WealthDash ka AI Financial Assistant hoon. Aap apne portfolio, SIP, tax, ya kisi bhi investment ke baare mein pooch sakte hain.<br><br>
          <em style="font-size:12px;opacity:.7;">Main SEBI registered advisor nahi hoon — major decisions se pehle professional se milein.</em>
        </div>
      </div>
    </div>

    <!-- Typing indicator -->
    <div id="cb-typing" style="display:none;padding:8px 16px;">
      <div class="cb-msg cb-assistant"><div class="cb-bubble" style="padding:8px 14px;"><span style="animation:blink 1s step-start infinite;">●●●</span></div></div>
    </div>

    <!-- Input -->
    <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:flex-end;">
      <textarea id="cb-input" class="form-control" rows="2" placeholder="Kuch bhi poochho — portfolio, SIP, tax, investment…"
                style="resize:none;flex:1;"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();CB.send();}"></textarea>
      <button class="btn btn-primary" onclick="CB.send()" id="cb-send-btn" style="height:42px;padding:0 20px;">Send ➤</button>
    </div>
  </div>
</div>

<style>
.cb-msg{display:flex;gap:8px;align-items:flex-end;}
.cb-assistant{justify-content:flex-start;}
.cb-user{justify-content:flex-end;}
.cb-bubble{max-width:75%;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.6;white-space:pre-wrap;word-break:break-word;}
.cb-assistant .cb-bubble{background:var(--bg-secondary);border-radius:12px 12px 12px 2px;}
.cb-user .cb-bubble{background:var(--accent);color:#fff;border-radius:12px 12px 2px 12px;}
.cb-avatar{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
</style>

<script>
const CB = {
  _contextId: null,

  init() {
    this._contextId = 'ctx_' + Date.now();
  },

  sendQuick(q) {
    document.getElementById('cb-input').value = q;
    this.send();
  },

  send() {
    const input = document.getElementById('cb-input');
    const msg   = input.value.trim();
    if (!msg) return;
    input.value = '';
    document.getElementById('cb-send-btn').disabled = true;

    this._addMsg('user', msg);
    document.getElementById('cb-typing').style.display = '';
    this._scrollBottom();

    apiPost({
      action: 'ai_chat_send',
      message: msg,
      context_id: this._contextId,
    }).then(r => {
      document.getElementById('cb-typing').style.display = 'none';
      document.getElementById('cb-send-btn').disabled = false;
      if (!r.ok) { this._addMsg('assistant', '❌ Error: ' + (r.message || 'Unknown error')); return; }
      this._addMsg('assistant', r.data.reply || 'No response');
      this._scrollBottom();
    }).catch(() => {
      document.getElementById('cb-typing').style.display = 'none';
      document.getElementById('cb-send-btn').disabled = false;
      this._addMsg('assistant', '❌ Network error. Dobara try karo.');
    });
  },

  _addMsg(role, text) {
    const wrap = document.getElementById('cb-messages');
    const div  = document.createElement('div');
    div.className = 'cb-msg cb-' + role;
    const avatar = role === 'assistant' ? '🤖' : '👤';
    div.innerHTML = `
      ${role==='assistant' ? `<div class="cb-avatar">${avatar}</div>` : ''}
      <div class="cb-bubble">${esc(text)}</div>
      ${role==='user' ? `<div class="cb-avatar">${avatar}</div>` : ''}`;
    wrap.appendChild(div);
    this._scrollBottom();
  },

  _scrollBottom() {
    const wrap = document.getElementById('cb-messages');
    wrap.scrollTop = wrap.scrollHeight;
  },

  newChat() {
    this._contextId = 'ctx_' + Date.now();
    const wrap = document.getElementById('cb-messages');
    wrap.innerHTML = `<div class="cb-msg cb-assistant"><div class="cb-avatar">🤖</div><div class="cb-bubble">Naya chat shuru! Koi bhi sawaal poochho. 😊</div></div>`;
  },

  clearChat() {
    if (!confirm('Is chat history clear karo?')) return;
    apiPost({ action: 'ai_chat_clear', context_id: this._contextId }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) this.newChat();
    });
  }
};

document.addEventListener('DOMContentLoaded', () => CB.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
