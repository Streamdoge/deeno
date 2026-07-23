/* deeno-news — тема deeno. Собственный код. */
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
