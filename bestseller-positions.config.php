<?php
/**
 * Settings for bestseller-positions.php. Keep this file next to the script.
 * Every setting is optional; the values below are the defaults.
 */
return [
    // Rank by the number of orders a product appeared in during the last N days.
    'window_days' => 90,

    // Ties (mostly products with no recent orders) are broken by orders in this longer period,
    // then by newest product first.
    'tiebreak_days' => 365,

    // Orders in these states do not count.
    'excluded_order_states' => ['canceled', 'closed', 'pending_payment'],

    // Out-of-stock products go below everything that is in stock.
    'out_of_stock_last' => true,

    // Category IDs the script must leave alone, for categories you arrange by hand.
    // Their products still show in parent categories at their hand-set position + 10000,
    // which is above the ranked products.
    'exclude_categories' => [],

    // Products that always come first in a category, in this order, by SKU.
    // The product must be assigned to that category itself, not only to a subcategory.
    // A product pinned in a subcategory also rises to the top of its parent categories,
    // unless it is assigned to the parent too.
    //
    // 'pins' => [
    //     12 => ['KLOMP-GEEL-14', 'TULP-BOEKET-9'],   // 12 = category ID, shown in Catalog > Categories
    // ],
    'pins' => [],

    // Category IDs whose new top is printed on every run (same as --preview=12,34).
    'preview_categories' => [],

    // How many dated backups of the old positions to keep. The first one ever is always kept.
    'keep_backups' => 14,
];
