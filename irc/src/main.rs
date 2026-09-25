mod api;
mod app;
mod background;
mod banner;
mod cli;
mod color;
mod commands;
mod config;
mod daemon;
mod daemon_client;
mod events;
mod player;
mod protocol;
mod theme;
mod ui;

use std::collections::HashMap;
use std::time::Duration;

use anyhow::Result;
use clap::Parser;
use crossterm::{
    event::{self, DisableMouseCapture, EnableMouseCapture, Event, KeyCode, KeyEventKind},
    execute,
    terminal::{disable_raw_mode, enable_raw_mode, EnterAlternateScreen, LeaveAlternateScreen},
};
use ratatui::{backend::CrosstermBackend, Terminal};
use tokio::sync::mpsc;

use api::Api;
use app::{App, AppInit};
use cli::{Cli, Command, ConfigAction};
use commands::AsyncJob;
use config::Config;
use player::Player;
use events::{NetEvent, UiCommand};

fn parse_channels(raw: &str) -> Vec<String> {
    raw.split(',')
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
        .collect()
}

#[tokio::main]
async fn main() -> Result<()> {
    let cli = Cli::parse();
    let cfg = Config::load()?;

    match cli.command {
        Command::Config { action } => run_config(cfg, action),
        Command::SetBackground { path, opacity } => background::set_background(&path, opacity),
        Command::Redeem {
            code,
            username,
            server,
        } => run_redeem(cfg, code, username, server).await,
        Command::Daemon {
            channel,
            server,
            name,
            port,
        } => {
            let server_url = cfg.resolve_server(server);
            let display_name = cfg.resolve_name(name);
            let daemon_port = cfg.resolve_daemon_port(port);
            let channels = parse_channels(&channel);
            daemon::run(server_url, display_name, cfg.staff_token, channels, daemon_port).await
        }
        Command::Start {
            channel,
            server,
            name,
        } => {
            let server_url = cfg.resolve_server(server);
            let display_name = cfg.resolve_name(name);
            let daemon_port = cfg.resolve_daemon_port(None);
            let friends = cfg.friends.clone();
            let theme_name = cfg.resolve_theme();
            let show_timestamps = cfg.resolve_show_timestamps();
            let show_tripcodes = cfg.resolve_show_tripcodes();
            let channels = parse_channels(&channel);
            run_client(
                channels,
                server_url,
                display_name,
                cfg.staff_token,
                daemon_port,
                friends,
                theme_name,
                show_timestamps,
                show_tripcodes,
            )
            .await
        }
    }
}

async fn run_redeem(
    mut cfg: Config,
    code: String,
    username: String,
    server_override: Option<String>,
) -> Result<()> {
    let server_url = cfg.resolve_server(server_override);
    let api = Api::new(server_url, None);

    let token = api.redeem_invite(&code, &username).await?;
    cfg.staff_token = Some(token);
    cfg.name = Some(username.clone());
    cfg.save()?;

    println!("Redeemed! Token saved to {}", Config::path()?.display());
    println!("You're now set up as staff — try: yukiko start --channel=#general,#staffs");
    Ok(())
}

fn run_config(mut cfg: Config, action: ConfigAction) -> Result<()> {
    match action {
        ConfigAction::Show => {
            println!("config file: {}", Config::path()?.display());
            println!("server: {}", cfg.server.as_deref().unwrap_or("(default: http://localhost:8080)"));
            println!("name:   {}", cfg.name.as_deref().unwrap_or("(default: Anon)"));
            println!(
                "staff:  {}",
                if cfg.staff_token.is_some() { "token set" } else { "(none — see `yukiko redeem`)" }
            );
            println!(
                "daemon port: {}",
                cfg.daemon_port.map(|p| p.to_string()).unwrap_or_else(|| "(default: 7878)".to_string())
            );
            println!("theme: {}", cfg.resolve_theme().label());
        }
        ConfigAction::SetServer { url } => {
            cfg.server = Some(url.clone());
            cfg.save()?;
            println!("server set to {url}");
        }
        ConfigAction::SetName { name } => {
            cfg.name = Some(name.clone());
            cfg.save()?;
            println!("name set to {name}");
        }
    }
    Ok(())
}

