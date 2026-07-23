/* deeno — admin.js: бургер-меню, модалка удаления, тосты. Vanilla JS. */

/* i18n: словарь window.DEENO_I18N задаётся в layout.php, ключ — русская строка */
function dnT(s) { return (window.DEENO_I18N || {})[s] || s; }
function dnTf(s, v) { return dnT(s).replace('%s', String(v)); }

/* ---------- Полноэкранные слои: стоп-скролл + цвет панели браузера ----------
   Один механизм на бургер-меню и ВСЕ модалки. Открытие/закрытие ловится
   наблюдателем (модалки в админке переключаются из десятка мест), поэтому
   ничего дополнительно вызывать не нужно — достаточно menu/modal.hidden.
   Зачем: (1) под открытым слоем фон не должен прокручиваться, иначе уезжает
   фикс-шапка; (2) meta[name=theme-color] должен становиться цветом затемнённой
   страницы, иначе панели браузера остаются светлыми поверх тёмного слоя.
   Те же значения и та же логика — в темах deeno-docs/deeno-news. */
(function () {
  'use strict';

  // --canvas из admin.css и он же под затемнением слоя
  var BAR = { light: '#f9fafb', dark: '#0F1117', lightDim: '#91949c', darkDim: '#10141e' };
  var lockedY = 0;

  function layerOpen() {
    var admin = document.querySelector('.admin');
    if (admin && admin.classList.contains('sidebar-open')) return true;
    var modals = document.querySelectorAll('.modal, .crop-modal');
    for (var i = 0; i < modals.length; i++) {
      if (!modals[i].hidden) return true;
    }
    return false;
  }

  function syncBar() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    var dark = document.documentElement.dataset.theme === 'dark';
    var open = layerOpen();
    meta.setAttribute('content', dark ? (open ? BAR.darkDim : BAR.dark)
                                      : (open ? BAR.lightDim : BAR.light));
  }

  // В админке прокручивается НЕ окно, а колонка контента (.content: overflow-y
  // auto — иначе сайдбар, привязанный к 100vh, «отклеивается»). Фиксации body
  // тут мало: колесо над затемнением крутило бы список под модалкой. Поэтому
  // глушим и окно (мобильная вёрстка, модалки на весь экран), и сам .content.
  function syncLock() {
    var open = layerOpen();
    var body = document.body;
    var pane = document.querySelector('.content');
    if (open === body.classList.contains('is-locked')) return; // уже в нужном состоянии
    if (open) {
      lockedY = window.pageYOffset || document.documentElement.scrollTop || 0;
      // Скроллбар исчезнет вместе с прокруткой — компенсируем, иначе вёрстка прыгает
      var gap = window.innerWidth - document.documentElement.clientWidth;
      body.style.top = -lockedY + 'px';
      if (gap > 0) body.style.paddingRight = gap + 'px';
      // Слои внутри body — absolute, а начало документа уехало на -lockedY:
      // возвращаем их на вьюпорт (см. --lock-y в admin.css)
      body.style.setProperty('--lock-y', lockedY + 'px');
      body.classList.add('is-locked');
      if (pane) {
        // Позицию внутри .content не трогаем — overflow:hidden её сохраняет
        var paneGap = pane.offsetWidth - pane.clientWidth;
        pane.style.overflowY = 'hidden';
        if (paneGap > 0) pane.style.paddingRight = (parseFloat(getComputedStyle(pane).paddingRight) + paneGap) + 'px';
      }
    } else {
      body.classList.remove('is-locked');
      body.style.top = '';
      body.style.paddingRight = '';
      body.style.removeProperty('--lock-y');
      if (pane) {
        pane.style.overflowY = '';
        pane.style.paddingRight = '';
      }
      window.scrollTo(0, lockedY);
    }
  }

  function sync() { syncLock(); syncBar(); }
  window.dnSyncLayers = sync;

  // Одного overflow:hidden мало: колесо над затемнением всё равно докручивает
  // .content до конца. Поэтому при открытом слое гасим сам жест — кроме случая,
  // когда крутят ВНУТРИ слоя (длинный список в модалке медиатеки).
  function blockScroll(e) {
    if (!layerOpen()) return;
    var inner = e.target.closest && e.target.closest('.media-lib__body, .modal__box, .crop-modal__box');
    if (inner && inner.scrollHeight > inner.clientHeight) return;
    e.preventDefault();
  }
  document.addEventListener('wheel', blockScroll, { passive: false });
  document.addEventListener('touchmove', blockScroll, { passive: false });

  // Ловим и класс .sidebar-open, и hidden у любой модалки — где бы их ни переключили
  new MutationObserver(function (records) {
    for (var i = 0; i < records.length; i++) {
      var el = records[i].target;
      if (el.nodeType !== 1) continue;
      if (el.classList.contains('modal') || el.classList.contains('crop-modal')
          || el.classList.contains('admin')) { sync(); return; }
    }
  }).observe(document.documentElement, {
    subtree: true,
    attributes: true,
    attributeFilter: ['hidden', 'class']
  });

  document.addEventListener('DOMContentLoaded', sync);

  // Кнопки переключения темы здесь больше нет: тема выбирается в Настройках →
  // «Панель управления» и приходит с сервера из профиля пользователя.
})();

/* ---------- Медиатека: переключатель вида сетка/список ---------- */
(function () {
  'use strict';
  var grid = document.getElementById('media-grid');
  var btns = document.querySelectorAll('.js-media-view');
  if (!grid || !btns.length) return;

  function apply(view) {
    grid.classList.toggle('media-grid--list', view === 'list');
    btns.forEach(function (b) {
      var on = b.dataset.view === view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  var saved = 'grid';
  try { saved = localStorage.getItem('deeno-media-view') || 'grid'; } catch (e) {}
  apply(saved);

  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      apply(b.dataset.view);
      try { localStorage.setItem('deeno-media-view', b.dataset.view); } catch (e) {}
    });
  });
})();

/* ---------- Плагины: переключатель вида список/карточки ---------- */
(function () {
  'use strict';
  var grid = document.getElementById('plugin-grid');
  var btns = document.querySelectorAll('.js-plugins-view');
  if (!grid || !btns.length) return;

  function apply(view) {
    grid.classList.toggle('plugin-grid--cards', view === 'cards');
    btns.forEach(function (b) {
      var on = b.dataset.view === view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  var saved = 'list';
  try { saved = localStorage.getItem('deeno-plugins-view') || 'list'; } catch (e) {}
  apply(saved);

  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      apply(b.dataset.view);
      try { localStorage.setItem('deeno-plugins-view', b.dataset.view); } catch (e) {}
    });
  });
})();
(function () {
  'use strict';

  // ---------- Бургер-меню (мобильная версия) ----------
  var admin   = document.querySelector('.admin');
  var burger  = document.getElementById('burger');
  var overlay = document.getElementById('overlay');

  if (burger && admin) {
    burger.addEventListener('click', function () {
      admin.classList.toggle('sidebar-open');
    });
  }
  if (overlay && admin) {
    overlay.addEventListener('click', function () {
      admin.classList.remove('sidebar-open');
    });
  }

  // ---------- Модальное окно удаления ----------
  var modal       = document.getElementById('delete-modal');
  var deleteFile  = document.getElementById('delete-file');
  var deleteTitle = document.getElementById('delete-title');

  document.querySelectorAll('.js-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!modal) return;
      deleteFile.value = btn.dataset.file || '';
      deleteTitle.textContent = btn.dataset.title || '';
      modal.hidden = false;
    });
  });

  document.querySelectorAll('.js-modal-close').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal').hidden = true;
    });
  });

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.hidden = true;
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') modal.hidden = true;
    });
  }

  // ---------- Тосты: auto-dismiss 3 сек ----------
  function dismissLater(el) {
    setTimeout(function () {
      el.style.transition = 'opacity .3s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 300);
    }, 3000);
  }

  document.querySelectorAll('[data-toast]').forEach(function (el) {
    var toasts = document.getElementById('toasts');
    if (toasts) toasts.appendChild(el);
    dismissLater(el);
  });

  // Программный тост (для JS-действий вроде «Скопировано!»)
  window.dnToast = function (message) {
    var toasts = document.getElementById('toasts');
    if (!toasts) return;
    var el = document.createElement('div');
    el.className = 'alert alert--success';
    el.textContent = message;
    toasts.appendChild(el);
    dismissLater(el);
  };
})();

