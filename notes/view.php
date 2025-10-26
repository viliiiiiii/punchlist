<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$id   = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}
$photos = notes_fetch_photos($id);

$title = 'View Note';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <div class="card-header">
    <div class="title"><?= sanitize($note['title']); ?></div>
    <div class="meta"><?= sanitize($note['note_date']); ?></div>
    <div class="actions">
      <?php if (notes_can_edit($note)): ?>
        <a class="btn" href="edit.php?id=<?= (int)$note['id']; ?>">Edit</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!empty($note['body'])): ?>
    <p><?= nl2br(sanitize($note['body'])); ?></p>
  <?php else: ?>
    <p class="muted">No text.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Photos</h2>
  <div class="photo-grid">
    <?php for ($i=1; $i<=3; $i++): $p = $photos[$i] ?? null; ?>
      <?php if ($p): ?>
        <a href="<?= sanitize($p['url']); ?>" target="_blank" rel="noopener" class="thumb">
          <img src="<?= sanitize($p['url']); ?>" alt="Note photo <?= $i; ?>">
        </a>
      <?php else: ?>
        <div class="muted small">No photo #<?= $i; ?></div>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
</section>
<?php include __DIR__ . '/../includes/footer.php';
