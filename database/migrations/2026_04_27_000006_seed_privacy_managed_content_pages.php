<?php

use App\Models\ManagedContent;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $pages = [
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'summary' => 'How DEMOS collects, uses, stores, and protects your data.',
                'body' => "DEMOS collects the information needed to create your account, verify your eligibility, and support civic participation features. This can include your name, email address, phone number, district information, device notification token, and participation activity inside the app.\n\nWe use this information to deliver account security, location-based civic content, participation history, and important product updates. We do not publish private personal information to other users.\n\nYou can request account deletion from the mobile app. Deleting your account removes access and soft-deletes the account record so support and moderation history can be retained when required for platform integrity.\n\nIf this policy changes, the updated version will be published in this page inside the app.",
            ],
            [
                'slug' => 'terms-of-service',
                'title' => 'Terms of Service',
                'summary' => 'Rules and responsibilities for using the DEMOS platform.',
                'body' => "By using DEMOS, you agree to provide accurate account information and use the platform in good faith. You may not abuse participation tools, impersonate others, automate harmful activity, or submit fraudulent civic input.\n\nDEMOS may limit or suspend accounts that violate platform rules, misuse community features, or interfere with election and legislative integrity workflows.\n\nYour continued use of the app after updates means you accept the latest terms published on this page.",
            ],
        ];

        foreach ($pages as $page) {
            ManagedContent::query()->updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'type' => ManagedContent::TYPE_GUIDELINE,
                    'audience' => ManagedContent::AUDIENCE_PRIVACY,
                    'title' => $page['title'],
                    'summary' => $page['summary'],
                    'body' => $page['body'],
                    'display_order' => $page['slug'] === 'privacy-policy' ? 1 : 2,
                    'is_published' => true,
                    'published_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        ManagedContent::query()
            ->whereIn('slug', ['privacy-policy', 'terms-of-service'])
            ->delete();
    }
};
