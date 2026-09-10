document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  initRoleGuide();
  initAiChat();
  initMobileNav();
  enhanceResponsiveTables();
});

function initRoleGuide() {
  var modal = document.getElementById('role-guide-modal');
  if (!modal) return;

  var steps = Array.prototype.slice.call(modal.querySelectorAll('[data-guide-step]'));
  var dots = Array.prototype.slice.call(modal.querySelectorAll('[data-guide-dot]'));
  var prevBtn = document.getElementById('guide-prev');
  var nextBtn = document.getElementById('guide-next');
  var doneBtn = document.getElementById('guide-done');
  var dontShow = document.getElementById('guide-dont-show');
  var storageKey = modal.getAttribute('data-storage-key') || 'ailis_guide_seen';
  var index = 0;
  var lastFocus = null;

  function showStep(i) {
    index = Math.max(0, Math.min(i, steps.length - 1));
    steps.forEach(function (step, si) {
      var on = si === index;
      step.classList.toggle('is-active', on);
      if (on) {
        step.removeAttribute('hidden');
        restartDemo(step);
      } else {
        step.setAttribute('hidden', '');
      }
    });
    dots.forEach(function (dot, di) {
      dot.classList.toggle('is-active', di === index);
      dot.classList.toggle('is-done', di < index);
    });
    if (prevBtn) prevBtn.disabled = index === 0;
    var last = index === steps.length - 1;
    if (nextBtn) nextBtn.hidden = last;
    if (doneBtn) doneBtn.hidden = !last;
  }

  function restartDemo(step) {
    var demo = step.querySelector('.guide-demo');
    if (!demo) return;
    demo.classList.remove('is-animating');
    // Force reflow so CSS animations replay
    void demo.offsetWidth;
    demo.classList.add('is-animating');
  }

  function openGuide(forceRestart) {
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('guide-open');
    if (forceRestart) {
      try { localStorage.removeItem(storageKey); } catch (err) { /* ignore */ }
      if (dontShow) dontShow.checked = false;
    }
    showStep(0);
    var closeBtn = modal.querySelector('.guide-close');
    if (closeBtn) closeBtn.focus();
  }

  function closeGuide() {
    modal.hidden = true;
    document.body.classList.remove('guide-open');
    if (dontShow && dontShow.checked) {
      try { localStorage.setItem(storageKey, '1'); } catch (err) { /* ignore */ }
    } else if (!localStorageGet(storageKey)) {
      // Mark seen after first manual close so it does not nag every page
      try { localStorage.setItem(storageKey, '1'); } catch (err) { /* ignore */ }
    }
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  function localStorageGet(key) {
    try { return localStorage.getItem(key); } catch (err) { return null; }
  }

  document.querySelectorAll('[data-guide-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openGuide(btn.hasAttribute('data-guide-restart'));
    });
  });

  modal.querySelectorAll('[data-guide-close]').forEach(function (el) {
    el.addEventListener('click', closeGuide);
  });

  if (prevBtn) {
    prevBtn.addEventListener('click', function () { showStep(index - 1); });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () { showStep(index + 1); });
  }
  if (doneBtn) {
    doneBtn.addEventListener('click', function () {
      if (dontShow) dontShow.checked = true;
      closeGuide();
    });
  }

  dots.forEach(function (dot) {
    dot.addEventListener('click', function () {
      showStep(parseInt(dot.getAttribute('data-guide-dot'), 10) || 0);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (modal.hidden) return;
    if (e.key === 'Escape') closeGuide();
    if (e.key === 'ArrowRight') showStep(index + 1);
    if (e.key === 'ArrowLeft') showStep(index - 1);
  });

  // Auto-open once per role on first login session visit
  if (!localStorageGet(storageKey)) {
    window.setTimeout(function () { openGuide(false); }, 450);
  }
}

function initAiChat() {
  var root = document.getElementById('ai-chat');
  if (!root) return;

  var panel = document.getElementById('ai-chat-panel');
  var toggle = document.getElementById('ai-chat-toggle');
  var closeBtn = document.getElementById('ai-chat-close');
  var form = document.getElementById('ai-chat-form');
  var input = document.getElementById('ai-chat-input');
  var messages = document.getElementById('ai-chat-messages');
  var sendBtn = document.getElementById('ai-chat-send');
  var endpoint = root.getAttribute('data-chat-url') || '';
  var history = [];
  var busy = false;

  function setOpen(open) {
    if (!panel || !toggle) return;
    if (open) {
      panel.removeAttribute('hidden');
    } else {
      panel.setAttribute('hidden', '');
    }
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    root.classList.toggle('is-open', open);
    document.body.classList.toggle('ai-chat-open', open);
    if (open && input) input.focus();
  }

  function appendBubble(text, kind) {
    var el = document.createElement('div');
    el.className = 'ai-chat-bubble ai-chat-' + kind;
    el.textContent = text;
    messages.appendChild(el);
    messages.scrollTop = messages.scrollHeight;
    return el;
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      setOpen(panel.hasAttribute('hidden'));
    });
  }
  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setOpen(false);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && root.classList.contains('is-open')) {
      setOpen(false);
    }
  });

  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || !input) return;
    var text = (input.value || '').trim();
    if (!text) return;

    appendBubble(text, 'user');
    history.push({ role: 'user', content: text });
    input.value = '';
    busy = true;
    if (sendBtn) sendBtn.disabled = true;
    var thinking = appendBubble('Thinking…', 'bot');
    thinking.classList.add('is-pending');

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ message: text, history: history.slice(0, -1) })
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { okHttp: res.ok, data: data };
        });
      })
      .then(function (result) {
        thinking.remove();
        var data = result.data || {};
        if (data.ok && data.reply) {
          appendBubble(data.reply, 'bot');
          history.push({ role: 'assistant', content: data.reply });
          if (history.length > 24) history = history.slice(-24);
        } else {
          appendBubble(data.detail || 'Sorry — the assistant could not reply.', 'bot');
        }
      })
      .catch(function () {
        thinking.remove();
        appendBubble('Network error talking to the assistant.', 'bot');
      })
      .finally(function () {
        busy = false;
        if (sendBtn) sendBtn.disabled = false;
        if (input) input.focus();
      });
  });

  if (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        form.requestSubmit();
      }
    });
  }

  var dashOpen = document.getElementById('dashboard-open-ai-chat');
  if (dashOpen) {
    dashOpen.addEventListener('click', function () { setOpen(true); });
  }
}

function initMobileNav() {
  var bar = document.getElementById('topbar');
  var toggle = document.getElementById('nav-toggle');
  if (!bar || !toggle) return;

  function setOpen(open) {
    bar.classList.toggle('is-open', open);
    document.body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }

  toggle.addEventListener('click', function () {
    setOpen(!bar.classList.contains('is-open'));
  });

  bar.querySelectorAll('.nav a').forEach(function (link) {
    link.addEventListener('click', function () { setOpen(false); });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && bar.classList.contains('is-open')) {
      setOpen(false);
    }
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 960) setOpen(false);
  });
}

function enhanceResponsiveTables() {
  document.querySelectorAll('main table').forEach(function (table) {
    if (!table.closest('.table-scroll')) {
      var wrap = document.createElement('div');
      wrap.className = 'table-scroll';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    }
    var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
      return (th.textContent || '').trim();
    });
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) {
        if (!td.hasAttribute('data-label')) {
          td.setAttribute('data-label', headers[i] || '');
        }
      });
    });
  });
}
