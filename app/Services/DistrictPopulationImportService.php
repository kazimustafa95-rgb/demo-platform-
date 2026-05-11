<?php

namespace App\Services;

use App\Models\DistrictPopulation;
use App\Support\LegislativeChamber;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;
use ZipArchive;

class DistrictPopulationImportService
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function import(string $path, array $options = []): array
    {
        $preparedPath = $this->preparePath($path);
        $provider = trim((string) ($options['provider'] ?? config('services.district_population.provider', 'atlas_export')));
        $sourceReference = trim((string) ($options['source_reference'] ?? ('file:' . basename($preparedPath['source_path']))));
        $sourcePayload = is_array($options['source_payload'] ?? null) ? $options['source_payload'] : [];
        $dryRun = (bool) ($options['dry_run'] ?? false);

        try {
            $extension = strtolower(pathinfo($preparedPath['working_path'], PATHINFO_EXTENSION));

            $result = match ($extension) {
                'json' => $this->importJsonFile($preparedPath['working_path'], $options, $provider, $sourceReference, $sourcePayload, $dryRun),
                'csv', 'tsv', 'txt' => $this->importDelimitedFile($preparedPath['working_path'], $options, $provider, $sourceReference, $sourcePayload, $dryRun),
                default => throw new RuntimeException("Unsupported district population import file type [{$extension}]."),
            };
        } finally {
            if ($preparedPath['cleanup_path'] !== null && File::exists($preparedPath['cleanup_path'])) {
                File::delete($preparedPath['cleanup_path']);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function importRows(array $rows, array $options = []): array
    {
        $provider = trim((string) ($options['provider'] ?? config('services.district_population.provider', 'atlas_export')));
        $sourceReference = trim((string) ($options['source_reference'] ?? 'rows:memory'));
        $sourcePayload = is_array($options['source_payload'] ?? null) ? $options['source_payload'] : [];
        $dryRun = (bool) ($options['dry_run'] ?? false);

        return $this->importAssociativeRows(
            $rows,
            $options,
            $provider,
            $sourceReference,
            array_merge($sourcePayload, ['encoding' => 'array']),
            $dryRun
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $sourcePayload
     * @return array<string, mixed>
     */
    private function importDelimitedFile(
        string $path,
        array $options,
        string $provider,
        string $sourceReference,
        array $sourcePayload,
        bool $dryRun
    ): array {
        $delimiter = $this->detectDelimiter($path, $options['delimiter'] ?? null);
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $headerRow = $file->fgetcsv();

        if (!is_array($headerRow) || $headerRow === [null] || $headerRow === false) {
            throw new RuntimeException('District population import file is empty.');
        }

        $headers = $this->normalizeHeaders($headerRow);
        $mode = $this->detectMode($headers, $options);
        $summary = $this->emptySummary($mode, $provider, $sourceReference, $dryRun);
        $summary['source_payload'] = array_merge($sourcePayload, [
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'headers' => array_values(array_filter($headers)),
        ]);

        if ($mode === 'aggregate') {
            while (!$file->eof()) {
                $row = $file->fgetcsv();

                if (!is_array($row) || $this->rowIsEmpty($row)) {
                    continue;
                }

                $summary['rows_read']++;

                $record = $this->mapAggregateRow($headers, $row, $options);

                if ($record === null) {
                    $summary['rows_skipped']++;
                    continue;
                }

                $this->persistRecord($record, $provider, $sourceReference, $summary['source_payload'], $dryRun, $summary);
            }

            return $summary;
        }

        $aggregates = [];

        while (!$file->eof()) {
            $row = $file->fgetcsv();

            if (!is_array($row) || $this->rowIsEmpty($row)) {
                continue;
            }

            $summary['rows_read']++;
            $records = $this->mapVoterExportRow($headers, $row, $options);

            if ($records === []) {
                $summary['rows_skipped']++;
                continue;
            }

            foreach ($records as $record) {
                $key = implode('|', [
                    $record['jurisdiction_type'],
                    $record['state_code'] ?? '',
                    $record['district'],
                    $record['chamber'],
                ]);

                if (!isset($aggregates[$key])) {
                    $aggregates[$key] = $record + ['registered_voter_count' => 0];
                }

                $aggregates[$key]['registered_voter_count']++;
            }
        }

        foreach ($aggregates as $record) {
            $this->persistRecord($record, $provider, $sourceReference, $summary['source_payload'], $dryRun, $summary);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $sourcePayload
     * @return array<string, mixed>
     */
    private function importJsonFile(
        string $path,
        array $options,
        string $provider,
        string $sourceReference,
        array $sourcePayload,
        bool $dryRun
    ): array {
        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $this->extractJsonRows($payload);

        return $this->importAssociativeRows(
            $rows,
            $options,
            $provider,
            $sourceReference,
            array_merge($sourcePayload, ['encoding' => 'json']),
            $dryRun
        );
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, mixed>  $options
     * @return array{jurisdiction_type:string, state_code:?string, district:string, chamber:string, registered_voter_count:int}|null
     */
    private function mapAggregateRow(array $headers, array $row, array $options): ?array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $data[$header] = $row[$index] ?? null;
        }

        return $this->mapAggregateAssociativeRow($data, $options);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $options
     * @return array{jurisdiction_type:string, state_code:?string, district:string, chamber:string, registered_voter_count:int}|null
     */
    private function mapAggregateAssociativeRow(array $row, array $options): ?array
    {
        $normalized = $this->normalizeAssociativeRow($row);
        $jurisdictionType = strtolower((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('jurisdiction_type', $options['jurisdiction_column'] ?? null)
        ));
        $stateCode = $this->normalizeStateCode((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('state_code', $options['state_column'] ?? null),
            $options['default_state'] ?? null
        ));
        $district = (string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('district', $options['district_column'] ?? null)
        );
        $chamber = $this->normalizePopulationChamber(
            $jurisdictionType,
            $this->firstMatch(
                $normalized,
                $this->headerCandidates('chamber', $options['chamber_column'] ?? null)
            )
        );
        $count = $this->parseInt($this->firstMatch(
            $normalized,
            $this->headerCandidates('count', $options['count_column'] ?? null)
        ));

        $stateCode ??= $this->inferStateCodeFromDistrict($district);

        if (
            !in_array($jurisdictionType, ['federal', 'state'], true)
            || $stateCode === null
            || $district === ''
            || $count === null
        ) {
            return null;
        }

        $normalizedDistrict = $this->normalizeDistrict($jurisdictionType, $district, $stateCode);

        if ($normalizedDistrict === '') {
            return null;
        }

        return [
            'jurisdiction_type' => $jurisdictionType,
            'state_code' => $stateCode,
            'district' => $normalizedDistrict,
            'chamber' => $chamber,
            'registered_voter_count' => $count,
        ];
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, mixed>  $options
     * @return array<int, array{jurisdiction_type:string, state_code:?string, district:string, chamber:string}>
     */
    private function mapVoterExportRow(array $headers, array $row, array $options): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $data[$header] = $row[$index] ?? null;
        }

        return $this->mapVoterExportAssociativeRow($data, $options);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $options
     * @return array<int, array{jurisdiction_type:string, state_code:?string, district:string, chamber:string}>
     */
    private function mapVoterExportAssociativeRow(array $row, array $options): array
    {
        $normalized = $this->normalizeAssociativeRow($row);
        $federalDistrictSource = trim((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('federal_district', $options['federal_column'] ?? null)
        ));
        $stateDistrictSource = trim((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('state_district', $options['state_district_column'] ?? null)
        ));
        $stateLowerDistrictSource = trim((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('state_lower_district')
        ));
        $stateUpperDistrictSource = trim((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('state_upper_district')
        ));
        $stateCode = $this->normalizeStateCode((string) $this->firstMatch(
            $normalized,
            $this->headerCandidates('state_code', $options['state_column'] ?? null),
            $options['default_state'] ?? null
        ));
        $stateCode ??= $this->inferStateCodeFromDistrict($federalDistrictSource)
            ?? $this->inferStateCodeFromDistrict($stateLowerDistrictSource)
            ?? $this->inferStateCodeFromDistrict($stateUpperDistrictSource)
            ?? $this->inferStateCodeFromDistrict($stateDistrictSource);

        if ($stateCode === null) {
            return [];
        }

        $records = [];

        if ($federalDistrictSource !== '') {
            $normalizedDistrict = $this->normalizeDistrict('federal', $federalDistrictSource, $stateCode);

            if ($normalizedDistrict !== '') {
                $records[] = [
                    'jurisdiction_type' => 'federal',
                    'state_code' => $stateCode,
                    'district' => $normalizedDistrict,
                    'chamber' => LegislativeChamber::GENERAL,
                ];
            }
        }

        foreach ([
            LegislativeChamber::HOUSE => $stateLowerDistrictSource,
            LegislativeChamber::SENATE => $stateUpperDistrictSource,
            LegislativeChamber::GENERAL => $stateDistrictSource,
        ] as $chamber => $stateDistrictCandidate) {
            if ($stateDistrictCandidate === '') {
                continue;
            }

            $normalizedDistrict = $this->normalizeDistrict('state', $stateDistrictCandidate, $stateCode);

            if ($normalizedDistrict !== '') {
                $records[] = [
                    'jurisdiction_type' => 'state',
                    'state_code' => $stateCode,
                    'district' => $normalizedDistrict,
                    'chamber' => $chamber,
                ];
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $summary
     */
    private function persistRecord(
        array $record,
        string $provider,
        string $sourceReference,
        array $sourcePayload,
        bool $dryRun,
        array &$summary
    ): void {
        if ($dryRun) {
            $summary['districts_written']++;
            if (count($summary['districts_preview']) < 10) {
                $summary['districts_preview'][] = $record;
            }
            return;
        }

        $population = DistrictPopulation::firstOrNew([
            'jurisdiction_type' => $record['jurisdiction_type'],
            'state_code' => $record['state_code'],
            'district' => $record['district'],
            'chamber' => $record['chamber'] ?? LegislativeChamber::GENERAL,
        ]);

        $wasExisting = $population->exists;
        $before = $wasExisting ? $population->getAttributes() : [];

        $population->fill([
            'registered_voter_count' => $record['registered_voter_count'],
            'provider' => $provider,
            'source_reference' => $sourceReference,
            'source_payload' => $sourcePayload,
            'last_synced_at' => now(),
        ]);

        if ($wasExisting && !$population->isDirty()) {
            $summary['districts_unchanged']++;
            $summary['districts_written']++;

            return;
        }

        $population->save();
        $summary['districts_written']++;

        if (!$wasExisting) {
            $summary['districts_created']++;

            return;
        }

        if ($before !== $population->getAttributes()) {
            $summary['districts_updated']++;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function detectMode(array $headers, array $options): string
    {
        $requestedMode = strtolower(trim((string) ($options['mode'] ?? 'auto')));

        if (in_array($requestedMode, ['aggregate', 'voter_export'], true)) {
            return $requestedMode;
        }

        $headerSet = array_fill_keys(array_filter($headers), true);
        $aggregateSignals = array_filter($this->headerCandidates('count', $options['count_column'] ?? null), fn ($header) => isset($headerSet[$header]));
        $jurisdictionSignals = array_filter($this->headerCandidates('jurisdiction_type', $options['jurisdiction_column'] ?? null), fn ($header) => isset($headerSet[$header]));

        if ($aggregateSignals !== [] && $jurisdictionSignals !== []) {
            return 'aggregate';
        }

        return 'voter_export';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractJsonRows(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['rows', 'data', 'items', 'exports'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return is_array($payload) ? [$payload] : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $sourcePayload
     * @return array<string, mixed>
     */
    private function importAssociativeRows(
        array $rows,
        array $options,
        string $provider,
        string $sourceReference,
        array $sourcePayload,
        bool $dryRun
    ): array {
        if ($rows === []) {
            throw new RuntimeException('District population import rows did not contain any usable data.');
        }

        $headers = $this->normalizeHeaders(array_keys($rows[0]));
        $mode = $this->detectMode($headers, $options);
        $summary = $this->emptySummary($mode, $provider, $sourceReference, $dryRun);
        $summary['source_payload'] = array_merge($sourcePayload, [
            'headers' => array_values(array_filter($headers)),
        ]);

        if ($mode === 'aggregate') {
            foreach ($rows as $row) {
                $summary['rows_read']++;
                $record = $this->mapAggregateAssociativeRow($row, $options);

                if ($record === null) {
                    $summary['rows_skipped']++;
                    continue;
                }

                $this->persistRecord($record, $provider, $sourceReference, $summary['source_payload'], $dryRun, $summary);
            }

            return $summary;
        }

        $aggregates = [];

        foreach ($rows as $row) {
            $summary['rows_read']++;
            $records = $this->mapVoterExportAssociativeRow($row, $options);

            if ($records === []) {
                $summary['rows_skipped']++;
                continue;
            }

            foreach ($records as $record) {
                $key = implode('|', [
                    $record['jurisdiction_type'],
                    $record['state_code'] ?? '',
                    $record['district'],
                    $record['chamber'],
                ]);

                if (!isset($aggregates[$key])) {
                    $aggregates[$key] = $record + ['registered_voter_count' => 0];
                }

                $aggregates[$key]['registered_voter_count']++;
            }
        }

        foreach ($aggregates as $record) {
            $this->persistRecord($record, $provider, $sourceReference, $summary['source_payload'], $dryRun, $summary);
        }

        return $summary;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $headers
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header): string {
            $normalized = trim((string) $header);
            $normalized = preg_replace('/^\xEF\xBB\xBF/u', '', $normalized) ?: $normalized;

            return $this->normalizeHeaderName($normalized);
        }, $headers);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAssociativeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeHeaderName((string) $key)] = $value;
        }

        return $normalized;
    }

    private function normalizeHeaderName(string $header): string
    {
        return Str::of($header)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', '_')
            ->trim('_')
            ->value();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $candidates
     */
    private function firstMatch(array $row, array $candidates, mixed $default = null): mixed
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && array_key_exists($candidate, $row) && trim((string) $row[$candidate]) !== '') {
                return $row[$candidate];
            }
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    private function headerCandidates(string $type, mixed $override = null): array
    {
        $overrideName = trim((string) $override);
        $candidates = $overrideName !== '' ? [$this->normalizeHeaderName($overrideName)] : [];

        $aliases = match ($type) {
            'jurisdiction_type' => ['jurisdiction_type', 'jurisdiction', 'scope_type', 'bill_scope'],
            'state_code' => ['state_code', 'state', 'usps', 'residence_state', 'registration_state', 'state_abbreviation', 'ds'],
            'district' => ['district', 'district_code', 'district_number'],
            'chamber' => ['chamber', 'district_chamber', 'bill_chamber', 'legislative_chamber'],
            'count' => ['registered_voter_count', 'count', 'total', 'num_total', 'record_count', 'records', 'voter_count'],
            'federal_district' => [
                'federal_district',
                'congressional_district',
                'us_congressional_district',
                'congress_district',
                'cd',
            ],
            'state_district' => [
                'state_district',
                'state_legislative_district',
                'legislative_district',
            ],
            'state_lower_district' => [
                'state_house_district',
                'state_lower_district',
                'state_assembly_district',
                'assembly_district',
                'house_district',
                'lower_district',
                'sldl',
            ],
            'state_upper_district' => [
                'state_senate_district',
                'state_upper_district',
                'senate_district',
                'upper_district',
                'sldu',
            ],
            default => [],
        };

        foreach ($aliases as $alias) {
            $candidates[] = $this->normalizeHeaderName($alias);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function parseInt(mixed $value): ?int
    {
        $normalized = preg_replace('/[^\d\-]/', '', trim((string) $value)) ?: '';

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function normalizeStateCode(?string $stateCode): ?string
    {
        $stateCode = strtoupper(trim((string) $stateCode));

        return preg_match('/^[A-Z]{2}$/', $stateCode) === 1 ? $stateCode : null;
    }

    private function inferStateCodeFromDistrict(string $district): ?string
    {
        if (preg_match('/^([A-Z]{2})[-\s]/i', trim($district), $matches) === 1) {
            return strtoupper((string) $matches[1]);
        }

        return null;
    }

    private function normalizeDistrict(string $jurisdictionType, string $district, ?string $stateCode): string
    {
        $district = trim($district);

        if ($district === '') {
            return '';
        }

        if ($jurisdictionType === 'federal') {
            if (preg_match('/at[-\s]?large/i', $district) === 1) {
                return 'At-Large';
            }

            if ($stateCode !== null) {
                $district = preg_replace('/^' . preg_quote($stateCode, '/') . '[-\s]?/i', '', $district) ?: $district;
            }

            $district = preg_replace('/^(district|cd)\s+/i', '', $district) ?: $district;
            $normalizedDistrict = ltrim($district, '0') ?: '0';

            if ($stateCode !== null && $normalizedDistrict === '1' && $this->isAtLargeState($stateCode)) {
                return 'At-Large';
            }

            return $normalizedDistrict;
        }

        if ($stateCode !== null) {
            $district = preg_replace('/^' . preg_quote($stateCode, '/') . '[-\s]?/i', '', $district) ?: $district;
        }

        if (preg_match('/(?:district|ward)\s+(.+)$/i', $district, $matches) === 1) {
            $district = trim((string) $matches[1]);
        }

        return $district;
    }

    private function normalizePopulationChamber(string $jurisdictionType, mixed $value): string
    {
        if ($jurisdictionType === 'federal') {
            return LegislativeChamber::GENERAL;
        }

        $normalized = LegislativeChamber::normalize($value);

        return match ($normalized) {
            LegislativeChamber::HOUSE,
            LegislativeChamber::SENATE => $normalized,
            default => LegislativeChamber::GENERAL,
        };
    }

    /**
     * @return array{source_path:string, working_path:string, cleanup_path:string|null}
     */
    private function preparePath(string $path): array
    {
        $resolved = File::exists($path) ? $path : base_path($path);

        if (!File::exists($resolved)) {
            throw new RuntimeException("District population import file not found at [{$path}].");
        }

        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));

        if ($extension !== 'zip') {
            return [
                'source_path' => $resolved,
                'working_path' => $resolved,
                'cleanup_path' => null,
            ];
        }

        $zip = new ZipArchive();

        if ($zip->open($resolved) !== true) {
            throw new RuntimeException("Unable to open ZIP import file [{$resolved}].");
        }

        try {
            $entryName = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $candidate = (string) $zip->getNameIndex($i);
                $candidateExtension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

                if (in_array($candidateExtension, ['csv', 'tsv', 'txt', 'json'], true)) {
                    $entryName = $candidate;
                    break;
                }
            }

            if ($entryName === null) {
                throw new RuntimeException('ZIP import file did not contain a CSV, TSV, TXT, or JSON payload.');
            }

            $stream = $zip->getStream($entryName);

            if ($stream === false) {
                throw new RuntimeException("Unable to read ZIP payload entry [{$entryName}].");
            }

            $tempDirectory = storage_path('app/private/district-population-imports');
            File::ensureDirectoryExists($tempDirectory);

            $tempPath = $tempDirectory . DIRECTORY_SEPARATOR . uniqid('district_population_', true) . '.' . pathinfo($entryName, PATHINFO_EXTENSION);
            file_put_contents($tempPath, stream_get_contents($stream));
            fclose($stream);

            return [
                'source_path' => $resolved,
                'working_path' => $tempPath,
                'cleanup_path' => $tempPath,
            ];
        } finally {
            $zip->close();
        }
    }

    private function detectDelimiter(string $path, mixed $preferredDelimiter = null): string
    {
        $preferred = trim((string) $preferredDelimiter);

        if ($preferred !== '') {
            return strtolower($preferred) === 'tab' ? "\t" : $preferred;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open import file [{$path}] to detect its delimiter.");
        }

        $firstLine = '';

        try {
            $firstLine = (string) fgets($handle);
        } finally {
            fclose($handle);
        }

        $firstLine = $firstLine !== '' ? $firstLine : '';
        $delimiters = [',', "\t", ';', '|'];
        $bestDelimiter = ',';
        $bestColumns = 0;

        foreach ($delimiters as $delimiter) {
            $columns = count(str_getcsv($firstLine, $delimiter));

            if ($columns > $bestColumns) {
                $bestColumns = $columns;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    private function isAtLargeState(string $stateCode): bool
    {
        return in_array(strtoupper(trim($stateCode)), ['AK', 'DE', 'DC', 'ND', 'SD', 'VT', 'WY'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $mode, string $provider, string $sourceReference, bool $dryRun): array
    {
        return [
            'mode' => $mode,
            'provider' => $provider,
            'source_reference' => $sourceReference,
            'dry_run' => $dryRun,
            'rows_read' => 0,
            'rows_skipped' => 0,
            'districts_written' => 0,
            'districts_created' => 0,
            'districts_updated' => 0,
            'districts_unchanged' => 0,
            'districts_preview' => [],
            'source_payload' => [],
        ];
    }
}
