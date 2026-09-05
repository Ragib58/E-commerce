<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\ExportFormat;
use App\Enums\ReportPeriod;
use App\Enums\ReportType;
use App\Models\Order;
use App\Models\Product;
use App\Services\Reporting\Export\ExcelExporter;
use App\Services\Reporting\ExportService;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Report exports.
 *
 * The security assertion here is the formula-injection one. A CSV cell
 * beginning `=` executes when the file is opened in Excel or Sheets, and these
 * reports carry customer-supplied strings — a product name, someone's display
 * name. An export that passes those through unescaped turns "download the sales
 * report" into a way to run a formula on a finance machine.
 */
final class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function exports(): ExportService
    {
        return $this->app->make(ExportService::class);
    }

    private function range(): DateRange
    {
        return DateRange::forPeriod(ReportPeriod::Last30Days);
    }

    /**
     * Run a StreamedResponse and capture what it wrote.
     *
     * The exporter calls `ob_flush()`/`flush()` after each row, which pushes
     * bytes past a plain `ob_start()` buffer and out to the real output — so
     * the naive capture returns an empty string while the content appears in
     * PHPUnit's "unexpected output" report instead.
     *
     * Wrapping the send in a callback that appends to a variable, rather than
     * relying on the buffer's contents at the end, captures every row
     * regardless of how often the exporter flushes.
     */
    private function capture(StreamedResponse $response): string
    {
        $captured = '';

        ob_start(function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        });

        $response->sendContent();

        ob_end_flush();

        return $captured;
    }

    /*
    |--------------------------------------------------------------------------
    | CSV
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_csv_export_carries_the_reports_headers_and_rows(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => 'Ada Lovelace']);

        $response = $this->exports()->export(
            ReportType::Orders,
            ExportFormat::Csv,
            $this->range(),
        );

        $body = $this->capture($response);

        $this->assertStringContainsString('Customer', $body);
        $this->assertStringContainsString('Ada Lovelace', $body);

        // Money is a plain decimal — a symbol or a thousands separator would
        // make the column text the spreadsheet cannot sum.
        $this->assertStringContainsString('100.00', $body);
    }

    #[Test]
    public function a_csv_cell_that_looks_like_a_formula_is_neutralised(): void
    {
        Order::factory()->paid()->totals(10_000)->create([
            'customer_name' => '=HYPERLINK("http://evil.test","click")',
        ]);

        $body = $this->capture($this->exports()->export(
            ReportType::Orders,
            ExportFormat::Csv,
            $this->range(),
        ));

        // The text survives, but never as the first character of the cell —
        // so a spreadsheet renders it rather than executing it.
        $this->assertStringContainsString('HYPERLINK', $body);
        $this->assertStringNotContainsString('"=HYPERLINK', $body);
        $this->assertStringContainsString("\t=HYPERLINK", $body);
    }

    #[Test]
    public function csv_money_carries_no_thousands_separator(): void
    {
        // Large enough to be grouped by a human-facing formatter.
        Order::factory()->paid()->totals(1_234_567)->create();

        $body = $this->capture($this->exports()->export(
            ReportType::Orders,
            ExportFormat::Csv,
            $this->range(),
        ));

        // "12,345.67" is text a spreadsheet cannot sum — and a
        // comma-delimited parser may split it across two columns.
        $this->assertStringContainsString('12345.67', $body);
        $this->assertStringNotContainsString('12,345.67', $body);
    }

    #[Test]
    public function a_csv_export_opens_with_a_utf8_bom(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => 'Zoë Müller']);

        $body = $this->capture($this->exports()->export(
            ReportType::Orders,
            ExportFormat::Csv,
            $this->range(),
        ));

        // Without it Excel on Windows reads the file as the legacy codepage
        // and mangles every non-ASCII name.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Zoë Müller', $body);
    }

    #[Test]
    public function the_csv_filename_names_the_report_and_its_window(): void
    {
        $response = $this->exports()->export(ReportType::Sales, ExportFormat::Csv, $this->range());

        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('sales-report_', (string) $disposition);
        $this->assertStringContainsString('.csv', (string) $disposition);
    }

    /*
    |--------------------------------------------------------------------------
    | Excel
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_excel_export_is_a_readable_workbook(): void
    {
        if (! $this->app->make(ExcelExporter::class)->supported()) {
            $this->markTestSkipped('The zip extension is not available.');
        }

        Order::factory()->paid()->totals(12_345)->create(['customer_name' => 'Ada Lovelace']);

        $response = $this->exports()->export(ReportType::Orders, ExportFormat::Excel, $this->range());

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The workbook is not a readable archive.');

        // The parts Excel requires before it will open a file at all.
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
            $this->assertNotFalse($zip->locateName($part), "Missing {$part}");
        }

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Ada Lovelace', $sheet);

        // Money is written as a number, in major units, so the recipient can
        // sum the column.
        $this->assertStringContainsString('123.45', $sheet);
    }

    #[Test]
    public function an_excel_export_escapes_characters_that_would_break_the_xml(): void
    {
        if (! $this->app->make(ExcelExporter::class)->supported()) {
            $this->markTestSkipped('The zip extension is not available.');
        }

        Order::factory()->paid()->totals(1_000)->create(['customer_name' => 'Smith & Sons <Ltd>']);

        $response = $this->exports()->export(ReportType::Orders, ExportFormat::Excel, $this->range());

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        // A raw ampersand would make the whole workbook unopenable.
        $this->assertStringContainsString('Smith &amp; Sons &lt;Ltd&gt;', $sheet);

        $this->assertNotFalse(
            simplexml_load_string($sheet),
            'The generated sheet is not well-formed XML.',
        );
    }

    #[Test]
    public function the_excel_content_type_matches_the_extension(): void
    {
        if (! $this->app->make(ExcelExporter::class)->supported()) {
            $this->markTestSkipped('The zip extension is not available.');
        }

        $response = $this->exports()->export(ReportType::Sales, ExportFormat::Excel, $this->range());

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_pdf_export_produces_a_pdf(): void
    {
        Product::factory()->published()->create(['name' => 'Widget', 'stock' => 4, 'low_stock_threshold' => 5]);

        $response = $this->exports()->export(ReportType::Inventory, ExportFormat::Pdf, $this->range());

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_export_larger_than_the_format_allows_is_refused_before_it_starts(): void
    {
        config()->set('reporting.limits.max_export_rows', 2);

        Order::factory()->count(4)->paid()->totals(1_000)->create();

        $this->expectException(ValidationException::class);

        // Refused immediately rather than after streaming a partial file the
        // client has already begun receiving with a 200.
        $this->exports()->export(ReportType::Orders, ExportFormat::Csv, $this->range());
    }

    #[Test]
    public function the_refusal_says_how_to_narrow_the_export(): void
    {
        config()->set('reporting.limits.max_export_rows', 1);

        Order::factory()->count(3)->paid()->totals(1_000)->create();

        try {
            $this->exports()->export(ReportType::Orders, ExportFormat::Csv, $this->range());
            $this->fail('An oversized export should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Narrow the date range',
                $exception->validator->errors()->first('format'),
            );
        }
    }

    #[Test]
    public function available_formats_report_what_this_installation_can_produce(): void
    {
        $formats = array_column($this->exports()->availableFormats(), 'value');

        // CSV needs nothing beyond PHP itself, so it is always offered.
        $this->assertContains('csv', $formats);
    }
}
