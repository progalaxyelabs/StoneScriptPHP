CREATE TABLE IF NOT EXISTS users (
    id    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email VARCHAR(255) NOT NULL,
    name  VARCHAR(100) NOT NULL,
    age   INT,
    CONSTRAINT users_email_key UNIQUE (email),
    CONSTRAINT users_age_check CHECK (age IS NULL OR age >= 0)
);
