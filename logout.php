<?php
require_once __DIR__ . '/auth.php';
logout();
redirect_with_message('login.php', 'Signed out successfully.', 'success');
