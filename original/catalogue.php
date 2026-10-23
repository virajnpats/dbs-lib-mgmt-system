<?php
/*
 * catalogue.php - CRUD for the master tables: AUTHOR, PUBLISHER, CATEGORY,
 * BRANCH, STAFF and MEMBERSHIP_PLAN. The table definitions and validation
 * rules are in includes/catalogue_config.php.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/catalogue_config.php';

$tab = $_GET['t'] ?? ($_POST['t'] ?? 'authors');
if (!isset(CATALOGUE[$tab])) {
    $tab = 'authors';
}
$cfg = CATALOGUE[$tab];
$table = $cfg['table'];          // from config, never from user input
$pk = $cfg['pk'];
$cols = array_keys($cfg['fields']);
$quoted = array_map(fn($c) => "`$c`", $cols);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $self = "catalogue.php?t=$tab";

    if ($action === 'save') {
        [$d, $errors] = validate_catalogue($tab, $_POST);
        if ($errors) {
            flash_errors($errors);
            remember_form($_POST);
            redirect($self . ($id ? "&edit=$id" : '') . '#form');
        }
        try {
            $values = array_values($d);
            if ($id === 0) {
                $id = next_id($table, $pk, $cfg['start']);
                $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
                execute("INSERT INTO $table ($pk, " . implode(', ', $quoted) . ") VALUES ($placeholders)",
                        array_merge([$id], $values));
                flash('success', "Record #$id added to $table.");
            } else {
                $set = implode(', ', array_map(fn($c) => "$c = ?", $quoted));
                execute("UPDATE $table SET $set WHERE $pk = ?", array_merge($values, [$id]));
                flash('success', "Record #$id in $table updated.");
            }
            redirect($self);
        } catch (Throwable $e) {
            flash('error', db_error_message($e));
            remember_form($_POST);
            redirect($self . (($_POST['id'] ?? '') !== '' ? "&edit=$id" : '') . '#form');
        }
    }

    if ($action === 'delete') {
        try {
            $n = execute("DELETE FROM $table WHERE $pk = ?", [$id]);
            flash($n ? 'success' : 'error', $n ? "Record #$id deleted." : 'Record not found.');
        } catch (Throwable $e) {
            flash('error', db_error_message($e));
        }
        redirect($self);
    }
}

// Option lists for foreign-key fields, also used to show labels in the table.
$fkOptions = [];
foreach ($cfg['fields'] as $name => $f) {
    if ($f['type'] === 'fk') {
        $fkOptions[$name] = fetch_all($f['sql']);
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$form = old_form();
if (!$form && $editId) {
    $form = fetch_one("SELECT * FROM $table WHERE $pk = ?", [$editId]) ?? [];
}

$q = trim($_GET['q'] ?? '');
$searchCols = array_filter($cols, fn($c) => in_array($cfg['fields'][$c]['type'], ['text', 'name', 'email', 'enum'], true));
$sql = "SELECT $pk, " . implode(', ', $quoted) . " FROM $table";
$params = [];
if ($q !== '' && $searchCols) {
    $sql .= ' WHERE ' . implode(' OR ', array_map(fn($c) => "`$c` LIKE ?", $searchCols));
    $params = array_fill(0, count($searchCols), "%$q%");
}
$rows = fetch_all($sql . " ORDER BY {$cfg['order']}", $params);

page_header('Catalogue: ' . $cfg['title']);
?>
<nav class="tabs">
  <?php foreach (CATALOGUE as $k => $c): ?>
    <a href="catalogue.php?t=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= h($c['title']) ?> <span class="muted">(<?= h($c['table']) ?>)</span></a>
  <?php endforeach; ?>
</nav>

<section class="card" id="form">
  <div class="card-head">
    <h2><?= $editId ? 'Edit #' . h($editId) : 'Add to ' . h($cfg['title']) ?></h2>
    <?php if ($editId): ?><a class="btn small secondary" href="catalogue.php?t=<?= $tab ?>">Cancel edit</a><?php endif; ?>
  </div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= h($tab) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= h($editId ?: '') ?>">
    <?php foreach ($cfg['fields'] as $name => $f):
        $val = $form[$name] ?? '';
        $req = !empty($f['required']) ? ' required' : ''; ?>
      <label><?= h($f['label']) ?><?= $req ? ' <span class="req">*</span>' : '' ?>
        <?php if ($f['type'] === 'enum'): ?>
          <select name="<?= $name ?>"<?= $req ?>><?= enum_options($f['values'], $val, '— Select —') ?></select>
        <?php elseif ($f['type'] === 'fk'): ?>
          <select name="<?= $name ?>"<?= $req ?>><?= options($fkOptions[$name], 'id', 'label', $val, '— Select —') ?></select>
        <?php elseif ($f['type'] === 'date'): ?>
          <input type="date" name="<?= $name ?>"<?= $req ?> <?= !empty($f['not_future']) ? 'max="' . date('Y-m-d') . '"' : '' ?> value="<?= h($val) ?>">
        <?php elseif ($f['type'] === 'int' || $f['type'] === 'decimal'): ?>
          <input type="number" name="<?= $name ?>"<?= $req ?> min="<?= h($f['min'] ?? '') ?>" max="<?= h($f['max'] ?? '') ?>" step="<?= $f['type'] === 'int' ? '1' : '0.01' ?>" value="<?= h($val) ?>">
        <?php else: ?>
          <input type="<?= $f['type'] === 'email' ? 'email' : ($f['type'] === 'url' ? 'url' : 'text') ?>" name="<?= $name ?>"<?= $req ?> maxlength="<?= h($f['max'] ?? 200) ?>" value="<?= h($val) ?>">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    <div class="actions"><button><?= $editId ? 'Save changes' : 'Add' ?></button></div>
  </form>
</section>

<section class="card">
  <form class="filters" method="get">
    <input type="hidden" name="t" value="<?= h($tab) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search <?= h(strtolower($cfg['title'])) ?>">
    <button>Search</button><a class="btn secondary" href="catalogue.php?t=<?= $tab ?>">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><?php foreach ($cfg['fields'] as $f): ?><th><?= h($f['label']) ?></th><?php endforeach; ?><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r[$pk]) ?></td>
        <?php foreach ($cfg['fields'] as $name => $f):
            $val = $r[$name];
            if ($f['type'] === 'fk') {
                foreach ($fkOptions[$name] as $o) { if ((string)$o['id'] === (string)$val) { $val = $o['label']; } }
            } ?>
          <td><?= $f['type'] === 'url' && $val ? '<a href="' . h($val) . '" target="_blank" rel="noopener">' . h($val) . '</a>' : h($val) ?></td>
        <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($cfg['fields']) + 2 ?>" class="muted">No records found.</td></tr><?php endif; ?>
        <td class="actions">
          <a class="btn small secondary" href="catalogue.php?t=<?= $tab ?>&amp;edit=<?= h($r[$pk]) ?>#form">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this record? Records still used elsewhere cannot be deleted.');">
            <?= csrf_field() ?><input type="hidden" name="t" value="<?= h($tab) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($r[$pk]) ?>">
            <button class="small danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($rows) ?> record(s) in <?= h($table) ?>.</p>
</section>
<?php page_footer();
