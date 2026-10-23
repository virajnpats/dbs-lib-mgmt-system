<?php
/* queries.php - runs the important SQL queries of the project and shows their results. */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/queries_list.php';

$id = (int)($_GET['q'] ?? 1);
if (!isset(QUERY_LIST[$id])) {
    $id = 1;
}
$query = QUERY_LIST[$id];

$rows = [];
$error = '';
$bound = [];
$inputErrors = [];
try {
    if (!empty($query['params'])) {
        foreach ($query['params'] as $p) {
            $raw = trim((string)($_GET[$p['name']] ?? $p['default']));
            if ($p['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                $inputErrors[] = $p['label'] . ' must be a date.';
            }
            if ($p['type'] === 'number' && !preg_match('/^\d{1,4}$/', $raw)) {
                $inputErrors[] = $p['label'] . ' must be a year (whole number).';
            }
            if ($p['type'] === 'select' && !preg_match('/^\d+$/', $raw)) {
                $inputErrors[] = 'Select a ' . strtolower($p['label']) . '.';
            }
            $bound[$p['name']] = $raw;
        }
        if (!$inputErrors) {
            $rows = fetch_all(rtrim($query['sql'], ';'), array_values($bound));
        }
    } else {
        $rows = db()->query(rtrim($query['sql'], ';'))->fetchAll();
    }
} catch (Throwable $e) {
    $error = db_error_message($e);
}

page_header('Important SQL queries');
?>
<div class="query-list">
  <nav>
    <?php
    // Group the queries by SQL concept for the side menu.
    $groups = [];
    foreach (QUERY_LIST as $n => $item) {
        $groups[$item['group']][$n] = $item;
    }
    foreach ($groups as $groupName => $items): ?>
      <div class="group"><?= h($groupName) ?></div>
      <?php foreach ($items as $n => $item): ?>
        <a href="queries.php?q=<?= $n ?>" class="<?= $n === $id ? 'active' : '' ?>"><?= $n ?>. <?= h($item['title']) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <section class="card">
    <div class="card-head"><h2><?= $id ?>. <?= h($query['title']) ?></h2><span class="badge"><?= h($query['concept']) ?></span></div>
    <p><?= h($query['explain']) ?></p>
    <?php if (!empty($query['params'])): ?>
      <form class="filters" method="get">
        <input type="hidden" name="q" value="<?= $id ?>">
        <?php foreach ($query['params'] as $p): ?>
          <label><?= h($p['label']) ?>
            <?php if ($p['type'] === 'select'): ?>
              <select name="<?= h($p['name']) ?>"><?= options(fetch_all($p['sql']), 'id', 'label', $bound[$p['name']] ?? $p['default']) ?></select>
            <?php else: ?>
              <input type="<?= $p['type'] === 'date' ? 'date' : 'number' ?>" name="<?= h($p['name']) ?>" value="<?= h($bound[$p['name']] ?? $p['default']) ?>">
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <button style="align-self:end">Run query</button>
      </form>
    <?php endif; ?>
    <pre class="sql"><?= h($query['sql']) ?></pre>
    <?php if ($bound): ?><p class="muted">Bound values: <?= h(implode(', ', $bound)) ?></p><?php endif; ?>
    <?php foreach ($inputErrors as $ie): ?><div class="flash error"><?= h($ie) ?></div><?php endforeach; ?>
    <?php if ($error): ?>
      <div class="flash error"><?= h($error) ?></div>
    <?php elseif (!$inputErrors): ?>
      <?= result_table($rows) ?>
    <?php endif; ?>
  </section>
</div>
<?php page_footer();
