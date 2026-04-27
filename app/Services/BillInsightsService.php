<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\User;
use App\Models\UserVote;
use Illuminate\Support\Collection;
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
        $analysis = $this->analyze($bill, $user);
        $context = $analysis['context'];

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
                'registered_voter_count' => $analysis['population_size'],
                'population_source' => $analysis['population_source'],
            ] : null,
            'participation' => [
                'verified_participant_count' => $analysis['sample_size'],
                'district_turnout_rate' => $this->percentageOrNull($analysis['sample_size'], $analysis['population_size']),
            ],
            'vote_totals' => [
                'in_favor' => $analysis['vote_counts']['in_favor'],
                'against' => $analysis['vote_counts']['against'],
                'abstain' => $analysis['vote_counts']['abstain'],
                'total' => $analysis['sample_size'],
            ],
            'vote_proportions' => [
                'in_favor' => $analysis['support_proportion'],
                'against' => $analysis['opposition_proportion'],
                'abstain' => $analysis['abstain_proportion'],
                'in_favor_percent' => $this->toPercent($analysis['support_proportion']),
                'against_percent' => $this->toPercent($analysis['opposition_proportion']),
                'abstain_percent' => $this->toPercent($analysis['abstain_proportion']),
            ],
            'statistical_validity' => [
                'confidence_level' => self::CONFIDENCE_LEVEL,
                'z_score' => self::Z_SCORE,
                'margin_of_error' => $analysis['support_margin'],
                'margin_of_error_percent' => $this->toPercent($analysis['support_margin']),
                'support_interval' => $analysis['support_interval'],
                'opposition_interval' => $analysis['opposition_interval'],
                'formula_inputs' => [
                    'n' => $analysis['sample_size'],
                    'N' => $analysis['population_size'],
                    'p_hat' => $analysis['support_proportion'],
                ],
            ],
            'card_stats' => $this->formatCardStats($analysis),
        ];
    }

    public function attachCardStats(Bill $bill, ?User $user = null): Bill
    {
        $bill->setAttribute('card_stats', $this->buildCardStats($bill, $user));

        return $bill;
    }

    public function attachCardStatsToCollection(Collection $bills, ?User $user = null): Collection
    {
        return $bills->map(fn (Bill $bill) => $this->attachCardStats($bill, $user));
    }

    public function buildCardStats(Bill $bill, ?User $user = null): array
    {
        return $this->formatCardStats($this->analyze($bill, $user));
    }

    private function analyze(Bill $bill, ?User $user): array
    {
        $bill->loadMissing('jurisdiction');

        $districtData = $user
            ? $this->districtPopulationService->resolveForBillAndUser($bill, $user)
            : ['context' => null, 'population' => null, 'source' => null];

        $context = $districtData['context'];
        $voteCounts = $context
            ? $this->districtVoteCounts($bill, $context)
            : $this->verifiedVoteCounts($bill);

        if ($context === null && $districtData['population'] === null) {
            $districtData = $this->districtPopulationService->resolveFallbackForBill($bill);
        }

        $sampleSize = array_sum($voteCounts);
        $populationSize = $districtData['population'];

        $supportProportion = $sampleSize > 0 ? $voteCounts['in_favor'] / $sampleSize : null;
        $oppositionProportion = $sampleSize > 0 ? $voteCounts['against'] / $sampleSize : null;
        $abstainProportion = $sampleSize > 0 ? $voteCounts['abstain'] / $sampleSize : null;

        $supportMargin = $this->calculateMarginOfError($supportProportion, $sampleSize, $populationSize);
        $oppositionMargin = $this->calculateMarginOfError($oppositionProportion, $sampleSize, $populationSize);

        return [
            'context' => $context,
            'scope_type' => $context ? 'district' : 'verified_voters',
            'scope_label' => $context['display_name'] ?? 'All Verified Voters',
            'population_size' => $populationSize,
            'population_source' => $districtData['source'],
            'sample_size' => $sampleSize,
            'vote_counts' => $voteCounts,
            'support_proportion' => $supportProportion,
            'opposition_proportion' => $oppositionProportion,
            'abstain_proportion' => $abstainProportion,
            'support_margin' => $supportMargin,
            'opposition_margin' => $oppositionMargin,
            'support_interval' => $this->confidenceInterval($supportProportion, $supportMargin),
            'opposition_interval' => $this->confidenceInterval($oppositionProportion, $oppositionMargin),
        ];
    }

    private function formatCardStats(array $analysis): array
    {
        $requiredSampleSize = $this->requiredSampleSize($analysis['population_size']);
        $representativenessPercent = $this->representativenessPercent($analysis['sample_size'], $requiredSampleSize);
        $supportInterval = $analysis['support_interval'];
        $supportPercent = $this->toPercent($analysis['support_proportion']);
        $againstPercent = $this->toPercent($analysis['opposition_proportion']);
        $abstainPercent = $this->toPercent($analysis['abstain_proportion']);
        $marginOfErrorPercent = $this->toPercent($analysis['support_margin']);
        $isStatisticallySignificant = $supportInterval !== null
            && (($supportInterval['lower_percent'] ?? 0) > 50 || ($supportInterval['upper_percent'] ?? 0) < 50);

        return [
            'scope_type' => $analysis['scope_type'],
            'scope_label' => $analysis['scope_label'],
            'verified_vote_count' => $analysis['sample_size'],
            'verified_vote_count_label' => number_format($analysis['sample_size']),
            'registered_voter_count' => $analysis['population_size'],
            'registered_voter_count_label' => $analysis['population_size'] !== null ? number_format($analysis['population_size']) : null,
            'in_favor_percent' => $supportPercent,
            'in_favor_percent_label' => $supportPercent !== null ? $this->formatPercentLabel($supportPercent) : null,
            'against_percent' => $againstPercent,
            'against_percent_label' => $againstPercent !== null ? $this->formatPercentLabel($againstPercent) : null,
            'abstain_percent' => $abstainPercent,
            'abstain_percent_label' => $abstainPercent !== null ? $this->formatPercentLabel($abstainPercent) : null,
            'margin_of_error_percent' => $marginOfErrorPercent,
            'margin_of_error_label' => $marginOfErrorPercent !== null ? '+/-' . $this->formatPercentLabel($marginOfErrorPercent) : null,
            'confidence_interval' => $supportInterval,
            'confidence_interval_label' => $supportInterval !== null
                ? sprintf(
                    '95%% CI: [%s, %s]',
                    $this->formatPercentLabel((float) $supportInterval['lower_percent']),
                    $this->formatPercentLabel((float) $supportInterval['upper_percent'])
                )
                : null,
            'is_statistically_significant' => $isStatisticallySignificant,
            'statistical_significance_label' => $isStatisticallySignificant ? 'Statistically Significant' : 'More Sample Needed',
            'vote_distribution' => [
                'in_favor' => $analysis['vote_counts']['in_favor'],
                'against' => $analysis['vote_counts']['against'],
                'abstain' => $analysis['vote_counts']['abstain'],
                'total' => $analysis['sample_size'],
            ],
            'sample_representativeness_percent' => $representativenessPercent,
            'sample_representativeness_label' => $analysis['population_size'] !== null
                ? sprintf(
                    '%s verified voters currently represent %s registered voters.',
                    number_format($analysis['sample_size']),
                    number_format($analysis['population_size'])
                )
                : null,
            'sample_target_count' => $requiredSampleSize,
            'population_source' => $analysis['population_source'],
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

    private function verifiedVoteCounts(Bill $bill): array
    {
        $counts = UserVote::query()
            ->selectRaw('vote, COUNT(*) AS aggregate')
            ->where('bill_id', $bill->id)
            ->whereHas('user', fn (Builder $query) => $this->applyVerifiedScope($query))
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
        $this->applyVerifiedScope($query);

        if ($context['jurisdiction_type'] === 'federal') {
            $query->where('federal_district', $context['district'])
                ->where('state_district', 'like', $context['state_code'] . '-%');

            return;
        }

        $query->where('state_district', $context['state_code'] . '-' . $context['district']);
    }

    private function applyVerifiedScope(Builder $query): void
    {
        $query->whereNotNull('email_verified_at')
            ->where(function (Builder $verifiedQuery) {
                $verifiedQuery->whereNotNull('identity_verified_at')
                    ->orWhere('is_verified', true);
            });
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

    private function requiredSampleSize(?int $populationSize, float $targetMargin = 0.01): ?int
    {
        if ($populationSize === null || $populationSize <= 1 || $targetMargin <= 0) {
            return null;
        }

        $variance = 0.25;
        $numerator = $populationSize * (self::Z_SCORE ** 2) * $variance;
        $denominator = (($populationSize - 1) * ($targetMargin ** 2)) + ((self::Z_SCORE ** 2) * $variance);

        if ($denominator <= 0) {
            return null;
        }

        return (int) ceil($numerator / $denominator);
    }

    private function representativenessPercent(int $sampleSize, ?int $requiredSampleSize): float
    {
        if ($sampleSize <= 0 || $requiredSampleSize === null || $requiredSampleSize <= 0) {
            return 0.0;
        }

        return round(min(100, ($sampleSize / $requiredSampleSize) * 100), 2);
    }

    private function formatPercentLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }
}
