create table roles (
    role_id serial primary key,
    name text not null unique,
    description text,
    is_system_role bool default false,
    created_on timestamptz not null default current_timestamp,
    last_updated_on timestamptz not null default current_timestamp
);

create index idx_roles_name on roles(name);
