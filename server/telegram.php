<?php
declare(strict_types=1);

function telegram(string $method, array $payload): array
{
    $token = config('telegram_token');
    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $token)) {
        throw new RuntimeException('Telegram configuration is missing.');
    }
    $handle = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($handle, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
    ]);
    $body = curl_exec($handle);
    $result = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($result)) {
        throw new RuntimeException('Telegram is unreachable.');
    }
    return $result;
}

function callbackButton(string $text, string $data): array
{
    return ['text' => $text, 'callback_data' => $data];
}

function orderMessage(array $order, ?int $cancelPage = null, ?int $selectedLine = null): array
{
    $id = (int) $order['id'];
    $version = (int) $order['version'];
    $button = fn(string $text, string $action) => callbackButton($text, "$action:$id:$version");
    $rows = match ($order['status']) {
        'pending' => [[$button('Принять', 'accept'), $button('Отклонить', 'reject')], [$button('Спам', 'spam')]],
        'cooking' => [[$button('Подан', 'serve'), $button('Отменить порции', 'choose')]],
        'served' => [[$button('Вернуть в «Готовится»', 'undo_serve')]],
        default => [],
    };
    if ($order['closed_at'] !== null) {
        $rows = [];
    } elseif (!in_array($order['status'], ['pending', 'cooking'], true)) {
        $rows[] = [callbackButton('Закрыть столик', 'close:' . $order['visit_id'] . ':' . $id)];
    }
    if ($selectedLine !== null) {
        $quantities = [];
        foreach ($order['lines'] as $line) {
            if ((int) $line['id'] !== $selectedLine) {
                continue;
            }
            $left = $line['quantity'] - $line['cancelled'];
            for ($quantity = 1; $quantity <= $left; $quantity++) {
                $quantities[] = callbackButton((string) $quantity, "cancel_line:$id:$version:$selectedLine:$quantity");
            }
        }
        $rows = array_chunk($quantities, 5);
        $rows[] = [$button('К позициям', 'choose')];
    } elseif ($cancelPage !== null) {
        $rows = [];
        $active = array_values(array_filter($order['lines'], fn($line) => $line['quantity'] > $line['cancelled']));
        foreach (array_slice($active, $cancelPage * 5, 5) as $line) {
            $rows[] = [callbackButton(mb_substr($line['name'], 0, 55), "select_line:$id:$version:" . $line['id'])];
        }
        $pages = [];
        if ($cancelPage > 0) {
            $pages[] = $button('Назад', 'page_' . ($cancelPage - 1));
        }
        if (($cancelPage + 1) * 5 < count($active)) {
            $pages[] = $button('Дальше', 'page_' . ($cancelPage + 1));
        }
        if ($pages) {
            $rows[] = $pages;
        }
        $rows[] = [$button('К заказу', 'back')];
    }
    $text = "Столик {$order['label']} · Заказ №$id\n" . STATUS[$order['status']];
    if ($order['closed_at'] !== null) {
        $text .= " · Столик закрыт";
    }
    foreach ($order['lines'] as $line) {
        $text .= "\n" . mb_substr($line['name'], 0, 75) . ' × ' . ($line['quantity'] - $line['cancelled']);
        if ($line['cancelled']) {
            $text .= ' (отменено: ' . $line['cancelled'] . ')';
        }
    }
    $text .= "\nИтого: " . $order['total'] . ' ₸';
    if ($selectedLine !== null) {
        $text .= "\nСколько порций отменить?";
    }
    return ['text' => $text, 'reply_markup' => ['inline_keyboard' => $rows]];
}

