<?php

declare(strict_types=1);

namespace Locally\Auth;

use Locally\Http\Response;
use Locally\Repository\UserRepository;

final class Access
{
    public static function userId(): ?int
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $id = (int) $_SESSION['user_id'];

        return $id > 0 ? $id : null;
    }

    public static function ensureAuth(): ?Response
    {
        if (self::userId() === null) {
            return Response::jsonError(
                ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
                401
            );
        }

        return null;
    }

    /**
     * @param list<string> $roleSlugs
     */
    public static function ensureRoles(UserRepository $users, array $roleSlugs): ?Response
    {
        $auth = self::ensureAuth();
        if ($auth !== null) {
            return $auth;
        }

        $row = $users->findByIdWithRole(self::userId() ?? 0);
        if ($row === null || !(int) ($row['is_active'] ?? 0)) {
            return Response::jsonError(
                ['code' => 'UNAUTHENTICATED', 'message' => 'Account unavailable.'],
                401
            );
        }

        $slug = (string) ($row['role_slug'] ?? '');
        if (!in_array($slug, $roleSlugs, true)) {
            return Response::jsonError(
                ['code' => 'FORBIDDEN', 'message' => 'Insufficient permissions.'],
                403
            );
        }

        return null;
    }
}
