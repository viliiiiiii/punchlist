<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

require_login();

$appsPdo = get_pdo();
$corePdo = get_pdo('core');

notes_bootstrap($appsPdo);

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

if ($currentUserId <= 0) {
    http_response_code(403);
    exit('Unauthorized');
}

$allUsers = fetch_user_directory($corePdo, $currentUserId);
$actionError = null;

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create_note':
                handle_create_note($appsPdo, $currentUserId, $_POST);
                redirect_with_message('index.php', 'Note created successfully.');
                break;
            case 'update_note':
                handle_update_note($appsPdo, $currentUserId, $_POST);
                redirect_with_message('index.php?note_id=' . (int)($_POST['note_id'] ?? 0), 'Note updated successfully.');
                break;
            case 'delete_note':
                handle_delete_note($appsPdo, $currentUserId, $_POST);
                redirect_with_message('index.php', 'Note deleted.');
                break;
            case 'share_note':
                handle_share_note($appsPdo, $corePdo, $currentUserId, $_POST);
                redirect_with_message('index.php?note_id=' . (int)($_POST['note_id'] ?? 0), 'Share updated.');
                break;
            case 'unshare_note':
                handle_unshare_note($appsPdo, $currentUserId, $_POST);
                redirect_with_message('index.php?note_id=' . (int)($_POST['note_id'] ?? 0), 'Share revoked.');
                break;
            case 'add_attachment':
                handle_add_attachment($appsPdo, $currentUserId, $_POST, $_FILES);
                redirect_with_message('index.php?note_id=' . (int)($_POST['note_id'] ?? 0), 'Attachment added.');
                break;
            case 'delete_attachment':
                handle_delete_attachment($appsPdo, $currentUserId, $_POST);
                redirect_with_message('index.php?note_id=' . (int)($_POST['note_id'] ?? 0), 'Attachment removed.');
                break;
            default:
                $actionError = 'Unknown action requested.';
        }
    } catch (RuntimeException $e) {
        $actionError = $e->getMessage();
    }
}

$noteId = isset($_GET['note_id']) ? (int)$_GET['note_id'] : 0;
$selectedNote = $noteId > 0 ? fetch_note_with_access($appsPdo, $noteId, $currentUserId) : null;
$selectedNoteShares = $selectedNote ? fetch_note_shares($appsPdo, $selectedNote['id']) : [];
$selectedNoteAttachments = $selectedNote ? fetch_note_attachments($appsPdo, $selectedNote['id']) : [];

$ownedNotes = fetch_owned_notes($appsPdo, $currentUserId);
$sharedNotes = fetch_shared_notes($appsPdo, $corePdo, $currentUserId);

