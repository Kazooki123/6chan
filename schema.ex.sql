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
    ip         VARCHAR(45),
    board_id   INT NOT NULL REFERENCES boards(id),
    subject    VARCHAR(200),
    body       TEXT NOT NULL,
    img_path   VARCHAR(300),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    bumped_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE posts (
    id         SERIAL PRIMARY KEY,
    ip         VARCHAR(45),
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
  ('d', 'Dev', 'Developer/Coder discussions'),
  ('rs', 'Rust', 'Rust Programming Language'),
  ('p', 'Python', 'Python Language'),
  ('go', 'Go', 'GoLang Programming Language'),
  ('cl', 'C-Lang', 'C Programming Language'),
  ('dn', '.NET', 'DotNET discussions'),
  ('cs', 'Computer Science', 'Computer Science stuffs and topics.'),
  ('wd', 'Web Dev', 'Web Development discussions'),
  ('f', 'FaceDev', 'Peak Youtuber, Face'),
  ('tu', 'Turtles', 'About Turtles'),
  ('l', 'LGBTQ', 'LGBTQ+ Topics');

INSERT INTO admins (username, pwd_hash) VALUES ('admin', '$2y$12$HASH');

-- ADD AUTHORS
ALTER TABLE threads ADD COLUMN author VARCHAR(64) NOT NULL DEFAULT 'Anonymous';
ALTER TABLE posts   ADD COLUMN author VARCHAR(64) NOT NULL DEFAULT 'Anonymous';

-- DROP NOT NULL in img_path
ALTER TABLE threads ALTER COLUMN img_path DROP NOT NULL;
ALTER TABLE posts   ALTER COLUMN img_path DROP NOT NULL;

-- ADD PINS AND LOCKS
ALTER TABLE threads ADD COLUMN pinned BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE threads ADD COLUMN locked BOOLEAN NOT NULL DEFAULT FALSE;

-- ADD IPs
ALTER TABLE threads ADD COLUMN ip VARCHAR(45);
ALTER TABLE posts   ADD COLUMN ip VARCHAR(45);