<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\Bill;
use App\Models\UserVote;
use App\Rules\WordCountBetween;
use App\Services\BillInsightsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class BillController extends Controller
{
    public function index(Request $request)
    {
        $query = Bill::with('jurisdiction');

        if ($request->has('jurisdiction')) {
            $query->whereHas('jurisdiction', function ($q) use ($request) {
                $q->where('code', $request->jurisdiction);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('deadline')) {
            $query->whereNotNull('voting_deadline')
                ->where('voting_deadline', '>', now());
        }

        $bills = $query->paginate(20);

        return response()->json($bills);
    }

    public function show(Bill $bill)
    {
        $bill->load([
            'jurisdiction',
            'amendments' => function ($q) {
                $q->userGenerated()
                    ->orderBy('support_count', 'desc')
                    ->limit(3);
            },
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