$pageTitle = 'Secure Notes';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <h1>Secure Notes</h1>
    <?php if ($actionError): ?>
        <div class="flash flash-error"><?php echo sanitize($actionError); ?></div>
    <?php endif; ?>
    <?php flash_message(); ?>
    <div class="grid">
        <section class="card">
            <h2>Your Notes</h2>
            <?php if (!$ownedNotes): ?>
                <p class="muted">You have not created any notes yet.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($ownedNotes as $note): ?>
                        <li class="item<?php echo $selectedNote && $selectedNote['id'] === $note['id'] ? ' active' : ''; ?>">
                            <a href="?note_id=<?php echo (int)$note['id']; ?>">
                                <strong><?php echo sanitize($note['title']); ?></strong>
                                <span class="meta">Updated <?php echo sanitize($note['updated_at']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <hr>
            <h3>Create a new note</h3>
            <form method="post" class="stacked">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="create_note">
                <label>Title
                    <input type="text" name="title" required maxlength="200">
                </label>
                <label>Content
                    <textarea name="body" rows="6" required></textarea>
                </label>
                <button type="submit">Create Note</button>
            </form>
        </section>
        <section class="card">
            <h2>Notes Shared With You</h2>
            <?php if (!$sharedNotes): ?>
                <p class="muted">No notes have been shared with you yet.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($sharedNotes as $note): ?>
                        <li class="item<?php echo $selectedNote && $selectedNote['id'] === $note['id'] ? ' active' : ''; ?>">
                            <a href="?note_id=<?php echo (int)$note['id']; ?>">
                                <strong><?php echo sanitize($note['title']); ?></strong>
                                <span class="meta">Owner: <?php echo sanitize($note['owner_email'] ?? 'Unknown'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($selectedNote): ?>
        <section class="card">
            <header class="card-header">
                <h2><?php echo sanitize($selectedNote['title']); ?></h2>
                <span class="meta">Last updated <?php echo sanitize($selectedNote['updated_at']); ?><?php if ($selectedNote['is_owner']): ?> • Owned by you<?php else: ?> • Shared with you<?php endif; ?></span>
            </header>
            <article class="note-body">
                <?php echo nl2br(sanitize($selectedNote['body'])); ?>
            </article>

            <?php if ($selectedNote['is_owner']): ?>
                <details class="accordion" open>
                    <summary>Edit note</summary>
                    <form method="post" class="stacked">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_note">
                        <input type="hidden" name="note_id" value="<?php echo (int)$selectedNote['id']; ?>">
                        <label>Title
                            <input type="text" name="title" required maxlength="200" value="<?php echo sanitize($selectedNote['title']); ?>">
                        </label>
                        <label>Content
                            <textarea name="body" rows="6" required><?php echo sanitize($selectedNote['body']); ?></textarea>
                        </label>
                        <button type="submit">Save Changes</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this note? This cannot be undone.');" class="inline">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="delete_note">
                        <input type="hidden" name="note_id" value="<?php echo (int)$selectedNote['id']; ?>">
                        <button type="submit" class="danger">Delete Note</button>
                    </form>
                </details>

                <details class="accordion">
                    <summary>Sharing</summary>
                    <h3>Share with another user</h3>
                    <form method="post" class="stacked">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="share_note">
                        <input type="hidden" name="note_id" value="<?php echo (int)$selectedNote['id']; ?>">
                        <label>User
                            <select name="shared_with" required>
                                <option value="">Select a user</option>
                                <?php foreach ($allUsers as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>"><?php echo sanitize($user['email']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit">Share</button>
                    </form>
                    <h3>Currently shared with</h3>
                    <?php if (!$selectedNoteShares): ?>
                        <p class="muted">This note is private.</p>
                    <?php else: ?>
                        <ul class="item-list">
                            <?php foreach ($selectedNoteShares as $share): ?>
                                <li class="item">
                                    <span><?php echo sanitize($share['email'] ?? ('User #' . $share['shared_with'])); ?></span>
                                    <form method="post" class="inline" onsubmit="return confirm('Revoke access for this user?');">
                                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="unshare_note">
                                        <input type="hidden" name="note_id" value="<?php echo (int)$selectedNote['id']; ?>">
                                        <input type="hidden" name="share_id" value="<?php echo (int)$share['id']; ?>">
                                        <button type="submit" class="link">Revoke</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </details>

                <details class="accordion">
                    <summary>Attachments</summary>
                    <form method="post" enctype="multipart/form-data" class="stacked">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="add_attachment">
                        <input type="hidden" name="note_id" value="<?php echo (int)$selectedNote['id']; ?>">
                        <label>Add a photo (JPG, PNG, GIF up to 10MB)
                            <input type="file" name="attachment" accept="image/*" required>
                        </label>
                        <button type="submit">Upload</button>
                    </form>
                    <?php if (!$selectedNoteAttachments): ?>
                        <p class="muted">No attachments yet.</p>
                    <?php else: ?>
                        <div class="attachment-grid">
                            <?php foreach ($selectedNoteAttachments as $attachment): ?>
                                <figure class="attachment">
                                    <img src="<?php echo sanitize(photo_public_url($attachment['s3_key'])); ?>" alt="Attachment">
                                    <figcaption>
                                        <?php echo sanitize($attachment['filename']); ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Delete this attachment?');">
                                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete_attachment">
                                            <input type="hidden" name="note_id" value="<?php echo (int)$selectedNote['id']; ?>">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int)$attachment['id']; ?>">
                                            <button type="submit" class="link">Remove</button>
                                        </form>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>
            <?php else: ?>
                <p class="muted">You have read-only access to this note.</p>
                <?php if ($selectedNoteAttachments): ?>
                    <div class="attachment-grid">
                        <?php foreach ($selectedNoteAttachments as $attachment): ?>
                            <figure class="attachment">
                                <img src="<?php echo sanitize(photo_public_url($attachment['s3_key'])); ?>" alt="Attachment">
                                <figcaption><?php echo sanitize($attachment['filename']); ?></figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
function notes_bootstrap(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        body LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX user_idx (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS notes_shares (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        note_id BIGINT UNSIGNED NOT NULL,
        shared_with BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY note_user (note_id, shared_with),
        INDEX shared_idx (shared_with),
        CONSTRAINT fk_note_share_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS notes_attachments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        note_id BIGINT UNSIGNED NOT NULL,
        s3_key VARCHAR(255) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX note_idx (note_id),
        CONSTRAINT fk_note_attach_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function fetch_user_directory(PDO $pdo, int $excludeUserId): array {
    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id != :id ORDER BY email');
    $stmt->execute([':id' => $excludeUserId]);
    return $stmt->fetchAll();
}

function handle_create_note(PDO $pdo, int $userId, array $data): void {
    $title = trim((string)($data['title'] ?? ''));
    $body = trim((string)($data['body'] ?? ''));
    if ($title === '' || $body === '') {
        throw new RuntimeException('Title and content are required.');
    }

    $stmt = $pdo->prepare('INSERT INTO notes (user_id, title, body) VALUES (:user_id, :title, :body)');
    $stmt->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':body' => $body,
    ]);
}

function handle_update_note(PDO $pdo, int $userId, array $data): void {
    $noteId = (int)($data['note_id'] ?? 0);
    if ($noteId <= 0) {
        throw new RuntimeException('Invalid note.');
    }
    $note = fetch_note_for_owner($pdo, $noteId, $userId);
    if (!$note) {
        throw new RuntimeException('You do not have permission to modify this note.');
    }

    $title = trim((string)($data['title'] ?? ''));
    $body = trim((string)($data['body'] ?? ''));
    if ($title === '' || $body === '') {
        throw new RuntimeException('Title and content are required.');
    }

    $stmt = $pdo->prepare('UPDATE notes SET title = :title, body = :body WHERE id = :id');
    $stmt->execute([
        ':title' => $title,
        ':body' => $body,
        ':id' => $noteId,
    ]);
}

function handle_delete_note(PDO $pdo, int $userId, array $data): void {
    $noteId = (int)($data['note_id'] ?? 0);
    if ($noteId <= 0) {
        throw new RuntimeException('Invalid note.');
    }
    $note = fetch_note_for_owner($pdo, $noteId, $userId);
    if (!$note) {
        throw new RuntimeException('You do not have permission to delete this note.');
    }

    $stmt = $pdo->prepare('DELETE FROM notes WHERE id = :id');
    $stmt->execute([':id' => $noteId]);
}

function handle_share_note(PDO $appsPdo, PDO $corePdo, int $userId, array $data): void {
    $noteId = (int)($data['note_id'] ?? 0);
    $sharedWith = (int)($data['shared_with'] ?? 0);
    if ($noteId <= 0 || $sharedWith <= 0) {
        throw new RuntimeException('Invalid share request.');
    }
    if ($sharedWith === $userId) {
        throw new RuntimeException('You cannot share a note with yourself.');
    }
    $note = fetch_note_for_owner($appsPdo, $noteId, $userId);
    if (!$note) {
        throw new RuntimeException('You do not have permission to share this note.');
    }

    if (!user_exists($corePdo, $sharedWith)) {
        throw new RuntimeException('Selected user does not exist.');
    }

    $stmt = $appsPdo->prepare('INSERT INTO notes_shares (note_id, shared_with) VALUES (:note_id, :shared_with) ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP');
    $stmt->execute([
        ':note_id' => $noteId,
        ':shared_with' => $sharedWith,
    ]);
}

function handle_unshare_note(PDO $pdo, int $userId, array $data): void {
    $noteId = (int)($data['note_id'] ?? 0);
    $shareId = (int)($data['share_id'] ?? 0);
    if ($noteId <= 0 || $shareId <= 0) {
        throw new RuntimeException('Invalid request.');
    }
    $note = fetch_note_for_owner($pdo, $noteId, $userId);
    if (!$note) {
        throw new RuntimeException('You do not have permission to modify shares for this note.');
    }

    $stmt = $pdo->prepare('DELETE FROM notes_shares WHERE id = :id AND note_id = :note_id');
    $stmt->execute([
        ':id' => $shareId,
        ':note_id' => $noteId,
    ]);
}

function handle_add_attachment(PDO $pdo, int $userId, array $data, array $files): void {
    $noteId = (int)($data['note_id'] ?? 0);
    if ($noteId <= 0) {
        throw new RuntimeException('Invalid note.');
    }
    $note = fetch_note_for_owner($pdo, $noteId, $userId);
    if (!$note) {
        throw new RuntimeException('You do not have permission to add attachments.');
    }

    if (!isset($files['attachment']) || !is_array($files['attachment'])) {
        throw new RuntimeException('No file uploaded.');
    }

    $file = $files['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    if (($file['size'] ?? 0) <= 0) {
        throw new RuntimeException('Empty file.');
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('File exceeds the 10MB limit.');
    }

    $tmpPath = $file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: 'application/octet-stream';
    if (strpos($mime, 'image/') !== 0) {
        throw new RuntimeException('Only image uploads are allowed.');
    }

    $originalName = (string)($file['name'] ?? 'attachment');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'bin';
    }

    $s3Key = 'notes/' . $userId . '/' . $noteId . '/' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]+/i', '', $extension);

    $client = s3_client();
    $client->putObject([
        'Bucket' => S3_BUCKET,
        'Key' => $s3Key,
        'SourceFile' => $tmpPath,
        'ACL' => 'private',
        'ContentType' => $mime,
    ]);

    $stmt = $pdo->prepare('INSERT INTO notes_attachments (note_id, s3_key, filename, mime_type, file_size) VALUES (:note_id, :s3_key, :filename, :mime, :size)');
    $stmt->execute([
        ':note_id' => $noteId,
        ':s3_key' => $s3Key,
        ':filename' => $originalName,
        ':mime' => $mime,
        ':size' => (int)$file['size'],
    ]);
}

function handle_delete_attachment(PDO $pdo, int $userId, array $data): void {
    $noteId = (int)($data['note_id'] ?? 0);
    $attachmentId = (int)($data['attachment_id'] ?? 0);
    if ($noteId <= 0 || $attachmentId <= 0) {
        throw new RuntimeException('Invalid request.');
    }
    $note = fetch_note_for_owner($pdo, $noteId, $userId);
    if (!$note) {
        throw new RuntimeException('You do not have permission to remove this attachment.');
    }

    $stmt = $pdo->prepare('SELECT s3_key FROM notes_attachments WHERE id = :id AND note_id = :note_id');
    $stmt->execute([
        ':id' => $attachmentId,
        ':note_id' => $noteId,
    ]);
    $attachment = $stmt->fetch();
    if (!$attachment) {
        return;
    }

    $client = s3_client();
    $client->deleteObject([
        'Bucket' => S3_BUCKET,
        'Key' => $attachment['s3_key'],
    ]);

    $delStmt = $pdo->prepare('DELETE FROM notes_attachments WHERE id = :id AND note_id = :note_id');
    $delStmt->execute([
        ':id' => $attachmentId,
        ':note_id' => $noteId,
    ]);
}

function fetch_note_for_owner(PDO $pdo, int $noteId, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        ':id' => $noteId,
        ':user_id' => $userId,
    ]);
    $note = $stmt->fetch();
    return $note ?: null;
}

