<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/orders.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/telegram.php';

function importCatalog(string $path): void
{
    $raw = file_get_contents($path);
    $categories = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($categories) || !array_is_list($categories) || !$categories) {
        fail(422, 'Ожидается массив категорий меню.');
    }
    transaction(function () use ($categories) {
        sql('SELECT id FROM settings WHERE id=1 FOR UPDATE');
        if ((int) sql('SELECT COUNT(*) FROM categories')->fetchColumn() !== 0) {
            fail(409, 'Импорт доступен только в пустой каталог.');
        }
        foreach ($categories as $position => $category) {
            sql('INSERT INTO categories (name,sort_order) VALUES (?,?)', [strField($category, 'name', 120), $position]);
            $categoryId = (int) db()->lastInsertId();
            foreach ($category['items'] as $index => $dish) {
                $name = strField($dish, 'name', 160);
                $image = $dish['image'] ?? null;
                if ($image !== null && (!is_string($image) || !preg_match('/^[a-zA-Z0-9_\/-]+$/D', $image)
                    || !is_file(dirname(__DIR__) . '/assets/' . $image . '-204.webp'))) {
                    fail(422, 'Проверьте файл фото для «' . $name . '».');
                }
                sql('INSERT INTO dishes (category_id,name,description,image,sort_order) VALUES (?,?,?,?,?)', [
                    $categoryId, $name, strField($dish, 'description', 2000, true), $image, $index,
                ]);
                $dishId = (int) db()->lastInsertId();
                $prices = $dish['prices'] ?? [];
                $variants = $dish['variants'] ?? null;
                if ($variants === null) {
                    $labels = [];
                    if (count($prices) === 2 && preg_match('/\((\d+)\/(\d+) мл\)$/u', $name, $sizes)) {
                        $labels = [$sizes[1] . ' мл', $sizes[2] . ' мл'];
                    }
                    $variants = [];
                    foreach ($prices as $i => $price) {
                        $variants[] = ['label' => $labels[$i] ?? '', 'price' => $price, 'available' => count($prices) === 1 || isset($labels[$i])];
                    }
                    if (count($prices) > 1 && !$labels) {
                        fwrite(STDERR, "Проверьте варианты и включите наличие в админке: $name\n");
                    }
                }
                foreach ($variants as $variant) {
                    $label = strField($variant, 'label', 100, true);
                    $price = intField($variant, 'price', 1);
                    $available = (int) ($variant['available'] ?? true);
                    sql('INSERT INTO variants (dish_id,label,price,available) VALUES (?,?,?,?)', [$dishId, $label, $price, $available]);
                }
            }
        }
    });
}

try {
    switch ($argv[1] ?? '') {
        case 'install':
            db()->exec(file_get_contents(__DIR__ . '/schema.sql'));
            echo "База подготовлена.\n";
            break;
        case 'admin':
            $login = loginName(['login' => $argv[2] ?? '']);
            if (getenv('REMI_INITIAL_PASSWORD') === false) {
                fwrite(STDERR, "Пароль из stdin: ");
            }
            $password = getenv('REMI_INITIAL_PASSWORD');
            if ($password === false) {
                $password = rtrim(fgets(STDIN) ?: '', "\r\n");
            }
            sql("INSERT INTO staff (login,password_hash,role) VALUES (?,?,'admin')", [$login, passwordHash(['password' => $password])]);
            echo "Администратор создан.\n";
            break;
        case 'import':
            importCatalog($argv[2] ?? dirname(__DIR__) . '/menu.json');
            echo "Каталог импортирован.\n";
            break;
        case 'notify':
            flushTelegram();
            sql('DELETE FROM attempts WHERE window_id < ?', [intdiv(time(), 300) - 288]);
            sql('DELETE FROM telegram_updates WHERE processed_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
            break;
        case 'webhook':
            $secret = config('telegram_secret');
            $base = rtrim(config('base_url'), '/');
            if (!str_starts_with($base, 'https://') || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)) {
                fail(422, 'Укажите HTTPS-адрес и секрет вебхука от 32 символов.');
            }
            $result = telegram('setWebhook', ['url' => $base . '/telegram.php', 'secret_token' => $secret,
                'allowed_updates' => ['message', 'callback_query']]);
            if (!$result['ok']) {
                fail(503, 'Telegram не принял настройку вебхука.');
            }
            echo "Вебхук подключён.\n";
            break;
        default:
            fwrite(STDERR, "php server/cli.php install | admin LOGIN | import [MENU] | notify | webhook\n");
            exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof RequestError ? $error->getMessage() : $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine()) . PHP_EOL);
    exit(1);
}
