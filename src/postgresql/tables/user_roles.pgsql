create table user_roles (
    user_id int not null references users(user_id) on delete cascade,
    role_id int not null references roles(role_id) on delete cascade,
    assigned_on timestamptz not null default current_timestamp,
    primary key (user_id, role_id)
);

create index idx_user_roles_user on user_roles(user_id);
create index idx_user_roles_role on user_roles(role_id);
