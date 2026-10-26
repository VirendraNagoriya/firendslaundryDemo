<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE_URL === '' ? '/' : BASE_URL,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = db_row('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $_SESSION['user_id']]);
    if ($user === null) {
        unset($_SESSION['user_id']);
        $user = null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], ['super_admin', 'admin'], true);
}

function is_super_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'super_admin';
}

function is_staff(): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], staff_roles(), true);
}

function is_customer(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'customer';
}

function attempt_login(string $identity, string $password): array
{
    $key = phone_key($identity);
    $user = $key !== ''
        ? db_row('SELECT * FROM users WHERE (email = ? OR phone = ? OR phone_key = ?) LIMIT 1', [$identity, $identity, $key])
        : db_row('SELECT * FROM users WHERE (email = ? OR phone = ?) LIMIT 1', [$identity, $identity]);
    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        return ['ok' => false, 'message' => 'Invalid credentials. Please check your details.'];
    }
    if ((int) $user['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'This account has been disabled. Contact the owner.'];
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    db_exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), (int) $user['id']]);
    audit('login', 'user', (string) $user['id'], $user['role']);
    return ['ok' => true, 'user' => $user];
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function home_for_role(string $role): string
{
    switch ($role) {
        case 'super_admin':
        case 'admin':
            return 'admin/index.php';
        case 'staff':
        case 'pickup':
        case 'delivery':
            return 'staff/index.php';
        case 'customer':
            return 'customer/index.php';
        default:
            return 'index.php';
    }
}

function require_login(bool $allowPasswordChange = false): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Please sign in to continue.');
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    if (!$allowPasswordChange && (int) $user['must_change_password'] === 1 && !in_array($user['role'], staff_roles(), true)) {
        redirect('account/change-password.php');
    }
    return $user;
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        flash('error', 'You do not have permission to open that page.');
        redirect(home_for_role($user['role']));
    }
    return $user;
}

function require_admin(): array
{
    return require_roles(['super_admin', 'admin']);
}

function require_super_admin(): array
{
    return require_roles(['super_admin']);
}

function require_staff(): array
{
    return require_roles(staff_roles());
}

function require_customer(): array
{
    return require_roles(['customer']);
}

function login_redirect_target(): string
{
    $user = current_user();
    if ($user !== null) {
        return home_for_role($user['role']);
    }
    return 'index.php';
}
