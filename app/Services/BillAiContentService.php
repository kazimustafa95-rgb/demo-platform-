<?php

namespace App\Services;

use App\Models\Bill;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BillAiContentService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->apiKey = trim((string) config('services.openai.api_key'));
        $this->baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $this->model = trim((string) config('services.openai.bill_model', 'gpt-5.4-mini'));
        $this->timeoutSeconds = max(5, (int) config('services.openai.timeout_seconds', 45));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function needsGeneration(Bill $bill): bool
    {
        return blank($bill->getRawOriginal('ai_summary_plain'))
            || blank($bill->getRawOriginal('ai_bill_impact'));
    }

    public function generateAndStore(Bill $bill, bool $force = false): Bill
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OpenAI bill generation is not configured.');
        }

        if (!$force && !$this->needsGeneration($bill)) {
            return $bill;
        }

        $bill->forceFill($this->generate($bill))->save();

        return $bill;
    }

    public function generate(Bill $bill): array
    {
        $bill->loadMissing('jurisdiction');

        $response = Http::asJson()
            ->acceptJson()
            ->withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->post($this->baseUrl . '/responses', $this->buildRequestPayload($bill))
            ->throw()
            ->json();

        return $this->normalizeGeneratedContent($this->extractGeneratedPayload($response));
    }

    private function buildRequestPayload(Bill $bill): array
    {
        $billJson = json_encode(
            $this->buildBillSourcePayload($bill),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (!is_string($billJson)) {
            throw new RuntimeException('Bill AI payload could not be encoded as JSON.');
        }

        return [
            'model' => $this->model,
            'store' => false,
            'instructions' => 'You generate concise plain-language bill copy for a civic mobile app. Return JSON only. Use only the provided bill record. Do not invent facts. Do not use markdown, bullets, headings, or line breaks. Keep both fields neutral, readable, and under 500 characters each. `ai_summary_plain` should explain what the bill does. `ai_bill_impact` should explain the likely practical impact on people, agencies, or businesses based only on the supplied data.',
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "Generate `ai_summary_plain` and `ai_bill_impact` for this bill record from the application's bills database.\n\nBill record JSON:\n{$billJson}",
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'bill_ai_content',
                    'description' => 'AI summary fields for a legislative bill.',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'ai_summary_plain' => [
                                'type' => 'string',
                                'description' => 'A plain-language summary of what the bill does, max 500 characters.',
                            ],
                            'ai_bill_impact' => [
                                'type' => 'string',
                                'description' => 'A plain-language summary of the likely practical impact of the bill, max 500 characters.',
                            ],
                        ],
                        'required' => [
                            'ai_summary_plain',
                            'ai_bill_impact',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'verbosity' => 'low',
            ],
            'max_output_tokens' => 400,
        ];
    }

    private function buildBillSourcePayload(Bill $bill): array
    {
        $payload = $bill->attributesToArray();

        unset(
            $payload['ai_summary_plain'],
            $payload['ai_bill_impact']
        );

        $payload['jurisdiction'] = $bill->jurisdiction?->only([
            'id',
            'name',
            'code',
            'type',
        ]);

        return $payload;
    }

    private function extractGeneratedPayload(array $response): array
    {
        foreach (($response['output'] ?? []) as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (is_array($contentItem['json'] ?? null)) {
                    return $contentItem['json'];
                }
            }
        }

        $text = $this->extractOutputText($response);

        if ($text === '') {
            $message = data_get($response, 'error.message', 'OpenAI bill generation response did not include output text.');

            throw new RuntimeException($message);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI bill generation response was not valid JSON.');
        }

        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        $chunks = [];

        foreach (($response['output'] ?? []) as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (($contentItem['type'] ?? null) === 'output_text' && is_string($contentItem['text'] ?? null)) {
                    $chunks[] = $contentItem['text'];
                }
            }
        }

        if ($chunks === [] && is_string($response['output_text'] ?? null)) {
            $chunks[] = $response['output_text'];
        }

        return trim(implode("\n", $chunks));
    }

    private function normalizeGeneratedContent(array $generated): array
    {
        $normalized = [
            'ai_summary_plain' => (string) ($generated['ai_summary_plain'] ?? ''),
            'ai_bill_impact' => (string) ($generated['ai_bill_impact'] ?? ''),
        ];

        if (blank($normalized['ai_summary_plain']) || blank($normalized['ai_bill_impact'])) {
            throw new RuntimeException('OpenAI bill generation returned empty AI content.');
        }

        return $normalized;
    }
}
