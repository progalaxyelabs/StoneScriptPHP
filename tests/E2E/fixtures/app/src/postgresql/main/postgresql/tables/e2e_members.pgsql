CREATE TABLE IF NOT EXISTS members (
    user_id UUID NOT NULL,
    org_id  UUID NOT NULL,
    CONSTRAINT members_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT members_org_fk  FOREIGN KEY (org_id)  REFERENCES orgs(id)
);
