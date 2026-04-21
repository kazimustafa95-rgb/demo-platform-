<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\Bill;
use App\Models\Setting;
use App\Rules\WordCountBetween;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AmendmentController extends Controller
{
    public function index(Request $request, Bill $bill)
    {
        $query = $bill->amendments()
            ->userGenerated()
            ->where('hidden', false);

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('popular')) {
            $query->orderBy('support_count', 'desc');
        }

        return response()->json($query->paginate(20));
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
            'amendment_text' => trim((string) $request->input('amendment_text')),
            'category' => trim((string) $request->input('category')),
        ];

        $validator = Validator::make($input, [
            'amendment_text' => ['required', 'string', 'max:5000', new WordCountBetween(50, 70)],
            'category' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $amendment = $bill->amendments()->create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $user->id,
            'amendment_text' => $input['amendment_text'],
            'category' => $input['category'],
            'support_count' => 0,
        ]);

        return response()->json(['message' => 'Amendment proposed.', 'amendment' => $amendment], 201);
    }

    public function show(Amendment $amendment)
    {
        if ($amendment->source !== Amendment::SOURCE_USER) {
            return response()->json(['message' => 'Amendment not found.'], 404);
        }

        $amendment->load('user', 'bill');
        $userSupported = auth()->check() ? $amendment->supports()->where('user_id', auth()->id())->exists() : false;

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
}
