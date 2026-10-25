<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 (P2/P3) - the small pieces every staff mobile-rentals endpoint shares: the list envelope
 * `{data:[...], meta:{page,per_page,total,last_page,empty_reason}}`, the date-range filter, the one `{"message": ...}` error shape
 * (with a machine-readable `code` where the app must react), absolute image URLs and the LIKE-escape for the search box.
 * .ai/specs/at-508-mobile-rentals.md sections 5 and 6.
 */
trait FormatsMobileRentalResponses
{
    /** @param list<array<string,mixed>> $rows */
    protected function listResponse(array $rows, LengthAwarePaginator $page, string $emptyReason): JsonResponse
    {
        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'empty_reason' => $rows === [] ? $emptyReason : null,
            ],
        ]);
    }

    /** Inclusive `date_from` / `date_to` on one column (a bare date as the end means the whole day). */
    protected function applyDateRange($query, Request $request, string $column): void
    {
        if ($from = $request->input('date_from')) {
            $query->where($column, '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->where($column, '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to) ? $to . ' 23:59:59' : $to);
        }
    }

    protected function message(string $message, int $status, ?string $code = null): JsonResponse
    {
        return response()->json(array_filter(['message' => $message, 'code' => $code], fn ($v) => $v !== null), $status);
    }

    /** A queued write for a record that has been archived meanwhile: tell the app to drop it (spec section 5.5). */
    protected function archivedResponse(string $what): JsonResponse
    {
        return $this->message("This {$what} has been archived, so nothing more can be added to it.", 410, 'archived');
    }

    protected function isArchived(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
    }

    /** Every image URL the API returns is absolute (spec rental-images.md 7a), so the device can load it. */
    protected static function absoluteUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $url)) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }

    /** A search term safe to put between `%` in a LIKE (the user's own % and _ are not wildcards). */
    protected static function likeTerm(string $term): string
    {
        return '%' . addcslashes(trim($term), '%_\\') . '%';
    }

    protected function pageNumber(Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }
}
