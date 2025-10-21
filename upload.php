<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();
header('Content-Type: application/json');

function fail(int $code, string $msg) {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Method not allowed');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    fail(422, 'Invalid CSRF token');
}

if (!can('edit')) {
    fail(403, 'Forbidden');
}

$taskId   = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$position = isset($_POST['position']) ? (int)$_POST['position'] : 0;

if ($taskId <= 0 || !in_array($position, [1,2,3], true)) {
    fail(422, 'Bad task_id or position');
}

if (!isset($_FILES['photo'])) {
    fail(422, 'No file field "photo"');
}
$err = $_FILES['photo']['error'];
if ($err !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE=>'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE=>'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL=>'Partial upload',
        UPLOAD_ERR_NO_FILE=>'No file sent',
        UPLOAD_ERR_NO_TMP_DIR=>'Missing tmp dir',
        UPLOAD_ERR_CANT_WRITE=>'Disk write failed',
        UPLOAD_ERR_EXTENSION=>'PHP extension blocked upload',
    ];
    fail(422, $map[$err] ?? ('Upload error code '.$err));
}

$tmp  = $_FILES['photo']['tmp_name'];
$size = (int)$_FILES['photo']['size'];
if ($size <= 0) {
    fail(422, 'Empty file');
}
if ($size > 20*1024*1024) { // 20 MB safety
    fail(422, 'File too large (max 20MB)');
}

// MIME whitelist
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmp);
finfo_close($finfo);
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
if (!isset($allowed[$mime])) {
    fail(422, 'Unsupported file type: '.$mime);
}
$ext = $allowed[$mime];

// Build S3 key
$uuid = bin2hex(random_bytes(8));
$key  = sprintf('tasks/%d/%s-%d.%s', $taskId, $uuid, $position, $ext);

try {
    // Upload to S3-compatible storage
    // Upload to S3-compatible storage
$bytes = file_get_contents($tmp);
s3_client()->putObject([
    'Bucket'      => S3_BUCKET,
    'Key'         => $key,
    'Body'        => $bytes,
    'ContentType' => $mime,
]);



    // Persist/Upsert DB record and build URL for display
    $url = s3_object_url($key);
    upsert_photo($taskId, $position, $key, $url);

    log_event('photo.upload', 'photo', $taskId, ['key' => $key, 'position' => $position]);
    echo json_encode(['ok'=>true, 'url'=>$url, 'position'=>$position]);
} catch (Throwable $e) {
    // Server error (S3/DB/others)
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Upload failed: '.$e->getMessage()]);
}
