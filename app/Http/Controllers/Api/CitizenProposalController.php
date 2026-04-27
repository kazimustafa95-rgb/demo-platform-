<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CitizenProposal;
use App\Models\ProposalSupport;
use App\Models\Setting;
use App\Rules\ValidJurisdictionFocus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class CitizenProposalController extends Controller
{
    public function index(Request $request)
    {
        $query = CitizenProposal::query()
            ->with('user:id,name')
            ->where('hidden', false);

        $this->applyJurisdictionFilters($query, $request);
        $this->applyCategoryFilters($query, $request);
        $this->applyStatusFilters($query, $request);
        $this->applySearchFilter($query, $request);

        if ($request->boolean('popular')) {
            $query->orderByDesc('support_count');
        } else {
            $query->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        $proposals = $query->paginate($this->resolvePerPage($request))->withQueryString();
        $viewer = $request->user('sanctum') ?? $request->user();

        $proposals->setCollection(
            $this->decorateProposals(
                $proposals->getCollection(),
                (int) Setting::get('proposal_threshold', 5000),
                max(1, (int) Setting::get('proposal_active_days', 30)),
                $viewer?->id
            )
        );

        return response()->json($proposals);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        if (!$user->isVerifiedConstituent()) {
            return response()->json(['message' => 'You must complete constituent verification before submitting proposals.'], 403);
        }

        $input = [
            'title' => trim((string) $request->input('title')),
            'content' => filled($request->input('content')) ? trim((string) $request->input('content')) : null,
            'problem_statement' => filled($request->input('problem_statement')) ? trim((string) $request->input('problem_statement')) : null,
            'proposed_solution' => filled($request->input('proposed_solution')) ? trim((string) $request->input('proposed_solution')) : null,
            'category' => trim((string) $request->input('category')),
            'jurisdiction_focus' => $this->normalizeJurisdictionFocus($request->input('jurisdiction_focus')),
        ];

        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'content' => ['nullable', 'string', 'min:30', 'max:5000'],
            'problem_statement' => ['nullable', 'string', 'min:10', 'max:2500'],
            'proposed_solution' => ['nullable', 'string', 'min:20', 'max:2500'],
            'category' => ['required', 'string', 'min:2', 'max:100'],
            'jurisdiction_focus' => ['required', 'string', new ValidJurisdictionFocus()],
        ]);

        $validator->after(function ($validator) use ($input): void {
            $hasLegacyContent = filled($input['content']);
            $hasProblem = filled($input['problem_statement']);
            $hasSolution = filled($input['proposed_solution']);

            if (!$hasLegacyContent && !($hasProblem && $hasSolution)) {
                $message = 'Provide both problem_statement and proposed_solution, or send content.';

                $validator->errors()->add('problem_statement', $message);
                $validator->errors()->add('proposed_solution', $message);
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $contentForStorage = $this->composeProposalContent(
            $input['problem_statement'],
            $input['proposed_solution'],
            $input['content']
        );

        $rawFocus = $input['jurisdiction_focus'];
        $lowerFocus = strtolower($rawFocus);
        $focusForStorage = $rawFocus;

        $billJurisdictionFilter = function ($query) use ($lowerFocus, $rawFocus, $user): void {
            if ($lowerFocus === 'federal') {
                $query->select('id')->from('jurisdictions')->where('type', 'federal');
                return;
            }

            if ($lowerFocus === 'state') {
                $stateDistrict = strtoupper((string) ($user->state_district ?? ''));

                if (preg_match('/^([A-Z]{2})[-\s]?/', $stateDistrict, $matches)) {
                    $query->select('id')->from('jurisdictions')->where('code', $matches[1]);
                } else {
                    $query->select('id')->from('jurisdictions')->whereRaw('1 = 0');
                }

                return;
            }

            $query->select('id')->from('jurisdictions')->where('code', strtoupper($rawFocus));
        };

        if (preg_match('/^[A-Za-z]{2}$/', $rawFocus)) {
            $focusForStorage = strtoupper($rawFocus);
        } elseif (in_array($lowerFocus, ['federal', 'state'], true)) {
            $focusForStorage = $lowerFocus;
        }

        $duplicateThreshold = (int) Setting::get('duplicate_threshold', 90);
        $bills = Bill::whereIn('jurisdiction_id', $billJurisdictionFilter)->get();

        foreach ($bills as $bill) {
            $referenceText = trim(($bill->title ?? '') . ' ' . ($bill->summary ?? ''));
            $similar = similar_text($contentForStorage, $referenceText, $percent);

            if ($percent >= $duplicateThreshold) {
                return response()->json([
                    'error' => 'This proposal appears very similar to an existing bill. Consider submitting an Amendment Proposal instead.',
                    'bill' => $bill,
                ], 422);
            }
        }

        $proposal = CitizenProposal::create([
            'user_id' => $user->id,
            'title' => $input['title'],
            'content' => $contentForStorage,
            'problem_statement' => $input['problem_statement'],
            'proposed_solution' => $input['proposed_solution'],
            'category' => $input['category'],
            'jurisdiction_focus' => $focusForStorage,
            'support_count' => 0,
        ]);

        $proposal->load('user:id,name');
        $this->decorateProposals(
            collect([$proposal]),
            (int) Setting::get('proposal_threshold', 5000),
            max(1, (int) Setting::get('proposal_active_days', 30)),
            $user->id
        );

        return response()->json(['message' => 'Proposal submitted.', 'proposal' => $proposal], 201);
    }

    public function show(CitizenProposal $proposal)
    {
        $proposal->load('user:id,name');
        $viewerId = auth()->id();
        $userSupported = auth()->check() ? $proposal->supports()->where('user_id', $viewerId)->exists() : false;
        $this->decorateProposals(
            collect([$proposal]),
            (int) Setting::get('proposal_threshold', 5000),
            max(1, (int) Setting::get('proposal_active_days', 30)),
            $viewerId
        );

        return response()->json([
            'proposal' => $proposal,
            'user_supported' => $userSupported,
        ]);
    }

    public function support(Request $request, CitizenProposal $proposal)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        if (!$user->isVerifiedConstituent()) {
            return response()->json(['message' => 'You must complete constituent verification before supporting proposals.'], 403);
        }

        if ($proposal->supports()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Already supported.'], 400);
        }

        $proposal->supports()->create(['user_id' => $user->id]);
        $proposal->increment('support_count');

        $threshold = (int) Setting::get('proposal_threshold', 5000);
        if ($proposal->support_count >= $threshold && !$proposal->threshold_reached) {
            $proposal->update([
                'threshold_reached' => true,
                'threshold_reached_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Proposal supported.', 'support_count' => $proposal->support_count]);
    }

    public function unsupport(Request $request, CitizenProposal $proposal)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        $deleted = $proposal->supports()->where('user_id', $user->id)->delete();

        if ($deleted && $proposal->support_count > 0) {
            $proposal->decrement('support_count');
        }

        $proposal->refresh();

        return response()->json(['message' => 'Support removed.', 'support_count' => $proposal->support_count]);
    }

    private function normalizeJurisdictionFocus(mixed $value): string
    {
        $focus = trim((string) $value);

        if ($focus === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]{2}$/', $focus)) {
            return strtoupper($focus);
        }

        return strtolower($focus);
    }

    private function applyJurisdictionFilters(Builder $query, Request $request): void
    {
        $focus = $this->normalizeJurisdictionFocus(
            $request->input('jurisdiction_focus', $request->input('jurisdiction', ''))
        );
        $jurisdictionType = strtolower(trim((string) $request->input('jurisdiction_type', $request->input('type', $request->input('tab')))));

        if ($focus !== '') {
            if ($focus === 'federal') {
                $query->whereRaw('LOWER(jurisdiction_focus) = ?', ['federal']);
                return;
            }

            if ($focus === 'state') {
                $query->where(function (Builder $stateQuery): void {
                    $stateQuery->whereRaw('LOWER(jurisdiction_focus) = ?', ['state'])
                        ->orWhereRaw('LENGTH(jurisdiction_focus) = 2');
                });

                return;
            }

            $query->whereRaw('UPPER(jurisdiction_focus) = ?', [strtoupper($focus)]);

            return;
        }

        if ($jurisdictionType === 'federal') {
            $query->whereRaw('LOWER(jurisdiction_focus) = ?', ['federal']);
        }

        if ($jurisdictionType === 'state') {
            $query->where(function (Builder $stateQuery): void {
                $stateQuery->whereRaw('LOWER(jurisdiction_focus) = ?', ['state'])
                    ->orWhereRaw('LENGTH(jurisdiction_focus) = 2');
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

        $cutoff = now()->subDays(max(1, (int) Setting::get('proposal_active_days', 30)));

        $query->where(function (Builder $statusQuery) use ($statuses, $cutoff): void {
            foreach ($statuses as $status) {
                match ($status) {
                    'passed' => $statusQuery->orWhere('threshold_reached', true),
                    'failed' => $statusQuery->orWhere(function (Builder $failedQuery) use ($cutoff): void {
                        $failedQuery->where('threshold_reached', false)
                            ->where('created_at', '<=', $cutoff);
                    }),
                    'active' => $statusQuery->orWhere(function (Builder $activeQuery) use ($cutoff): void {
                        $activeQuery->where('threshold_reached', false)
                            ->where('created_at', '>', $cutoff);
                    }),
                };
            }
        });
    }

    private function applySearchFilter(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search', $request->input('q', $request->input('query', ''))));

        if ($search === '') {
            return;
        }

        $like = '%' . mb_strtolower($search) . '%';

        $query->where(function (Builder $searchQuery) use ($like): void {
            $searchQuery->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(content, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(problem_statement, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(proposed_solution, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(category) LIKE ?', [$like]);
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

    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 20);

        return max(1, min($perPage, 100));
    }

    private function decorateProposals(Collection $proposals, int $supportThreshold, int $activeDays, ?int $viewerId = null): Collection
    {
        $supportedIds = collect();

        if ($viewerId !== null && $proposals->isNotEmpty()) {
            $supportedIds = ProposalSupport::query()
                ->where('user_id', $viewerId)
                ->whereIn('citizen_proposal_id', $proposals->pluck('id'))
                ->pluck('citizen_proposal_id')
                ->flip();
        }

        return $proposals->map(function (CitizenProposal $proposal) use ($supportThreshold, $activeDays, $viewerId, $supportedIds) {
            $expiresAt = $proposal->created_at?->copy()->addDays($activeDays);
            $daysLeft = 0;

            if ($expiresAt instanceof Carbon) {
                $daysLeft = max(0, now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false));
            }

            $proposal->setAttribute('support_threshold', $supportThreshold);
            $proposal->setAttribute('status', $this->resolveStatus($proposal, $activeDays));
            $proposal->setAttribute('jurisdiction_type', $this->resolveJurisdictionType($proposal->jurisdiction_focus));
            $proposal->setAttribute('expires_at', $expiresAt?->toISOString());
            $proposal->setAttribute('days_left', $daysLeft);
            $proposal->setAttribute('user_supported', $viewerId !== null ? $supportedIds->has($proposal->id) : false);

            return $proposal;
        });
    }

    private function resolveStatus(CitizenProposal $proposal, int $activeDays): string
    {
        if ($proposal->threshold_reached) {
            return 'passed';
        }

        $expiresAt = $proposal->created_at?->copy()->addDays($activeDays);

        if ($expiresAt instanceof Carbon && now()->gte($expiresAt)) {
            return 'failed';
        }

        return 'active';
    }

    private function resolveJurisdictionType(?string $focus): string
    {
        $focus = strtolower(trim((string) $focus));

        return $focus === 'federal' ? 'federal' : 'state';
    }

    private function composeProposalContent(?string $problemStatement, ?string $proposedSolution, ?string $legacyContent): string
    {
        if (filled($problemStatement) && filled($proposedSolution)) {
            return trim("Problem: {$problemStatement}\n\nSolution: {$proposedSolution}");
        }

        return (string) $legacyContent;
    }
}
