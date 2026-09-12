use anyhow::{bail, Result};
use serde::Deserialize;

#[derive(Debug, Clone, Deserialize)]
pub struct ChatMessage {
    pub id: i64,
    pub channel: String,
    pub author: String,
    pub tripcode: Option<String>,
    pub is_staff: bool,
    pub body: String,
    pub created_at: String, // RFC3339, e.g. 2026-09-08T12:00:00Z
}

#[derive(Debug, Deserialize)]
struct PollResponse {
    #[serde(default)]
    ok: bool,
    #[serde(default)]
    messages: Vec<ChatMessage>,
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

pub struct Api {
    client: reqwest::Client,
    base_url: String, // ex: http://localhost:8080
}

impl Api {
    pub fn new(base_url: String) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.trim_end_matches('/').to_string(),
        }
    }

    fn endpoint(&self) -> String {
        format!("{}/api/irc.php", self.base_url)
    }

    /// Strips a leading '#' — the server stores plain channel names
    /// ("general", "staffs"); the '#' is purely a client-side convention.
    fn normalize_channel(channel: &str) -> &str {
        channel.trim_start_matches('#')
    }

    pub async fn poll(&self, channel: &str, since: i64) -> Result<Vec<ChatMessage>> {
        let url = self.endpoint();
        let resp = self
            .client
            .get(&url)
            .query(&[
                ("endpoint", "poll"),
                ("channel", Self::normalize_channel(channel)),
                ("since", &since.to_string()),
                ("limit", "50"),
            ])
            .send()
            .await?;

        let status = resp.status();
        let parsed: PollResponse = resp.json().await?;

        if !status.is_success() || !parsed.ok {
            bail!(parsed.error.unwrap_or_else(|| format!("poll failed ({status})")));
        }

        Ok(parsed.messages)
    }

    pub async fn send(&self, channel: &str, author: &str, body: &str) -> Result<()> {
        let url = self.endpoint();
        let resp = self
            .client
            .post(&url)
            .query(&[("endpoint", "send")])
            .form(&[
                ("channel", Self::normalize_channel(channel)),
                ("author", author),
                ("body", body),
            ])
            .send()
            .await?;

        let status = resp.status();
        let parsed: SendResponse = resp.json().await?;

        if !status.is_success() || !parsed.ok {
            bail!(parsed.error.unwrap_or_else(|| format!("send failed ({status})")));
        }

        Ok(())
    }
}
