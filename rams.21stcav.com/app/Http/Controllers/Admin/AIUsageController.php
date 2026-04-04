<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AIUsage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class AIUsageController extends Controller
{
    public function index(): View
    {
        $now = Carbon::now();

        $todayStart = $now->copy()->startOfDay();
        $weekStart  = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $stats = [
            'today' => $this->summarize(AIUsage::query()->where('created_at', '>=', $todayStart)),
            'week'  => $this->summarize(AIUsage::query()->where('created_at', '>=', $weekStart)),
            'month' => $this->summarize(AIUsage::query()->where('created_at', '>=', $monthStart)),
            'last_month' => $this->summarize(
                AIUsage::query()->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ),
        ];

        $recent = AIUsage::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.ai-usage', compact('stats', 'recent'));
    }

    private function summarize(Builder $query): array
    {
        return [
            'calls'        => (int) $query->count(),
            'input_tokens' => (int) $query->sum('input_tokens'),
            'output_tokens'=> (int) $query->sum('output_tokens'),
            'total_tokens' => (int) $query->sum('total_tokens'),
            'cost_usd'     => (float) $query->sum('cost_usd'),
        ];
    }
}
