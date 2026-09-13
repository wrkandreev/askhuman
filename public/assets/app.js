(() => {
  const menu = () => {
    const toggle = document.querySelector('.menu-toggle');
    const navigation = document.querySelector('#site-navigation');
    if (!toggle || !navigation) return;

    const setMenu = (open) => {
      document.body.classList.toggle('menu-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.querySelector('.menu-toggle-label').textContent = open ? 'Закрыть' : 'Меню';
    };

    toggle.addEventListener('click', () => {
      setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });

    navigation.addEventListener('click', (event) => {
      if (event.target.closest('a')) setMenu(false);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setMenu(false);
    });

    const desktopQuery = window.matchMedia('(min-width: 901px)');
    const handleDesktop = (event) => {
      if (event.matches) setMenu(false);
    };

    if (desktopQuery.addEventListener) {
      desktopQuery.addEventListener('change', handleDesktop);
    } else {
      desktopQuery.addListener(handleDesktop);
    }
  };

  const agentPrompt = () => {
    const promptBox = document.querySelector('.agent-prompt');
    if (!promptBox) return;

    const toggle = promptBox.querySelector('.prompt-toggle');
    const pre = promptBox.querySelector('pre');
    const copy = promptBox.querySelector('.prompt-copy');
    let copiedTimer = 0;

    toggle?.addEventListener('click', () => {
      const open = promptBox.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
      const label = toggle.querySelector('.prompt-toggle-label');
      if (label) label.textContent = open ? 'Свернуть' : 'Показать полностью';
    });

    copy?.addEventListener('click', async () => {
      const text = pre?.innerText ?? '';
      let ok = false;
      try {
        await navigator.clipboard.writeText(text);
        ok = true;
      } catch {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { ok = document.execCommand('copy'); } catch { /* noop */ }
        ta.remove();
      }
      if (!ok) return;
      copy.classList.add('copied');
      copy.setAttribute('aria-label', 'Скопировано');
      window.clearTimeout(copiedTimer);
      copiedTimer = window.setTimeout(() => {
        copy.classList.remove('copied');
        copy.setAttribute('aria-label', 'Скопировать промпт');
      }, 2000);
    });
  };

  menu();
  agentPrompt();
})();
