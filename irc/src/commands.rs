use tokio::sync::mpsc;

use crate::app::App;
use crate::config::Config;
use crate::events::UiCommand;

fn normalize(raw: &str) -> String {
    let raw = raw.trim();
    if raw.starts_with('#') {
        raw.to_string()
    } else {
        format!("#{raw}")
    }
}

pub fn dispatch(app: &mut App, out_tx: &mpsc::UnboundedSender<UiCommand>, line: &str) {
    let mut parts = line.splitn(2, ' ');
    let cmd = parts.next().unwrap_or("").to_lowercase();
    let rest = parts.next().unwrap_or("").trim();
    let active = app.active_channel().to_string();

    match cmd.as_str() {
        "nick" => {
            if rest.is_empty() {
                app.push_system_message(&active, "Usage: /nick <name> (or \"name#password\" for a tripcode)");
                return;
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
                return;
            }
            let channel = normalize(rest);
            app.add_channel(channel.clone());
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
                return;
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
                app.push_system_message(&active, "Usage: /friend <name or tripcode>");
                return;
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
                app.push_system_message(&active, "Usage: /unfriend <name or tripcode>");
                return;
            }
            app.friends.retain(|f| f != rest);
            if let Ok(mut cfg) = Config::load() {
                cfg.friends = app.friends.clone();
                let _ = cfg.save();
            }
            app.push_system_message(&active, format!("Removed {rest} from friends"));
        }

        "quit" => {
            app.should_quit = true;
        }

        "help" => {
            app.push_system_message(
                &active,
                "/nick <name>  /join <#channel>  /part [#channel]  /me <action>  /friend <name>  /unfriend <name>  /quit  /help",
            );
        }

        other => {
            app.push_system_message(&active, format!("Unknown command: /{other} (try /help)"));
        }
    }
}
