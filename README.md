# 6chan - The Peak Imageboard

![Logo](6chan.png)

Welcome to **6chan**! An imageboard from scratch with the philosophy of **4chan** and a few modern web
concepts that still keeps users anonymous and private!

Read the **/rules**, don't be an idiot.

**Shoutout to @immesolite (Kabusa) on Discord for the logo!**

## Features

- Music display with lyrics embed support
- Reactions
- Polls
- Custom gif background (in settings)
- Custom **tripcodes**
- Markdown support (LaTeX support later)
- Customizable colors and fonts (in settings)
- (NEW!) IRC clients for mods / staffs and average users

> [!IMPORTANT]
> This project is still in it's early development stage, updates and patches are ongoing, thank you!

## Setup

**Prerequisites:**

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
composer install
```

## 4. Starting the server

```bash
cd public
php -S localhost:8080 # Start the server
```

You can now visit [https://localhost:8080](https://localhost:8080) and begin using 6chan!!

## Donating 🩷

If you have a few bucks to throw around, maybe support this project!
Your donations will be used for:

- Domain registration and renewals
- Storage bucket fees
- Hard drives & servers
- Development for features and improvements

and much more!

LTC: `ltc1qq969qhfpxyx3c8twgrdezmt5ec5amxar6c9lck`

ETH: `0x52c38E0097D5cbc8e552E38014805eC4E43DD18e`

SOL: `4CZQYQCvArR6PYm9x4NoW5wDGjVUQKiGcFYBcStNQxUP`

**A huge Huge thanks to `@.zelte` for donating to this project!!**

## Contribution

You're always welcome to contribute to 6chan and make it a better place !! ⭐💗

## License

Under the **AGPL** License.
