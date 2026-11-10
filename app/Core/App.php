<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array $mw = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $mw];
    }

    public function get(string $p, array $h, array $mw = []): void { $this->add('GET', $p, $h, $mw); }
    public function post(string $p, array $h, array $mw = []): void { $this->add('POST', $p, $h, $mw); }

    /** @return array{0:array,1:array,2:array}|null [handler, mw, params] */
    public function match(string $method, string $path): ?array
    {
        $allowed = false;
        foreach ($this->routes as [$m, $regex, $handler, $mw]) {
            if (preg_match($regex, $path, $mm)) {
                if ($m === $method || ($method === 'HEAD' && $m === 'GET')) {
                    $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$handler, $mw, $params];
                }
                $allowed = true;
            }
        }
        return $allowed ? [[], ['__405'], []] : null;
    }
}

/** Application kernel: routing, middleware, security headers, error handling. */
final class App
{
    public static function router(): Router
    {
        static $router = null;
        if ($router === null) {
            $router = new Router();
            (require base_path('routes/web.php'))($router);
            (require base_path('routes/admin.php'))($router);
        }
        return $router;
    }

    public static function handle(Request $req): Response
    {
        try {
            $res = self::dispatch($req);
        } catch (HttpException $e) {
            $res = self::errorPage($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('app', $e::class . ': ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
            $res = self::errorPage(500, is_production() ? '' : $e->getMessage());
        }
        return self::secureHeaders($res);
    }

    private static function dispatch(Request $req): Response
    {
        $m = self::router()->match($req->method, $req->path);
        if ($m === null) {
            throw new HttpException(404);
        }
        [$handler, $mw, $params] = $m;
        if ($mw === ['__405']) {
            throw new HttpException(405);
        }
        $req->params = $params;

        if (in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !Csrf::valid($req)) {
            throw new HttpException(419, 'Security token expired. Please go back, refresh the page and try again.');
        }
        foreach ($mw as $name) {
            $r = \App\Middleware\Gate::check($name, $req);
            if ($r instanceof Response) {
                return $r;
            }
        }
        [$class, $method] = $handler;
        $out = (new $class())->$method($req);
        return $out instanceof Response ? $out : Response::html((string) $out);
    }

    public static function errorPage(int $status, string $msg = ''): Response
    {
        $titles = [403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed', 419 => 'Session expired', 429 => 'Too many requests', 500 => 'Server error'];
        try {
            $html = View::render('public/error', ['status' => $status, 'heading' => $titles[$status] ?? 'Error', 'message' => $msg, 'title' => (string) $status]);
        } catch (\Throwable) {
            $html = '<h1>' . $status . '</h1><p>' . e($msg) . '</p>';
        }
        return Response::html($html, $status);
    }

    public static function secureHeaders(Response $r): Response
    {
        $r->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
        ];
        if (is_production()) {
            $r->headers += ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'];
        }
        return $r;
    }
}

final class HttpException extends \RuntimeException
{
    public function __construct(public int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}
