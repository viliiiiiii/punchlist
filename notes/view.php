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
$shareDetails    = notes_get_share_details($id);
$commentsEnabled = notes_comments_table_exists();
$commentThreads  = $commentsEnabled ? notes_fetch_comment_threads($id) : [];
$commentCount    = $commentsEnabled ? notes_comment_count($id) : 0;
$errors = [];

if ($commentsEnabled && $commentThreads) {
    $decorate = function (&$items) use (&$decorate, $note) {
        foreach ($items as &$item) {
            $item['can_delete'] = notes_comment_can_delete($item, $note);
            if (!empty($item['children'])) {
                $decorate($item['children']);
            }
        }
        unset($item);
    };
    $decorate($commentThreads);
}

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if (isset($_POST['add_comment']) && $commentsEnabled) {
            $body = trim((string)($_POST['body'] ?? ''));
            $parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            if ($body === '') {
                $errors[] = 'Comment cannot be empty.';
            } else {
                try {
                    $commentId = notes_comment_insert($id, (int)(current_user()['id'] ?? 0), $body, $parentId ?: null);
                    log_event('note.comment.create', 'note', $id, ['comment_id'=>$commentId]);
                    redirect_with_message('view.php?id='.$id.'#comment-'.$commentId, 'Reply posted.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to save comment.';
                }
            }
        }

        if (isset($_POST['delete_comment']) && $commentsEnabled) {
            $commentId = (int)$_POST['delete_comment'];
            $comment   = notes_comment_fetch($commentId);
            if (!$comment || (int)($comment['note_id'] ?? 0) !== $id) {
                $errors[] = 'Comment not found.';
            } elseif (!notes_comment_can_delete($comment, $note)) {
                $errors[] = 'You cannot remove this comment.';
            } else {
                notes_comment_delete($commentId);
                log_event('note.comment.delete', 'note', $id, ['comment_id'=>$commentId]);
                redirect_with_message('view.php?id='.$id.'#comments', 'Comment removed.', 'success');
            }
        }
    }
}

if ($commentsEnabled) {
    // Refresh comment threads if we hit validation errors
    $commentThreads  = notes_fetch_comment_threads($id);
    $commentCount    = notes_comment_count($id);
    if ($commentThreads) {
        $decorate = function (&$items) use (&$decorate, $note) {
            foreach ($items as &$item) {
                $item['can_delete'] = notes_comment_can_delete($item, $note);
                if (!empty($item['children'])) {
                    $decorate($item['children']);
                }
            }
            unset($item);
        };
        $decorate($commentThreads);
    }
}

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
  <?php if ($shareDetails): ?>
    <p class="muted small">Shared with:
      <?php foreach ($shareDetails as $share): ?>
        <span class="badge badge-muted"><?= sanitize($share['label']); ?></span>
      <?php endforeach; ?>
    </p>
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

<section class="card" id="comments">
  <div class="card-header">
    <div class="title">Discussion</div>
    <div class="meta"><?= (int)$commentCount; ?> replies</div>
  </div>
  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>
  <?php if (!$commentsEnabled): ?>
    <p class="muted">Commenting is disabled because the note_comments table was not detected.</p>
  <?php else: ?>
    <?php if (!$commentThreads): ?>
      <p class="muted">No replies yet.</p>
    <?php else: ?>
      <div class="note-comments">
        <?php
        $renderComment = function (array $comment) use (&$renderComment, $note, $commentsEnabled) {
            ?>
            <article class="note-comment" id="comment-<?= (int)$comment['id']; ?>">
              <header class="note-comment-header">
                <strong><?= sanitize($comment['author_label']); ?></strong>
                <span class="muted small"><?= sanitize(substr((string)($comment['created_at'] ?? ''), 0, 16)); ?></span>
              </header>
              <div class="note-comment-body"><?= nl2br(sanitize($comment['body'] ?? '')); ?></div>
              <footer class="note-comment-actions">
                <?php if ($commentsEnabled): ?>
                  <details class="note-comment-reply">
                    <summary>Reply</summary>
                    <form method="post" class="note-comment-form">
                      <textarea name="body" rows="3" required></textarea>
                      <input type="hidden" name="parent_id" value="<?= (int)$comment['id']; ?>">
                      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
                      <button class="btn small" type="submit" name="add_comment" value="1">Post Reply</button>
                    </form>
                  </details>
                <?php endif; ?>
                <?php if (!empty($comment['can_delete'])): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this reply?');">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
                    <button class="btn small" type="submit" name="delete_comment" value="<?= (int)$comment['id']; ?>">Delete</button>
                  </form>
                <?php endif; ?>
              </footer>
              <?php if (!empty($comment['children'])): ?>
                <div class="note-comment-children">
                  <?php foreach ($comment['children'] as $child) { $renderComment($child); } ?>
                </div>
              <?php endif; ?>
            </article>
            <?php
        };

        foreach ($commentThreads as $comment) {
            $renderComment($comment);
        }
        ?>
      </div>
    <?php endif; ?>

    <form method="post" class="note-comment-form note-comment-new">
      <label class="field-span-2">
        <span class="lbl">Add a reply</span>
        <textarea name="body" rows="4" required><?= sanitize($_POST['body'] ?? ''); ?></textarea>
      </label>
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
      <button class="btn primary" type="submit" name="add_comment" value="1">Post Reply</button>
    </form>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php';
