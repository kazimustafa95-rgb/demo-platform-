<?php

namespace App\Console\Commands;

use App\Services\AtlasDataMappingClient;
use App\Services\AtlasDistrictPopulationSyncService;
use App\Services\DistrictPopulationImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ImportDistrictPopulations extends Command
{
    protected $signature = 'demos:import-district-populations
                            {--file= : Path to a district population CSV, TSV, TXT, JSON, or ZIP file}
                            {--mode=auto : Import mode: auto, aggregate, or voter_export}
                            {--provider= : Provider label to save on district_populations}
                            {--source-reference= : Source reference string to save on district_populations}
                            {--default-state= : Default USPS state code when the source file omits it}
                            {--delimiter= : Override CSV delimiter; use "tab" for TSV}
                            {--jurisdiction-column= : Override the jurisdiction_type column name for aggregate imports}
                            {--state-column= : Override the state/state_code column name}
                            {--district-column= : Override the district column name for aggregate imports}
                            {--chamber-column= : Override the chamber column name for aggregate imports}
                            {--count-column= : Override the registered voter count column name for aggregate imports}
                            {--federal-column= : Override the federal district column name for voter exports}
                            {--state-district-column= : Override the state district column name for voter exports}
                            {--atlas-export-id= : Download and import an existing Atlas export by id}
                            {--latest-atlas-export : Import the latest Atlas export in the selected date window}
                            {--atlas-stats : Import live district counts from the configured Atlas application stats}
                            {--atlas-dataset= : Atlas dataset/application id such as VM_DE}
                            {--customer-code= : Atlas customer code when the account has multiple customer scopes}
                            {--atlas-start-date= : Atlas export search start date in YYYY-MM-DD format}
                            {--atlas-end-date= : Atlas export search end date in YYYY-MM-DD format}
                            {--atlas-universe= : Optional Atlas universe name filter when searching exports}
                            {--dry-run : Parse the source and show a summary without writing database rows}';

    protected $description = 'Import registered voter counts into district_populations from Atlas live stats, Atlas exports, or local files';

    public function handle(
        DistrictPopulationImportService $importService,
        AtlasDataMappingClient $atlasClient,
        AtlasDistrictPopulationSyncService $atlasSyncService,
    ): int {
        $source = [];

        try {
            if ($this->shouldUseAtlasStats($atlasClient)) {
                $result = $atlasSyncService->sync($this->atlasSyncOptions());
            } else {
                $source = $this->resolveSource($atlasClient);
                $options = $this->importOptions($source);
                $result = $importService->import($source['path'], $options);
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (isset($source['cleanup_path']) && is_string($source['cleanup_path']) && File::exists($source['cleanup_path'])) {
                File::delete($source['cleanup_path']);
            }
        }

        $this->info(sprintf(
            'District population import finished. Mode=%s, rows=%d, skipped=%d, written=%d.',
            $result['mode'],
            $result['rows_read'],
            $result['rows_skipped'],
            $result['districts_written']
        ));
        $this->line(sprintf(
            'Created=%d, updated=%d, unchanged=%d, provider=%s, source=%s%s',
            $result['districts_created'],
            $result['districts_updated'],
            $result['districts_unchanged'],
            $result['provider'],
            $result['source_reference'],
            $result['dry_run'] ? ' (dry run)' : ''
        ));

        if ($result['dry_run'] && $result['districts_preview'] !== []) {
            $this->table(
                ['Jurisdiction', 'State', 'District', 'Chamber', 'Registered Voters'],
                array_map(
                    fn (array $preview) => [
                        $preview['jurisdiction_type'],
                        $preview['state_code'] ?? '',
                        $preview['district'],
                        $preview['chamber'] ?? '',
                        number_format((int) $preview['registered_voter_count']),
                    ],
                    $result['districts_preview']
                )
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array{path:string, source_reference:?string, source_payload:array<string, mixed>, provider:?string, cleanup_path:?string, default_state:?string}
     */
    private function resolveSource(AtlasDataMappingClient $atlasClient): array
    {
        $file = trim((string) $this->option('file'));

        if ($file !== '') {
            return [
                'path' => $file,
                'source_reference' => trim((string) $this->option('source-reference')) ?: null,
                'source_payload' => [],
                'provider' => trim((string) $this->option('provider')) ?: null,
                'cleanup_path' => null,
                'default_state' => $this->normalizeState($this->option('default-state')),
            ];
        }

        $exportId = trim((string) $this->option('atlas-export-id'));
        $latestExport = (bool) $this->option('latest-atlas-export');

        if ($exportId === '' && !$latestExport) {
            throw new RuntimeException('Pass --atlas-stats, --file, --atlas-export-id, or --latest-atlas-export.');
        }

        if (!$atlasClient->isConfigured()) {
            throw new RuntimeException('Atlas import requested, but Atlas credentials are not configured.');
        }

        $selectedExport = $exportId !== ''
            ? ['_id' => $exportId, 'format' => 'Atlas']
            : $this->latestAtlasExport($atlasClient);

        $download = $atlasClient->downloadExport((string) $selectedExport['_id']);
        $temporaryDirectory = storage_path('app/private/district-population-imports');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = $temporaryDirectory . DIRECTORY_SEPARATOR . uniqid('atlas_export_', true) . '_' . basename($download['filename']);
        File::put($temporaryPath, $download['content']);

        $defaultState = $this->normalizeState(
            $this->option('default-state') ?? $this->atlasStateCodeFromExport($selectedExport)
        );

        return [
            'path' => $temporaryPath,
            'source_reference' => trim((string) $this->option('source-reference')) ?: 'atlas_export:' . $selectedExport['_id'],
            'source_payload' => [
                'atlas_export_id' => $selectedExport['_id'],
                'atlas_export_format' => $selectedExport['format'] ?? null,
                'atlas_export_label' => $selectedExport['label'] ?? null,
                'atlas_export_created_at' => $selectedExport['created_at'] ?? null,
                'atlas_customer' => $selectedExport['customer']['qb_name'] ?? null,
                'atlas_dataset' => $selectedExport['ds'] ?? null,
                'downloaded_filename' => $download['filename'],
                'downloaded_content_type' => $download['content_type'],
            ],
            'provider' => trim((string) $this->option('provider')) ?: 'atlas_export',
            'cleanup_path' => $temporaryPath,
            'default_state' => $defaultState,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function importOptions(array $source): array
    {
        return [
            'mode' => $this->option('mode'),
            'provider' => $source['provider'],
            'source_reference' => $source['source_reference'],
            'source_payload' => $source['source_payload'],
            'default_state' => $source['default_state'],
            'delimiter' => $this->option('delimiter'),
            'jurisdiction_column' => $this->option('jurisdiction-column'),
            'state_column' => $this->option('state-column'),
            'district_column' => $this->option('district-column'),
            'chamber_column' => $this->option('chamber-column'),
            'count_column' => $this->option('count-column'),
            'federal_column' => $this->option('federal-column'),
            'state_district_column' => $this->option('state-district-column'),
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function atlasSyncOptions(): array
    {
        return [
            'provider' => trim((string) $this->option('provider')) ?: 'atlas_stats',
            'source_reference' => trim((string) $this->option('source-reference')) ?: null,
            'default_state' => $this->normalizeState($this->option('default-state')),
            'customer_code' => $this->filledString($this->option('customer-code')),
            'atlas_dataset' => $this->filledString($this->option('atlas-dataset')),
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestAtlasExport(AtlasDataMappingClient $atlasClient): array
    {
        $scope = $atlasClient->resolveScope($this->option('customer-code'));
        $startDate = $this->parseDate((string) ($this->option('atlas-start-date') ?: now()->subDays(30)->toDateString()));
        $endDate = $this->parseDate((string) ($this->option('atlas-end-date') ?: now()->toDateString()));
        $exports = $atlasClient->searchExports(
            $scope['scope'],
            $startDate,
            $endDate,
            null,
            $this->filledString($this->option('atlas-universe'))
        );

        usort($exports, function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        if ($exports === []) {
            throw new RuntimeException('No Atlas exports matched the requested date window and filters.');
        }

        return $exports[0];
    }

    private function parseDate(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', trim($value)) ?: CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new RuntimeException("Invalid date [{$value}]. Use YYYY-MM-DD.");
        }
    }

    private function atlasStateCodeFromExport(array $export): ?string
    {
        $dataset = strtoupper(trim((string) ($export['ds'] ?? '')));

        return preg_match('/^[A-Z]{2}$/', $dataset) === 1 ? $dataset : null;
    }

    private function normalizeState(mixed $value): ?string
    {
        $state = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $state) === 1 ? $state : null;
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function shouldUseAtlasStats(AtlasDataMappingClient $atlasClient): bool
    {
        if ((bool) $this->option('atlas-stats')) {
            return true;
        }

        $hasFile = trim((string) $this->option('file')) !== '';
        $hasExportId = trim((string) $this->option('atlas-export-id')) !== '';
        $hasLatestExport = (bool) $this->option('latest-atlas-export');

        return !$hasFile && !$hasExportId && !$hasLatestExport && $atlasClient->isConfigured();
    }
}
