<?php

declare(strict_types=1);

require dirname(__DIR__, 5) . '/wp-load.php';

const VA_TRAINING_ORIGIN_BACKUP = 'veciahorra_training_origin_backup';

$action = $argv[1] ?? 'status';
$current = ['home' => get_option('home'), 'siteurl' => get_option('siteurl')];

if ($action === 'set') {
    $origin = rtrim((string) ($argv[2] ?? ''), '/');
    if (preg_match('#^https://[a-z0-9-]+\.trycloudflare\.com/Minimarket$#D', $origin) !== 1) {
        throw new InvalidArgumentException('El origen temporal no es valido.');
    }
    if (get_option(VA_TRAINING_ORIGIN_BACKUP, null) === null) {
        update_option(VA_TRAINING_ORIGIN_BACKUP, $current, false);
    }
    update_option('home', $origin);
    update_option('siteurl', $origin);
} elseif ($action === 'restore') {
    $backup = get_option(VA_TRAINING_ORIGIN_BACKUP, null);
    if (! is_array($backup) || ! isset($backup['home'], $backup['siteurl'])) {
        throw new RuntimeException('No existe respaldo de origen para restaurar.');
    }
    update_option('home', (string) $backup['home']);
    update_option('siteurl', (string) $backup['siteurl']);
    delete_option(VA_TRAINING_ORIGIN_BACKUP);
} elseif ($action !== 'status') {
    throw new InvalidArgumentException('Accion no valida.');
}

echo wp_json_encode([
    'home' => get_option('home'),
    'siteurl' => get_option('siteurl'),
    'backup_present' => get_option(VA_TRAINING_ORIGIN_BACKUP, null) !== null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
