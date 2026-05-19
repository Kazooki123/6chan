# 6chan - The Peak Imageboard

![Logo](6chan.png)

Welcome to **6chan**! Unlike imageboards like **Soyjack.party**, **4chan**, and other imageboards where racist, homophobic, and other discrimination happens, we're making this website as friendly and welcoming as possible!

Read the **/rules/**, don't be an idiot.

> [!IMPORTANT]
> 

## Setup

**Prerequisites:**

> [!NOTE]
> If you want to contribute to the automod system, you might want to have **Python** installed.

- PostgreSQL
- PHP (8.5+)
- Composer (just in case of new updates)

### 0. Creating tables

Please copy the schema code in [schema.ex.sql](schema.ex.sql) and paste it in **pgAdmin4** or your
preferred way to use postgresql. Hit run or execute and it should create the tables and columns.

### 1. Running

> [!WARNING]
> Make sure you have **PDO** and **pdo_pgsql** extension enabled! Check it with `php -m`!!
> Also run `composer install` since it uses composer packages.

```bash
cd public
php -S localhost:8080
```

> [!NOTE]
> If you're encountering errors, drop `NOT NULL` for `img_path`:

```sql
ALTER TABLE threads ALTER COLUMN img_path DROP NOT NULL;
ALTER TABLE posts   ALTER COLUMN img_path DROP NOT NULL;
```

If you're having problems with PHP extensions, go to `php.ini` (OS may vary) and search for:

```ini
;extension='fileinfo'
;extension='openssl'
;extension='pdo_pgsql'
;extension='pgsql'
```

And just remove the **semicolon** (;).

## Contribution

You're always welcome to contribute to 6chan and make it a better place !! ⭐💗

## License

Under the **MIT** License.
