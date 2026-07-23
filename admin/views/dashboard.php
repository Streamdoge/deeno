<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $counters  @var Post[] $recent  @var array $checklist  @var string $adminBase
 *  @var int $viewsTotal  @var array $viewsDaily  @var array $topPages  @var array $sysinfo
 *  @var string $statsMonthLabel  @var int $inWork  @var int $views7  @var ?int $viewsTrend  @var ?int $backupDays */

$topMax = empty($topPages) ? 1 : max($topPages);

// Линейный график просмотров: монотонная кубическая интерполяция
// (Fritsch–Carlson, порт HourlyChart из deeno UI) — кривая не «перелетает» пики
$lcPath = function (array $p): string {
    $n = count($p);
    if ($n < 2) {
        return '';
    }
    if ($n === 2) {
        return sprintf('M%s,%s L%s,%s', $p[0]['x'], $p[0]['y'], $p[1]['x'], $p[1]['y']);
    }
    $dx = $sl = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $dx[$i] = $p[$i + 1]['x'] - $p[$i]['x'];
        $sl[$i] = ($p[$i + 1]['y'] - $p[$i]['y']) / $dx[$i];
    }
    $m = array_fill(0, $n, 0.0);
    $m[0] = $sl[0];
    $m[$n - 1] = $sl[$n - 2];
    for ($i = 1; $i < $n - 1; $i++) {
        $m[$i] = ($sl[$i - 1] == 0.0 || $sl[$i] == 0.0 || ($sl[$i - 1] > 0) !== ($sl[$i] > 0))
            ? 0.0 : ($sl[$i - 1] + $sl[$i]) / 2;
    }
    for ($i = 0; $i < $n - 1; $i++) {
        if ($sl[$i] == 0.0) {
            $m[$i] = $m[$i + 1] = 0.0;
            continue;
        }
        $a = $m[$i] / $sl[$i];
        $b = $m[$i + 1] / $sl[$i];
        $s = $a * $a + $b * $b;
        if ($s > 9) {
            $t2 = 3 / sqrt($s);
            $m[$i]     = $t2 * $a * $sl[$i];
            $m[$i + 1] = $t2 * $b * $sl[$i];
        }
    }
    $d = sprintf('M%s,%s ', $p[0]['x'], $p[0]['y']);
    for ($i = 0; $i < $n - 1; $i++) {
        $d .= sprintf('C%s,%s %s,%s %s,%s ',
            round($p[$i]['x'] + $dx[$i] / 3, 2),     round($p[$i]['y'] + $m[$i] * $dx[$i] / 3, 2),
            round($p[$i + 1]['x'] - $dx[$i] / 3, 2), round($p[$i + 1]['y'] - $m[$i + 1] * $dx[$i] / 3, 2),
            $p[$i + 1]['x'], $p[$i + 1]['y']);
    }
    return $d;
};

$chartVals = array_values($viewsDaily);
$chartDays = array_keys($viewsDaily);
// Уникальные приходят по тем же дням; обе серии на одной шкале, иначе линии
// нельзя сравнивать глазами. Уникальных всегда не больше просмотров.
$uniqVals  = array_values(array_map('intval', $uniqDaily ?? []));
$chartMax  = max(array_merge([0], $chartVals, $uniqVals));
$lcPoints  = [];
$lcUniq    = [];
$lcN       = count($chartVals);
$lcStep    = $lcN > 1 ? 100 / ($lcN - 1) : 100;
$lcY       = fn(int $v): float => round(92 - ($v / max(1, $chartMax)) * 84, 2);
foreach ($chartVals as $i => $v) {
    $u = (int)($uniqVals[$i] ?? 0);
    // 8% отступ сверху и снизу — место для маркера и тултипа
    $lcPoints[] = [
        'x' => round($i * $lcStep, 2),
        'y' => $lcY((int)$v),
        'v' => (int)$v,
        'u' => $u,
        'uy' => $lcY($u),
        'd' => date('d.m', (int)strtotime((string)$chartDays[$i])),
    ];
    $lcUniq[] = ['x' => round($i * $lcStep, 2), 'y' => $lcY($u)];
}
$lcLine = $lcPath($lcPoints);
$lcArea = $lcLine !== '' ? $lcLine . 'L100,100 L0,100 Z' : '';
// Вторую линию рисуем, только если уникальные вообще собирались: на данных,
// записанных до появления счётчика, она была бы прямой по нулю
$hasUniq     = array_sum($uniqVals) > 0;
$lcUniqLine  = $hasUniq ? $lcPath($lcUniq) : '';
?>

