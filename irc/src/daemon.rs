use std::collections::HashMap;
use std::sync::Arc;
use std::time::Duration;

use anyhow::Result;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::{broadcast, RwLock};

use crate::api::{Api, ChatMessage, PresenceEntry};
use crate::protocol::{ClientMsg, DaemonMsg};

struct ChannelBuffer {
    messages: Vec<ChatMessage>,
    last_id: i64,
    users: Vec<PresenceEntry>,
}

impl ChannelBuffer {
    fn new() -> Self {
        Self { messages: Vec::new(), last_id: 0, users: Vec::new() }
    }
}

type SharedState = Arc<RwLock<HashMap<String, ChannelBuffer>>>;

pub async fn run(
    server_url: String,
    name: String,
    staff_token: Option<String>,
    channels: Vec<String>,
    port: u16,
) -> Result<()> {
    let api = Api::new(server_url, staff_token);
    let state: SharedState = Arc::new(RwLock::new(
        channels.iter().map(|c| (c.clone(), ChannelBuffer::new())).collect(),
    ));
    let (tx, _rx) = broadcast::channel::<DaemonMsg>(256);

    spawn_poller(api.clone(), name.clone(), state.clone(), tx.clone());

    let listener = TcpListener::bind(("127.0.0.1", port)).await?;
    println!("Yukiko daemon listening on 127.0.0.1:{port} (loopback only)");
    println!("Polling as {name}. Attach with: yukiko start --channel=...");

    loop {
        let (socket, _addr) = listener.accept().await?;
        tokio::spawn(handle_client(
            socket,
            state.clone(),
            tx.clone(),
            api.clone(),
            name.clone(),
        ));
    }
}

fn spawn_poller(api: Api, name: String, state: SharedState, tx: broadcast::Sender<DaemonMsg>) {
    tokio::spawn(async move {
        let mut poll_interval = tokio::time::interval(Duration::from_millis(1500));
        loop {
            poll_interval.tick().await;
            // Snapshot the channel list fresh each tick, a client's
            // Subscribe (e.g. from /join) can add channels mid-run, and
            // this is how the poller picks them up without a restart.
            let channels: Vec<String> = state.read().await.keys().cloned().collect();
            for channel in &channels {
                let cursor = state.read().await.get(channel).map(|b| b.last_id).unwrap_or(0);
                match api.poll(channel, cursor, &name).await {
                    Ok(result) => {
                        {
                            let mut guard = state.write().await;
                            let buf = guard.entry(channel.clone()).or_insert_with(ChannelBuffer::new);
                            for msg in &result.messages {
                                buf.last_id = buf.last_id.max(msg.id);
                            }
                            buf.messages.extend(result.messages.iter().cloned());
                            buf.users = result.users.clone();
                            const MAX_HISTORY: usize = 2000;
                            if buf.messages.len() > MAX_HISTORY {
                                let excess = buf.messages.len() - MAX_HISTORY;
                                buf.messages.drain(0..excess);
                            }
                        }
                        for msg in result.messages {
                            let _ = tx.send(DaemonMsg::Message { channel: channel.clone(), message: msg });
                        }
                        let _ = tx.send(DaemonMsg::Users { channel: channel.clone(), users: result.users });
                    }
                    Err(e) => {
                        let _ = tx.send(DaemonMsg::Status {
                            channel: channel.clone(),
                            status: Some(format!("poll error: {e}")),
                        });
                    }
                }
            }
        }
    });
}

async fn handle_client(
    socket: TcpStream,
    state: SharedState,
    tx: broadcast::Sender<DaemonMsg>,
    api: Api,
    name: String,
) {
    let (reader, mut writer) = socket.into_split();
    let mut lines = BufReader::new(reader).lines();
    let mut rx = tx.subscribe();

    if send_line(&mut writer, &DaemonMsg::Connected { name: name.clone() }).await.is_err() {
        return;
    }

    loop {
        tokio::select! {
            line = lines.next_line() => {
                let Ok(Some(text)) = line else { break }; // disconnected or read error
                let Ok(msg) = serde_json::from_str::<ClientMsg>(&text) else { continue };
                match msg {
                    ClientMsg::Subscribe { channels } => {
                        for ch in channels {
                            let (messages, users) = {
                                let mut guard = state.write().await;
                                let buf = guard.entry(ch.clone()).or_insert_with(ChannelBuffer::new);
                                (buf.messages.clone(), buf.users.clone())
                            };
                            if send_line(&mut writer, &DaemonMsg::History { channel: ch.clone(), messages }).await.is_err() {
                                return;
                            }
                            if send_line(&mut writer, &DaemonMsg::Users { channel: ch, users }).await.is_err() {
                                return;
                            }
                        }
                    }
                    ClientMsg::Send { channel, author, body } => {
                        if let Err(e) = api.send(&channel, &author, &body).await {
                            let _ = send_line(&mut writer, &DaemonMsg::Error {
                                error: format!("send failed: {e}"),
                            }).await;
                        }
                    }
                }
            }
            evt = rx.recv() => {
                match evt {
                    Ok(msg) => {
                        if send_line(&mut writer, &msg).await.is_err() {
                            break;
                        }
                    }
                    Err(broadcast::error::RecvError::Lagged(_)) => continue,
                    Err(_) => break,
                }
            }
        }
    }
}

async fn send_line(
    writer: &mut (impl AsyncWriteExt + Unpin),
    msg: &DaemonMsg,
) -> Result<()> {
    let mut line = serde_json::to_string(msg)?;
    line.push('\n');
    writer.write_all(line.as_bytes()).await?;
    Ok(())
}
