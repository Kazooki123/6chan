use chrono::{DateTime, Local};
use ratatui::{
    layout::{Constraint, Direction, Layout, Rect},
    style::{Color, Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Paragraph},
    Frame,
};

use crate::api::PresenceEntry;
use crate::app::{App, SETTINGS_ROW_COUNT};
use crate::color::color_for;
use crate::theme::{Theme, ThemeName};

pub fn draw(frame: &mut Frame, app: &App) {
    let area = frame.area();
    let theme = app.theme();

    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(1),
            Constraint::Length(2),
            Constraint::Min(3),
            Constraint::Length(3),
        ])
        .split(area);

    draw_tabs(frame, app, &theme, chunks[0]);
    draw_header(frame, app, &theme, chunks[1]);

    if app.settings_open {
        draw_settings(frame, app, &theme, chunks[2]);
        draw_settings_hint(frame, &theme, chunks[3]);
    } else {
        let body = Layout::default()
            .direction(Direction::Horizontal)
            .constraints([Constraint::Min(20), Constraint::Length(22)])
            .split(chunks[2]);
        draw_messages(frame, app, &theme, body[0]);
        draw_users(frame, app, &theme, body[1]);
        draw_input(frame, app, &theme, chunks[3]);
    }
}

fn rainbow_spans(text: &str) -> Vec<Span<'static>> {
    let stripes = ThemeName::pride_stripes();
    text.chars()
        .enumerate()
        .map(|(i, c)| {
            Span::styled(
                c.to_string(),
                Style::default().fg(stripes[i % stripes.len()]).add_modifier(Modifier::BOLD),
            )
        })
        .collect()
}

fn draw_tabs(frame: &mut Frame, app: &App, theme: &Theme, area: Rect) {
    let mut spans = Vec::new();
    for (i, channel) in app.channels.iter().enumerate() {
        let is_active = i == app.active && !app.settings_open;
        let (unread, mentioned) = app
            .state
            .get(channel)
            .map(|s| (s.unread, s.mentioned))
            .unwrap_or((0, false));
        let label = if unread > 0 {
            format!(" {channel} ({unread}) ")
        } else {
            format!(" {channel} ")
        };

        if is_active && app.theme_name == ThemeName::Pride {
            spans.extend(rainbow_spans(&label));
        } else {
            let style = if is_active {
                Style::default().fg(Color::Black).bg(theme.accent).add_modifier(Modifier::BOLD)
            } else if mentioned {
                Style::default().fg(theme.mention).add_modifier(Modifier::BOLD)
            } else if unread > 0 {
                Style::default().fg(theme.accent)
            } else {
                Style::default().fg(theme.muted)
            };
            spans.push(Span::styled(label, style));
        }
        spans.push(Span::raw(" "));
    }

    let settings_style = if app.settings_open {
        Style::default().fg(Color::Black).bg(theme.accent).add_modifier(Modifier::BOLD)
    } else {
        Style::default().fg(theme.muted)
    };
    spans.push(Span::styled(" ⚙ Settings ", settings_style));
    spans.push(Span::raw("  "));
    spans.push(Span::styled(
        "(Tab/Shift+Tab switch, /settings toggles)",
        Style::default().fg(theme.muted),
    ));
    frame.render_widget(Paragraph::new(Line::from(spans)), area);
}

fn draw_header(frame: &mut Frame, app: &App, theme: &Theme, area: Rect) {
    let status = if app.settings_open {
        "Settings".to_string()
    } else {
        app.active_state().status.clone().unwrap_or_else(|| app.global_status.clone())
    };

    let title = Line::from(vec![
        Span::styled("Yukiko IRC Client", Style::default().add_modifier(Modifier::BOLD)),
        Span::raw("  —  Connected to 6chan  —  "),
        Span::styled(status, Style::default().fg(theme.muted)),
    ]);
    let block = Block::default().borders(Borders::BOTTOM);
    frame.render_widget(Paragraph::new(title).block(block), area);
}

fn identity_style(app: &App, theme: &Theme, display_name: &str, tripcode: &Option<String>, is_staff: bool) -> (Color, &'static str) {
    if is_staff {
        (theme.staff, "★")
    } else if app.is_friend(display_name, tripcode) {
        (theme.friend, "♥")
    } else {
        (color_for(display_name, tripcode), "")
    }
}

fn parse_file_marker(body: &str) -> Option<(String, u64, String)> {
    let inner = body.strip_prefix("⟦FILE:")?.strip_suffix('⟧')?;
    let mut parts = inner.splitn(3, ':');
    let id = parts.next()?.to_string();
    let size: u64 = parts.next()?.parse().ok()?;
    let filename = parts.next()?.to_string();
    Some((id, size, filename))
}

