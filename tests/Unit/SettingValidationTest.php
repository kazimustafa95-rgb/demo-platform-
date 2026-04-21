<?php

namespace Tests\Unit;

use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SettingValidationTest extends TestCase
{
    public function test_contact_email_settings_require_valid_emails(): void
    {
        $valid = Validator::make(
            ['value' => 'support@example.com'],
            ['value' => Setting::validationRulesFor('contact_email')],
        );

        $invalid = Validator::make(
            ['value' => 'not-an-email'],
            ['value' => Setting::validationRulesFor('contact_email')],
        );

        $this->assertFalse($valid->fails());
        $this->assertTrue($invalid->fails());
    }

    public function test_duplicate_threshold_must_stay_within_percentage_range(): void
    {
        $validator = Validator::make(
            ['value' => 101],
            ['value' => Setting::validationRulesFor('duplicate_threshold')],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_boolean_feature_settings_accept_only_boolean_style_values(): void
    {
        $valid = Validator::make(
            ['value' => 'true'],
            ['value' => Setting::validationRulesFor('maintenance_mode')],
        );

        $invalid = Validator::make(
            ['value' => 'maybe'],
            ['value' => Setting::validationRulesFor('maintenance_mode')],
        );

        $this->assertFalse($valid->fails());
        $this->assertTrue($invalid->fails());
    }

    public function test_setting_values_are_normalized_for_storage(): void
    {
        $this->assertSame('info@example.com', Setting::normalizeValueForStorage('contact_email', ' INFO@Example.COM '));
        $this->assertSame('1', Setting::normalizeValueForStorage('maintenance_mode', 'yes'));
        $this->assertSame('48', Setting::normalizeValueForStorage('voting_deadline_hours', ' 48 '));
    }
}
