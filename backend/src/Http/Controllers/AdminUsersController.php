<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\UserRepository;
use JsonException;
use PDOException;

/** Admin-only user directory and safe account updates (no password reset here). */
final class AdminUsersController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function list(Request $request): Response
    {
        return $this->admin(function () use ($request): Response {
            $q = isset($request->query['q']) && is_string($request->query['q']) ? trim($request->query['q']) : '';
            $page = max(1, (int) ($request->query['page'] ?? 1));
            $per = min(50, max(1, (int) ($request->query['per_page'] ?? 20)));
            $res = $this->users->listForAdmin($q, $page, $per);

            $items = array_map(
                fn (array $row): array => $this->users->shapeAdminUser($row),
                $res['items']
            );

            return Response::jsonOk([
                'items' => $items,
                'page' => $page,
                'per_page' => $per,
                'total' => $res['total'],
            ]);
        });
    }

    public function patch(Request $request, string $segment): Response
    {
        return $this->admin(function () use ($request, $segment): Response {
            $id = (int) $segment;
            if ($id <= 0) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'User not found.'], 404);
            }

            $target = $this->users->findByIdWithRole($id);
            if ($target === null) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'User not found.'], 404);
            }

            try {
                $body = $request->jsonBody();
            } catch (JsonException) {
                return Response::jsonError(
                    ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                    400
                );
            }

            $actorId = Access::userId() ?? 0;
            $targetRole = (string) ($target['role_slug'] ?? '');
            $targetActive = (bool) ((int) ($target['is_active'] ?? 0));

            $newRoleId = null;
            if (isset($body['role_id']) && is_numeric($body['role_id'])) {
                $newRoleId = (int) $body['role_id'];
            } elseif (isset($body['role_slug']) && is_string($body['role_slug'])) {
                $slug = trim($body['role_slug']);
                $newRoleId = $this->users->roleIdBySlug($slug);
                if ($newRoleId === null) {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'role_slug is not a valid role.'], 422);
                }
            }

            $newIsActive = null;
            if (array_key_exists('is_active', $body)) {
                $newIsActive = $body['is_active'] === true || $body['is_active'] === 1 || $body['is_active'] === '1';
            }

            $newTheme = null;
            if (isset($body['theme_preference']) && is_string($body['theme_preference'])) {
                $newTheme = trim($body['theme_preference']);
                if (!in_array($newTheme, ['system', 'light', 'dark'], true)) {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'theme_preference must be system, light, or dark.'], 422);
                }
            }

            if ($newRoleId === null && $newIsActive === null && $newTheme === null) {
                return Response::jsonOk(['user' => $this->users->shapeAdminUser($target)]);
            }

            $newRoleSlug = $targetRole;
            if ($newRoleId !== null) {
                $slugRow = $this->users->roleSlugById($newRoleId);
                if ($slugRow === null) {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'role_id is not a valid role.'], 422);
                }
                $newRoleSlug = $slugRow;
            }

            if ($id === $actorId) {
                if ($newIsActive === false) {
                    return Response::jsonError(['code' => 'FORBIDDEN', 'message' => 'You cannot deactivate your own account.'], 403);
                }
                if ($newRoleId !== null && $newRoleSlug !== 'admin') {
                    return Response::jsonError(['code' => 'FORBIDDEN', 'message' => 'You cannot remove your own administrator role.'], 403);
                }
            }

            if ($targetRole === 'admin' && $targetActive) {
                $otherAdmins = $this->users->countActiveAdminsExcluding($id);
                if ($otherAdmins < 1) {
                    if ($newRoleId !== null && $newRoleSlug !== 'admin') {
                        return Response::jsonError(
                            ['code' => 'LAST_ADMIN', 'message' => 'Cannot change the only active administrator’s role.'],
                            409
                        );
                    }
                    if ($newIsActive === false) {
                        return Response::jsonError(
                            ['code' => 'LAST_ADMIN', 'message' => 'Cannot deactivate the only active administrator.'],
                            409
                        );
                    }
                }
            }

            $patch = [];
            if ($newRoleId !== null) {
                $patch['role_id'] = $newRoleId;
            }
            if ($newIsActive !== null) {
                $patch['is_active'] = $newIsActive;
            }
            if ($newTheme !== null) {
                $patch['theme_preference'] = $newTheme;
            }

            try {
                $this->users->updateAdminFields($id, $patch);
            } catch (PDOException) {
                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not update user.'], 500);
            }

            $fresh = $this->users->findByIdWithRole($id);

            return Response::jsonOk(['user' => $fresh !== null ? $this->users->shapeAdminUser($fresh) : null]);
        });
    }

    /**
     * @param callable(): Response $fn
     */
    private function admin(callable $fn): Response
    {
        $block = Access::ensureRoles($this->users, ['admin']);
        if ($block !== null) {
            return $block;
        }

        return $fn();
    }
}
