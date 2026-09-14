use std::time::Duration;

use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpStream;
use tokio::sync::mpsc;

use crate::events::{NetEvent, UiCommand};
use crate::protocol::{ClientMsg, DaemonMsg};

pub async fn try_connect(port: u16) -> Option<TcpStream> {
    tokio::time::timeout(Duration::from_millis(300), TcpStream::connect(("127.0.0.1", port)))
        .await
        .ok()?
        .ok()
}

pub async fn run(
    stream: TcpStream,
    channels: Vec<String>,
    net_tx: mpsc::UnboundedSender<NetEvent>,
    mut out_rx: mpsc::UnboundedReceiver<UiCommand>,
) {
    let (reader, mut writer) = stream.into_split();
    let mut lines = BufReader::new(reader).lines();

    if send_line(&mut writer, &ClientMsg::Subscribe { channels }).await.is_err() {
        let _ = net_tx.send(NetEvent::Connected("could not reach daemon".to_string()));
        return;
    }

    loop {
        tokio::select! {
            line = lines.next_line() => {
                let Ok(Some(text)) = line else { break };
                let Ok(msg) = serde_json::from_str::<DaemonMsg>(&text) else { continue };
                match msg {
                    DaemonMsg::Connected { name } => {
                        let _ = net_tx.send(NetEvent::Connected(format!("connected as {name} (via daemon)")));
                    }
                    DaemonMsg::History { channel, messages } => {
                        let _ = net_tx.send(NetEvent::Messages { channel, messages });
                    }
                    DaemonMsg::Message { channel, message } => {
                        let _ = net_tx.send(NetEvent::Messages { channel, messages: vec![message] });
                    }
                    DaemonMsg::Users { channel, users } => {
                        let _ = net_tx.send(NetEvent::Users { channel, users });
                    }
                    DaemonMsg::Status { channel, status } => {
                        let _ = net_tx.send(NetEvent::ChannelStatus { channel, status });
                    }
                    DaemonMsg::Error { error } => {
                        let _ = net_tx.send(NetEvent::Connected(format!("daemon error: {error}")));
                    }
                }
            }
            Some(cmd) = out_rx.recv() => {
                let msg = match cmd {
                    UiCommand::Send { channel, author, body } => ClientMsg::Send { channel, author, body },
                    UiCommand::Join { channel } => ClientMsg::Subscribe { channels: vec![channel] },
                };
                if send_line(&mut writer, &msg).await.is_err() {
                    break;
                }
            }
        }
    }
}

async fn send_line(
    writer: &mut (impl AsyncWriteExt + Unpin),
    msg: &ClientMsg,
) -> anyhow::Result<()> {
    let mut line = serde_json::to_string(msg)?;
    line.push('\n');
    writer.write_all(line.as_bytes()).await?;
    Ok(())
}
