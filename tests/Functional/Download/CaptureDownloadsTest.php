<?php

declare(strict_types=1);

/*
 * This file is part of the community-maintained Playwright PHP project.
 * It is not affiliated with or endorsed by Microsoft.
 *
 * (c) 2025-Present - Playwright PHP - https://github.com/playwright-php
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Playwright\Tests\Functional\Download;

use PHPUnit\Framework\Attributes\CoversClass;
use Playwright\Download\CapturedDownload;
use Playwright\Download\Download;
use Playwright\Exception\TimeoutException;
use Playwright\Page\Options\CaptureDownloadsOptions;
use Playwright\Page\Page;
use Playwright\Tests\Functional\FunctionalTestCase;

#[CoversClass(Page::class)]
#[CoversClass(Download::class)]
#[CoversClass(CapturedDownload::class)]
#[CoversClass(CaptureDownloadsOptions::class)]
final class CaptureDownloadsTest extends FunctionalTestCase
{
    /**
     * Marker the /download/hang fixture writes once the download has started,
     * so tests can wait for the browser to be mid-download.
     */
    private const HANG_MARKER = 'pw-php-download-hang-started';

    /** @var list<string> */
    private array $downloadsDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->downloadsDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->downloadsDirs = [];

        @unlink(sys_get_temp_dir().'/'.self::HANG_MARKER);

        parent::tearDown();
    }

    public function testItReturnsAnEmptyListWhenTheActionTriggersNoDownload(): void
    {
        $this->page->setContent('<button id="noop">nothing to download</button>');
        $dir = $this->makeDownloadsDir();

        $results = $this->page->captureDownloads(
            fn () => $this->page->locator('#noop')->click(),
            $dir,
            ['timeout' => 5000, 'idleTimeout' => 300],
        );

        $this->assertSame([], $results);
        $this->assertSame([], $this->listFiles($dir));
    }

    public function testItCapturesASingleDownloadIntoTheDirectory(): void
    {
        $this->page->setContent(
            '<a id="dl" href="data:text/plain,hello%20world" download="hello.txt">download</a>'
        );
        $dir = $this->makeDownloadsDir();

        $results = $this->page->captureDownloads(
            fn () => $this->page->locator('#dl')->click(),
            $dir,
        );

        $this->assertCount(1, $results);

        $captured = $results[0];
        $this->assertSame(CapturedDownload::STATUS_COMPLETED, $captured->status());
        $this->assertTrue($captured->isSuccessful());
        $this->assertNull($captured->error());
        $this->assertSame('hello.txt', $captured->suggestedFilename());
        $this->assertStringStartsWith('data:text/plain', $captured->url());

        $this->assertSame($dir.'/hello.txt', $captured->path());
        $this->assertSame('hello world', file_get_contents((string) $captured->path()));

        // The returned download handle is live and exposes the same metadata.
        $this->assertInstanceOf(Download::class, $captured->download());
        $this->assertSame('hello.txt', $captured->download()->suggestedFilename());
        $this->assertSame($captured->url(), $captured->download()->url());
        $this->assertNull($captured->download()->failure());

        // The capture never closes the caller's browser or context.
        $this->assertTrue($this->browser->isConnected());
        $extraPage = $this->context->newPage();
        $extraPage->close();
    }

    public function testItCapturesMultipleDownloadsAndDeduplicatesNames(): void
    {
        $this->page->setContent(<<<'HTML'
            <a id="a1" href="data:text/plain,alpha" download="report.txt">1</a>
            <a id="a2" href="data:text/plain,bravo" download="report.txt">2</a>
            <a id="a3" href="data:text/plain,charlie" download="report.txt">3</a>
            <a id="a4" href="data:text/plain,escape" download="../escape.txt">4</a>
            HTML);
        $dir = $this->makeDownloadsDir();

        $results = $this->page->captureDownloads(function (): void {
            $this->page->locator('#a1')->click();
            $this->page->locator('#a2')->click();
            $this->page->locator('#a3')->click();
            $this->page->locator('#a4')->click();
        }, $dir);

        $this->assertCount(4, $results);

        $paths = [];
        foreach ($results as $captured) {
            $this->assertTrue($captured->isSuccessful(), 'download failed: '.$captured->error());
            $path = $captured->path();
            $this->assertNotNull($path);
            // Whatever the browser suggests, the file must land directly in
            // the capture directory - never outside of it.
            $this->assertSame($dir, \dirname($path));
            $this->assertStringNotContainsString('..', \basename($path));
            $paths[] = \basename($path);
        }

        // Same suggested name three times: every file is kept, names are unique.
        $this->assertCount(4, array_unique($paths));

        $reportNames = array_values(array_filter($paths, static fn (string $p): bool => str_starts_with($p, 'report')));
        sort($reportNames);
        $this->assertSame(['report (1).txt', 'report (2).txt', 'report.txt'], $reportNames);

        // The traversal attempt collapsed to a plain name inside the directory
        // (the exact sanitization is the browser's; the location is ours).
        $escapeNames = array_values(array_diff($paths, $reportNames));
        $this->assertCount(1, $escapeNames);
        $this->assertStringEndsWith('escape.txt', $escapeNames[0]);

        $contents = array_map(
            static fn (string $name): string => (string) file_get_contents($dir.'/'.$name),
            $paths
        );
        sort($contents);
        $this->assertSame(['alpha', 'bravo', 'charlie', 'escape'], $contents);
    }

    public function testItReportsFailedDownloadsWithoutLeavingFiles(): void
    {
        // The fixture announces a 1MB attachment and closes after 128 bytes.
        $this->page->setContent(
            '<a id="dl" href="'.$this->getBaseUrl().'/download/broken">download</a>'
        );
        $dir = $this->makeDownloadsDir();

        $results = $this->page->captureDownloads(
            fn () => $this->page->locator('#dl')->click(),
            $dir,
        );

        $this->assertCount(1, $results);

        $captured = $results[0];
        $this->assertSame(CapturedDownload::STATUS_FAILED, $captured->status());
        $this->assertFalse($captured->isSuccessful());
        $this->assertNotNull($captured->error());
        $this->assertNotSame('', $captured->error());
        $this->assertNull($captured->path());
        $this->assertSame('broken.bin', $captured->suggestedFilename());

        // No finished file and no temporary artifact is left behind.
        $this->assertSame([], $this->listFiles($dir));
    }

    public function testConcurrentPagesDoNotInterfere(): void
    {
        $page2 = $this->context->newPage();
        $this->page->setContent('<a id="dl" href="data:text/plain,page-one" download="one.txt">1</a>');
        $page2->setContent('<a id="dl" href="data:text/plain,page-two" download="two.txt">2</a>');

        $dir1 = $this->makeDownloadsDir();
        $dir2 = $this->makeDownloadsDir();

        try {
            // While page one's capture is armed, page two also downloads:
            // the capture must only see its own page's download.
            $results1 = $this->page->captureDownloads(function () use ($page2): void {
                $this->page->locator('#dl')->click();
                $page2->locator('#dl')->click();
            }, $dir1);

            $this->assertCount(1, $results1);
            $this->assertSame('one.txt', $results1[0]->suggestedFilename());
            $this->assertSame('page-one', file_get_contents((string) $results1[0]->path()));
            $this->assertSame(['one.txt'], $this->listFiles($dir1));

            // Page two is fully usable for its own capture afterwards.
            $results2 = $page2->captureDownloads(
                fn () => $page2->locator('#dl')->click(),
                $dir2,
            );

            $this->assertCount(1, $results2);
            $this->assertSame('two.txt', $results2[0]->suggestedFilename());
            $this->assertSame('page-two', file_get_contents((string) $results2[0]->path()));
            $this->assertSame(['two.txt'], $this->listFiles($dir2));
        } finally {
            $page2->close();
        }
    }

    public function testItThrowsOnTimeoutAndCleansUp(): void
    {
        @unlink(sys_get_temp_dir().'/'.self::HANG_MARKER);

        // The fixture drips one byte at a time, so the download never
        // finishes within the capture timeout.
        $this->page->setContent(
            '<a id="dl" href="'.$this->getBaseUrl().'/download/hang">download</a>'
        );
        $dir = $this->makeDownloadsDir();

        try {
            $this->page->captureDownloads(
                fn () => $this->page->locator('#dl')->click(),
                $dir,
                ['timeout' => 1500, 'idleTimeout' => 300],
            );
            $this->fail('Expected a TimeoutException for a download that never finishes');
        } catch (TimeoutException) {
            // expected
        }

        // Neither a finished file nor a temporary artifact remains.
        $this->assertSame([], $this->listFiles($dir));

        // The listener is gone and the page keeps working: a later capture
        // on the same page only sees its own downloads.
        $this->page->setContent(
            '<a id="dl2" href="data:text/plain,after%20timeout" download="after.txt">download</a>'
        );
        $results = $this->page->captureDownloads(
            fn () => $this->page->locator('#dl2')->click(),
            $dir,
        );

        $this->assertCount(1, $results);
        $this->assertSame(['after.txt'], $this->listFiles($dir));
    }

    public function testItHandlesPageCloseDuringCapture(): void
    {
        $marker = sys_get_temp_dir().'/'.self::HANG_MARKER;
        @unlink($marker);

        $this->page->setContent(
            '<a id="dl" href="'.$this->getBaseUrl().'/download/hang">download</a>'
        );
        $dir = $this->makeDownloadsDir();

        $results = $this->page->captureDownloads(function () use ($marker): void {
            $this->page->locator('#dl')->click();
            $this->waitForFile($marker, 5.0);
            $this->page->close();
        }, $dir, ['timeout' => 10000, 'idleTimeout' => 300]);

        // The in-flight download is reported as failed, nothing is written,
        // and the capture returns instead of hanging.
        $this->assertCount(1, $results);
        $this->assertSame(CapturedDownload::STATUS_FAILED, $results[0]->status());
        $this->assertNotNull($results[0]->error());
        $this->assertNull($results[0]->path());
        $this->assertSame([], $this->listFiles($dir));

        // Only the page is gone - the caller's browser and context survive.
        $this->assertTrue($this->browser->isConnected());
        $survivor = $this->context->newPage();
        $survivor->close();
    }

    private function makeDownloadsDir(): string
    {
        $dir = sys_get_temp_dir().'/pw-php-capture-'.uniqid();
        mkdir($dir, 0777, true);
        $this->downloadsDirs[] = $dir;

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir): array
    {
        $entries = scandir($dir);
        if (false === $entries) {
            return [];
        }

        $files = array_values(array_diff($entries, ['.', '..']));
        sort($files);

        return $files;
    }

    private function waitForFile(string $path, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (is_file($path)) {
                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function removeDirectory(string $dir): void
    {
        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
