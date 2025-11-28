<?php

namespace App\Routes;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Database;
use App\Contracts\IWebsitesRoute;
use App\DTO\WebsitesRequest;
use App\DTO\WebsitesResponse;

class WebsitesRoute implements IRouteHandler, IWebsitesRoute
{
    public string $name;
    public string $type;
    public ?string $userId = null;

    public function validation_rules(): array
    {
        return [
            'name' => 'required|min:3|max:255',
            'type' => 'required|in:portfolio,business,ecommerce,blog',
        ];
    }

    public function process(): ApiResponse
    {
        // Build request DTO from input
        $request = new WebsitesRequest(
            name: $this->name,
            type: $this->type,
            userId: $this->userId
        );

        $response = $this->execute($request);

        return res_ok($response, 'Website created successfully');
    }

    public function execute(WebsitesRequest $request): WebsitesResponse
    {
        // Get database connection
        $db = Database::getConnection();

        // Insert website into database
        $sql = "
            INSERT INTO websites (name, type, user_id, status, created_at, updated_at)
            VALUES (:name, :type, :user_id, 'draft', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id, name, type, status, created_at
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'name' => $request->name,
            'type' => $request->type,
            'user_id' => $request->userId,
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            throw new \Exception('Failed to create website');
        }

        // Return response DTO
        return new WebsitesResponse(
            id: $result['id'],
            name: $result['name'],
            type: $result['type'],
            status: $result['status'],
            createdAt: $result['created_at']
        );
    }
}
