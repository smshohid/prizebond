<?php
declare(strict_types=1);

namespace App\Core;

/** Session wrapper with an in-memory mode used by CLI/test runs. */
final class Session
{
    private static array $data = [];
    private static bool $native = false;

    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || self::$native) {
            return;
        }
        $secure = is_production() || (($_SERVER['HTTPS'] ?? '') === 'on');
        session_name('pbm_session');
        $dir = base_path('storage/sessions');
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        self::$native = true;
        self::$data = &$_SESSION;
    }

    public static function reset(): void
    {
        self::$data = [];
    }

    public static function get(string $k, mixed $default = null): mixed
    {
        return self::$data[$k] ?? $default;
    }

    public static function put(string $k, mixed $v): void
    {
        self::$data[$k] = $v;
    }

    public static function forget(string $k): void
    {
        unset(self::$data[$k]);
    }

    public static function pull(string $k, mixed $default = null): mixed
    {
        $v = self::$data[$k] ?? $default;
        unset(self::$data[$k]);
        return $v;
    }

    /** Prevent session fixation after privilege change. */
    public static function regenerate(): void
    {
        if (self::$native) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        self::$data = [];
        if (self::$native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function all(): array
    {
        return self::$data;
    }
}

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) < 32) {
            $t = bin2hex(random_bytes(32));
            Session::put('_csrf', $t);
        }
        return $t;
    }

    public static function valid(Request $r): bool
    {
        $sent = $r->post('_csrf') ?? $r->header('X-CSRF-Token');
        $t = Session::get('_csrf');
        return is_string($sent) && is_string($t) && hash_equals($t, $sent);
    }
}

final class Lang
{
    private static array $cache = [];

    /** Temporary locale override (used when rendering emails for a given user). */
    public static ?string $override = null;

    public static function locale(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $l = Session::get('lang');
        return in_array($l, ['en', 'bn'], true) ? $l : 'en';
    }

    public static function load(string $loc): array
    {
        if (!isset(self::$cache[$loc])) {
            $f = base_path("resources/lang/$loc/app.php");
            self::$cache[$loc] = is_file($f) ? (require $f) : [];
        }
        return self::$cache[$loc];
    }

    public static function get(string $key, array $repl = []): string
    {
        $loc = self::locale();
        $s = self::load($loc)[$key] ?? self::load('en')[$key] ?? $key;
        foreach ($repl as $k => $v) {
            $s = str_replace(':' . $k, (string) $v, $s);
        }
        return $s;
    }

    public static function set(string $loc): void
    {
        if (in_array($loc, ['en', 'bn'], true)) {
            Session::put('lang', $loc);
        }
    }
}

final class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public array $files = [],
        public array $server = [],
        public array $cookies = [],
        public array $headers = [],
        public array $params = [],
    ) {
    }

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim((string) env('APP_BASE_PATH', ''), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), '/' . trim(rawurldecode($uri), '/'), $_GET, $_POST, $_FILES, $_SERVER, $_COOKIE, $headers);
    }

    public function input(string $k, mixed $default = null): mixed
    {
        return $this->post[$k] ?? $this->query[$k] ?? $default;
    }

    public function post(string $k, mixed $default = null): mixed
    {
        return $this->post[$k] ?? $default;
    }

    public function get(string $k, mixed $default = null): mixed
    {
        return $this->query[$k] ?? $default;
    }

    public function str(string $k, string $default = ''): string
    {
        $v = $this->input($k, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public function int(string $k, int $default = 0): int
    {
        $v = $this->input($k);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function header(string $k, mixed $default = null): mixed
    {
        return $this->headers[strtolower($k)] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function file(string $k): ?array
    {
        $f = $this->files[$k] ?? null;
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }
}

final class Response
{
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $b, int $status = 200): self
    {
        return new self($b, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $d, int $status = 200): self
    {
        return new self(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $loc = preg_match('#^https?://#', $to) ? $to : url($to);
        return new self('', $status, ['Location' => $loc]);
    }

    public static function download(string $content, string $name, string $mime = 'text/csv'): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        return new self($content, 200, [
            'Content-Type' => $mime . '; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function text(string $b, string $mime = 'text/plain', int $status = 200): self
    {
        return new self($b, $status, ['Content-Type' => $mime . '; charset=utf-8']);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::partial($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $view, array $data = []): string
    {
        $file = base_path('resources/views/' . $view . '.php');
        if (!is_file($file) || str_contains($view, '..')) {
            throw new \RuntimeException("View not found: $view");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
