#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# UL CMS — smoke-тесты (интеграция, HTTP).
# Разворачивает ИЗОЛИРОВАННУЮ копию во временной папке (реальные
# content/users/config/media НЕ трогаются), поднимает php -S, дёргает
# реальные URL и проверяет коды. Ловит роутинг/видимость статусов/
# режим обслуживания — то, что юнит-тесты не видят.
# Запуск: bash tests/smoke.sh   (код возврата 0 — всё ок, 1 — падения)
# ─────────────────────────────────────────────────────────────
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${SMOKE_PORT:-8123}"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
JAR="$TMP/cookies.txt"
SRV=""
PASS=0
FAIL=0

cleanup() {
    [ -n "$SRV" ] && kill "$SRV" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

# check "описание" ОЖИДАЕМЫЙ_КОД [доп. аргументы curl...]
check() {
    local desc="$1" exp="$2"; shift 2
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' "$@")"
    if [ "$code" = "$exp" ]; then
        PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s (%s)\n' "$desc" "$code"
    else
        FAIL=$((FAIL + 1)); printf '  \033[31m✗ %s — ожидал %s, получил %s\033[0m\n' "$desc" "$exp" "$code"
    fi
}

# ── 1. Изолированная копия кода ──
rsync -a \
    --exclude='.git' --exclude='node_modules' \
    --exclude='content/posts/*' --exclude='content/pages/*' \
    --exclude='media/*' --exclude='cache/*' --exclude='backups/*' \
    --exclude='users/*' --exclude='config.php' --exclude='config.json' \
    --exclude='system/secret.key' --exclude='system/security-data.php' \
    --exclude='system/logs/*' --exclude='system/categories.php' \
    --exclude='system/redirects.json' --exclude='_*' \
    "$ROOT/" "$TMP/" >/dev/null

mkdir -p "$TMP"/content/posts "$TMP"/content/pages "$TMP"/media \
         "$TMP"/cache "$TMP"/backups "$TMP"/users "$TMP"/system/logs
rm -f "$TMP/install.php"   # чтобы не редиректило в мастер установки

GUARD="<?php http_response_code(403); exit('UL CMS'); ?>"

# ── 2. Конфиг ──
cat > "$TMP/config.php" <<EOF
$GUARD
{"site_title":"Smoke","site_url":"$BASE","theme":"default","language":"ru","timezone":"UTC","posts_per_page":10,"cache_enabled":false,"maintenance_mode":false,"rss_enabled":true,"sitemap_enabled":true,"plugins":[]}
EOF

# ── 3. Админ (пароль smoke12345) ──
HASH="$(php -r 'echo password_hash("smoke12345", PASSWORD_BCRYPT, ["cost" => 8]);')"
cat > "$TMP/users/admin.php" <<EOF
$GUARD
{"username":"admin","display_name":"Admin","email":"a@b.c","password":"$HASH","role":"admin","language":"ru","created":"2026-01-01T00:00:00+00:00","active":true}
EOF

# Автор (пароль тот же smoke12345) — для проверок разграничения прав
cat > "$TMP/users/petya.php" <<EOF
$GUARD
{"username":"petya","display_name":"Petya","email":"p@b.c","password":"$HASH","role":"author","language":"ru","created":"2026-01-01T00:00:00+00:00","active":true}
EOF

# ── 4. Тестовые посты ──
# Пост админа — «жертва» в проверках прав ниже. Отдельный файл, а не hello.md:
# тот участвует в тестах расстановки и меняется по ходу прогона.
cat > "$TMP/content/posts/owned-by-admin.md" <<'EOF'
---
title: Пост админа
slug: owned-by-admin
status: published
author: admin
date: 2026-01-01
---
Оригинальный текст. Автор не должен его перезаписать.
EOF
cat > "$TMP/content/posts/hello.md" <<'EOF'
---
title: Привет мир
slug: hello
status: published
category: novosti
date: 2026-01-01
---
Тело опубликованного поста.
EOF
cat > "$TMP/content/posts/draft.md" <<'EOF'
---
title: Черновик
slug: secret-draft
status: draft
date: 2026-01-01
---
Черновик не виден гостям.
EOF
cat > "$TMP/content/posts/future.md" <<'EOF'
---
title: Отложенный
slug: future-post
status: scheduled
scheduled_date: 2099-01-01T09:00
date: 2099-01-01
---
Ещё не время.
EOF
cat > "$TMP/content/posts/link.md" <<'EOF'
---
title: Вне списков
slug: unlisted-post
status: unlisted
date: 2026-01-01
---
Доступен по прямой ссылке.
EOF
cat > "$TMP/content/pages/about.md" <<'EOF'
---
title: О нас
slug: about
status: published
---
Про нас.
EOF
cat > "$TMP/content/pages/hidden.md" <<'EOF'
---
title: Скрытая
slug: hidden-page
status: draft
---
Черновик-страница.
EOF

# ── 5. Старт сервера ──
pushd "$TMP" >/dev/null
php -S "127.0.0.1:$PORT" index.php >"$TMP/server.log" 2>&1 &
SRV=$!
popd >/dev/null

# ждём готовности
for _ in $(seq 1 20); do
    curl -s -o /dev/null "$BASE/" && break
    sleep 0.3
done

echo "Публичные маршруты:"
check "главная → 200"            200 "$BASE/"
check "пост → 200"               200 "$BASE/novosti/hello/"
check "категория → 200"          200 "$BASE/novosti/"
check "несуществующее → 404"     404 "$BASE/net/takogo/"
check "rss.xml → 200"            200 "$BASE/rss.xml"
check "sitemap.xml → 200"        200 "$BASE/sitemap.xml"

echo "Видимость по статусу:"
check "черновик-пост скрыт → 404"    404 "$BASE/posts/secret-draft/"
check "отложенный (будущее) → 404"   404 "$BASE/posts/future-post/"
check "unlisted по ссылке → 200"     200 "$BASE/posts/unlisted-post/"
check "страница published → 200"     200 "$BASE/about/"
check "черновик-страница → 404"      404 "$BASE/hidden-page/"

echo "Авторизация и защита:"
CSRF="$(curl -s -c "$JAR" "$BASE/admin/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$BASE/admin/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "username=admin" --data-urlencode "password=smoke12345"
check "админ вошёл: /admin/ → 200"          200 -b "$JAR" "$BASE/admin/"
check "CSRF: POST без токена → 403"          403 -b "$JAR" -X POST "$BASE/admin/settings/"

# Экран входа — на языке браузера: там ещё некому было выбрать язык, а настройка
# сайта к постороннему посетителю отношения не имеет
if curl -s -H 'Accept-Language: en-US,en;q=0.9' "$BASE/admin/" | grep -q '>Log in<'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m вход: английский браузер → английский экран\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ вход: английский браузер получил не английский экран\033[0m\n'
fi
if curl -s -H 'Accept-Language: ru-RU,ru;q=0.9' "$BASE/admin/" | grep -q '>Войти<'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m вход: русский браузер → русский экран\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ вход: русский браузер получил не русский экран\033[0m\n'
fi
if curl -s -H 'Accept-Language: de-DE,de;q=0.9' "$BASE/admin/" | grep -q '>Войти<'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m вход: незнакомый язык → язык сайта\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ вход: незнакомый язык не откатился на язык сайта\033[0m\n'
fi

# Личные настройки панели (тема и язык) переехали из сайдбара в Настройки.
# Раздел теперь открыт всем ролям, но не-админ видит только эту карточку.
SET_HTML="$(curl -s -b "$JAR" "$BASE/admin/settings/")"
if printf '%s' "$SET_HTML" | grep -q 'name="admin_theme"' && printf '%s' "$SET_HTML" | grep -q 'name="admin_lang"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m настройки: карточка «Панель управления» на месте\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ настройки: нет полей темы/языка\033[0m\n'
fi
if printf '%s' "$(curl -s -b "$JAR" "$BASE/admin/")" | grep -q 'id="theme-toggle"'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ сайдбар: старый переключатель темы не убран\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m сайдбар: переключатели убраны\n'
fi
# Выбор темы и языка сохраняется В ПРОФИЛЬ, а не только в сессию.
# Сохранение настроек перезаписывает config целиком, поэтому бэкапим и возвращаем —
# иначе следующие проверки поедут на изменённом конфиге.
cp "$TMP/config.php" "$TMP/config.before-ui.php"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/settings/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "admin_theme=dark" --data-urlencode "admin_lang=en" \
    --data-urlencode "site_title=Smoke" --data-urlencode "posts_per_page=10"
if grep -qE '"admin_theme": *"dark"' "$TMP/users/admin.php" \
   && grep -qE '"language": *"en"' "$TMP/users/admin.php"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m тема и язык записались в профиль пользователя\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ тема/язык не сохранились в профиль\033[0m\n'
fi
mv "$TMP/config.before-ui.php" "$TMP/config.php"

echo "Расстановка (drag-and-drop):"
check "reorder без CSRF → 403"   403 -b "$JAR" -X POST "$BASE/admin/reorder/" \
    --data-urlencode 'data={"sections":[{"category":"tech","posts":["hello.md"]}]}'
check "reorder с CSRF → 200"     200 -b "$JAR" -X POST "$BASE/admin/reorder/" \
    --data-urlencode "csrf=$CSRF" \
    --data-urlencode 'data={"sections":[{"category":"tech","posts":["hello.md"]}]}'
if grep -q 'category: tech' "$TMP/content/posts/hello.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m reorder: пост перенесён в tech (файл обновлён)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ reorder: категория поста не изменилась в файле\033[0m\n'
fi
check "reorder-редирект старого URL → 301"   301 "$BASE/novosti/hello/"

# Номера пишутся только при ручном порядке: иначе дерево всё равно рендерится
# расчётом (алфавит/даты) и записанный position молча игнорировался бы.
# Перенос в другой раздел обязан работать при любом режиме.
# Заведомый номер: если правило сломается, reorder перепишет его на 0. Именно
# ЗАМЕНА существующего поля (его проставил предыдущий reorder), а не вставка
# нового — иначе в шапке оказались бы два position и парсер взял бы последний.
sed -i.bak 's/^position: .*/position: 7/' "$TMP/content/posts/hello.md"
POS_BEFORE="$(grep -E '^position:' "$TMP/content/posts/hello.md" | head -1)"
sed -i.bak 's/"posts_per_page":10/"posts_per_page":10,"article_order":"alpha"/' "$TMP/config.php"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/reorder/" \
    --data-urlencode "csrf=$CSRF" \
    --data-urlencode 'data={"sections":[{"category":"novosti","posts":["hello.md"]}]}'
POS_AFTER="$(grep -E '^position:' "$TMP/content/posts/hello.md" | head -1)"
if [ "$POS_BEFORE" = "position: 7" ] && [ "$POS_AFTER" = "position: 7" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m не ручной порядок: position в файле не переписан\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ не ручной порядок: position изменился (%s → %s)\033[0m\n' "$POS_BEFORE" "$POS_AFTER"
fi
if grep -q 'category: novosti' "$TMP/content/posts/hello.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m не ручной порядок: перенос между разделами всё равно работает\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ не ручной порядок: категория не изменилась\033[0m\n'
fi
# Тема должна получить признак режима: 0 — расставлять номера нельзя, дерево
# рендерится расчётом; 1 — ручной порядок, перетаскивание внутри раздела имеет смысл
sed -i.bak 's/"theme":"default"/"theme":"deeno-docs"/' "$TMP/config.php"
if curl -s -b "$JAR" "$BASE/" | grep -q 'data-reorder-manual="0"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m тема deeno-docs получила data-reorder-manual="0"\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ тема не получила признак не-ручного порядка\033[0m\n'
fi
sed -i.bak 's/,"article_order":"alpha"/,"article_order":"manual"/' "$TMP/config.php"
if curl -s -b "$JAR" "$BASE/" | grep -q 'data-reorder-manual="1"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m при ручном порядке признак меняется на "1"\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ при ручном порядке признак не стал "1"\033[0m\n'
fi
if curl -s "$BASE/" | grep -q 'data-reorder-manual'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ гостю отдаются данные для расстановки\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m гостю дерево отдаётся без данных расстановки\n'
fi
sed -i.bak 's/"theme":"deeno-docs"/"theme":"default"/; s/,"article_order":"manual"//' "$TMP/config.php"

echo "Content-Security-Policy:"
CSP_HDR="$(curl -s -D- -o /dev/null "$BASE/" | grep -i '^content-security-policy:')"
CSP_SCRIPT="$(printf '%s' "$CSP_HDR" | grep -oiE "script-src[^;]*")"
if printf '%s' "$CSP_HDR" | grep -q .; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m заголовок отдаётся публичной частью\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ CSP-заголовок не отдаётся\033[0m\n'
fi
# Главный смысл ужесточения: чужой скрипт не выполнится ни со стороннего
# домена, ни из тела страницы. Раньше политика разрешала и то, и другое.
if printf '%s' "$CSP_SCRIPT" | grep -q "unsafe-inline"; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ script-src разрешает unsafe-inline\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m script-src без unsafe-inline\n'
fi
if printf '%s' "$CSP_SCRIPT" | grep -qE "https:( |;|$)"; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ script-src разрешает любой https-домен\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m script-src не разрешает произвольные домены\n'
fi
# Отпечаток обязан быть в кавычках: без них браузер игнорирует токен и
# блокирует инлайн-скрипт темы (анти-мигание тёмной темы). Ошибки в консоли
# при этом нет — сайт просто перестаёт применять сохранённую тему.
#
# Проверяем на deeno-news: в теме default инлайн-скриптов нет, отпечатку
# взяться неоткуда и проверка была бы бессмысленно зелёной (или ложно красной).
sed -i.bak 's/"theme":"default"/"theme":"deeno-news"/' "$TMP/config.php"
CSP_INLINE="$(curl -s -D- -o /dev/null "$BASE/" | grep -i '^content-security-policy:' | grep -oiE "script-src[^;]*")"
if printf '%s' "$CSP_INLINE" | grep -qE "'sha256-[A-Za-z0-9+/]+={0,2}'"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m отпечаток инлайн-скрипта в кавычках (иначе браузер его игнорирует)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ отпечаток без кавычек или отсутствует: %s\033[0m\n' "$CSP_INLINE"
fi
sed -i.bak 's/"theme":"deeno-news"/"theme":"default"/' "$TMP/config.php"
# Видео вставляется движком как iframe на youtube-nocookie/vimeo — политика
# обязана их пропускать, иначе ролики в статьях перестанут проигрываться
if printf '%s' "$CSP_HDR" | grep -q "youtube-nocookie.com" && printf '%s' "$CSP_HDR" | grep -q "player.vimeo.com"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m frame-src пропускает видео YouTube/Vimeo\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ frame-src не пропускает видео — ролики сломаются\033[0m\n'
fi
# Домены из настройки должны попадать в политику, иначе счётчик молча не работает
sed -i.bak 's/"plugins":\[\]/"plugins":[],"external_scripts":"mc.yandex.ru"/' "$TMP/config.php"
if curl -s -D- -o /dev/null "$BASE/" | grep -i '^content-security-policy:' | grep -q "https://mc.yandex.ru"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m домен счётчика из настроек попадает в политику\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ домен счётчика не попал в политику\033[0m\n'
fi
sed -i.bak 's/,"external_scripts":"mc.yandex.ru"//' "$TMP/config.php"
# У админки своя политика с nonce (её страницы не кэшируются). Фронтовый
# заголовок не должен её перекрывать — header() затёр бы админскую.
if curl -s -D- -o /dev/null -b "$JAR" "$BASE/admin/" | grep -i '^content-security-policy:' | grep -q "nonce-"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m админка сохраняет свой CSP с nonce\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ админка потеряла свой CSP с nonce\033[0m\n'
fi

echo "Разграничение прав (роль author):"
JAR2="$TMP/cookies-author.txt"
CSRF_A="$(curl -s -c "$JAR2" "$BASE/admin/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$BASE/admin/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "username=petya" --data-urlencode "password=smoke12345"
check "author вошёл: /admin/ → 200"   200 -b "$JAR2" "$BASE/admin/"

# Чужой пост нельзя перезаписать, подставив его имя файла в форму. До фикса
# маршрут сохранения проверял только CSRF: author затирал любой пост и
# становился его автором. Проверка на открытие редактора этого не ловила —
# форму можно не открывать, а отправить POST напрямую.
check "author: сохранение ЧУЖОГО поста → 403"   403 -b "$JAR2" -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "file=owned-by-admin.md" \
    --data-urlencode "title=ВЗЛОМАНО" --data-urlencode "content=подменено" --data-urlencode "status=published"
if grep -q 'title: Пост админа' "$TMP/content/posts/owned-by-admin.md" \
   && grep -q 'author: admin' "$TMP/content/posts/owned-by-admin.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m чужой пост не изменился (заголовок и автор на месте)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ чужой пост перезаписан автором\033[0m\n'
fi
check "author: удаление ЧУЖОГО поста → 403"     403 -b "$JAR2" -X POST "$BASE/admin/posts/delete/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "file=owned-by-admin.md"
if [ -f "$TMP/content/posts/owned-by-admin.md" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m чужой пост не удалён\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ автор удалил чужой пост\033[0m\n'
fi

# Обратная сторона: собственная работа автора не должна пострадать от проверки
check "author: свой новый пост сохраняется → 302"  302 -b "$JAR2" -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "file=" \
    --data-urlencode "title=Пост автора" --data-urlencode "content=текст" --data-urlencode "status=draft"
if ls "$TMP"/content/posts/*post-avtora*.md >/dev/null 2>&1; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m свой пост создан (файл на диске)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ автор не смог создать свой пост\033[0m\n'
fi

# Разделы, закрытые для роли author по модели прав (раздел 10 ТЗ)
check "author: страницы закрыты → 403"       403 -b "$JAR2" "$BASE/admin/pages/"
check "author: категории закрыты → 403"      403 -b "$JAR2" "$BASE/admin/categories/"
check "author: пользователи закрыты → 403"   403 -b "$JAR2" "$BASE/admin/users/"
check "author: плагины закрыты → 403"        403 -b "$JAR2" "$BASE/admin/plugins/"
check "author: темы закрыты → 403"           403 -b "$JAR2" "$BASE/admin/themes/"
check "author: бэкапы закрыты → 403"         403 -b "$JAR2" "$BASE/admin/backups/"
check "author: расстановка закрыта → 403"    403 -b "$JAR2" -X POST "$BASE/admin/reorder/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode 'data={"sections":[]}'
# Личные настройки панели открыты всем ролям — это не дыра, а осознанное решение
check "author: настройки панели доступны → 200"  200 -b "$JAR2" "$BASE/admin/settings/"

echo "Режим обслуживания:"
sed -i.bak 's/"maintenance_mode":false/"maintenance_mode":true/' "$TMP/config.php"
check "обслуживание: гость → 503"            503 "$BASE/"
check "обслуживание: админ проходит → 200"   200 -b "$JAR" "$BASE/"

echo ""
if [ "$FAIL" -eq 0 ]; then
    printf '\033[32mSmoke: все %d проверок прошли ✓\033[0m\n' "$PASS"
    exit 0
else
    printf '\033[31mSmoke: %d из %d упали ✗\033[0m\n' "$FAIL" "$((PASS + FAIL))"
    exit 1
fi
