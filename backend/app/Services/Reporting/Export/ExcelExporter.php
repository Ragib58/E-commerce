<?php

declare(strict_types=1);

namespace App\Services\Reporting\Export;

use App\Enums\ReportType;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use ZipArchive;

/**
 * Excel (.xlsx) export, written as a minimal OOXML package.
 *
 * ## Why this is hand-rolled rather than PhpSpreadsheet
 *
 * PhpSpreadsheet builds a full object model of a workbook in memory before
 * writing it, which is the wrong shape for this job — an export of fifty
 * thousand order rows would hold all of them at once, and the library is a
 * heavy dependency to add for the one feature used here: a single sheet of
 * flat rows with typed cells.
 *
 * An .xlsx file is a ZIP containing a handful of XML parts. Writing those
 * directly keeps memory flat and adds no dependency, at the cost of supporting
 * no formatting beyond what a report actually needs: a bold header row, numbers
 * that are numbers, and money at two decimal places.
 *
 * ## Numbers are written as numbers
 *
 * The point of offering Excel alongside CSV is that the recipient can sum a
 * column. Money and counts are therefore written as numeric cells with a
 * numeric format applied, not as strings — a column of text that looks like
 * currency is exactly what makes people ask for the export again as "a proper
 * spreadsheet".
 *
 * Because cells are typed, the CSV formula-injection problem does not arise
 * here: a value written into a `t="inlineStr"` cell is never parsed as a
 * formula, which requires an `<f>` element this writer never emits.
 */
final class ExcelExporter
{
    /**
     * Style ids from the `styles.xml` written below. Their order there defines
     * these indices, so the two must be changed together.
     */
    private const STYLE_DEFAULT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_MONEY = 2;

    private const STYLE_PERCENT = 3;

