# Capturing Downloads

A single click can start several downloads at once, and watching a directory
for whatever files happen to appear is racy. `Page::captureDownloads()`
installs a download listener *before* your action runs, collects every
download the action triggers, waits for each of them to complete or fail,
and saves the finished files into a directory you choose.

```php
$results = $page->captureDownloads(
    action: fn () => $page->locator('#export-all')->click(),
    directory: '/path/to/downloads',
);

foreach ($results as $captured) {
    if ($captured->isSuccessful()) {
        echo $captured->path();               // /path/to/downloads/report.csv
    } else {
        echo $captured->error();              // why the browser failed it
    }
}
```

## How it works

1. The listener is armed, then your `$action` runs (a click, several clicks,
   a form submit - anything that triggers downloads).
2. Every download started by the action is tracked until it **completes or
   fails**. The method returns only when all of them have settled.
3. Completed downloads are written into `$directory` (created when missing).
   A file only ever appears in the directory **complete**: the artifact is
   written next to its final name and atomically renamed into place.
4. Failed or canceled downloads create no file and clean up their temporary
   artifacts. The same happens on timeout, page close, or cancellation -
   the listener is always removed and your browser is never closed.

Each returned `Playwright\Download\CapturedDownload` exposes:

| Method | Meaning |
| --- | --- |
| `status()` | `'completed'` or `'failed'` (see the `STATUS_*` constants) |
| `isSuccessful()` | shorthand for a completed download |
| `error()` | failure reason, `null` when completed |
| `path()` | absolute path of the finished file, `null` when failed |
| `suggestedFilename()` | filename suggested by the browser |
| `url()` | URL the download was started from |
| `download()` | the live `DownloadInterface` handle (e.g. `saveAs()`, `createReadStream()`) |

An action that triggers no download returns an empty list.

## File names

Suggested filenames are sanitized before use: path segments are stripped so
a name can never escape the target directory, illegal characters are
replaced, and empty names fall back to `download`. When several downloads
suggest the same name - or the name already exists in the directory - the
later files get a numeric suffix:

```
report.csv
report (1).csv
report (2).csv
```

## Options

Pass an array or a `CaptureDownloadsOptions` instance as the third argument:

```php
use Playwright\Page\Options\CaptureDownloadsOptions;

$results = $page->captureDownloads(
    $action,
    $directory,
    new CaptureDownloadsOptions(timeout: 10_000, idleTimeout: 500),
);
```

- **`timeout`** (ms, default `30000`) - overall budget for the downloads to
  finish once the action has run. When it is exceeded, in-flight downloads
  are canceled, temporary files are removed, and a
  `Playwright\Exception\TimeoutException` is thrown.
- **`idleTimeout`** (ms, default `1000`) - how long to wait for *further*
  downloads to start before the capture is considered complete. Lower it
  when you know the action triggers at most one download; raise it when
  downloads are started in staggered batches.

## Isolation

Captures are scoped to their page: downloads triggered on other pages or in
other contexts - even while a capture is armed - are never collected, and
concurrent captures on different pages do not interfere with each other.
The caller's browser and context stay open and fully usable after the call.

## Notes

- The directory is interpreted on the machine running the Playwright server
  (the local machine in the default setup), like `downloadsDir` in the
  configuration.
- The returned `DownloadInterface` handles stay valid after the capture, so
  you can still stream or re-save a completed download elsewhere.
