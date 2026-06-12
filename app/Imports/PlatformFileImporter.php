<?php

namespace App\Imports;

use App\Models\ImportJob;
use App\Models\Shop;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * Shared plumbing for every per-platform export importer (ROADMAP Phase
 * 4/5): the target Shop arrives via the ImportJob context (shop_id) — the
 * file itself cannot say which Shop it belongs to — plus header-cell and
 * Thai-wall-clock → UTC timestamp helpers (an unparseable non-empty value
 * is fail-loud, never guessed, ADR 0005).
 */
abstract class PlatformFileImporter implements Importer, ImportJobAware
{
    private ?ImportJob $importJob = null;

    private ?Shop $shop = null;

    public function setImportJob(ImportJob $importJob): void
    {
        $this->importJob = $importJob;
    }

    /**
     * The timestamp formats this platform's export writes, tried in order.
     *
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        return ['Y-m-d H:i:s', 'Y-m-d H:i'];
    }

    protected function parseBangkokTime(mixed $value, string $column): ?DateTimeImmutable
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '') {
            return null;
        }

        $bangkok = new DateTimeZone('Asia/Bangkok');

        foreach ($this->dateFormats() as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text, $bangkok);

            if ($parsed !== false) {
                return $parsed->setTimezone(new DateTimeZone('UTC'));
            }
        }

        throw new RowImportException("ระบบไม่รองรับ — unparseable timestamp [{$text}] in [{$column}].");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function cell(array $row, string $header): string
    {
        $value = $row[$header] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A money cell: a plain baht number, defensively stripped of currency
     * markers / thousands commas; empty = zero (ADR 0015).
     *
     * @param  array<string, mixed>  $row
     */
    protected function moneyCell(array $row, string $header): Money
    {
        $value = str_replace(['฿', 'THB', ',', ' '], '', $this->cell($row, $header));

        return Money::fromBaht($value === '' ? '0' : $value);
    }

    protected function shop(): Shop
    {
        if ($this->shop !== null) {
            return $this->shop;
        }

        $shopId = $this->importJob?->context['shop_id'] ?? null;

        if (! is_numeric($shopId)) {
            throw new LogicException('A platform file import needs a shop_id in its ImportJob context.');
        }

        return $this->shop = Shop::query()->findOrFail((int) $shopId);
    }
}
