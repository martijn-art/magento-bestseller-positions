#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bestseller category positions for Magento 2
 *
 * Ranks every product by the number of orders it appeared in during a rolling
 * window (orders, not units) and writes that ranking into the category
 * "position" field, so Magento's own "Position" sort shows bestsellers first.
 * Hand-picked products can be pinned above the ranking per category.
 *
 * Run from the Magento root, as the same user that runs Magento's cron:
 *
 *   php bestseller-positions.php                    dry run: shows the result, changes nothing
 *   php bestseller-positions.php --preview=12,34    dry run + the new top of categories 12 and 34
 *   php bestseller-positions.php --apply            write positions, reindex, clean page cache
 *   php bestseller-positions.php --restore=FILE --apply   put back a saved set of positions
 *
 * Other options: --root=DIR  --config=FILE  --days=N  --top=N  --no-reindex  --help
 *
 * Every --apply first saves the existing positions to var/bestseller-positions/.
 * The very first save is kept as positions-original.csv and is never deleted.
 *
 * Requires PHP 7.4+ with pdo_mysql. Reads the database login from app/etc/env.php.
 */

/**
 * Ranks are written 20000 apart (20000, 40000, 60000, ...). Magento shows a
 * product that sits only in a subcategory at "its position there + 10000" in
 * the parent category. With a gap wider than 10000 the parent keeps the same
 * bestseller order whether a product is assigned to it directly or only
 * through a subcategory. Positions 1-9999 stay free for pinned products.
 */
const RANK_STEP = 20000;
const INHERIT_OFFSET = 10000;
const MAX_POSITION = 2000000000;
const MAX_PINS = 9999;

const DEFAULTS = [
    'window_days' => 90,
    'tiebreak_days' => 365,
    'excluded_order_states' => ['canceled', 'closed', 'pending_payment'],
    'out_of_stock_last' => true,
    'exclude_categories' => [],
    'pins' => [],
    'preview_categories' => [],
    'keep_backups' => 14,
];

const OPTIONS = [
    'root' => true, 'config' => true, 'days' => true, 'top' => true,
    'preview' => true, 'restore' => true,
    'apply' => false, 'no-reindex' => false, 'help' => false,
];

try {
    main($argv);
} catch (Throwable $e) {
    fail($e->getMessage());
}

function main(array $argv): void
{
    $opt = parseArgs($argv);
    if (isset($opt['help'])) {
        echo "Usage: php bestseller-positions.php [--apply] [--preview=ID,ID] [--top=N] [--days=N]\n"
            . "           [--restore=FILE] [--root=DIR] [--config=FILE] [--no-reindex]\n"
            . "Without --apply nothing is changed. See the comment at the top of this file.\n";
        return;
    }

    $root = findRoot($opt['root'] ?? null);
    $cfg = loadConfig($opt['config'] ?? null);
    if (isset($opt['days'])) {
        $cfg['window_days'] = (int)$opt['days'];
    }
    if ($cfg['window_days'] < 1) {
        fail('window_days must be at least 1.');
    }
    $cfg['tiebreak_days'] = max((int)$cfg['tiebreak_days'], (int)$cfg['window_days']);
    $apply = isset($opt['apply']);
    $top = max(1, (int)($opt['top'] ?? 25));

    [$db, $prefix] = connect($root);
    $t = static function (string $name) use ($prefix): string {
        return '`' . $prefix . $name . '`';
    };

    $workDir = $root . '/var/bestseller-positions';
    if (!is_dir($workDir) && !mkdir($workDir, 0775, true) && !is_dir($workDir)) {
        fail("Cannot create $workDir");
    }
    $lock = fopen($workDir . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fail('Another run is still in progress.');
    }

    out(($apply ? 'APPLY' : 'DRY RUN') . ' in ' . $root);
    $current = loadAssignments($db, $t);

    if (isset($opt['restore'])) {
        $file = resolveBackup((string)$opt['restore'], $workDir);
        $desired = readBackup($file);
        out('Restoring positions from ' . $file);
    } else {
        $desired = buildDesired($db, $t, $cfg, $current, $opt, $top);
    }

    $changes = [];
    $total = 0;
    foreach ($current as $cat => $rows) {
        foreach ($rows as $pid => $pos) {
            $total++;
            if (isset($desired[$cat][$pid]) && $desired[$cat][$pid] !== $pos) {
                $changes[] = [$cat, $pid, $desired[$cat][$pid]];
            }
        }
    }
    out(sprintf('%d of %d category positions %s.', count($changes), $total, $apply ? 'will change' : 'would change'));

    if (!$apply) {
        out('Dry run: nothing was changed. Add --apply to write these positions.');
        return;
    }
    if (!$changes) {
        out('Positions are already up to date. Nothing to do.');
        return;
    }

    out('Saved the existing positions to ' . writeBackup($workDir, $current, (int)$cfg['keep_backups']));
    out(writePositions($db, $t, $changes) . ' positions written.');

    if (isset($opt['no-reindex'])) {
        out('Skipped reindex (--no-reindex). The shop shows the new order after: '
            . 'bin/magento indexer:reindex catalog_category_product catalogsearch_fulltext');
        return;
    }
    magento($root, 'indexer:reindex catalog_category_product catalogsearch_fulltext');
    magento($root, 'cache:clean block_html full_page');
    out('Done.');
}

