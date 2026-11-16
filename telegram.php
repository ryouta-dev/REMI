<?php
declare(strict_types=1);
require __DIR__ . '/server/bootstrap.php';
require __DIR__ . '/server/orders.php';
require __DIR__ . '/server/telegram.php';

endpoint(function () {
    $secret = config('telegram_secret');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $secret === ''
        || !hash_equals($secret, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
        fail(403, 'Доступ закрыт.');
    }
    try {
        $update = json_decode(file_get_contents('php://input', false, null, 0, 131072), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail(422, 'Некорректное обновление.');
    }
    if (!is_array($update)) {
        fail(422, 'Некорректное обновление.');
    }
    telegramUpdate($update);
    return ['ok' => true];
});
