<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\AmendmentSupport;
use App\Models\Bill;
use App\Models\Setting;
use App\Rules\WordCountBetween;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class AmendmentController extends Controller
{
    public function all(Request $request)
    {
        $query = Amendment::query()
            ->with(['user:id,name', 'bill.jurisdiction'])
            ->userGenerated()
            ->where('hidden', false);

        $this->applyJurisdictionFilters($query, $request);
        $this->applyCategoryFilters($query, $request);
        $this->applyStatusFilters($query, $request);

        if ($request->boolean('popular')) {
            $query->orderByDesc('support_count');
        } else {
            $query->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        $amendments = $query->paginate($this->resolvePerPage($request))->withQueryString();
        $viewer = $request->user('sanctum') ?? $request->user();

        $amendments->setCollection(
            $this->decorateAmendments(
                $amendments->getCollection(),
                (int) Setting::get('amendment_threshold', 1000),
                $viewer?->id
            )
        );

        return response()->json($amendments);
    }

    public function index(Request $request, Bill $bill)
    {
        $query = $bill->amendments()
            ->with(['user:id,name', 'bill.jurisdiction'])
            ->userGenerated()
            ->where('hidden', false);

        $this->applyCategoryFilters($query, $request);
        $this->applyStatusFilters($query, $request);

        if ($request->boolean('popular')) {
            $query->orderByDesc('support_count');
        } else {
            $query->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        $amendments = $query->paginate($this->resolvePerPage($request))->withQueryString();
        $viewer = $request->user('sanctum') ?? $request->user();

        $amendments->setCollection(
            $this->decorateAmendments(
                $amendments->getCollection(),
                (int) Setting::get('amendment_threshold', 1000),
                $viewer?->id
            )
        );

        return response()->json($amendments);
    }

    public function store(Request $request, Bill $bill)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        if (!$user->isVerifiedConstituent()) {
            return response()->json(['message' => 'You must complete constituent verification before proposing amendments.'], 403);
        }

        if ($bill->official_vote_date && now()->gte($bill->official_vote_date)) {
            return response()->json(['message' => 'This bill has already been voted on; amendments are closed.'], 403);
        }

        $input = [
            'title' => trim((string) $request->input('title')),
            'amendment_text' => trim((string) $request->input('amendment_text')),
            'category' => trim((string) $request->input('category')),
        ];

        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'amendment_text' => ['required', 'string', 'max:5000', new WordCountBetween(50, 70)],
            'category' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $amendment = $bill->amendments()->create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $user->id,
            'title' => $input['title'],
            'amendment_text' => $input['amendment_text'],
            'category' => $input['category'],
            'submitted_at' => now(),
            'support_count' => 0,
        ]);

        $amendment->load('user:id,name');

        return response()->json(['message' => 'Amendment proposed.', 'amendment' => $amendment], 201);
    }

    public function show(Amendment $amendment)
    {
        if ($amendment->source !== Amendment::SOURCE_USER) {
            return response()->json(['message' => 'Amendment not found.'], 404);
        }

        $amendment->load('user:id,name', 'bill.jurisdiction');
        $viewerId = auth()->id();
        $userSupported = auth()->check() ? $amendment->supports()->where('user_id', $viewerId)->exists() : false;
        $this->decorateAmendments(
            collect([$amendment]),
            (int) Setting::get('amendment_threshold', 1000),
            $viewerId
        );

        return response()->json([
            'amendment' => $amendment,
            'user_supported' => $userSupported,
        ]);
    }

    public function support(Request $request, Amendment $amendment)
    {
        if ($amendment->source !== Amendment::SOURCE_USER) {
            return response()->json(['message' => 'Only citizen amendments can be supported.'], 403);
        }

        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        if (!$user->isVerifiedConstituent()) {
            return response()->json(['message' => 'You must complete constituent verification before supporting amendments.'], 403);
        }

        if ($amendment->supports()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Already supported.'], 400);
        }

        $amendment->supports()->create(['user_id' => $user->id]);
        $amendment->increment('support_count');

        $threshold = (int) Setting::get('amendment_threshold', 1000);
        if ($amendment->support_count >= $threshold && !$amendment->threshold_reached) {
            $amendment->update([
                'threshold_reached' => true,
                'threshold_reached_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Amendment supported.', 'support_count' => $amendment->support_count]);
    }

    public function unsupport(Request $request, Amendment $amendment)
    {
        if ($amendment->source !== Amendment::SOURCE_USER) {
            return response()->json(['message' => 'Only citizen amendments can be unsupported.'], 403);
        }

        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        $deleted = $amendment->supports()->where('user_id', $user->id)->delete();

        if ($deleted && $amendment->support_count > 0) {
            $amendment->decrement('support_count');
        }

        $amendment->refresh();

        return response()->json(['message' => 'Support removed.', 'support_count' => $amendment->support_count]);
    }

    private function applyJurisdictionFilters(Builder $query, Request $request): void
    {
        $jurisdiction = trim((string) $request->input('jurisdiction', ''));
        $jurisdictionType = $this->normalizeJurisdictionType(
            $request->input('jurisdiction_type', $request->input('type', $request->input('scope', $request->input('tab'))))
        );

        if ($jurisdiction !== '') {
            $normalizedJurisdiction = strtolower($jurisdiction);

            if (in_array($normalizedJurisdiction, ['federal', 'state'], true)) {
                $jurisdictionType ??= $normalizedJurisdiction;
            } else {
                $query->whereHas('bill.jurisdiction', function (Builder $jurisdictionQuery) use ($jurisdiction): void {
                    $jurisdictionQuery->whereRaw('UPPER(code) = ?', [strtoupper($jurisdiction)]);
                });
            }
        }

        if ($jurisdictionType !== null) {
            $query->whereHas('bill.jurisdiction', function (Builder $jurisdictionQuery) use ($jurisdictionType): void {
                $jurisdictionQuery->where('type', $jurisdictionType);
            });
        }
    }

    private function applyCategoryFilters(Builder $query, Request $request): void
    {
        $categories = $this->parseFilterValues($request->input('category', $request->input('categories', [])));

        if ($categories === []) {
            return;
        }

        $query->where(function (Builder $categoryQuery) use ($categories): void {
            foreach ($categories as $category) {
                $categoryQuery->orWhereRaw('LOWER(category) = ?', [mb_strtolower($category)]);
            }
        });
    }

    private function applyStatusFilters(Builder $query, Request $request): void
    {
        $statuses = array_values(array_intersect(
            $this->parseFilterValues($request->input('status', $request->input('statuses', []))),
            ['active', 'passed', 'failed']
        ));

        if ($statuses === []) {
            return;
        }

        $query->where(function (Builder $statusQuery) use ($statuses): void {
            foreach ($statuses as $status) {
                match ($status) {
                    'passed' => $statusQuery->orWhere('threshold_reached', true),
                    'failed' => $statusQuery->orWhere(function (Builder $failedQuery): void {
                        $failedQuery->where('threshold_reached', false)
                            ->whereHas('bill', function (Builder $billQuery): void {
                                $billQuery->whereNotNull('official_vote_date')
                                    ->where('official_vote_date', '<=', now());
                            });
                    }),
                    'active' => $statusQuery->orWhere(function (Builder $activeQuery): void {
                        $activeQuery->where('threshold_reached', false)
                            ->whereHas('bill', function (Builder $billQuery): void {
                                $billQuery->where(function (Builder $eligibleBillQuery): void {
                                    $eligibleBillQuery->whereNull('official_vote_date')
                                        ->orWhere('official_vote_date', '>', now());
                                });
                            });
                    }),
                };
            }
        });
    }

    private function parseFilterValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $parsed = [];

        foreach ($values as $candidate) {
            if (is_array($candidate)) {
                foreach ($this->parseFilterValues($candidate) as $nestedValue) {
                    $parsed[] = $nestedValue;
                }

                continue;
            }

            foreach (explode(',', (string) $candidate) as $segment) {
                $segment = strtolower(trim($segment));

                if ($segment !== '') {
                    $parsed[] = $segment;
                }
            }
        }

        return array_values(array_unique($parsed));
    }

    private function normalizeJurisdictionType(mixed $value): ?string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['federal', 'state'], true) ? $type : null;
    }

    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 20);

        return max(1, min($perPage, 100));
    }

    private function decorateAmendments(Collection $amendments, int $supportThreshold, ?int $viewerId = null): Collection
    {
        $supportedIds = collect();

        if ($viewerId !== null && $amendments->isNotEmpty()) {
            $supportedIds = AmendmentSupport::query()
                ->where('user_id', $viewerId)
                ->whereIn('amendment_id', $amendments->pluck('id'))
                ->pluck('amendment_id')
                ->flip();
        }

        return $amendments->map(function (Amendment $amendment) use ($supportThreshold, $viewerId, $supportedIds) {
            $amendment->setAttribute('support_threshold', $supportThreshold);
            $amendment->setAttribute('status', $this->resolveStatus($amendment));
            $amendment->setAttribute('user_supported', $viewerId !== null ? $supportedIds->has($amendment->id) : false);

            return $amendment;
        });
    }

    private function resolveStatus(Amendment $amendment): string
    {
        if ($amendment->threshold_reached) {
            return 'passed';
        }

        $officialVoteDate = $amendment->bill?->official_vote_date;

        if ($officialVoteDate && now()->gte($officialVoteDate)) {
            return 'failed';
        }

        return 'active';
    }
}