/**
 * Rank the catalog and return the positions every category row should get,
 * as [categoryId][productId] => position. Prints the report along the way.
 */
function buildDesired(PDO $db, callable $t, array $cfg, array $current, array $opt, int $top): array
{
    $productIds = [];
    foreach ($current as $rows) {
        $productIds += $rows;
    }
    $productIds = array_keys($productIds);
    if (count($productIds) * RANK_STEP > MAX_POSITION) {
        fail('Too many products for the position field (' . count($productIds) . ').');
    }

    $stats = orderCounts($db, $t, $cfg);
    $stock = $cfg['out_of_stock_last'] ? stockStatus($db, $t) : [];
    $rank = rankProducts($productIds, $stats, $stock);

    $withOrders = 0;
    foreach ($productIds as $pid) {
        $withOrders += empty($stats[$pid]['recent']) ? 0 : 1;
    }
    out(sprintf(
        'Ranked %d products by number of orders; %d were ordered in the last %d days.',
        count($productIds),
        $withOrders,
        $cfg['window_days']
    ));

    $excluded = array_flip(array_map('intval', (array)$cfg['exclude_categories']));
    $desired = $current;
    foreach ($current as $cat => $rows) {
        if (isset($excluded[$cat])) {
            continue;
        }
        foreach ($rows as $pid => $unused) {
            $desired[$cat][$pid] = $rank[$pid] * RANK_STEP;
        }
    }
    $pinned = applyPins($db, $t, (array)$cfg['pins'], $desired, $excluded);

    // Report: overall top, then the new top of each requested category.
    $names = eavValues($db, $t, 'catalog_product', 'catalog_product_entity', 'name', 'varchar');
    $skus = $db->query("SELECT entity_id, sku FROM {$t('catalog_product_entity')}")->fetchAll(PDO::FETCH_KEY_PAIR);
    $line = static function (int $n, int $pid, string $flag) use ($names, $skus, $stats, $stock): string {
        return sprintf(
            '  %3d. %-18s %-48s %3d %s%s%s',
            $n,
            mb_strimwidth((string)($skus[$pid] ?? '#' . $pid), 0, 18, '~'),
            mb_strimwidth((string)($names[$pid] ?? ''), 0, 48, '~'),
            $stats[$pid]['recent'] ?? 0,
            ($stats[$pid]['recent'] ?? 0) === 1 ? 'order ' : 'orders',
            isset($stock[$pid]) && !$stock[$pid] ? '  (out of stock)' : '',
            $flag === '' ? '' : '  [' . $flag . ']'
        );
    };

    // Disabled products and variants that are not shown on their own are left out of the lists.
    $status = eavValues($db, $t, 'catalog_product', 'catalog_product_entity', 'status', 'int');
    $visibility = eavValues($db, $t, 'catalog_product', 'catalog_product_entity', 'visibility', 'int');
    $visible = static function (int $pid) use ($status, $visibility): bool {
        return (int)($status[$pid] ?? 1) === 1 && in_array((int)($visibility[$pid] ?? 4), [2, 4], true);
    };

    $byRank = array_flip($rank);
    ksort($byRank);
    out("Overall top $top:");
    $n = 0;
    foreach ($byRank as $pid) {
        if ($visible($pid)) {
            echo $line(++$n, $pid, ''), "\n";
            if ($n >= $top) {
                break;
            }
        }
    }

    $preview = isset($opt['preview']) ? explode(',', (string)$opt['preview']) : (array)$cfg['preview_categories'];
    $preview = array_filter(array_map('intval', $preview));
    if ($preview) {
        $paths = $db->query("SELECT entity_id, path FROM {$t('catalog_category_entity')}")->fetchAll(PDO::FETCH_KEY_PAIR);
        $catNames = eavValues($db, $t, 'catalog_category', 'catalog_category_entity', 'name', 'varchar');
        $anchor = eavValues($db, $t, 'catalog_category', 'catalog_category_entity', 'is_anchor', 'int');
        foreach ($preview as $cat) {
            if (!isset($paths[$cat])) {
                out("Category $cat does not exist.");
                continue;
            }
            $isAnchor = (int)($anchor[$cat] ?? 1) === 1;
            $list = displayOrder($cat, $desired, $paths, $isAnchor);
            out(sprintf('New top %d of category %d "%s":', $top, $cat, $catNames[$cat] ?? ''));
            $n = 0;
            foreach ($list as $pid => $pos) {
                if (!$visible($pid)) {
                    continue;
                }
                $flag = '';
                if (isset($pinned[$cat][$pid])) {
                    $flag = 'pinned';
                } elseif ($pos < RANK_STEP) {
                    $flag = isset($excluded[$cat]) ? 'manual position' : 'pinned or hand-placed in a subcategory';
                }
                echo $line(++$n, $pid, $flag), "\n";
                if ($n >= $top) {
                    break;
                }
            }
        }
    }

    return $desired;
}

