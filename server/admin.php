<?php
declare(strict_types=1);

function passwordHash(array $data): string
{
    $password = $data['password'] ?? '';
    if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
        fail(422, 'Пароль должен содержать от 10 до 72 байт.');
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

function loginName(array $data): string
{
    $login = strtolower(strField($data, 'login', 32));
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,31}$/D', $login)) {
        fail(422, 'Логин: 3–32 символа, латинские буквы, цифры, точка, тире или подчёркивание.');
    }
    return $login;
}

function uploadPhoto(array $file): string
{
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || $file['size'] > 6 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        fail(422, 'Загрузите фото размером до 6 МБ.');
    }
    $info = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
    if (!$info || !in_array($info[2], $allowed, true) || $info[0] * $info[1] > 12_000_000) {
        fail(422, 'Поддерживаются JPEG, PNG и WebP до 12 мегапикселей.');
    }
    $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$image) {
        fail(422, 'Не удалось открыть фотографию.');
    }
    $ratio = min(1, 1000 / max($info[0], $info[1]));
    $scaled = imagescale($image, max(1, (int) ($info[0] * $ratio)), max(1, (int) ($info[1] * $ratio)));
    $directory = config('media_dir');
    if (!$directory || !is_dir($directory) || !$scaled) {
        fail(503, 'Хранилище фотографий недоступно.');
    }
    $key = bin2hex(random_bytes(20));
    $path = $directory . '/' . $key . '.webp';
    if (!imagewebp($scaled, $path, 82) || !is_file($path) || filesize($path) === 0) {
        if (is_file($path)) {
            unlink($path);
        }
        fail(503, 'Не удалось сохранить фотографию.');
    }
    return 'photo.php?id=' . $key;
}

function adminAction(array $actor, string $action, array $input): array
{
    $photo = $action === 'dish' && isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE
        ? uploadPhoto($_FILES['photo']) : null;
    $retiredPhoto = null;
    try {
        $result = transaction(function () use ($actor, $action, $input, $photo, &$retiredPhoto) {
            lockActor($actor);
            $id = intField($input, 'id', 0);
            $flag = fn(string $key) => intField($input, $key, 0, 1);
            switch ($action) {
                case 'category':
                    $values = [strField($input, 'name', 120), intField($input, 'sort_order', 0), $flag('published')];
                    $id ? sql('UPDATE categories SET name=?, sort_order=?, published=? WHERE id=?', [...$values, $id])
                        : sql('INSERT INTO categories (name, sort_order, published) VALUES (?,?,?)', $values);
                    break;
                case 'dish':
                    $values = [intField($input, 'category_id'), strField($input, 'name', 160), strField($input, 'description', 2000, true),
                        intField($input, 'sort_order', 0), $flag('available'), $flag('published')];
                    if ($id) {
                        $dish = sql('SELECT image FROM dishes WHERE id = ? FOR UPDATE', [$id])->fetch();
                        if (!$dish) {
                            fail(404, 'Блюдо не найдено.');
                        }
                        sql('UPDATE dishes SET category_id=?, name=?, description=?, sort_order=?, available=?, published=? WHERE id=?', [...$values, $id]);
                        if ($photo || ($input['remove_photo'] ?? '') === '1') {
                            sql('UPDATE dishes SET image=? WHERE id=?', [$photo, $id]);
                            $retiredPhoto = $dish['image'];
                        }
                    } else {
                        sql('INSERT INTO dishes (category_id,name,description,sort_order,available,published,image) VALUES (?,?,?,?,?,?,?)',
                            [...$values, $photo]);
                    }
                    break;
                case 'variant':
                    $values = [strField($input, 'label', 100, true), intField($input, 'price', 1), $flag('available')];
                    $id ? sql('UPDATE variants SET label=?, price=?, available=? WHERE id=?', [...$values, $id])
                        : sql('INSERT INTO variants (label,price,available,dish_id) VALUES (?,?,?,?)', [...$values, intField($input, 'dish_id')]);
                    break;
                case 'table':
                    $name = strField($input, 'label', 80);
                    $id ? sql('UPDATE dining_tables SET label=? WHERE id=?', [$name, $id])
                        : sql('INSERT INTO dining_tables (label, qr_token) VALUES (?,?)', [$name, bin2hex(random_bytes(24))]);
                    break;
                case 'rotate':
                    sql('UPDATE dining_tables SET qr_token=?, generation=generation+1 WHERE id=? AND enabled', [bin2hex(random_bytes(24)), $id]);
                    break;
                case 'staff_add':
                    $role = strField($input, 'role', 10);
                    if (!in_array($role, ['admin', 'waiter'], true)) {
                        fail(422, 'Укажите роль сотрудника.');
                    }
                    sql('INSERT INTO staff (login,password_hash,role) VALUES (?,?,?)', [loginName($input), passwordHash($input), $role]);
                    break;
                case 'staff_password':
                    sql('UPDATE staff SET password_hash=?, auth_version=auth_version+1 WHERE id=?', [passwordHash($input), $id]);
                    break;
                case 'staff_disable':
                    if ($id === (int) $actor['id']) {
                        fail(422, 'Нельзя отключить свою учётную запись.');
                    }
                    sql('UPDATE staff SET enabled=0, auth_version=auth_version+1, telegram_id=NULL, link_hash=NULL, link_expires=NULL WHERE id=?', [$id]);
                    break;
                case 'staff_enable':
                    sql('UPDATE staff SET enabled=1 WHERE id=?', [$id]);
                    break;
                case 'staff_link':
                    $bot = config('telegram_bot');
                    if (!preg_match('/^[A-Za-z0-9_]+$/D', $bot)) {
                        fail(503, 'Telegram-бот ещё не подключён.');
                    }
                    $token = bin2hex(random_bytes(24));
                    if (!sql('UPDATE staff SET link_hash=?, link_expires=UTC_TIMESTAMP()+INTERVAL 30 MINUTE WHERE id=? AND enabled',
                        [hash('sha256', $token), $id])->rowCount()) {
                        fail(404, 'Действующий сотрудник не найден.');
                    }
                    return ['link' => 'https://t.me/' . $bot . '?start=' . $token];
                case 'staff_unlink':
                    sql('UPDATE staff SET telegram_id=NULL, link_hash=NULL, link_expires=NULL WHERE id=?', [$id]);
                    break;
                default:
                    fail(404, 'Действие не найдено.');
            }
            audit((int) $actor['id'], $action, detail: (string) ($id ?: db()->lastInsertId()));
            return ['ok' => true];
        });
    } catch (Throwable $error) {
        if ($photo) {
            $path = config('media_dir') . '/' . substr($photo, strlen('photo.php?id=')) . '.webp';
            unlink($path);
        }
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) {
            fail(409, 'Такой логин или номер столика уже используется.');
        }
        throw $error;
    }
    if ($retiredPhoto && preg_match('/^photo\.php\?id=([a-f0-9]{40})$/D', $retiredPhoto, $match)) {
        $path = config('media_dir') . '/' . $match[1] . '.webp';
        if (is_file($path) && !@unlink($path)) {
            error_log('REMI: unable to remove retired menu photo.');
        }
    }
    return $result;
}
