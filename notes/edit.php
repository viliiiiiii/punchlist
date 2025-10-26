<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}

$canEdit  = notes_can_edit($note);
$canShare = notes_can_share($note);
$photos   = notes_fetch_photos($id);

$shareOptions  = notes_all_users();
$currentShares = notes_get_share_user_ids($id);

$errors = [];

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        // delete
        if (isset($_POST['delete_note']) && $canEdit) {
            notes_delete($id);
            log_event('note.delete', 'note', $id);
            redirect_with_message('index.php', 'Note deleted.', 'success');
        }

        // save text fields
        if (isset($_POST['save_note']) && $canEdit) {
            $data = [
                'note_date' => (string)($_POST['note_date'] ?? ''),
                'title'     => trim((string)($_POST['title'] ?? '')),
                'body'      => trim((string)($_POST['body'] ?? '')),
            ];
            if ($data['title'] === '') $errors[] = 'Title is required.';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['note_date'])) $errors[] = 'Valid date is required.';
            if (!$errors) {
                notes_update($id, $data);
                log_event('note.update', 'note', $id);
                redirect_with_message('view.php?id='.$id, 'Note updated.', 'success');
            }
            $note = array_merge($note, $data);
        }

        // shares update
        if (isset($_POST['save_shares'])) {
  if (!$canShare) { http_response_code(403); exit('Forbidden'); }
  $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));
  $ownerId = (int)$note['user_id'];
  // Do not allow sharing to self:
  $selected = array_values(array_filter($selected, fn($u) => $u !== $ownerId));

  try {
      notes_update_shares($id, $selected); // this writes into notes_shares.user_id
      redirect_with_message('edit.php?id='.$id, 'Shares updated.', 'success');
  } catch (Throwable $e) {
      $errors[] = 'Failed to update shares.';
  }
}

        // photo upload/replace
        if (isset($_POST['upload_position']) && $canEdit) {
            $pos = (int)$_POST['upload_position'];
            if (in_array($pos,[1,2,3],true)) {
                try {
                    notes_save_uploaded_photo($id, $pos, 'photo');
                    redirect_with_message('edit.php?id='.$id, "Photo $pos uploaded.", 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Photo upload failed: '.$e->getMessage();
                }
                $photos = notes_fetch_photos($id);
            } else {
                $errors[] = 'Bad photo position.';
            }
        }

        // photo delete
        if (isset($_POST['delete_photo_id']) && $canEdit) {
            try {
                notes_remove_photo_by_id((int)$_POST['delete_photo_id']);
                redirect_with_message('edit.php?id='.$id, 'Photo removed.', 'success');
            } catch (Throwable $e) {
                $errors[] = 'Failed to remove photo.';
            }
            $photos = notes_fetch_photos($id);
        }
    }
}

$title = 'Edit Note';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <div class="card-header">
    <div class="title">Edit Note</div>
    <div class="meta"><?= sanitize($note['note_date']); ?></div>
    <div class="actions"><a class="btn" href="view.php?id=<?= (int)$note['id']; ?>">View</a></div>
  </div>

  <?php if ($errors): ?><div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div><?php endif; ?>

  <form method="post" class="grid two" novalidate>
    <label>Date
      <input type="date" name="note_date" value="<?= sanitize($note['note_date']); ?>" required <?= $canEdit?'':'disabled'; ?>>
    </label>
    <label>Title
      <input type="text" name="title" value="<?= sanitize($note['title']); ?>" required <?= $canEdit?'':'disabled'; ?>>
    </label>
    <label class="field-span-2">Notes
      <textarea name="body" rows="5" <?= $canEdit?'':'disabled'; ?>><?= sanitize($note['body'] ?? ''); ?></textarea>
    </label>

    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
    <?php if ($canEdit): ?>
      <div class="form-actions field-span-2">
        <button class="btn primary" type="submit" name="save_note" value="1">Save</button>
        <a class="btn secondary" href="view.php?id=<?= (int)$note['id']; ?>">Cancel</a>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($canEdit): ?>
    <form method="post" onsubmit="return confirm('Delete this note?');" class="inline" style="margin-top:.5rem">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
      <input type="hidden" name="delete_note" value="1">
      <button class="btn danger" type="submit">Delete</button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Photos</h2>
  <div class="photo-grid photo-grid-compact">
    <?php for ($i=1; $i<=3; $i++): $p = $photos[$i] ?? null; ?>
      <div class="photo-slot photo-slot-compact">
        <?php if ($p): ?>
          <img src="<?= sanitize($p['url']); ?>" alt="Note photo <?= $i; ?>">
          <?php if ($canEdit): ?>
            <form method="post" class="photo-actions" onsubmit="return confirm('Remove this photo?');">
              <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
              <input type="hidden" name="delete_photo_id" value="<?= (int)$p['id']; ?>">
              <button class="btn small" type="submit">Remove</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <div class="muted small">No photo</div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
          <form method="post" enctype="multipart/form-data" class="photo-upload-inline">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
            <input type="hidden" name="upload_position" value="<?= $i; ?>">
            <input class="file-compact" type="file" name="photo" accept="image/*,image/heic,image/heif" required>
            <button class="btn small" type="submit"><?= $p ? 'Replace' : 'Upload'; ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</section>

<?php if ($canShare): ?>
<section class="card">
  <h2>Sharing</h2>
  <form method="post" class="grid two">
    <label class="field field-span-2">
      <span class="lbl">Share with users</span>
      <select name="shared_ids[]" multiple size="8">
        <?php
          $ownerId = (int)$note['user_id'];
          foreach ($shareOptions as $u):
            $uid = (int)$u['id'];
            if ($uid === $ownerId) continue;
        ?>
          <option value="<?= $uid; ?>" <?= in_array($uid, $currentShares, true) ? 'selected' : '' ?>>
            <?= sanitize($u['email']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="muted small">Hold Ctrl/Cmd to pick multiple.</span>
    </label>
    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
    <div class="form-actions field-span-2">
      <button class="btn" type="submit" name="save_shares" value="1">Save Shares</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php';
