pub fn nfo_banner(channel: &str, server: &str) -> Vec<String> {
    let content = vec![
        "     __  ____  ____ __ ______ ______
              \ \/ / / / / //_//  _/ //_/ __ \
               \  / / / / ,<   / // ,< / / / /
               / / /_/ / /| |_/ // /| / /_/ /
              /_/\____/_/ |_/___/_/ |_\____/     ".to_string(),
        String::new(),
        format!("channel : {channel}"),
        format!("server  : {server}"),
        "shoutz  : daemon, presence, themes, file-sharing".to_string(),
        String::new(),
        "Welcome to 6chan!! anyone here?".to_string(),
        "https://codeberg.org/Kazooki123/6chan".to_string(),
    ];

    let width = content.iter().map(|l| l.chars().count()).max().unwrap_or(0);

    let mut out = Vec::with_capacity(content.len() + 2);
    out.push(format!("┌{}┐", "─".repeat(width + 2)));
    for line in &content {
        out.push(format!("│ {line:<width$} │"));
    }
    out.push(format!("└{}┘", "─".repeat(width + 2)));
    out
}
