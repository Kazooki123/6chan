CREATE TABLE admins (
    id          SERIAL PRIMARY KEY,
    username    VARCHAR(64) UNIQUE NOT NULL,
    pwd_hash    VARCHAR(255) NOT NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE bans (
    id         SERIAL PRIMARY KEY,
    ip         VARCHAR(45) NOT NULL,
    reason     TEXT,
    banned_at  TIMESTAMPTZ DEFAULT NOW(),
    expires_at TIMESTAMPTZ
);

CREATE TABLE boards (
    id        SERIAL PRIMARY KEY,
    slug      VARCHAR(10) UNIQUE NOT NULL,
    name      VARCHAR(64) NOT NULL,
    descript  TEXT
);

CREATE TABLE threads (
    id         SERIAL PRIMARY KEY,
    board_id   INT NOT NULL REFERENCES boards(id),
    subject    VARCHAR(200),
    body       TEXT NOT NULL,
    img_path   VARCHAR(300),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    bumped_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE posts (
    id         SERIAL PRIMARY KEY,
    thread_id  INT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    body       TEXT NOT NULL,
    img_path   VARCHAR(300),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO boards (slug, name, descript) VALUES
  ('r', 'Random', 'Anythong goes.'),
  ('t', 'Tech', 'Kwompwutwers'),
  ('a', 'Anime', 'Japs & Manga'),
  ('g', 'Games', 'Vidyoe Gaemes'),
  ('m', 'Music', 'Music topics and discussions'),
  ('rs', 'Rust', 'Rust Programming Language'),
  ('f', 'FaceDev', 'Peak Youtuber, Face'),
  ('tu', 'Turtles', 'About Turtles'),
  ('l', 'LGBTQ', 'LGBTQ+ Topics');

INSERT INTO admins (username, pwd_hash) VALUES ('admin', '$2y$12$HASH')
