(function () {
  const KONAMI = [
    'ArrowUp','ArrowUp','ArrowDown','ArrowDown',
    'ArrowLeft','ArrowRight','ArrowLeft','ArrowRight',
    'b','a'
  ];
  let pos = 0;

  document.addEventListener('keydown', (e) => {
    if (e.key === KONAMI[pos]) {
      pos++;
      if (pos === KONAMI.length) {
        pos = 0;
        window.location.href = '/../etc/dev/null/egg.html';
      }
    } else {
      pos = e.key === KONAMI[0] ? 1 : 0;
    }
  });
})();

// Feel free to expand. These fire only when NOT focused on an input ^w^
(function() {
  function notTyping() {
    const el = document.activeElement;
    return !el || !['INPUT','TEXTAREA','SELECT'].includes(el.tagName);
  }

  document.addEventListener('keydown', (e) => {
    if (!notTyping()) return;

    switch (e.key) {

      // [?] — show shortcuts help overlay
      case '?':
        toggleShortcutsHelp();
        break;

      // [C] — jump to catalog (if on a board page)
      case 'c': case 'C':
        window.location.href = '/../catalog.php?b=';
        break;

      // [H] — go home
      case 'h': case 'H':
        window.location.href = '/';
        break;

      // [R] — refresh thread/page
      case 'r': case 'R':
        window.location.reload();
        break;

      // [N] — jump to reply box
      case 'n': case 'N': {
        const box = document.querySelector('.postform') || document.querySelector('textarea[name="body"]');
        if (box) { box.scrollIntoView({ behavior: 'smooth' }); box.focus(); }
        break;
      }

      // [Esc] — close any open modals/lightboxes
      case 'Escape': {
        const modal = document.querySelector('.modal.open, .lightbox.open');
        if (modal) modal.classList.remove('open');
        break;
      }
    }
  });


  // Shortcuts Helper
  function toggleShortcutsHelp() {
    let overlay = document.getElementById('shortcuts-overlay');
    if (overlay) { overlay.remove(); return; }

    overlay = document.createElement('div');
    overlay.id = 'shortcuts-overlay';
    overlay.innerHTML = `
      <div class="shortcuts-box">
        <div class="shortcuts-title">Keyboard Shortcuts</div>
        <table>
          <tr><td>?</td><td>Toggle this help</td></tr>
          <tr><td>H</td><td>Go to homepage</td></tr>
          <tr><td>C</td><td>Board catalog</td></tr>
          <tr><td>N</td><td>Jump to reply box</td></tr>
          <tr><td>R</td><td>Refresh</td></tr>
          <tr><td>Esc</td><td>Close overlays</td></tr>
        </table>
        <div class="shortcuts-close" onclick="this.closest('#shortcuts-overlay').remove()">[ close ]</div>
      </div>
    `;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  }

  const style = document.createElement('style');
  style.textContent = `
    #shortcuts-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.7);
      display: flex; align-items: center; justify-content: center;
      z-index: 9999; font-family: monospace;
    }
    .shortcuts-box {
      background: #111; border: 1px solid #444; color: #ccc;
      padding: 24px 32px; min-width: 320px; border-radius: 4px;
    }
    .shortcuts-title { font-size: 1.1em; color: #fff; margin-bottom: 14px; border-bottom: 1px solid #333; padding-bottom: 8px; }
    .shortcuts-box table { width: 100%; border-collapse: collapse; }
    .shortcuts-box td { padding: 4px 8px; }
    .shortcuts-box td:first-child { color: #8be; font-weight: bold; width: 130px; }
    .secret-row td { color: #555 !important; font-style: italic; }
    .shortcuts-close { margin-top: 16px; text-align: right; cursor: pointer; color: #666; }
    .shortcuts-close:hover { color: #ccc; }
  `;
  document.head.appendChild(style);
})();
