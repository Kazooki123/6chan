use clap::{Parser, Subcommand};

#[derive(Parser)]
#[command(name = "yukiko", about = "Terminal IRC client for 6chan")]
pub struct Cli {
    #[command(subcommand)]
    pub command: Command,
}

#[derive(Subcommand)]
pub enum Command {
    Start {
        #[arg(long, default_value = "#general")]
        channel: String,
        #[arg(long)]
        server: Option<String>,
        #[arg(long)]
        name: Option<String>,
    },
    Daemon {
        #[arg(long, default_value = "#general")]
        channel: String,
        #[arg(long)]
        server: Option<String>,
        #[arg(long)]
        name: Option<String>,
        #[arg(long)]
        port: Option<u16>,
    },
    /// Redeems a one-time invite code (from admin/invite.php) for a
    /// permanent staff token, and save it to the config file.
    Redeem {
        code: String,
        #[arg(long)]
        username: String,
        #[arg(long)]
        server: Option<String>,
    },
    Config {
        #[command(subcommand)]
        action: ConfigAction,
    },

    SetBackground {
        path: String,
        #[arg(long)]
        opacity: Option<f32>,
    },
}

#[derive(Subcommand)]
pub enum ConfigAction {
    Show,
    SetServer { url: String },
    SetName { name: String }, // Persist a default display name, e.g. "anon#password"
}