fn human_size(bytes: u64) -> String {
    const MB: f64 = 1024.0 * 1024.0;
    const KB: f64 = 1024.0;
    let b = bytes as f64;
    if b >= MB {
        format!("{:.1} MB", b / MB)
    } else if b >= KB {
        format!("{:.1} KB", b / KB)
    } else {
        format!("{bytes} B")
    }
}

fn draw_messages(frame: &mut Frame, app: &App, theme: &Theme, area: Rect) {
    let state = app.active_state();
    let inner_height = area.height as usize;
    let total = state.messages.len();

    let end = total.saturating_sub(state.scroll);
    let start = end.saturating_sub(inner_height);
    let visible = &state.messages[start..end];

    let mut lines: Vec<Line> = visible
        .iter()
        .map(|msg| {
            let time = format_time(&msg.created_at);
            let (name_color, badge) = if msg.id < 0 {
                (theme.system, "") // local system message — Yukiko herself
            } else {
                identity_style(app, theme, &msg.author, &msg.tripcode, msg.is_staff)
            };

            let mut spans = vec![];
            if app.show_timestamps {
                spans.push(Span::styled(format!("[{time}] "), Style::default().fg(theme.muted)));
            }
            spans.push(Span::styled(
                format!("<{badge}{}> ", msg.author),
                Style::default().fg(name_color).add_modifier(Modifier::BOLD),
            ));

            if app.show_tripcodes {
                if let Some(trip) = &msg.tripcode {
                    spans.push(Span::styled(format!("{trip} "), Style::default().fg(theme.muted)));
                }
            }

            if let Some((id, size, filename)) = parse_file_marker(&msg.body) {
                spans.push(Span::styled(
                    format!("📎 {filename} ({}) — /getfile {id}", human_size(size)),
                    Style::default().fg(theme.accent).add_modifier(Modifier::ITALIC),
                ));
            } else {
                spans.push(Span::raw(msg.body.clone()));
            }

            Line::from(spans)
        })
        .collect();

    if state.scroll > 0 {
        lines.push(Line::from(Span::styled(
            format!("-- scrolled back {} messages, End/PageDown to catch up --", state.scroll),
            Style::default().fg(Color::Yellow),
        )));
    }

    frame.render_widget(Paragraph::new(lines), area);
}

fn draw_users(frame: &mut Frame, app: &App, theme: &Theme, area: Rect) {
    let users: &[PresenceEntry] = &app.active_state().users;
    let block = Block::default().borders(Borders::LEFT).title(format!(" Online ({}) ", users.len()));

    let lines: Vec<Line> = users
        .iter()
        .map(|u| {
            let (color, badge) = identity_style(app, theme, &u.display_name, &u.tripcode, u.is_staff);
            Line::from(Span::styled(format!("{badge}{}", u.display_name), Style::default().fg(color)))
        })
        .collect();

    frame.render_widget(Paragraph::new(lines).block(block), area);
}

fn draw_input(frame: &mut Frame, app: &App, theme: &Theme, area: Rect) {
    let block = Block::default().borders(Borders::TOP);
    let text = Line::from(vec![
        Span::styled("> ", Style::default().fg(theme.accent)),
        Span::raw(&app.input),
        Span::styled("█", Style::default().fg(theme.muted)),
    ]);
    frame.render_widget(Paragraph::new(text).block(block), area);
}

fn settings_row_label(row: usize, app: &App) -> String {
    match row {
        0 => format!("Theme: {}", app.theme_name.label()),
        1 => format!("Show timestamps: {}", if app.show_timestamps { "on" } else { "off" }),
        2 => format!("Show tripcodes: {}", if app.show_tripcodes { "on" } else { "off" }),
        _ => String::new(),
    }
}

fn draw_settings(frame: &mut Frame, app: &App, theme: &Theme, area: Rect) {
    let block = Block::default().borders(Borders::ALL).title(" Settings ");

    let mut lines = Vec::new();
    for row in 0..SETTINGS_ROW_COUNT {
        let label = settings_row_label(row, app);
        let style = if row == app.settings_selected {
            Style::default().fg(Color::Black).bg(theme.accent).add_modifier(Modifier::BOLD)
        } else {
            Style::default()
        };
        lines.push(Line::from(Span::styled(format!(" {label} "), style)));
    }

    frame.render_widget(Paragraph::new(lines).block(block), area);
}

fn draw_settings_hint(frame: &mut Frame, theme: &Theme, area: Rect) {
    let block = Block::default().borders(Borders::TOP);
    let text = Line::from(Span::styled(
        "↑/↓ select   ←/→ or Enter change   Esc or /settings to close",
        Style::default().fg(theme.muted),
    ));
    frame.render_widget(Paragraph::new(text).block(block), area);
}

fn format_time(rfc3339: &str) -> String {
    DateTime::parse_from_rfc3339(rfc3339)
        .map(|dt| dt.with_timezone(&Local).format("%H:%M:%S").to_string())
        .unwrap_or_else(|_| rfc3339.to_string())
}
