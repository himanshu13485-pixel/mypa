<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A small Excel (.xlsx) writer.
 *
 * Enough for a register or a statement: several sheets, a bold title, a
 * shaded header row that stays put while you scroll, money in #,##0.00, and
 * column widths. No formulas, no charts - the figures are already worked
 * out by the time they get here.
 *
 * Written by hand rather than pulled in as a library because an .xlsx is a
 * zip of a handful of short XML files, and a dependency for that would be a
 * composer update on the server for every deploy that touches it.
 *
 * A cell is a plain value (a string, int or float; null leaves it empty) or
 * ['v' => value, 's' => style] for one of the styles below.
 */
final class Xlsx
{
    public const PLAIN = 0;
    public const HEADER = 1;
    public const MONEY = 2;
    public const BOLD_MONEY = 3;
    public const TITLE = 4;
    public const BOLD = 5;

    /** @var array<int, array{name: string, rows: array, widths: array, freeze: int}> */
    private array $sheets = [];

    /** A money cell. */
    public static function money(float|int|null $value, bool $bold = false): array
    {
        return ['v' => round((float) $value, 2), 's' => $bold ? self::BOLD_MONEY : self::MONEY];
    }

    public static function cell(mixed $value, int $style): array
    {
        return ['v' => $value, 's' => $style];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, float|int>  $widths  character widths, by column from 0
     * @param  int  $freeze  rows kept in view at the top (0 for none)
     */
    public function sheet(string $name, array $rows, array $widths = [], int $freeze = 0): self
    {
        $clean = trim(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $name)) ?: 'Sheet' . (count($this->sheets) + 1);

        $this->sheets[] = [
            'name' => mb_substr($clean, 0, 31),
            'rows' => $rows,
            'widths' => $widths,
            'freeze' => $freeze,
        ];

        return $this;
    }

    /** Write the workbook to a temporary file and return its path. */
    public function toTempFile(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the spreadsheet file.');
        }

        $count = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($count));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($count));
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->worksheet($sheet));
        }

        $zip->close();

        return $path;
    }

    // ---- The parts --------------------------------------------------------

    private function contentTypes(int $count): string
    {
        $sheets = '';
        for ($i = 1; $i <= $count; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets
            . '</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="' . $this->escape($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRels(int $count): string
    {
        $rels = '';
        for ($i = 1; $i <= $count; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param array{name: string, rows: array, widths: array, freeze: int} $sheet */
    private function worksheet(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($sheet['freeze'] > 0) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="' . $sheet['freeze'] . '" topLeftCell="A' . ($sheet['freeze'] + 1) . '" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        }

        if ($sheet['widths'] !== []) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $i => $width) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach (array_values($sheet['rows']) as $r => $row) {
            $number = $r + 1;
            $xml .= '<row r="' . $number . '">';
            foreach (array_values($row) as $c => $cell) {
                $xml .= $this->cellXml(self::column($c) . $number, $cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private function cellXml(string $ref, mixed $cell): string
    {
        $style = self::PLAIN;
        $value = $cell;
        if (is_array($cell)) {
            $style = (int) ($cell['s'] ?? self::PLAIN);
            $value = $cell['v'] ?? null;
        }

        if ($value === null || $value === '') {
            return $style === self::PLAIN ? '' : '<c r="' . $ref . '" s="' . $style . '"/>';
        }

        $s = $style === self::PLAIN ? '' : ' s="' . $style . '"';

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . $this->number($value) . '</v></c>';
        }
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
            . $this->escape((string) $value) . '</t></is></c>';
    }

    /** A number as Excel reads it: no thousands separator, no exponent. */
    private function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    private function escape(string $text): string
    {
        // Characters XML 1.0 cannot carry at all, then the five it must escape.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