/** Orders per product: [productId => ['recent' => n, 'long' => n]]. */
function orderCounts(PDO $db, callable $t, array $cfg): array
{
    // A configurable or bundle counts on the parent the shopper sees, so child rows are skipped.
    $states = array_values((array)$cfg['excluded_order_states']);
    $sql = "SELECT oi.product_id,
                   COUNT(DISTINCT CASE WHEN o.created_at >= ? THEN o.entity_id END) AS recent,
                   COUNT(DISTINCT o.entity_id) AS longterm
            FROM {$t('sales_order_item')} oi
            JOIN {$t('sales_order')} o ON o.entity_id = oi.order_id
            WHERE oi.parent_item_id IS NULL
              AND oi.product_id IS NOT NULL
              AND o.created_at >= ?";
    if ($states) {
        $sql .= ' AND (o.state IS NULL OR o.state NOT IN (' . implode(',', array_fill(0, count($states), '?')) . '))';
    }
    $sql .= ' GROUP BY oi.product_id';

    // Magento stores order dates in UTC.
    $recent = gmdate('Y-m-d H:i:s', time() - 86400 * (int)$cfg['window_days']);
    $long = gmdate('Y-m-d H:i:s', time() - 86400 * (int)$cfg['tiebreak_days']);
    $st = $db->prepare($sql);
    $st->execute(array_merge([$recent, $long], $states));

    $stats = [];
    foreach ($st as $row) {
        $stats[(int)$row['product_id']] = ['recent' => (int)$row['recent'], 'long' => (int)$row['longterm']];
    }
    return $stats;
}

