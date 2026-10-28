<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapCsvColumn;
use Pocta\DataMapper\Mapper;

/**
 * fromCsv() (string) and fromCsvFile() (file) must parse the same CSV identically.
 */
class CsvSourcesConsistencyTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/dm-csv-consistency-' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @return array<string, array{string, bool, class-string}>
     */
    public static function csvProvider(): array
    {
        return [
            'header and rows' => ["name,qty\na,1\nb,2\n", true, CsvConsistencyRow::class],
            'blank lines between rows' => ["name,qty\na,1\n\n\nb,2\n", true, CsvConsistencyRow::class],
            'quoted newline' => ["name,qty\n\"multi\nline\",3\n", true, CsvConsistencyRow::class],
            'no trailing newline' => ["name,qty\na,1", true, CsvConsistencyRow::class],
            'header only' => ["name,qty\n", true, CsvConsistencyRow::class],
            'empty' => ['', true, CsvConsistencyRow::class],
            'without header (positional)' => ["a,1\nb,2\n", false, CsvConsistencyCtorRow::class],
            'mapped columns' => ["product,amount\nx,5\n", true, CsvConsistencyMappedRow::class],
            'leading blank line before header' => ["\nname,qty\na,1\n", true, CsvConsistencyRow::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('csvProvider')]
    public function testStringAndFileGiveSameResult(string $csv, bool $hasHeader, string $class): void
    {
        file_put_contents($this->file, $csv);
        $mapper = new Mapper();

        $fromString = $mapper->fromCsv($csv, $class, hasHeader: $hasHeader);
        $fromFile = $mapper->fromCsvFile($this->file, $class, hasHeader: $hasHeader);

        $this->assertEquals($fromString, $fromFile);
    }

    public function testLeadingBlankLineIsSkippedInFile(): void
    {
        file_put_contents($this->file, "\nname,qty\na,1\n");

        $rows = (new Mapper())->fromCsvFile($this->file, CsvConsistencyRow::class);

        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]->name);
        $this->assertSame(1, $rows[0]->qty);
    }

    public function testMissingFileStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV file not found');

        (new Mapper())->fromCsvFile($this->file . '.missing', CsvConsistencyRow::class);
    }
}

class CsvConsistencyRow
{
    public string $name = 'def';

    public int $qty = 0;
}

class CsvConsistencyCtorRow
{
    public function __construct(public string $name, public int $qty)
    {
    }
}

class CsvConsistencyMappedRow
{
    #[MapCsvColumn(name: 'product')]
    public string $name = '';

    #[MapCsvColumn(name: 'amount')]
    public int $qty = 0;
}
