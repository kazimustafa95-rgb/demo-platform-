<?php

namespace Tests\Feature;

use App\Models\Jurisdiction;
use App\Models\Representative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepresentativePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_historical_service_years(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate(
            ['type' => 'state', 'code' => 'AL'],
            ['name' => 'Alabama'],
        );

        $representative = Representative::create([
            'external_id' => '11312',
            'first_name' => 'Tete',
            'last_name' => 'Ertret',
            'party' => 'Independent',
            'chamber' => 'senate',
            'district' => '3',
            'jurisdiction_id' => $jurisdiction->id,
            'photo_url' => 'https://demos.hbox.digital/admin/bills?page=1857',
            'years_in_office_start' => 1900,
            'years_in_office_end' => 2000,
            'contact_info' => [
                '223232' => '23424',
                '24234' => '24242',
            ],
            'committee_assignments' => ['2424242'],
        ]);

        $representative->refresh();

        $this->assertSame(1900, $representative->years_in_office_start);
        $this->assertSame(2000, $representative->years_in_office_end);
        $this->assertDatabaseHas('representatives', [
            'id' => $representative->id,
            'years_in_office_start' => 1900,
            'years_in_office_end' => 2000,
        ]);
    }
}
