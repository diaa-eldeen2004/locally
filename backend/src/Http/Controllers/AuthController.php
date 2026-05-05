<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\CartRepository;
use Locally\Repository\UserRepository;
use Locally\Security\CsrfGuard;
use JsonException;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CartRepository $carts,
    ) {
    }

    public function register(Request $request): Response
    {
        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $email = self::normalizeEmail($body['email'] ?? null);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $first = is_string($body['first_name'] ?? null) ? trim($body['first_name']) : '';
        $last = is_string($body['last_name'] ?? null) ? trim($body['last_name']) : '';

        $err = self::validateRegistration($email, $password, $first, $last);
        if ($err !== null) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => $err], 422);
        }

        if ($this->users->emailExists($email)) {
            return Response::jsonError(
                ['code' => 'EMAIL_TAKEN', 'message' => 'An account with this email already exists.'],
                409
            );
        }

        $roleId = $this->users->roleIdBySlug('customer');
        if ($roleId === null) {
            return Response::jsonError(
                ['code' => 'CONFIG_ERROR', 'message' => 'Customer role is missing from the database.'],
                500
            );
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $id = $this->users->create([
            'email' => $email,
            'password_hash' => $hash,
            'first_name' => $first,
            'last_name' => $last,
            'role_id' => $roleId,
        ]);

        $guestKey = isset($_SESSION['cart_guest_key']) && is_string($_SESSION['cart_guest_key'])
            ? $_SESSION['cart_guest_key']
            : null;

        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        CsrfGuard::regenerate();

        $this->safeMergeGuestCart($id, $guestKey);

        $fresh = $this->users->findByIdWithRole($id);

        return Response::jsonOk(['user' => $this->serializeUser($fresh)], 201);
    }

    public function login(Request $request): Response
    {
        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $email = self::normalizeEmail($body['email'] ?? null);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if ($email === '' || $password === '') {
            return Response::jsonError(
                ['code' => 'VALIDATION_ERROR', 'message' => 'Email and password are required.'],
                422
            );
        }

        $row = $this->users->findByEmail($email);
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            return Response::jsonError(
                ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid email or password.'],
                401
            );
        }

        if (!(int) $row['is_active']) {
            return Response::jsonError(
                ['code' => 'ACCOUNT_DISABLED', 'message' => 'This account has been deactivated.'],
                403
            );
        }

        $guestKey = isset($_SESSION['cart_guest_key']) && is_string($_SESSION['cart_guest_key'])
            ? $_SESSION['cart_guest_key']
            : null;

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        CsrfGuard::regenerate();
        $this->users->touchLastLogin((int) $row['id']);

        $this->safeMergeGuestCart((int) $row['id'], $guestKey);

        $fresh = $this->users->findByIdWithRole((int) $row['id']);

        return Response::jsonOk(['user' => $this->serializeUser($fresh)]);
    }

    public function logout(): Response
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();

        return Response::jsonOk(['logged_out' => true]);
    }

    public function me(): Response
    {
        $id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($id <= 0) {
            return Response::jsonOk(['user' => null]);
        }

        $row = $this->users->findByIdWithRole($id);
        if ($row === null || !(int) $row['is_active']) {
            unset($_SESSION['user_id']);

            return Response::jsonOk(['user' => null]);
        }

        return Response::jsonOk(['user' => $this->serializeUser($row)]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'role' => (string) $row['role_slug'],
            'theme_preference' => (string) ($row['theme_preference'] ?? 'system'),
        ];
    }

    private static function normalizeEmail(mixed $email): string
    {
        if (!is_string($email)) {
            return '';
        }

        return strtolower(trim($email));
    }

    private static function validateRegistration(string $email, string $password, string $first, string $last): ?string
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'A valid email address is required.';
        }
        if ($first === '') {
            return 'First name is required.';
        }
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[^\s]/', $password)) {
            return 'Password cannot be blank or whitespace only.';
        }

        return null;
    }

    private function safeMergeGuestCart(int $userId, ?string $guestKey): void
    {
        if ($guestKey === null || $guestKey === '') {
            return;
        }
        try {
            $this->carts->mergeGuestIntoUser($userId, $guestKey);
        } catch (\Throwable) {
            // Cart merge must not block authentication; user can retry by logging in again.
        }
    }
}
