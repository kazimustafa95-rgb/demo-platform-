<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\CitizenProposal;
use App\Models\Report;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'reportable_type' => 'required|in:amendment,proposal',
            'reportable_id' => 'required|integer',
            'reason' => 'required|string|in:spam,offensive,joke,duplicate,other',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $modelClass = $request->reportable_type === 'amendment' ? Amendment::class : CitizenProposal::class;
        $reportable = $modelClass::find($request->reportable_id);

        if (!$reportable) {
            return response()->json(['message' => 'Reportable item not found.'], 404);
        }

        $existing = Report::where('user_id', $user->id)
            ->where('reportable_type', $modelClass)
            ->where('reportable_id', $request->reportable_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already reported this item.'], 400);
        }

        Report::create([
            'user_id' => $user->id,
            'reportable_type' => $modelClass,
            'reportable_id' => $request->reportable_id,
            'reason' => $request->reason,
            'description' => $request->description,
            'status' => 'pending',
        ]);

        $count = Report::where('reportable_type', $modelClass)
            ->where('reportable_id', $request->reportable_id)
            ->count();

        $autoHideThreshold = Setting::get('auto_hide_report_count', 10);
        if ($count >= $autoHideThreshold) {
            $reportable->update(['hidden' => true]);
        }

        return response()->json(['message' => 'Report submitted.'], 201);
    }
}
