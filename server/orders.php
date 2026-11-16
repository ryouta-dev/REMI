<?php
declare(strict_types=1);

const STATUS = [
    'pending' => 'Ожидает подтверждения', 'cooking' => 'Готовится', 'served' => 'Подан',
    'rejected' => 'Отклонён', 'cancelled' => 'Отменён',
];

function catalog(bool $admin = false): array
{
    $categories = sql('SELECT * FROM categories ' . ($admin ? '' : 'WHERE published ') . 'ORDER BY sort_order, id')->fetchAll();
    $dishes = sql('SELECT * FROM dishes ' . ($admin ? '' : 'WHERE published ') . 'ORDER BY sort_order, id')->fetchAll();
    $variants = sql('SELECT * FROM variants ORDER BY id')->fetchAll();
    $byDish = [];
    foreach ($variants as $variant) {
        $byDish[$variant['dish_id']][] = $variant;
    }
    $byCategory = [];
    foreach ($dishes as $dish) {
        $dish['variants'] = $byDish[$dish['id']] ?? [];
        $dish['prices'] = array_column($dish['variants'], 'price');
        $byCategory[$dish['category_id']][] = $dish;
    }
    foreach ($categories as &$category) {
        $category['items'] = $byCategory[$category['id']] ?? [];
    }
    return $categories;
}

function accepting(): bool
{
    return (bool) sql('SELECT accepting FROM settings WHERE id = 1')->fetchColumn();
}

function tableByToken(mixed $token, bool $lock = false): array
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/D', $token)) {
        fail(404, 'QR-код столика недействителен.');
    }
    $table = sql('SELECT id, label, generation FROM dining_tables WHERE qr_token = ? AND enabled'
        . ($lock ? ' FOR UPDATE' : ''), [$token])->fetch();
    if (!$table) {
        fail(404, 'QR-код столика недействителен. Обратитесь к официанту.');
    }
    return $table;
}

function orderLines(array $orders): array
{
    if (!$orders) {
        return [];
    }
    $ids = array_column($orders, 'id');
    $params = implode(',', array_fill(0, count($ids), '?'));
    $lines = sql("SELECT id, order_id, name, price, quantity, cancelled FROM order_lines WHERE order_id IN ($params) ORDER BY id", $ids)->fetchAll();
    $byOrder = [];
    foreach ($lines as $line) {
        $byOrder[$line['order_id']][] = $line;
    }
    foreach ($orders as &$order) {
        $order['lines'] = $byOrder[$order['id']] ?? [];
        $order['total'] = array_sum(array_map(
            fn(array $line) => ((int) $line['quantity'] - (int) $line['cancelled']) * (int) $line['price'],
            $order['lines']
        ));
    }
    return $orders;
}