/* ============================================================
   Этап 2: редактор (транслитерация, автосохранение, drag&drop)
   и медиатека (загрузка, копирование URL)
   ============================================================ */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  // ---------- Транслитерация slug (зеркало system/Slugger.php) ----------
  var TRANSLIT = {
    'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z',
    'и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r',
    'с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'ts','ч':'ch','ш':'sh',
    'щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'
  };

  function slugify(text) {
    return text.toLowerCase().trim()
      .split('').map(function (ch) { return ch in TRANSLIT ? TRANSLIT[ch] : ch; }).join('')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-{2,}/g, '-')
      .slice(0, 120);
  }

  var titleInput = document.getElementById('post-title');
  var slugInput  = document.getElementById('post-slug');

  if (titleInput && slugInput) {
    var slugTouched = slugInput.value !== '';
    slugInput.addEventListener('input', function () { slugTouched = slugInput.value !== ''; });
    titleInput.addEventListener('input', function () {
      if (!slugTouched) slugInput.value = slugify(titleInput.value);
    });
  }

  // ---------- Табы боковой панели ----------
  document.querySelectorAll('.tabs__btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.tabs__btn').forEach(function (b) { b.classList.remove('active'); });
      document.querySelectorAll('.tab-pane').forEach(function (p) { p.hidden = true; });
      btn.classList.add('active');
      var pane = document.getElementById('tab-' + btn.dataset.tab);
      if (pane) pane.hidden = false;
    });
  });

  // ---------- Статус: поле даты (scheduled) + живая надпись кнопки ----------
  var statusSelect = document.getElementById('post-status');
  var scheduledRow = document.getElementById('field-scheduled');
  var saveBtn      = document.getElementById('save-btn');

  // Надпись по матрице: уже публичный пост → «Сохранить»; иначе по статусу
  // «Опубликовать» (публичные), «Запланировать» (scheduled), «Сохранить» (draft).
  function updateSaveLabel() {
    if (!saveBtn || !statusSelect) return;
    var s = statusSelect.value;
    var label;
    if (saveBtn.dataset.wasLive === '1' || s === 'draft') {
      label = saveBtn.dataset.labelSave;
    } else if (s === 'scheduled') {
      label = saveBtn.dataset.labelSchedule;
    } else {
      label = saveBtn.dataset.labelPublish;
    }
    saveBtn.textContent = label;
  }

  if (statusSelect) {
    statusSelect.addEventListener('change', function () {
      if (scheduledRow) scheduledRow.hidden = statusSelect.value !== 'scheduled';
      updateSaveLabel();
    });
  }

  // ---------- Кастомные поля ----------
  var cfWrap = document.getElementById('custom-fields');
  var cfAdd  = document.getElementById('cf-add');

  function bindCfRemove(row) {
    var btn = row.querySelector('.js-cf-remove');
    if (btn) btn.addEventListener('click', function () { row.remove(); });
  }

  if (cfWrap) {
    cfWrap.querySelectorAll('.cf-row').forEach(bindCfRemove);
    if (cfAdd) {
      cfAdd.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'cf-row';
        row.innerHTML = '<input type="text" name="cf_key[]" placeholder="key">' +
                        '<input type="text" name="cf_value[]" placeholder="' + dnT('значение') + '">' +
                        '<button type="button" class="btn btn--small btn--secondary js-cf-remove">✕</button>';
        cfWrap.appendChild(row);
        bindCfRemove(row);
      });
    }
  }

  // ---------- Вставка <!--more--> ----------
  var contentArea = document.getElementById('post-content');
  var moreBtn     = document.getElementById('insert-more');

  function insertAtCursor(textarea, text) {
    var start = textarea.selectionStart || 0;
    var end   = textarea.selectionEnd || 0;
    textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
    textarea.selectionStart = textarea.selectionEnd = start + text.length;
    textarea.focus();
  }

  if (moreBtn && contentArea) {
    moreBtn.addEventListener('click', function () {
      insertAtCursor(contentArea, '\n\n<!--more-->\n\n');
    });
  }

  // ---------- Автосохранение в localStorage каждые 30 сек ----------
  var form = document.getElementById('editor-form');
  var autosaveStatus = document.getElementById('autosave-status');

  if (form && contentArea) {
    var draftKey = 'deeno-draft-' + (form.querySelector('[name="file"]').value || 'new');

    // Восстановление
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(draftKey) || 'null'); } catch (e) {}
    if (saved && saved.content && saved.content !== contentArea.value) {
      if (confirm(dnTf('Найдена несохранённая копия от %s. Восстановить?', saved.time))) {
        contentArea.value = saved.content;
        if (titleInput && saved.title) titleInput.value = saved.title;
      } else {
        localStorage.removeItem(draftKey);
      }
    }

    setInterval(function () {
      try {
        localStorage.setItem(draftKey, JSON.stringify({
          title:   titleInput ? titleInput.value : '',
          content: contentArea.value,
          time:    new Date().toLocaleTimeString()
        }));
        if (autosaveStatus) autosaveStatus.textContent = dnTf('Черновик сохранён локально в %s', new Date().toLocaleTimeString());
      } catch (e) {}
    }, 30000);

    // После успешной отправки локальная копия не нужна
    form.addEventListener('submit', function () {
      localStorage.removeItem(draftKey);
    });
  }

  // ---------- Загрузка файла на сервер ----------
  function uploadFile(file, onDone, onError) {
    // Предпроверка: файл больше серверного лимита — не гоняем впустую, сразу понятная ошибка
    var max = window.DEENO_MAX_UPLOAD || 0;
    if (max && file.size > max) {
      onError(dnTf('Файл больше лимита сервера: %s МБ.', (max / 1048576).toFixed(1)));
      return;
    }
    var fd = new FormData();
    fd.append('file', file);
    fd.append('csrf', CSRF);
    fetch((window.DEENO_ADMIN_BASE || '/admin/') + 'media/upload/', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) onError(data.error); else onDone(data);
      })
      .catch(function () { onError(dnT('Сеть недоступна.')); });
  }

  // ---------- Drag & drop в редакторе ----------
  var dropzone = document.getElementById('dropzone');

  if (dropzone && contentArea) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      dropzone.addEventListener(ev, function (e) {
        e.preventDefault();
        dropzone.classList.add('dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dropzone.addEventListener(ev, function (e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');
      });
    });
    function dropOne(file) {
      var placeholder = '\n![' + dnTf('загрузка %s…', file.name) + ']()\n';
      insertAtCursor(contentArea, placeholder);
      uploadFile(file, function (data) {
        var md = data.url.match(/\.pdf$/i)
          ? '[' + file.name + '](' + data.url + ')'
          : '![' + file.name.replace(/\.[^.]+$/, '') + '](' + data.url + ')';
        contentArea.value = contentArea.value.replace(placeholder, '\n' + md + '\n');
      }, function (err) {
        contentArea.value = contentArea.value.replace(placeholder, '');
        alert(dnTf('Ошибка загрузки: %s', err));
      });
    }
    dropzone.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;
      // Одиночное растровое изображение — предложить кадрирование
      if (files.length === 1 && window.dnCroppable && window.dnCroppable(files[0])) {
        window.dnCrop(files[0], dropOne, null);
      } else {
        Array.prototype.forEach.call(files, dropOne);
      }
    });
  }

  // ---------- Медиатека: dropzone и выбор файлов ----------
  var mediaDrop  = document.getElementById('media-dropzone');
  var mediaInput = document.getElementById('media-input');
  var mediaPick  = document.getElementById('media-pick');

  function doMediaUpload(files) {
    var remaining = files.length;
    if (mediaPick) mediaPick.classList.add('is-loading'); // кнопка «Выбрать файлы» → спиннер до перезагрузки
    Array.prototype.forEach.call(files, function (file) {
      uploadFile(file, function () {
        remaining--;
        if (remaining === 0) location.reload();
      }, function (err) {
        remaining--;
        alert(file.name + ': ' + err);
        if (remaining === 0) location.reload();
      });
    });
  }
  function mediaUpload(files) {
    // Одиночное растровое изображение — предложить кадрирование перед загрузкой
    if (files.length === 1 && window.dnCroppable && window.dnCroppable(files[0])) {
      window.dnCrop(files[0], function (f) { doMediaUpload([f]); }, null);
    } else {
      doMediaUpload(files);
    }
  }

  if (mediaDrop) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      mediaDrop.addEventListener(ev, function (e) { e.preventDefault(); mediaDrop.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      mediaDrop.addEventListener(ev, function (e) { e.preventDefault(); mediaDrop.classList.remove('dragover'); });
    });
    mediaDrop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) mediaUpload(e.dataTransfer.files);
    });
  }
  if (mediaInput) {
    mediaInput.addEventListener('change', function () {
      if (mediaInput.files.length) mediaUpload(mediaInput.files);
    });
  }

  // ---------- Копирование URL ----------
  document.querySelectorAll('.js-copy-url').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = location.origin + btn.dataset.url;
      (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject())
        .then(function () {
          if (window.dnToast) window.dnToast(dnT('Скопировано!'));
        })
        .catch(function () { prompt(dnT('Скопируйте URL:'), url); });
    });
  });

  // ---------- Панель форматирования Markdown ----------
  var mdToolbar = document.getElementById('md-toolbar');

  // Обернуть выделение (или заглушку) и оставить его выделенным
  function mdWrap(before, after, placeholder) {
    var start = contentArea.selectionStart, end = contentArea.selectionEnd;
    var sel = contentArea.value.slice(start, end) || placeholder;
    contentArea.setRangeText(before + sel + after, start, end, 'preserve');
    contentArea.selectionStart = start + before.length;
    contentArea.selectionEnd   = start + before.length + sel.length;
    contentArea.focus();
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Префикс для каждой строки выделения; повторное нажатие снимает его
  function mdPrefixLines(prefix, numbered) {
    var start = contentArea.selectionStart, end = contentArea.selectionEnd;
    var value = contentArea.value;
    var ls = value.lastIndexOf('\n', start - 1) + 1;
    var le = value.indexOf('\n', end);
    if (le === -1) le = value.length;
    var lines = value.slice(ls, le).split('\n');
    var re = numbered ? /^\d+\.\s/ : null;
    var marked = lines.every(function (l) {
      return l === '' || (re ? re.test(l) : l.indexOf(prefix) === 0);
    });
    var n = 0;
    var out = lines.map(function (l) {
      if (l === '') return l;
      if (marked) return re ? l.replace(re, '') : l.slice(prefix.length);
      n++;
      return re ? n + '. ' + l : prefix + l;
    }).join('\n');
    contentArea.setRangeText(out, ls, le, 'preserve');
    contentArea.selectionStart = ls;
    contentArea.selectionEnd   = ls + out.length;
    contentArea.focus();
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Ссылка: [текст](url), курсор выделяет url
  function mdLink() {
    var start = contentArea.selectionStart, end = contentArea.selectionEnd;
    var sel = contentArea.value.slice(start, end) || dnT('текст');
    contentArea.setRangeText('[' + sel + '](url)', start, end, 'preserve');
    contentArea.selectionStart = start + sel.length + 3;
    contentArea.selectionEnd   = start + sel.length + 6;
    contentArea.focus();
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Уровень заголовка текущей строки: 0 — снять (абзац), 1–4 — задать
  function mdSetHeading(level) {
    var start = contentArea.selectionStart;
    var value = contentArea.value;
    var ls = value.lastIndexOf('\n', start - 1) + 1;
    var le = value.indexOf('\n', ls);
    if (le === -1) le = value.length;
    var line = value.slice(ls, le).replace(/^#{1,6}\s+/, '');
    var newLine = level > 0 ? (new Array(level + 1).join('#') + ' ' + line) : line;
    contentArea.setRangeText(newLine, ls, le, 'preserve');
    contentArea.selectionStart = contentArea.selectionEnd = ls + newLine.length;
    contentArea.focus();
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Каркас GFM-таблицы на новой строке
  function mdTable() {
    var h = dnT('Заголовок'), c = dnT('Ячейка');
    var tpl = '\n| ' + h + ' | ' + h + ' |\n| --- | --- |\n| ' + c + ' | ' + c + ' |\n| ' + c + ' | ' + c + ' |\n\n';
    insertAtCursor(contentArea, tpl);
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Обернуть выделение в блок выравнивания ::: name … :::
  function mdAlign(name) {
    var start = contentArea.selectionStart, end = contentArea.selectionEnd;
    var sel = contentArea.value.slice(start, end) || dnT('текст');
    contentArea.setRangeText('\n::: ' + name + '\n' + sel + '\n:::\n', start, end, 'end');
    contentArea.focus();
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Вставить ссылку на видео (YouTube/Vimeo) отдельной строкой — рендерер сделает iframe
  function mdVideo() {
    var url = prompt(dnT('Ссылка на видео (YouTube или Vimeo):'), '');
    if (!url) return;
    insertAtCursor(contentArea, '\n\n' + url.trim() + '\n\n');
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Снять markdown-разметку с выделения
  function mdClearFormat() {
    var start = contentArea.selectionStart, end = contentArea.selectionEnd;
    if (start === end) return;
    var s = contentArea.value.slice(start, end)
      .replace(/\*\*([\s\S]+?)\*\*/g, '$1')
      .replace(/\*([\s\S]+?)\*/g, '$1')
      .replace(/~~([\s\S]+?)~~/g, '$1')
      .replace(/==([\s\S]+?)==/g, '$1')
      .replace(/`([^`]+?)`/g, '$1')
      .replace(/\{[a-z]+:([\s\S]+?)\}/g, '$1')
      .replace(/\[([^\]]+?)\]\([^)]*?\)/g, '$1')
      .replace(/^#{1,6}\s+/gm, '')
      .replace(/^>\s?/gm, '');
    contentArea.setRangeText(s, start, end, 'end');
    contentArea.focus();
    contentArea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function mdAction(action) {
    var ph = dnT('текст');
    switch (action) {
      case 'bold':      mdWrap('**', '**', ph); break;
      case 'italic':    mdWrap('*', '*', ph); break;
      case 'strike':    mdWrap('~~', '~~', ph); break;
      case 'mark':      mdWrap('==', '==', ph); break;
      case 'code':      mdWrap('`', '`', 'code'); break;
      case 'codeblock': mdWrap('\n```\n', '\n```\n', 'code'); break;
      case 'link':      mdLink(); break;
      case 'video':     mdVideo(); break;
      case 'clear':     mdClearFormat(); break;
      case 'ul':        mdPrefixLines('- ', false); break;
      case 'ol':        mdPrefixLines('', true); break;
      case 'quote':     mdPrefixLines('> ', false); break;
      case 'table':     mdTable(); break;
      case 'align-left':    mdAlign('left'); break;
      case 'align-center':  mdAlign('center'); break;
      case 'align-right':   mdAlign('right'); break;
      case 'align-justify': mdAlign('justify'); break;
      case 'hr':
        insertAtCursor(contentArea, '\n\n---\n\n');
        contentArea.dispatchEvent(new Event('input', { bubbles: true }));
        break;
    }
  }

  if (mdToolbar && contentArea) {
    mdToolbar.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-md]');
      if (!btn || btn.classList.contains('js-pick-media')) return;
      mdAction(btn.dataset.md);
    });
    contentArea.addEventListener('keydown', function (e) {
      if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
      var k = e.key.toLowerCase();
      if (k === 'b') { e.preventDefault(); mdAction('bold'); }
      else if (k === 'i') { e.preventDefault(); mdAction('italic'); }
      else if (k === 'k') { e.preventDefault(); mdAction('link'); }
    });

    // Дропдаун уровня заголовка (сбрасывается на «Стиль» после применения)
    var mdHeadingSel = document.getElementById('md-heading');
    if (mdHeadingSel) {
      mdHeadingSel.addEventListener('change', function () {
        if (mdHeadingSel.value !== '') mdSetHeading(parseInt(mdHeadingSel.value, 10));
        mdHeadingSel.selectedIndex = 0;
      });
    }

    // Попап выбора цвета текста: оборачивает выделение в {color:…}
    var mdColor = document.getElementById('md-color');
    if (mdColor) {
      var colorTrigger = mdColor.querySelector('.md-color__trigger');
      var colorPop = mdColor.querySelector('.md-color__pop');
      colorTrigger.addEventListener('click', function (e) {
        e.stopPropagation();
        colorPop.hidden = !colorPop.hidden;
      });
      colorPop.addEventListener('click', function (e) {
        var sw = e.target.closest('[data-color]');
        if (!sw) return;
        var start = contentArea.selectionStart, end = contentArea.selectionEnd;
        var sel = contentArea.value.slice(start, end) || dnT('текст');
        contentArea.setRangeText('{' + sw.dataset.color + ':' + sel + '}', start, end, 'end');
        contentArea.dispatchEvent(new Event('input', { bubbles: true }));
        colorPop.hidden = true;
        contentArea.focus();
      });
      document.addEventListener('click', function (e) {
        if (!mdColor.contains(e.target)) colorPop.hidden = true;
      });
    }
  }

  // ---------- Счётчик слов и знаков ----------
  var countEl = document.getElementById('editor-count');
  if (countEl && contentArea) {
    var updateCount = function () {
      var trimmed = contentArea.value.trim();
      var words = trimmed ? trimmed.split(/\s+/).length : 0;
      countEl.textContent = words + ' ' + dnT('сл.') + ' · ' + contentArea.value.length + ' ' + dnT('зн.');
    };
    contentArea.addEventListener('input', updateCount);
    updateCount();
  }

  // ---------- Выбор изображения из медиатеки ----------
  var pickModal = document.getElementById('media-pick-modal');
  var pickTargetId = '';

  function applyPicked(url, name) {
    if (pickTargetId) {
      var input = document.getElementById(pickTargetId);
      if (input) {
        input.value = url;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    } else if (contentArea) {
      insertAtCursor(contentArea, '![' + String(name || '').replace(/\.[^.]+$/, '') + '](' + url + ')');
      contentArea.dispatchEvent(new Event('input', { bubbles: true }));
    }
    pickModal.hidden = true;
  }

  if (pickModal) {
    document.querySelectorAll('.js-pick-media').forEach(function (btn) {
      btn.addEventListener('click', function () {
        pickTargetId = btn.dataset.target || '';
        pickModal.hidden = false;
      });
    });
    pickModal.addEventListener('click', function (e) {
      if (e.target === pickModal) { pickModal.hidden = true; return; }
      if (e.target.closest('.js-copy-url')) return; // копирование URL — не выбор файла
      var btn = e.target.closest('.media-pick-item__btn');
      if (btn) applyPicked(btn.dataset.url || '', btn.dataset.name || '');
    });

    // ---------- Вид сетка/список (запоминается отдельно от страницы «Медиа») ----------
    var pickGrid = document.getElementById('media-pick-grid');
    var pickViewBtns = pickModal.querySelectorAll('.js-media-pick-view');
    function applyPickView(view) {
      if (pickGrid) pickGrid.classList.toggle('media-pick-grid--list', view === 'list');
      pickViewBtns.forEach(function (b) {
        var on = b.dataset.view === view;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }
    var savedPickView = 'grid';
    try { savedPickView = localStorage.getItem('deeno-media-pick-view') || 'grid'; } catch (e) {}
    applyPickView(savedPickView);
    pickViewBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        applyPickView(b.dataset.view);
        try { localStorage.setItem('deeno-media-pick-view', b.dataset.view); } catch (e) {}
      });
    });


    // Карточка только что загруженного файла — та же разметка, что и в PHP-шаблоне
    function buildPickItem(data, file) {
      var wrap = document.createElement('div');
      wrap.className = 'media-pick-item';

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'media-pick-item__btn';
      btn.dataset.url = data.url;
      btn.dataset.name = file.name;
      btn.title = file.name;

      var prev = document.createElement('span');
      prev.className = 'media-pick-item__preview';
      var img = document.createElement('img');
      img.src = data.thumb || data.url;
      img.alt = file.name;
      prev.appendChild(img);

      var meta = document.createElement('span');
      meta.className = 'media-pick-item__meta';
      var nm = document.createElement('span');
      nm.className = 'media-pick-item__name';
      nm.textContent = file.name;
      var sz = document.createElement('span');
      sz.className = 'media-pick-item__size muted';
      sz.textContent = Math.round((file.size || 0) / 1024) + ' ' + dnT('КБ');
      meta.appendChild(nm);
      meta.appendChild(sz);

      btn.appendChild(prev);
      btn.appendChild(meta);

      var copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'btn btn--small btn--secondary media-pick-item__copy js-copy-url';
      copy.dataset.url = data.url;
      copy.title = dnT('Копировать URL');
      copy.innerHTML = document.querySelector('.media-pick-item__copy')
        ? document.querySelector('.media-pick-item__copy').innerHTML : '';

      wrap.appendChild(btn);
      wrap.appendChild(copy);
      return wrap;
    }

    var pickInput  = document.getElementById('media-pick-input');
    var pickStatus = document.getElementById('media-pick-status');
    if (pickInput) {
      function pickUpload(file) {
        if (pickStatus) pickStatus.textContent = dnTf('Загрузка %s…', file.name);
        uploadFile(file, function (data) {
          if (pickStatus) pickStatus.textContent = '';
          var grid = document.getElementById('media-pick-grid');
          if (grid) grid.prepend(buildPickItem(data, file));
          var empty = document.getElementById('media-pick-empty');
          if (empty) empty.hidden = true;
          applyPicked(data.url, file.name);
        }, function (err) {
          if (pickStatus) pickStatus.textContent = '';
          alert(dnTf('Ошибка загрузки: %s', err));
        });
      }
      // Общий путь для выбранного и перетащенного файла: одиночное растровое
      // изображение сперва идёт в кадрирование, остальное — сразу на загрузку
      function pickHandleFile(file) {
        if (window.dnCroppable && window.dnCroppable(file)) {
          window.dnCrop(file, pickUpload, null);
        } else {
          pickUpload(file);
        }
      }

      pickInput.addEventListener('change', function () {
        if (!pickInput.files.length) return;
        var file = pickInput.files[0];
        pickInput.value = ''; // сброс, чтобы повторный выбор того же файла срабатывал
        pickHandleFile(file);
      });

      // ---------- Перетаскивание файла в окно модалки ----------
      var pickZone = document.getElementById('media-pick-dropzone');
      if (pickZone) {
        ['dragenter', 'dragover'].forEach(function (ev) {
          pickZone.addEventListener(ev, function (e) {
            e.preventDefault();
            pickZone.classList.add('is-dragover');
          });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
          pickZone.addEventListener(ev, function (e) {
            e.preventDefault();
            // dragleave срабатывает и при переходе на дочерний элемент
            if (ev === 'dragleave' && pickZone.contains(e.relatedTarget)) return;
            pickZone.classList.remove('is-dragover');
          });
        });
        pickZone.addEventListener('drop', function (e) {
          var files = e.dataTransfer && e.dataTransfer.files;
          if (files && files.length) pickHandleFile(files[0]);
        });
      }
    }
  }

  // ---------- Превью обложки ----------
  // Превью картинки-поля (обложка, иконка): показывает выбранный файл, прячет при пустом/битом
  function wirePreview(inputId, previewId) {
    var input = document.getElementById(inputId);
    var preview = document.getElementById(previewId);
    if (!input || !preview) return;
    input.addEventListener('input', function () {
      var v = input.value.trim();
      preview.hidden = v === '';
      if (v !== '') preview.src = v;
    });
    preview.addEventListener('error', function () { preview.hidden = true; });
    if (!preview.hidden && preview.complete && preview.naturalWidth === 0) preview.hidden = true;
  }
  wirePreview('post-cover', 'post-cover-preview');
  wirePreview('post-icon', 'post-icon-preview');

  // ---------- Модалка удаления в медиатеке ----------
  var mModal = document.getElementById('media-delete-modal');
  if (mModal) {
    document.querySelectorAll('.js-media-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('media-delete-url').value = btn.dataset.url || '';
        document.getElementById('media-delete-title').textContent = btn.dataset.title || '';
        mModal.hidden = false;
      });
    });
    mModal.addEventListener('click', function (e) { if (e.target === mModal) mModal.hidden = true; });
  }

  // ---------- Модалка создания/редактирования пользователя ----------
  var ueModal    = document.getElementById('user-edit-modal');
  var ueForm     = document.getElementById('user-edit-form');
  var ueHeading  = document.getElementById('user-edit-heading');
  var ueUsername = document.getElementById('user-edit-username');
  var ueDisplay  = document.getElementById('user-edit-display-name');
  var ueEmail    = document.getElementById('user-edit-email');
  var ueRole     = document.getElementById('user-edit-role');
  var uePassLbl  = document.getElementById('user-edit-password-label');
  var uePassword = document.getElementById('user-edit-password');
  var ueActive   = document.getElementById('user-edit-active');
  if (ueModal && ueForm) {
    document.querySelectorAll('.js-user-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        ueHeading.textContent = dnT('Редактирование') + ': ' + (btn.dataset.username || '');
        ueUsername.value = btn.dataset.username || '';
        ueUsername.readOnly = true;
        ueDisplay.value = btn.dataset.displayName || '';
        ueEmail.value = btn.dataset.email || '';
        ueRole.value = btn.dataset.role || 'author';
        uePassLbl.textContent = dnT('Пароль') + ' (' + dnT('пусто — не менять') + ')';
        uePassword.required = false;
        uePassword.value = '';
        // Свою учётку нельзя удалить и нельзя отключить — прячем чекбокс active,
        // но держим его отмеченным, чтобы форма отправляла active=1.
        var isSelf = btn.dataset.self === '1';
        var activeRow = ueActive.closest('.field--check');
        if (activeRow) activeRow.hidden = isSelf;
        ueActive.checked = isSelf ? true : (btn.dataset.active === '1');
        ueModal.hidden = false;
      });
    });
    document.querySelectorAll('.js-user-new').forEach(function (btn) {
      btn.addEventListener('click', function () {
        ueHeading.textContent = dnT('Новый пользователь');
        ueUsername.value = '';
        ueUsername.readOnly = false;
        ueDisplay.value = '';
        ueEmail.value = '';
        ueRole.value = 'author';
        uePassLbl.textContent = dnT('Пароль');
        uePassword.required = true;
        uePassword.value = '';
        var newActiveRow = ueActive.closest('.field--check');
        if (newActiveRow) newActiveRow.hidden = false;
        ueActive.checked = true;
        ueModal.hidden = false;
      });
    });
    ueModal.addEventListener('click', function (e) { if (e.target === ueModal) ueModal.hidden = true; });
  }

  // ---------- Модалка удаления пользователя ----------
  var uModal = document.getElementById('user-delete-modal');
  if (uModal) {
    document.querySelectorAll('.js-user-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('user-delete-name').value = btn.dataset.name || '';
        document.getElementById('user-delete-title').textContent = btn.dataset.title || '';
        uModal.hidden = false;
      });
    });
    uModal.addEventListener('click', function (e) { if (e.target === uModal) uModal.hidden = true; });
  }

  // ---------- Установка темы: выбор файла сразу отправляет форму ----------
  var themeZipInput  = document.getElementById('theme-zip-input');
  var themeInstallForm = document.getElementById('theme-install-form');
  if (themeZipInput && themeInstallForm) {
    themeZipInput.addEventListener('change', function () {
      if (themeZipInput.files.length) themeInstallForm.submit();
    });
  }

  // ---------- Модалка удаления темы ----------
  var tModal = document.getElementById('theme-delete-modal');
  if (tModal) {
    document.querySelectorAll('.js-theme-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('theme-delete-name').value = btn.dataset.name || '';
        document.getElementById('theme-delete-title').textContent = btn.dataset.title || '';
        tModal.hidden = false;
      });
    });
    tModal.addEventListener('click', function (e) { if (e.target === tModal) tModal.hidden = true; });
  }

  // ---------- Установка плагина: выбор файла сразу отправляет форму ----------
  var pluginZipInput   = document.getElementById('plugin-zip-input');
  var pluginInstallForm = document.getElementById('plugin-install-form');
  if (pluginZipInput && pluginInstallForm) {
    pluginZipInput.addEventListener('change', function () {
      if (pluginZipInput.files.length) pluginInstallForm.submit();
    });
  }

  // ---------- Модалка удаления плагина ----------
  var pModal = document.getElementById('plugin-delete-modal');
  if (pModal) {
    document.querySelectorAll('.js-plugin-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('plugin-delete-name').value = btn.dataset.name || '';
        document.getElementById('plugin-delete-title').textContent = btn.dataset.title || '';
        pModal.hidden = false;
      });
    });
    pModal.addEventListener('click', function (e) { if (e.target === pModal) pModal.hidden = true; });
  }

  // ---------- Модалка удаления бэкапа ----------
  var bModal = document.getElementById('backup-delete-modal');
  if (bModal) {
    document.querySelectorAll('.js-backup-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('backup-delete-file').value = btn.dataset.file || '';
        document.getElementById('backup-delete-title').textContent = btn.dataset.title || '';
        bModal.hidden = false;
      });
    });
    bModal.addEventListener('click', function (e) { if (e.target === bModal) bModal.hidden = true; });
  }

  // ---------- Модалка создания/редактирования категории ----------
  var ceModal   = document.getElementById('category-edit-modal');
  var ceForm    = document.getElementById('category-edit-form');
  var ceHeading = document.getElementById('category-edit-heading');
  var ceHint    = document.getElementById('category-edit-hint');
  var ceFrom    = document.getElementById('category-edit-from');
  var ceTitle   = document.getElementById('category-edit-title');
  var ceSlug    = document.getElementById('category-edit-slug');
  var ceDescription = document.getElementById('category-edit-description');
  var cePosition = document.getElementById('category-edit-position');
  var ceIcon = document.getElementById('category-edit-icon');
  wirePreview('category-edit-icon', 'category-edit-icon-preview');
  if (ceModal && ceForm) {
    // Автоподстановка ссылки из названия — и при создании, и при редактировании.
    // «Правили руками» = ссылка непустая и не совпадает со слагом названия: такую
    // не трогаем, чтобы не переехал устоявшийся URL (переезд сам по себе безопасен —
    // CategoryController ставит 301, — но менять кастомный слаг молча не нужно).
    // Очистили поле — автоподстановка снова включается, как в редакторе поста.
    var ceSlugTouched = true;
    function ceSyncTouched() {
      ceSlugTouched = ceSlug.value !== '' && ceSlug.value !== slugify(ceTitle.value);
    }
    ceSlug.addEventListener('input', ceSyncTouched);
    ceTitle.addEventListener('input', function () {
      if (!ceSlugTouched) ceSlug.value = slugify(ceTitle.value);
    });

    document.querySelectorAll('.js-category-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        ceForm.action = ceForm.dataset.saveUrl;
        ceHeading.textContent = dnT('Редактировать категорию');
        ceHint.hidden = false;
        ceFrom.value = btn.dataset.slug || '';
        ceTitle.value = btn.dataset.title || '';
        ceSlug.value = btn.dataset.slug || '';
        ceDescription.value = btn.dataset.description || '';
        if (cePosition) cePosition.value = btn.dataset.position || '0';
        if (ceIcon) { ceIcon.value = btn.dataset.icon || ''; ceIcon.dispatchEvent(new Event('input')); }
        ceSyncTouched(); // слаг совпадает с названием → правка названия обновит и ссылку
        ceModal.hidden = false;
      });
    });
    document.querySelectorAll('.js-category-new').forEach(function (btn) {
      btn.addEventListener('click', function () {
        ceForm.action = ceForm.dataset.createUrl;
        ceHeading.textContent = dnT('Новая категория');
        ceHint.hidden = true;
        ceFrom.value = '';
        ceTitle.value = '';
        ceSlug.value = '';
        ceDescription.value = '';
        if (cePosition) cePosition.value = '0';
        if (ceIcon) { ceIcon.value = ''; ceIcon.dispatchEvent(new Event('input')); }
        ceSyncTouched(); // оба поля пусты → автоподстановка включена
        ceModal.hidden = false;
      });
    });
    ceModal.addEventListener('click', function (e) { if (e.target === ceModal) ceModal.hidden = true; });
  }

  // ---------- Модалка удаления категории ----------
  var cdModal = document.getElementById('category-delete-modal');
  if (cdModal) {
    document.querySelectorAll('.js-category-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('category-delete-from').value = btn.dataset.slug || '';
        document.getElementById('category-delete-title').textContent = btn.dataset.title || '';
        cdModal.hidden = false;
      });
    });
    cdModal.addEventListener('click', function (e) { if (e.target === cdModal) cdModal.hidden = true; });
  }
})();

/* ============================================================
   Джамп-бар (⌘K): поиск по контенту и быстрые действия.
   Индекс приходит из layout.php (window.DEENO_PALETTE).
   ============================================================ */
(function () {
  'use strict';

  var modal   = document.getElementById('jump-modal');
  var input   = document.getElementById('jump-input');
  var list    = document.getElementById('jump-list');
  var trigger = document.getElementById('jump-trigger');
  if (!modal || !input || !list) return;

  var items  = window.DEENO_PALETTE || [];
  var shown  = [];
  var active = 0;

  // Подпись хоткея по платформе
  var isMac = /Mac|iPhone|iPad/.test(navigator.platform);
  var kbd = document.getElementById('jump-kbd');
  if (kbd) kbd.textContent = isMac ? '⌘ K' : 'Ctrl K';

  function open() {
    modal.hidden = false;
    input.value = '';
    render('');
    input.focus();
  }
  function close() { modal.hidden = true; }

  // Список строится DOM-узлами: заголовки не должны интерпретироваться как HTML
  function render(query) {
    var q = query.trim().toLowerCase();
    shown = items.filter(function (it) {
      return q === '' || it.title.toLowerCase().indexOf(q) !== -1;
    }).slice(0, 12);
    active = 0;

    list.textContent = '';
    if (!shown.length) {
      var empty = document.createElement('div');
      empty.className = 'jump__empty';
      empty.textContent = dnT('Ничего не найдено.');
      list.appendChild(empty);
      return;
    }

    var lastGroup = null;
    shown.forEach(function (it, i) {
      if (it.group !== lastGroup) {
        lastGroup = it.group;
        var g = document.createElement('div');
        g.className = 'jump__group';
        g.textContent = it.group;
        list.appendChild(g);
      }
      var a = document.createElement('a');
      a.className = 'jump__item' + (i === active ? ' active' : '');
      a.href = it.url;
      a.setAttribute('role', 'option');
      var titleSpan = document.createElement('span');
      titleSpan.textContent = it.title;
      a.appendChild(titleSpan);
      if (it.meta) {
        var meta = document.createElement('span');
        meta.className = 'jump__meta';
        meta.textContent = it.meta;
        a.appendChild(meta);
      }
      a.addEventListener('mousemove', function () { setActive(i); });
      list.appendChild(a);
    });
  }

  function setActive(i) {
    active = i;
    var els = list.querySelectorAll('.jump__item');
    els.forEach(function (el, j) { el.classList.toggle('active', j === active); });
    if (els[active]) els[active].scrollIntoView({ block: 'nearest' });
  }

  if (trigger) trigger.addEventListener('click', open);

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (modal.hidden) open(); else close();
      return;
    }
    if (modal.hidden) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(active + 1, shown.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(active - 1, 0)); }
    else if (e.key === 'Enter') {
      var el = list.querySelectorAll('.jump__item')[active];
      if (el) { e.preventDefault(); window.location.href = el.href; }
    }
  });

  input.addEventListener('input', function () { render(input.value); });
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
})();

// Автоприменение фильтров: селекты отправляют форму сразу, поиск — по Enter
(function () {
  'use strict';
  document.querySelectorAll('.js-autofilter select').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });
})();

// Линейный график просмотров: маркеры-точки и тултип при наведении.
// Вторая серия (уникальные посетители) появляется, только если счётчик уже
// собрал данные — тогда в разметке есть #uniq-chart-dot (см. dashboard.php).
(function () {
  'use strict';
  var chart = document.getElementById('views-chart');
  var tip = document.getElementById('views-chart-tip');
  var dot = document.getElementById('views-chart-dot');
  var uDot = document.getElementById('uniq-chart-dot');
  if (!chart || !tip || !dot) return;
  chart.querySelectorAll('.linechart__zone').forEach(function (zone) {
    zone.addEventListener('mouseenter', function () {
      var x = zone.dataset.x + '%';
      var y = Math.max(parseFloat(zone.dataset.y), 12) + '%';
      dot.style.left = x;
      dot.style.top = zone.dataset.y + '%';
      dot.hidden = false;
      var text = zone.dataset.day + ' — ' + zone.dataset.views;
      if (uDot) {
        uDot.style.left = x;
        uDot.style.top = zone.dataset.uy + '%';
        uDot.hidden = false;
        text += ' / ' + zone.dataset.uniq;
      }
      tip.textContent = text;
      tip.style.left = x;
      tip.style.top = y;
      tip.hidden = false;
    });
    zone.addEventListener('mouseleave', function () {
      dot.hidden = true;
      if (uDot) uDot.hidden = true;
      tip.hidden = true;
    });
  });
})();

/* ---------- Кастомный выбор даты и времени (отложенная публикация) ---------- */
(function () {
  'use strict';
  var pickers = document.querySelectorAll('.js-datepicker');
  if (!pickers.length) return;

  var lang = document.documentElement.lang || 'ru';
  var CAL = '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">'
    + '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/></svg></span>';
  function pad(n) { return String(n).padStart(2, '0'); }
  function toMachine(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
      + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  pickers.forEach(function (root) {
    var hidden = root.querySelector('input[type="hidden"]');
    var sel = hidden.value ? new Date(hidden.value.replace(' ', 'T')) : null;
    if (sel && isNaN(sel.getTime())) sel = null;
    var base = sel || new Date();
    var view = new Date(base.getFullYear(), base.getMonth(), 1);

    // ---- DOM: поле даты, ряд времени, попап-календарь ----
    var field = document.createElement('button');
    field.type = 'button';
    field.className = 'datepicker__field';
    field.innerHTML = '<span class="datepicker__value"></span>' + CAL;

    var hOpts = '', mOpts = '';
    for (var h = 0; h < 24; h++) hOpts += '<option value="' + h + '">' + pad(h) + '</option>';
    for (var mn = 0; mn < 60; mn += 5) mOpts += '<option value="' + mn + '">' + pad(mn) + '</option>';
    var timeRow = document.createElement('div');
    timeRow.className = 'datepicker__time';
    timeRow.innerHTML = '<span class="datepicker__time-label">' + dnT('Время') + '</span>'
      + '<div class="datepicker__time-controls">'
      + '<select class="datepicker__h">' + hOpts + '</select>'
      + '<span class="datepicker__colon">:</span>'
      + '<select class="datepicker__m">' + mOpts + '</select>'
      + '</div>';

    var pop = document.createElement('div');
    pop.className = 'datepicker__pop';
    pop.hidden = true;

    // поле даты и попап — в relative-обёртке, чтобы календарь был прямо под датой
    var dateWrap = document.createElement('div');
    dateWrap.className = 'datepicker__date';
    dateWrap.appendChild(field);
    dateWrap.appendChild(pop);
    root.appendChild(dateWrap);
    root.appendChild(timeRow);

    var valEl = field.querySelector('.datepicker__value');
    var hEl = timeRow.querySelector('.datepicker__h');
    var mEl = timeRow.querySelector('.datepicker__m');

    function syncTime() {
      hEl.value = sel ? sel.getHours() : 12;
      var mm = sel ? sel.getMinutes() : 0;
      mEl.value = mm - (mm % 5);
    }
    function fmtDate() {
      if (!sel) { valEl.textContent = dnT('Выберите дату'); valEl.classList.add('is-empty'); return; }
      valEl.classList.remove('is-empty');
      valEl.textContent = sel.toLocaleDateString(lang, { day: '2-digit', month: '2-digit', year: 'numeric' });
    }
    function commit() { hidden.value = sel ? toMachine(sel) : ''; fmtDate(); }

    function render() {
      var y = view.getFullYear(), m = view.getMonth();
      var mName = view.toLocaleDateString(lang, { month: 'long' });
      mName = mName.charAt(0).toUpperCase() + mName.slice(1);
      var chev = function (d) {
        return '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="'
          + (d < 0 ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6') + '"/></svg></span>';
      };
      var html = '<div class="datepicker__head">'
        + '<button type="button" class="datepicker__nav" data-nav="-1" aria-label="prev">' + chev(-1) + '</button>'
        + '<span class="datepicker__title">' + mName + ' ' + y + '</span>'
        + '<button type="button" class="datepicker__nav" data-nav="1" aria-label="next">' + chev(1) + '</button>'
        + '</div><div class="datepicker__grid">';
      var wd = new Date(2024, 0, 1); // понедельник
      for (var i = 0; i < 7; i++) {
        html += '<span class="datepicker__wd">' + wd.toLocaleDateString(lang, { weekday: 'short' }) + '</span>';
        wd.setDate(wd.getDate() + 1);
      }
      var offset = (new Date(y, m, 1).getDay() + 6) % 7; // Пн = 0
      var days = new Date(y, m + 1, 0).getDate();
      var t = new Date();
      for (var b = 0; b < offset; b++) html += '<span></span>';
      for (var day = 1; day <= days; day++) {
        var cls = 'datepicker__day';
        if (sel && sel.getFullYear() === y && sel.getMonth() === m && sel.getDate() === day) cls += ' is-selected';
        if (t.getFullYear() === y && t.getMonth() === m && t.getDate() === day) cls += ' is-today';
        html += '<button type="button" class="' + cls + '" data-day="' + day + '">' + day + '</button>';
      }
      pop.innerHTML = html + '</div>';
    }

    function open() { render(); pop.hidden = false; root.classList.add('is-open'); }
    function close() { pop.hidden = true; root.classList.remove('is-open'); }

    field.addEventListener('click', function () { pop.hidden ? open() : close(); });

    pop.addEventListener('click', function (e) {
      e.stopPropagation(); // клик внутри попапа не должен закрывать его (фикс скрытия при навигации)
      var nav = e.target.closest('[data-nav]');
      if (nav) { view.setMonth(view.getMonth() + parseInt(nav.dataset.nav, 10)); render(); return; }
      var dayBtn = e.target.closest('[data-day]');
      if (dayBtn) {
        var hh = parseInt(hEl.value, 10), mm = parseInt(mEl.value, 10);
        sel = new Date(view.getFullYear(), view.getMonth(), parseInt(dayBtn.dataset.day, 10), hh, mm);
        commit();
        close();
      }
    });

    function onTime() {
      if (!sel) { var n = new Date(); sel = new Date(n.getFullYear(), n.getMonth(), n.getDate()); }
      sel.setHours(parseInt(hEl.value, 10));
      sel.setMinutes(parseInt(mEl.value, 10));
      commit();
    }
    hEl.addEventListener('change', onTime);
    mEl.addEventListener('change', onTime);

    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });

    syncTime();
    fmtDate();
  });
})();

/* ---------- Кадрирование изображения перед загрузкой ---------- */
(function () {
  'use strict';
  var modal, stage, img, box, curFile, onCropCb, onCancelCb, ratio = 0;
  var orig, origUrl, rotation = 0;

  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  function iw() { return img.clientWidth; }
  function ih() { return img.clientHeight; }
  function getBox() { return { l: box.offsetLeft, t: box.offsetTop, w: box.offsetWidth, h: box.offsetHeight }; }

  // Записать бокс с клампом в границы изображения (w/h считаем валидными)
  function place(l, t, w, h) {
    w = clamp(w, 24, iw()); h = clamp(h, 24, ih());
    l = clamp(l, 0, iw() - w); t = clamp(t, 0, ih() - h);
    box.style.left = l + 'px'; box.style.top = t + 'px';
    box.style.width = w + 'px'; box.style.height = h + 'px';
  }

  // Центрированный бокс заданного соотношения (максимальный)
  function ratioBox(r) {
    var w = iw(), h = w / r;
    if (h > ih()) { h = ih(); w = h * r; }
    place((iw() - w) / 2, (ih() - h) / 2, w, h);
  }

  function setRatio(r) {
    ratio = r;
    box.classList.toggle('is-locked', r > 0);
    if (r > 0) ratioBox(r); else place(0, 0, iw(), ih());
  }

  function build() {
    modal = document.createElement('div');
    modal.className = 'crop-modal';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="crop-modal__box">' +
        '<div class="crop-modal__stage"><img alt=""><div class="crop-box">' +
          '<span class="crop-h" data-h="nw"></span><span class="crop-h" data-h="ne"></span>' +
          '<span class="crop-h" data-h="sw"></span><span class="crop-h" data-h="se"></span>' +
        '</div></div>' +
        '<div class="crop-modal__bar">' +
          '<div class="crop-modal__tools">' +
            '<div class="segmented crop-ratios">' +
              '<button type="button" class="segmented__btn is-active" data-r="0" title="' + dnT('Свободно') + '" aria-label="' + dnT('Свободно') + '">' +
                '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/></svg></span>' +
              '</button>' +
              '<button type="button" class="segmented__btn" data-r="1">1:1</button>' +
              '<button type="button" class="segmented__btn" data-r="1.33333">4:3</button>' +
              '<button type="button" class="segmented__btn" data-r="1.77778">16:9</button>' +
            '</div>' +
            '<button type="button" class="btn btn--secondary btn--icon crop-rotate" data-act="rotate" title="' + dnT('Повернуть') + '" aria-label="' + dnT('Повернуть') + '">' +
              '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></span>' +
            '</button>' +
          '</div>' +
          '<div class="crop-modal__actions">' +
            '<button type="button" class="btn btn--secondary btn--icon" data-act="cancel" title="' + dnT('Отмена') + '" aria-label="' + dnT('Отмена') + '">' +
              '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></span>' +
            '</button>' +
            '<button type="button" class="btn btn--primary btn--icon" data-act="crop" title="' + dnT('Обрезать') + '" aria-label="' + dnT('Обрезать') + '">' +
              '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    stage = modal.querySelector('.crop-modal__stage');
    img = modal.querySelector('img');
    box = modal.querySelector('.crop-box');
    wire();
  }

  function wire() {
    var mode = null, handle = null, rect, start, offX, offY;

    box.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      rect = img.getBoundingClientRect();
      start = getBox();
      if (e.target.classList.contains('crop-h')) {
        mode = 'resize'; handle = e.target.dataset.h;
      } else {
        mode = 'move';
        offX = (e.clientX - rect.left) - start.l;
        offY = (e.clientY - rect.top) - start.t;
      }
      box.setPointerCapture(e.pointerId);
    });

    box.addEventListener('pointermove', function (e) {
      if (!mode) return;
      // Если кнопка не зажата (перетаскивание прервалось вне окна) — сбросить режим,
      // иначе простое наведение курсора продолжало бы двигать/менять рамку.
      if ((e.buttons & 1) === 0) { mode = null; return; }
      var px = clamp(e.clientX - rect.left, 0, iw());
      var py = clamp(e.clientY - rect.top, 0, ih());
      if (mode === 'move') {
        place(px - offX, py - offY, start.w, start.h);
      } else if (ratio > 0) {
        // Ресайз с сохранением соотношения: противоположный угол закреплён,
        // тянем от него, вторую сторону считаем по соотношению, держим в границах.
        var ax = (handle === 'nw' || handle === 'sw') ? (start.l + start.w) : start.l;
        var ay = (handle === 'nw' || handle === 'ne') ? (start.t + start.h) : start.t;
        var dirX = (handle === 'ne' || handle === 'se') ? 1 : -1;
        var dirY = (handle === 'sw' || handle === 'se') ? 1 : -1;
        var wDrag = Math.abs(px - ax), hDrag = Math.abs(py - ay);
        var w, h;
        if (wDrag / hDrag > ratio) { w = wDrag; h = w / ratio; }
        else { h = hDrag; w = h * ratio; }
        var maxW = dirX > 0 ? iw() - ax : ax;
        var maxH = dirY > 0 ? ih() - ay : ay;
        if (w > maxW) { w = maxW; h = w / ratio; }
        if (h > maxH) { h = maxH; w = h * ratio; }
        place(dirX > 0 ? ax : ax - w, dirY > 0 ? ay : ay - h, w, h);
      } else {
        var right = start.l + start.w, bottom = start.t + start.h;
        var l = start.l, t = start.t, w = start.w, h = start.h;
        if (handle === 'se') { w = px - start.l; h = py - start.t; }
        else if (handle === 'nw') { l = px; t = py; w = right - px; h = bottom - py; }
        else if (handle === 'ne') { t = py; w = px - start.l; h = bottom - py; }
        else if (handle === 'sw') { l = px; w = right - px; h = py - start.t; }
        place(l, t, w, h);
      }
    });

    box.addEventListener('pointerup', function () { mode = null; });
    box.addEventListener('pointercancel', function () { mode = null; });
    box.addEventListener('lostpointercapture', function () { mode = null; });

    modal.querySelector('.crop-ratios').addEventListener('click', function (e) {
      var b = e.target.closest('[data-r]'); if (!b) return;
      modal.querySelectorAll('.crop-ratios .segmented__btn').forEach(function (x) { x.classList.remove('is-active'); });
      b.classList.add('is-active');
      setRatio(parseFloat(b.dataset.r));
    });

    modal.querySelector('.crop-rotate').addEventListener('click', function () {
      rotation = (rotation + 90) % 360;
      showImage();
    });

    modal.querySelector('.crop-modal__actions').addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return;
      if (b.dataset.act === 'cancel') { close(); if (onCancelCb) onCancelCb(); }
      else if (b.dataset.act === 'crop') exportCrop();
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) { close(); if (onCancelCb) onCancelCb(); } });
  }

  // Показать оригинал (rotation 0) или повёрнутую версию (через canvas → data:)
  function showImage() {
    if (rotation === 0) { img.src = origUrl; return; }
    var sw = orig.naturalWidth, sh = orig.naturalHeight, swap = rotation % 180 !== 0;
    var cv = document.createElement('canvas');
    cv.width = swap ? sh : sw; cv.height = swap ? sw : sh;
    var cx = cv.getContext('2d');
    cx.translate(cv.width / 2, cv.height / 2);
    cx.rotate(rotation * Math.PI / 180);
    cx.drawImage(orig, -sw / 2, -sh / 2);
    img.src = cv.toDataURL('image/png');
  }

  function exportCrop() {
    var g = getBox();
    var scale = img.naturalWidth / iw();
    var sw = Math.max(1, Math.round(g.w * scale)), sh = Math.max(1, Math.round(g.h * scale));
    var canvas = document.createElement('canvas');
    canvas.width = sw; canvas.height = sh;
    canvas.getContext('2d').drawImage(img, Math.round(g.l * scale), Math.round(g.t * scale), sw, sh, 0, 0, sw, sh);
    var type = /png|webp/.test(curFile.type) ? curFile.type : 'image/jpeg';
    canvas.toBlob(function (blob) {
      var f = new File([blob], curFile.name, { type: type });
      var done = onCropCb;
      close();
      if (done) done(f);
    }, type, 0.9);
  }

  function close() {
    if (!modal) return;
    modal.hidden = true;
    img.removeAttribute('src');
    if (origUrl) { URL.revokeObjectURL(origUrl); origUrl = ''; }
    orig = null;
  }

  // Открыть кадрирование для файла-изображения. onCrop(File) — результат, onCancel() — отмена.
  window.dnCrop = function (file, onCrop, onCancel) {
    if (!modal) build();
    curFile = file; onCropCb = onCrop; onCancelCb = onCancel;
    rotation = 0;
    // При каждой смене img.src (первый показ и повороты) — сброс рамки.
    // Модалку показываем ДО setRatio: иначе clientWidth изображения = 0 (display:none).
    img.onload = function () { modal.hidden = false; setRatio(0); };
    orig = new Image();
    orig.onload = showImage;
    origUrl = URL.createObjectURL(file);
    orig.src = origUrl;
  };

  // Можно ли кадрировать (растровое, не gif/pdf)
  window.dnCroppable = function (file) {
    return /^image\/(jpeg|png|webp)$/.test(file.type || '');
  };
})();
