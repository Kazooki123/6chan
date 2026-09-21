use ratatui::style::Color;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ThemeName {
    Default,
    Catppuccin,
    Pride,
}

impl ThemeName {
    pub fn next(self) -> Self {
        match self {
            ThemeName::Default => ThemeName::Catppuccin,
            ThemeName::Catppuccin => ThemeName::Pride,
            ThemeName::Pride => ThemeName::Default,
        }
    }

    pub fn prev(self) -> Self {
        match self {
            ThemeName::Default => ThemeName::Pride,
            ThemeName::Catppuccin => ThemeName::Default,
            ThemeName::Pride => ThemeName::Catppuccin,
        }
    }

    pub fn label(self) -> &'static str {
        match self {
            ThemeName::Default => "Default",
            ThemeName::Catppuccin => "Catppuccin (Mocha)",
            ThemeName::Pride => "Pride",
        }
    }

    /// Round-trips through config.toml as a plain string.
    pub fn as_key(self) -> &'static str {
        match self {
            ThemeName::Default => "default",
            ThemeName::Catppuccin => "catppuccin",
            ThemeName::Pride => "pride",
        }
    }

    pub fn from_key(key: &str) -> Self {
        match key {
            "catppuccin" => ThemeName::Catppuccin,
            "pride" => ThemeName::Pride,
            _ => ThemeName::Default,
        }
    }

    pub fn theme(self) -> Theme {
        match self {
            ThemeName::Default => Theme {
                accent: Color::Cyan,
                staff: Color::White,
                friend: Color::LightYellow,
                system: Color::Magenta,
                muted: Color::DarkGray,
                mention: Color::Red,
            },
            ThemeName::Catppuccin => Theme {
                accent: Color::Rgb(203, 166, 247),  // Mauve
                staff: Color::Rgb(250, 179, 135),   // Peach
                friend: Color::Rgb(245, 194, 231),  // Pink
                system: Color::Rgb(166, 227, 161),  // Green
                muted: Color::Rgb(108, 112, 134),   // Overlay
                mention: Color::Rgb(243, 139, 168), // Red
            },
            ThemeName::Pride => Theme {
                accent: Color::Rgb(228, 3, 3),     // red
                staff: Color::Rgb(255, 237, 0),    // yellow
                friend: Color::Rgb(0, 158, 150),   // teal
                system: Color::Rgb(117, 7, 135),   // violet
                muted: Color::Rgb(0, 76, 255),     // blue
                mention: Color::White,             // stands out against the rest
            },
        }
    }

    pub fn pride_stripes() -> [Color; 6] {
        [
            Color::Rgb(228, 3, 3),
            Color::Rgb(255, 140, 0),
            Color::Rgb(255, 237, 0),
            Color::Rgb(0, 128, 38),
            Color::Rgb(0, 76, 255),
            Color::Rgb(117, 7, 135),
        ]
    }
}

pub struct Theme {
    pub accent: Color, // active tab, input prompt
    pub staff: Color,
    pub friend: Color,
    pub system: Color, // Yukiko's own local messages
    pub muted: Color,  // timestamps, borders, secondary text
    pub mention: Color, // tab badge when you've been mentioned elsewhere
}
