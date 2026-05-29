/* ============================================================
   EIAAW Social Media Team — floating support chatbot
   Same shape as eiaawsolutions.com's eiaaw-connect.js, but same-origin
   (no CORS) and surface-aware. The widget reads its surface + endpoints
   from the <script> tag's data-* attributes:

     <script src="/brand/smt-chat.js"
             data-surface="landing|client|hq"
             data-chat-url="/api/chatbot"
             data-contact-url="/api/contact"
             data-csrf="<token-or-empty>"
             defer></script>

   - "Talk to AI agent"  -> chat panel, posts to data-chat-url
   - "Talk to us"        -> enquiry form, posts to data-contact-url
   The server picks the system prompt from `surface` (and downgrades a
   spoofed panel surface to landing for logged-out callers), so the bot's
   intent (sale conversion vs guide) is enforced server-side, not here.
   ============================================================ */
(function () {
  const SELF = document.currentScript;
  const SURFACE = (SELF && SELF.dataset.surface) || 'landing';
  const CHAT_URL = (SELF && SELF.dataset.chatUrl) || '/api/chatbot';
  const CONTACT_URL = (SELF && SELF.dataset.contactUrl) || '/api/contact';
  const CSRF = (SELF && SELF.dataset.csrf) || '';

  const COPY = {
    landing: {
      title: 'EIAAW assistant',
      sub: 'Ethical AI · Always honest',
      greeting: "Hi — I'm the EIAAW Social Media Team assistant. I can explain how we ship every post with receipts, walk you through pricing, or get you started. What are you posting?",
      placeholder: 'Ask about SMT, pricing, or how it works…',
      quick: [
        { label: 'What is SMT?', msg: 'What is the Social Media Team and what makes it different?' },
        { label: 'Pricing', msg: 'How much does it cost?' },
        { label: 'Subscribe', action: 'subscribe' },
        { label: 'Talk to us', action: 'form' },
      ],
    },
    client: {
      title: 'SMT guide',
      sub: 'Your in-app assistant',
      greeting: "Hi — I'm your Social Media Team guide. Ask me how to connect a platform, add a brand, review drafts, or set autonomy. What would you like to do?",
      placeholder: 'Ask how to do something in SMT…',
      quick: [
        { label: 'How do I get started?', msg: 'How do I get started — what are the first steps?' },
        { label: 'Why was a post held?', msg: 'Why was one of my posts held instead of published?' },
        { label: 'Talk to us', action: 'form' },
      ],
    },
    hq: {
      title: 'HQ guide',
      sub: 'Internal assistant',
      greeting: "Hi — HQ guide here. Ask me where to find things: provisioning a workspace, triaging held posts, plan caps, or where website enquiries land.",
      placeholder: 'Ask where to find / how to do…',
      quick: [
        { label: 'Provision a customer', msg: 'How do I provision a new customer workspace?' },
        { label: 'Where are enquiries?', msg: 'Where do website enquiries show up?' },
      ],
    },
  };
  const C = COPY[SURFACE] || COPY.landing;

  // ---------- Floating launcher ----------
  function ensureLauncher() {
    if (document.getElementById('smt-chat-launcher')) return;
    const btn = document.createElement('button');
    btn.id = 'smt-chat-launcher';
    btn.className = 'smt-chat-launcher';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Open assistant');
    btn.innerHTML = '<span class="smt-chat-launcher-dot"></span><span>Chat</span>';
    btn.addEventListener('click', toggleChat);
    document.body.appendChild(btn);
  }

  // ---------- Contact modal: "Talk to us" ----------
  function ensureContactModal() {
    if (document.getElementById('smt-contact-modal')) return;
    const wrap = document.createElement('div');
    wrap.id = 'smt-contact-modal';
    wrap.className = 'smt-modal';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML = `
      <div class="smt-modal-panel" role="dialog" aria-modal="true" aria-labelledby="smt-contact-title">
        <button class="smt-modal-close" aria-label="Close">&times;</button>
        <div data-view="form">
          <span class="smt-eyebrow">Talk to us</span>
          <h3 id="smt-contact-title">Tell us what you're working on.</h3>
          <p class="smt-modal-lead">We read every message. Expect a reply within one working day from <strong>eiaawsolutions@gmail.com</strong>.</p>
          <div class="smt-field">
            <label for="smt-name">Name</label>
            <input id="smt-name" type="text" autocomplete="name" maxlength="120" required>
          </div>
          <div class="smt-row">
            <div class="smt-field">
              <label for="smt-email">Email</label>
              <input id="smt-email" type="email" autocomplete="email" maxlength="160" required>
            </div>
            <div class="smt-field">
              <label for="smt-phone">Phone <small>(optional)</small></label>
              <input id="smt-phone" type="tel" autocomplete="tel" maxlength="40">
            </div>
          </div>
          <div class="smt-field">
            <label for="smt-company">Company <small>(optional)</small></label>
            <input id="smt-company" type="text" autocomplete="organization" maxlength="160">
          </div>
          <div class="smt-field">
            <label for="smt-message">What would you like to explore?</label>
            <textarea id="smt-message" rows="4" maxlength="2000" required placeholder="A few lines about your brand, your goals, and the outcome you're after."></textarea>
          </div>
          <div class="smt-modal-err" id="smt-error" hidden></div>
          <div class="smt-modal-actions">
            <button type="button" class="smt-btn smt-btn-primary" id="smt-submit">Send enquiry &rarr;</button>
          </div>
        </div>
        <div data-view="success" hidden>
          <span class="smt-eyebrow">Message sent</span>
          <h3>Thanks — we'll be in touch.</h3>
          <p class="smt-modal-lead">Your enquiry just landed with our team. We'll reply within one working day. Meanwhile, you're welcome to keep exploring.</p>
          <div class="smt-modal-actions">
            <button type="button" class="smt-btn smt-btn-ghost smt-modal-close-sec">Close</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(wrap);
    wrap.addEventListener('click', (e) => {
      if (e.target === wrap) closeContact();
      if (e.target.classList.contains('smt-modal-close') || e.target.classList.contains('smt-modal-close-sec')) closeContact();
    });
    wrap.querySelector('#smt-submit').addEventListener('click', submitContact);
  }

  function openContact(prefill) {
    ensureContactModal();
    const modal = document.getElementById('smt-contact-modal');
    modal.querySelector('[data-view="form"]').hidden = false;
    modal.querySelector('[data-view="success"]').hidden = true;
    if (prefill && prefill.message) {
      const el = modal.querySelector('#smt-message');
      if (el) el.value = prefill.message;
    }
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(() => modal.querySelector('#smt-name').focus(), 30);
  }

  function closeContact() {
    const modal = document.getElementById('smt-contact-modal');
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  async function submitContact() {
    const modal = document.getElementById('smt-contact-modal');
    const name = modal.querySelector('#smt-name').value.trim();
    const email = modal.querySelector('#smt-email').value.trim();
    const phone = modal.querySelector('#smt-phone').value.trim();
    const company = modal.querySelector('#smt-company').value.trim();
    const message = modal.querySelector('#smt-message').value.trim();
    const errEl = modal.querySelector('#smt-error');
    const btn = modal.querySelector('#smt-submit');

    if (!name || !email || !message) {
      errEl.textContent = 'Please fill in your name, email, and message.';
      errEl.hidden = false;
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errEl.textContent = 'Please enter a valid email address.';
      errEl.hidden = false;
      return;
    }
    errEl.hidden = true;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    try {
      const res = await fetch(CONTACT_URL, {
        method: 'POST',
        headers: postHeaders(),
        body: JSON.stringify({ name, email, phone, company, message, surface: SURFACE }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) {
        errEl.textContent = data.error || 'Something went wrong. You can also email eiaawsolutions@gmail.com directly.';
        errEl.hidden = false;
        btn.disabled = false;
        btn.innerHTML = 'Send enquiry &rarr;';
        return;
      }
      modal.querySelector('[data-view="form"]').hidden = true;
      modal.querySelector('[data-view="success"]').hidden = false;
    } catch (e) {
      errEl.textContent = 'Connection issue. You can also email eiaawsolutions@gmail.com directly.';
      errEl.hidden = false;
      btn.disabled = false;
      btn.innerHTML = 'Send enquiry &rarr;';
    }
  }

  // ---------- Chat panel: "Talk to AI agent" ----------
  let chatHistory = [];

  function ensureChatPanel() {
    if (document.getElementById('smt-chat-panel')) return;
    const panel = document.createElement('div');
    panel.id = 'smt-chat-panel';
    panel.className = 'smt-chat-panel';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML = `
      <div class="smt-chat-head">
        <div><strong>${escapeHtml(C.title)}</strong><small>${escapeHtml(C.sub)}</small></div>
        <button class="smt-chat-close" aria-label="Close chat">&times;</button>
      </div>
      <div class="smt-chat-modes">
        <button type="button" class="smt-chat-mode active" data-mode="agent">Talk to AI agent</button>
        <button type="button" class="smt-chat-mode" data-mode="form">Talk to us</button>
      </div>
      <div class="smt-chat-msgs" id="smt-chat-msgs" role="log" aria-live="polite"></div>
      <div class="smt-chat-quick" id="smt-chat-quick"></div>
      <form class="smt-chat-form" id="smt-chat-form">
        <input type="text" id="smt-chat-input" placeholder="${escapeHtml(C.placeholder)}" maxlength="500" autocomplete="off">
        <button type="submit" aria-label="Send">&rarr;</button>
      </form>`;
    document.body.appendChild(panel);

    panel.querySelector('.smt-chat-close').addEventListener('click', toggleChat);
    panel.querySelectorAll('.smt-chat-mode').forEach(b => {
      b.addEventListener('click', () => {
        if (b.dataset.mode === 'form') { openContact(); return; }
        panel.querySelectorAll('.smt-chat-mode').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
      });
    });
    panel.querySelector('#smt-chat-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const input = panel.querySelector('#smt-chat-input');
      const v = input.value.trim();
      if (!v) return;
      input.value = '';
      handleUserMessage(v);
    });

    addBotMessage(C.greeting);
    renderQuickReplies(C.quick);
  }

  function addBotMessage(text) {
    const msgs = document.getElementById('smt-chat-msgs');
    const el = document.createElement('div');
    el.className = 'smt-chat-bubble bot';
    el.innerHTML = renderMarkdown(text); // safe: renderMarkdown escapes first, then formats
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
    // Store the raw text (not HTML) so the history sent back to the model stays clean.
    chatHistory.push({ role: 'assistant', content: text });
  }
  function addUserMessage(text) {
    const msgs = document.getElementById('smt-chat-msgs');
    const el = document.createElement('div');
    el.className = 'smt-chat-bubble user';
    el.textContent = text;
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
    chatHistory.push({ role: 'user', content: text });
  }
  function addTyping() {
    const msgs = document.getElementById('smt-chat-msgs');
    const el = document.createElement('div');
    el.className = 'smt-chat-bubble bot typing';
    el.id = 'smt-chat-typing';
    el.innerHTML = '<span></span><span></span><span></span>';
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
  }
  function removeTyping() { document.getElementById('smt-chat-typing')?.remove(); }

  function renderQuickReplies(items) {
    const qr = document.getElementById('smt-chat-quick');
    if (!qr) return;
    qr.innerHTML = '';
    (items || []).forEach(it => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'smt-chat-chip';
      btn.textContent = it.label;
      btn.addEventListener('click', () => {
        if (it.action === 'form') { openContact(); }
        else if (it.action === 'subscribe') { window.location.href = '/signup'; }
        else if (it.msg) { handleUserMessage(it.msg); }
      });
      qr.appendChild(btn);
    });
  }

  async function handleUserMessage(text) {
    addUserMessage(text);
    addTyping();
    try {
      // Send a short rolling history (last ~6 turns, excluding the just-added
      // user turn) so the bot can follow "tell me more".
      const history = chatHistory.slice(-7, -1);
      const res = await fetch(CHAT_URL, {
        method: 'POST',
        headers: postHeaders(),
        body: JSON.stringify({ message: text, surface: SURFACE, history }),
      });
      const data = await res.json().catch(() => ({}));
      removeTyping();
      addBotMessage(data.response || "I'm having trouble right now — please try the 'Talk to us' form.");
      renderQuickReplies(SURFACE === 'landing'
        ? [{ label: 'Subscribe', action: 'subscribe' }, { label: 'Talk to us', action: 'form' }]
        : [{ label: 'Talk to us', action: 'form' }]);
    } catch (e) {
      removeTyping();
      addBotMessage("I can't reach the server right now. Please use 'Talk to us' or email eiaawsolutions@gmail.com.");
      renderQuickReplies([{ label: 'Talk to us', action: 'form' }]);
    }
  }

  function toggleChat() {
    ensureChatPanel();
    const panel = document.getElementById('smt-chat-panel');
    const open = panel.classList.toggle('open');
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (open) setTimeout(() => panel.querySelector('#smt-chat-input')?.focus(), 50);
  }

  function postHeaders() {
    const h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (CSRF) h['X-CSRF-TOKEN'] = CSRF; // panels send it; landing leaves it blank (route is CSRF-exempt)
    return h;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  /**
   * Tiny, SAFE markdown renderer for assistant replies. Escapes the whole
   * string FIRST (so no raw HTML/script from the model can execute), then turns
   * a small allow-list of markdown into formatting:
   *   **bold**, *italic*, `code`, [text](https/mailto links only),
   *   - / * / • bullet lists, and blank-line paragraph breaks.
   * Anything outside this set renders as plain (escaped) text. No external lib.
   */
  function renderMarkdown(src) {
    let s = escapeHtml(String(src).trim());

    // Inline: links FIRST (before bold/italic touch the brackets), restricted
    // to safe schemes. The url is already escaped; we re-check the scheme.
    s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g,
      (m, label, url) => `<a href="${url}" target="_blank" rel="noopener">${label}</a>`);
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Block: group consecutive bullet lines into a <ul>; everything else into
    // paragraphs split on blank lines, with single newlines as <br>.
    const blocks = s.split(/\n{2,}/);
    const html = blocks.map(block => {
      const lines = block.split('\n');
      const isList = lines.every(l => /^\s*[-*•]\s+/.test(l)) && lines.length > 0;
      if (isList) {
        const items = lines.map(l => `<li>${l.replace(/^\s*[-*•]\s+/, '')}</li>`).join('');
        return `<ul>${items}</ul>`;
      }
      return `<p>${block.replace(/\n/g, '<br>')}</p>`;
    }).join('');

    return html;
  }

  // ---------- Public API + CTA hooks ----------
  window.SMTChat = { openContact, closeContact, toggleChat };

  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-smt]');
    if (!t) return;
    const act = t.dataset.smt;
    if (act === 'contact') { e.preventDefault(); openContact(); }
    else if (act === 'chat') { e.preventDefault(); toggleChat(); }
  });

  // Build the launcher as soon as the DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureLauncher);
  } else {
    ensureLauncher();
  }
})();
