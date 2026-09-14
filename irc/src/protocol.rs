use serde::{Deserialize, Serialize};

use crate::api::{ChatMessage, PresenceEntry};

/// Sent by a `yukiko start` client, one JSON object per line.
#[derive(Debug, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ClientMsg {
    Subscribe { channels: Vec<String> },
    Send { channel: String, author: String, body: String },
}

/// Sent by the daemon, one JSON object per line.
#[derive(Debug, Serialize, Deserialize, Clone)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum DaemonMsg {
    Connected { name: String },
    History { channel: String, messages: Vec<ChatMessage> },
    Message { channel: String, message: ChatMessage },
    Users { channel: String, users: Vec<PresenceEntry> },
    Status { channel: String, status: Option<String> },
    Error { error: String },
}
