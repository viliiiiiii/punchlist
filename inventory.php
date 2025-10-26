<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

// Optional dev diagnostics: uncomment for troubleshooting only
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

$appsPdo = get_pdo();        // APPS (punchlist) DB
$corePdo = get_pdo('core');  // CORE (users/roles/sectors/activity) DB — may be same as APPS if not split

$canManage   = can('inventory_manage');
$isRoot      = current_user_role_key() === 'root';
$userSectorId= current_user_sector_id();

$errors = [];

// --- POST actions ---
if (is_post()) {
    try {
        if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
            $errors[] = 'Invalid CSRF token.';
        } elseif (!$canManage) {
            $errors[] = 'Insufficient permissions.';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'create_item') {
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $quantity = max(0, (int)($_POST['quantity'] ?? 0));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';
                $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;

                if ($name === '') {
                    $errors[] = 'Name is required.';
                }
                if (!$isRoot && $sectorId === null) {
                    $errors[] = 'Your sector must be assigned before creating items.';
                }

                if (!$errors) {
                    $stmt = $appsPdo->prepare('
                        INSERT INTO inventory_items (sku, name, sector_id, quantity, location)
                        VALUES (:sku, :name, :sector_id, :quantity, :location)
                    ');
                    $stmt->execute([
                        ':sku'       => $sku !== '' ? $sku : null,
                        ':name'      => $name,
                        ':sector_id' => $sectorId,
                        ':quantity'  => $quantity,
                        ':location'  => $location !== '' ? $location : null,
                    ]);
                    $itemId = (int)$appsPdo->lastInsertId();

                    if ($quantity > 0) {
                        $movStmt = $appsPdo->prepare('
                            INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                            VALUES (:item_id, :direction, :amount, :reason, :user_id)
                        ');
                        $movStmt->execute([
                            ':item_id'  => $itemId,
                            ':direction'=> 'in',
                            ':amount'   => $quantity,
                            ':reason'   => 'Initial quantity',
                            ':user_id'  => current_user()['id'] ?? null,
                        ]);
                    }
                    log_event('inventory.add', 'inventory_item', $itemId, ['quantity' => $quantity, 'sector_id' => $sectorId]);
                    redirect_with_message('inventory.php', 'Item added.');
                }
            } elseif ($action === 'update_item') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } else {
                    $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;
                    if (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                        $errors[] = 'Cannot edit items from other sectors.';
                    }
                    if ($name === '') {
                        $errors[] = 'Name is required.';
                    }
                    if (!$isRoot && $sectorId === null) {
                        $errors[] = 'Your sector must be assigned before editing items.';
                    }
                    if (!$errors) {
                        $updStmt = $appsPdo->prepare('
                            UPDATE inventory_items
                            SET name=:name, sku=:sku, location=:location, sector_id=:sector_id
                            WHERE id=:id
                        ');
                        $updStmt->execute([
                            ':name'      => $name,
                            ':sku'       => $sku !== '' ? $sku : null,
                            ':location'  => $location !== '' ? $location : null,
                            ':sector_id' => $sectorId,
                            ':id'        => $itemId,
                        ]);
                        redirect_with_message('inventory.php', 'Item updated.');
                    }
                }
            } elseif ($action === 'move_stock') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $direction= $_POST['direction'] === 'out' ? 'out' : 'in';
                $amount   = max(1, (int)($_POST['amount'] ?? 0));
                $reason   = trim((string)($_POST['reason'] ?? ''));

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } elseif (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                    $errors[] = 'Cannot move stock for other sectors.';
                } else {
                    $delta = $direction === 'in' ? $amount : -$amount;
                    $newQuantity = (int)$item['quantity'] + $delta;
                    if ($newQuantity < 0) {
                        $errors[] = 'Not enough stock to move.';
                    } else {
                        $appsPdo->beginTransaction();
                        try {
                            $appsPdo->prepare('UPDATE inventory_items SET quantity = quantity + :delta WHERE id = :id')
                                    ->execute([':delta' => $delta, ':id' => $itemId]);

                            $appsPdo->prepare('
                                INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                                VALUES (:item_id, :direction, :amount, :reason, :user_id)
                            ')->execute([
                                ':item_id'  => $itemId,
                                ':direction'=> $direction,
                                ':amount'   => $amount,
                                ':reason'   => $reason !== '' ? $reason : null,
                                ':user_id'  => current_user()['id'] ?? null,
                            ]);

                            $appsPdo->commit();
                            log_event('inventory.move', 'inventory_item', $itemId, ['direction' => $direction, 'amount' => $amount]);
                            redirect_with_message('inventory.php', 'Stock updated.');
                        } catch (Throwable $e) {
                            $appsPdo->rollBack();
                            $errors[] = 'Unable to record movement.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// --- Fetch sectors (CORE) ---
$sectorOptions = [];
try {
    $sectorOptions = $corePdo->query('SELECT id, name FROM sectors ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Sectors table missing in CORE DB (or query failed).';
}

// --- Sector filter logic ---
if ($isRoot) {
    $sectorFilter = $_GET['sector'] ?? '';
} elseif ($userSectorId !== null) {
    $sectorFilter = (string)$userSectorId;
} else {
    $sectorFilter = 'null';
}

$where = [];
$params= [];
if ($sectorFilter !== '' && $sectorFilter !== 'all') {
    if ($sectorFilter === 'null') {
        $where[] = 'sector_id IS NULL';
    } else {
        $where[] = 'sector_id = :sector';
        $params[':sector'] = (int)$sectorFilter;
    }
}
if (!$isRoot && $userSectorId !== null) {
    $where[] = 'sector_id = :my_sector';
    $params[':my_sector'] = (int)$userSectorId;
}
if (!$isRoot && $userSectorId === null) {
    $where[] = 'sector_id IS NULL';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Fetch items & recent movements (APPS) ---
$items = [];
$movementsByItem = [];

try {
    $itemStmt = $appsPdo->prepare("SELECT * FROM inventory_items $whereSql ORDER BY name");
    $itemStmt->execute($params);
    $items = $itemStmt->fetchAll();

    if ($items) {
        $movementStmt = $appsPdo->prepare('SELECT * FROM inventory_movements WHERE item_id = ? ORDER BY ts DESC LIMIT 5');
        foreach ($items as $item) {
            $movementStmt->execute([$item['id']]);
            $movementsByItem[$item['id']] = $movementStmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Inventory tables missing in APPS DB (or query failed).';
}

// --- Helper to resolve sector name ---
function sector_name_by_id(array $sectors, $id): string {
    foreach ($sectors as $s) {
        if ((string)$s['id'] === (string)$id) return (string)$s['name'];
    }
    return '';
}

$title = 'Inventory';
include __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Inventory</h1>
    <?php if ($errors): ?>
        <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
    <?php endif; ?>
    <form method="get" class="grid three">
        <label>Sector
            <select name="sector" <?php echo $isRoot ? '' : 'disabled'; ?>>
                <option value="all">All</option>
                <option value="null" <?php echo $sectorFilter === 'null' ? 'selected' : ''; ?>>Unassigned</option>
                <?php foreach ((array)$sectorOptions as $sector): ?>
                    <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$sector['id'] === (string)$sectorFilter) ? 'selected' : ''; ?>>
                        <?php echo sanitize((string)$sector['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($isRoot): ?>
            <div class="form-actions">
                <button class="btn" type="submit">Filter</button>
                <a class="btn secondary" href="inventory.php">Reset</a>
            </div>
        <?php endif; ?>
    </form>
</section>

<?php if ($canManage): ?>
<section class="card">
    <h2>Add Item</h2>
    <form method="post" class="grid four">
        <label>Name
            <input type="text" name="name" required>
        </label>
        <label>SKU
            <input type="text" name="sku">
        </label>
        <label>Initial Quantity
            <input type="number" name="quantity" min="0" value="0">
        </label>
        <label>Location
            <input type="text" name="location">
        </label>
        <?php if ($isRoot): ?>
            <label>Sector
                <select name="sector_id">
                    <option value="null">Unassigned</option>
                    <?php foreach ((array)$sectorOptions as $sector): ?>
                        <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <input type="hidden" name="action" value="create_item">
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <div class="form-actions">
            <button class="btn primary" type="submit">Add</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2>Items</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>SKU</th>
                <th>Sector</th>
                <th>Quantity</th>
                <th>Location</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo sanitize((string)$item['name']); ?></td>
                <td><?php echo !empty($item['sku']) ? sanitize((string)$item['sku']) : '<em>-</em>'; ?></td>
                <td>
                    <?php
                        $sn = sector_name_by_id((array)$sectorOptions, $item['sector_id']);
                        echo $sn !== '' ? sanitize($sn) : '<em>Unassigned</em>';
                    ?>
                </td>
                <td><?php echo (int)$item['quantity']; ?></td>
                <td><?php echo !empty($item['location']) ? sanitize((string)$item['location']) : '<em>-</em>'; ?></td>
                <td>
                    <details>
                        <summary>Details</summary>
                        <?php if ($canManage && ($isRoot || (int)$item['sector_id'] === (int)$userSectorId)): ?>
                            <form method="post" class="form-inline">
                                <label>Name
                                    <input type="text" name="name" value="<?php echo sanitize((string)$item['name']); ?>" required>
                                </label>
                                <label>SKU
                                    <input type="text" name="sku" value="<?php echo sanitize((string)($item['sku'] ?? '')); ?>">
                                </label>
                                <label>Location
                                    <input type="text" name="location" value="<?php echo sanitize((string)($item['location'] ?? '')); ?>">
                                </label>
                                <?php if ($isRoot): ?>
                                    <label>Sector
                                        <select name="sector_id">
                                            <option value="null">Unassigned</option>
                                            <?php foreach ((array)$sectorOptions as $sector): ?>
                                                <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$item['sector_id'] === (string)$sector['id']) ? 'selected' : ''; ?>>
                                                    <?php echo sanitize((string)$sector['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                                <input type="hidden" name="action" value="update_item">
                                <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                <button class="btn small" type="submit">Save</button>
                            </form>
                            <form method="post" class="form-inline">
                                <label>Direction
                                    <select name="direction">
                                        <option value="in">In</option>
                                        <option value="out">Out</option>
                                    </select>
                                </label>
                                <label>Amount
                                    <input type="number" name="amount" min="1" value="1" required>
                                </label>
                                <label>Reason
                                    <input type="text" name="reason" placeholder="Optional reason">
                                </label>
                                <input type="hidden" name="action" value="move_stock">
                                <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                <button class="btn small primary" type="submit">Record</button>
                            </form>
                        <?php endif; ?>
                        <h3>Recent Movements</h3>
                        <ul class="movements">
                            <?php foreach ($movementsByItem[$item['id']] ?? [] as $move): ?>
                                <li>
                                    <strong><?php echo sanitize(strtoupper((string)$move['direction'])); ?></strong>
                                    <?php echo (int)$move['amount']; ?>
                                    <span class="muted small"><?php echo sanitize((string)$move['ts']); ?></span>
                                    <?php if (!empty($move['reason'])): ?> - <?php echo sanitize((string)$move['reason']); ?><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($movementsByItem[$item['id']])): ?>
                                <li class="muted small">No movements yet.</li>
                            <?php endif; ?>
                        </ul>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
