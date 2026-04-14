<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\User;
use App\Models\UserVote;
use Illuminate\Database\Eloquent\Builder;

class BillInsightsService
{
    private const CONFIDENCE_LEVEL = 0.95;

    private const Z_SCORE = 1.96;

    public function __construct(
        private readonly DistrictPopulationService $districtPopulationService,
    ) {
    }

    public function build(Bill $bill, User $user): array
    {
        $bill->loadMissing('jurisdiction');

        $districtData = $this->districtPopulationService->resolveForBillAndUser($bill, $user);
        $context = $districtData['context'];

        $voteCounts = $context
            ? $this->districtVoteCounts($bill, $context)
            : ['in_favor' => 0, 'against' => 0, 'abstain' => 0];

        $sampleSize = array_sum($voteCounts);
        $populationSize = $districtData['population'];

        $supportProportion = $sampleSize > 0 ? $voteCounts['in_favor'] / $sampleSize : null;
        $oppositionProportion = $sampleSize > 0 ? $voteCounts['against'] / $sampleSize : null;
        $abstainProportion = $sampleSize > 0 ? $voteCounts['abstain'] / $sampleSize : null;

        $supportMargin = $this->calculateMarginOfError($supportProportion, $sampleSize, $populationSize);
        $oppositionMargin = $this->calculateMarginOfError($oppositionProportion, $sampleSize, $populationSize);

        return [
            'bill' => [
                'id' => $bill->id,
                'external_id' => $bill->external_id,
                'number' => $bill->number,
                'title' => $bill->title,
                'status' => $bill->status,
                'jurisdiction' => [
                    'type' => $bill->jurisdiction?->type,
                    'code' => $bill->jurisdiction?->code,
                    'name' => $bill->jurisdiction?->name,
                ],
            ],
            'district' => $context ? [
                'jurisdiction_type' => $context['jurisdiction_type'],
                'state_code' => $context['state_code'],
                'district' => $context['district'],
                'district_key' => $context['district_key'],
                'display_name' => $context['display_name'],
                'registered_voter_count' => $populationSize,
                'population_source' => $districtData['source'],
            ] : null,
            'participation' => [
                'verified_participant_count' => $sampleSize,
                'district_turnout_rate' => $this->percentageOrNull($sampleSize, $populationSize),
            ],
            'vote_totals' => [
                'in_favor' => $voteCounts['in_favor'],
                'against' => $voteCounts['against'],
                'abstain' => $voteCounts['abstain'],
                'total' => $sampleSize,
            ],
            'vote_proportions' => [
                'in_favor' => $supportProportion,
                'against' => $oppositionProportion,
                'abstain' => $abstainProportion,
                'in_favor_percent' => $this->toPercent($supportProportion),
                'against_percent' => $this->toPercent($oppositionProportion),
                'abstain_percent' => $this->toPercent($abstainProportion),
            ],
            'statistical_validity' => [
                'confidence_level' => self::CONFIDENCE_LEVEL,
                'z_score' => self::Z_SCORE,
                'margin_of_error' => $supportMargin,
                'margin_of_error_percent' => $this->toPercent($supportMargin),
                'support_interval' => $this->confidenceInterval($supportProportion, $supportMargin),
                'opposition_interval' => $this->confidenceInterval($oppositionProportion, $oppositionMargin),
                'formula_inputs' => [
                    'n' => $sampleSize,
                    'N' => $populationSize,
                    'p_hat' => $supportProportion,
                ],
            ],
        ];
    }

    private function districtVoteCounts(Bill $bill, array $context): array
    {
        $counts = UserVote::query()
            ->selectRaw('vote, COUNT(*) AS aggregate')
            ->where('bill_id', $bill->id)
            ->whereHas('user', fn (Builder $query) => $this->applyVerifiedDistrictScope($query, $context))
            ->groupBy('vote')
            ->pluck('aggregate', 'vote');

        return [
            'in_favor' => (int) ($counts['in_favor'] ?? 0),
            'against' => (int) ($counts['against'] ?? 0),
            'abstain' => (int) ($counts['abstain'] ?? 0),
        ];
    }

    private function applyVerifiedDistrictScope(Builder $query, array $context): void
    {
        $query->whereNotNull('email_verified_at')
            ->where(function (Builder $verifiedQuery) {
                $verifiedQuery->whereNotNull('identity_verified_at')
                    ->orWhere('is_verified', true);
            });

        if ($context['jurisdiction_type'] === 'federal') {
            $query->where('federal_district', $context['district'])
                ->where('state_district', 'like', $context['state_code'] . '-%');

            return;
        }

        $query->where('state_district', $context['state_code'] . '-' . $context['district']);
    }

    private function calculateMarginOfError(?float $proportion, int $sampleSize, ?int $populationSize): ?float
    {
        if ($proportion === null || $sampleSize <= 0 || $populationSize === null || $populationSize <= 1) {
            return null;
        }

        if ($sampleSize > $populationSize) {
            return null;
        }

        $sampleVariance = ($proportion * (1 - $proportion)) / $sampleSize;
        $finitePopulationCorrection = ($populationSize - $sampleSize) / ($populationSize - 1);

        return self::Z_SCORE * sqrt(max(0.0, $sampleVariance * $finitePopulationCorrection));
    }

    private function confidenceInterval(?float $proportion, ?float $marginOfError): ?array
    {
        if ($proportion === null || $marginOfError === null) {
            return null;
        }

        $lower = max(0.0, $proportion - $marginOfError);
        $upper = min(1.0, $proportion + $marginOfError);

        return [
            'lower' => $lower,
            'upper' => $upper,
            'lower_percent' => $this->toPercent($lower),
            'upper_percent' => $this->toPercent($upper),
        ];
    }

    private function percentageOrNull(?int $value, ?int $total): ?float
    {
        if ($value === null || $total === null || $total <= 0) {
            return null;
        }

        return round(($value / $total) * 100, 2);
    }

    private function toPercent(?float $value): ?float
    {
        return $value === null ? null : round($value * 100, 2);
    }
}
