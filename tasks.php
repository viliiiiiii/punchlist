<?php
require_once __DIR__ . '/helpers.php';
require_login();

$filters   = get_filter_values();
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$sort      = $_GET['sort'] ?? 'id';       // changed default from created_at → id
$direction = $_GET['direction'] ?? 'DESC';
$total     = 0;

$tasks  = fetch_tasks($filters, $sort, $direction, $perPage, ($page - 1) * $perPage, $total);
$taskIds = array_column($tasks, 'id');
$photos  = fetch_photos_for_tasks($taskIds);
$buildings = fetch_buildings();
$rooms   = $filters['building_id'] ? fetch_rooms_by_building($filters['building_id']) : [];

$query = $_GET;
unset($query['page']);
$baseQuery = http_build_query(array_filter($query, fn($value) => $value !== '' && $value !== []));
$pages = (int)ceil($total / $perPage);

$title = 'Tasks';
include __DIR__ . '/includes/header.php';
?>

<section class="card">
    <div class="card-header">
        <h1>Tasks</h1>
        <div class="actions">
            <a class="btn primary" href="task_new.php">New Task</a>
            <a class="btn" href="export_table_pdf_wkhtml.php?<?php echo $baseQuery; ?>" target="_blank">Export PDF</a>
            <a class="btn" href="export_table_pdf.php?<?php echo $baseQuery; ?>" target="_blank">Export PDF W/O pictures </a>

            <a class="btn" href="export_csv.php?<?php echo $baseQuery; ?>" target="_blank">Export CSV</a>
        </div>
    </div>
    <form method="get" class="filters">
        <label>Search
            <input type="text" name="search" value="<?php echo sanitize($filters['search']); ?>" placeholder="Title or description">
        </label>
        <label>Building
            <select name="building_id" data-room-source data-room-target="filter-room">
                <option value="">All</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?php echo $building['id']; ?>" <?php echo $filters['building_id'] == $building['id'] ? 'selected' : ''; ?>><?php echo sanitize($building['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Room
            <select name="room_id" id="filter-room">
                <option value="">All</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo $room['id']; ?>" <?php echo $filters['room_id'] == $room['id'] ? 'selected' : ''; ?>><?php echo sanitize($room['room_number'] . ($room['label'] ? ' - ' . $room['label'] : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Priority
  <select name="priority">
    <option value="">Any priority</option>
    <?php foreach (get_priorities() as $priority): ?>
      <option value="<?php echo $priority; ?>"
        <?php echo (($filters['priority'] ?? '') === $priority) ? 'selected' : ''; ?>>
        <?php echo sanitize(priority_label($priority)); ?>
      </option>
    <?php endforeach; ?>
  </select>
</label>

        <label>Status
            <select name="status">
                <option value="">All</option>
                <?php foreach (get_statuses() as $status): ?>
                    <option value="<?php echo $status; ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>><?php echo sanitize(status_label($status)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Assigned To
            <input type="text" name="assigned_to" value="<?php echo sanitize($filters['assigned_to']); ?>">
        </label>
        <label>Created From
            <input type="date" name="created_from" value="<?php echo sanitize($filters['created_from']); ?>">
        </label>
        <label>Created To
            <input type="date" name="created_to" value="<?php echo sanitize($filters['created_to']); ?>">
        </label>
        <label>Due From
            <input type="date" name="due_from" value="<?php echo sanitize($filters['due_from']); ?>">
        </label>
        <label>Due To
            <input type="date" name="due_to" value="<?php echo sanitize($filters['due_to']); ?>">
        </label>
        <label>Has Photos
            <select name="has_photos">
                <option value="">Any</option>
                <option value="1" <?php echo $filters['has_photos'] === '1' ? 'selected' : ''; ?>>Yes</option>
                <option value="0" <?php echo $filters['has_photos'] === '0' ? 'selected' : ''; ?>>No</option>
            </select>
        </label>
        <div class="filter-actions">
            <button class="btn primary" type="submit">Apply</button>
            <a class="btn secondary" href="tasks.php">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <form id="taskListForm" method="get" action="export_pdf.php" target="_blank">
  <input type="hidden" name="selected" id="selectedTasks" value="">
  <table class="table table-excel compact-rows">
    <thead>
      <tr>
        <th class="col-check">
          <input type="checkbox" id="toggle-all">
        </th>
        <th class="col-id">
          <a href="?<?php echo http_build_query(array_merge($query, ['sort' => 'id', 'direction' => $direction === 'ASC' ? 'DESC' : 'ASC'])); ?>">
            ID
          </a>
        </th>
        <th class="col-building">Building</th>
        <th class="col-room">
          <a href="?<?php echo http_build_query(array_merge($query, ['sort' => 'room', 'direction' => $direction === 'ASC' ? 'DESC' : 'ASC'])); ?>">
            Room
          </a>
        </th>
        <th class="col-title">Title</th>
        <th class="col-priority">
          <a href="?<?php echo http_build_query(array_merge($query, ['sort' => 'priority', 'direction' => $direction === 'ASC' ? 'DESC' : 'ASC'])); ?>">
            Priority
          </a>
        </th>
        <th class="col-assigned">Assigned To</th>
        <th class="col-status">Status</th>
        <th class="col-photos">Photos</th>
        <th class="col-actions"></th>
      </tr>
    </thead>

    <tbody>
      <?php if (!$tasks): ?>
        <tr>
          <td colspan="10" class="text-center muted">No tasks found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($tasks as $task): ?>
          <?php $taskPhotos = $photos[$task['id']] ?? []; ?>
          <tr data-task-id="<?php echo (int)$task['id']; ?>">
            <td class="col-check">
              <input type="checkbox" class="task-checkbox" value="<?php echo $task['id']; ?>">
            </td>
            <td class="col-id">#<?php echo (int)$task['id']; ?></td>
            <td class="col-building"><?php echo sanitize($task['building_name']); ?></td>
            <td class="col-room">
              <?php echo sanitize(
                $task['room_number'] . ($task['room_label'] ? ' - ' . $task['room_label'] : '')
              ); ?>
            </td>
            <td class="col-title">
              <a href="task_view.php?id=<?php echo (int)$task['id']; ?>">
                <?php echo sanitize($task['title']); ?>
              </a>
            </td>
            <td class="col-priority">
              <span class="badge <?php echo priority_class($task['priority']); ?>">
                <?php echo sanitize(priority_label($task['priority'])); ?>
              </span>
            </td>
            <td class="col-assigned"><?php echo sanitize($task['assigned_to'] ?? ''); ?></td>
            <td class="col-status">
              <span class="badge <?php echo status_class($task['status']); ?>">
                <?php echo sanitize(status_label($task['status'])); ?>
              </span>
            </td>
            <td class="col-photos">
              <button
                class="btn small js-view-photos"
                type="button"
                data-task-id="<?php echo (int)$task['id']; ?>"
              >
                View photos<?php if (!empty($task['photo_count'])) echo ' ('.(int)$task['photo_count'].')'; ?>
              </button>
            </td>
            <td class="col-actions">
              <a class="btn small" href="task_edit.php?id=<?php echo (int)$task['id']; ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="bulk-actions">
    <button type="button" class="btn" onclick="submitSelected('export_pdf.php')">Export Selected to PDF</button>
    <button type="button" class="btn" onclick="submitSelected('export_csv.php')">Export Selected to CSV</button>
  </div>
