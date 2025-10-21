<?php
require_once __DIR__ . '/helpers.php';
require_login();

$taskId = (int)($_GET['id'] ?? 0);
$task = fetch_task($taskId);
if (!$task) {
    redirect_with_message('tasks.php', 'Task not found.', 'error');
}

$photos = fetch_task_photos($taskId);
$title = 'Task #' . $taskId;
include __DIR__ . '/includes/header.php';
?>
<section class="card">
    <div class="card-header">
        <h1>Task #<?php echo $taskId; ?></h1>
        <div class="actions">
            <a class="btn" href="task_edit.php?id=<?php echo $taskId; ?>">Edit Task</a>
            <a class="btn" href="export_pdf.php?selected=<?php echo $taskId; ?>" target="_blank">Export to PDF</a>
            <a class="btn" href="export_room_pdf.php?room_id=<?php echo $task['room_id']; ?>" target="_blank">Export Room PDF</a>
        </div>
    </div>
    <div class="grid two">
        <div>
            <h3>Details</h3>
            <p><strong>Building:</strong> <?php echo sanitize($task['building_name']); ?></p>
            <p><strong>Room:</strong> <?php echo sanitize($task['room_number'] . ($task['room_label'] ? ' - ' . $task['room_label'] : '')); ?></p>
            <p><strong>Priority:</strong> <span class="badge <?php echo priority_class($task['priority']); ?>"><?php echo sanitize(priority_label($task['priority'])); ?></span></p>
            <p><strong>Status:</strong> <span class="badge <?php echo status_class($task['status']); ?>"><?php echo sanitize(status_label($task['status'])); ?></span></p>
            <p><strong>Assigned To:</strong> <?php echo sanitize($task['assigned_to'] ?? ''); ?></p>
            <p><strong>Due Date:</strong> <?php echo $task['due_date'] ? sanitize($task['due_date']) : '—'; ?></p>
            <p><strong>Created:</strong> <?php echo sanitize($task['created_at']); ?></p>
            <p><strong>Updated:</strong> <?php echo $task['updated_at'] ? sanitize($task['updated_at']) : '—'; ?></p>
        </div>
        <div>
            <h3>Description</h3>
            <p><?php echo nl2br(sanitize($task['description'] ?? 'No description.')); ?></p>
        </div>
    </div>
</section>

<section class="card">
    <h2>Photos</h2>
    <?php if ($photos): ?>
        <div class="photo-grid">
            <?php foreach ($photos as $photo): ?>
  <?php $src = photo_public_url($photo, 1800); ?>
  <img src="<?php echo sanitize($src); ?>" alt="">
<?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">No photos uploaded.</p>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
