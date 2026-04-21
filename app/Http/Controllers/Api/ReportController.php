<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\CitizenProposal;
use App\Models\Report;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended from participation.',
                'suspension' => $user->suspensionDetails(),
            ], 423);
        }

        $input = [
            'reportable_type' => strtolower(trim((string) $request->input('reportable_type'))),
            'reportable_id' => $request->input('reportable_id'),
            'reason' => strtolower(trim((string) $request->input('reason'))),
            'description' => filled($request->input('description')) ? trim((string) $request->input('description')) : null,
        ];

        $validator = Validator::make($input, [
            'reportable_type' => ['required', 'string', Rule::in(['amendment', 'proposal'])],
            'reportable_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in(array_keys(Report::reasonOptions()))],
            'description' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail) use ($input): void {
                    if ($input['reason'] !== Report::REASON_OTHER) {
                        return;
                    }

                    $description = trim((string) $value);

                    if ($description === '' || mb_strlen($description) < 10) {
                        $fail('Please provide at least 10 characters of additional detail when selecting "other".');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $modelClass = $input['reportable_type'] === 'amendment' ? Amendment::class : CitizenProposal::class;
        $reportable = $modelClass::find($input['reportable_id']);

        if (!$reportable) {
            return response()->json(['message' => 'Reportable item not found.'], 404);
        }

        $existing = Report::where('user_id', $user->id)
            ->where('reportable_type', $modelClass)
            ->where('reportable_id', $input['reportable_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already reported this item.'], 400);
        }

        Report::create([
            'user_id' => $user->id,
            'reportable_type' => $modelClass,
            'reportable_id' => $input['reportable_id'],
            'reason' => $input['reason'],
            'description' => $input['description'],
            'status' => Report::STATUS_PENDING,
        ]);

        $count = Report::where('reportable_type', $modelClass)
            ->where('reportable_id', $input['reportable_id'])
            ->count();

        $autoHideThreshold = Setting::get('auto_hide_report_count', 10);
        if ($count >= $autoHideThreshold) {
            $reportable->update(['hidden' => true]);
        }

        return response()->json(['message' => 'Report submitted.'], 201);
    }
}
