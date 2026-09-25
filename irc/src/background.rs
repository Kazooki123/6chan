use std::path::{Path, PathBuf};

use anyhow::{bail, Context, Result};

const MAX_BG_BYTES: u64 = 20 * 1024 * 1024;

pub fn set_background(image_path_raw: &str, opacity: Option<f32>) -> Result<()> {
    let image_path = std::fs::canonicalize(image_path_raw)
        .with_context(|| format!("can't find image at {image_path_raw}"))?;
    let image_path_str = image_path.to_string_lossy().to_string();
    warn_if_large(&image_path);

    if std::env::var("WT_SESSION").is_ok() {
        return set_windows_terminal_background(&image_path_str, opacity);
    }
    if std::env::var("KITTY_WINDOW_ID").is_ok() {
        return set_kitty_background(&image_path_str);
    }
    if std::env::var("WEZTERM_EXECUTABLE").is_ok() {
        println!(
            "WezTerm detected. Its config is Lua, so I won't auto-edit it, add this line to your wezterm.lua yourself:\n\n  \
             config.window_background_image = \"{image_path_str}\"\n"
        );
        return Ok(());
    }
    if std::env::var("KONSOLE_VERSION").is_ok() {
        println!(
            "Konsole detected. Background images live inside your profile's .colorscheme file \
             under $HOME/.local/share/konsole/.\n"
        );
        return Ok(());
    }

    if cfg!(windows) {
        println!(
            "Didn't detect Windows Terminal (no WT_SESSION env var) and no other supported \
             terminal either.. background images aren't supported in the legacy console host \
             (plain cmd.exe/PowerShell window). Try running this from inside Windows Terminal."
        );
    } else {
        let term_program = std::env::var("TERM_PROGRAM").unwrap_or_default();
        let term = std::env::var("TERM").unwrap_or_default();
        println!(
            "Couldn't detect a terminal known to support this (TERM_PROGRAM={term_program:?}, \
             TERM={term:?}). Confirmed working: Windows Terminal, kitty. GNOME Terminal and \
             Alacritty don't support background images at all, at any config depth that's a \
             terminal limitation, not something any app running inside them can work around unfortunantly."
        );
    }
    Ok(())
}

fn warn_if_large(path: &Path) {
    if let Ok(meta) = std::fs::metadata(path) {
        if meta.len() > MAX_BG_BYTES {
            println!(
                "Note: {} is {} MB - some terminals may be slow to load a background that large.",
                path.display(),
                meta.len() / (1024 * 1024)
            );
        }
    }
}

fn windows_terminal_settings_path() -> Option<PathBuf> {
    let local = std::env::var("LOCALAPPDATA").ok()?;
    [
        format!(r"{local}\Packages\Microsoft.WindowsTerminal_8wekyb3d8bbwe\LocalState\settings.json"),
        format!(r"{local}\Packages\Microsoft.WindowsTerminalPreview_8wekyb3d8bbwe\LocalState\settings.json"),
        format!(r"{local}\Microsoft\Windows Terminal\settings.json"),
    ]
    .into_iter()
    .map(PathBuf::from)
    .find(|p| p.exists())
}

fn strip_json_line_comments(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    for line in input.lines() {
        let bytes = line.as_bytes();
        let mut in_string = false;
        let mut escaped = false;
        let mut cut = line.len();
        let mut i = 0;
        while i < bytes.len() {
            let c = bytes[i];
            if in_string {
                if escaped {
                    escaped = false;
                } else if c == b'\\' {
                    escaped = true;
                } else if c == b'"' {
                    in_string = false;
                }
            } else if c == b'"' {
                in_string = true;
            } else if c == b'/' && i + 1 < bytes.len() && bytes[i + 1] == b'/' {
                cut = i;
                break;
            }
            i += 1;
        }
        out.push_str(&line[..cut]);
        out.push('\n');
    }
    out
}

fn set_windows_terminal_background(image_path: &str, opacity: Option<f32>) -> Result<()> {
    let path = windows_terminal_settings_path()
        .context("WT_SESSION is set but couldn't find settings.json in any known location")?;

    let raw = std::fs::read_to_string(&path)
        .with_context(|| format!("reading {}", path.display()))?;
    let stripped = strip_json_line_comments(&raw);
    let mut json: serde_json::Value = serde_json::from_str(&stripped).with_context(|| {
        format!(
            "{} didn't parse as JSON even after stripping // comments — is it valid? \
             (block /* */ comments aren't handled, if you have any of those)",
            path.display()
        )
    })?;

    let profiles = json
        .get_mut("profiles")
        .context("no top-level \"profiles\" key in settings.json — unexpected shape, not editing")?;
    let Some(profiles_obj) = profiles.as_object_mut() else {
        bail!("\"profiles\" isn't an object in this settings.json — unexpected (very old?) shape, not editing");
    };
    let defaults = profiles_obj
        .entry("defaults")
        .or_insert_with(|| serde_json::json!({}));
    let Some(defaults_obj) = defaults.as_object_mut() else {
        bail!("\"profiles.defaults\" isn't an object — not editing");
    };

    defaults_obj.insert("backgroundImage".to_string(), serde_json::Value::String(image_path.to_string()));
    if let Some(op) = opacity {
        defaults_obj.insert("backgroundImageOpacity".to_string(), serde_json::json!(op));
    }

    let backup = path.with_extension("json.bak");
    std::fs::copy(&path, &backup).with_context(|| format!("backing up to {}", backup.display()))?;
    std::fs::write(&path, serde_json::to_string_pretty(&json)?)
        .with_context(|| format!("writing {}", path.display()))?;

    println!(
        "Set backgroundImage in {} (backup saved as {}). Comments in the original file, if any, \
         were not preserved — that's inherent to round-tripping through JSON.",
        path.display(),
        backup.display()
    );
    Ok(())
}

fn set_kitty_background(image_path: &str) -> Result<()> {
    let config_dir = dirs::config_dir().context("couldn't resolve a config directory")?;
    let path = config_dir.join("kitty").join("kitty.conf");
    if !path.exists() {
        bail!("kitty.conf not found at {} — is kitty actually configured?", path.display());
    }

    let raw = std::fs::read_to_string(&path).with_context(|| format!("reading {}", path.display()))?;
    let mut found = false;
    let mut lines: Vec<String> = raw
        .lines()
        .map(|l| {
            if l.trim_start().starts_with("background_image ") {
                found = true;
                format!("background_image {image_path}")
            } else {
                l.to_string()
            }
        })
        .collect();
    if !found {
        lines.push(format!("background_image {image_path}"));
    }

    let backup = path.with_extension("conf.bak");
    std::fs::copy(&path, &backup).with_context(|| format!("backing up to {}", backup.display()))?;
    std::fs::write(&path, lines.join("\n") + "\n").with_context(|| format!("writing {}", path.display()))?;

    println!(
        "Set background_image in {} (backup saved as {}). Reload kitty (ctrl+shift+F5, or \
         restart it) to see it - or if you have remote control enabled, \
         `kitty @ set-background-image {image_path}` applies it instantly without a reload.",
        path.display(),
        backup.display()
    );
    Ok(())
}
