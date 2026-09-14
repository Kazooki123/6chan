use chrono::{DateTime, Local};
use ratatui::{
    layout::{Constraint, Direction, Layout, Rect},
    style::{Color, Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Paragraph},
    Frame,
};

use crate::api::PresenceEntry;
use crate::app::App;
use crate::color::{color_for, FRIEND_COLOR, STAFF_COLOR};

pub fn draw(frame: &mut Frame, app: &App) {
    let area = frame.area();

    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(1),
            Constraint::Length(2),
            Constraint::Min(3),
            Constraint::Length(3),
        ])
        .split(area);

    draw_tabs(frame, app, chunks[0]);
    draw_header(frame, app, chunks[1]);

    let body = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Min(20), Constraint::Length(22)])
        .split(chunks[2]);
    draw_messages(frame, app, body[0]);
    draw_users(frame, app, body[1]);

    draw_input(frame, app, chunks[3]);
}

fn draw_tabs(frame: &mut Frame, app: &App, area: Rect) {
    let mut spans = Vec::new();
    for (i, channel) in app.channels.iter().enumerate() {
        let is_active = i == app.active;
        let style = if is_active {
            Style::default()
                .fg(Color::Black)
                .bg(Color::Cyan)
                .add_modifier(Modifier::BOLD)
        } else {
            Style::default().fg(Color::DarkGray)
        };
        spans.push(Span::styled(format!(" {channel} "), style));
        spans.push(Span::raw(" "));
    }
    spans.push(Span::styled(
        "(Tab/Shift+Tab to switch)",
        Style::default().fg(Color::DarkGray),
    ));
    frame.render_widget(Paragraph::new(Line::from(spans)), area);
}

fn draw_header(frame: &mut Frame, app: &App, area: Rect) {
    let active = app.active_state();
    let channel_status = active
        .status
        .clone()
        .unwrap_or_else(|| app.global_status.clone());

    let title = Line::from(vec![
        Span::styled(
            "Yukiko IRC Client",
            Style::default().add_modifier(Modifier::BOLD),
        ),
        Span::raw("  —  Connected to 6chan  —  "),
        Span::styled(channel_status, Style::default().fg(Color::DarkGray)),
    ]);
    let block = Block::default().borders(Borders::BOTTOM);
    frame.render_widget(Paragraph::new(title).block(block), area);
}

fn identity_style(app: &App, display_name: &str, tripcode: &Option<String>, is_staff: bool) -> (Color, &'static str) {
    if is_staff {
        (STAFF_COLOR, "★")
    } else if app.is_friend(display_name, tripcode) {
        (FRIEND_COLOR, "♥")
    } else {
        (color_for(display_name, tripcode), "")
    }
}

fn draw_messages(frame: &mut Frame, app: &App, area: Rect) {
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
                (Color::Magenta, "") // local system message - Yukiko herself 🎀
            } else {
                identity_style(app, &msg.author, &msg.tripcode, msg.is_staff)
            };

            let mut spans = vec![
                Span::styled(format!("[{time}] "), Style::default().fg(Color::DarkGray)),
                Span::styled(
                    format!("<{badge}{}> ", msg.author),
                    Style::default().fg(name_color).add_modifier(Modifier::BOLD),
                ),
                Span::raw(msg.body.clone()),
            ];

            if let Some(trip) = &msg.tripcode {
                spans.insert(
                    2,
                    Span::styled(format!("{trip} "), Style::default().fg(Color::DarkGray)),
                );
            }

            Line::from(spans)
        })
        .collect();

    if state.scroll > 0 {
        lines.push(Line::from(Span::styled(
            format!(
                "-- scrolled back {} messages, End/PageDown to catch up --",
                state.scroll
            ),
            Style::default().fg(Color::Yellow),
        )));
    }

    frame.render_widget(Paragraph::new(lines), area);
}

fn draw_users(frame: &mut Frame, app: &App, area: Rect) {
    let users: &[PresenceEntry] = &app.active_state().users;
    let block = Block::default()
        .borders(Borders::LEFT)
        .title(format!(" Online ({}) ", users.len()));

    let lines: Vec<Line> = users
        .iter()
        .map(|u| {
            let (color, badge) = identity_style(app, &u.display_name, &u.tripcode, u.is_staff);
            Line::from(Span::styled(
                format!("{badge}{}", u.display_name),
                Style::default().fg(color),
            ))
        })
        .collect();

    frame.render_widget(Paragraph::new(lines).block(block), area);
}

fn draw_input(frame: &mut Frame, app: &App, area: Rect) {
    let block = Block::default().borders(Borders::TOP);
    let text = Line::from(vec![
        Span::styled("> ", Style::default().fg(Color::Green)),
        Span::raw(&app.input),
        Span::styled("█", Style::default().fg(Color::DarkGray)), // cursor
    ]);
    frame.render_widget(Paragraph::new(text).block(block), area);
}

fn format_time(rfc3339: &str) -> String {
    DateTime::parse_from_rfc3339(rfc3339)
        .map(|dt| dt.with_timezone(&Local).format("%H:%M:%S").to_string())
        .unwrap_or_else(|_| rfc3339.to_string())
}
