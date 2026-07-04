# 6chan - The Peak Imageboard

![Logo](6chan.png)

Welcome to **6chan**! Unlike imageboards like **Soyjack.party**, **4chan**, and other imageboards where racist, homophobic, and other discrimination happens, we're making this website as friendly and welcoming as possible!

Read the **/rules**, don't be an idiot.

## Features

- Music display with lyrics embed support
- Reactions
- Polls
- Custom gif background (in settings)
- Custom **tripcodes**
- Markdown support (LaTeX support later)
- Customizable colors and fonts (in settings)

> [!IMPORTANT]
> This project is still in it's early development stage, updates and patches are ongoing, thank you!

## Setup

**Prerequisites:**

> [!NOTE]
> If you want to contribute to the automod system, you might want to have **Python** installed.

- PostgreSQL18 **OR** Podman
- PHP (8.5+)
- Composer

## 1. Database setup

### WITH PODMAN

Run the command below to create a containerised database with the schema already applied:

```bash
podman compose up -d
```

### WITHOUT PODMAN

Run the commands below to create the database and apply the schema:

```bash
createdb -U postgres 6chan
psql -U postgres -d 6chan < schema.sql
```

## 2. Environment variables

Rename [.env.example](.env.example) to `.env` and change the `DB_PASS` variable to a secure password

## 3. Installing packages/extensions

Ensure that the `PDO` and `pdo_pgsql` extensions are active in your PHP environment. You can quickly verify this by running:

```bash
php -m
```

If these extensions are missing from the terminal output, open your `php.ini` configuration file, look for the lines matching these extension names, and uncomment them by removing the leading semicolon (;).

Install the required packages:

```bash
compose install
```

## 4. Starting the server

```bash
cd public
php -S localhost:8080 # Start the server
```

You can now visit [https://localhost:8080](https://localhost:8080) and begin using 6chan!!

## Contribution

You're always welcome to contribute to 6chan and make it a better place !! ⭐💗

## License

Under the **AGPL** License.
