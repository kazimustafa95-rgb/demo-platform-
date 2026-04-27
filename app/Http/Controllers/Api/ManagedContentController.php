<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagedContent;
use Illuminate\Http\Request;

class ManagedContentController extends Controller
{
    public function index(Request $request)
    {
        $query = ManagedContent::query()
            ->published()
            ->orderBy('display_order')
            ->orderByDesc('published_at')
            ->orderBy('title');

        $type = trim((string) $request->query('type', ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        $audience = trim((string) $request->query('audience', ''));
        if ($audience !== '') {
            $query->whereIn('audience', [
                ManagedContent::AUDIENCE_GLOBAL,
                $audience,
            ]);
        }

        $items = $query->get();

        if ($type !== '') {
            return response()->json([
                'items' => $items->values(),
            ]);
        }

        $grouped = $items->groupBy('type');

        return response()->json([
            'faqs' => $grouped->get(ManagedContent::TYPE_FAQ, collect())->values(),
            'guidelines' => $grouped->get(ManagedContent::TYPE_GUIDELINE, collect())->values(),
            'announcements' => $grouped->get(ManagedContent::TYPE_ANNOUNCEMENT, collect())->values(),
        ]);
    }

    public function show(string $slug)
    {
        $item = ManagedContent::query()
            ->published()
            ->where('slug', trim($slug))
            ->firstOrFail();

        return response()->json([
            'item' => $item,
        ]);
    }
}
