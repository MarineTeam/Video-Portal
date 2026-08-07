<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\QueryMonitor\QueryReport;

require_once dirname(__DIR__) . '/plugins/query-monitor/src/QueryReport.php';

/**
 * Reading a request's query log.
 *
 * The number worth having is not "how many queries" — that tells nobody what
 * to do — but "which statement ran twenty-eight times". These tests are mostly
 * about that: which findings surface, in which order, and which do not surface
 * at all because they are normal.
 */
final class QueryReportTest extends TestCase
{
    // ------------------------------------------------------------- summarise

    /**
     * The counters, not the array.
     *
     * Db caps its log at 500 entries and its counters are unbounded, so a page
     * issuing a thousand statements — precisely the page this exists to catch
     * — would report 500 if the summary counted the array. The same mistake
     * made three earlier tests in this project vacuous.
     */
    public function testTheTotalComesFromTheCounterAndNotTheLog(): void
    {
        $log = array_fill(0, 500, ['sql' => 'SELECT 1', 'ms' => 0.1, 'rows' => 1]);

        $summary = QueryReport::summarise($log, 1200, 480.0);

        self::assertSame(1200, $summary['queries']);
        self::assertSame(500, $summary['logged']);
        self::assertTrue($summary['truncated'], 'a truncated log makes every count below it a floor');
    }

    public function testAnUntruncatedLogSaysSo(): void
    {
        $summary = QueryReport::summarise(
            [['sql' => 'SELECT 1', 'ms' => 0.5, 'rows' => 1]],
            1,
            0.5
        );

        self::assertFalse($summary['truncated']);
    }

    public function testTheSlowestStatementIsFound(): void
    {
        $summary = QueryReport::summarise([
            ['sql' => 'SELECT a', 'ms' => 1.0, 'rows' => 1],
            ['sql' => 'SELECT b', 'ms' => 84.5, 'rows' => 9],
            ['sql' => 'SELECT c', 'ms' => 3.0, 'rows' => 2],
        ], 3, 88.5);

        self::assertNotNull($summary['slowest']);
        self::assertSame('SELECT b', $summary['slowest']['sql']);
        self::assertSame(84.5, $summary['slowest']['ms']);
    }

    public function testAnEmptyRequestHasNoSlowestStatement(): void
    {
        $summary = QueryReport::summarise([], 0, 0.0);

        self::assertNull($summary['slowest']);
        self::assertSame(0, $summary['queries']);
        self::assertFalse($summary['truncated']);
    }

    // ------------------------------------------------------------ duplicates

    public function testAStatementInALoopIsFound(): void
    {
        $log = [];
        for ($i = 0; $i < 28; $i++) {
            $log[] = ['sql' => 'SELECT * FROM videos WHERE id = ?', 'ms' => 0.4, 'rows' => 1];
        }
        $log[] = ['sql' => 'SELECT COUNT(*) FROM videos', 'ms' => 1.0, 'rows' => 1];

        $duplicates = QueryReport::duplicates($log);

        self::assertCount(1, $duplicates);
        self::assertSame(28, $duplicates[0]['times']);
        self::assertSame('SELECT * FROM videos WHERE id = ?', $duplicates[0]['sql']);
    }

    /**
     * A count and a page of rows is two queries and exactly right. Reporting it
     * would train people to ignore the panel, which costs more than the finding
     * is worth.
     */
    public function testAStatementRunTwiceIsNotAFinding(): void
    {
        self::assertSame([], QueryReport::duplicates([
            ['sql' => 'SELECT * FROM videos LIMIT 20', 'ms' => 1.0, 'rows' => 20],
            ['sql' => 'SELECT * FROM videos LIMIT 20', 'ms' => 1.0, 'rows' => 20],
        ]));
    }

    public function testNothingRepeatedIsNoFindings(): void
    {
        self::assertSame([], QueryReport::duplicates([
            ['sql' => 'SELECT a', 'ms' => 1.0, 'rows' => 1],
            ['sql' => 'SELECT b', 'ms' => 1.0, 'rows' => 1],
            ['sql' => 'SELECT c', 'ms' => 1.0, 'rows' => 1],
        ]));
    }

    /**
     * By count, not by duration.
     *
     * A statement that ran forty times in nine milliseconds is still the bug —
     * the fix is to stop running it forty times. Sorting by time would bury it
     * under one slow join that is behaving exactly as designed.
     */
    public function testTheMostRepeatedComesFirstEvenWhenItIsNotTheSlowest(): void
    {
        $log = [];
        for ($i = 0; $i < 40; $i++) {
            $log[] = ['sql' => 'SELECT tiny', 'ms' => 0.2, 'rows' => 1];
        }
        for ($i = 0; $i < 4; $i++) {
            $log[] = ['sql' => 'SELECT enormous', 'ms' => 30.0, 'rows' => 900];
        }

        $duplicates = QueryReport::duplicates($log);

        self::assertSame('SELECT tiny', $duplicates[0]['sql']);
        self::assertSame(40, $duplicates[0]['times']);
        self::assertSame('SELECT enormous', $duplicates[1]['sql']);
        // And the total time is still reported, so the slow one is not hidden.
        self::assertSame(120.0, $duplicates[1]['ms']);
    }

