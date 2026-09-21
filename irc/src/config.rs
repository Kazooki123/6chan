use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;

const DEFAULT_SERVER: &str = "http://localhost:8080";
pub const DEFAULT_DAEMON_PORT: u16 = 7878;

use crate::theme::ThemeName;

#[derive(Debug, Serialize, Deserialize, Default, Clone)]
pub struct Config {
    pub server: Option<String>,
    pub name: Option<String>,
    pub staff_token: Option<String>,
    pub daemon_port: Option<u16>,
    #[serde(default)]
    pub friends: Vec<String>,
    pub theme: Option<String>,
    pub show_timestamps: Option<bool>,
    pub show_tripcodes: Option<bool>,
}

impl Config {
    /// ~/.config/yukiko/config.toml (respects XDG_CONFIG_HOME via `dirs`)
    pub fn path() -> Result<PathBuf> {
        let mut dir = dirs::config_dir().context("could not resolve a config directory")?;
        dir.push("yukiko");
        Ok(dir.join("config.toml"))
    }

    pub fn load() -> Result<Config> {
        let path = Self::path()?;
        if !path.exists() {
            return Ok(Config::default());
        }
        let raw = fs::read_to_string(&path)
            .with_context(|| format!("reading config at {}", path.display()))?;
        let cfg: Config = toml::from_str(&raw)
            .with_context(|| format!("parsing config at {}", path.display()))?;
        Ok(cfg)
    }

    pub fn save(&self) -> Result<()> {
        let path = Self::path()?;
        if let Some(parent) = path.parent() {
            fs::create_dir_all(parent)
                .with_context(|| format!("creating config dir {}", parent.display()))?;
        }
        let raw = toml::to_string_pretty(self)?;
        fs::write(&path, raw).with_context(|| format!("writing config at {}", path.display()))?;
        Ok(())
    }

    /// Resolution order: CLI flag > config file > built-in default.
    pub fn resolve_server(&self, cli_override: Option<String>) -> String {
        cli_override
            .or_else(|| self.server.clone())
            .unwrap_or_else(|| DEFAULT_SERVER.to_string())
    }

    /// Resolution order: CLI flag > config file > "Anon" (no tripcode).
    pub fn resolve_name(&self, cli_override: Option<String>) -> String {
        cli_override
            .or_else(|| self.name.clone())
            .unwrap_or_else(|| "Anon".to_string())
    }

    /// Resolution order: CLI flag > config file > built-in default (7878).
    pub fn resolve_daemon_port(&self, cli_override: Option<u16>) -> u16 {
        cli_override.or(self.daemon_port).unwrap_or(DEFAULT_DAEMON_PORT)
    }

    pub fn resolve_theme(&self) -> ThemeName {
        self.theme.as_deref().map(ThemeName::from_key).unwrap_or(ThemeName::Default)
    }

    pub fn resolve_show_timestamps(&self) -> bool {
        self.show_timestamps.unwrap_or(true)
    }

    pub fn resolve_show_tripcodes(&self) -> bool {
        self.show_tripcodes.unwrap_or(true)
    }
}
