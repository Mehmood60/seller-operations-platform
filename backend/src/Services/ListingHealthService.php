<?php

declare(strict_types=1);

namespace App\Services;

class ListingHealthService
{
    // ── Score dimensions (total = 100 pts) ───────────────────────────────────
    private const DIMS = [
        'title'      => 20,
        'images'     => 20,
        'specifics'  => 20,
        'description'=> 15,
        'category'   => 15,
        'source_url' => 10,
    ];

    public function score(array $listing): array
    {
        $issues = [];
        $pts    = 0;

        // ── Title (20 pts) ────────────────────────────────────────────────────
        $titleLen = mb_strlen(strip_tags($listing['title'] ?? ''));
        if ($titleLen === 0) {
            $issues[] = $this->issue('title', 'high', 'Title is missing — listings without a title cannot be found in search.', 'title');
        } elseif ($titleLen < 30) {
            $pts += 5;
            $issues[] = $this->issue('title', 'high', "Title too short ({$titleLen} chars) — aim for 50–80 characters with relevant keywords.", 'title');
        } elseif ($titleLen < 50) {
            $pts += 12;
            $issues[] = $this->issue('title', 'medium', "Title a bit short ({$titleLen} chars) — 50–80 characters improves search visibility.", 'title');
        } else {
            $pts += self::DIMS['title'];
        }

        // ── Images (20 pts) ───────────────────────────────────────────────────
        $imageCount = count(array_filter($listing['images'] ?? [], fn($i) => is_string($i) && $i !== ''));
        if ($imageCount === 0) {
            $issues[] = $this->issue('images', 'high', 'No images — listings without photos receive significantly fewer clicks.', 'images');
        } elseif ($imageCount < 2) {
            $pts += 5;
            $issues[] = $this->issue('images', 'high', 'Only 1 image — add at least 3–4 product photos to improve conversion.', 'images');
        } elseif ($imageCount < 4) {
            $pts += 12;
            $issues[] = $this->issue('images', 'medium', "{$imageCount} images — 4 or more product photos increase conversion rate.", 'images');
        } else {
            $pts += self::DIMS['images'];
        }

        // ── Item Specifics (20 pts) ───────────────────────────────────────────
        $specifics = $listing['item_specifics'] ?? [];
        $filledCount = 0;
        if (is_array($specifics)) {
            foreach ($specifics as $k => $v) {
                if ((string)$k !== '' && (string)$v !== '') {
                    $filledCount++;
                }
            }
        }
        if ($filledCount === 0) {
            $issues[] = $this->issue('specifics', 'high', 'Item specifics missing — eBay buyers frequently filter by item attributes.', 'specifics');
        } elseif ($filledCount < 3) {
            $pts += 7;
            $issues[] = $this->issue('specifics', 'medium', "Only {$filledCount} item specific(s) — 5+ attributes improve search ranking.", 'specifics');
        } elseif ($filledCount < 5) {
            $pts += 14;
            $issues[] = $this->issue('specifics', 'low', "{$filledCount} item specifics — adding more attributes helps buyers filter.", 'specifics');
        } else {
            $pts += self::DIMS['specifics'];
        }

        // ── Description (15 pts) ──────────────────────────────────────────────
        $descLen = mb_strlen(strip_tags($listing['description'] ?? ''));
        if ($descLen === 0) {
            $issues[] = $this->issue('description', 'high', 'Description missing — buyers expect a detailed product description.', 'description');
        } elseif ($descLen < 100) {
            $pts += 4;
            $issues[] = $this->issue('description', 'medium', "Description too short ({$descLen} chars) — 300+ characters builds buyer trust.", 'description');
        } elseif ($descLen < 300) {
            $pts += 9;
            $issues[] = $this->issue('description', 'low', "Description could be longer ({$descLen} chars) — more detail helps conversions.", 'description');
        } else {
            $pts += self::DIMS['description'];
        }

        // ── Category (15 pts) ─────────────────────────────────────────────────
        $catId = trim((string)($listing['category']['ebay_category_id'] ?? ''));
        if ($catId === '' || $catId === '0') {
            $issues[] = $this->issue('category', 'high', 'No eBay category set — listing cannot be published without a category.', 'category');
        } else {
            $pts += self::DIMS['category'];
        }

        // ── Source URL (10 pts) ───────────────────────────────────────────────
        $sourceUrl = trim((string)($listing['source_url'] ?? ''));
        if ($sourceUrl === '') {
            $issues[] = $this->issue('source_url', 'low', 'No source URL — price monitoring cannot be enabled without a supplier URL.', 'source_url');
        } else {
            $pts += self::DIMS['source_url'];
        }

        $grade = $this->toGrade($pts);

        return [
            'score'  => $pts,
            'grade'  => $grade,
            'issues' => $issues,
            'dims'   => [
                'title'       => $this->dimScore('title',       $listing),
                'images'      => $this->dimScore('images',      $listing),
                'specifics'   => $this->dimScore('specifics',   $listing),
                'description' => $this->dimScore('description', $listing),
                'category'    => $this->dimScore('category',    $listing),
                'source_url'  => $this->dimScore('source_url',  $listing),
            ],
        ];
    }

    private function toGrade(int $pts): string
    {
        if ($pts >= 80) return 'A';
        if ($pts >= 60) return 'B';
        if ($pts >= 40) return 'C';
        if ($pts >= 20) return 'D';
        return 'F';
    }

    private function issue(string $field, string $priority, string $message, string $action): array
    {
        return compact('field', 'priority', 'message', 'action');
    }

    private function dimScore(string $dim, array $listing): int
    {
        return match ($dim) {
            'title' => (function () use ($listing): int {
                $l = mb_strlen(strip_tags($listing['title'] ?? ''));
                if ($l >= 50) return 20;
                if ($l >= 30) return 12;
                if ($l > 0)   return 5;
                return 0;
            })(),
            'images' => (function () use ($listing): int {
                $c = count(array_filter($listing['images'] ?? [], fn($i) => is_string($i) && $i !== ''));
                if ($c >= 4) return 20;
                if ($c >= 2) return 12;
                if ($c === 1) return 5;
                return 0;
            })(),
            'specifics' => (function () use ($listing): int {
                $n = 0;
                foreach (($listing['item_specifics'] ?? []) as $k => $v) {
                    if ((string)$k !== '' && (string)$v !== '') $n++;
                }
                if ($n >= 5) return 20;
                if ($n >= 3) return 14;
                if ($n >= 1) return 7;
                return 0;
            })(),
            'description' => (function () use ($listing): int {
                $l = mb_strlen(strip_tags($listing['description'] ?? ''));
                if ($l >= 300) return 15;
                if ($l >= 100) return 9;
                if ($l > 0)    return 4;
                return 0;
            })(),
            'category' => (function () use ($listing): int {
                $id = trim((string)($listing['category']['ebay_category_id'] ?? ''));
                return ($id !== '' && $id !== '0') ? 15 : 0;
            })(),
            'source_url' => (function () use ($listing): int {
                return trim((string)($listing['source_url'] ?? '')) !== '' ? 10 : 0;
            })(),
            default => 0,
        };
    }
}
