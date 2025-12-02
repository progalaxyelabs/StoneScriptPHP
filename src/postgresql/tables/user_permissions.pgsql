create table user_permissions (
    user_id int not null references users(user_id) on delete cascade,
    permission_id int not null references permissions(permission_id) on delete cascade,
    granted_on timestamptz not null default current_timestamp,
    primary key (user_id, permission_id)
);

create index idx_user_permissions_user on user_permissions(user_id);
create index idx_user_permissions_permission on user_permissions(permission_id);
