# Yukiko 🎀

<img src="banner.png" width="300px" height="200px" alt="Yukiko Banner">

A terminal IRC client for 6chan's `#general` and `#staffs` chat.

> **Yukiko** Amagi is a fictional female character from the hit game Persona 4, she is a sweat and kind character whos a member of the Investigation Team.

[![Built With Ratatui](https://img.shields.io/badge/Built_With_Ratatui-000?logo=ratatui&logoColor=fff)](https://ratatui.rs/)

## Build

Prerequisites:

- rust

```bash
cargo build --release
```

## Configure

Configure files are usually located in `/yukiko/config.toml` in your OS filesystem.

For Windows its in `AppData`, for Linux its in `~/.config/`.

```bash
yukiko config set-server http://localhost:8080
yukiko config set-name "anon#mypassword"
yukiko config show
```

Resolution order for both server and name: `--server`/`--name` flag on
`start` > config file (`~/.config/yukiko/config.toml`) > built-in default
(`http://localhost:8080`, name `Anon`).

## Daemon

Run one background process that polls the server, and let any number of
`yukiko start` windows attach to it instead of each polling separately:

```bash
yukiko daemon --channel=#general,#staffs
```

Then in another terminal (or several):

```bash
yukiko start --channel=#general,#staffs
```

`start` tries `127.0.0.1:7878` first; if a daemon answers, it attaches and
does no HTTP polling of its own. If nothing's listening there, it falls
back to polling directly — same as before the daemon existed. Nothing
about this touches your web server config: the daemon's socket is
loopback-only, unrelated to whatever serves the PHP site on 8080.

Override the port with `--port` on either command, or persist one via
`yukiko config` (not yet exposed as a `set-` subcommand — edit
`daemon_port` in `config.toml` directly for now).

## Run

Once you've installed or build the IRC client, you can run it by:

```bash
yukiko start --channel=#general
yukiko start --channel=#general,#staffs
```

And it should render:

![UI](ui.png)

## Slash commands

- `/nick <name>` - change your display name (persists to config too)
- `/join <#channel>` - open a new tab, starts polling/subscribing to it
- `/part [#channel]` - close a tab (defaults to the active one; refuses to close your last one)
- `/me <action>` - sends `*name action*` as a normal message
- `/friend <name or !tripcode>` - color someone gold in the message pane and user list, persists to config
- `/unfriend <name or !tripcode>` - remove a friend
- `/send <local file path>` - upload a file (5MB max); everyone in the channel sees a 📎 line
- `/getfile <id> [folder]` - download a shared file (id comes from the 📎 line), saved to `./yukiko-downloads` by default
- `/settings` - toggle the settings screen (same as pressing it in the tab bar)
- `/quit` - same as Esc/Ctrl+C
- `/help` - lists all of the above, shown as a local system message (never sent to the server)

Friend matching uses whatever you already see: type the exact tripcode
(with the `!`) to match by tripcode, or the plain name to match by name —
same key the color hashing itself uses.

## Music

```bash
/play <local file path>
/pause  /resume  /stop
/volume <0-200>
```

`/play` renders "🎵 now playing: X" as a normal chat message so that others
know what's on, it does **NOT** try to play it on their computers/devices or
keeps multiple listeners in <span style="color:red;">sync</span>. True synchronized listening (clock
sync, buffer alignment across independently-polling clients) is a real,
hard problem that <span style="color:lime;">Spotify</span>/<span style="color:#6c63ff;">Discord</span> have whole teams for. This deliberately doesn't attempt it. Combine with file sharing: `/getfile <id>` a shared track, and then `/play yukiko-downloads/<filename>`.

## Background Image

```bash
yukiko set-background <path> [--opacity 0.0-1.0]
```

This actually can't paint anything itself.. a TUI can't draw behind its own text
through the terminal protocol, however the terminal emulator can. So this
detects which terminal you're in and edits *its* config the way that
terminal actually supports:

- **Windows Terminal** = edits `settings.json` (`profiles.defaults.backgroundImage`
  / `backgroundImageOpacity`), backs up the original first. Note:
  round-tripping through JSON strips any `//` comments you had.
- **kitty** = edits `~/.config/kitty/kitty.conf` (`background_image` line),
  backs up first. Reload kitty (ctrl+shift+F5) to see it.
- **WezTerm** = config is Lua, so this won't blind edit it; just prints the
  exact line to add yourself.
- **Konsole** = background images live inside whichever `.colorscheme`
  file your profile uses, which isn't reliably detectable from outside;
  prints the manual steps instead of guessing at a file to rewrite.
- **GNOME Terminal, Alacritty, plain cmd.exe/PowerShell console** = <span style="color:red;">NOT</span>
  supported at all, by the terminal itself, not by anything this can
  work around.

## Keys

- Type to compose, `Enter` to send (goes to whichever tab is active)
- `Tab` / `Shift+Tab` - switch channel tabs
- `PageUp` / `PageDown` - scroll back / forward through history
- `End` - jump back to the live bottom of the current channel
- `Esc` or `Ctrl+C` - quit

## License

Under the MIT License.