#[allow(clippy::too_many_arguments)]
async fn run_client(
    channels: Vec<String>,
    server_url: String,
    name: String,
    staff_token: Option<String>,
    daemon_port: u16,
    friends: Vec<String>,
    theme_name: theme::ThemeName,
    show_timestamps: bool,
    show_tripcodes: bool,
) -> Result<()> {
    if channels.is_empty() {
        anyhow::bail!("no channels given");
    }

    let (net_tx, mut net_rx) = mpsc::unbounded_channel::<NetEvent>();
    let (out_tx, out_rx) = mpsc::unbounded_channel::<UiCommand>();
    let mut app = App::new(AppInit {
        channels: channels.clone(),
        name: name.clone(),
        friends,
        theme_name,
        show_timestamps,
        show_tripcodes,
        server_url: server_url.clone(),
    });

    let mut player = match Player::new() {
        Ok(p) => Some(p),
        Err(e) => {
            let first = app.active_channel().to_string();
            app.push_system_message(&first, format!("No audio output available ({e}) — /play etc. disabled"));
            None
        }
    };

    let action_api = Api::new(server_url.clone(), staff_token.clone());
    let job_net_tx = net_tx.clone();

    if let Some(stream) = daemon_client::try_connect(daemon_port).await {
        tokio::spawn(daemon_client::run(stream, channels.clone(), net_tx, out_rx));
    } else {
        let api = Api::new(server_url.clone(), staff_token);
        tokio::spawn(network_task(api, channels.clone(), name.clone(), net_tx, out_rx));
    }

    enable_raw_mode()?;
    let mut stdout = std::io::stdout();
    execute!(stdout, EnterAlternateScreen, EnableMouseCapture)?;
    let backend = CrosstermBackend::new(stdout);
    let mut terminal = Terminal::new(backend)?;

    let result = event_loop(&mut terminal, &mut app, &mut net_rx, &out_tx, &action_api, job_net_tx, &mut player).await;

    disable_raw_mode()?;
    execute!(
        terminal.backend_mut(),
        LeaveAlternateScreen,
        DisableMouseCapture
    )?;
    terminal.show_cursor()?;

    result
}

fn spawn_async_job(job: AsyncJob, api: Api, net_tx: mpsc::UnboundedSender<NetEvent>) {
    tokio::spawn(async move {
        match job {
            AsyncJob::Upload { channel, author, path } => {
                let body = match api.upload_file(&channel, &author, &path).await {
                    Ok(filename) => format!("Uploaded {filename}"),
                    Err(e) => format!("Upload failed: {e}"),
                };
                let _ = net_tx.send(NetEvent::SystemMessage { channel, body });
            }
            AsyncJob::Download { file_id, dest_dir, feedback_channel } => {
                let body = match api.download_file(&file_id, &dest_dir).await {
                    Ok(path) => format!("Saved to {}", path.display()),
                    Err(e) => format!("Download failed: {e}"),
                };
                let _ = net_tx.send(NetEvent::SystemMessage { channel: feedback_channel, body });
            }
        }
    });
}

