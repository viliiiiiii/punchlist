<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$errors = [];
$today  = date('Y-m-d');

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $data = [
            'user_id'   => (int)(current_user()['id'] ?? 0),
            'note_date' => (string)($_POST['note_date'] ?? $today),
            'title'     => trim((string)($_POST['title'] ?? '')),
            'body'      => trim((string)($_POST['body'] ?? '')),
        ];
        if ($data['title'] === '') $errors[] = 'Title is required.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['note_date'])) $errors[] = 'Valid date is required.';

        if (!$errors) {
            $id = notes_insert($data);
            // inline photos best-effort
            for ($i=1; $i<=3; $i++) {
                if (!empty($_FILES["photo$i"]['name'])) {
                    try { notes_save_uploaded_photo($id, $i, "photo$i"); } catch (Throwable $e) {}
                }
            }
            redirect_with_message('view.php?id='.$id, 'Note created.', 'success');
        }
    }
}

$title = 'New Note';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <h1>Create Note</h1>
  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="grid two" novalidate>
    <label>Date
      <input type="date" name="note_date" value="<?= sanitize($_POST['note_date'] ?? $today); ?>" required>
    </label>
    <label>Title
      <input type="text" name="title" value="<?= sanitize($_POST['title'] ?? ''); ?>" required>
    </label>
    <label class="field-span-2">Notes
      <textarea name="body" rows="5"><?= sanitize($_POST['body'] ?? ''); ?></textarea>
    </label>

    <div class="field-span-2">
      <div class="photo-upload-inline">
        <?php for ($i=1; $i<=3; $i++): ?>
          <label>Photo <?= $i; ?>
            <input type="file" name="photo<?= $i; ?>" accept="image/*,image/heic,image/heif" class="file-compact">
          </label>
        <?php endfor; ?>
      </div>
      <p class="muted small">JPG/PNG/WebP/HEIC up to <?= (int)NOTES_MAX_MB; ?> MB each.</p>
    </div>

    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
    <div class="form-actions field-span-2">
      <button class="btn primary" type="submit">Create</button>
      <a class="btn secondary" href="index.php">Cancel</a>
    </div>
  </form>
</section>
<?php include __DIR__ . '/../includes/footer.php';