    /**
     * Statements in this codebase are written across several lines. Grouping on
     * the raw text would count a reformatted query separately from itself.
     */
    public function testTheSameStatementFormattedDifferentlyIsOneStatement(): void
    {
        $duplicates = QueryReport::duplicates([
            ['sql' => "SELECT *\n  FROM videos\n  WHERE id = ?", 'ms' => 0.4, 'rows' => 1],
            ['sql' => 'SELECT * FROM videos WHERE id = ?', 'ms' => 0.4, 'rows' => 1],
            ['sql' => "SELECT *   FROM videos   WHERE id = ?", 'ms' => 0.4, 'rows' => 1],
        ]);

        self::assertCount(1, $duplicates);
        self::assertSame(3, $duplicates[0]['times']);
    }

    /**
     * Two different statements to MySQL, so two rows here. Folding them
     * together would hide a loop issuing differently-sized batches, which is
     * the failure this whole panel is for.
     */
    public function testBatchesOfDifferentSizesAreDifferentStatements(): void
    {
        $log = [];
        for ($i = 0; $i < 3; $i++) {
            $log[] = ['sql' => 'SELECT * FROM videos WHERE id IN (?, ?, ?)', 'ms' => 0.4, 'rows' => 3];
        }
        for ($i = 0; $i < 3; $i++) {
            $log[] = ['sql' => 'SELECT * FROM videos WHERE id IN (?, ?)', 'ms' => 0.4, 'rows' => 2];
        }

        self::assertCount(2, QueryReport::duplicates($log));
    }

    // ------------------------------------------------------------------ slow

    public function testOnlyGenuinelySlowStatementsAreListed(): void
    {
        $slow = QueryReport::slow([
            ['sql' => 'SELECT fast', 'ms' => 2.0, 'rows' => 1],
            ['sql' => 'SELECT slow', 'ms' => 90.0, 'rows' => 400],
            ['sql' => 'SELECT slower', 'ms' => 210.0, 'rows' => 8000],
        ]);

        self::assertCount(2, $slow);
        self::assertSame('SELECT slower', $slow[0]['sql'], 'slowest first');
        self::assertSame(8000, $slow[0]['rows']);
    }

    public function testAFastRequestHasNoSlowStatements(): void
    {
        self::assertSame([], QueryReport::slow([
            ['sql' => 'SELECT a', 'ms' => 1.0, 'rows' => 1],
            ['sql' => 'SELECT b', 'ms' => 49.9, 'rows' => 1],
        ]));
    }

    // --------------------------------------------------------------- verdict

    /**
     * The panel is a wall of numbers and most people read one line. It has to
     * name the worst finding rather than averaging the request into a grade.
     */
    public function testARepeatedStatementOutranksASlowOneInTheVerdict(): void
    {
        $log = [];
        for ($i = 0; $i < 12; $i++) {
            $log[] = ['sql' => 'SELECT looped', 'ms' => 0.3, 'rows' => 1];
        }
        $log[] = ['sql' => 'SELECT slow', 'ms' => 300.0, 'rows' => 90];

        $verdict = QueryReport::verdict(
            QueryReport::summarise($log, count($log), 303.6),
            QueryReport::duplicates($log),
            QueryReport::slow($log)
        );

        self::assertStringContainsString('12 times', $verdict);
        self::assertStringContainsString('loop', $verdict);
    }

    public function testASlowStatementIsNamedWhenNothingRepeated(): void
    {
        $log = [['sql' => 'SELECT slow', 'ms' => 300.0, 'rows' => 90]];

        $verdict = QueryReport::verdict(
            QueryReport::summarise($log, 1, 300.0),
            QueryReport::duplicates($log),
            QueryReport::slow($log)
        );

        self::assertStringContainsString('300', $verdict);
        self::assertStringContainsString('index', $verdict);
    }

    public function testAHealthyRequestSaysSoWithoutAlarm(): void
    {
        $log = [
            ['sql' => 'SELECT a', 'ms' => 1.0, 'rows' => 1],
            ['sql' => 'SELECT b', 'ms' => 1.0, 'rows' => 1],
        ];

        $verdict = QueryReport::verdict(
            QueryReport::summarise($log, 2, 2.0),
            QueryReport::duplicates($log),
            QueryReport::slow($log)
        );

        self::assertStringContainsString('nothing repeated', $verdict);
        self::assertStringContainsString('2 queries', $verdict);
    }

    public function testARequestWithNoQueriesSaysThat(): void
    {
        $verdict = QueryReport::verdict(QueryReport::summarise([], 0, 0.0), [], []);

        self::assertStringContainsString('No database queries', $verdict);
    }

    public function testTheSingularIsUsedForOneQuery(): void
    {
        $log = [['sql' => 'SELECT a', 'ms' => 1.0, 'rows' => 1]];

        $verdict = QueryReport::verdict(QueryReport::summarise($log, 1, 1.0), [], []);

        self::assertStringContainsString('1 query in', $verdict);
    }

    // ------------------------------------------------------------------ tidy

    public function testVeryLongSqlIsCut(): void
    {
        $tidied = QueryReport::tidy('SELECT ' . str_repeat('column_name, ', 200));

        self::assertLessThan(320, strlen($tidied));
        self::assertStringEndsWith('…', $tidied);
    }

    public function testShortSqlIsLeftAlone(): void
    {
        self::assertSame('SELECT 1', QueryReport::tidy('  SELECT 1  '));
    }
}
