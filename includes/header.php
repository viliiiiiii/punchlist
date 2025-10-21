<?php
if (!isset($title)) {
    $title = APP_TITLE;
}
$roleKey = current_user_role_key();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo sanitize($title); ?> - <?php echo sanitize(APP_TITLE); ?></title>
</head>
<body>
<header class="site-header">
    <nav class="nav">
        <a class="brand" href="/index.php"><?php echo sanitize(APP_TITLE); ?></a>
        <ul class="nav-links">
            <li><a href="/index.php">Dashboard</a></li>
            <li><a href="/tasks.php">Tasks</a></li>
            <li><a href="/rooms.php">Rooms</a></li>
            <li><a href="/inventory.php">Inventory</a></li>
            <?php if ($roleKey === 'root'): ?>
                <li><a href="/admin/users.php">Users</a></li>
                <li><a href="/admin/sectors.php">Sectors</a></li>
                <li><a href="/admin/activity.php">Activity</a></li>
            <?php endif; ?>
        </ul>
        <div class="nav-user">
            <?php if (current_user()): ?>
                <span><?php echo sanitize(current_user()['email'] ?? ''); ?></span>
                <a href="/logout.php">Logout</a>
            <?php else: ?>
                <a href="/login.php">Login</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<main class="container">
    <?php flash_message(); ?>
