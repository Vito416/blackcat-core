<?php

declare(strict_types=1);

namespace BlackCat\Core\Kernel;

/**
 * Generic, locked-down HTML error pages for kernel rejections.
 *
 * Notes:
 * - Keep output deterministic and non-reflective (no user input echoed).
 * - No scripts and strict CSP (`default-src 'none'`).
 * - Intended for human-facing minimal bundles / demos, not API endpoints.
 */
final class KernelErrorPage
{
    /**
     * @param array<string,mixed> $server
     */
    public static function response(int $status, array $server, HttpKernelOptions $options): HttpKernelResponse
    {
        $status = in_array($status, [400, 503, 500], true) ? $status : 500;

        if (!$options->prettyErrorPages) {
            return self::plainTextResponse($status);
        }

        if (self::prefersJson($server)) {
            return self::jsonResponse($status);
        }

        return self::htmlResponse($status, $options);
    }

    private static function plainTextResponse(int $status): HttpKernelResponse
    {
        $body = match ($status) {
            400 => "Bad Request\n",
            503 => "Service Unavailable\n",
            default => "Internal Server Error\n",
        };

        return new HttpKernelResponse(
            $status,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            $body,
        );
    }

    private static function jsonResponse(int $status): HttpKernelResponse
    {
        $payload = [
            'ok' => false,
            'status' => $status,
            'error' => match ($status) {
                400 => 'Request rejected by security kernel.',
                503 => 'Service unavailable (fail-closed).',
                default => 'Internal error.',
            },
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = "{\"ok\":false,\"status\":500,\"error\":\"Internal error.\"}\n";
        }
        $json .= "\n";

        return new HttpKernelResponse(
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $json,
        );
    }

