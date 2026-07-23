/* deeno-docs — тема deeno. Собственный код. */
(function () {
  'use strict';

  // Мобильное меню: выезжающий слева drawer + затемняющий оверлей (как в админке)
  var shell = document.querySelector('.shell');
  var burger = document.getElementById('menu-toggle');
  var overlay = document.getElementById('overlay');

  // ── Цвет панели браузера (meta[name=theme-color], см. layout.php) ──
  // Работает в Chrome/Android и в Safari до 26; Safari 26 тег игнорирует и
  // красит панели по CSS-фону страницы (см. .overlay в style.css).
  // Значения: --canvas из style.css и он же под подложкой меню.
  var BAR = { light: '#f9fafb', dark: '#0F1117', lightDim: '#91949c', darkDim: '#10141e' };
  function syncBar() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var open = !!(shell && shell.classList.contains('sidebar-open'));
    meta.setAttribute('content', dark ? (open ? BAR.darkDim : BAR.dark)
                                      : (open ? BAR.lightDim : BAR.light));
  }

  // ── Стоп-скролл под открытым меню ──
  // Подложка меню сделана absolute (иначе Safari 26 красит ею свою панель),
  // а absolute не следует за прокруткой — поэтому на время открытого меню
  // фиксируем саму страницу, запомнив позицию, и возвращаем её при закрытии.
  var lockedY = 0;
  // Открыт ли хоть один полноэкранный слой — меню или поиск.
  function overlayOpen() {
    var so = document.getElementById('search-overlay');
    return !!(shell && shell.classList.contains('sidebar-open')) || !!(so && !so.hidden);
  }
  function syncLock() {
    var open = overlayOpen();
    var body = document.body;
    if (open === body.classList.contains('is-locked')) return; // уже в нужном состоянии
    if (open) {
      lockedY = window.pageYOffset || document.documentElement.scrollTop || 0;
      body.style.top = -lockedY + 'px';
      // Полноэкранные слои внутри body — absolute, а начало документа уехало
      // на -lockedY. Возвращаем их на вьюпорт (см. --lock-y в style.css).
      body.style.setProperty('--lock-y', lockedY + 'px');
      body.classList.add('is-locked');
    } else {
      body.classList.remove('is-locked');
      body.style.top = '';
      body.style.removeProperty('--lock-y');
      window.scrollTo(0, lockedY);
    }
  }

  // Единая точка после любого изменения .sidebar-open
  function syncDrawer() { syncLock(); syncBar(); }

  if (burger && shell) {
    burger.addEventListener('click', function () {
      shell.classList.toggle('sidebar-open');
      syncDrawer();
    });
  }
  if (overlay && shell) {
    overlay.addEventListener('click', function () {
      shell.classList.remove('sidebar-open');
      syncDrawer();
    });
  }
  // Закрыть по клику на пункт меню и по Escape
  var sideNav = document.getElementById('side-nav');
  if (sideNav && shell) {
    sideNav.addEventListener('click', function (e) {
      if (e.target.closest('a')) { shell.classList.remove('sidebar-open'); syncDrawer(); }
    });
  }
  if (shell) {
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { shell.classList.remove('sidebar-open'); syncDrawer(); }
    });
  }

  // Переключение темы (светлая/тёмная): data-theme на <html> + localStorage
  var themeBtn = document.getElementById('theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      if (isDark) {
        document.documentElement.removeAttribute('data-theme');
      } else {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
      syncBar(); // data-theme уже переставлен выше — цвет панели посчитается сам
      try { localStorage.setItem('deeno-site-theme', isDark ? 'light' : 'dark'); } catch (e) {}
    });
  }

  // Переключение языка интерфейса: ставим куку и перезагружаем страницу
  var langBtn = document.getElementById('lang-toggle');
  if (langBtn) {
    langBtn.addEventListener('click', function () {
      var to = langBtn.getAttribute('data-lang');
      document.cookie = 'deeno_lang=' + to + '; path=/; max-age=31536000; samesite=lax';
      location.reload();
    });
  }

  // ── Дерево: сворачивание разделов ──
  document.querySelectorAll('.tree__toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sec = btn.closest('.tree__section');
      if (!sec) return;
      var open = sec.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // ── Drag-and-drop расстановка (только вошедшему админу; данные из data-*) ──
  (function () {
    var tree = document.querySelector('.tree.is-reorderable');
    if (!tree) return;
    var url = tree.getAttribute('data-reorder-url');
    var csrf = tree.getAttribute('data-reorder-csrf');
    // Ручной порядок статей выключен → номера не сохраняются, и перестановка
    // внутри раздела была бы обманом: после перезагрузки дерево пересчиталось бы
    // по алфавиту или датам. Переносить статью в ДРУГОЙ раздел при этом можно.
    var manual = tree.getAttribute('data-reorder-manual') !== '0';
    var dragged = null;
    var fromItems = null;

    tree.querySelectorAll('.tree__section .tree__link').forEach(function (el) {
      el.setAttribute('draggable', 'true');
      el.addEventListener('dragstart', function (e) {
        dragged = el;
        fromItems = el.closest('.tree__items');
        el.classList.add('is-dragging');
        if (!manual) hint(true);
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } catch (x) {}
      });
      el.addEventListener('dragend', function () {
        el.classList.remove('is-dragging');
        hint(false);
        if (dragged) { dragged = null; save(); }
      });
    });

    tree.addEventListener('dragover', function (e) {
      if (!dragged) return;
      var items = e.target.closest ? e.target.closest('.tree__items') : null;
      if (!items) return;
      // Не ручной режим: принимаем только переносы в чужой раздел
      if (!manual && items === fromItems) return;
      e.preventDefault();
      var target = e.target.closest('.tree__link');
      if (target && target !== dragged && target.dataset.file) {
        var r = target.getBoundingClientRect();
        items.insertBefore(dragged, e.clientY < r.top + r.height / 2 ? target : target.nextSibling);
      } else if (!target) {
        items.appendChild(dragged);
      }
    });

    // Подсказка на время перетаскивания: почему порядок внутри раздела не меняется
    function hint(show) {
      var el = tree.querySelector('.tree__hint');
      if (!show) { if (el) el.remove(); return; }
      if (el) return;
      el = document.createElement('p');
      el.className = 'tree__hint';
      el.textContent = tree.getAttribute('data-reorder-hint')
        || 'Порядок статей задан сортировкой. Чтобы расставлять вручную, включите «Вручную» в Настройках. Перенести статью в другой раздел можно и сейчас.';
      tree.insertBefore(el, tree.firstChild);
    }

    function save() {
      var sections = [];
      tree.querySelectorAll('.tree__section').forEach(function (sec) {
        var posts = [];
        sec.querySelectorAll('.tree__link').forEach(function (a) { if (a.dataset.file) posts.push(a.dataset.file); });
        sections.push({ category: sec.getAttribute('data-category') || '', posts: posts });
      });
      var body = new URLSearchParams();
      body.set('csrf', csrf);
      body.set('data', JSON.stringify({ sections: sections }));
      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { flash(d && d.ok); })
        .catch(function () { flash(false); });
    }

    function flash(ok) {
      tree.classList.remove('reorder-ok', 'reorder-err');
      tree.classList.add(ok ? 'reorder-ok' : 'reorder-err');
      setTimeout(function () { tree.classList.remove('reorder-ok', 'reorder-err'); }, 1000);
    }
  })();

  // ── «На этой странице»: TOC из заголовков статьи + якоря + scroll-spy ──
  (function () {
    var content = document.querySelector('.doc__content');
    var toc = document.getElementById('toc');
    var tocNav = document.getElementById('toc-nav');
    if (!content || !toc || !tocNav) return;
    var headings = content.querySelectorAll('h2, h3');
    if (!headings.length) return;

    function slugify(t) {
      return (t.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '')) || 'section';
    }
    var links = [];
    headings.forEach(function (h) {
      var text = h.textContent;
      if (!h.id) {
        var id = slugify(text), base = id, n = 2;
        while (document.getElementById(id)) { id = base + '-' + n++; }
        h.id = id;
      }
      var anchor = document.createElement('a');
      anchor.className = 'anchor'; anchor.href = '#' + h.id; anchor.textContent = '#'; anchor.setAttribute('aria-hidden', 'true');
      h.insertBefore(anchor, h.firstChild);

      var link = document.createElement('a');
      link.className = 'toc__link' + (h.tagName === 'H3' ? ' is-sub' : '');
      link.href = '#' + h.id;
      link.textContent = text;
      tocNav.appendChild(link);
      links.push({ link: link, h: h });
    });
    toc.hidden = false;

    function onScroll() {
      var current = links[0];
      for (var i = 0; i < links.length; i++) {
        if (links[i].h.getBoundingClientRect().top <= 120) current = links[i]; else break;
      }
      links.forEach(function (l) { l.link.classList.toggle('active', l === current); });
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  })();

  var overlay = document.getElementById('search-overlay');
  var openBtn = document.getElementById('search-open');
  var closeBtn = document.getElementById('search-close');
  var field = document.getElementById('search-field');
  if (!overlay || !openBtn) return;

  function openSearch() {
    // Поиск живёт внутри бокового меню — закрываем его, иначе меню остаётся
    // висеть под поиском (и держит стоп-скролл на себе).
    if (shell) shell.classList.remove('sidebar-open');
    overlay.hidden = false;
    syncDrawer();
    if (field) field.focus();
  }
  function closeSearch() {
    overlay.hidden = true;
    syncDrawer();
  }

  openBtn.addEventListener('click', openSearch);
  if (closeBtn) closeBtn.addEventListener('click', closeSearch);

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeSearch();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeSearch();
  });
})();
