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
    id           SERIAL PRIMARY KEY,
    ip           VARCHAR(45),
    board_id     INT NOT NULL REFERENCES boards(id),
    author       VARCHAR(64) NOT NULL DEFAULT 'Anonymous',
    subject      VARCHAR(200),
    body         TEXT NOT NULL,
    img_path     VARCHAR(300),
    automod_flag VARCHAR(50),
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    bumped_at    TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE posts (
    id           SERIAL PRIMARY KEY,
    ip           VARCHAR(45),
    thread_id    INT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    author       VARCHAR(64) NOT NULL DEFAULT 'Anonymous',
    body         TEXT NOT NULL,
    img_path     VARCHAR(300),
    automod_flag VARCHAR(50),
    options      VARCHAR(64),
    created_at   TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO boards (slug, name, descript) VALUES
  ('qa', 'Q/A', 'Question & Answers'),
  ('r', 'Random', 'Anything goes.'),
  ('t', 'Tech', 'Kwompwutwers'),
  ('a', 'Anime', 'Japs & Manga'),
  ('g', 'Games', 'Vidyoe Gaemes'),
  ('m', 'Music', 'Music topics and discussions'),
  ('pl', 'Politics', 'International Politics'),
  ('d', 'Dev', 'Developer/Coder discussions'),
  ('rs', 'Rust', 'Rust Programming Language'),
  ('p', 'Python', 'Python Language'),
  ('go', 'Go', 'GoLang Programming Language'),
  ('cl', 'C-Lang', 'C Programming Language'),
  ('dn', '.NET', 'DotNET discussions'),
  ('cs', 'Computer Science', 'Computer Science stuffs and topics.'),
  ('wd', 'Web Dev', 'Web Development discussions'),
  ('f', 'FaceDev', 'Peak YouTuber, Face'),
  ('tu', 'Turtles', 'About Turtles'),
  ('l', 'LGBTQ', 'LGBTQ+ Topics');

CREATE TABLE automod_rules (
    id         SERIAL PRIMARY KEY,
    pattern    VARCHAR(500) NOT NULL,
    rule_type  VARCHAR(20) NOT NULL,
    is_regex   BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE reports (
    id          SERIAL PRIMARY KEY,
    post_type   VARCHAR(10) NOT NULL CHECK (post_type IN ('thread', 'post')),
    post_id     INT NOT NULL,
    thread_id   INT,
    board_slug  VARCHAR(10),
    reason      VARCHAR(200),
    ip          VARCHAR(45),
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    resolved    BOOLEAN NOT NULL DEFAULT FALSE
);

INSERT INTO automod_rules (pattern, rule_type, is_regex) VALUES
    ('spam',          'flag',  false),
    ('buy cheap',     'block', false),
    ('\b(word)\b',    'block', true);

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

-- ADD OPTIONS (for Sage-ing)
ALTER TABLE posts ADD COLUMN options VARCHAR(64);
