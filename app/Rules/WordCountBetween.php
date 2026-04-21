<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WordCountBetween implements ValidationRule
{
    public function __construct(
        protected int $minWords,
        protected int $maxWords,
        protected bool $allowBlank = false,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = trim((string) $value);

        if ($text === '' && $this->allowBlank) {
            return;
        }

        $wordCount = $this->countWords($text);

        if (($wordCount < $this->minWords) || ($wordCount > $this->maxWords)) {
            $fail($this->message($attribute));
        }
    }

    protected function countWords(string $text): int
    {
        $normalized = preg_replace("/[^\p{L}\p{N}']+/u", ' ', $text) ?? $text;
        $words = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words);
    }

    protected function message(string $attribute): string
    {
        if ($this->minWords <= 0) {
            return ucfirst(str_replace('_', ' ', $attribute)) . " cannot exceed {$this->maxWords} words.";
        }

        if ($this->minWords === $this->maxWords) {
            return ucfirst(str_replace('_', ' ', $attribute)) . " must contain exactly {$this->minWords} words.";
        }

        return ucfirst(str_replace('_', ' ', $attribute)) . " must be between {$this->minWords} and {$this->maxWords} words.";
    }
}
