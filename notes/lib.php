<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * Notes library (owner + explicit shares)
 * Tables expected (in your APP DB):
 *  - notes(id, user_id, note_date, title, body, created_at, updated_at)
 *  - note_photos(id, note_id, position, s3_key, url, created_at)
 *  - notes_shares(id, note_id, user_id, created_at)
 *
 * Optional: if your shares table still uses `shared_with`, detection below handles it.
 */

const NOTES_MAX_MB = 70;
const NOTES_ALLOWED_MIMES = [
    'image/jpeg'          => 'jpg',
    'image/png'           => 'png',
    'image/webp'          => 'webp',
    'image/heic'          => 'heic',
    'image/heif'          => 'heic',
    'image/heic-sequence' => 'heic',
    'image/heif-sequence' => 'heic',
    'application/octet-stream' => null, // fallback by filename
];

/* ---------- tiny schema helpers (tolerant) ---------- */
function notes__col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function notes__table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function notes__shares_column(PDO $pdo): ?string {
    // Prefer `user_id` (current schema). Fall back to legacy `shared_with`.
    if (!notes__table_exists($pdo, 'notes_shares')) return null;
    if (notes__col_exists($pdo, 'notes_shares', 'user_id')) return 'user_id';
    if (notes__col_exists($pdo, 'notes_shares', 'shared_with')) return 'shared_with';
    return null;
}

/* ---------- MIME/extension helpers ---------- */
function notes_ext_for_mime(string $mime): ?string {
    return NOTES_ALLOWED_MIMES[$mime] ?? null;
}
function notes_resolve_ext_and_mime(string $tmpPath, string $origName): array {
    $mime = 'application/octet-stream';
    $fi   = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $mm = @finfo_file($fi, $tmpPath);
        if (is_string($mm) && $mm !== '') $mime = $mm;
        @finfo_close($fi);
    }
    $ext = notes_ext_for_mime($mime);
    if ($ext === null) {
        $fnExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (in_array($fnExt, ['jpg','jpeg','png','webp','heic','heif'], true)) {
            $ext = $fnExt === 'jpeg' ? 'jpg' : ($fnExt === 'heif' ? 'heic' : $fnExt);
            if ($mime === 'application/octet-stream') {
                $mime = [
                    'jpg'  => 'image/jpeg',
                    'png'  => 'image/png',
                    'webp' => 'image/webp',
                    'heic' => 'image/heic',
                ][$ext] ?? 'application/octet-stream';
            }
        }
    }
    return [$ext, $mime];
}

/* ---------- CRUD ---------- */
function notes_insert(array $data): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "INSERT INTO notes (user_id, note_date, title, body)
         VALUES (:user_id, :note_date, :title, :body)"
    );
    $stmt->execute([
        ':user_id'   => (int)$data['user_id'],
        ':note_date' => $data['note_date'],
        ':title'     => $data['title'],
        ':body'      => ($data['body'] ?? '') !== '' ? $data['body'] : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function notes_update(int $id, array $data): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "UPDATE notes SET note_date=:note_date, title=:title, body=:body WHERE id=:id"
    );
    $stmt->execute([
        ':note_date' => $data['note_date'],
        ':title'     => $data['title'],
        ':body'      => ($data['body'] ?? '') !== '' ? $data['body'] : null,
        ':id'        => $id,
    ]);
}

