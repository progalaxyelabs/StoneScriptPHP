-- Sample User Data (Development Only)
-- This seed data is useful for development and testing

INSERT INTO users (email, name, age) VALUES
    ('alice@example.com', 'Alice Johnson', 28),
    ('bob@example.com', 'Bob Smith', 35),
    ('charlie@example.com', 'Charlie Davis', 42)
ON CONFLICT (email) DO NOTHING;
