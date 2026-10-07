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
    id              SERIAL PRIMARY KEY,
    slug            VARCHAR(10) UNIQUE NOT NULL,
    name            VARCHAR(64) NOT NULL,
    descript        TEXT,
    robot9k_enabled BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE threads (
    id           SERIAL PRIMARY KEY,
    ip           VARCHAR(45),
    board_id     INT NOT NULL REFERENCES boards(id),
    author       VARCHAR(64) NOT NULL DEFAULT 'Anonymous',
    tripcode     VARCHAR(12) DEFAULT NULL,
    subject      VARCHAR(200),
    body         TEXT NOT NULL,
    img_path     VARCHAR(300),
    automod_flag VARCHAR(50),
    bgm_post_id  INT REFERENCES posts(id) ON DELETE SET NULL,
    flag_code    VARCHAR(10),
    flag_name    VARCHAR(64),
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
    tripcode     VARCHAR(12) DEFAULT NULL,
    body         TEXT NOT NULL,
    img_path     VARCHAR(300),
    automod_flag VARCHAR(50),
    flag_code    VARCHAR(10),
    flag_code    VARCHAR(64),
    nsfw         BOOLEAN NOT NULL DEFAULT FALSE,
    options      VARCHAR(64),
    created_at   TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO boards (slug, name, descript) VALUES
  ('qa',    'Q&A',          'Question & Answers'),
  ('r',     'Random',       'Anything goes.'),
  ('t',     'Tech', '       Technology Board'),
  ('a',     'Anime',        'Japs & Manga'),
  ('v',     'Games',        'Video Games (General)'),
  ('m',     'Music',        'Music topics and discussions'),
  ('meta',  'Meta',         'Site Feedback ^w^'),
  ('sp',    'Sports',       'Sports Topic'),
  ('pg',    'Photography',  'Photos and Views'),
  ('art',   'Artwork',      'Arts and Paintings'),
  ('pol',   'Politics',     'International Politics'),
  ('fem',   'Feminism',     'Feminism discussions'),
  ('d',     'Dev',          'Developer discussions'),
  ('rs',    'Rust',         'Rust Programming Language'),
  ('py',    'Python',       'Python Language'),
  ('go',    'Go',           'Go Programming Language'),
  ('cl',    'Clang',        'C Programming Language'),
  ('dn',    '.NET',         'DotNET discussions'),
  ('cs',    'CompSci',      'Computer Science stuffs'),
  ('sm',    'S&M',          'Science & Math'),
  ('cd',    'CD&DVD',       'CDs & DVD Collections'),
  ('h',     'History',      'History learning and Discussions'),
  ('wd',    'Web Dev',      'Web Development discussions'),
  ('f',     'FaceDev',      'Peak YouTuber, Face'),
  ('tu',    'Turtles',      'About Turtles'),
  ('lgb',   'LGBTQ',        'LGBTQ+ Topics'),
  ('y',     'Yuri',         'Yuri Media (NSFW)'),
  ('x',     'Paranormal',   'Paranormal Activities');

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

CREATE TABLE spaces (
    id           SERIAL PRIMARY KEY,
    board_id     INT NOT NULL REFERENCES boards(id),
    thread_id    INT REFERENCES threads(id) ON DELETE SET NULL,
    title        VARCHAR(200) NOT NULL,
    host_session VARCHAR(64) NOT NULL,
    host_author  VARCHAR(64) NOT NULL DEFAULT 'Anon',
    host_trip    VARCHAR(12),
    status       VARCHAR(10) NOT NULL DEFAULT 'live' CHECK (status IN ('live', 'ended')),
    max_speakers INT NOT NULL DEFAULT 6,
    started_at   TIMESTAMPTZ DEFAULT NOW(),
    ended_at     TIMESTAMPTZ
);

CREATE TABLE space_participants (
    id         SERIAL PRIMARY KEY,
    space_id   INT NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    session_id VARCHAR(64) NOT NULL,
    author     VARCHAR(64) NOT NULL DEFAULT 'Anon',
    tripcode   VARCHAR(12),
    role       VARCHAR(10) NOT NULL DEFAULT 'listener' CHECK (role IN ('host', 'speaker', 'listener')),
    muted      BOOLEAN NOT NULL DEFAULT FALSE,
    joined_at  TIMESTAMPTZ DEFAULT NOW(),
    left_at    TIMESTAMPTZ,
    last_seen  TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (space_id, session_id)
);

CREATE TABLE space_speaker_requests (
    id           SERIAL PRIMARY KEY,
    space_id     INT NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    session_id   VARCHAR(64) NOT NULL,
    author       VARCHAR(64) NOT NULL DEFAULT 'Anon',
    tripcode     VARCHAR(12),
    status       VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'denied')),
    requested_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (space_id, session_id)
);

CREATE TABLE space_signals (
    id           BIGSERIAL PRIMARY KEY,
    space_id     INT NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    from_session VARCHAR(64) NOT NULL,
    to_session   VARCHAR(64),
    type         VARCHAR(20) NOT NULL,
    payload      JSONB NOT NULL,
    created_at   TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE pfps (
    tripcode   VARCHAR(12) PRIMARY KEY,
    img_key    VARCHAR(300) NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE robot9k_hashes (
    id         BIGSERIAL PRIMARY KEY,
    board_id   INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    hash       CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (board_id, hash)
);

-- Run: `php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT) . PHP_EOL;"`
INSERT INTO admins (username, pwd_hash) VALUES ('admin', '$2y$12$HASH');

CREATE INDEX cache_expires_idx ON cache (expires_at)
    WHERE expires_at IS NOT NULL;

INSERT INTO automod_rules (pattern, rule_type, is_regex) VALUES
    ('spam',          'flag',  false),
    ('buy cheap',     'block', false),
    ('\b(
        word|
        )\b',         'block', true);

-- DROP NOT NULL in img_path
-- ALTER TABLE threads ALTER COLUMN img_path DROP NOT NULL;
-- ALTER TABLE posts   ALTER COLUMN img_path DROP NOT NULL;

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

CREATE INDEX idx_threads_tripcode ON threads (tripcode) WHERE tripcode IS NOT NULL;
CREATE INDEX idx_posts_tripcode   ON posts (tripcode) WHERE tripcode IS NOT NULL;

CREATE EXTENSION pg_trgm;
CREATE INDEX threads_trgm_idx ON threads USING GIN(body gin_trgm_ops);
CREATE INDEX posts_trgm_idx ON posts USING GIN(body gin_trgm_ops);

CREATE INDEX space_signals_poll_idx
    ON space_signals (space_id, to_session, id);

CREATE INDEX spaces_live_board_idx
    ON spaces (board_id, status) WHERE status = 'live';

CREATE INDEX idx_robot9k_hashes_board_hash
    ON robot9k_hashes (board_id, hash);
