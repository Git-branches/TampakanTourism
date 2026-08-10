<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rule-based validation shared by HTML forms and JSON endpoints.
 *
 * Client-side validation in TourSync is a convenience for the tourist filling
 * the logbook on a phone. This class is the one that actually decides.
 *
 * Usage:
 *   $v = new Validator($_POST);
 *   $v->require('tourist_type')->in('tourist_type', ['local','domestic']);
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    private array $data;
    private array $errors = [];

    private const LABELS = [
        'username'       => 'Username',
        'password'       => 'Password',
        'full_name'      => 'Full name',
        'email'          => 'Email address',
        'contact_number' => 'Contact number',
        'tourist_type'   => 'Tourist type',
    ];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function value(string $field, $default = null)
    {
        $v = $this->data[$field] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    private function label(string $field): string
    {
        return self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private function fail(string $field, string $message): void
    {
        // Keep only the first error per field — a form showing four
        // complaints about one input is hostile to read.
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    /** True when the field is present and not blank. */
    private function filled(string $field): bool
    {
        $v = $this->value($field);
        return $v !== null && $v !== '' && $v !== [];
    }

    public function require(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (!$this->filled($field)) {
                $this->fail($field, $this->label($field) . ' is required.');
            }
        }
        return $this;
    }

    public function length(string $field, int $min, int $max): self
    {
        if (!$this->filled($field)) return $this;

        $len = mb_strlen((string) $this->value($field));
        if ($len < $min) {
            $this->fail($field, $this->label($field) . " must be at least {$min} characters.");
        } elseif ($len > $max) {
            $this->fail($field, $this->label($field) . " must not exceed {$max} characters.");
        }
        return $this;
    }

    public function email(string $field): self
    {
        if ($this->filled($field) && !filter_var($this->value($field), FILTER_VALIDATE_EMAIL)) {
            $this->fail($field, 'Enter a valid email address.');
        }
        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        if ($this->filled($field) && !in_array($this->value($field), $allowed, true)) {
            $this->fail($field, 'Choose one of the available options.');
        }
        return $this;
    }

    public function integer(string $field, ?int $min = null, ?int $max = null): self
    {
        if (!$this->filled($field)) return $this;

        $v = filter_var($this->value($field), FILTER_VALIDATE_INT);
        if ($v === false) {
            $this->fail($field, $this->label($field) . ' must be a whole number.');
            return $this;
        }
        if ($min !== null && $v < $min) {
            $this->fail($field, $this->label($field) . " must be at least {$min}.");
        }
        if ($max !== null && $v > $max) {
            $this->fail($field, $this->label($field) . " must not exceed {$max}.");
        }
        return $this;
    }

    /**
     * Philippine mobile number, stored in E.164. Accepts 09XXXXXXXXX,
     * +639XXXXXXXXX, and 639XXXXXXXXX — all three appear on paper forms.
     */
    public function mobile(string $field): self
    {
        if (!$this->filled($field)) return $this;

        $digits = preg_replace('/\D/', '', (string) $this->value($field));

        if (preg_match('/^09\d{9}$/', $digits)) {
            $this->data[$field] = '+63' . substr($digits, 1);
        } elseif (preg_match('/^639\d{9}$/', $digits)) {
            $this->data[$field] = '+' . $digits;
        } else {
            $this->fail($field, 'Enter a valid Philippine mobile number, for example 0917 123 4567.');
        }
        return $this;
    }

    public function date(string $field): self
    {
        if (!$this->filled($field)) return $this;

        $v = (string) $this->value($field);
        $d = \DateTime::createFromFormat('Y-m-d', $v);

        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->fail($field, 'Enter a valid date.');
        }
        return $this;
    }

    public function matches(string $field, string $otherField): self
    {
        if ($this->filled($field) && $this->value($field) !== $this->value($otherField)) {
            $this->fail($field, 'The two entries do not match.');
        }
        return $this;
    }

    /** Adds an error decided outside the rule set. */
    public function addError(string $field, string $message): self
    {
        $this->fail($field, $message);
        return $this;
    }

    public function fails(): bool  { return $this->errors !== []; }
    public function passes(): bool { return $this->errors === []; }
    public function errors(): array { return $this->errors; }
    public function firstError(): ?string { return $this->errors ? reset($this->errors) : null; }

    /** Returns only the named fields, after any normalisation the rules applied. */
    public function only(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $out[$f] = $this->value($f);
        }
        return $out;
    }
}
