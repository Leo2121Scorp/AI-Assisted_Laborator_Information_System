document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  initRoleGuide();
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
