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

        [$username, $domain] = explode('@', $value, 2);

        if ($domain === '') {
            return;
        }

        $lowerDomain = strtolower($domain);

        // Check for common fake Gmail structures
        if ($lowerDomain === 'gmail.com' || $lowerDomain === 'googlemail.com') {
            $rawUsername = str_replace('.', '', $username);

            if (strlen($rawUsername) < 6 || strlen($rawUsername) > 30) {
                $fail(__('Gmail usernames must be between 6 and 30 characters.'));

                return;
            }

            if (str_starts_with($username, '.') || str_ends_with($username, '.') || str_contains($username, '..')) {
                $fail(__('Gmail addresses cannot begin, end, or contain consecutive dots.'));

                return;
            }

            if (! preg_match('/^[a-zA-Z0-9.]+$/', $username)) {
                $fail(__('Gmail addresses can only contain letters, numbers, and periods.'));

                return;
            }
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