<div class="cards">
  <?php /* Порядок карточек: сначала то, за чем следят чаще всего (2026-07-20) */ ?>
  <div class="card stat">
    <span class="stat__label"><?= e(t('Просмотры · 7 дней')) ?></span>
    <span class="stat__num"><?= number_format((int)$views7, 0, '.', ' ') ?><?php if ($viewsTrend !== null): ?> <span class="stat__trend stat__trend--<?= $viewsTrend >= 0 ? 'up' : 'down' ?>"><?= $viewsTrend >= 0 ? '↑' : '↓' ?><?= abs((int)$viewsTrend) ?>%</span><?php endif; ?></span>
  </div>
  <div class="card stat">
    <span class="stat__label"><?= e(t('Опубликовано')) ?></span>
    <span class="stat__num"><?= (int)$counters['published'] ?></span>
  </div>
  <div class="card stat" title="<?= e(t('Черновики и отложенные публикации')) ?>">
    <span class="stat__label"><?= e(t('В работе')) ?></span>
    <span class="stat__num"><?= (int)$inWork ?></span>
  </div>
  <div class="card stat">
    <span class="stat__label"><?= e(t('Последний бэкап')) ?></span>
    <?php if ($backupDays === null): ?>
      <span class="stat__num stat__num--warn"><?= e(t('нет')) ?></span>
    <?php elseif ($backupDays === 0): ?>
      <span class="stat__num"><?= e(t('сегодня')) ?></span>
    <?php else: ?>
      <span class="stat__num<?= $backupDays > 7 ? ' stat__num--warn' : '' ?>"><?= (int)$backupDays ?> <span class="stat__unit"><?= e(t('дн. назад')) ?></span></span>
    <?php endif; ?>
  </div>
</div>

