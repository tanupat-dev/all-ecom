<?php

namespace App\Support\Xlsx;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/**
 * Zip-level surgery on an xlsx file (ADR 0019 — Channel Upload Template fill).
 *
 * The platform template carries machine tokens and hidden validation sheets
 * that must NOT be disturbed. This class opens the xlsx (it's a zip), lets
 * callers write cell values at (sheet, rowIndex, colIndex), and saves the
 * result with every other zip entry copied byte-identical (content-level).
 *
 * String cells use t="inlineStr" — valid OOXML, no sharedStrings edits.
 * Numeric cells use the default numeric type (no t attribute, <v> holds value).
 * The <dimension> ref in the worksheet is updated whenever a cell is written
 * beyond the current boundary.
 *
 * Invariant tested by the fill suite: after unzipping both the source and the
 * filled output, every entry except the modified target sheet is byte-equal.
 */
final class WorkbookSurgeon
{
    /** Sheet display name → path within zip (e.g. "xl/worksheets/sheet2.xml")
     *
     * @var array<string, string>
     */
    private array $sheetPaths = [];

    /** Shared strings array (index → text), loaded lazily.
     *
     * @var array<int, string>|null
     */
    private ?array $sharedStrings = null;

    /** DOMDocument instances for sheets we have loaded (keyed by zip path)
     *
     * @var array<string, DOMDocument>
     */
    private array $loadedDoms = [];

    /** Column key prefix → 1-based column index cache per sheet.
     *
     * @var array<string, array<string, int>>
     */
    private array $columnIndexCache = [];

    private ZipArchive $zip;

    public function __construct(private readonly string $sourcePath)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($sourcePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Cannot open xlsx: {$sourcePath}");
        }

