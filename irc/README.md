# Yukiko

Terminal IRC-style client for 6chan's `#general` and `#staffs` chat.

## Build

```bash
cargo build --release
```

## Configure

```bash
yukiko config set-server http://localhost:8080
yukiko config set-name "anon#mypassword"
yukiko config show
```

Resolution order for both server and name: `--server`/`--name` flag on
`start` > config file (`~/.config/yukiko/config.toml`) > built-in default
(`http://localhost:8080`, name `Anon`).

## Run

```bash
yukiko start --channel=#general
yukiko start --channel=#staffs
```

`#staffs` requires the machine you're running `yukiko` from to already
have a valid `admin` PHP session cookie against the target server —
the client doesn't do its own login flow yet, it rides on the browser
session your `admin/panel.php` login created. (Phase 2 problem: give
Yukiko its own auth.)

## Keys

- Type to compose, `Enter` to send
- `Esc` or `Ctrl+C` to quit

## Known scaffold limitations (phase 2 territory)

- No cookie jar wired into `reqwest::Client` yet — see the `#staffs` auth
  note above.
- Polling, not push — 1.5s interval, hardcoded in `main.rs`.
- No scrollback key bindings yet (`app.scroll` exists but nothing sets it).
- No daemon — this is a single foreground client process per channel.
