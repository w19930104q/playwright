const fs = require('fs');
const path = require('path');
const { logger } = require('./core');

const DEFAULT_TIMEOUT = 30000;
const DEFAULT_IDLE_TIMEOUT = 1000;
const POLL_INTERVAL = 50;
// Grace given to in-flight downloads to report their failure once the page
// is gone; Playwright settles them, just not always synchronously.
const PAGE_CLOSE_GRACE = 2000;
// If PHP never sends callback.continue (its action threw), the async command
// coordinator drops the sequence after timeout + 60s. This watchdog fires
// just after that so the page listener and in-flight downloads do not leak.
const WATCHDOG_EXTRA = 70000;
const MAX_FILENAME_LENGTH = 120;

// Live capture state cannot cross the JSON bridge (it holds timers, listeners
// and Download objects), so phases hand it around through this map instead of
// the coordinator's phase data.
const activeCaptures = new Map();

/**
 * A suggested filename is browser-supplied and must never be allowed to
 * escape the capture directory: keep the last path segment only, strip
 * characters that are illegal or control characters on common filesystems,
 * and fall back to a plain name when nothing usable remains.
 */
function sanitizeFilename(name) {
  let base = String(name ?? '').replace(/\\/g, '/').split('/').pop() || '';
  // eslint-disable-next-line no-control-regex
  base = base.replace(/[\x00-\x1f<>:"|?*]/g, '_');
  base = base.trim().replace(/[. ]+$/, '');
  if (base === '' || base === '.' || base === '..') base = 'download';
  if (base.length > MAX_FILENAME_LENGTH) base = base.slice(0, MAX_FILENAME_LENGTH);
  return base;
}

/**
 * First capture wins the plain suggested name; later ones - and names that
 * already exist in the directory - get a " (n)" suffix before the extension.
 */
function uniqueFilename(directory, name, used) {
  const dot = name.lastIndexOf('.');
  const stem = dot > 0 ? name.slice(0, dot) : name;
  const ext = dot > 0 ? name.slice(dot) : '';
  let candidate = name;
  let counter = 1;
  while (used.has(candidate) || fs.existsSync(path.join(directory, candidate))) {
    candidate = `${stem} (${counter})${ext}`;
    counter++;
  }
  used.add(candidate);
  return candidate;
}

/**
 * Coordinates captureDownloads between the PHP client and this server,
 * following the same two-phase callback pattern as the popup coordinator:
 * phase one installs the listener and asks PHP to run its action, phase two
 * (after callback.continue) waits for the triggered downloads to settle and
 * moves them into the target directory.
 */
class DownloadCoordinator {
  static createCapturePhases(dependencies) {
    const { downloads, generateId } = dependencies;

    return [
      {
        name: 'setupCapture',
        handler: async (data) => {
          const { page, pageId, directory, timeout, requestId } = data;

          logger.info('Setting up download capture', { pageId, directory, timeout, requestId });

          fs.mkdirSync(directory, { recursive: true });

          const state = {
            entries: [],
            closed: false,
            lastActivity: Date.now(),
            onDownload: null,
            onClose: null,
            watchdog: null,
          };

          state.onDownload = (download) => {
            const entry = {
              download,
              suggestedFilename: DownloadCoordinator.safeCall(() => download.suggestedFilename(), ''),
              url: DownloadCoordinator.safeCall(() => download.url(), ''),
              // null = still in flight, true = completed, false = failed
              ok: null,
              error: null,
            };
            state.entries.push(entry);
            state.lastActivity = Date.now();

            (async () => {
              try {
                // failure() resolves once the download finishes, successfully or not
                const failure = await download.failure();
                if (failure) {
                  entry.ok = false;
                  entry.error = String(failure);
                } else {
                  // path() resolves with the finished artifact
                  await download.path();
                  entry.ok = true;
                }
              } catch (error) {
                entry.ok = false;
                entry.error = error?.message || String(error);
              }
              state.lastActivity = Date.now();
            })();
          };

          state.onClose = () => {
            state.closed = true;
            state.lastActivity = Date.now();
          };

          page.on('download', state.onDownload);
          page.once('close', state.onClose);

          const budget = (typeof timeout === 'number' && timeout > 0 ? timeout : DEFAULT_TIMEOUT) + WATCHDOG_EXTRA;
          state.watchdog = setTimeout(() => {
            DownloadCoordinator.teardown(page, state);
            activeCaptures.delete(requestId);
          }, budget);
          state.watchdog.unref?.();

          activeCaptures.set(requestId, state);

          return { captureKey: requestId };
        },
        waitForCallback: true,
        callbackType: 'readyForAction',
      },
      {
        name: 'collectDownloads',
        handler: async (data) => {
          const { page, pageId, directory, requestId } = data;
          const state = activeCaptures.get(requestId);
          if (!state) {
            throw new Error(`Download capture not found: ${requestId}`);
          }
          const timeout = typeof data.timeout === 'number' ? data.timeout : DEFAULT_TIMEOUT;
          const idleTimeout = typeof data.idleTimeout === 'number' ? data.idleTimeout : DEFAULT_IDLE_TIMEOUT;
          const deadline = timeout > 0 ? Date.now() + timeout : Infinity;

          logger.info('Collecting captured downloads', { pageId, timeout, idleTimeout, requestId });

          try {
            while (true) {
              const pending = state.entries.filter((entry) => entry.ok === null);
              const quietFor = Date.now() - state.lastActivity;

              if (pending.length === 0 && quietFor >= idleTimeout) break;
              if (state.closed) break;

              if (Date.now() > deadline) {
                await Promise.all(pending.map((entry) => DownloadCoordinator.boundedCleanup(entry.download.cancel())));
                await Promise.all(pending.map((entry) => DownloadCoordinator.boundedCleanup(entry.download.delete())));
                const error = new Error(
                  `captureDownloads timed out after ${timeout}ms with ${pending.length} download(s) still in flight`
                );
                error.name = 'TimeoutError';
                throw error;
              }

              await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL));
            }

            if (state.closed) {
              // Playwright settles in-flight downloads when the page goes away,
              // just not always before its close event reaches us.
              const graceDeadline = Date.now() + PAGE_CLOSE_GRACE;
              while (state.entries.some((entry) => entry.ok === null) && Date.now() < graceDeadline) {
                await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL));
              }
              for (const entry of state.entries) {
                if (entry.ok === null) {
                  entry.ok = false;
                  entry.error = 'Page closed before the download finished';
                }
              }
            }

            const used = new Set();
            const results = [];

            for (const entry of state.entries) {
              const downloadId = generateId('download');
              downloads.set(downloadId, entry.download);

              const result = {
                downloadId,
                suggestedFilename: entry.suggestedFilename,
                url: entry.url,
                path: null,
                status: 'failed',
                error: entry.error,
              };

              if (entry.ok) {
                // The artifact is written next to its final name and renamed
                // into place, so a finished file only ever appears complete.
                let tmpPath = null;
                try {
                  const name = uniqueFilename(directory, sanitizeFilename(entry.suggestedFilename), used);
                  const finalPath = path.join(directory, name);
                  tmpPath = path.join(directory, `.${name}.${process.pid}.${results.length}.part`);
                  await entry.download.saveAs(tmpPath);
                  fs.renameSync(tmpPath, finalPath);
                  tmpPath = null;
                  result.path = finalPath;
                  result.status = 'completed';
                  result.error = null;
                } catch (error) {
                  result.status = 'failed';
                  result.error = error?.message || String(error);
                  if (tmpPath) {
                    try { fs.unlinkSync(tmpPath); } catch {}
                  }
                }
              } else {
                // Failed or canceled downloads leave no artifact behind.
                await DownloadCoordinator.boundedCleanup(entry.download.delete());
              }

              results.push(result);
            }

            logger.info('Download capture finished', {
              pageId,
              requestId,
              total: results.length,
              completed: results.filter((r) => r.status === 'completed').length,
            });

            return { downloads: results };
          } finally {
            DownloadCoordinator.teardown(page, state);
            activeCaptures.delete(requestId);
          }
        },
        waitForCallback: false,
      },
    ];
  }

  static teardown(page, state) {
    if (state.watchdog) {
      clearTimeout(state.watchdog);
      state.watchdog = null;
    }
    try { if (state.onDownload) page.off('download', state.onDownload); } catch {}
    try { if (state.onClose) page.off('close', state.onClose); } catch {}
  }

  // cancel()/delete() wait for the download to finish, which may never
  // happen once the page is gone - never let cleanup block the capture.
  static boundedCleanup(promise, ms = 1000) {
    return Promise.race([
      Promise.resolve(promise).catch(() => {}),
      new Promise((resolve) => setTimeout(resolve, ms)),
    ]);
  }

  static safeCall(fn, fallback) {
    try {
      const value = fn();
      return typeof value === 'string' ? value : fallback;
    } catch {
      return fallback;
    }
  }
}

module.exports = { DownloadCoordinator, sanitizeFilename, uniqueFilename };
