<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical USPS state codes.
     */
    private array $stateCodeToName = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->normalizeFederalJurisdiction();
            $this->normalizeStateJurisdictions();
            $this->ensureMissingStateJurisdictions();
        });

        Schema::table('jurisdictions', function (Blueprint $table): void {
            $table->unique(['type', 'code'], 'jurisdictions_type_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table): void {
            $table->dropUnique('jurisdictions_type_code_unique');
        });
    }

    private function normalizeFederalJurisdiction(): void
    {
        $federalRows = DB::table('jurisdictions')
            ->where('type', 'federal')
            ->orderBy('id')
            ->get();

        if ($federalRows->isEmpty()) {
            DB::table('jurisdictions')->insert([
                'name' => 'Federal',
                'type' => 'federal',
                'code' => 'US',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $keep = $federalRows->first();

        DB::table('jurisdictions')
            ->where('id', $keep->id)
            ->update([
                'name' => 'Federal',
                'code' => 'US',
                'updated_at' => now(),
            ]);

        foreach ($federalRows->slice(1) as $duplicate) {
            $this->repointJurisdictionReferences((int) $duplicate->id, (int) $keep->id);
            DB::table('jurisdictions')->where('id', $duplicate->id)->delete();
        }
    }

    private function normalizeStateJurisdictions(): void
    {
        $codeByName = array_flip($this->stateCodeToName);
        $canonicalIdByCode = [];

        $stateRows = DB::table('jurisdictions')
            ->where('type', 'state')
            ->orderBy('id')
            ->get();

        foreach ($stateRows as $row) {
            $name = (string) $row->name;
            $existingCode = strtoupper((string) ($row->code ?? ''));
            $resolvedCode = $codeByName[$name] ?? $existingCode;

            if (!array_key_exists($resolvedCode, $this->stateCodeToName)) {
                continue;
            }

            if (!isset($canonicalIdByCode[$resolvedCode])) {
                $canonicalIdByCode[$resolvedCode] = (int) $row->id;

                DB::table('jurisdictions')
                    ->where('id', $row->id)
                    ->update([
                        'code' => $resolvedCode,
                        'name' => $this->stateCodeToName[$resolvedCode],
                        'updated_at' => now(),
                    ]);

                continue;
            }

            $canonicalId = $canonicalIdByCode[$resolvedCode];
            $this->repointJurisdictionReferences((int) $row->id, $canonicalId);
            DB::table('jurisdictions')->where('id', $row->id)->delete();
        }
    }

    private function ensureMissingStateJurisdictions(): void
    {
        foreach ($this->stateCodeToName as $code => $name) {
            $existing = DB::table('jurisdictions')
                ->where('type', 'state')
                ->where('code', $code)
                ->first();

            if ($existing) {
                DB::table('jurisdictions')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $name,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('jurisdictions')->insert([
                'name' => $name,
                'type' => 'state',
                'code' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function repointJurisdictionReferences(int $fromId, int $toId): void
    {
        DB::table('bills')
            ->where('jurisdiction_id', $fromId)
            ->update(['jurisdiction_id' => $toId]);

        DB::table('representatives')
            ->where('jurisdiction_id', $fromId)
            ->update(['jurisdiction_id' => $toId]);
    }
};