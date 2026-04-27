<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Setting;
use App\Models\UserVote;
use App\Services\BillAiContentService;
use App\Rules\WordCountBetween;
use App\Services\BillInsightsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BillController extends Controller
{
    public function index(Request $request, BillInsightsService $billInsightsService)
    {
        $query = Bill::query()->with('jurisdiction');

        $this->applyJurisdictionFilters($query, $request);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('deadline')) {
            $query->whereNotNull('voting_deadline')
                ->where('voting_deadline', '>', now());
        }

        $this->applySearchFilter($query, $request);

        $bills = $query->paginate(20);
        $viewer = $request->user('sanctum') ?? $request->user();

        $bills->setCollection(
            $billInsightsService->attachCardStatsToCollection($bills->getCollection(), $viewer)
        );

        return response()->json($bills);
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
                $query->whereHas('jurisdiction', function (Builder $jurisdictionQuery) use ($jurisdiction): void {
                    $jurisdictionQuery->whereRaw('UPPER(code) = ?', [strtoupper($jurisdiction)]);
                });
            }
        }

        if ($jurisdictionType !== null) {
            $query->whereHas('jurisdiction', function (Builder $jurisdictionQuery) use ($jurisdictionType): void {
                $jurisdictionQuery->where('type', $jurisdictionType);
            });
        }
    }

    private function applySearchFilter(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search', $request->input('q', $request->input('query', ''))));

        if ($search === '') {
            return;
        }

        $like = '%' . mb_strtolower($search) . '%';
        $sponsorsExpression = $this->jsonColumnAsTextExpression($query, 'sponsors');

        $query->where(function (Builder $searchQuery) use ($like, $sponsorsExpression): void {
            $searchQuery->whereRaw('LOWER(number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(summary, \'\')) LIKE ?', [$like])
                ->orWhereRaw("LOWER(COALESCE({$sponsorsExpression}, '')) LIKE ?", [$like]);
        });
    }

    private function jsonColumnAsTextExpression(Builder $query, string $column): string
    {
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST({$column} AS CHAR)",
            default => "CAST({$column} AS TEXT)",
        };
    }

    private function normalizeJurisdictionType(mixed $value): ?string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['federal', 'state'], true) ? $type : null;
    }

    public function show(Bill $bill, BillAiContentService $billAiContentService)
    {
        $amendmentSupportThreshold = (int) Setting::get('amendment_threshold', 1000);

        $bill->load([
            'jurisdiction',
            'amendments' => function ($q) {
                $q->userGenerated()
                    ->with('user:id,name')
                    ->orderBy('support_count', 'desc')
                    ->limit(3);
            },
        ]);

        $bill->setRelation(
            'amendments',
            $bill->amendments->map(function ($amendment) use ($amendmentSupportThreshold) {
                $amendment->setAttribute('support_threshold', $amendmentSupportThreshold);

                return $amendment;
            })
        );

        if ($billAiContentService->isConfigured() && $billAiContentService->needsGeneration($bill)) {
            try {
                $billAiContentService->generateAndStore($bill);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $bill->makeVisible([
            'ai_summary_plain',
            'ai_bill_impact',
        ]);

        $userVote = auth()->check()
            ? $bill->userVotes()->where('user_id', auth()->id())->first()
            : null;

        $voteTotals = [
            'in_favor' => $bill->userVotes()->where('vote', 'in_favor')->count(),
            'against' => $bill->userVotes()->where('vote', 'against')->count(),
            'abstain' => $bill->userVotes()->where('vote', 'abstain')->count(),
        ];

        $totalVotes = array_sum($voteTotals);
        $percentages = $totalVotes > 0 ? [
            'in_favor' => round($voteTotals['in_favor'] / $totalVotes * 100, 2),
            'against' => round($voteTotals['against'] / $totalVotes * 100, 2),
            'abstain' => round($voteTotals['abstain'] / $totalVotes * 100, 2),
        ] : null;

        $repVotes = $bill->votes()->with('representative')->get();

        return response()->json([
            'bill' => $bill,
            'user_vote' => $userVote,
            'constituent_totals' => $voteTotals,
            'constituent_percentages' => $percentages,
            'representative_votes' => $repVotes,
            'voting_open' => $bill->isVotingOpen(),
        ]);
    }

    public function vote(Request $request, Bill $bill)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        if (!$user->isVerifiedConstituent()) {
            return response()->json(['message' => 'You must complete constituent verification before voting.'], 403);
        }

        if (!$bill->isVotingOpen()) {
            return response()->json(['message' => 'Voting is closed for this bill.'], 403);
        }

        $input = [
            'vote' => trim((string) $request->input('vote')),
            'comment' => filled($request->input('comment')) ? trim((string) $request->input('comment')) : null,
        ];

        $validator = Validator::make($input, [
            'vote' => ['required', Rule::in(['in_favor', 'against', 'abstain'])],
            'comment' => ['nullable', 'string', 'max:1000', new WordCountBetween(0, 50, allowBlank: true)],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $userVote = UserVote::updateOrCreate(
            ['user_id' => $user->id, 'bill_id' => $bill->id],
            ['vote' => $input['vote'], 'comment' => $input['comment']]
        );

        return response()->json(['message' => 'Vote recorded.', 'vote' => $userVote]);
    }

    public function deleteVote(Request $request, Bill $bill)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        if (!$bill->isVotingOpen()) {
            return response()->json(['message' => 'Voting is closed for this bill.'], 403);
        }

        $userVote = UserVote::where('user_id', $user->id)->where('bill_id', $bill->id)->first();

        if ($userVote) {
            $userVote->delete();
        }

        return response()->json(['message' => 'Vote removed.']);
    }

    public function insights(Request $request, Bill $bill, BillInsightsService $billInsightsService)
    {
        $user = $request->user();

        if (!$user->hasCompletedLocation()) {
            return response()->json([
                'message' => 'Complete location verification before viewing district insights.',
            ], 422);
        }

        return response()->json($billInsightsService->build($bill, $user));
    }
}
