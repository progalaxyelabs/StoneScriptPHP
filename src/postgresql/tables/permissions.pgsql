create table permissions (
    permission_id serial primary key,
    name text not null unique,
    description text,
    resource text not null,
    action text not null,
    created_on timestamptz not null default current_timestamp,
    last_updated_on timestamptz not null default current_timestamp
);

create index idx_permissions_resource on permissions(resource);
create index idx_permissions_action on permissions(action);
create unique index idx_permissions_resource_action on permissions(resource, action);