function fetch_note_with_access(PDO $pdo, int $noteId, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT *, (user_id = :user_id) AS is_owner FROM notes WHERE id = :id');
    $stmt->execute([
        ':id' => $noteId,
        ':user_id' => $userId,
    ]);
    $note = $stmt->fetch();
    if (!$note) {
        return null;
    }
    if ((bool)$note['is_owner']) {
        $note['is_owner'] = true;
        return $note;
    }
    $sharedStmt = $pdo->prepare('SELECT 1 FROM notes_shares WHERE note_id = :note_id AND shared_with = :shared_with');
    $sharedStmt->execute([
        ':note_id' => $noteId,
        ':shared_with' => $userId,
    ]);
    if ($sharedStmt->fetch()) {
        $note['is_owner'] = false;
        return $note;
    }
    return null;
}

function fetch_note_shares(PDO $pdo, int $noteId): array {
    $stmt = $pdo->prepare('SELECT id, note_id, shared_with, created_at FROM notes_shares WHERE note_id = :note_id ORDER BY created_at DESC');
    $stmt->execute([':note_id' => $noteId]);
    $shares = $stmt->fetchAll();
    if (!$shares) {
        return [];
    }

    $userIds = array_map(static fn($row) => (int)$row['shared_with'], $shares);
    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $coreStmt = get_pdo('core')->prepare('SELECT id, email FROM users WHERE id IN (' . $placeholders . ')');
        $coreStmt->execute($userIds);
        $map = [];
        foreach ($coreStmt->fetchAll() as $user) {
            $map[(int)$user['id']] = $user['email'];
        }
        foreach ($shares as &$share) {
            $uid = (int)$share['shared_with'];
            if (isset($map[$uid])) {
                $share['email'] = $map[$uid];
            }
        }
    }
    return $shares;
}

