<?php
require_once __DIR__ . '/../helpers.php';
require_perm('manage_sectors');

$corePdo = get_pdo('core');
$errors = [];

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $slug = strtolower(trim((string)($_POST['key_slug'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($action === 'create') {
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                $errors[] = 'Slug must be lowercase letters, numbers, dashes, or underscores.';
            }
            if ($name === '') {
                $errors[] = 'Name is required.';
            }
            if (!$errors) {
                try {
                    $stmt = $corePdo->prepare('INSERT INTO sectors (key_slug, name) VALUES (:slug, :name)');
                    $stmt->execute([':slug' => $slug, ':name' => $name]);
                    $sectorId = (int)$corePdo->lastInsertId();
                    log_event('sector.create', 'sector', $sectorId, ['slug' => $slug]);
                    redirect_with_message('sectors.php', 'Sector created.');
                } catch (Throwable $e) {
                    $errors[] = 'Could not create sector.';
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid sector.';
            }
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                $errors[] = 'Slug must be lowercase letters, numbers, dashes, or underscores.';
            }
            if ($name === '') {
                $errors[] = 'Name is required.';
            }
            if (!$errors) {
                try {
                    $stmt = $corePdo->prepare('UPDATE sectors SET key_slug=:slug, name=:name WHERE id=:id');
                    $stmt->execute([':slug' => $slug, ':name' => $name, ':id' => $id]);
                    log_event('sector.update', 'sector', $id, ['slug' => $slug]);
                    redirect_with_message('sectors.php', 'Sector updated.');
                } catch (Throwable $e) {
                    $errors[] = 'Could not update sector.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid sector.';
            } else {
                try {
                    $stmt = $corePdo->prepare('DELETE FROM sectors WHERE id=:id');
                    $stmt->execute([':id' => $id]);
                    log_event('sector.delete', 'sector', $id);
                    redirect_with_message('sectors.php', 'Sector deleted.');
                } catch (Throwable $e) {
                    $errors[] = 'Could not delete sector (in use?).';
                }
            }
        }
    }
}

$sectors = $corePdo->query('SELECT * FROM sectors ORDER BY name')->fetchAll();

$title = 'Manage Sectors';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h1>Sectors</h1>
    <?php if ($errors): ?>
        <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
    <?php endif; ?>
    <form method="post" class="grid three">
        <label>Slug
            <input type="text" name="key_slug" required placeholder="e.g. facilities">
        </label>
        <label>Name
            <input type="text" name="name" required placeholder="Display name">
        </label>
        <div class="form-actions">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit">Add Sector</button>
        </div>
    </form>
</section>

<section class="card">
    <h2>Existing Sectors</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Slug</th>
                <th>Name</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sectors as $sector): ?>
            <tr>
                <td><?php echo sanitize($sector['key_slug']); ?></td>
                <td><?php echo sanitize($sector['name']); ?></td>
                <td>
                    <details>
                        <summary>Edit</summary>
                        <form method="post" class="form-inline">
                            <label>Slug
                                <input type="text" name="key_slug" value="<?php echo sanitize($sector['key_slug']); ?>" required>
                            </label>
                            <label>Name
                                <input type="text" name="name" value="<?php echo sanitize($sector['name']); ?>" required>
                            </label>
                            <input type="hidden" name="id" value="<?php echo (int)$sector['id']; ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                            <button class="btn small primary" type="submit">Save</button>
                        </form>
                        <form method="post" class="form-inline" onsubmit="return confirm('Delete this sector?');">
                            <input type="hidden" name="id" value="<?php echo (int)$sector['id']; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                            <button class="btn small danger" type="submit">Delete</button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
