<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\ReviewRepository;
use Locally\Repository\UserRepository;
use JsonException;
use PDOException;

final class ReviewController
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly UserRepository $users,
    ) {
    }

    public function create(Request $request): Response
    {
        $block = Access::ensureAuth();
        if ($block !== null) {
            return $block;
        }

        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $uid = Access::userId() ?? 0;
        $pid = isset($body['product_id']) ? (int) $body['product_id'] : 0;
        $rating = isset($body['rating']) ? (int) $body['rating'] : 0;
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : null;
        $bodyText = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : null;

        if ($pid <= 0) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'product_id is required.'], 422);
        }
        if ($rating < 1 || $rating > 5) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'rating must be between 1 and 5.'], 422);
        }

        $title = $title !== null && $title !== '' ? substr($title, 0, 200) : null;
        $bodyText = $bodyText !== null && $bodyText !== '' ? substr($bodyText, 0, 4000) : null;

        if (!$this->reviews->productExists($pid)) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
        }

        if ($this->reviews->userHasReview($uid, $pid)) {
            return Response::jsonError(
                ['code' => 'ALREADY_REVIEWED', 'message' => 'You already reviewed this product.'],
                409
            );
        }

        try {
            $this->reviews->insert($uid, $pid, $rating, $title, $bodyText);
            $this->reviews->refreshProductStats($pid);
        } catch (PDOException) {
            return Response::jsonError(['code' => 'REVIEW_FAILED', 'message' => 'Could not save review.'], 500);
        }

        $row = $this->users->findByIdWithRole($uid);

        return Response::jsonOk([
            'review' => [
                'product_id' => $pid,
                'rating' => $rating,
                'title' => $title,
                'body' => $bodyText,
                'author' => [
                    'first_name' => is_array($row) ? (string) ($row['first_name'] ?? '') : '',
                ],
            ],
        ], 201);
    }
}
