<?php
require_once __DIR__ . '/../helpers.php';
require_perm('view_audit');

$corePdo = get_pdo('core');

$filters = [
    'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null,
    'action' => trim((string)($_GET['action'] ?? '')),
    'from' => trim((string)($_GET['from'] ?? '')),
    'to' => trim((string)($_GET['to'] ?? '')),
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where = [];
$params = [];
if ($filters['user_id']) {
    $where[] = 'al.user_id = :user_id';
    $params[':user_id'] = $filters['user_id'];
}
if ($filters['action'] !== '') {
    $where[] = 'al.action LIKE :action';
    $params[':action'] = $filters['action'] . '%';
}
if ($filters['from'] !== '') {
    $where[] = 'al.ts >= :from';
    $params[':from'] = $filters['from'];
}
if ($filters['to'] !== '') {
    $where[] = 'al.ts <= :to';
    $params[':to'] = $filters['to'] . ' 23:59:59';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $corePdo->prepare("SELECT COUNT(*) FROM activity_log al $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT al.*, u.email FROM activity_log al LEFT JOIN users u ON u.id = al.user_id $whereSql ORDER BY al.id DESC LIMIT :limit OFFSET :offset";
$stmt = $corePdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$userOptions = $corePdo->query('SELECT id, email FROM users ORDER BY email')->fetchAll();

function render_meta(?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return sanitize($json);
    }
    return '<pre class="meta">' . sanitize(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

function render_ip($binary): string {
    if ($binary === null || $binary === '') {
        return '';
    }
    $ip = @inet_ntop($binary);
    return $ip ?: '';
}

$pages = (int)ceil($total / $perPage);

$title = 'Activity Log';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h1>Activity Log</h1>

    <form method="get" class="form-compact">
        <div class="grid-compact">
            <label class="field">
                <span class="lbl">User</span>
                <select name="user_id">
                    <option value="">All</option>
                    <?php foreach ($userOptions as $user): ?>
                        <option value="<?php echo (int)$user['id']; ?>" <?php echo ($filters['user_id'] == $user['id']) ? 'selected' : ''; ?>>
                            <?php echo sanitize($user['email']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="lbl">Action</span>
                <input type="text" name="action" value="<?php echo sanitize($filters['action']); ?>" placeholder="e.g. task.create">
            </label>

            <label class="field">
                <span class="lbl">From</span>
                <input type="date" name="from" value="<?php echo sanitize($filters['from']); ?>">
            </label>

            <label class="field">
                <span class="lbl">To</span>
                <input type="date" name="to" value="<?php echo sanitize($filters['to']); ?>">
            </label>

            <div class="form-actions-compact field-span-3">
                <button class="btn primary btn-compact" type="submit">Filter</button>
                <a class="btn secondary btn-compact" href="activity.php">Reset</a>
            </div>
        </div>
    </form>
</section>

<section class="card">
    <h2>Results</h2>

    <table class="table table-excel">
        <thead>
            <tr>
                <th class="col-id">Time</th>
                <th>User</th>
                <th class="col-status">Action</th>
                <th>Entity</th>
                <th>Meta</th>
                <th>IP</th>
                <th>User Agent</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td data-label="Time"><?php echo sanitize($row['ts']); ?></td>

                <td data-label="User">
                    <?php echo $row['email'] ? sanitize($row['email']) : '<em class="muted">System</em>'; ?>
                </td>

                <td data-label="Action">
                    <span class="badge"><?php echo sanitize($row['action']); ?></span>
                </td>

                <td data-label="Entity">
                    <?php echo sanitize(trim($row['entity_type'] . '#' . ($row['entity_id'] ?? ''))); ?>
                </td>

                <td data-label="Meta">
                    <div class="truncate-2"><?php echo render_meta($row['meta']); ?></div>
                </td>

                <td data-label="IP">
                    <code><?php echo sanitize(render_ip($row['ip'])); ?></code>
                </td>

                <td data-label="User Agent">
                    <span class="muted small"><?php echo sanitize($row['ua'] ?? ''); ?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Activity pages">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php $query = http_build_query(array_merge($filters, ['page' => $p])); ?>
                <a
                  class="btn small <?php echo $p === $page ? 'primary' : 'secondary'; ?>"
                  href="?<?php echo $query; ?>"
                ><?php echo $p; ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
