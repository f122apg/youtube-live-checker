CREATE TABLE feed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id TEXT,
    channel_name TEXT,
    content_id TEXT,
    content_type TEXT,
    publish_date TEXT,
    check_date TEXT
);