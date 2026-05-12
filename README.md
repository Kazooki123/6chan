# 6chan - The Peak Imageboard

Welcome to **6chan**! Unlike imageboards like **Soyjack.party** & **4chan** where racist, homophobic, and other
discrimination bullshit happens, we're making this website as friendly and welcoming as possible!

Read the **/rules/**, don't be an idiot.

## Setup

**Prerequisites:**

- PostgreSQL
- PHP (8.5+)
- Composer (just in case of new updates)

0. Creating tables

Please copy the schema code in [schema.ex.sql](schema.ex.sql) and paste it in **pgAdmin4** or your
preferred way to use postgresql. Hit run or execute and it should create the tables and columns.

1. Running

 [!WARNING]
> Make sure you have **PDO** and **pdo_pgsql** extension enabled! Check it with `php -m`!!

```bash
cd public
php -S localhost:8080
```

## Contribution

You're always welcome to contribute to 6chan and make it a better place !! ⭐💗

## License

Under the **MIT** License.
