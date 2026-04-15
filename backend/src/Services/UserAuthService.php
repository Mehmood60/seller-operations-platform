<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Response;
use App\Storage\Json\SessionRepository;
use App\Storage\Json\UserRepository;

class UserAuthService
{
    /** Session lifetime in seconds (30 days). */
    private const SESSION_TTL = 60 * 60 * 24 * 30;

    public function __construct(
        private readonly UserRepository  $userRepo,
        private readonly SessionRepository $sessionRepo,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Create a new user account.
     * Returns the safe user array (no password_hash).
     *
     * @throws \RuntimeException if email already exists or validation fails.
     */
    public function register(string $email, string $password, string $fullName): array
    {
        $email    = strtolower(trim($email));
        $fullName = trim(strip_tags($fullName));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid email address.');
        }

        if (strlen($email) > 254) {
            throw new \RuntimeException('Email address is too long.');
        }

        if (strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters.');
        }

        // bcrypt silently truncates at 72 bytes — reject longer passwords explicitly.
        if (strlen($password) > 72) {
            throw new \RuntimeException('Password must not exceed 72 characters.');
        }

        if ($fullName === '') {
            throw new \RuntimeException('Full name is required.');
        }

        if (mb_strlen($fullName) > 100) {
            throw new \RuntimeException('Full name must not exceed 100 characters.');
        }

        if ($this->userRepo->findByEmail($email) !== null) {
            throw new \RuntimeException('An account with that email already exists.');
        }

        $user = [
            'id'            => 'usr_' . bin2hex(random_bytes(8)),
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'full_name'     => $fullName,
            'role'          => 'owner',
            'status'        => 'active',
            'last_login_at' => null,
        ];

        $saved = $this->userRepo->save($user);

        return $this->safeUser($saved);
    }

    /**
     * Validate credentials and issue a session token.
     *
     * @return array{token: string, user: array}
     * @throws \RuntimeException on bad credentials.
     */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        $user = $this->userRepo->findByEmail($email);

        // Constant-time path: always run password_verify to prevent timing attacks
        $hash = $user['password_hash'] ?? '$2y$12$invalidhashpadding000000000000000000000000000000000000000';
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid) {
            throw new \RuntimeException('Invalid email or password.');
        }

        if ($user['status'] !== 'active') {
            throw new \RuntimeException('Account is inactive.');
        }

        // Update last login timestamp
        $user['last_login_at'] = date('c');
        $this->userRepo->save($user);

        // Issue session
        $token = bin2hex(random_bytes(32));
        $this->sessionRepo->save([
            'token'      => $token,
            'user_id'    => $user['id'],
            'expires_at' => date('c', time() + self::SESSION_TTL),
            'created_at' => date('c'),
        ]);

        return [
            'token' => $token,
            'user'  => $this->safeUser($user),
        ];
    }

    /**
     * Invalidate a session token.
     */
    public function logout(string $token): void
    {
        $this->sessionRepo->deleteByToken($token);
    }

    /**
     * Return the authenticated user for a token, or null if invalid/expired.
     */
    public function me(string $token): ?array
    {
        $session = $this->sessionRepo->findByToken($token);

        if ($session === null) {
            return null;
        }

        if (strtotime($session['expires_at']) < time()) {
            $this->sessionRepo->deleteByToken($token);
            return null;
        }

        $user = $this->userRepo->find($session['user_id']);

        return $user !== null ? $this->safeUser($user) : null;
    }

    /**
     * Extract bearer token from Authorization header, validate it, and return
     * the safe user array. Calls Response::error(401) and exits if invalid.
     * Use this in controllers that require authentication.
     */
    public function requireAuth(): array
    {
        $token = $this->extractBearerToken();

        if ($token === null) {
            Response::error('Authentication required.', 401);
        }

        $user = $this->me($token);

        if ($user === null) {
            Response::error('Invalid or expired session.', 401);
        }

        return $user;
    }

    /** Read the Bearer token from the Authorization header, if present. */
    public function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
            // Validate format: 64 lowercase hex chars
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                return $token;
            }
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function safeUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