        $this->parseWorkbook();
    }

    /**
     * Return the 1-based column index for the first column in $sheetName
     * whose Row-1 machine-key has the given $keyPrefix (prefix before the
     * first `|`). Returns null if not found.
     */
    public function columnIndex(string $sheetName, string $keyPrefix): ?int
    {
        if (isset($this->columnIndexCache[$sheetName][$keyPrefix])) {
            return $this->columnIndexCache[$sheetName][$keyPrefix];
        }

        $dom = $this->getSheetDom($sheetName);
        $ns = $this->worksheetNamespace($dom);

        foreach ($dom->getElementsByTagNameNS($ns, 'row') as $rowEl) {
            /** @var DOMElement $rowEl */
            if ($rowEl->getAttribute('r') !== '1') {
                continue;
            }

            foreach ($rowEl->getElementsByTagNameNS($ns, 'c') as $cellEl) {
                /** @var DOMElement $cellEl */
                $value = $this->readCellValue($cellEl, $ns);
                $prefix = explode('|', $value)[0];

                if ($prefix === $keyPrefix) {
                    $ref = $cellEl->getAttribute('r');
                    $colLetter = rtrim($ref, '0123456789');
                    $idx = self::letterToIndex($colLetter);
                    $this->columnIndexCache[$sheetName][$keyPrefix] = $idx;

                    return $idx;
                }
            }

            // Row 1 found but key not present — stop scanning rows.
            break;
        }

        return null;
    }

    /**
     * Write a value at ($rowIndex, $colIndex) in the named sheet. Strings
     * are stored as inlineStr; integers/floats as bare numeric cells. Fluent.
     */
    public function writeCell(string $sheetName, int $rowIndex, int $colIndex, string|int|float $value): self
    {
        $dom = $this->getSheetDom($sheetName);
        $ns = $this->worksheetNamespace($dom);
        $ref = self::indexToLetter($colIndex).$rowIndex;

        $rowEl = $this->findOrCreateRow($dom, $ns, $rowIndex);
        $cellEl = $this->findOrCreateCell($dom, $ns, $rowEl, $ref, $colIndex);

        // Clear existing child nodes.
        while ($cellEl->hasChildNodes()) {
            $first = $cellEl->firstChild;
            if ($first !== null) {
                $cellEl->removeChild($first);
            }
        }

        if (is_string($value)) {
            $cellEl->setAttribute('t', 'inlineStr');
            $is = $dom->createElement('is');
            $t = $dom->createElement('t');
            $t->appendChild($dom->createTextNode($value));
            $is->appendChild($t);
            $cellEl->appendChild($is);
        } else {
            // Numeric — no t attribute.
            $cellEl->removeAttribute('t');
            $v = $dom->createElement('v');
            $v->appendChild($dom->createTextNode((string) $value));
            $cellEl->appendChild($v);
        }

        $this->updateDimension($dom, $ns, $rowIndex, $colIndex);

        return $this;
    }

    /**
     * Write the filled workbook to $outputPath. Every zip entry except the
     * modified sheet(s) is copied from the source at the content level
     * (decompressed bytes are identical; ZipArchive may choose its own
     * compression, but extracted content is bit-for-bit the same).
     */
    public function save(string $outputPath): void
    {
        // Serialise modified sheets.
        $modified = [];
        foreach ($this->loadedDoms as $zipPath => $dom) {
            $modified[$zipPath] = $dom->saveXML();
        }

        $dst = new ZipArchive;
        $dst->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $content = $modified[$name] ?? $this->zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $dst->addFromString($name, $content);
        }

        $dst->close();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function parseWorkbook(): void
    {
        $workbookXml = $this->zip->getFromName('xl/workbook.xml');
        $relsXml = $this->zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Not a valid xlsx: missing xl/workbook.xml or its .rels.');
        }

        $wbDom = new DOMDocument;
        $wbDom->loadXML($workbookXml);

        $relsDom = new DOMDocument;
        $relsDom->loadXML($relsXml);

        // Build rId → relative target path map.
        $rIdToTarget = [];

        foreach ($relsDom->getElementsByTagNameNS('*', 'Relationship') as $rel) {
            /** @var DOMElement $rel */
            $rIdToTarget[$rel->getAttribute('Id')] = ltrim($rel->getAttribute('Target'), '/');
        }

        // Map sheet name → zip path.
        foreach ($wbDom->getElementsByTagNameNS('*', 'sheet') as $sheet) {
            /** @var DOMElement $sheet */
            $name = $sheet->getAttribute('name');
            // The rId attribute may be in the r: namespace.
            $rId = $sheet->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id'
            );

            if ($rId === '') {
                // Fallback: check without namespace prefix.
                $rId = $sheet->getAttribute('r:id');
            }

            if (isset($rIdToTarget[$rId])) {
                $target = $rIdToTarget[$rId];
                // Targets are relative to xl/ — join properly.
                $this->sheetPaths[$name] = str_starts_with($target, 'xl/')
                    ? $target
                    : 'xl/'.$target;
            }
        }
    }

    private function getSheetDom(string $sheetName): DOMDocument
    {
        $path = $this->sheetPaths[$sheetName]
            ?? throw new RuntimeException("Sheet '{$sheetName}' not found in the workbook.");

        if (! isset($this->loadedDoms[$path])) {
            $xml = $this->zip->getFromName($path);

            if ($xml === false) {
                throw new RuntimeException("Cannot read sheet file '{$path}' from zip.");
            }

            $dom = new DOMDocument;
            $dom->loadXML($xml);
            $this->loadedDoms[$path] = $dom;
        }

        return $this->loadedDoms[$path];
    }

    /**
     * Read the text value of a cell element. Handles shared-string (t="s"),
     * inlineStr (t="inlineStr"), and plain numeric cells.
     */
    private function readCellValue(DOMElement $cell, string $ns): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $tNodes = $cell->getElementsByTagNameNS($ns, 't');
            $node = $tNodes->item(0);

            if ($node === null) {
                return '';
            }

            return $node->textContent;
        }

        if ($type === 's') {
            $vNodes = $cell->getElementsByTagNameNS($ns, 'v');
            $node = $vNodes->item(0);

            if ($node === null) {
                return '';
            }

            return $this->sharedString((int) $node->textContent);
        }

        $vNodes = $cell->getElementsByTagNameNS($ns, 'v');
        $node = $vNodes->item(0);

        if ($node === null) {
            return '';
        }

        return $node->textContent;
    }

    private function sharedString(int $index): string
    {
        if ($this->sharedStrings === null) {
            $this->sharedStrings = $this->loadSharedStrings();
        }

        return $this->sharedStrings[$index] ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function loadSharedStrings(): array
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $strings = [];

        foreach ($dom->getElementsByTagNameNS('*', 'si') as $si) {
            /** @var DOMElement $si */
            // A <si> element is either a single <t> or multiple <r><t> runs.
            $tNodes = $si->getElementsByTagNameNS('*', 't');
            $text = '';

            foreach ($tNodes as $t) {
                $text .= $t->textContent;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Detect the namespace URI used for spreadsheet elements in this sheet.
     * Modern xlsx uses the standard OOXML namespace; older files may use a
     * strict variant. Returns '*' as a safe wildcard fallback.
     */
    private function worksheetNamespace(DOMDocument $dom): string
    {
        $root = $dom->documentElement;

        if ($root === null) {
            return '*';
        }

        $ns = $root->namespaceURI;

        return $ns !== null && $ns !== '' ? $ns : '*';
    }

    /**
     * Find an existing <row r="$rowIndex"> or create one in the correct
     * position (rows must stay sorted in ascending r order).
     */
    private function findOrCreateRow(DOMDocument $dom, string $ns, int $rowIndex): DOMElement
    {
        $sheetData = $dom->getElementsByTagNameNS($ns, 'sheetData')->item(0)
            ?? $dom->getElementsByTagName('sheetData')->item(0);

        if ($sheetData === null) {
            throw new RuntimeException('Malformed sheet: no <sheetData> element.');
        }

        // Try to find existing row.
        foreach ($sheetData->getElementsByTagNameNS($ns, 'row') as $rowEl) {
            /** @var DOMElement $rowEl */
            if ((int) $rowEl->getAttribute('r') === $rowIndex) {
                return $rowEl;
            }
        }

        // Create new row, inserted in sorted position.
        $newRow = $dom->createElement('row');
        $newRow->setAttribute('r', (string) $rowIndex);

        $insertBefore = null;

        foreach ($sheetData->getElementsByTagNameNS($ns, 'row') as $rowEl) {
            /** @var DOMElement $rowEl */
            if ((int) $rowEl->getAttribute('r') > $rowIndex) {
                $insertBefore = $rowEl;
                break;
            }
        }

        if ($insertBefore !== null) {
            $sheetData->insertBefore($newRow, $insertBefore);
        } else {
            $sheetData->appendChild($newRow);
        }

        return $newRow;
    }

    /**
     * Find an existing cell with ref $ref in $rowEl, or create one. Cells
     * within a row must be sorted by column index — new cells are inserted in
     * the correct position.
     */
    private function findOrCreateCell(DOMDocument $dom, string $ns, DOMElement $rowEl, string $ref, int $colIndex): DOMElement
    {
        foreach ($rowEl->getElementsByTagNameNS($ns, 'c') as $cellEl) {
            /** @var DOMElement $cellEl */
            if ($cellEl->getAttribute('r') === $ref) {
                return $cellEl;
            }
        }

        $newCell = $dom->createElement('c');
        $newCell->setAttribute('r', $ref);

        // Insert sorted by column index.
        $insertBefore = null;

        foreach ($rowEl->getElementsByTagNameNS($ns, 'c') as $cellEl) {
            /** @var DOMElement $cellEl */
            $existingRef = $cellEl->getAttribute('r');
            $existingLetter = rtrim($existingRef, '0123456789');
            $existingIdx = self::letterToIndex($existingLetter);

            if ($existingIdx > $colIndex) {
                $insertBefore = $cellEl;
                break;
            }
        }

        if ($insertBefore !== null) {
            $rowEl->insertBefore($newCell, $insertBefore);
        } else {
            $rowEl->appendChild($newCell);
        }

        return $newCell;
    }

    /**
     * Expand the <dimension ref="A1:Zn"> element if the new cell is outside
     * the current boundary. No-op if no <dimension> element is present.
     */
    private function updateDimension(DOMDocument $dom, string $ns, int $rowIndex, int $colIndex): void
    {
        $dimEl = $dom->getElementsByTagNameNS($ns, 'dimension')->item(0);

        if (! $dimEl instanceof DOMElement) {
            return;
        }

        $currentRef = $dimEl->getAttribute('ref');

        // Parse "A1:Z99" or "A1" (single cell).
        if (str_contains($currentRef, ':')) {
            [, $end] = explode(':', $currentRef, 2);
        } else {
            $end = $currentRef;
        }

        $endLetter = rtrim($end, '0123456789');
        $endRow = (int) ltrim($end, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');

        $newEndCol = max(self::letterToIndex($endLetter), $colIndex);
        $newEndRow = max($endRow, $rowIndex);

        $startPart = str_contains($currentRef, ':')
            ? explode(':', $currentRef)[0]
            : $currentRef;

        $dimEl->setAttribute('ref', $startPart.':'.self::indexToLetter($newEndCol).$newEndRow);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Static column letter ↔ index helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert a 1-based column index to the Excel letter reference ("A", "B",
     * …, "Z", "AA", …).
     */
    public static function indexToLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    /**
     * Convert an Excel column letter to a 1-based index ("A" → 1, "B" → 2, …).
     */
    public static function letterToIndex(string $letter): int
    {
        $letter = strtoupper($letter);
        $index = 0;

        foreach (str_split($letter) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index;
    }
}