function telegramOrder(int $id): ?array
{
    $orders = orderLines(sql('SELECT o.*, t.label, v.closed_at FROM orders o JOIN visits v ON v.id=o.visit_id
                              JOIN dining_tables t ON t.id=v.table_id WHERE o.id=?', [$id])->fetchAll());
    return $orders[0] ?? null;
}

function flushTelegram(int $limit = 10, ?callable $send = null): void
{
    $send ??= telegram(...);
    if (!config('telegram_token') || !config('telegram_chat')) {
        return;
    }
    if (!(int) sql("SELECT GET_LOCK(CONCAT(DATABASE(), ':remi_notify'), 0)")->fetchColumn()) {
        return;
    }
    try {
        $ids = sql('SELECT id FROM orders WHERE notify_due <= UTC_TIMESTAMP() ORDER BY notify_due, id LIMIT ' . $limit)->fetchAll();
        foreach ($ids as $row) {
            $order = telegramOrder((int) $row['id']);
            $id = (int) $order['id'];
            $message = $order['telegram_message'];
            if (!$message && $order['status'] !== 'pending') {
                sql('UPDATE orders SET notify_due=NULL WHERE id=? AND version=?', [$id, $order['version']]);
                continue;
            }
            $payload = ['chat_id' => config('telegram_chat')] + orderMessage($order);
            try {
                $result = $message
                    ? $send('editMessageText', $payload + ['message_id' => $message])
                    : $send('sendMessage', $payload);
                $description = $result['description'] ?? '';
                if (!$result['ok'] && $message && str_contains($description, 'message to edit not found')) {
                    sql('UPDATE orders SET telegram_message=NULL, notify_due=UTC_TIMESTAMP() WHERE id=? AND telegram_message=?', [$id, $message]);
                    continue;
                }
                if (!$result['ok'] && !($message && str_contains($description, 'message is not modified'))) {
                    $delay = max(30, min(3600, (int) ($result['parameters']['retry_after'] ?? 60)));
                    sql("UPDATE orders SET notify_due=UTC_TIMESTAMP()+INTERVAL $delay SECOND WHERE id=? AND version=?", [$id, $order['version']]);
                    continue;
                }
                $delivered = $message ?: ($result['result']['message_id'] ?? null);
                sql('UPDATE orders SET telegram_message=?, notified_at=UTC_TIMESTAMP(),
                     notify_due=IF(version=? AND (SELECT closed_at FROM visits WHERE id=orders.visit_id) <=> ?, NULL, UTC_TIMESTAMP()) WHERE id=?',
                    [$delivered, $order['version'], $order['closed_at'], $id]);
            } catch (RuntimeException) {
                sql('UPDATE orders SET notify_due=UTC_TIMESTAMP()+INTERVAL 1 MINUTE WHERE id=? AND version=?', [$id, $order['version']]);
                break;
            }
        }
    } finally {
        sql("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':remi_notify'))");
    }
}

function telegramActor(mixed $telegramId): ?array
{
    return sql('SELECT id, role, telegram_id AS telegram FROM staff WHERE enabled AND telegram_id=?', [$telegramId])->fetch() ?: null;
}

function notifyAfterResponse(): void
{
    if (function_exists('fastcgi_finish_request') && config('telegram_token') && config('telegram_chat')) {
        register_shutdown_function(function () {
            fastcgi_finish_request();
            try {
                flushTelegram(3);
            } catch (Throwable $error) {
                error_log('REMI: Telegram queue deferred to scheduler.');
            }
        });
    }
}

function telegramUpdate(array $update, ?callable $send = null): void
{
    $send ??= telegram(...);
    $message = $update['message'] ?? null;
    $callback = $update['callback_query'] ?? null;
    $configuredChat = config('telegram_chat');
    if ($message && ($message['chat']['type'] ?? '') === 'private' &&
        preg_match('/^\/start ([a-f0-9]{48})$/D', $message['text'] ?? '', $matches)) {
        $text = 'Ссылка больше не действует. Попросите администратора выдать новую.';
        try {
            $linked = sql('UPDATE staff SET telegram_id=?, link_hash=NULL, link_expires=NULL
                           WHERE enabled AND link_hash=? AND link_expires > UTC_TIMESTAMP()',
                [$message['from']['id'], hash('sha256', $matches[1])])->rowCount();
            if ($linked) {
                $text = 'Telegram привязан. Работайте с заказами в чате REMI.';
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            $text = 'Этот аккаунт уже привязан к другому сотруднику.';
        }
        $send('sendMessage', ['chat_id' => $message['chat']['id'], 'text' => $text]);
        return;
    }
    if ($message && $configuredChat !== '' && (string) ($message['chat']['id'] ?? '') === $configuredChat
        && preg_match('/^\/(remi|orders|order)(?:@[A-Za-z0-9_]+)?(?: (\d{1,10}))?$/D', $message['text'] ?? '', $command)
        && telegramActor($message['from']['id'] ?? null)) {
        if ($command[1] === 'remi') {
            $enabled = accepting();
            $payload = [
                'text' => $enabled ? 'Приём QR-заказов включён.' : 'Приём QR-заказов выключен.',
                'reply_markup' => ['inline_keyboard' => [[callbackButton($enabled ? 'Остановить приём' : 'Включить приём', $enabled ? 'accepting:0' : 'accepting:1')]]],
            ];
        } elseif ($command[1] === 'order') {
            $order = telegramOrder((int) ($command[2] ?? 0));
            $payload = $order ? orderMessage($order) : ['text' => 'Укажите номер заказа: /order 123'];
        } else {
            $recent = sql("SELECT o.id, o.status, t.label FROM orders o JOIN visits v ON v.id=o.visit_id
                           JOIN dining_tables t ON t.id=v.table_id WHERE v.closed_at IS NULL
                           ORDER BY (o.status = 'pending') DESC, o.id DESC LIMIT 25")->fetchAll();
            $rows = array_map(fn($row) => "№{$row['id']} · {$row['label']} · " . STATUS[$row['status']], $recent);
            $payload = ['text' => $rows ? "Последние заказы открытых столиков\n" . implode("\n", $rows)
                . "\n\nОткрыть заказ: /order 123" : 'Открытых столиков нет.'];
        }
        $send('sendMessage', ['chat_id' => $configuredChat] + $payload);
        return;
    }
    if (!$callback) {
        return;
    }
    $answer = 'Доступно только сотрудникам REMI.';
    $actor = $configuredChat !== '' && (string) ($callback['message']['chat']['id'] ?? '') === $configuredChat
        ? telegramActor($callback['from']['id'] ?? null) : null;
    if ($actor) {
        try {
            [$action, $id, $version, $line, $quantity] = array_pad(explode(':', $callback['data'] ?? ''), 5, null);
            if (in_array($action, ['choose', 'back', 'select_line'], true) || preg_match('/^page_[0-5]$/D', $action)) {
                $order = telegramOrder((int) $id);
                if (!$order || $order['closed_at'] !== null || $order['status'] !== 'cooking' || (int) $order['version'] !== (int) $version) {
                    fail(409, 'Заказ уже изменился.');
                }
                $page = $action === 'back' ? null : (str_starts_with($action, 'page_') ? (int) substr($action, 5) : 0);
                $selectedLine = $action === 'select_line' ? (int) $line : null;
                $send('editMessageText', ['chat_id' => $configuredChat, 'message_id' => $callback['message']['message_id']]
                    + orderMessage($order, $page, $selectedLine));
            } else {
                staffOrderAction($actor + ['update_id' => $update['update_id'] ?? null], $action,
                    ['id' => $id, 'version' => $version, 'line' => $line, 'quantity' => $quantity, 'enabled' => $id]);
                notifyAfterResponse();
                if ($action === 'accepting') {
                    $enabled = accepting();
                    $send('editMessageText', [
                        'chat_id' => $configuredChat, 'message_id' => $callback['message']['message_id'],
                        'text' => $enabled ? 'Приём QR-заказов включён.' : 'Приём QR-заказов выключен.',
                        'reply_markup' => ['inline_keyboard' => [[callbackButton($enabled ? 'Остановить приём' : 'Включить приём', $enabled ? 'accepting:0' : 'accepting:1')]]],
                    ]);
                } else {
                    $order = telegramOrder((int) ($action === 'close' ? $version : $id));
                    $messageId = $callback['message']['message_id'];
                    if ($order && (int) $order['telegram_message'] !== (int) $messageId) {
                        $send('editMessageText', ['chat_id' => $configuredChat, 'message_id' => $messageId] + orderMessage($order));
                    }
                }
            }
            $answer = 'Готово.';
        } catch (RequestError $error) {
            $answer = $error->getMessage();
        }
    }
    $send('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => $answer]);
}