#[allow(clippy::too_many_arguments)]
async fn event_loop(
    terminal: &mut Terminal<CrosstermBackend<std::io::Stdout>>,
    app: &mut App,
    net_rx: &mut mpsc::UnboundedReceiver<NetEvent>,
    out_tx: &mpsc::UnboundedSender<UiCommand>,
    action_api: &Api,
    job_net_tx: mpsc::UnboundedSender<NetEvent>,
    player: &mut Option<Player>, // Music Player
) -> Result<()> {
    loop {
        while let Ok(evt) = net_rx.try_recv() {
            match evt {
                NetEvent::Messages { channel, messages } => app.push_messages(&channel, messages),
                NetEvent::Users { channel, users } => app.set_users(&channel, users),
                NetEvent::ChannelStatus { channel, status } => app.set_channel_status(&channel, status),
                NetEvent::Connected(s) => app.global_status = s,
                NetEvent::SystemMessage { channel, body } => app.push_system_message(&channel, body),
            }
        }

        terminal.draw(|f| ui::draw(f, app))?;

        if event::poll(Duration::from_millis(100))? {
            if let Event::Key(key) = event::read()? {
                if key.kind != KeyEventKind::Press {
                    continue;
                }

                if app.settings_open {
                    match key.code {
                        KeyCode::Esc => app.settings_open = false,
                        KeyCode::Char('c') if key.modifiers.contains(event::KeyModifiers::CONTROL) => {
                            app.should_quit = true;
                        }
                        KeyCode::Up => app.settings_move(-1),
                        KeyCode::Down => app.settings_move(1),
                        KeyCode::Left => app.settings_cycle(false),
                        KeyCode::Right | KeyCode::Enter => app.settings_cycle(true),
                        _ => {}
                    }
                    if app.should_quit {
                        break;
                    }
                    continue;
                }

                match key.code {
                    KeyCode::Esc => {
                        app.should_quit = true;
                    }
                    KeyCode::Char('c')
                        if key.modifiers.contains(event::KeyModifiers::CONTROL) =>
                    {
                        app.should_quit = true;
                    }
                    KeyCode::Tab => app.next_channel(),
                    KeyCode::BackTab => app.prev_channel(),
                    KeyCode::PageUp => app.scroll_up(),
                    KeyCode::PageDown => app.scroll_down(),
                    KeyCode::End => app.scroll_to_bottom(),
                    KeyCode::Enter => {
                        if let Some(text) = app.take_input() {
                            if let Some(rest) = text.strip_prefix('/') {
                                if let Some(job) = commands::dispatch(app, out_tx, player, rest) {
                                    spawn_async_job(job, action_api.clone(), job_net_tx.clone());
                                }
                            } else {
                                let channel = app.active_channel().to_string();
                                let author = app.name.clone();
                                let _ = out_tx.send(UiCommand::Send { channel, author, body: text });
                            }
                        }
                    }
                    KeyCode::Backspace => {
                        app.input.pop();
                    }
                    KeyCode::Char(c) => {
                        app.input.push(c);
                    }
                    _ => {}
                }
            }
        }

        if app.should_quit {
            break;
        }
    }
    Ok(())
}

async fn network_task(
    api: Api,
    channels: Vec<String>,
    name: String,
    net_tx: mpsc::UnboundedSender<NetEvent>,
    mut out_rx: mpsc::UnboundedReceiver<UiCommand>,
) {
    let _ = net_tx.send(NetEvent::Connected(format!("connected as {name} (direct)")));

    let mut channels = channels;
    let mut current_name = name;
    let mut since: HashMap<String, i64> = channels.iter().map(|c| (c.clone(), 0)).collect();
    let mut poll_interval = tokio::time::interval(Duration::from_millis(1500));

    loop {
        tokio::select! {
            _ = poll_interval.tick() => {
                for channel in &channels {
                    let cursor = *since.get(channel).unwrap_or(&0);
                    match api.poll(channel, cursor, &current_name).await {
                        Ok(result) => {
                            let _ = net_tx.send(NetEvent::ChannelStatus {
                                channel: channel.clone(),
                                status: None,
                            });
                            if let Some(last) = result.messages.last() {
                                since.insert(channel.clone(), last.id);
                            }
                            if !result.messages.is_empty() {
                                let _ = net_tx.send(NetEvent::Messages {
                                    channel: channel.clone(),
                                    messages: result.messages,
                                });
                            }
                            let _ = net_tx.send(NetEvent::Users {
                                channel: channel.clone(),
                                users: result.users,
                            });
                        }
                        Err(e) => {
                            let _ = net_tx.send(NetEvent::ChannelStatus {
                                channel: channel.clone(),
                                status: Some(format!("poll error: {e}")),
                            });
                        }
                    }
                }
            }
            Some(cmd) = out_rx.recv() => {
                match cmd {
                    UiCommand::Send { channel, author, body } => {
                        current_name = author.clone();
                        if let Err(e) = api.send(&channel, &author, &body).await {
                            let _ = net_tx.send(NetEvent::ChannelStatus {
                                channel,
                                status: Some(format!("send failed: {e}")),
                            });
                        }
                    }
                    UiCommand::Join { channel } => {
                        if !channels.contains(&channel) {
                            channels.push(channel.clone());
                            since.insert(channel, 0);
                        }
                    }
                }
            }
        }
    }
}