    /**
     * Build the workbook and return its bytes.
     *
     * Returns a string rather than streaming: a ZIP's central directory is
     * written last and records the offset of every entry, so the archive cannot
     * be produced incrementally without seeking. The sheet XML is still built
     * row by row into a temporary file rather than concatenated in memory, so
     * peak usage stays bounded by the largest single row.
     *
     * @param  LazyCollection<int, array<string, mixed>>  $rows
     *
     * @throws RuntimeException when the zip extension is unavailable.
     */
    public function build(ReportType $type, LazyCollection $rows, string $title): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Excel export requires the PHP zip extension. Enable ext-zip, or export as CSV.',
            );
        }

        $archivePath = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($archivePath === false) {
            throw new RuntimeException('A temporary file for the export could not be created.');
        }

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::OVERWRITE) !== true) {
            @unlink($archivePath);

            throw new RuntimeException('The export archive could not be opened for writing.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($title));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($type, $rows));

            $zip->close();

            $contents = file_get_contents($archivePath);

            if ($contents === false) {
                throw new RuntimeException('The generated spreadsheet could not be read back.');
            }

            return $contents;
        } finally {
            @unlink($archivePath);
        }
    }

    public function supported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * The sheet body: a header row followed by one row per record.
     *
     * @param  LazyCollection<int, array<string, mixed>>  $rows
     */
    private function sheet(ReportType $type, LazyCollection $rows): string
    {
        $columns = $type->columns();

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Freeze the header row so it stays visible while scrolling a long
            // report. `sheetViews` must precede `cols` and `sheetData`; Excel
            // rejects the file outright if the parts are out of schema order.
            .'<sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .$this->columnWidths($columns)
            .'<sheetData>';

        $rowNumber = 1;
        $cells = '';

        foreach ($columns as $index => $column) {
            $cells .= $this->inlineStringCell(
                $this->cellReference($index, $rowNumber),
                $column['label'],
                self::STYLE_HEADER,
            );
        }

        $xml .= sprintf('<row r="%d">%s</row>', $rowNumber, $cells);

        foreach ($rows as $row) {
            $rowNumber++;
            $cells = '';

            foreach ($columns as $index => $column) {
                $cells .= $this->cell(
                    $this->cellReference($index, $rowNumber),
                    $row[$column['key']] ?? null,
                    $column['type'],
                );
            }

            $xml .= sprintf('<row r="%d">%s</row>', $rowNumber, $cells);
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * One cell, typed according to its column.
     */
    private function cell(string $reference, mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return sprintf('<c r="%s"/>', $reference);
        }

        return match ($type) {
            /*
             * Money is stored in minor units and written as a decimal, so the
             * cell holds 12.34 rather than 1234 — the recipient is summing
             * currency, not counting pennies. The division is exact in this
             * range: two decimal places of a value well inside the float's
             * 53 bits of integer precision.
             */
            'money' => sprintf(
                '<c r="%s" s="%d"><v>%s</v></c>',
                $reference,
                self::STYLE_MONEY,
                number_format(((int) $value) / 100, 2, '.', ''),
            ),

            'number' => sprintf(
                '<c r="%s"><v>%d</v></c>',
                $reference,
                (int) $value,
            ),

            'percent' => sprintf(
                '<c r="%s" s="%d"><v>%s</v></c>',
                $reference,
                self::STYLE_PERCENT,
                number_format((float) $value, 2, '.', ''),
            ),

            default => $this->inlineStringCell($reference, (string) $value, self::STYLE_DEFAULT),
        };
    }

    /**
     * A string cell written inline rather than through the shared-strings part.
     *
     * The shared string table exists to deduplicate repeated text across a
     * workbook, which would mean holding every distinct string in memory until
     * the sheet is finished — the opposite of what a streaming export wants.
     * Inline strings cost a little file size and keep memory flat.
     */
    private function inlineStringCell(string $reference, string $value, int $style): string
    {
        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference,
            $style,
            $this->escape($value),
        );
    }

    /**
     * Escape text for XML, stripping characters the format forbids.
     *
     * Control characters below 0x20 (other than tab, newline, carriage return)
     * are illegal in XML 1.0 even when escaped, and a single stray byte in a
     * customer-supplied string would make the whole workbook unopenable rather
     * than merely showing odd text.
     */
    private function escape(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Translate a zero-based column index and 1-based row into an A1 reference.
     *
     * Handles the wrap past column Z, which a report with more than 26 columns
     * reaches — the inventory and order reports are close enough that a naive
     * `chr(65 + $index)` would eventually emit `[` as a column letter and
     * corrupt the file.
     */
    private function cellReference(int $columnIndex, int $row): string
    {
        $letters = '';
        $index = $columnIndex;

        do {
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letters.$row;
    }

    /**
     * @param  array<int, array{key: string, label: string, type: string}>  $columns
     */
    private function columnWidths(array $columns): string
    {
        $definitions = '';

        foreach ($columns as $index => $column) {
            // Money and dates need a predictable width; text columns get room
            // for a product name without being so wide the sheet scrolls.
            $width = match ($column['type']) {
                'money', 'number', 'percent' => 14,
                'date' => 20,
                default => 28,
            };

            $definitions .= sprintf(
                '<col min="%d" max="%d" width="%d" customWidth="1"/>',
                $index + 1,
                $index + 1,
                $width,
            );
        }

        return '<cols>'.$definitions.'</cols>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(string $title): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($this->sheetName($title)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    /**
     * Excel rejects a sheet name over 31 characters or containing any of
     * `[]:*?/\`, and rejects the whole file rather than the name.
     */
    private function sheetName(string $title): string
    {
        $clean = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $title);
        $clean = trim($clean);

        if ($clean === '') {
            return 'Report';
        }

        return mb_substr($clean, 0, 31);
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * The style table.
     *
     * `cellXfs` order defines the `s="..."` indices used above — see the
     * STYLE_* constants, which must be kept in step with this list.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // 164 and up are the custom range; below it is reserved for the
            // formats Excel defines itself.
            .'<numFmts count="2">'
            .'<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            .'<numFmt numFmtId="165" formatCode="0.00&quot;%&quot;"/>'
            .'</numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}
