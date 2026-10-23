<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Repayment Reminder Scheduler

Repayment reminders are evaluated daily at 8:00 AM using the application's configured timezone. Set `APP_TIMEZONE` in the environment (for example, `Asia/Manila`) and run database migrations before enabling the scheduler.

### Production scheduler

Laravel does not install a system scheduler automatically. Configure the server's cron service to invoke Laravel's scheduler every minute; Laravel will run the reminder command at its configured daily time:

```cron
* * * * * cd /path/to/TindahanLedger_web && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/path/to/TindahanLedger_web` with the application's deployment path and ensure the cron user can access PHP, the application, its database, and its configured cache store. For multiple application servers, use a shared cache store to support the scheduler's single-server and overlap locks.

Confirm the registered schedule with `php artisan schedule:list`. The scheduler is not active until the system cron entry is installed.

### Local Windows testing

Set `APP_TIMEZONE=Asia/Manila` in `.env`, then run the scheduler worker in a terminal:

```powershell
php artisan schedule:work
```

To run a reminder check immediately without waiting for 8:00 AM, use:

```powershell
php artisan reminders:send-due
```

The command prints processed, sent, skipped, and failed counts. Failed sends and reminders skipped for a missing or invalid customer email are retained in the reminder history and retried on a later scheduled run when eligible. Successful reminders are not sent again for the same ledger entry, reminder type, and scheduled date. SMTP delivery cannot guarantee exactly-once behavior if the provider accepts a message but the application fails before recording success; a retry may then produce a duplicate.

## External basket dataset validation (DUMS-38)

External market-basket data may later seed *global* fallback product recommendations. Before any of it is used, it is validated with a command that is **validation only**: it never writes to the database, never creates products, customers, purchases, credits, payments, penalties, or Suki Points, and never activates a model.

### Supplying a dataset

1. Place the CSV inside the project, for example `database/transaction.csv`. Do not commit it until its license is known and allows redistribution.
2. Record where it came from: source URL, title, version, license, and download date.

### Running validation

```powershell
php artisan dataset:validate-baskets database/transaction.csv --source-url="https://..." --title="..." --dataset-version="..." --license="..." --downloaded-at=2026-10-23
```

The command prints a summary and saves the full JSON report to `storage/app/private/dataset-validation/<sha256>.json` (use `--no-report` to skip saving). The SHA-256 checksum identifies the exact file version. The command exits with an error when the file cannot be validated or has no acceptable baskets. Missing source or license details are reported as "not provided"; such a dataset cannot be approved for import.

### Supported format

Measured from the supplied file, not assumed: UTF-8, comma-delimited, header exactly `Transaction_ID,itemset`, one basket per row, and `itemset` written as a Python-style list of quoted labels such as `['Milk', 'Bread']`. Any other header is rejected as a file error rather than guessed at.

### Validation rules and duplicate policy

Every exclusion is listed in the report with its line number, transaction ID, and reason. Nothing is dropped silently.

| Situation | Handling |
|---|---|
| Missing or unreadable file, empty file, unexpected header | File error; validation stops |
| Byte order mark | Skipped and reported |
| Row not valid UTF-8 | Row rejected (`invalid_encoding`) |
| Wrong number of fields | Row rejected (`wrong_field_count`) |
| Empty transaction ID | Row rejected (`missing_transaction_id`) |
| Transaction ID already used | Later row rejected (`duplicate_transaction_id`); the first occurrence is kept |
| Itemset is not a list of quoted labels | Row rejected (`malformed_itemset`) |
| Empty list | Row rejected (`empty_basket`) |
| Blank label, or label over 120 characters | Row rejected (`blank_label`, `label_too_long`) |
| Same label twice in one basket | Kept once and reported as a warning (`repeated_label`); a basket is a set of products |
| Blank line | Skipped and reported as a warning |
| Identical baskets under different IDs | Kept, because repeat purchases are real, and counted as `repeated_item_sets` |

Labels are normalized with the product catalog's rule: trim, collapse spaces, lowercase. Each normalized label is reported with the original spellings it came from. Curly apostrophes, as in `Lay’s`, are kept as written.

The report also includes an independence check: the lift of every product pair that appears together in at least 20 baskets. If lift is close to 1 for nearly every pair, products were picked independently and association rules mined from the data would be noise.

### Current dataset: `database/transaction.csv`

| Measure | Result |
|---|---|
| SHA-256 | `ed19771073ebb32240a7bed9fc24cb263f936cd269fe49bc03da953d40bc0707` |
| Size | 168,330 bytes, UTF-8, no byte order mark, LF line endings |
| Baskets | 1,000 rows, all accepted; IDs `t1` to `t1000`, no duplicates |
| Products | 64 distinct labels, 11,477 item occurrences |
| Basket size | 7 to 17 items, median 12 |
| Quantities, prices, dates, cancellations, returns | Not present in the file |
| Lift of the 961 pairs seen in at least 20 baskets | Median 0.99; 90% between 0.81 and 1.21; highest 1.53 |
| Source, license, version, download date | Unknown |

**Suitability decision: development experiments only. Not approved for customer-facing recommendations.**

- The product pairs show no association beyond chance. Pairs that sell together in practice, such as shampoo and conditioner, have lift near 1. The data looks synthetic, with items drawn independently.
- The baskets do not resemble sari-sari store purchases. They hold 7 to 17 items, while sari-sari purchases are typically one to a few items. The products are Indian supermarket brands plus household goods such as curtains, bedsheets, and pillow covers.
- Generic and branded labels overlap, for example "Toothpaste" and "Colgate Toothpaste", so the same need is split across labels.
- The source and license are unknown, so the file must not be redistributed or imported.

### Not yet implemented

Importing external baskets is intentionally not built. Before it is, a dataset must have a known source and license, pass validation, and pass a suitability review. The import must then keep external baskets in their own storage, separate from store purchase history; track the checksum to refuse a second import of the same file; and never replace a working model with a failed or partial run.
