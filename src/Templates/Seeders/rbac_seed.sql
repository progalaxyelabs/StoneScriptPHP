-- RBAC Seeder: Default roles and permissions
-- This file seeds default roles and permissions for the RBAC system

-- Insert default permissions
INSERT INTO permissions (name, description, resource, action) VALUES
    -- User management permissions
    ('users.view', 'View user details', 'users', 'view'),
    ('users.create', 'Create new users', 'users', 'create'),
    ('users.update', 'Update user information', 'users', 'update'),
    ('users.delete', 'Delete users', 'users', 'delete'),
    ('users.list', 'List all users', 'users', 'list'),

    -- Role management permissions
    ('roles.view', 'View role details', 'roles', 'view'),
    ('roles.create', 'Create new roles', 'roles', 'create'),
    ('roles.update', 'Update role information', 'roles', 'update'),
    ('roles.delete', 'Delete roles', 'roles', 'delete'),
    ('roles.list', 'List all roles', 'roles', 'list'),
    ('roles.assign', 'Assign roles to users', 'roles', 'assign'),

    -- Permission management permissions
    ('permissions.view', 'View permission details', 'permissions', 'view'),
    ('permissions.create', 'Create new permissions', 'permissions', 'create'),
    ('permissions.update', 'Update permission information', 'permissions', 'update'),
    ('permissions.delete', 'Delete permissions', 'permissions', 'delete'),
    ('permissions.list', 'List all permissions', 'permissions', 'list'),
    ('permissions.assign', 'Assign permissions to roles or users', 'permissions', 'assign'),

    -- Content management permissions
    ('content.view', 'View content', 'content', 'view'),
    ('content.create', 'Create content', 'content', 'create'),
    ('content.update', 'Update content', 'content', 'update'),
    ('content.delete', 'Delete content', 'content', 'delete'),
    ('content.publish', 'Publish content', 'content', 'publish')
ON CONFLICT (name) DO NOTHING;

-- Insert default roles
INSERT INTO roles (name, description, is_system_role) VALUES
    ('super_admin', 'Super administrator with full system access', true),
    ('admin', 'Administrator with most privileges', true),
    ('moderator', 'Content moderator with limited admin access', true),
    ('user', 'Regular user with basic access', true),
    ('guest', 'Guest user with read-only access', true)
ON CONFLICT (name) DO NOTHING;

-- Assign permissions to roles
-- Super Admin: All permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'super_admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;

-- Admin: Most permissions except some critical ones
INSERT INTO role_permissions (role_id, permission_id)
SELECT
    (SELECT role_id FROM roles WHERE name = 'admin'),
    permission_id
FROM permissions
WHERE name IN (
    'users.view', 'users.create', 'users.update', 'users.list',
    'roles.view', 'roles.list', 'roles.assign',
    'permissions.view', 'permissions.list',
    'content.view', 'content.create', 'content.update', 'content.delete', 'content.publish'
)
ON CONFLICT (role_id, permission_id) DO NOTHING;

-- Moderator: Content management and user viewing
INSERT INTO role_permissions (role_id, permission_id)
SELECT
    (SELECT role_id FROM roles WHERE name = 'moderator'),
    permission_id
FROM permissions
WHERE name IN (
    'users.view', 'users.list',
    'content.view', 'content.update', 'content.delete'
)
ON CONFLICT (role_id, permission_id) DO NOTHING;

-- User: Basic content permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT
    (SELECT role_id FROM roles WHERE name = 'user'),
    permission_id
FROM permissions
WHERE name IN (
    'content.view', 'content.create'
)
ON CONFLICT (role_id, permission_id) DO NOTHING;

-- Guest: View only
INSERT INTO role_permissions (role_id, permission_id)
SELECT
    (SELECT role_id FROM roles WHERE name = 'guest'),
    permission_id
FROM permissions
WHERE name IN (
    'content.view'
)
ON CONFLICT (role_id, permission_id) DO NOTHING;
