use crate::api::{ChatMessage, PresenceEntry};

pub enum NetEvent {
    Messages { channel: String, messages: Vec<ChatMessage> },
    Users { channel: String, users: Vec<PresenceEntry> },
    ChannelStatus { channel: String, status: Option<String> },
    Connected(String),
    SystemMessage { channel: String, body: String },
}

pub enum UiCommand {
    Send { channel: String, author: String, body: String },
    Join { channel: String },
}
