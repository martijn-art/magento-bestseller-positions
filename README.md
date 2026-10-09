# Magento bestseller positions

A nightly script for Magento 2 that puts bestsellers first on category pages.

It ranks every product by the **number of orders** it appeared in (orders, not units sold) and writes that ranking into the category position field. Magento's own "Position" sort then shows bestsellers first. No extension and no front-end changes are needed.

## What it does

- Counts orders per product over the last 90 days. Ten orders of one magnet beat three orders of fifty.
- Breaks ties by orders over the last 365 days, then newest product first.
- Ignores canceled, closed and pending-payment orders.
- Counts a configurable or bundle product once per order, on the parent product.
- Puts out-of-stock products below everything that is in stock.
- Lets you pin hand-picked SKUs to the top of a category.
- Saves the existing positions before every change, so you can always go back.
- Reindexes and cleans the page cache when positions changed.

## Requirements

- Magento 2.4 (Open Source or Adobe Commerce)
- PHP 7.4 or newer with `pdo_mysql`
- Command-line access as the user that runs Magento's cron

## Install

Copy `bestseller-positions.php` and `bestseller-positions.config.php` into the Magento root. The script reads the database login from `app/etc/env.php`.

## Use

Run from the Magento root.

```bash
# Dry run: shows the new order, changes nothing
php bestseller-positions.php

# Dry run, plus the new top of categories 12 and 34
php bestseller-positions.php --preview=12,34

# Write the positions, reindex and clean the page cache
php bestseller-positions.php --apply

# Put back a saved set of positions
php bestseller-positions.php --restore=positions-original.csv --apply
```

Category IDs are shown next to the category name under Catalog > Categories.

Other options: `--days=N` (ranking window), `--top=N` (lines per list), `--no-reindex`, `--root=DIR`, `--config=FILE`, `--help`.

### Run it every night

```cron
15 3 * * * cd /path/to/magento && php bestseller-positions.php --apply >> var/log/bestseller-positions.log 2>&1
```

### Make Position the default sort

The new order only shows when category pages sort by position.

1. Stores > Configuration > Catalog > Catalog > Storefront: set **Product Listing Sort by** to **Position**.
2. Catalog > Categories > a category > Display Settings: tick **Use Config Settings** at **Default Product Listing Sort By**.

## Settings

All settings live in `bestseller-positions.config.php` and are optional.

| Setting | Default | Meaning |
|---|---|---|
| `window_days` | 90 | Rank by orders in the last N days |
| `tiebreak_days` | 365 | Longer period used to break ties |
| `excluded_order_states` | canceled, closed, pending_payment | Orders that do not count |
| `out_of_stock_last` | true | Move out-of-stock products to the bottom |
| `exclude_categories` | none | Category IDs the script leaves alone |
| `pins` | none | SKUs that always come first, per category |
| `preview_categories` | none | Category IDs to preview on every run |
| `keep_backups` | 14 | Number of dated backups to keep |

### Pins

```php
'pins' => [
    12 => ['KLOMP-GEEL-14', 'TULP-BOEKET-9'],   // 12 = category ID
],
```

- A pinned product must be assigned to that category itself, not only to a subcategory. Otherwise the script skips it with a warning.
- A product pinned in a subcategory also rises to the top of its parent categories, unless it is assigned to the parent too.

## How parent categories are ordered

Every product gets the same position in every category it belongs to, based on one shop-wide ranking. A parent category that shows the products of its subcategories therefore lists them all in one bestseller order.

Positions are written 20000 apart. Magento shows a product that sits only in a subcategory at its position there plus 10000, so the wide gap keeps the parent in the same order either way. Positions 1 to 9999 are used for pins.

## Backups and undo

Every `--apply` first saves the existing positions to `var/bestseller-positions/`. The first save is kept as `positions-original.csv` and is never deleted.

## Good to know

- The script overwrites the position of every product in every category, except those in `exclude_categories`.
- Positions are shared by all store views, and orders from all store views count.
- The page cache is cleaned on every run that changes something, so schedule it at a quiet hour.
- A grouped product gets no orders of its own; they count on the products inside it.
- Tested against a copy of the Magento tables on MariaDB 10.11 with PHP 8.3. Start with a dry run on your own shop.

## License

MIT. See [LICENSE](LICENSE).
