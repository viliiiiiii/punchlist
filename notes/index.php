<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$meId = (int)(current_user()['id'] ?? 0);
$pdo  = get_pdo();

/* ---------- Local fallbacks if helpers aren't defined ---------- */
if (!function_exists('notes__table_exists')) {
  function notes__table_exists(PDO $pdo, string $tbl): bool {
    try {
      $st = $pdo->prepare("SHOW TABLES LIKE ?");
      $st->execute([$tbl]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('notes__col_exists')) {
  function notes__col_exists(PDO $pdo, string $tbl, string $col): bool {
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM `{$tbl}` LIKE ?");
      $st->execute([$col]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

/* ---------- Detect optional schema features ---------- */
$hasSharesTbl  = notes__table_exists($pdo, 'notes_shares');
$sharesHasUser = $hasSharesTbl && notes__col_exists($pdo, 'notes_shares', 'user_id');
$sharesHasOld  = $hasSharesTbl && notes__col_exists($pdo, 'notes_shares', 'shared_with');
$sharesCol     = $sharesHasUser ? 'user_id' : ($sharesHasOld ? 'shared_with' : null);

$hasNoteDate   = notes__col_exists($pdo, 'notes', 'note_date');
$hasCreatedAt  = notes__col_exists($pdo, 'notes', 'created_at');
$hasPhotosTbl  = notes__table_exists($pdo, 'note_photos');
$hasCommentsTbl= notes__table_exists($pdo, 'note_comments');

/* ---------- Filters ---------- */
$search = trim((string)($_GET['q'] ?? ''));
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));

$where  = [];
$params = [];

/* Text filter */
if ($search !== '') {
  $where[]        = '(n.title LIKE :q OR COALESCE(n.body,"") LIKE :q)';
  $params[':q']   = '%'.$search.'%';
}
/* Date filters */
if ($hasNoteDate && $from !== '') { $where[] = 'n.note_date >= :from'; $params[':from'] = $from; }
if ($hasNoteDate && $to   !== '') { $where[] = 'n.note_date <= :to';   $params[':to']   = $to;   }

/* ---------- Visibility (non-admin semantics) ---------- */
/* Only owner or explicitly shared-with-me. If shares table/column missing, show own notes only. */
if ($sharesCol) {
  $where[] = "(n.user_id = :me1
              OR EXISTS (SELECT 1 FROM notes_shares s
                         WHERE s.note_id = n.id
                           AND s.{$sharesCol} = :me2))";
  $params[':me1'] = $meId;
  $params[':me2'] = $meId;
  $isSharedExpr   = "EXISTS(SELECT 1 FROM notes_shares s WHERE s.note_id = n.id AND s.{$sharesCol} = :me2) AS is_shared";
} else {
  $where[]        = "n.user_id = :me1";
  $params[':me1'] = $meId;
  $isSharedExpr   = "0 AS is_shared";
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- Other selectable columns ---------- */
$photoCountExpr = $hasPhotosTbl
  ? "(SELECT COUNT(*) FROM note_photos p WHERE p.note_id = n.id) AS photo_count"
  : "0 AS photo_count";
$commentCountExpr = $hasCommentsTbl
  ? "(SELECT COUNT(*) FROM note_comments c WHERE c.note_id = n.id) AS comment_count"
  : "0 AS comment_count";

/* ---------- Ordering ---------- */
$orderParts = [];
if ($hasNoteDate)  { $orderParts[] = "n.note_date DESC"; }
if ($hasCreatedAt) { $orderParts[] = "n.created_at DESC"; }
$orderParts[] = "n.id DESC";
$orderSql = " ORDER BY ".implode(', ', $orderParts)." LIMIT 200";

/* ---------- Final SQL ---------- */
$sql = "SELECT
          n.*,
          (n.user_id = :me1) AS is_owner,
          {$isSharedExpr},
          {$photoCountExpr},
          {$commentCountExpr}
        FROM notes n
        {$whereSql}
        {$orderSql}";

$rows = [];
try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  // Optional: show a friendly message or log
  $rows = [];
}

$title = 'Notes';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <div class="card-header">
    <div class="title">Notes</div>
    <div class="actions"><a class="btn primary" href="new.php">New Note</a></div>
  </div>

  <form method="get" class="grid three" action="index.php">
    <label>Search
      <input type="text" name="q" value="<?= sanitize($search); ?>" placeholder="Title or text">
    </label>
    <label>From
      <input type="date" name="from" value="<?= sanitize($from); ?>" <?= $hasNoteDate ? '' : 'disabled'; ?>>
    </label>
    <label>To
      <input type="date" name="to" value="<?= sanitize($to); ?>" <?= $hasNoteDate ? '' : 'disabled'; ?>>
    </label>
    <div class="form-actions">
      <button class="btn" type="submit">Filter</button>
      <a class="btn secondary" href="index.php">Reset</a>
    </div>
  </form>
</section>

<section class="card">
  <h2>Results</h2>
  <?php if (!$rows): ?>
    <p class="muted">No notes yet.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr><th>Date</th><th>Title</th><th>Photos</th><th>Replies</th><th class="text-right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $n): ?>
        <tr>
          <td data-label="Date">
            <?php
              $d = $n['note_date'] ?? null;
              if (!$d && isset($n['created_at'])) $d = substr((string)$n['created_at'], 0, 10);
              echo sanitize((string)$d);
            ?>
          </td>
          <td data-label="Title">
            <?= sanitize($n['title']); ?>
            <?php if (!empty($n['is_shared']) && empty($n['is_owner'])): ?>
              <span class="badge">Shared</span>
            <?php endif; ?>
          </td>
          <td data-label="Photos"><?= (int)($n['photo_count'] ?? 0); ?></td>
          <td data-label="Replies"><?= (int)($n['comment_count'] ?? 0); ?></td>
          <td class="text-right">
            <a class="btn small" href="view.php?id=<?= (int)$n['id']; ?>">View</a>
            <?php if (notes_can_edit($n)): ?>
              <a class="btn small" href="edit.php?id=<?= (int)$n['id']; ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php';
