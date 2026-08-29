<?php

/**
 * Write the system settings the CLI installer forgets.
 *
 * Called by ci/compose-evo.sh after `install/cli-install.php`. Two rows the web
 * installer writes have no equivalent on the CLI path: it inserts them at
 * installLevel 4 (install/src/controllers/install.php), and cli-install.php's
 * migrationAndSeed() - which is the whole of its install - never gets there.
 *
 *   manager_theme  without it the manager builds its stylesheet URL as
 *                  'media/style/' . $config['manager_theme'] . '/style.css',
 *                  which becomes media/style//style.css, 404s, and leaves the
 *                  manager with no CSS at all. This is the visible one.
 *   site_id        a per-installation identifier the CMS expects to exist.
 *
 * Only missing rows are written: a value the installer or an operator did set
 * is never overwritten, so this is safe to re-run and safe if the CLI installer
 * is fixed upstream and starts writing them itself.
 *
 * Usage: php seed-missing-settings.php <sqlite-file> [table-prefix]
 */

$db = $argv[1] ?? '';
$prefix = $argv[2] ?? 'evo_';

if ($db === '' || !is_file($db)) {
    fwrite(STDERR, "usage: php seed-missing-settings.php <sqlite-file> [table-prefix]\n");
    exit(2);
}

$defaults = [
    // The only theme a stock tree ships, and the one the web installer picks.
    'manager_theme' => 'default',
    'site_id' => uniqid(''),
];

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = $prefix . 'system_settings';
$read = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE setting_name = ?");
$write = $pdo->prepare("INSERT INTO {$table} (setting_name, setting_value) VALUES (?, ?)");

foreach ($defaults as $name => $value) {
    $read->execute([$name]);
    if ($read->fetchColumn()) {
        printf("%-16s already set\n", $name);
        continue;
    }
    $write->execute([$name, $value]);
    printf("%-16s = %s\n", $name, $value);
}
