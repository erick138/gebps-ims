<?php
/** Run after uploading:  php bin/check.php   - checks PHP, folders, .env and the database. */
require __DIR__ . '/../app/bootstrap.php';

use App\Libraries\Db;

$ok = true;
$say = function (bool $pass, string $msg) use (&$ok) { $ok = $ok && $pass; echo ($pass ? '  OK   ' : '  FAIL ') . $msg . "\n"; };

$say(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION . ' (8.1 or newer needed)');
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'openssl'] as $e) {
    $say(extension_loaded($e), "PHP extension $e");
}
$say(is_file(ROOT_PATH . '/.env'), '.env exists');
$say(strlen((string) env('jwt.secret', '')) >= 32, 'JWT_SECRET is set (32+ characters)');
foreach (['storage/logs', 'storage/cache', 'storage/uploads'] as $d) {
    $say(is_dir(ROOT_PATH . "/$d") && is_writable(ROOT_PATH . "/$d"), "$d is writable");
}
try {
    $say(Db::value('SELECT 1') == 1, 'Connected to MySQL ' . Db::value('SELECT VERSION()'));
    foreach (['users', 'equipment', 'issuances', 'maintenance', 'doc_counters'] as $t) {
        $say((bool) Db::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]), "table $t");
    }
    $say((int) Db::value("SELECT COUNT(*) FROM users WHERE username = 'superadmin'") > 0, 'super admin account exists (import 02_seed.sql)');
} catch (Throwable $e) {
    $say(false, 'Database: ' . $e->getMessage());
}
echo $ok ? "\nAll good.\n" : "\nFix the FAIL lines above, then run this again.\n";
exit($ok ? 0 : 1);
