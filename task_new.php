<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_login();
if (!can('edit')) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = get_pdo();
$errors = [];
$createdTaskId = null;

if (is_post()) {
    // ---- CSRF ----
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors['csrf'] = 'Invalid CSRF token.';
    }

    // ---- Collect inputs ----
    $title        = trim((string)($_POST['title'] ?? ''));
    $buildingId   = (int)($_POST['building_id'] ?? 0);
    $roomNumber   = trim((string)($_POST['room_number'] ?? ''));
    $priority     = (string)($_POST['priority'] ?? '');
    $description  = trim((string)($_POST['description'] ?? ''));
    $assignedTo   = null; // reserved for future
    $status       = 'open';
    $dueDate      = null;
    $createdBy    = current_user()['id'] ?? null;

    // ---- Validate ----
    if ($title === '')        $errors['title'] = 'Title is required.';
    if ($buildingId <= 0)     $errors['building_id'] = 'Building is required.';
    if ($roomNumber === '')   $errors['room_number'] = 'Room number is required.';

    // Priority from allowed set
    $allowedPriorities = get_priorities(); // ['', 'low','low/mid','mid','mid/high','high']
    if (!in_array($priority, $allowedPriorities, true)) {
        $errors['priority'] = 'Invalid priority.';
    }

    // Resolve room_id from building + room_number
    $roomId = null;
    if (!$errors && $buildingId > 0 && $roomNumber !== '') {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE building_id = ? AND room_number = ? LIMIT 1');
        $stmt->execute([$buildingId, $roomNumber]);
        $roomId = (int)$stmt->fetchColumn();
        if (!$roomId) {
            $errors['room_number'] = 'Room does not exist in the selected building.';
        }
    }

    // ---- Create task & upload 3 photos ----
    if (!$errors) {
        try {
            $createdTaskId = insert_task([
                'building_id' => $buildingId,
                'room_id'     => $roomId,
                'title'       => $title,
                'description' => $description,
                'priority'    => $priority,
                'assigned_to' => $assignedTo,
                'status'      => $status,
                'due_date'    => $dueDate,
                'created_by'  => $createdBy,
            ]);

            // Handle 3 photo slots (photo_1..photo_3)
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            for ($pos = 1; $pos <= 3; $pos++) {
                $field = 'photo_' . $pos;
                if (!isset($_FILES[$field])) continue;
                $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) continue;

                $tmp   = $_FILES[$field]['tmp_name'] ?? null;
                $size  = (int)($_FILES[$field]['size'] ?? 0);
                if (!$tmp || !is_uploaded_file($tmp) || $size <= 0) continue;
                if ($size > 20*1024*1024) continue; // 20MB cap

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (!isset($allowed[$mime])) continue;
                $ext = $allowed[$mime];

                $key = sprintf('tasks/%d/%s-%02d.%s', $createdTaskId, bin2hex(random_bytes(6)), $pos, $ext);

                $client = s3_client();
                $client->putObject([
                    'Bucket'      => S3_BUCKET,
                    'Key'         => $key,
                    'SourceFile'  => $tmp,
                    'ACL'         => 'public-read',
                    'ContentType' => $mime,
                    'CacheControl'=> 'max-age=31536000,public',
                ]);

                $url = s3_object_url($key);
                upsert_photo($createdTaskId, $pos, $key, $url);
            }

            log_event('task.create', 'task', $createdTaskId);
            header('Location: task_edit.php?id=' . $createdTaskId);
            exit;
        } catch (Throwable $e) {
            $errors['fatal'] = 'Failed to create task: ' . $e->getMessage();
        }
    }
}

// ---- Fetch buildings for the form ----
$buildings = [];
try {
    $buildings = $pdo->query('SELECT id, name FROM buildings ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $buildings = []; }

$title = 'New Task';
include __DIR__ . '/includes/header.php';
?>
<section class="card form-compact">
  <h1>Create Task</h1>

  <?php if (!empty($errors)): ?>
    <div class="flash flash-error">
      <?php echo sanitize(implode(' ', array_values($errors))); ?>
    </div>
  <?php endif; ?>

  <form id="task-create-form"
        data-create-task
        action="task_new.php"
        method="POST"
        enctype="multipart/form-data">

    <div class="grid-compact">
      <label class="field">
        <span class="lbl">Title</span>
        <input type="text" name="title" required value="<?php echo sanitize($_POST['title'] ?? ''); ?>">
        <?php if (!empty($errors['title'])): ?><span class="error small"><?php echo sanitize($errors['title']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Building</span>
        <select id="building_id"
                name="building_id"
                required
                data-room-source
                data-room-input="room_number"
                data-room-datalist="room_number_list">
          <option value="">Select building</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo (isset($_POST['building_id']) && (int)$_POST['building_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
              <?php echo sanitize($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['building_id'])): ?><span class="error small"><?php echo sanitize($errors['building_id']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Room Number</span>
        <input id="room_number"
               name="room_number"
               list="room_number_list"
               placeholder="Type room number"
               required
               value="<?php echo sanitize($_POST['room_number'] ?? ''); ?>">
        <datalist id="room_number_list"></datalist>
        <?php if (!empty($errors['room_number'])): ?><span class="error small"><?php echo sanitize($errors['room_number']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Priority</span>
        <select name="priority">
          <?php foreach (get_priorities() as $p): ?>
            <option value="<?php echo $p; ?>" <?php echo (($_POST['priority'] ?? '') === $p) ? 'selected' : ''; ?>>
              <?php echo sanitize(priority_label($p)); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['priority'])): ?><span class="error small"><?php echo sanitize($errors['priority']); ?></span><?php endif; ?>
      </label>

      <label class="field field-span-3">
        <span class="lbl">Description</span>
        <textarea name="description" placeholder="Describe the task..."><?php echo sanitize($_POST['description'] ?? ''); ?></textarea>
      </label>

      <!-- Three photo slots (positions 1..3) -->
      <div class="field field-span-3">
        <span class="lbl">Photos (up to 3)</span>
        <div class="photo-upload-inline">
          <div class="field">
            <span class="muted small">Photo 1</span>
            <input type="file" name="photo_1" accept="image/*" capture="environment" class="file-compact">
          </div>
          <div class="field">
            <span class="muted small">Photo 2</span>
            <input type="file" name="photo_2" accept="image/*" capture="environment" class="file-compact">
          </div>
          <div class="field">
            <span class="muted small">Photo 3</span>
            <input type="file" name="photo_3" accept="image/*" capture="environment" class="file-compact">
          </div>
        </div>
        <span class="muted small">JPEG, PNG or WEBP. Max 20MB each.</span>
      </div>
    </div>

    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

    <div class="form-actions-compact">
      <button class="btn primary btn-compact" type="submit">Create Task</button>
      <a class="btn btn-compact" href="tasks.php">Cancel</a>
    </div>
  </form>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