<div class="grid-main">

  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="card__title" title="<?= e(t('Ваши собственные заходы не учитываются: пока вы вошли в админку, открытые вами страницы в статистику не попадают. Не считаются и запросы поисковых роботов.')) ?>"><?= e(t('Просмотры')) ?></h2>
        <p class="card__sub"><?= e(t('за')) ?> <?= e($statsMonthLabel) ?> · <?= (int)$viewsTotal ?></p>
      </div>
      <?php if ($hasUniq): ?>
        <div class="chart-legend">
          <span class="chart-legend__item"><span class="chart-legend__swatch"></span><?= e(t('Просмотры')) ?></span>
          <span class="chart-legend__item"><span class="chart-legend__swatch chart-legend__swatch--uniq"></span><?= e(t('Уникальные')) ?></span>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($chartMax === 0): ?>
      <p class="muted" style="margin:16px 0 0"><?= e(t('Пока нет просмотров.')) ?></p>
    <?php else: ?>
      <div class="linechart" id="views-chart" aria-hidden="true">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none">
          <defs>
            <linearGradient id="views-fill" x1="0" y1="0" x2="0" y2="1">
              <stop class="linechart__stop-a" offset="0%"/>
              <stop class="linechart__stop-b" offset="100%"/>
            </linearGradient>
          </defs>
          <path d="<?= e($lcArea) ?>" fill="url(#views-fill)" stroke="none"/>
          <path class="linechart__line" d="<?= e($lcLine) ?>" vector-effect="non-scaling-stroke"/>
          <?php if ($lcUniqLine !== ''): ?>
            <path class="linechart__line linechart__line--uniq" d="<?= e($lcUniqLine) ?>" vector-effect="non-scaling-stroke"/>
          <?php endif; ?>
        </svg>
        <?php foreach ($lcPoints as $pt): ?>
          <div class="linechart__zone"
               style="left:<?= $pt['x'] ?>%;width:<?= round($lcStep, 2) ?>%"
               data-x="<?= $pt['x'] ?>" data-y="<?= $pt['y'] ?>"
               data-uy="<?= $pt['uy'] ?>"
               data-day="<?= e($pt['d']) ?>" data-views="<?= $pt['v'] ?>"
               data-uniq="<?= $pt['u'] ?>"
               title="<?= e($pt['d']) ?> — <?= $pt['v'] ?><?= $hasUniq ? ' / ' . $pt['u'] : '' ?>"></div>
        <?php endforeach; ?>
        <span class="linechart__dot" id="views-chart-dot" hidden></span>
        <?php if ($hasUniq): ?>
          <span class="linechart__dot linechart__dot--uniq" id="uniq-chart-dot" hidden></span>
        <?php endif; ?>
        <div class="linechart__tip" id="views-chart-tip" hidden></div>
      </div>
      <div class="linechart__x">
        <span><?= e(date('d.m', (int)strtotime((string)($chartDays[0] ?? 'now')))) ?></span>
        <span><?= e(date('d.m', (int)strtotime((string)($chartDays[(int)(count($chartDays) / 2)] ?? 'now')))) ?></span>
        <span><?= e(date('d.m', (int)strtotime((string)(end($chartDays) ?: 'now')))) ?></span>
      </div>
      <?php /* Обёртка-div обязательна: класс не схлопывает саму таблицу (см. admin.css) */ ?>
      <div class="visually-hidden">
      <table>
        <caption><?= e(t('Просмотры по дням')) ?></caption>
        <thead>
          <tr>
            <th scope="col"><?= e(t('Дата')) ?></th>
            <th scope="col"><?= e(t('Просмотры')) ?></th>
            <?php if ($hasUniq): ?><th scope="col"><?= e(t('Уникальные')) ?></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($viewsDaily as $day => $v): ?>
            <tr>
              <th scope="row"><?= e($day) ?></th>
              <td><?= (int)$v ?></td>
              <?php if ($hasUniq): ?><td><?= (int)($uniqDaily[$day] ?? 0) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-stack">
    <?php /* «Последние материалы» скрыт по решению пользователя (2026-07-12).
             Вернуть: убрать false && */ ?>
    <?php if (false): ?>
    <div class="card">
      <h2 class="card__title"><?= e(t('Последние материалы')) ?></h2>
      <?php if (empty($recent)): ?>
        <p class="muted"><?= e(t('Постов пока нет.')) ?></p>
      <?php else: ?>
        <table class="table">
          <?php foreach ($recent as $p): ?>
            <tr>
              <td class="table__title"><a href="<?= e($adminBase) ?>posts/edit/?file=<?= e(urlencode(basename($p->filePath))) ?>"><?= e($p->title ?: $p->slug) ?></a></td>
              <td><span class="badge badge--<?= e($p->status) ?>"><?= e($p->status) ?></span></td>
              <td class="muted num"><?= e($p->dateModified()) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card card--sub-title">
      <div class="card__head">
        <div>
          <h2 class="card__title"><?= e(t('Популярные страницы')) ?></h2>
          <p class="card__sub"><?= e(t('за')) ?> <?= e($statsMonthLabel) ?></p>
        </div>
      </div>
      <?php if (empty($topPages)): ?>
        <p class="muted" style="margin:0"><?= e(t('Пока нет просмотров.')) ?></p>
      <?php else: ?>
        <ul class="toplist">
          <?php foreach ($topPages as $path => $views): ?>
            <li class="toplist__row">
              <a class="toplist__path" href="<?= e($path) ?>" target="_blank" title="<?= e($path) ?>"><?= e($path) ?></a>
              <span class="toplist__views"><?= (int)$views ?></span>
              <span class="toplist__bar"><span style="width:<?= (int)round($views / $topMax * 100) ?>%"></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

  </div>

</div>

<?php /* --equal: «Система» и «Безопасность» тянутся до одной высоты — рядом
         стоящие карточки одного смысла, разнобой по высоте выглядит небрежно */ ?>
<div class="grid-2 grid-2--equal">

  <div class="card">
    <h2 class="card__title"><?= e(t('Система')) ?></h2>
    <dl class="sysinfo">
      <?php foreach ($sysinfo as $k => $v): ?>
        <div class="sysinfo__row">
          <dt><?= e((string)$k) ?></dt>
          <dd><?= e((string)$v) ?></dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </div>

  <div class="card">
    <h2 class="card__title"><?= e(t('Безопасность')) ?></h2>
    <ul class="checklist">
      <?php foreach ($checklist as $label => $ok): ?>
        <li class="<?= $ok ? 'ok' : 'warn' ?>"><?= icon($ok ? 'check' : 'alert') ?><?= e(t($label)) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

</div>
