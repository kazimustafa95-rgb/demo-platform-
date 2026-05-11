<?php

namespace App\Support;

class LegislativeChamber
{
    public const GENERAL = 'general';
    public const HOUSE = 'house';
    public const SENATE = 'senate';
    public const LEGISLATURE = 'legislature';

    /**
     * @param  mixed  $value
     */
    public static function normalize(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            in_array($normalized, ['general', 'all', 'statewide'], true) => self::GENERAL,
            in_array($normalized, ['house', 'lower', 'assembly'], true) => self::HOUSE,
            in_array($normalized, ['senate', 'upper'], true) => self::SENATE,
            in_array($normalized, ['legislature', 'unicameral', 'council'], true) => self::LEGISLATURE,
            default => null,
        };
    }

    public static function inferFromBillNumber(?string $billNumber): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z]+/', '', trim((string) $billNumber)) ?: '');

        if ($normalized === '') {
            return null;
        }

        foreach ([
            self::HOUSE => [
                'HB', 'HBCR', 'HCR', 'HJR', 'HR', 'HJRCA', 'AB', 'ACR', 'AJR', 'AR', 'AF', 'HF',
            ],
            self::SENATE => [
                'SB', 'SCR', 'SJR', 'SR', 'SF',
            ],
            self::LEGISLATURE => [
                'LB', 'LR', 'B', 'PR', 'CR',
            ],
        ] as $chamber => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($normalized, $prefix)) {
                    return $chamber;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function inferFromOpenStates(array $details, ?string $billNumber = null): ?string
    {
        $candidates = [
            data_get($details, 'from_organization.classification'),
            data_get($details, 'sponsorships.0.person.current_role.org_classification'),
            data_get($details, 'actions.0.organization.classification'),
            data_get($details, 'actions.1.organization.classification'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);

            if ($normalized !== null && $normalized !== self::GENERAL) {
                return $normalized;
            }
        }

        return self::inferFromBillNumber($billNumber ?? (string) ($details['identifier'] ?? ''));
    }

    public static function displayLabel(?string $chamber): string
    {
        return match (self::normalize($chamber)) {
            self::HOUSE => 'House',
            self::SENATE => 'Senate',
            self::LEGISLATURE => 'Legislature',
            default => 'State',
        };
    }
}
