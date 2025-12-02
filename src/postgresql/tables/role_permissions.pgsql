create table role_permissions (
    role_id int not null references roles(role_id) on delete cascade,
    permission_id int not null references permissions(permission_id) on delete cascade,
    granted_on timestamptz not null default current_timestamp,
    primary key (role_id, permission_id)
);

create index idx_role_permissions_role on role_permissions(role_id);
create index idx_role_permissions_permission on role_permissions(permission_id);
