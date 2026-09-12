mod api;
mod app;
mod cli;
mod color;
mod config;
mod ui;

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

use api::{Api, ChatMessage};
use app::App;
use cli::{Cli, Command, ConfigAction};
use config::Config;

/// Messages flowing from the network task back to the UI loop.
enum NetEvent {
    Messages(Vec<ChatMessage>),
    Status(String),
}

#[tokio::main]
async fn main() -> Result<()> {
    let cli = Cli::parse();
    let cfg = Config::load()?;

    match cli.command {
        Command::Config { action } => run_config(cfg, action),
        Command::Start {
            channel,
            server,
            name,
        } => {
            let server_url = cfg.resolve_server(server);
            let display_name = cfg.resolve_name(name);
            run_client(channel, server_url, display_name).await
        }
    }
}

fn run_config(mut cfg: Config, action: ConfigAction) -> Result<()> {
    match action {
        ConfigAction::Show => {
            println!("config file: {}", Config::path()?.display());
            println!("server: {}", cfg.server.as_deref().unwrap_or("(default: http://localhost:8080)"));
            println!("name:   {}", cfg.name.as_deref().unwrap_or("(default: Anon)"));
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

async fn run_client(channel: String, server_url: String, name: String) -> Result<()> {
    let api = Api::new(server_url.clone());
    let mut app = App::new(channel.clone(), name.clone());

    // net_tx/net_rx: network task -> UI loop (new messages, status updates)
    // out_tx/out_rx: UI loop -> network task (outgoing message bodies)
    let (net_tx, mut net_rx) = mpsc::unbounded_channel::<NetEvent>();
    let (out_tx, out_rx) = mpsc::unbounded_channel::<String>();

    tokio::spawn(network_task(api, channel.clone(), name, net_tx, out_rx));

    // --- terminal setup ---
    enable_raw_mode()?;
    let mut stdout = std::io::stdout();
    execute!(stdout, EnterAlternateScreen, EnableMouseCapture)?;
    let backend = CrosstermBackend::new(stdout);
    let mut terminal = Terminal::new(backend)?;

    let result = event_loop(&mut terminal, &mut app, &mut net_rx, &out_tx).await;

    // --- terminal teardown (always run, even on error) ---
    disable_raw_mode()?;
    execute!(
        terminal.backend_mut(),
        LeaveAlternateScreen,
        DisableMouseCapture
    )?;
    terminal.show_cursor()?;

    result
}

async fn event_loop(
    terminal: &mut Terminal<CrosstermBackend<std::io::Stdout>>,
    app: &mut App,
    net_rx: &mut mpsc::UnboundedReceiver<NetEvent>,
    out_tx: &mpsc::UnboundedSender<String>,
) -> Result<()> {
    loop {
        // Drain any pending network events without blocking the UI.
        while let Ok(evt) = net_rx.try_recv() {
            match evt {
                NetEvent::Messages(msgs) => app.push_messages(msgs),
                NetEvent::Status(s) => app.status = s,
            }
        }

        terminal.draw(|f| ui::draw(f, app))?;

        // Short timeout keeps the UI responsive to incoming messages
        // even when the user isn't typing.
        if event::poll(Duration::from_millis(100))? {
            if let Event::Key(key) = event::read()? {
                if key.kind != KeyEventKind::Press {
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
                    KeyCode::Enter => {
                        if let Some(body) = app.take_input() {
                            let _ = out_tx.send(body);
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
    channel: String,
    name: String,
    net_tx: mpsc::UnboundedSender<NetEvent>,
    mut out_rx: mpsc::UnboundedReceiver<String>,
) {
    let _ = net_tx.send(NetEvent::Status(format!("connected as {name}")));

    let mut since: i64 = 0;
    let mut poll_interval = tokio::time::interval(Duration::from_millis(1500));

    loop {
        tokio::select! {
            _ = poll_interval.tick() => {
                match api.poll(&channel, since).await {
                    Ok(msgs) => {
                        if let Some(last) = msgs.last() {
                            since = since.max(last.id);
                        }
                        if !msgs.is_empty() {
                            let _ = net_tx.send(NetEvent::Messages(msgs));
                        }
                    }
                    Err(e) => {
                        let _ = net_tx.send(NetEvent::Status(format!("poll error: {e}")));
                    }
                }
            }
            Some(body) = out_rx.recv() => {
                if let Err(e) = api.send(&channel, &name, &body).await {
                    let _ = net_tx.send(NetEvent::Status(format!("send failed: {e}")));
                }
                // No optimistic append here — the next poll tick picks up
                // our own message from the server, which keeps the client
                // dumb and the server the single source of truth.
            }
        }
    }
}