function fetch_note_attachments(PDO $pdo, int $noteId): array {
    $stmt = $pdo->prepare('SELECT id, note_id, s3_key, filename, mime_type FROM notes_attachments WHERE note_id = :note_id ORDER BY created_at DESC');
    $stmt->execute([':note_id' => $noteId]);
    return $stmt->fetchAll();
}

function fetch_owned_notes(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT id, title, updated_at FROM notes WHERE user_id = :user_id ORDER BY updated_at DESC');
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll();
}

function fetch_shared_notes(PDO $appsPdo, PDO $corePdo, int $userId): array {
    $stmt = $appsPdo->prepare('SELECT n.id, n.title, n.user_id, n.updated_at FROM notes n INNER JOIN notes_shares s ON s.note_id = n.id WHERE s.shared_with = :user_id ORDER BY n.updated_at DESC');
    $stmt->execute([':user_id' => $userId]);
    $notes = $stmt->fetchAll();
    if (!$notes) {
        return [];
    }
    $ownerIds = array_map(static fn($row) => (int)$row['user_id'], $notes);
    if ($ownerIds) {
        $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
        $coreStmt = $corePdo->prepare('SELECT id, email FROM users WHERE id IN (' . $placeholders . ')');
        $coreStmt->execute($ownerIds);
        $map = [];
        foreach ($coreStmt->fetchAll() as $user) {
            $map[(int)$user['id']] = $user['email'];
        }
        foreach ($notes as &$note) {
            $ownerId = (int)$note['user_id'];
            $note['owner_email'] = $map[$ownerId] ?? null;
        }
    }
    return $notes;
}

function user_exists(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    return (bool)$stmt->fetchColumn();
}
?>
