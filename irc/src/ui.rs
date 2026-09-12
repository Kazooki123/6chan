use chrono::{DateTime, Local};
use ratatui::{
    layout::{Constraint, Direction, Layout, Rect},
    style::{Color, Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Paragraph},
    Frame,
};

use crate::app::App;
use crate::color::{color_for, STAFF_COLOR};

pub fn draw(frame: &mut Frame, app: &App) {
    let area = frame.area();

    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(3),
            Constraint::Min(3),
            Constraint::Length(3),
        ])
        .split(area);

    draw_header(frame, app, chunks[0]);
    draw_messages(frame, app, chunks[1]);
    draw_input(frame, app, chunks[2]);
}

fn draw_header(frame: &mut Frame, app: &App, area: Rect) {
    let title = Line::from(vec![
        Span::styled(
            "Yukiko IRC Client",
            Style::default().add_modifier(Modifier::BOLD),
        ),
        Span::raw("  —  Connected to 6chan  —  "),
        Span::styled(format!("[{}]", app.channel), Style::default().fg(Color::Cyan)),
        Span::raw("  —  "),
        Span::styled(&app.status, Style::default().fg(Color::DarkGray)),
    ]);
    let block = Block::default().borders(Borders::BOTTOM);
    frame.render_widget(Paragraph::new(title).block(block), area);
}

fn draw_messages(frame: &mut Frame, app: &App, area: Rect) {
    let inner_height = area.height as usize;
    let total = app.messages.len();

    // scroll = 0 pins to the bottom (newest messages visible).
    let end = total.saturating_sub(app.scroll);
    let start = end.saturating_sub(inner_height);
    let visible = &app.messages[start..end];

    let lines: Vec<Line> = visible
        .iter()
        .map(|msg| {
            let time = format_time(&msg.created_at);
            let (name_color, badge) = if msg.is_staff {
                (STAFF_COLOR, "★")
            } else {
                (color_for(&msg.author, &msg.tripcode), "")
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

    frame.render_widget(Paragraph::new(lines), area);
}

fn draw_input(frame: &mut Frame, app: &App, area: Rect) {
    let block = Block::default().borders(Borders::TOP);
    let text = Line::from(vec![
        Span::styled("> ", Style::default().fg(Color::Green)),
        Span::raw(&app.input),
        Span::styled("█", Style::default().fg(Color::DarkGray)),
    ]);
    frame.render_widget(Paragraph::new(text).block(block), area);
}

fn format_time(rfc3339: &str) -> String {
    DateTime::parse_from_rfc3339(rfc3339)
        .map(|dt| dt.with_timezone(&Local).format("%H:%M:%S").to_string())
        .unwrap_or_else(|_| rfc3339.to_string())
}
