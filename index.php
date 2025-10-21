<?php
require_once __DIR__ . '/helpers.php';
require_login();

$counts = analytics_counts();

$priorityData = analytics_group("SELECT priority, COUNT(*) AS total FROM tasks GROUP BY priority");
$statusData = analytics_group("SELECT status, COUNT(*) AS total FROM tasks GROUP BY status");
$assigneeData = analytics_group("SELECT COALESCE(NULLIF(TRIM(assigned_to), ''), 'Unassigned') AS label, COUNT(*) AS total
    FROM tasks GROUP BY label ORDER BY total DESC LIMIT 10");
$ageData = analytics_group("SELECT " . get_age_bucket_sql() . " AS bucket, COUNT(*) AS total FROM tasks t WHERE t.status <> 'done' GROUP BY bucket");
$buildingData = analytics_group("SELECT b.name AS label, COUNT(*) AS total FROM tasks t JOIN buildings b ON b.id = t.building_id GROUP BY b.id ORDER BY total DESC LIMIT 10");
$roomData = analytics_group("SELECT CONCAT(r.room_number, IF(r.label IS NULL OR r.label = '', '', CONCAT(' - ', r.label))) AS label,
    COUNT(*) AS total FROM tasks t JOIN rooms r ON r.id = t.room_id GROUP BY r.id ORDER BY total DESC LIMIT 10");

$title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Dashboard</h1>
    <div class="kpi-grid">
        <a class="kpi-card" href="tasks.php?status=open">
            <h2>Open Tasks</h2>
            <p class="kpi-number"><?php echo number_format($counts['open']); ?></p>
        </a>
        <a class="kpi-card" href="tasks.php?status=done&created_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
            <h2>Done (30d)</h2>
            <p class="kpi-number"><?php echo number_format($counts['done30']); ?></p>
        </a>
        <a class="kpi-card" href="tasks.php?due_from=<?php echo date('Y-m-d'); ?>&due_to=<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
            <h2>Due This Week</h2>
            <p class="kpi-number"><?php echo number_format($counts['dueWeek']); ?></p>
        </a>
        <a class="kpi-card" href="tasks.php?status=open&due_to=<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
            <h2>Overdue</h2>
            <p class="kpi-number"><?php echo number_format($counts['overdue']); ?></p>
        </a>
    </div>
</section>

<section class="grid two">
    <div class="card">
        <h2>Tasks by Priority</h2>
        <canvas id="priorityChart" class="chart" data-chart='<?php echo json_encode($priorityData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
    <div class="card">
        <h2>Tasks by Status</h2>
        <canvas id="statusChart" class="chart" data-chart='<?php echo json_encode($statusData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
</section>

<section class="grid two">
    <div class="card">
        <h2>Tasks by Assignee (Top 10)</h2>
        <canvas id="assigneeChart" class="chart" data-chart='<?php echo json_encode($assigneeData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
    <div class="card">
        <h2>Age of Open Tasks</h2>
        <canvas id="ageChart" class="chart" data-chart='<?php echo json_encode($ageData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
</section>

<section class="grid two">
    <div class="card">
        <h2>Tasks per Building</h2>
        <canvas id="buildingChart" class="chart" data-chart='<?php echo json_encode($buildingData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
    <div class="card">
        <h2>Tasks per Room (Top 10)</h2>
        <canvas id="roomChart" class="chart" data-chart='<?php echo json_encode($roomData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
</section>

<script>
(function() {
    const palette = ['#2d6cdf', '#2d9d78', '#f0b429', '#d64545', '#00a7e1', '#6c63ff', '#ff6f91', '#00c2cb'];

    function drawBarChart(canvas, data) {
        if (!canvas || !data.length) return;
        const ctx = canvas.getContext('2d');
        const width = canvas.width = canvas.clientWidth;
        const height = canvas.height = canvas.clientHeight;
        const values = data.map(row => parseInt(row.total, 10));
        const labels = data.map(row => row.label || row.priority || row.status);
        const max = Math.max(...values, 1);
        const barWidth = width / values.length * 0.6;
        const gap = width / values.length * 0.4;
        ctx.clearRect(0, 0, width, height);
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        values.forEach((value, index) => {
            const x = index * (barWidth + gap) + gap / 2;
            const barHeight = (value / max) * (height - 40);
            ctx.fillStyle = palette[index % palette.length];
            ctx.fillRect(x, height - barHeight - 20, barWidth, barHeight);
            ctx.fillStyle = '#1f2933';
            ctx.fillText(value, x + barWidth / 2, height - barHeight - 25);
            ctx.save();
            ctx.translate(x + barWidth / 2, height - 5);
            ctx.rotate(-Math.PI / 4);
            ctx.fillText(labels[index], 0, 0);
            ctx.restore();
        });
    }

    function drawDonutChart(canvas, data) {
        if (!canvas || !data.length) return;
        const ctx = canvas.getContext('2d');
        const width = canvas.width = canvas.clientWidth;
        const height = canvas.height = canvas.clientHeight;
        const total = data.reduce((sum, row) => sum + parseInt(row.total, 10), 0);
        let start = -Math.PI / 2;
        ctx.clearRect(0, 0, width, height);
        data.forEach((row, index) => {
            const value = parseInt(row.total, 10);
            const angle = (value / total) * Math.PI * 2;
            ctx.beginPath();
            ctx.fillStyle = palette[index % palette.length];
            ctx.moveTo(width / 2, height / 2);
            ctx.arc(width / 2, height / 2, Math.min(width, height) / 2 - 10, start, start + angle);
            ctx.closePath();
            ctx.fill();
            start += angle;
        });
        ctx.globalCompositeOperation = 'destination-out';
        ctx.beginPath();
        ctx.arc(width / 2, height / 2, Math.min(width, height) / 2 - 40, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalCompositeOperation = 'source-over';
        ctx.fillStyle = '#1f2933';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(total + ' tasks', width / 2, height / 2 + 5);
    }

    function drawCharts() {
        document.querySelectorAll('canvas[data-chart]').forEach(canvas => {
            const data = JSON.parse(canvas.dataset.chart);
            const id = canvas.id;
            if (id === 'statusChart') {
                drawDonutChart(canvas, data.map(row => ({ label: row.status, total: row.total })));
            } else {
                drawBarChart(canvas, data.map(row => ({
                    label: row.label || row.priority || row.status || row.bucket,
                    total: row.total
                })));
            }
        });
    }

    drawCharts();
    window.addEventListener('resize', drawCharts);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
