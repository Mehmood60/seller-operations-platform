<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\Json\ProfileRepository;

class ProfileService
{
    public function __construct(private readonly ProfileRepository $profileRepo) {}

    /**
     * Return the first profile on disk, or a blank array when none exists.
     * Used by invoice generation which has no user-auth context.
     */
    public function getFirst(): array
    {
        return $this->profileRepo->findFirst() ?? [];
    }

    /**
     * Return the profile for a user, creating a blank one if it does not exist yet.
     */
    public function get(string $userId): array
    {
        $profile = $this->profileRepo->findByUserId($userId);

        if ($profile === null) {
            $profile = $this->blank($userId);
        }

        return $profile;
    }

    /**
     * Validate, sanitize, merge, and persist profile fields.
     * Only whitelisted keys are accepted — all values are cleaned before storage.
     *
     * @throws \InvalidArgumentException on validation failure.
     */
    public function update(string $userId, array $incoming): array
    {
        $this->validate($incoming);

        $profile = $this->get($userId);

        // ── Scalar fields ─────────────────────────────────────────────────────
        $scalarLimits = [
            'full_name'  => 100,
            'phone'      => 30,
            'avatar_url' => 500,
        ];

        foreach ($scalarLimits as $field => $maxLen) {
            if (array_key_exists($field, $incoming)) {
                $profile[$field] = $this->sanitizeString((string)($incoming[$field] ?? ''), $maxLen);
            }
        }

        // Email is validated separately (format check)
        if (array_key_exists('email', $incoming)) {
            $profile['email'] = strtolower(trim(strip_tags((string)($incoming['email'] ?? ''))));
        }

        // Avatar URL — store null for empty string
        if (array_key_exists('avatar_url', $incoming)) {
            $val = $this->sanitizeString((string)($incoming['avatar_url'] ?? ''), 500);
            $profile['avatar_url'] = $val !== '' ? $val : null;
        }

        // ── Address sub-object ────────────────────────────────────────────────
        if (array_key_exists('address', $incoming) && is_array($incoming['address'])) {
            $addrLimits = [
                'line1'       => 100,
                'line2'       => 100,
                'city'        => 85,
                'state'       => 85,
                'postal_code' => 20,
                'country'     => 2,
            ];

            foreach ($addrLimits as $field => $maxLen) {
                if (array_key_exists($field, $incoming['address'])) {
                    $profile['address'][$field] = $this->sanitizeString(
                        (string)($incoming['address'][$field] ?? ''),
                        $maxLen
                    );
                }
            }
        }

        // ── Store sub-object ──────────────────────────────────────────────────
        if (array_key_exists('store', $incoming) && is_array($incoming['store'])) {
            $storeLimits = [
                'name'          => 100,
                'phone'         => 30,
                'email'         => 254,
                'address'       => 255,
                'description'   => 1000,
                'business_name' => 100,
                'tax_number'    => 50,
                'vat_number'    => 50,
            ];

            foreach ($storeLimits as $field => $maxLen) {
                if (array_key_exists($field, $incoming['store'])) {
                    $val = $this->sanitizeString((string)($incoming['store'][$field] ?? ''), $maxLen);
                    // tax_number and vat_number: store null for empty string
                    if (in_array($field, ['tax_number', 'vat_number'], true)) {
                        $profile['store'][$field] = $val !== '' ? $val : null;
                    } else {
                        $profile['store'][$field] = $val;
                    }
                }
            }
        }

        return $this->profileRepo->save($profile);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Validate incoming data before processing.
     *
     * @throws \InvalidArgumentException
     */
    private function validate(array $data): void
    {
        // ── Personal ─────────────────────────────────────────────────────────
        if (!empty($data['full_name'])) {
            if (!preg_match('/^[\p{L}\s\'\-]+$/u', trim($data['full_name']))) {
                throw new \InvalidArgumentException(
                    'Full name may only contain letters, spaces, hyphens, and apostrophes.'
                );
            }
        }

        if (!empty($data['email'])) {
            if (!filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email address.');
            }
        }

        if (!empty($data['phone'])) {
            if (!preg_match('/^[0-9\s+\-()\[\]]+$/', trim($data['phone']))) {
                throw new \InvalidArgumentException(
                    'Phone may only contain digits and + - ( ) characters.'
                );
            }
        }

        if (!empty($data['avatar_url'])) {
            $url = trim($data['avatar_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Invalid avatar URL.');
            }
            if (!str_starts_with($url, 'https://')) {
                throw new \InvalidArgumentException('Avatar URL must use HTTPS.');
            }
        }

        // ── Address ───────────────────────────────────────────────────────────
        if (!empty($data['address'])) {
            if (!empty($data['address']['city'])) {
                if (!preg_match('/^[\p{L}\s\'\-]+$/u', trim($data['address']['city']))) {
                    throw new \InvalidArgumentException(
                        'City may only contain letters, spaces, and hyphens.'
                    );
                }
            }
            if (!empty($data['address']['state'])) {
                if (!preg_match('/^[\p{L}\s\'\-]+$/u', trim($data['address']['state']))) {
                    throw new \InvalidArgumentException(
                        'State / County may only contain letters, spaces, and hyphens.'
                    );
                }
            }
            if (!empty($data['address']['postal_code'])) {
                $pc = trim($data['address']['postal_code']);
                if (!preg_match('/^[A-Z0-9\s\-]+$/i', $pc)) {
                    throw new \InvalidArgumentException(
                        'Postal code may only contain letters, digits, spaces, and hyphens.'
                    );
                }
                if (!preg_match('/\d/', $pc)) {
                    throw new \InvalidArgumentException(
                        'Postal code must contain at least one number.'
                    );
                }
            }
            if (!empty($data['address']['country'])) {
                if (!preg_match('/^[A-Za-z]{2}$/', trim($data['address']['country']))) {
                    throw new \InvalidArgumentException(
                        'Country must be a 2-letter code (e.g. GB, US).'
                    );
                }
            }
        }

        // ── Store ─────────────────────────────────────────────────────────────
        if (!empty($data['store']['phone'])) {
            if (!preg_match('/^[0-9\s+\-()\[\]]+$/', trim($data['store']['phone']))) {
                throw new \InvalidArgumentException(
                    'Store phone may only contain digits and + - ( ) characters.'
                );
            }
        }

        if (!empty($data['store']['email'])) {
            if (!filter_var(trim($data['store']['email']), FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid store email address.');
            }
        }

        if (!empty($data['store']['tax_number'])) {
            if (!preg_match('/^[A-Z0-9\s\-\/]+$/i', trim($data['store']['tax_number']))) {
                throw new \InvalidArgumentException(
                    'Tax number may only contain letters, digits, hyphens, and slashes.'
                );
            }
        }

        if (!empty($data['store']['vat_number'])) {
            if (!preg_match('/^[A-Z0-9\s\-\/]+$/i', trim($data['store']['vat_number']))) {
                throw new \InvalidArgumentException(
                    'VAT number may only contain letters, digits, hyphens, and slashes.'
                );
            }
        }
    }

    /**
     * Trim whitespace, strip HTML tags, and enforce a maximum byte length.
     */
    private function sanitizeString(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        // mb_substr to avoid splitting multi-byte characters
        return mb_substr($value, 0, $maxLength);
    }

    private function blank(string $userId): array
    {
        return [
            'user_id'    => $userId,
            'full_name'  => '',
            'email'      => '',
            'phone'      => '',
            'address'    => [
                'line1'       => '',
                'line2'       => '',
                'city'        => '',
                'state'       => '',
                'postal_code' => '',
                'country'     => '',
            ],
            'avatar_url' => null,
            'store'      => [
                'name'          => '',
                'phone'         => '',
                'email'         => '',
                'address'       => '',
                'description'   => '',
                'business_name' => '',
                'tax_number'    => null,
                'vat_number'    => null,
            ],
        ];
    }
}
