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
    Config {
        #[command(subcommand)]
        action: ConfigAction,
    },
}

#[derive(Subcommand)]
pub enum ConfigAction {
    Show,
    SetServer { url: String },
    SetName { name: String }, // Persist a default display name, e.g. "anon#password"
}
