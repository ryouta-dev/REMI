<?php
declare(strict_types=1);

final class RequestError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $details = [])
    {
        parent::__construct($message);
    }
}

function config(string $key): string
{
    static $values;
    if ($values === null) {
        $path = getenv('REMI_CONFIG') ?: dirname(__DIR__, 2) . '/remi-config.php';
        $values = is_file($path) ? require $path : [];
    }
    return (string) ($values[$key] ?? '');
}

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(config('dsn'), config('db_user'), config('db_password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $pdo;
}

function sql(string $query, array $args = []): PDOStatement
{
    $statement = db()->prepare($query);
    $statement->execute($args);
    return $statement;
}

function transaction(callable $callback): mixed
{
    db()->beginTransaction();
    try {
        $result = $callback();
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

function fail(int $status, string $message, array $details = []): never
{
    throw new RequestError($status, $message, $details);
}

function startSession(): void
{
    ini_set('session.use_strict_mode', '1');
    session_name('remi_session');
    session_set_cookie_params([
        'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => str_starts_with(config('base_url'), 'https://'),
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function guestIdentity(): string
{
    $token = $_COOKIE['remi_guest'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $token = bin2hex(random_bytes(32));
    }
    setcookie('remi_guest', $token, [
        'expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => str_starts_with(config('base_url'), 'https://'),
    ]);
    return hash('sha256', $token);
}

function limit(string $scope, string $value, int $max): void
{
    $bucket = hash('sha256', $scope . ':' . $value);
    $window = intdiv(time(), 300);
    sql('INSERT INTO attempts VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE
         hits = IF(window_id = VALUES(window_id), hits + 1, 1), window_id = VALUES(window_id)', [$bucket, $window]);
    if ((int) sql('SELECT hits FROM attempts WHERE bucket = ?', [$bucket])->fetchColumn() > $max) {
        fail(429, 'Слишком много попыток. Повторите через несколько минут.');
    }
}

function currentStaff(): ?array
{
    if (!isset($_SESSION['staff_id'], $_SESSION['auth_version'])) {
        return null;
    }
    return sql('SELECT id, login, role, telegram_id FROM staff WHERE id = ? AND enabled AND auth_version = ?',
        [$_SESSION['staff_id'], $_SESSION['auth_version']])->fetch() ?: null;
}

function requireStaff(?array $staff, bool $admin = false): array
{
    if (!$staff) {
        fail(401, 'Войдите в систему.');
    }
    if ($admin && $staff['role'] !== 'admin') {
        fail(403, 'Действие доступно администратору.');
    }
    return $staff;
}

function strField(array $data, string $key, int $max, bool $optional = false): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) || !preg_match('//u', $value)) {
        fail(422, 'Проверьте заполнение полей.');
    }
    $value = trim($value);
    if ((!$optional && $value === '') || mb_strlen($value) > $max) {
        fail(422, 'Проверьте заполнение полей.');
    }
    return $value;
}

function intField(array $data, string $key, int $min = 1, int $max = 2147483647): int
{
    $value = $data[$key] ?? null;
    if ((!is_int($value) && !is_string($value))
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
        fail(422, 'Проверьте числовые поля.');
    }
    return (int) $value;
}

function audit(int $staff, string $action, ?int $order = null, ?int $visit = null, ?string $detail = null): void
{
    sql('INSERT INTO staff_events (staff_id, action, order_id, visit_id, detail) VALUES (?, ?, ?, ?, ?)',
        [$staff, $action, $order, $visit, $detail]);
}

function respond(mixed $value): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function endpoint(callable $callback): never
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    try {
        respond($callback());
    } catch (RequestError $error) {
        http_response_code($error->status);
        respond(['error' => $error->getMessage()] + $error->details);
    } catch (Throwable $error) {
        error_log('REMI ' . $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
        http_response_code(500);
        respond(['error' => 'Сервис временно недоступен. Повторите попытку.']);
    }
}
