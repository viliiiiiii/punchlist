<?php
require_once __DIR__ . '/helpers.php';
require_login();
if (!can('edit')) {
    http_response_code(403);
    exit('Forbidden');
}

$taskId = (int)($_GET['id'] ?? 0);
$task = fetch_task($taskId);
if (!$task) {
    redirect_with_message('tasks.php', 'Task not found.', 'error');
}

$errors = [];
$photos = fetch_task_photos($taskId);
$buildings = fetch_buildings();
$rooms = fetch_rooms_by_building($task['building_id']);

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors['csrf'] = 'Invalid CSRF token.';
    } elseif (isset($_POST['delete_task'])) {
        delete_task($taskId);
        log_event('task.delete', 'task', $taskId);
        redirect_with_message('tasks.php', 'Task deleted.', 'success');
    } else {
        $data = [
            'building_id' => (int)($_POST['building_id'] ?? 0),
            'room_id' => (int)($_POST['room_id'] ?? 0),
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'priority' => $_POST['priority'] ?? '',
            'assigned_to' => trim($_POST['assigned_to'] ?? ''),
            'status' => $_POST['status'] ?? 'open',
            'due_date' => $_POST['due_date'] ?? null,
        ];
        if (!validate_task_payload($data, $errors)) {
            // validation messages set into $errors
        } elseif (!ensure_building_room_valid($data['building_id'], $data['room_id'])) {
            $errors['room_id'] = 'Selected room does not belong to building.';
        } else {
            update_task($taskId, $data);
            log_event('task.update', 'task', $taskId);
            redirect_with_message('task_view.php?id=' . $taskId, 'Task updated successfully.');
        }
        if ($data['building_id']) {
            $rooms = fetch_rooms_by_building($data['building_id']);
        }
        $task = array_merge($task, $data);
    }
}

$title = 'Edit Task';
include __DIR__ . '/includes/header.php';
?>
<script defer src="/assets/js/photos.js"></script>

<section class="card card-compact">
  <div class="card-header">
    <h1>Edit Task #<?php echo $taskId; ?></h1>
    <a class="btn btn-compact" href="task_view.php?id=<?php echo $taskId; ?>">View Task</a>
  </div>

  <?php if (!empty($errors['csrf'])): ?>
    <div class="flash flash-error"><?php echo sanitize($errors['csrf']); ?></div>
  <?php endif; ?>

  <form method="post" class="form-compact" novalidate>
    <div class="grid-compact">
      <label class="field">
        <span class="lbl">Building</span>
        <select name="building_id" required data-room-source data-room-target="room-select">
          <option value="">Select building</option>
          <?php foreach ($buildings as $building): ?>
            <option value="<?php echo $building['id']; ?>" <?php echo ($task['building_id'] == $building['id']) ? 'selected' : ''; ?>>
              <?php echo sanitize($building['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['building_id'])): ?><span class="error small"><?php echo sanitize($errors['building_id']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Room</span>
        <select name="room_id" id="room-select" required>
          <option value="">Select room</option>
          <?php foreach ($rooms as $room): ?>
            <option value="<?php echo $room['id']; ?>" <?php echo ($task['room_id'] == $room['id']) ? 'selected' : ''; ?>>
              <?php echo sanitize($room['room_number'] . ($room['label'] ? ' - ' . $room['label'] : '')); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['room_id'])): ?><span class="error small"><?php echo sanitize($errors['room_id']); ?></span><?php endif; ?>
      </label>

      <label class="field field-span-2">
        <span class="lbl">Title</span>
        <input type="text" name="title" required value="<?php echo sanitize($task['title']); ?>" placeholder="Brief, action-oriented title" autofocus>
        <?php if (!empty($errors['title'])): ?><span class="error small"><?php echo sanitize($errors['title']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Priority</span>
        <select name="priority">
          <?php foreach (get_priorities() as $priority): ?>
            <option value="<?php echo $priority; ?>" <?php echo ($task['priority'] === $priority) ? 'selected' : ''; ?>>
              <?php echo sanitize(priority_label($priority)); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['priority'])): ?><span class="error small"><?php echo sanitize($errors['priority']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Status</span>
        <select name="status">
          <?php foreach (get_statuses() as $status): ?>
            <option value="<?php echo $status; ?>" <?php echo ($task['status'] === $status) ? 'selected' : ''; ?>>
              <?php echo sanitize(status_label($status)); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['status'])): ?><span class="error small"><?php echo sanitize($errors['status']); ?></span><?php endif; ?>
      </label>

      <label class="field">
        <span class="lbl">Assigned To</span>
        <input type="text" name="assigned_to" value="<?php echo sanitize($task['assigned_to'] ?? ''); ?>" placeholder="Name or team">
      </label>

      <label class="field">
        <span class="lbl">Due Date</span>
        <input type="date" name="due_date" value="<?php echo sanitize($task['due_date'] ?? ''); ?>">
        <?php if (!empty($errors['due_date'])): ?><span class="error small"><?php echo sanitize($errors['due_date']); ?></span><?php endif; ?>
      </label>

      <label class="field field-span-3">
        <span class="lbl">Description</span>
        <textarea name="description" placeholder="Short context, steps, or acceptance criteria"><?php echo sanitize($task['description'] ?? ''); ?></textarea>
      </label>
    </div>

    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

    <div class="form-actions-compact">
      <button class="btn primary btn-compact" type="submit">Save Changes</button>
      <a class="btn danger btn-compact" href="#" onclick="if(confirm('Delete this task?')){ this.closest('section').querySelector('form[data-delete]').submit(); } return false;">Delete Task</a>
    </div>
  </form>

  <!-- hidden delete form -->
  <form method="post" data-delete style="display:none">
    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="delete_task" value="1">
  </form>
</section>

<section class="card card-compact">
  <div class="card-header">
    <h2>Photos</h2>
  </div>
  <div class="photo-grid photo-grid-compact">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <?php $photo = $photos[$i] ?? null; ?>
      <div class="photo-slot photo-slot-compact">
        <?php if ($photo): ?>
          <?php $src = photo_public_url($photo, 900); ?>
          <img src="<?php echo sanitize($src); ?>" alt="Task photo">
          <div class="photo-actions">
            <button
              type="button"
              class="btn small btn-compact"
              data-remove-photo
              data-photo-id="<?php echo $photo['id']; ?>"
              data-csrf-name="<?php echo CSRF_TOKEN_NAME; ?>"
              data-csrf-value="<?php echo csrf_token(); ?>"
            >Remove</button>
          </div>
        <?php else: ?>
          <div class="muted small">No photo</div>
        <?php endif; ?>

        <form
          action="/upload.php"
          method="post"
          enctype="multipart/form-data"
          class="photo-upload-inline"
          data-upload-form
        >
          <input type="hidden" name="task_id" value="<?php echo $taskId; ?>">
          <input type="hidden" name="position" value="<?php echo $i; ?>">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <!-- Allow camera + HEIC on mobile -->
          <input class="file-compact" type="file" name="photo" accept="image/*,image/heic,image/heif" required>
          <button class="btn small btn-compact" type="submit"><?php echo $photo ? 'Replace' : 'Upload'; ?></button>
        </form>

      </div>
    <?php endfor; ?>
  </div>
  <p class="muted small" style="margin-top:.5rem">JPG/PNG/WebP/HEIC, up to 70&nbsp;MB each.</p>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
