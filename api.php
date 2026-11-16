<?php
declare(strict_types=1);
require __DIR__ . '/server/bootstrap.php';
require __DIR__ . '/server/orders.php';
require __DIR__ . '/server/admin.php';
require __DIR__ . '/server/telegram.php';

endpoint(function () {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $action === 'catalog') {
        return catalog();
    }
    startSession();
    $csrf = $_SESSION['csrf'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($method === 'GET') {
        if ($action === 'session') {
            return ['csrf' => $csrf, 'staff' => currentStaff()];
        }
        session_write_close();
        if ($action === 'table') {
            guestIdentity();
            return ['table' => tableByToken($_GET['token'] ?? null), 'accepting' => accepting(), 'csrf' => $csrf];
        }
        if ($action === 'accepting') {
            return ['accepting' => accepting()];
        }
        if ($action === 'my_orders') {
            $ids = sql('SELECT o.id, o.status, o.created_at, t.label FROM orders o JOIN visits v ON v.id=o.visit_id
                        JOIN dining_tables t ON t.id=v.table_id WHERE o.guest=? ORDER BY o.id DESC LIMIT 50', [guestIdentity()])->fetchAll();
            return orderLines($ids);
        }
        $staff = requireStaff(currentStaff(), $action === 'admin');
        return match ($action) {
            'queue' => queue(),
            'history' => orderLines(sql('SELECT o.id, o.status, o.created_at, t.label FROM orders o JOIN visits v ON v.id=o.visit_id
                                         JOIN dining_tables t ON t.id=v.table_id WHERE v.closed_at IS NOT NULL ORDER BY o.id DESC LIMIT 100
                                         OFFSET ' . ((int) min(1_000_000, intField($_GET + ['offset' => 0], 'offset', 0))), [])->fetchAll()),
            'admin' => [
                'catalog' => catalog(true),
                'tables' => sql('SELECT id,label,qr_token FROM dining_tables WHERE enabled ORDER BY id')->fetchAll(),
                'staff' => sql('SELECT id,login,role,enabled,telegram_id FROM staff ORDER BY id')->fetchAll(),
                'base' => rtrim(config('base_url'), '/'),
            ],
            default => fail(404, 'Действие не найдено.'),
        };
    }
    if ($method !== 'POST') {
        fail(405, 'Метод не поддерживается.');
    }
    if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        fail(403, 'Сессия истекла. Повторите действие.', ['expired' => true]);
    }
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        $input = $_POST;
    } else {
        try {
            $input = json_decode(file_get_contents('php://input', false, null, 0, 65537), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            fail(422, 'Запрос не удалось прочитать.');
        }
    }
    if (!is_array($input)) {
        fail(422, 'Запрос не удалось прочитать.');
    }
    if ($action === 'login') {
        $login = loginName($input);
        limit('login-ip', $ip, 25);
        limit('login-name', $login, 12);
        $record = sql('SELECT id,password_hash,enabled,auth_version FROM staff WHERE login=?', [$login])->fetch();
        $password = $input['password'] ?? '';
        $hash = $record['password_hash'] ?? '$2y$10$4Gj/Q9n8dd0uFTdA3egbrOfPFUBaX6HzHj.MuI8NKpqJ4ILXJ5BcG';
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")
            || !password_verify($password, $hash) || !$record || !$record['enabled']) {
            fail(401, 'Проверьте логин и пароль.');
        }
        session_regenerate_id(true);
        $_SESSION['staff_id'] = (int) $record['id'];
        $_SESSION['auth_version'] = (int) $record['auth_version'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return ['ok' => true];
    }
    if ($action === 'logout') {
        unset($_SESSION['staff_id'], $_SESSION['auth_version']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return ['ok' => true];
    }
    $authVersion = $_SESSION['auth_version'] ?? null;
    session_write_close();
    if ($action === 'order') {
        $result = placeOrder($input, guestIdentity(), $ip);
        notifyAfterResponse();
        return $result;
    }
    $admin = is_string($action) && str_starts_with($action, 'admin_');
    $actor = requireStaff(currentStaff(), $admin) + ['auth_version' => $authVersion];
    if ($admin) {
        return adminAction($actor, substr($action, 6), $input);
    }
    $result = staffOrderAction($actor, (string) $action, $input);
    notifyAfterResponse();
    return $result;
});
