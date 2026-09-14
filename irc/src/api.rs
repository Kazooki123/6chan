use std::time::{SystemTime, UNIX_EPOCH};

use anyhow::{bail, Result};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatMessage {
    pub id: i64,
    pub channel: String,
    pub author: String,
    pub tripcode: Option<String>,
    pub is_staff: bool,
    pub body: String,
    pub created_at: String, // RFC3339, e.g. 2026-09-08T12:00:00Z
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PresenceEntry {
    pub display_name: String,
    pub tripcode: Option<String>,
    pub is_staff: bool,
}

pub struct PollResult {
    pub messages: Vec<ChatMessage>,
    pub users: Vec<PresenceEntry>,
}

#[derive(Debug, Deserialize)]
struct PollResponse {
    #[serde(default)]
    ok: bool,
    #[serde(default)]
    messages: Vec<ChatMessage>,
    #[serde(default)]
    users: Vec<PresenceEntry>,
    #[serde(default)]
    error: Option<String>,
}

#[derive(Debug, Deserialize)]
struct SendResponse {
    #[serde(default)]
    ok: bool,
    #[serde(default)]
    error: Option<String>,
}

#[derive(Debug, Deserialize)]
struct RedeemResponse {
    #[serde(default)]
    ok: bool,
    #[serde(default)]
    token: Option<String>,
    #[serde(default)]
    error: Option<String>,
}

fn generate_client_id() -> String {
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_nanos())
        .unwrap_or(0);
    let pid = std::process::id() as u128;
    format!("{:x}", nanos ^ (pid << 64))
}

#[derive(Clone)]
pub struct Api {
    client: reqwest::Client,
    base_url: String, // ex: http://localhost:8080
    staff_token: Option<String>,
    client_id: String,
}

impl Api {
    pub fn new(base_url: String, staff_token: Option<String>) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.trim_end_matches('/').to_string(),
            staff_token,
            client_id: generate_client_id(),
        }
    }

    fn endpoint(&self) -> String {
        format!("{}/api/irc.php", self.base_url)
    }

    fn invite_endpoint(&self) -> String {
        format!("{}/admin/invite.php", self.base_url)
    }

    pub async fn redeem_invite(&self, code: &str, username: &str) -> Result<String> {
        let resp = self
            .client
            .post(self.invite_endpoint())
            .query(&[("action", "redeem")])
            .form(&[("code", code), ("username", username)])
            .send()
            .await?;

        let status = resp.status();
        let parsed: RedeemResponse = resp.json().await?;

        if !status.is_success() || !parsed.ok {
            bail!(parsed.error.unwrap_or_else(|| format!("redeem failed ({status})")));
        }
        parsed.token.ok_or_else(|| anyhow::anyhow!("server did not return a token"))
    }

    /// Strips a leading '#' — the server stores plain channel names
    /// ("general", "staffs"); the '#' is purely a client-side convention.
    fn normalize_channel(channel: &str) -> &str {
        channel.trim_start_matches('#')
    }

    pub async fn poll(&self, channel: &str, since: i64, name: &str) -> Result<PollResult> {
        let url = self.endpoint();
        let mut req = self
            .client
            .get(&url)
            .query(&[
                ("endpoint", "poll"),
                ("channel", Self::normalize_channel(channel)),
                ("since", &since.to_string()),
                ("limit", "50"),
                ("client_id", &self.client_id),
                ("name", name),
            ]);
        if let Some(token) = &self.staff_token {
            req = req.header("X-Yukiko-Token", token);
        }
        let resp = req.send().await?;

        let status = resp.status();
        let parsed: PollResponse = resp.json().await?;

        if !status.is_success() || !parsed.ok {
            bail!(parsed.error.unwrap_or_else(|| format!("poll failed ({status})")));
        }

        Ok(PollResult { messages: parsed.messages, users: parsed.users })
    }

    pub async fn send(&self, channel: &str, author: &str, body: &str) -> Result<()> {
        let url = self.endpoint();
        let mut req = self
            .client
            .post(&url)
            .query(&[("endpoint", "send")])
            .form(&[
                ("channel", Self::normalize_channel(channel)),
                ("author", author),
                ("body", body),
            ]);
        if let Some(token) = &self.staff_token {
            req = req.header("X-Yukiko-Token", token);
        }
        let resp = req.send().await?;

        let status = resp.status();
        let parsed: SendResponse = resp.json().await?;

        if !status.is_success() || !parsed.ok {
            bail!(parsed.error.unwrap_or_else(|| format!("send failed ({status})")));
        }

        Ok(())
    }
}