function placeOrder(array $input, string $guest, string $ip): array
{
    $key = strField($input, 'key', 36);
    if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $key)) {
        fail(422, 'Не удалось прочитать номер запроса.');
    }
    $requested = $input['lines'] ?? null;
    if (!is_array($requested) || !array_is_list($requested) || !$requested || count($requested) > 30) {
        fail(422, 'Добавьте от 1 до 30 разных позиций.');
    }
    $lines = [];
    foreach ($requested as $line) {
        if (!is_array($line)) {
            fail(422, 'Проверьте позиции заказа.');
        }
        $id = intField($line, 'id');
        if (isset($lines[$id])) {
            fail(422, 'Повторяющаяся позиция.');
        }
        $lines[$id] = ['quantity' => intField($line, 'quantity', 1, 20), 'price' => intField($line, 'price', 0)];
    }
    ksort($lines);
    $find = fn() => sql('SELECT id FROM orders WHERE guest = ? AND request_key = ?', [$guest, $key])->fetchColumn();
    if ($previous = $find()) {
        return ['id' => (int) $previous];
    }
    limit('order-ip', $ip, 30);
    limit('order-guest', $guest, 12);

    return transaction(function () use ($input, $guest, $key, $lines, $find) {
        $table = tableByToken($input['token'] ?? null, true);
        if ($previous = $find()) {
            return ['id' => (int) $previous];
        }
        if ((int) $table['generation'] !== intField($input, 'generation')) {
            fail(409, 'Столик закрыт. Откройте меню заново по QR.', ['stale' => true]);
        }
        if (!sql('SELECT accepting FROM settings WHERE id = 1 LOCK IN SHARE MODE')->fetchColumn()) {
            fail(409, 'Приём заказов приостановлен. Обратитесь к официанту.');
        }
        $visit = sql('SELECT id FROM visits WHERE active_table = ?', [$table['id']])->fetchColumn();
        if ($visit && (int) sql("SELECT COUNT(*) FROM orders WHERE visit_id = ? AND status = 'pending'", [$visit])->fetchColumn() >= 8) {
            fail(429, 'На столике уже есть заказы, ожидающие подтверждения.');
        }
        $available = [];
        $changes = [];
        foreach ($lines as $id => $line) {
            $variant = sql('SELECT v.price, v.label, d.name, (v.available AND d.available AND d.published AND c.published) AS available
                            FROM variants v JOIN dishes d ON d.id = v.dish_id JOIN categories c ON c.id = d.category_id
                            WHERE v.id = ? LOCK IN SHARE MODE', [$id])->fetch();
            if (!$variant || !(int) $variant['available'] || (int) $variant['price'] !== $line['price']) {
                $changes[] = ['id' => $id, 'available' => (bool) ($variant['available'] ?? false), 'price' => $variant['price'] ?? null];
            }
            $available[$id] = $variant;
        }
        if ($changes) {
            fail(409, 'Меню изменилось. Проверьте корзину перед отправкой.', ['changes' => $changes]);
        }
        if (!$visit) {
            sql('INSERT INTO visits (table_id) VALUES (?)', [$table['id']]);
            $visit = db()->lastInsertId();
        }
        sql('INSERT INTO orders (visit_id, guest, request_key, notify_due) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$visit, $guest, $key]);
        $order = (int) db()->lastInsertId();
        foreach ($lines as $id => $line) {
            $variant = $available[$id];
            $name = $variant['name'] . ($variant['label'] !== '' ? ' · ' . $variant['label'] : '');
            sql('INSERT INTO order_lines (order_id, variant_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
                [$order, $id, $name, $variant['price'], $line['quantity']]);
        }
        return ['id' => $order];
    });
}

function queue(): array
{
    $visits = sql('SELECT v.id, v.opened_at, t.label FROM visits v JOIN dining_tables t ON t.id = v.table_id
                   WHERE v.closed_at IS NULL ORDER BY v.opened_at')->fetchAll();
    $orders = orderLines(sql('SELECT o.id, o.visit_id, o.status, o.version, o.created_at FROM orders o
                              JOIN visits v ON v.id = o.visit_id WHERE v.closed_at IS NULL ORDER BY o.id DESC')->fetchAll());
    $grouped = [];
    foreach ($orders as $order) {
        $grouped[$order['visit_id']][] = $order;
    }
    foreach ($visits as &$visit) {
        $visit['orders'] = $grouped[$visit['id']] ?? [];
    }
    return ['accepting' => accepting(), 'visits' => $visits];
}

function lockActor(array $actor): void
{
    $row = sql('SELECT enabled, role, telegram_id, auth_version FROM staff WHERE id = ? LOCK IN SHARE MODE', [$actor['id']])->fetch();
    if (!$row || !$row['enabled'] || $row['role'] !== $actor['role']
        || (isset($actor['telegram']) && (string) $row['telegram_id'] !== (string) $actor['telegram'])
        || (isset($actor['auth_version']) && (int) $row['auth_version'] !== (int) $actor['auth_version'])) {
        fail(403, 'Доступ сотрудника отключён или изменился.');
    }
}

function closeLockedVisit(array $visit): void
{
    sql('UPDATE visits SET closed_at = UTC_TIMESTAMP() WHERE id = ?', [$visit['id']]);
    sql('UPDATE dining_tables SET generation = generation + 1 WHERE id = ?', [$visit['table_id']]);
    sql('UPDATE orders SET notify_due = UTC_TIMESTAMP() WHERE visit_id = ? AND telegram_message IS NOT NULL', [$visit['id']]);
}

function staffOrderAction(array $actor, string $action, array $input): array
{
    return transaction(function () use ($actor, $action, $input) {
        lockActor($actor);
        if (isset($actor['update_id']) && !sql('INSERT IGNORE INTO telegram_updates (id) VALUES (?)', [$actor['update_id']])->rowCount()) {
            return ['ok' => true, 'replayed' => true];
        }
        if ($action === 'accepting') {
            $enabled = intField($input, 'enabled', 0, 1);
            sql('UPDATE settings SET accepting = ? WHERE id = 1', [$enabled]);
            audit((int) $actor['id'], 'accepting', detail: (string) $enabled);
            return ['ok' => true];
        }

        $orderId = $action === 'close' ? null : intField($input, 'id');
        $visitId = $orderId === null
            ? intField($input, 'id')
            : sql('SELECT visit_id FROM orders WHERE id = ?', [$orderId])->fetchColumn();
        $visit = sql('SELECT id, table_id FROM visits WHERE id = ?', [$visitId])->fetch();
        if (!$visit) {
            fail(404, 'Заказ или столик не найден.');
        }
        sql('SELECT id FROM dining_tables WHERE id = ? FOR UPDATE', [$visit['table_id']]);
        if (sql('SELECT closed_at FROM visits WHERE id = ?', [$visitId])->fetchColumn() !== null) {
            fail(409, 'Столик уже закрыт.');
        }

        if ($action === 'close') {
            $unfinished = sql("SELECT id FROM orders WHERE visit_id = ? AND status IN ('pending', 'cooking') LIMIT 1", [$visitId])->fetchColumn();
            if ($unfinished) {
                fail(409, 'Сначала завершите заказ №' . $unfinished . '.');
            }
            closeLockedVisit($visit);
            audit((int) $actor['id'], 'close', visit: (int) $visitId);
            return ['ok' => true];
        }

        $order = sql('SELECT id, status, version FROM orders WHERE id = ?', [$orderId])->fetch();
        if ((int) $order['version'] !== intField($input, 'version')) {
            fail(409, 'Заказ уже обновлён. Проверьте текущий статус.');
        }
        $status = $order['status'];
        $transitions = [
            'accept' => ['pending', 'cooking'], 'serve' => ['cooking', 'served'],
            'undo_serve' => ['served', 'cooking'], 'reject' => ['pending', 'rejected'],
            'spam' => ['pending', 'rejected'],
        ];
        $detail = null;
        if ($action === 'cancel_line' && $status === 'cooking') {
            $lineId = intField($input, 'line');
            $quantity = intField($input, 'quantity', 1, 20);
            $updated = sql('UPDATE order_lines SET cancelled = cancelled + ? WHERE id = ? AND order_id = ? AND quantity - cancelled >= ?',
                [$quantity, $lineId, $orderId, $quantity])->rowCount();
            if (!$updated) {
                fail(422, 'Проверьте количество отменяемых порций.');
            }
            if (!sql('SELECT id FROM order_lines WHERE order_id = ? AND quantity > cancelled LIMIT 1', [$orderId])->fetchColumn()) {
                $status = 'cancelled';
            }
            $detail = json_encode(['line' => $lineId, 'quantity' => $quantity], JSON_THROW_ON_ERROR);
        } elseif (isset($transitions[$action]) && $status === $transitions[$action][0]) {
            $status = $transitions[$action][1];
        } else {
            fail(409, 'Это действие недоступно для текущего статуса.');
        }
        sql('UPDATE orders SET status = ?, version = version + 1, notify_due = UTC_TIMESTAMP() WHERE id = ?', [$status, $orderId]);
        audit((int) $actor['id'], $action, $orderId, (int) $visitId, $detail);
        if ($action === 'spam' && (int) sql('SELECT COUNT(*) FROM orders WHERE visit_id = ?', [$visitId])->fetchColumn() === 1) {
            closeLockedVisit($visit);
        }
        return ['ok' => true, 'id' => $orderId];
    });
}
