<?php
require_once __DIR__ . '/helpers.php';
require_login();

use Dompdf\Dompdf;
use Dompdf\Options;

set_time_limit(120);

/* ---------- selection vs. filters ---------- */
$selectedIds = [];
if (!empty($_REQUEST['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
}

if ($selectedIds) {
    $tasks   = fetch_tasks_by_ids($selectedIds);
    $filters = [];
    $summary = 'Selected tasks: ' . implode(', ', $selectedIds);
} else {
    $filters = get_filter_values();
    // Use the same function you use for export (no images required here)
    $tasks   = export_tasks($filters);
    $summary = filter_summary($filters);
}

/* ---------- build HTML (Dompdf-friendly, landscape table) ---------- */
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Tasks Table Report</title>
<style>
  /* Page: landscape, leave room for header/footer */
  @page {
    margin: 60px 28px 55px 28px; /* top, right, bottom, left */
  }

  /* Repeat table header/footer on each page (Dompdf friendly) */
  thead { display: table-header-group; }
  tfoot { display: table-row-group; }
  tr    { page-break-inside: avoid; }

  /* Header & Footer boxes (fixed) */
  .pdf-header {
    position: fixed; top: -46px; left: 0; right: 0; height: 46px;
    font-family: DejaVu Sans, Arial, sans-serif; color: #162029;
    border-bottom: 1px solid #dfe3ea;
    padding: 6px 0 6px 0;
  }
  .pdf-footer {
    position: fixed; bottom: -42px; left: 0; right: 0; height: 42px;
    border-top: 1px solid #dfe3ea;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 10px; color: #6b7280;
    padding-top: 6px;
  }
  .brand { font-weight: 700; font-size: 14px; }
  .meta-line { font-size: 10px; color: #6b7280; }

  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 11px; color: #162029; line-height: 1.25;
  }

  /* Summary line (compact) */
  .summary {
    border: 1px solid #e6e9ef; border-radius: 4px; padding: 6px 8px;
    background: #f6f8fb; margin: 0 0 8px 0; font-size: 10px;
  }

  /* Compact Excel-like table */
  table.tasks {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed; /* keeps widths predictable in landscape */
    border: 1px solid #e6e9ef;
  }

  table.tasks thead th {
    background: #f6f8fb;
    border-bottom: 1px solid #e6e9ef;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .02em;
    color: #445064;
    padding: 6px 6px;
  }

  table.tasks tbody td {
    padding: 6px 6px;
    border-bottom: 1px solid #eef1f6;
    vertical-align: top;
    word-wrap: break-word;      /* dompdf supports normal wrapping */
    overflow: hidden;           /* no ellipsis in dompdf, just wrap */
  }

  /* Zebra rows for readability */
  table.tasks tbody tr:nth-child(even) td { background: #fbfdff; }

  /* Column widths (tweak as needed) */
  .col-id       { width: 40px; }
  .col-building { width: 120px; }
  .col-room     { width: 120px; }
  .col-title    { width: 260px; }
  .col-priority { width: 80px; }
  .col-status   { width: 90px; }
  .col-assigned { width: 120px; }
  .col-due      { width: 90px; }
  .col-created  { width: 110px; }
  .col-updated  { width: 110px; }

  /* Small, unobtrusive badges as plain text cells here */
  .muted { color: #6b7280; }

  /* Tiny helpers */
  .nowrap { white-space: nowrap; } /* Use sparingly; wrapping is preferred for PDF */
</style>
</head>
<body>

<!-- Fixed header -->
<div class="pdf-header">
  <div class="brand">Tasks Table Report</div>
  <div class="meta-line">
    Generated: <?php echo htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?> •
    Total tasks: <?php echo (int)count($tasks); ?> •
    Layout: Landscape (no images)
  </div>
</div>

<!-- Fixed footer -->
<div class="pdf-footer">
  <span class="muted">www.your-domain.tld</span>
</div>

<!-- Content -->
<div class="summary">
  <strong>Filters:</strong> <?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?>
</div>

<?php if (empty($tasks)): ?>
  <p class="muted">No tasks found for the selected filters.</p>
<?php else: ?>
  <table class="tasks">
    <thead>
      <tr>
        <th class="col-id">ID</th>
        <th class="col-building">Building</th>
        <th class="col-room">Room</th>
        <th class="col-title">Title</th>
        <th class="col-priority">Priority</th>
        <th class="col-status">Status</th>
        <th class="col-assigned">Assigned To</th>
        <th class="col-due">Due</th>
        <th class="col-created">Created</th>
        <th class="col-updated">Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tasks as $task): ?>
        <?php
          $roomText = $task['room_number'] . (!empty($task['room_label']) ? ' - ' . $task['room_label'] : '');
          $created  = !empty($task['created_at']) ? substr((string)$task['created_at'], 0, 16) : '';
          $updated  = !empty($task['updated_at']) ? substr((string)$task['updated_at'], 0, 16) : '';
          $due      = !empty($task['due_date'])    ? (string)$task['due_date'] : '—';
        ?>
        <tr>
          <td class="col-id">#<?php echo (int)$task['id']; ?></td>
          <td class="col-building"><?php echo htmlspecialchars($task['building_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-room"><?php echo htmlspecialchars($roomText, ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-title"><?php echo htmlspecialchars($task['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-priority"><?php echo htmlspecialchars(priority_label($task['priority'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-status"><?php echo htmlspecialchars(status_label($task['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-assigned"><?php echo htmlspecialchars($task['assigned_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-due"><?php echo htmlspecialchars($due, ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-created"><?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-updated"><?php echo htmlspecialchars($updated, ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* ---------- Dompdf options ---------- */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

/* A4 Landscape */
$dompdf->setPaper('A4', 'landscape');

$dompdf->render();

/* Page numbers via canvas */
$canvas = $dompdf->get_canvas();
$w = $canvas->get_width();
$h = $canvas->get_height();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$size = 9;
$canvas->page_text($w - 130, $h - 30, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, $size, [0.42, 0.45, 0.50]);

// Stream inline
$dompdf->stream('tasks-table.pdf', ['Attachment' => false]);