    private static function htmlResponse(int $status, HttpKernelOptions $options): HttpKernelResponse
    {
        $badge = match ($status) {
            400 => '<span class="pill warn">request rejected</span>',
            503 => '<span class="pill bad">fail-closed</span>',
            default => '<span class="pill bad">error</span>',
        };

        $title = match ($status) {
            400 => 'Request blocked',
            503 => 'Temporarily unavailable',
            default => 'Internal error',
        };

        $lead = match ($status) {
            400 => 'BlackCat rejected this request because it violates safe-request rules.',
            503 => 'BlackCat is running in a fail-closed mode until trust and runtime checks are healthy.',
            default => 'BlackCat hit an unexpected error and returned a generic failure.',
        };

        $assetsBase = trim($options->prettyErrorAssetsBase);
        if ($assetsBase === '' || str_contains($assetsBase, "\0")) {
            $assetsBase = '/_blackcat/assets';
        }
        if (!str_starts_with($assetsBase, '/')) {
            $assetsBase = '/' . $assetsBase;
        }
        // Only allow same-origin, absolute-path bases (no protocol-relative URLs).
        if (str_starts_with($assetsBase, '//')) {
            $assetsBase = '/_blackcat/assets';
        }
        // Avoid weird characters in CSS/HTML contexts.
        if (!preg_match('#^/[a-zA-Z0-9/_-]+$#', $assetsBase)) {
            $assetsBase = '/_blackcat/assets';
        }
        $assetsBase = rtrim($assetsBase, '/');

        $grid = trim($options->prettyErrorGrid);
        if ($grid === '' || str_contains($grid, "\0") || !preg_match('/^[a-zA-Z0-9._-]+$/', $grid)) {
            $grid = 'bg-grid.png';
        }
        $banner = trim($options->prettyErrorHeroBanner);
        if ($banner === '' || str_contains($banner, "\0") || !preg_match('/^[a-zA-Z0-9._-]+$/', $banner)) {
            $banner = 'hero-banner.png';
        }

        $gridUrl = $assetsBase . '/' . rawurlencode($grid);
        $bannerUrl = $assetsBase . '/' . rawurlencode($banner);

        $body = <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>BlackCat — {$title}</title>
    <style>
      :root { color-scheme: dark; }
      *, *::before, *::after { box-sizing: border-box; }
      body {
        margin: 0;
        min-height: 100svh;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: clamp(16px, 2.5vh, 56px) 16px 16px;
        font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        position: relative;
        isolation: isolate;
        background:
          radial-gradient(900px 420px at 20% 0%, rgba(86, 116, 255, 0.18), transparent 55%),
          radial-gradient(900px 420px at 80% 0%, rgba(255, 123, 114, 0.12), transparent 60%),
          #0b0f17;
        color: #e7eefc;
      }
      body::before {
        content: "";
        position: fixed;
        inset: 0;
        background: url("{$gridUrl}") repeat;
        background-size: 512px 512px;
        opacity: 0.30;
        mix-blend-mode: screen;
        filter: brightness(2.1) contrast(1.32) saturate(1.1);
        pointer-events: none;
        z-index: 0;
      }
      @media (prefers-reduced-motion: no-preference) {
        body::before { animation: bcGridDrift 52s linear infinite; }
        @keyframes bcGridDrift {
          from { background-position: 0 0; }
          to { background-position: 240px 120px; }
        }
      }
      .card {
        max-width: 980px;
        width: 100%;
        border-radius: 18px;
        border: 1px solid rgba(42, 59, 99, 0.78);
        background:
          radial-gradient(900px 420px at 18% 0%, rgba(255, 255, 255, 0.07), transparent 62%),
          radial-gradient(900px 420px at 82% 0%, rgba(86, 116, 255, 0.10), transparent 66%),
          linear-gradient(180deg, rgba(15, 21, 36, 0.74), rgba(15, 21, 36, 0.40));
        backdrop-filter: blur(18px) saturate(1.25);
        -webkit-backdrop-filter: blur(18px) saturate(1.25);
        box-shadow: 0 30px 100px rgba(0, 0, 0, 0.45);
        overflow: hidden;
        position: relative;
        z-index: 1;
      }
      .banner {
        width: 100%;
        height: clamp(140px, 18vw, 220px);
        background:
          linear-gradient(180deg, rgba(11, 15, 23, 0.00), rgba(11, 15, 23, 0.82)),
          url("{$bannerUrl}") left center / cover no-repeat;
        border-bottom: 1px solid rgba(31, 42, 68, 0.95);
      }
      .body { padding: 14px 16px 16px; }
      h1 { margin: 0 0 8px; font-size: 24px; }
      .muted { color: #9fb0d0; }
      .pill {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 999px;
        background: rgba(18, 32, 66, 0.8);
        border: 1px solid rgba(31, 42, 68, 0.95);
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        margin-left: 10px;
      }
      .pill.bad { background: rgba(255, 123, 114, 0.12); border-color: rgba(255, 123, 114, 0.28); color: #ff7b72; }
      .pill.warn { background: rgba(255, 212, 107, 0.12); border-color: rgba(255, 212, 107, 0.28); color: #ffd46b; }
      .box {
        margin-top: 12px;
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid rgba(31, 42, 68, 0.95);
        background: rgba(11, 15, 23, 0.55);
      }
      ul { margin: 10px 0 0 18px; padding: 0; }
      li { margin: 6px 0; }
      code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    </style>
  </head>
  <body>
    <main class="card">
      <div class="banner" aria-hidden="true"></div>
      <div class="body">
        <h1>BlackCat Kernel {$badge}</h1>
        <p class="muted">{$lead}</p>
        <div class="box">
          <div><strong>What you can do:</strong></div>
          <ul class="muted">
            <li>If this is setup time: open <code>/_blackcat/setup</code> over HTTPS.</li>
            <li>If you changed files: revert unexpected modifications and redeploy a trusted bundle.</li>
            <li>If RPC is down: restore multiple endpoints + quorum and retry.</li>
          </ul>
        </div>
      </div>
    </main>
  </body>
</html>
HTML;

        return new HttpKernelResponse(
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
                'Cross-Origin-Opener-Policy' => 'same-origin',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
                // Locked-down: no scripts.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            ],
            $body,
        );
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function prefersJson(array $server): bool
    {
        $accept = $server['HTTP_ACCEPT'] ?? null;
        if (!is_string($accept) || $accept === '') {
            return false;
        }

        $lower = strtolower($accept);
        $wantsJson = str_contains($lower, 'application/json');
        $wantsHtml = str_contains($lower, 'text/html');

        return $wantsJson && !$wantsHtml;
    }
}
