<?php

namespace Tests\Feature;

use App\Models\DistrictPopulation;
use App\Services\AtlasDataMappingClient;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DistrictPopulationImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_imports_aggregate_population_rows(): void
    {
        Storage::disk('local')->put(
            'testing/district-populations-aggregate.csv',
            implode("\n", [
                'jurisdiction_type,state_code,district,registered_voter_count',
                'federal,DE,At-Large,150000',
                'state,DE,1,47000',
                'state,DE,2,48000',
            ])
        );

        $path = Storage::disk('local')->path('testing/district-populations-aggregate.csv');

        $this->artisan('demos:import-district-populations', [
            '--file' => $path,
            '--provider' => 'atlas_export',
        ])->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'federal',
            'state_code' => 'DE',
            'district' => 'At-Large',
            'chamber' => 'general',
            'registered_voter_count' => 150000,
            'provider' => 'atlas_export',
        ]);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'state',
            'state_code' => 'DE',
            'district' => '1',
            'chamber' => 'general',
            'registered_voter_count' => 47000,
            'provider' => 'atlas_export',
        ]);
    }

    public function test_command_aggregates_voter_export_rows_into_district_counts(): void
    {
        Storage::disk('local')->put(
            'testing/district-populations-voter-export.csv',
            implode("\n", [
                'Congressional District,State House District,L2 ID',
                'At-Large,1,1001',
                'At-Large,1,1002',
                'At-Large,2,1003',
                'At-Large,2,1004',
            ])
        );

        $path = Storage::disk('local')->path('testing/district-populations-voter-export.csv');

        $this->artisan('demos:import-district-populations', [
            '--file' => $path,
            '--provider' => 'atlas_export',
            '--default-state' => 'DE',
        ])->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'federal',
            'state_code' => 'DE',
            'district' => 'At-Large',
            'chamber' => 'general',
            'registered_voter_count' => 4,
            'provider' => 'atlas_export',
        ]);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'state',
            'state_code' => 'DE',
            'district' => '1',
            'chamber' => 'house',
            'registered_voter_count' => 2,
            'provider' => 'atlas_export',
        ]);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'state',
            'state_code' => 'DE',
            'district' => '2',
            'chamber' => 'house',
            'registered_voter_count' => 2,
            'provider' => 'atlas_export',
        ]);
    }

    public function test_dry_run_does_not_write_rows(): void
    {
        Storage::disk('local')->put(
            'testing/district-populations-dry-run.csv',
            implode("\n", [
                'jurisdiction_type,state_code,district,registered_voter_count',
                'federal,DE,At-Large,150000',
            ])
        );

        $path = Storage::disk('local')->path('testing/district-populations-dry-run.csv');

        $this->artisan('demos:import-district-populations', [
            '--file' => $path,
            '--dry-run' => true,
        ])->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, DistrictPopulation::count());
    }

    public function test_command_imports_live_atlas_stats_when_no_file_is_supplied(): void
    {
        $this->mock(AtlasDataMappingClient::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')
                ->once()
                ->andReturn(true);

            $mock->shouldReceive('resolveApplication')
                ->once()
                ->with(null, null, null)
                ->andReturn([
                    'customer_code' => '1RIJ',
                    'customer_name' => 'HBOX Digital',
                    'dataset_id' => 'VM_DE',
                    'application_label' => 'Delaware Voters',
                    'pick_url' => 'https://www.l2datamapping.com/atlas/pick?c=1RIJ&a=VM_DE',
                    'state_code' => 'DE',
                ]);

            $mock->shouldReceive('fetchDatasetStats')
                ->once()
                ->andReturn([
                    'US_Congressional_District' => [
                        '_counts' => [
                            '10' => 763993,
                            '112' => 1,
                        ],
                        '_dict' => [
                            '10' => 'DE##01',
                            '112' => 'DE##',
                        ],
                    ],
                    'State_House_District' => [
                        '_counts' => [
                            '427' => 18843,
                            '144' => 312,
                        ],
                        '_dict' => [
                            '427' => 'DE##037',
                            '144' => 'DE##',
                        ],
                    ],
                    'State_Senate_District' => [
                        '_counts' => [
                            '601' => 22455,
                        ],
                        '_dict' => [
                            '601' => 'DE##021',
                        ],
                    ],
                ]);
        });

        $this->artisan('demos:import-district-populations')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'federal',
            'state_code' => 'DE',
            'district' => 'At-Large',
            'chamber' => 'general',
            'registered_voter_count' => 763993,
            'provider' => 'atlas_stats',
        ]);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'state',
            'state_code' => 'DE',
            'district' => '37',
            'chamber' => 'house',
            'registered_voter_count' => 18843,
            'provider' => 'atlas_stats',
        ]);

        $this->assertDatabaseHas('district_populations', [
            'jurisdiction_type' => 'state',
            'state_code' => 'DE',
            'district' => '21',
            'chamber' => 'senate',
            'registered_voter_count' => 22455,
            'provider' => 'atlas_stats',
        ]);
    }
}
