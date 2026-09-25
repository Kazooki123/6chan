use std::path::PathBuf;

use tokio::sync::mpsc;

use crate::app::App;
use crate::config::Config;
use crate::events::UiCommand;
use crate::player::Player;

pub enum AsyncJob {
    Upload { channel: String, author: String, path: PathBuf },
    Download { file_id: String, dest_dir: PathBuf, feedback_channel: String },
}

fn normalize(raw: &str) -> String {
    let raw = raw.trim();
    if raw.starts_with('#') {
        raw.to_string()
    } else {
        format!("#{raw}")
    }
}

pub fn dispatch(
    app: &mut App,
    out_tx: &mpsc::UnboundedSender<UiCommand>,
    player: &mut Option<Player>,
    line: &str,
) -> Option<AsyncJob> {
    let mut parts = line.splitn(2, ' ');
    let cmd = parts.next().unwrap_or("").to_lowercase();
    let rest = parts.next().unwrap_or("").trim();
    let active = app.active_channel().to_string();

    match cmd.as_str() {
        "nick" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /nick <name> (or \"name#password\" for a tripcode)");
                return None;
            }
            app.name = rest.to_string();
            if let Ok(mut cfg) = Config::load() {
                cfg.name = Some(rest.to_string());
                let _ = cfg.save();
            }
            app.push_system_message(&active, format!("You are now known as {rest}"));
        }

        "join" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /join <#channel>");
                return None;
            }
            let channel = normalize(rest);
            app.add_channel(channel.clone());
            app.push_system_message(&channel, format!("Joined {channel}"));
            let _ = out_tx.send(UiCommand::Join { channel });
        }

        "part" | "leave" => {
            let channel = if rest.is_empty() { active.clone() } else { normalize(rest) };
            if let Err(msg) = app.remove_channel(&channel) {
                app.push_system_message(&active, msg);
            }
        }

        "me" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /me <action>");
                return None;
            }
            let body = format!("*{} {}*", app.name, rest);
            let _ = out_tx.send(UiCommand::Send {
                channel: active,
                author: app.name.clone(),
                body,
            });
        }

        "friend" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /friend <name or !tripcode>");
                return None;
            }
            if !app.friends.iter().any(|f| f == rest) {
                app.friends.push(rest.to_string());
            }
            if let Ok(mut cfg) = Config::load() {
                cfg.friends = app.friends.clone();
                let _ = cfg.save();
            }
            app.push_system_message(&active, format!("Added {rest} as a friend"));
        }

        "unfriend" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /unfriend <name or !tripcode>");
                return None;
            }
            app.friends.retain(|f| f != rest);
            if let Ok(mut cfg) = Config::load() {
                cfg.friends = app.friends.clone();
                let _ = cfg.save();
            }
            app.push_system_message(&active, format!("Removed {rest} from friends"));
        }

        "settings" => {
            app.settings_open = !app.settings_open;
        }

        "play" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /play <local file path>");
                return None;
            }
            let Some(player) = player else {
                app.push_system_message(&active, "No audio output device available");
                return None;
            };
            let path = PathBuf::from(rest);
            match player.play(&path) {
                Ok(()) => {
                    let title = player.now_playing.clone().unwrap_or_else(|| rest.to_string());
                    app.push_system_message(&active, format!("Now playing: {title}"));
                    let _ = out_tx.send(UiCommand::Send {
                        channel: active,
                        author: app.name.clone(),
                        body: format!("🎵 now playing: {title}"),
                    });
                }
                Err(e) => {
                    app.push_system_message(&active, format!("Couldn't play {}: {e}", path.display()));
                }
            }
        }

        "pause" => {
            if let Some(player) = player {
                player.pause();
                app.push_system_message(&active, "Paused");
            } else {
                app.push_system_message(&active, "No audio output device available");
            }
        }

        "resume" => {
            if let Some(player) = player {
                player.resume();
                app.push_system_message(&active, "Resumed");
            } else {
                app.push_system_message(&active, "No audio output device available");
            }
        }

        "stop" => {
            if let Some(player) = player {
                player.stop();
                app.push_system_message(&active, "Stopped");
            } else {
                app.push_system_message(&active, "No audio output device available");
            }
        }

        "volume" => {
            let Some(player) = player else {
                app.push_system_message(&active, "No audio output device available");
                return None;
            };
            match rest.trim().parse::<u32>() {
                Ok(pct) => {
                    player.set_volume(pct);
                    app.push_system_message(&active, format!("Volume set to {}%", pct.min(200)));
                }
                Err(_) => app.push_system_message(&active, "Usage: /volume <0-200>"),
            }
        }

        "send" | "upload" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /send <local file path> (5MB max)");
                return None;
            }
            let path = PathBuf::from(rest);
            app.push_system_message(&active, format!("Uploading {}...", path.display()));
            return Some(AsyncJob::Upload { channel: active, author: app.name.clone(), path });
        }

        "getfile" | "download" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /getfile <id> [folder]  (id comes from a 📎 line)");
                return None;
            }
            let mut args = rest.splitn(2, ' ');
            let file_id = args.next().unwrap_or("").to_string();
            let dest_dir = args.next().map(PathBuf::from).unwrap_or_else(|| PathBuf::from("yukiko-downloads"));
            app.push_system_message(&active, format!("Downloading {file_id}..."));
            return Some(AsyncJob::Download { file_id, dest_dir, feedback_channel: active });
        }

        "quit" => {
            app.should_quit = true;
        }

        "help" => {
            app.push_system_message(
                &active,
                "/nick <name>  /join <#channel>  /part [#channel]  /me <action>  /friend <name>  /unfriend <name>  /send <path>  /getfile <id>  /play <path>  /pause  /resume  /stop  /volume <0-200>  /settings  /quit  /help",
            );
        }

        other => {
            app.push_system_message(&active, format!("Unknown command: /{other} (try /help)"));
        }
    }
    None
}
