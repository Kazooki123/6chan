use ratatui::style::Color;

const PALETTE: &[Color] = &[
    Color::Red,
    Color::Green,
    Color::Yellow,
    Color::Blue,
    Color::Magenta,
    Color::Cyan,
    Color::LightRed,
    Color::LightGreen,
    Color::LightBlue,
    Color::LightMagenta,
    Color::LightCyan,
];

pub fn color_for(author: &str, tripcode: &Option<String>) -> Color {
    let key = tripcode.as_deref().unwrap_or(author);
    let hash = fnv1a(key.as_bytes());
    PALETTE[(hash as usize) % PALETTE.len()]
}

pub const STAFF_COLOR: Color = Color::White;

pub const FRIEND_COLOR: Color = Color::LightYellow;

fn fnv1a(bytes: &[u8]) -> u64 {
    let mut hash: u64 = 0xcbf29ce484222325;
    for b in bytes {
        hash ^= *b as u64;
        hash = hash.wrapping_mul(0x100000001b3);
    }
    hash
}
