<?php
if (!isset($title)) { $title = APP_TITLE; }
$roleKey = current_user_role_key();
$me = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo sanitize($title); ?> - <?php echo sanitize(APP_TITLE); ?></title>

    <!-- Use root-absolute path so CSS loads from subfolders too -->
    <link rel="stylesheet" href="/assets/css/app.css?v=2">
</head>
<body>
<header>
    <div class="topbar">
        <div class="brand">
    <a href="/index.php" class="brand-logo" aria-label="<?php echo sanitize(APP_TITLE); ?>">
        <img src="/assets/logo.png" alt="<?php echo sanitize(APP_TITLE); ?> logo" class="logo">
    </a>
    <a href="/index.php" class="brand-title"><?php echo sanitize(APP_TITLE); ?></a>
</div>


        <nav aria-label="Main">
            <ul>
                <li><a href="/index.php">Dashboard</a></li>
                <li><a href="/tasks.php">Tasks</a></li>
                <li><a href="/rooms.php">Rooms</a></li>
                <li><a href="/inventory.php">Inventory</a></li>
                <li><a href="/notes/index.php">Notes</a></li>
                <?php if ($roleKey === 'root'): ?>
                    <li><a href="/admin/users.php">Users</a></li>
                    <li><a href="/admin/sectors.php">Sectors</a></li>
                    <li><a href="/admin/activity.php">Activity</a></li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="nav-user">
            <?php if ($me): ?>
                <span><?php echo sanitize($me['email'] ?? ''); ?></span>
                <a class="btn small" href="/logout.php">Logout</a>
            <?php else: ?>
                <a class="btn small" href="/login.php">Login</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="container">
    <?php flash_message(); ?>