</form>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="btn <?php echo $i === $page ? 'primary' : ''; ?>" href="?<?php echo http_build_query(array_merge($query, ['page' => $i])); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>
<script>
function submitSelected(action) {
    const selected = Array.from(document.querySelectorAll('.task-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        alert('Select at least one task.');
        return;
    }
    const form = document.getElementById('taskListForm');
    document.getElementById('selectedTasks').value = selected.join(',');
    form.action = action;
    form.submit();
}
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modal     = document.getElementById('photoModal');
  const modalBody = document.getElementById('photoModalBody');

  function openModal() {
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    modalBody.innerHTML = '';
  }

  // Close handlers (backdrop, close button, Esc)
  modal.addEventListener('click', (e) => {
    if (e.target.matches('[data-close-photo-modal], .photo-modal-backdrop')) closeModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
  });

  // Delegate click from any "View photos" button
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-view-photos');
    if (!btn) return;

    const taskId = parseInt(btn.getAttribute('data-task-id'), 10) || 0;
    if (!taskId) {
      console.error('Missing data-task-id on .js-view-photos button.');
      alert('Could not determine Task ID for photos.');
      return;
    }

    try {
      const res = await fetch('get_task_photos.php?task_id=' + encodeURIComponent(taskId), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json();

      if (!res.ok || !data.ok) {
        throw new Error(data?.error || 'Failed to load photos');
      }

      if (!Array.isArray(data.photos) || data.photos.length === 0) {
        modalBody.innerHTML = '<p class="text-muted">No photos for this task.</p>';
        openModal();
        return;
      }

      // Build images
      const frag = document.createDocumentFragment();
      data.photos.forEach((p) => {
        // Defensive: only add valid-looking URLs
        if (!p || !p.url) return;
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.decoding = 'async';
        img.src = p.url;        // Do not HTML-escape in JS – use the URL as returned
        img.alt = 'Task photo';
        frag.appendChild(img);
      });
      modalBody.innerHTML = '';
      modalBody.appendChild(frag);
      openModal();
    } catch (err) {
      console.error(err);
      alert('Could not load photos: ' + err.message);
    }
  });
});
</script>


<!-- Photo Modal -->
<div id="photoModal" class="photo-modal hidden" aria-hidden="true">
  <div class="photo-modal-backdrop" data-close-photo-modal></div>
  <div class="photo-modal-box" role="dialog" aria-modal="true" aria-labelledby="photoModalTitle">
    <div class="photo-modal-header">
      <h3 id="photoModalTitle">Task photos</h3>
      <button class="close-btn" type="button" title="Close" data-close-photo-modal>&times;</button>
    </div>
    <div id="photoModalBody" class="photo-modal-body">
      <!-- images are injected here -->
    </div>
  </div>
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