function notes_delete(int $id): void {
    // delete photos and object storage, then note
    $photos = notes_fetch_photos($id);
    foreach ($photos as $p) {
        try { s3_client()->deleteObject(['Bucket'=>S3_BUCKET,'Key'=>$p['s3_key']]); } catch (Throwable $e) {}
    }
    $pdo = get_pdo();
    $pdo->prepare("DELETE FROM note_photos WHERE note_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM notes_shares WHERE note_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$id]);
}

function notes_fetch(int $id): ?array {
    $pdo = get_pdo();
    $st = $pdo->prepare("SELECT * FROM notes WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- photos ---------- */
function notes_fetch_photos(int $noteId): array {
    $pdo = get_pdo();
    if (!notes__table_exists($pdo, 'note_photos')) return [];
    $st = $pdo->prepare("SELECT * FROM note_photos WHERE note_id=? ORDER BY position");
    $st->execute([$noteId]);
    $out = [];
    while ($r = $st->fetch()) { $out[(int)$r['position']] = $r; }
    return $out;
}

function notes_upsert_photo(int $noteId, int $position, string $key, string $url): void {
    $pdo = get_pdo();
    if (!notes__table_exists($pdo, 'note_photos')) return;
    $sql = "INSERT INTO note_photos (note_id,position,s3_key,url)
            VALUES (:note_id,:position,:s3_key,:url)
            ON DUPLICATE KEY UPDATE s3_key=VALUES(s3_key), url=VALUES(url), created_at=NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':note_id'  => $noteId,
        ':position' => $position,
        ':s3_key'   => $key,
        ':url'      => $url,
    ]);
}

function notes_remove_photo_by_id(int $photoId): void {
    $pdo = get_pdo();
    if (!notes__table_exists($pdo, 'note_photos')) return;
    $st = $pdo->prepare("SELECT * FROM note_photos WHERE id=?");
    $st->execute([$photoId]);
    if ($row = $st->fetch()) {
        try { s3_client()->deleteObject(['Bucket'=>S3_BUCKET,'Key'=>$row['s3_key']]); } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM note_photos WHERE id=?")->execute([$photoId]);
    }
}

/** Save uploaded photo (field name -> e.g. 'photo', 'photo1' etc.). Returns [url,key,mime]. */
function notes_save_uploaded_photo(int $noteId, int $position, string $fieldName): array {
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("No file for $fieldName");
    }
    $err = (int)$_FILES[$fieldName]['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE=>'file exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE=>'file exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL=>'partial upload',
            UPLOAD_ERR_NO_TMP_DIR=>'missing tmp dir',
            UPLOAD_ERR_CANT_WRITE=>'disk write failed',
            UPLOAD_ERR_EXTENSION=>'blocked by extension',
        ];
        throw new RuntimeException("Upload error: " . ($map[$err] ?? "code $err"));
    }

    $tmp   = (string)$_FILES[$fieldName]['tmp_name'];
    $size  = (int)($_FILES[$fieldName]['size'] ?? 0);
    $oname = (string)($_FILES[$fieldName]['name'] ?? '');
    if ($size <= 0) throw new RuntimeException('Empty file');
    if ($size > NOTES_MAX_MB * 1024 * 1024) throw new RuntimeException('File too large (max '.NOTES_MAX_MB.'MB)');

    [$ext, $mime] = notes_resolve_ext_and_mime($tmp, $oname);
    if (!$ext) throw new RuntimeException("Unsupported type");

    $uuid = bin2hex(random_bytes(8));
    $key  = sprintf('notes/%d/%s-%d.%s', $noteId, $uuid, $position, $ext);

    $url = null;
    $s3Available = class_exists(\Aws\S3\S3Client::class) && S3_BUCKET !== '' && S3_ENDPOINT !== '';
    if ($s3Available) {
        try {
            s3_client()->putObject([
                'Bucket'      => S3_BUCKET,
                'Key'         => $key,
                'SourceFile'  => $tmp,
                'ContentType' => $mime,
            ]);
            $url = s3_object_url($key);
        } catch (Throwable $e) {
            $s3Available = false; // fallback to local
        }
    }
    if (!$s3Available) {
        $base = __DIR__ . '/../uploads';
        $dir  = $base . '/notes/' . $noteId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Failed to create uploads directory');
        }
        $dest = $dir . '/' . basename($key);
        if (!@move_uploaded_file($tmp, $dest)) {
            $bytes = @file_get_contents($tmp);
            if ($bytes === false || !@file_put_contents($dest, $bytes)) {
                throw new RuntimeException('Failed to write local file');
            }
        }
        $url = '/uploads/notes/' . $noteId . '/' . basename($dest);
    }

    notes_upsert_photo($noteId, $position, $key, $url);
    return ['url' => $url, 'key' => $key, 'mime' => $mime];
}

/* ---------- shares & authorization ---------- */
function notes_all_users(): array {
    $pdo = get_pdo('core'); // your CORE users
    try {
        $st = $pdo->query('SELECT id, email FROM users ORDER BY email');
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        // try app DB as fallback
        $pdo = get_pdo();
        $st = $pdo->query('SELECT id, email FROM users ORDER BY email');
        return $st->fetchAll() ?: [];
    }
}

function notes_get_share_user_ids(int $noteId): array {
    $pdo = get_pdo();
    $col = notes__shares_column($pdo);
    if (!$col) return [];
    $st = $pdo->prepare("SELECT $col AS user_id FROM notes_shares WHERE note_id = ?");
    $st->execute([$noteId]);
    return array_map('intval', array_column($st->fetchAll() ?: [], 'user_id'));
}

function notes_update_shares(int $noteId, array $userIds): void {
    $pdo = get_pdo();
    $col = notes__shares_column($pdo);
    if (!$col) {
        throw new RuntimeException('notes_shares table/column not present.');
    }
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM notes_shares WHERE note_id = ?')->execute([$noteId]);
        if ($userIds) {
            $sql = "INSERT INTO notes_shares (note_id, $col) VALUES (?, ?)";
            $ins = $pdo->prepare($sql);
            foreach ($userIds as $uid) $ins->execute([$noteId, $uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function notes_can_view(array $note): bool {
    $role = current_user_role_key();
    if ($role === 'root' || $role === 'admin') return true;

    $meId = (int)(current_user()['id'] ?? 0);
    if ($meId <= 0) return false;

    // Owner?
    if ((int)($note['user_id'] ?? 0) === $meId) return true;

    // Shared with me?
    if (!isset($note['id'])) return false;
    try {
        $pdo = get_pdo();
        $st = $pdo->prepare('SELECT 1 FROM notes_shares WHERE note_id = ? AND user_id = ? LIMIT 1');
        $st->execute([(int)$note['id'], $meId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


function notes_can_edit(array $note): bool {
    if (!can('edit')) return false;
    $role = current_user_role_key();
    if ($role === 'root' || $role === 'admin') return true;
    $meId = (int)(current_user()['id'] ?? 0);
    return (int)($note['user_id'] ?? 0) === $meId;
}

function notes_can_share(array $note): bool {
    $role = current_user_role_key();
    if (in_array($role, ['root','admin'], true)) return true;
    $meId = (int)(current_user()['id'] ?? 0);
    return (int)($note['user_id'] ?? 0) === $meId;
}

