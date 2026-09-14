use std::collections::HashMap;

use crate::api::{ChatMessage, PresenceEntry};

const SCROLL_PAGE: usize = 10;

pub struct ChannelState {
    pub messages: Vec<ChatMessage>,
    pub last_id: i64,
    pub scroll: usize,
    pub status: Option<String>,
    pub users: Vec<PresenceEntry>,
}

impl ChannelState {
    fn new() -> Self {
        Self {
            messages: Vec::new(),
            last_id: 0,
            scroll: 0,
            status: None,
            users: Vec::new(),
        }
    }
}

pub struct App {
    pub name: String,
    pub channels: Vec<String>, // tab order
    pub active: usize,         // index into `channels`
    pub state: HashMap<String, ChannelState>,
    pub input: String,
    pub global_status: String, // connection-wide, e.g. "connected as X"
    pub should_quit: bool,
    /// Tripcodes or plain names the user has marked as friends! Purely
    /// local, no server involvement. Matched the same way message colors
    /// are keyed: tripcode if present, else the display name.
    pub friends: Vec<String>,
}

impl App {
    pub fn new(channels: Vec<String>, name: String, friends: Vec<String>) -> Self {
        let mut state = HashMap::new();
        for ch in &channels {
            state.insert(ch.clone(), ChannelState::new());
        }
        Self {
            name,
            channels,
            active: 0,
            state,
            input: String::new(),
            global_status: "connecting...".to_string(),
            should_quit: false,
            friends,
        }
    }

    pub fn active_channel(&self) -> &str {
        &self.channels[self.active]
    }

    pub fn active_state(&self) -> &ChannelState {
        self.state.get(self.active_channel()).expect("active channel always has state")
    }

    pub fn next_channel(&mut self) {
        self.active = (self.active + 1) % self.channels.len();
    }

    pub fn prev_channel(&mut self) {
        self.active = (self.active + self.channels.len() - 1) % self.channels.len();
    }

    pub fn push_messages(&mut self, channel: &str, mut new_msgs: Vec<ChatMessage>) {
        if new_msgs.is_empty() {
            return;
        }
        if let Some(state) = self.state.get_mut(channel) {
            if let Some(last) = new_msgs.last() {
                state.last_id = state.last_id.max(last.id);
            }
            state.messages.append(&mut new_msgs);
            const MAX_HISTORY: usize = 2000;
            if state.messages.len() > MAX_HISTORY {
                let excess = state.messages.len() - MAX_HISTORY;
                state.messages.drain(0..excess);
            }
        }
    }

    pub fn set_channel_status(&mut self, channel: &str, status: Option<String>) {
        if let Some(state) = self.state.get_mut(channel) {
            state.status = status;
        }
    }

    pub fn set_users(&mut self, channel: &str, users: Vec<PresenceEntry>) {
        if let Some(state) = self.state.get_mut(channel) {
            state.users = users;
        }
    }

    /// The key friend-matching and message coloring both use: tripcode
    /// if present, else the plain display name.
    pub fn is_friend(&self, display_name: &str, tripcode: &Option<String>) -> bool {
        let key = tripcode.as_deref().unwrap_or(display_name);
        self.friends.iter().any(|f| f == key)
    }

    /// `/join` — adds a new tab and switches to it. No-ops (just
    /// switches focus) if the channel's already open.
    pub fn add_channel(&mut self, channel: String) {
        if let Some(idx) = self.channels.iter().position(|c| c == &channel) {
            self.active = idx;
            return;
        }
        self.state.insert(channel.clone(), ChannelState::new());
        self.channels.push(channel);
        self.active = self.channels.len() - 1;
    }

    /// `/part` — closes a tab. Refuses to close your last remaining
    /// channel rather than leaving you with an empty tab bar.
    pub fn remove_channel(&mut self, channel: &str) -> Result<(), &'static str> {
        if self.channels.len() <= 1 {
            return Err("can't part your only channel");
        }
        let Some(idx) = self.channels.iter().position(|c| c == channel) else {
            return Err("not in that channel");
        };
        self.channels.remove(idx);
        self.state.remove(channel);
        if self.active >= self.channels.len() {
            self.active = self.channels.len() - 1;
        }
        Ok(())
    }

    pub fn push_system_message(&mut self, channel: &str, body: impl Into<String>) {
        let Some(state) = self.state.get_mut(channel) else { return };
        state.messages.push(ChatMessage {
            id: -1,
            channel: channel.to_string(),
            author: "Yukiko".to_string(),
            tripcode: None,
            is_staff: false,
            body: body.into(),
            created_at: chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ").to_string(),
        });
    }

    pub fn scroll_up(&mut self) {
        let channel = self.active_channel().to_string();
        if let Some(state) = self.state.get_mut(&channel) {
            state.scroll = (state.scroll + SCROLL_PAGE).min(state.messages.len());
        }
    }

    pub fn scroll_down(&mut self) {
        let channel = self.active_channel().to_string();
        if let Some(state) = self.state.get_mut(&channel) {
            state.scroll = state.scroll.saturating_sub(SCROLL_PAGE);
        }
    }

    pub fn scroll_to_bottom(&mut self) {
        let channel = self.active_channel().to_string();
        if let Some(state) = self.state.get_mut(&channel) {
            state.scroll = 0;
        }
    }

    pub fn take_input(&mut self) -> Option<String> {
        let trimmed = self.input.trim();
        if trimmed.is_empty() {
            return None;
        }
        let out = trimmed.to_string();
        self.input.clear();
        Some(out)
    }
}