/** [productId => 1 in stock | 0 out of stock]. Products without a row count as in stock. */
function stockStatus(PDO $db, callable $t): array
{
    try {
        $rows = $db->query(
            "SELECT product_id, MAX(stock_status) FROM {$t('cataloginventory_stock_status')} GROUP BY product_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        out('Warning: could not read stock status, so out-of-stock products are not moved down.');
        return [];
    }
    return array_map('intval', $rows);
}

/**
 * Best first: in stock, then orders in the window, then orders in the longer
 * tie-break window, then newest product. Returns [productId => rank from 1].
 */
function rankProducts(array $productIds, array $stats, array $stock): array
{
    $key = static function (int $pid) use ($stats, $stock): array {
        return [$stock[$pid] ?? 1, $stats[$pid]['recent'] ?? 0, $stats[$pid]['long'] ?? 0, $pid];
    };
    usort($productIds, static function (int $a, int $b) use ($key): int {
        return $key($b) <=> $key($a);
    });
    $rank = [];
    foreach ($productIds as $i => $pid) {
        $rank[$pid] = $i + 1;
    }
    return $rank;
}

/**
 * Put pinned SKUs at positions 1, 2, 3... of their category.
 * Returns [categoryId][productId] => true for the pins that were applied.
 */
function applyPins(PDO $db, callable $t, array $pins, array &$desired, array $excluded): array
{
    $wanted = [];
    foreach ($pins as $skus) {
        foreach ((array)$skus as $sku) {
            $wanted[mb_strtolower((string)$sku)] = (string)$sku;
        }
    }
    if (!$wanted) {
        return [];
    }
    $ids = [];
    foreach (array_chunk(array_values($wanted), 500) as $chunk) {
        $st = $db->prepare(
            "SELECT sku, entity_id FROM {$t('catalog_product_entity')} WHERE sku IN ("
            . implode(',', array_fill(0, count($chunk), '?')) . ')'
        );
        $st->execute($chunk);
        foreach ($st as $row) {
            $ids[mb_strtolower((string)$row['sku'])] = (int)$row['entity_id'];
        }
    }

    $pinned = [];
    foreach ($pins as $cat => $skus) {
        $cat = (int)$cat;
        if (isset($excluded[$cat])) {
            out("Warning: category $cat is in exclude_categories, so its pins are ignored.");
            continue;
        }
        if (count((array)$skus) > MAX_PINS) {
            fail("Category $cat has more than " . MAX_PINS . ' pins.');
        }
        $position = 0;
        foreach ((array)$skus as $sku) {
            $pid = $ids[mb_strtolower((string)$sku)] ?? null;
            if ($pid === null) {
                out("Warning: pin skipped, SKU \"$sku\" does not exist (category $cat).");
            } elseif (!isset($desired[$cat][$pid])) {
                out("Warning: pin skipped, SKU \"$sku\" is not assigned directly to category $cat. "
                    . 'Add it under Catalog > Categories > Products in Category first.');
            } elseif (!isset($pinned[$cat][$pid])) {
                $desired[$cat][$pid] = ++$position;
                $pinned[$cat][$pid] = true;
            }
        }
    }
    return $pinned;
}

/**
 * The order Magento will show for a category: its own products by position,
 * and for an anchor category also the products of its subcategories at
 * (lowest position there + 10000). Returns [productId => position], sorted.
 */
function displayOrder(int $cat, array $positions, array $paths, bool $anchor): array
{
    $list = $positions[$cat] ?? [];
    if ($anchor) {
        $below = $paths[$cat] . '/';
        $inherited = [];
        foreach ($paths as $id => $path) {
            if (strncmp((string)$path, $below, strlen($below)) !== 0) {
                continue;
            }
            foreach ($positions[(int)$id] ?? [] as $pid => $pos) {
                if (!isset($inherited[$pid]) || $pos < $inherited[$pid]) {
                    $inherited[$pid] = $pos;
                }
            }
        }
        foreach ($inherited as $pid => $pos) {
            if (!isset($list[$pid])) {
                $list[$pid] = $pos + INHERIT_OFFSET;
            }
        }
    }
    uksort($list, static function (int $a, int $b) use ($list): int {
        return [$list[$a], $a] <=> [$list[$b], $b];
    });
    return $list;
}

/** Default-scope values of one EAV attribute: [entityId => value]. Empty if the attribute is missing. */
function eavValues(PDO $db, callable $t, string $type, string $entityTable, string $attribute, string $backend): array
{
    $st = $db->prepare(
        "SELECT ea.attribute_id FROM {$t('eav_attribute')} ea
         JOIN {$t('eav_entity_type')} et ON et.entity_type_id = ea.entity_type_id
         WHERE et.entity_type_code = ? AND ea.attribute_code = ?"
    );
    $st->execute([$type, $attribute]);
    $attributeId = $st->fetchColumn();
    if ($attributeId === false) {
        return [];
    }
    $values = $t($entityTable . '_' . $backend);
    // Adobe Commerce links values by row_id, Magento Open Source by entity_id.
    $link = $db->query("SHOW COLUMNS FROM $values LIKE 'row_id'")->fetch() ? 'row_id' : 'entity_id';
    $st = $db->prepare(
        "SELECT e.entity_id, v.value FROM $values v
         JOIN {$t($entityTable)} e ON e.$link = v.$link
         WHERE v.attribute_id = ? AND v.store_id = 0"
    );
    $st->execute([$attributeId]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Current category assignments: [categoryId][productId] => position. */
function loadAssignments(PDO $db, callable $t): array
{
    $current = [];
    foreach ($db->query("SELECT category_id, product_id, position FROM {$t('catalog_category_product')}") as $row) {
        $current[(int)$row['category_id']][(int)$row['product_id']] = (int)$row['position'];
    }
    return $current;
}

/** Write [[categoryId, productId, position], ...] in one statement. Returns the number of rows changed. */
function writePositions(PDO $db, callable $t, array $changes): int
{
    $db->exec('DROP TEMPORARY TABLE IF EXISTS tmp_bestseller_positions');
    $db->exec(
        'CREATE TEMPORARY TABLE tmp_bestseller_positions (
            category_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            position INT NOT NULL,
            PRIMARY KEY (category_id, product_id)
        )'
    );
    foreach (array_chunk($changes, 1000) as $chunk) {
        $values = [];
        foreach ($chunk as [$cat, $pid, $pos]) {
            $values[] = sprintf('(%d,%d,%d)', $cat, $pid, $pos);
        }
        $db->exec('INSERT INTO tmp_bestseller_positions VALUES ' . implode(',', $values));
    }
    $db->beginTransaction();
    try {
        $changed = $db->exec(
            "UPDATE {$t('catalog_category_product')} ccp
             JOIN tmp_bestseller_positions p
               ON p.category_id = ccp.category_id AND p.product_id = ccp.product_id
             SET ccp.position = p.position"
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    $db->exec('DROP TEMPORARY TABLE tmp_bestseller_positions');
    return (int)$changed;
}

function writeBackup(string $dir, array $current, int $keep): string
{
    $file = $dir . '/positions-' . date('Ymd-His') . '.csv';
    $fh = fopen($file, 'w');
    if (!$fh) {
        fail("Cannot write $file");
    }
    fputcsv($fh, ['category_id', 'product_id', 'position'], ',', '"', '\\');
    foreach ($current as $cat => $rows) {
        foreach ($rows as $pid => $pos) {
            fputcsv($fh, [$cat, $pid, $pos], ',', '"', '\\');
        }
    }
    if (!fclose($fh)) {
        fail("Cannot write $file");
    }
    if (!file_exists($dir . '/positions-original.csv')) {
        copy($file, $dir . '/positions-original.csv');
    }
    $dated = glob($dir . '/positions-2*.csv') ?: [];
    sort($dated);
    foreach (array_slice($dated, 0, max(0, count($dated) - max(1, $keep))) as $old) {
        unlink($old);
    }
    return $file;
}

function resolveBackup(string $file, string $workDir): string
{
    foreach ([$file, $workDir . '/' . $file] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    fail("Backup file not found: $file");
}

function readBackup(string $file): array
{
    $fh = fopen($file, 'r');
    if (!$fh || fgetcsv($fh, 0, ',', '"', '\\') !== ['category_id', 'product_id', 'position']) {
        fail("Not a positions backup: $file");
    }
    $positions = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($row) === 3) {
            $positions[(int)$row[0]][(int)$row[1]] = (int)$row[2];
        }
    }
    fclose($fh);
    return $positions;
}

function magento(string $root, string $args): void
{
    out('Running: bin/magento ' . $args);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/magento') . ' ' . $args, $code);
    if ($code !== 0) {
        fail("The positions are saved, but \"bin/magento $args\" failed. Run it by hand to show the new order.", 2);
    }
}

function connect(string $root): array
{
    $env = require $root . '/app/etc/env.php';
    $c = $env['db']['connection']['default'] ?? null;
    if (!is_array($c) || empty($c['dbname'])) {
        fail('No default database connection in app/etc/env.php.');
    }
    $host = (string)($c['host'] ?? 'localhost');
    $dsn = 'mysql:dbname=' . $c['dbname'] . ';charset=utf8mb4';
    if (strpos($host, '/') !== false) {
        $dsn .= ';unix_socket=' . $host;
    } else {
        $port = $c['port'] ?? null;
        if (strpos($host, ':') !== false) {
            [$host, $port] = explode(':', $host, 2);
        }
        $dsn .= ';host=' . $host . ($port ? ';port=' . $port : '');
    }
    $options = (array)($c['driver_options'] ?? []) + [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $db = new PDO($dsn, (string)($c['username'] ?? ''), (string)($c['password'] ?? ''), $options);
    return [$db, (string)($env['db']['table_prefix'] ?? '')];
}

function findRoot(?string $given): string
{
    foreach ($given !== null ? [$given] : [getcwd(), __DIR__, dirname(__DIR__)] as $dir) {
        if ($dir && is_file($dir . '/app/etc/env.php')) {
            return rtrim((string)realpath($dir), '/');
        }
    }
    fail('Magento root not found. Run this from the Magento root or pass --root=/path/to/magento.');
}

function loadConfig(?string $given): array
{
    $file = $given ?? __DIR__ . '/bestseller-positions.config.php';
    if (!is_file($file)) {
        if ($given !== null) {
            fail("Config file not found: $given");
        }
        return DEFAULTS;
    }
    $cfg = require $file;
    if (!is_array($cfg)) {
        fail("$file must return an array.");
    }
    $unknown = array_diff(array_keys($cfg), array_keys(DEFAULTS));
    if ($unknown) {
        fail('Unknown setting(s) in ' . $file . ': ' . implode(', ', $unknown));
    }
    return $cfg + DEFAULTS;
}

function parseArgs(array $argv): array
{
    $opt = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) || !isset(OPTIONS[$m[1]])) {
            fail("Unknown argument: $arg (try --help)");
        }
        $hasValue = isset($m[2]) && $m[2] !== '';
        if (OPTIONS[$m[1]] !== $hasValue) {
            fail(OPTIONS[$m[1]] ? "--{$m[1]} needs a value, e.g. --{$m[1]}=..." : "--{$m[1]} takes no value.");
        }
        $opt[$m[1]] = $hasValue ? $m[2] : true;
    }
    return $opt;
}

function out(string $message): void
{
    echo '[', date('Y-m-d H:i:s'), '] ', $message, "\n";
}

function fail(string $message, int $code = 1): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . "\n");
    exit($code);
}
