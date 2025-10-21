<?php
require_once __DIR__ . '/auth.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $error = 'Invalid CSRF token';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$email || !$password) {
            $error = 'Email and password are required.';
        } elseif (!attempt_login($email, $password)) {
            $error = 'Invalid credentials.';
        } else {
            redirect_with_message('index.php', 'Welcome back!');
        }
    }
}

$title = 'Login';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-wrapper">
    <form method="post" class="card">
        <h1>Punch List Login</h1>
        <?php if ($error): ?>
            <div class="flash flash-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <label>Email
            <input type="email" name="email" required value="<?php echo sanitize($_POST['email'] ?? ''); ?>">
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <button type="submit" class="btn primary">Login</button>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
