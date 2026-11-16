<?php
declare(strict_types=1);
$page = file_get_contents(__DIR__ . '/index.html');
echo str_replace('</head>', '<meta name="remi-catalog" content="api.php?action=catalog"></head>', $page);
