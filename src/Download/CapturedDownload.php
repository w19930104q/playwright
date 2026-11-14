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

namespace Playwright\Download;

/**
 * One download collected by Page::captureDownloads().
 *
 * The download has fully settled by the time it is returned: it either
 * completed (status self::STATUS_COMPLETED, path() points at the finished
 * file inside the capture directory) or failed/was canceled (status
 * self::STATUS_FAILED, error() holds the reason and no file was created).
 */
final class CapturedDownload
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly DownloadInterface $download,
        private readonly ?string $path,
        private readonly string $status,
        private readonly ?string $error = null,
    ) {
    }

    /**
     * The live download handle, e.g. to stream its contents or save it elsewhere.
     */
    public function download(): DownloadInterface
    {
        return $this->download;
    }

    /**
     * Filename suggested by the browser (before sanitization and deduplication).
     */
    public function suggestedFilename(): string
    {
        return $this->download->suggestedFilename();
    }

    public function url(): string
    {
        return $this->download->url();
    }

    /**
     * Absolute path of the finished file inside the capture directory,
     * or null when the download did not complete.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Either self::STATUS_COMPLETED or self::STATUS_FAILED.
     */
    public function status(): string
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return self::STATUS_COMPLETED === $this->status;
    }

    /**
     * Failure reason reported by the browser, or null for a completed download.
     */
    public function error(): ?string
    {
        return $this->error;
    }
}
