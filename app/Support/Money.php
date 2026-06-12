<?php

namespace App\Support;

/**
 * A THB amount as an integer count of satang (ADR 0015). Never float.
 */
final readonly class Money
{
    private function __construct(
        public int $satang,
    ) {}

    public static function fromSatang(int $satang): self
    {
        return new self($satang);
    }

    /**
     * Parse a baht amount from its decimal-string form ("129.50") without
     * ever passing through float. Used at the UI/import boundary (ADR 0015).
     */
    public static function fromBaht(string $baht): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $baht, $m) !== 1) {
            throw new \InvalidArgumentException("Not a valid baht amount: \"{$baht}\"");
        }

        $satang = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return new self($m[1] === '-' ? -$satang : $satang);
    }

    public function add(self $other): self
    {
        return new self($this->satang + $other->satang);
    }

    public function subtract(self $other): self
    {
        return new self($this->satang - $other->satang);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->satang * $quantity);
    }

    public function negate(): self
    {
        return new self(-$this->satang);
    }

    public function equals(self $other): bool
    {
        return $this->satang === $other->satang;
    }

    public function isZero(): bool
    {
        return $this->satang === 0;
    }

    public function isNegative(): bool
    {
        return $this->satang < 0;
    }

    /**
     * Canonical 2-decimal baht string ("129.50") for the UI/export boundary.
     */
    public function toBaht(): string
    {
        $abs = abs($this->satang);

        return ($this->satang < 0 ? '-' : '')
            .intdiv($abs, 100)
            .'.'
            .str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
