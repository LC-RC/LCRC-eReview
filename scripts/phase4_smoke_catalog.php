<?php
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/includes/commerce_catalog.php';
echo 'packages=' . count(commerce_catalog_packages_for_registration($conn)) . PHP_EOL;
echo 'topic_groups=' . count(commerce_catalog_topics_for_registration($conn)) . PHP_EOL;
$v = commerce_validate_package_selection($conn, 99999);
echo 'bad_pkg_ok=' . ($v['ok'] ? '1' : '0') . PHP_EOL;
$t = commerce_validate_topic_selection($conn, [1, 2]);
echo 'topics_validate=' . json_encode($t['ok'] ? ['ok' => true, 'total' => $t['total_centavos'] ?? null] : ['ok' => false, 'error' => $t['error'] ?? '']) . PHP_EOL;
echo 'payments=' . (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) c FROM payments'))['c'] . PHP_EOL;
