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
    nsfw         BOOLEAN NOT NULL DEFAULT FALSE,
    pinned       BOOLEAN NOT NULL DEFAULT FALSE,
    locked       BOOLEAN NOT NULL DEFAULT FALSE,
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
    nsfw         BOOLEAN NOT NULL DEFAULT FALSE,
    options      VARCHAR(64),
    created_at   TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO boards (slug, name, descript) VALUES
  ('qa', 'Q/A', 'Question & Answers'),
  ('r', 'Random', 'Anything goes.'),
  ('t', 'Tech', 'Technology Board'),
  ('a', 'Anime', 'Japs & Manga'),
  ('g', 'Games', 'Video Games (General)'),
  ('m', 'Music', 'Music topics and discussions'),
  ('sp', 'Sports', 'Sports Topic'),
  ('pg', 'Photography', 'Photos and Views'),
  ('art', 'Artwork', 'Arts and Paintings'),
  ('pol', 'Politics', 'International Politics'),
  ('d', 'Dev', 'Developer/Coder discussions'),
  ('rs', 'Rust', 'Rust Programming Language'),
  ('py', 'Python', 'Python Language'),
  ('go', 'Go', 'Go Programming Language'),
  ('cl', 'Clang', 'C Programming Language'),
  ('dn', '.NET', 'DotNET discussions'),
  ('cs', 'Computer Science', 'Computer Science stuffs and topics.'),
  ('sm', 'S&M', 'Science & Math'),
  ('cd', 'CD&DVD', 'CDs & DVD Collections'),
  ('h', 'History', 'History learning and Discussions'),
  ('wd', 'Web Dev', 'Web Development discussions'),
  ('f', 'FaceDev', 'Peak YouTuber, Face'),
  ('tu', 'Turtles', 'About Turtles'),
  ('lgb', 'LGBTQ', 'LGBTQ+ Topics'),
  ('yh', 'Yuri', 'Yuri Media (NSFW)');

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

CREATE TABLE cache (
    key        TEXT PRIMARY KEY,
    value      JSONB NOT NULL,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE reactions (
	id		   SERIAL PRIMARY KEY,
	post_type  VARCHAR(10) NOT NULL CHECK(post_type IN ('thread', 'post')),
	post_id    INT NOT NULL,
	emoji      VARCHAR(10) NOT NULL,
	ip_hash    VARCHAR(64) NOT NULL,
	created_at TIMESTAMPTZ DEFAULT NOW(),
	
	UNIQUE (post_type, post_id, emoji, ip_hash)
);

CREATE INDEX reactions_lookup_idx ON reactions (post_type, post_id);

CREATE TABLE polls (
	id		   SERIAL PRIMARY KEY,
	thread_id  INT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
	post_id    INT REFERENCES posts(id) ON DELETE CASCADE,
	question   VARCHAR(300) NOT NULL,
	closes_at  TIMESTAMPTZ,
	created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE poll_options (
	id		 SERIAL PRIMARY KEY,
	poll_id	 INT NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
	label    VARCHAR(200) NOT NULL,
	position INT NOT NULL DEFAULT 0
);

CREATE TABLE poll_votes (
	id		  SERIAL PRIMARY KEY,
	option_id INT NOT NULL REFERENCES poll_options(id) ON DELETE CASCADE,
	ip_hash	  VARCHAR(64) NOT NULL,
	voted_at  TIMESTAMPTZ DEFAULT NOW(),
	UNIQUE (option_id, ip_hash)
);

CREATE INDEX cache_expires_idx ON cache (expires_at)
    WHERE expires_at IS NOT NULL;

INSERT INTO automod_rules (pattern, rule_type, is_regex) VALUES
    ('spam',          'flag',  false),
    ('buy cheap',     'block', false),
    ('\b(word)\b',    'block', true);

INSERT INTO admins (username, pwd_hash) VALUES ('admin', '$2y$12$HASH');

-- BGM
ALTER TABLE threads ADD COLUMN bgm_post_id  INT REFERENCES posts(id) ON DELETE SET NULL;

-- DROP NOT NULL in img_path
ALTER TABLE threads ALTER COLUMN img_path DROP NOT NULL;
ALTER TABLE posts   ALTER COLUMN img_path DROP NOT NULL;

-- ADD PINS AND LOCKS
ALTER TABLE threads ADD COLUMN pinned BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE threads ADD COLUMN locked BOOLEAN NOT NULL DEFAULT FALSE;

-- FOR SEARCHES
ALTER TABLE threads ADD COLUMN search_vec tsvector
	GENERATED ALWAYS AS (
		to_tsvector('english',
		    coalesce(subject, '') || ' ' || coalesce(body, ''))
	) STORED;
	
ALTER TABLE posts ADD COLUMN search_vec tsvector
	GENERATED ALWAYS AS (
		to_tsvector('english', coalesce(body, ''))
	) STORED;
	
CREATE INDEX threads_fts_idx ON threads USING GIN(search_vec);
CREATE INDEX posts_fts_idx ON posts USING GIN(search_vec);

CREATE EXTENSION pg_trgm;
CREATE INDEX threads_trgm_idx ON threads USING GIN(body gin_trgm_ops);
CREATE INDEX posts_trgm_idx ON posts USING GIN(body gin_trgm_ops);
