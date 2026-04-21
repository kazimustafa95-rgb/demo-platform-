<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CitizenProposal;
use App\Models\Setting;
use App\Rules\ValidJurisdictionFocus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CitizenProposalController extends Controller
{
    public function index(Request $request)
    {
        $query = CitizenProposal::with('user')->where('hidden', false);

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('jurisdiction_focus')) {
            $query->where('jurisdiction_focus', $request->jurisdiction_focus);
        }

        if ($request->has('popular')) {
            $query->orderBy('support_count', 'desc');
        }

        return response()->json($query->paginate(20));
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
            'content' => trim((string) $request->input('content')),
            'category' => trim((string) $request->input('category')),
            'jurisdiction_focus' => $this->normalizeJurisdictionFocus($request->input('jurisdiction_focus')),
        ];

        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'content' => ['required', 'string', 'min:30', 'max:5000'],
            'category' => ['required', 'string', 'min:2', 'max:100'],
            'jurisdiction_focus' => ['required', 'string', new ValidJurisdictionFocus()],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

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
            $similar = similar_text($input['content'], $referenceText, $percent);

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
            'content' => $input['content'],
            'category' => $input['category'],
            'jurisdiction_focus' => $focusForStorage,
            'support_count' => 0,
        ]);

        return response()->json(['message' => 'Proposal submitted.', 'proposal' => $proposal], 201);
    }

    public function show(CitizenProposal $proposal)
    {
        $proposal->load('user');
        $userSupported = auth()->check() ? $proposal->supports()->where('user_id', auth()->id())->exists() : false;

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
}
