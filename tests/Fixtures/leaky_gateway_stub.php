<?php
// Test double: answers every request like a gateway whose PostgreSQL error echoes customer data.
http_response_code(500);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'query_failed',
    'message' => "Query for function 'create_user' failed for jane.sentinel@example.test",
    'database' => 'tenant_db',
    'job_id' => 'job-1',
    'cause' => 'duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(jane.sentinel@example.test) already exists. | HINT: phone +919876500123 hash $2y$10$sentinelhashvalue | COLUMN: email | TABLE: users | SCHEMA: public | User +919876500123 exists',
]);
