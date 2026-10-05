<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class BootstrapPatcher
{
    /**
     * Classes the patched bootstrap file needs to import.
     *
     * @var list<string>
     */
    private const array IMPORTS = [
        'App\Exceptions\ApiExceptionRenderer',
        'App\Http\Middleware\ForceJsonResponse',
        'App\Http\Middleware\RequestId',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Wire the API middleware and exception rendering into bootstrap/app.php.
     *
     * Returns null when the file was patched or was already wired, and an
     * explanation when the developer has to do it by hand.
     */
    public function patch(): ?string
    {
        $path = $this->basePath.'/bootstrap/app.php';

        if (! $this->files->exists($path)) {
            return 'bootstrap/app.php was not found.';
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, 'ApiExceptionRenderer')) {
            return null;
        }

        $patched = $this->addMiddleware($contents);

        if ($patched === null) {
            return 'The withMiddleware() call in bootstrap/app.php was not in the expected shape.';
        }

        $patched = $this->addExceptions($patched);

        if ($patched === null) {
            return 'The withExceptions() call in bootstrap/app.php was not in the expected shape.';
        }

        $this->files->put($path, $this->addImports($patched));

        return null;
    }

    /**
     * Append the API middleware to the global stack.
     *
     * trustProxies() is part of this block because every deployment behind a
     * reverse proxy needs it and the failure is silent without it: Laravel
     * ignores X-Forwarded-Proto, so url() builds http:// links, signed URLs 403
     * against their own signature, and $request->ip() returns the proxy, giving
     * every client one rate limit bucket and one IP in the logs.
     */
    private function addMiddleware(string $contents): ?string
    {
        return $this->insertInto(
            $contents,
            '/(->withMiddleware\(function \(Middleware \$middleware\)[^{]*\{\n)(\h*\/\/\n)?/',
            <<<'PHP'
                    // Trust every proxy: correct while the application is only
                    // reachable through a reverse proxy. If it is ever published
                    // directly, narrow this, or clients can forge X-Forwarded-For.
                    $middleware->trustProxies(at: '*');

                    $middleware->append(ForceJsonResponse::class);
                    $middleware->append(RequestId::class);

            PHP,
        );
    }

    /**
     * Render every uncaught throwable through the API response envelope.
     */
    private function addExceptions(string $contents): ?string
    {
        return $this->insertInto(
            $contents,
            '/(->withExceptions\(function \(Exceptions \$exceptions\)[^{]*\{\n)(\h*\/\/\n)?/',
            <<<'PHP'
                    $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
                    $exceptions->render(new ApiExceptionRenderer);

            PHP,
        );
    }

    /**
     * Insert a block at the start of a closure, dropping its empty placeholder.
     */
    private function insertInto(string $contents, string $pattern, string $block): ?string
    {
        $patched = preg_replace(
            $pattern,
            '$1'.str_replace('$', '\$', $block),
            $contents,
            1,
            $count,
        );

        if (! is_string($patched) || $count !== 1) {
            return null;
        }

        return $patched;
    }

    /**
     * Add the use statements the inserted code depends on.
     */
    private function addImports(string $contents): string
    {
        foreach (array_reverse(self::IMPORTS) as $import) {
            if (str_contains($contents, 'use '.$import.';')) {
                continue;
            }

            $patched = preg_replace(
                '/^use /m',
                'use '.$import.";\nuse ",
                $contents,
                1,
            );

            if (is_string($patched)) {
                $contents = $patched;
            }
        }

        return $contents;
    }
}
