<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\AnalyticsRepository;
use JsonException;
use PDOException;

/** Append-only client analytics (CSRF + session; user_id when logged in). */
final class AnalyticsController
{
    public function __construct(private readonly AnalyticsRepository $analytics)
    {
    }

    public function track(Request $request): Response
    {
        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $name = isset($body['event_name']) && is_string($body['event_name']) ? trim($body['event_name']) : '';
        if ($name === '' || strlen($name) > 64) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'event_name is required (max 64 chars).'], 422);
        }
        if (!preg_match('/^[a-z][a-z0-9_.]*$/i', $name)) {
            return Response::jsonError(
                ['code' => 'VALIDATION_ERROR', 'message' => 'event_name must start with a letter and use only letters, digits, underscore, dot.'],
                422
            );
        }

        $entityType = isset($body['entity_type']) && is_string($body['entity_type']) ? substr(trim($body['entity_type']), 0, 32) : null;
        if ($entityType === '') {
            $entityType = null;
        }

        $entityId = null;
        if (isset($body['entity_id']) && is_numeric($body['entity_id'])) {
            $entityId = (int) $body['entity_id'];
            if ($entityId <= 0) {
                $entityId = null;
            }
        }

        $props = null;
        if (isset($body['properties']) && is_array($body['properties'])) {
            $props = $body['properties'];
        }

        $uid = Access::userId();
        $sid = session_id();
        $sid = $sid !== '' ? $sid : null;

        try {
            $this->analytics->insert($uid, $sid, $name, $entityType, $entityId, $props);
        } catch (PDOException) {
            return Response::jsonError(['code' => 'TRACK_FAILED', 'message' => 'Could not record event.'], 500);
        }

        return Response::jsonOk(['recorded' => true]);
    }
}
