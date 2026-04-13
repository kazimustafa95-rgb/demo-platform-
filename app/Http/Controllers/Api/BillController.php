<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\Bill;
use App\Models\UserVote;
use Illuminate\Http\Request;
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
        
        if (!$user->email_verified_at) {
            return response()->json(['message' => 'You must be verified to vote.'], 403);
        }
        
        if (!$bill->isVotingOpen()) {
            return response()->json(['message' => 'Voting is closed for this bill.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'vote' => 'required|in:in_favor,against,abstain',
            'comment' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $words = str_word_count(trim((string) $value));

                    if ($words > 50) {
                        $fail('Comment cannot exceed 50 words.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $userVote = UserVote::updateOrCreate(
            ['user_id' => $user->id, 'bill_id' => $bill->id],
            ['vote' => $request->vote, 'comment' => $request->comment]
        );

        return response()->json(['message' => 'Vote recorded.', 'vote' => $userVote]);
    }

    public function deleteVote(Request $request, Bill $bill)
    {
        $user = $request->user();

        if (!$bill->isVotingOpen()) {
            return response()->json(['message' => 'Voting is closed for this bill.'], 403);
        }

        $userVote = UserVote::where('user_id', $user->id)->where('bill_id', $bill->id)->first();

        if ($userVote) {
            $userVote->delete();
        }

        return response()->json(['message' => 'Vote removed.']);
    }
}
