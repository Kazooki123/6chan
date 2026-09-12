use crate::api::ChatMessage;

pub struct App {
    pub channel: String,
    pub name: String, // raw "name#password" or plain display name, sent as is.
    pub messages: Vec<ChatMessage>,
    pub last_id: i64,
    pub input: String,
    pub scroll: usize,
    pub status: String,
    pub should_quit: bool,
}

impl App {
    pub fn new(channel: String, name: String) -> Self {
        Self {
            channel,
            name,
            messages: Vec::new(),
            last_id: 0,
            input: String::new(),
            scroll: 0,
            status: "connecting...".to_string(),
            should_quit: false,
        }
    }

    pub fn push_messages(&mut self, mut new_msgs: Vec<ChatMessage>) {
        if new_msgs.is_empty() {
            return;
        }
        if let Some(last) = new_msgs.last() {
            self.last_id = self.last_id.max(last.id);
        }
        self.messages.append(&mut new_msgs);
        // Keep the buffer from growing unbounded across a long session.
        const MAX_HISTORY: usize = 2000;
        if self.messages.len() > MAX_HISTORY {
            let excess = self.messages.len() - MAX_HISTORY;
            self.messages.drain(0..excess);
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
