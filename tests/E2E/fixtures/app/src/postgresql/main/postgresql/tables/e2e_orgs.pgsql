CREATE TABLE IF NOT EXISTS orgs (
    id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(100) NOT NULL
);
