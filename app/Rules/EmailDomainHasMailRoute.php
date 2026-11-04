<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class EmailDomainHasMailRoute implements ValidationRule
{
    /**
     * @param  (Closure(string, string): bool)|null  $dnsLookup
     */
    public function __construct(private readonly ?Closure $dnsLookup = null) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || substr_count($value, '@') !== 1) {
            return;
        }

        [, $domain] = explode('@', $value, 2);

        if ($domain === '') {
            return;
        }

        if (! $this->hasDnsRecord($domain, 'MX') && ! $this->hasDnsRecord($domain, 'A')) {
            $fail(__('This email address is not valid. Please check for typos.'));
        }
    }

    private function hasDnsRecord(string $domain, string $type): bool
    {
        return $this->dnsLookup !== null
            ? ($this->dnsLookup)($domain, $type)
            : checkdnsrr($domain, $type);
    }
}
