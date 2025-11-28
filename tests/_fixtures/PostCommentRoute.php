<?php

namespace Tests\Fixtures;

use Framework\IRouteHandler;
use Framework\ApiResponse;

/**
 * Test route handler that accepts multiple parameters: {postId} and {commentId}
 */
class PostCommentRoute implements IRouteHandler
{
    public $postId;
    public $commentId;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return res_ok(
            [
                'postId' => $this->postId,
                'commentId' => $this->commentId
            ],
            "Post $this->postId, Comment $this->commentId"
        );
    }
}
