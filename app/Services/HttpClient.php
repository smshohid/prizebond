<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Outbound HTTP for official-source fetching.
 * SSRF protections: https only, host allow-list, private/loopback/link-local IP rejection (incl. after
 * DNS resolution), manual redirect handling with re-validation, size cap, TLS verification always on.
 */
final class HttpClient
{
    /** @var null|callable(string,string,array):array test hook: fn($method,$url,$fields) => [status, body, headers] */
    private static $fake = null;

    public function __construct(private ?array $allowedHosts = null)
    {
        $this->allowedHosts ??= array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('SOURCE_ALLOWED_HOSTS', ''))))));
    }

    public static function fake(?callable $fn): void
    {
        self::$fake = $fn;
    }

    /** @return array{status:int,body:string,headers:array,url:string} */
    public function get(string $url): array
    {
        return $this->request('GET', $url, []);
    }

    public function post(string $url, array $fields): array
    {
        return $this->request('POST', $url, $fields);
    }

    public function assertAllowed(string $url): void
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            throw new \RuntimeException('Only absolute https URLs may be fetched.');
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            throw new \RuntimeException('Non-standard ports are not allowed.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \RuntimeException('Credentials in URLs are not allowed.');
        }
        $host = strtolower($p['host']);
        if (!in_array($host, $this->allowedHosts, true)) {
            throw new \RuntimeException("Host '$host' is not in the source allow-list.");
        }
        if (self::$fake === null) {
            $ips = gethostbynamel($host) ?: [];
            if (!$ips) {
                throw new \RuntimeException("Cannot resolve host '$host'.");
            }
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \RuntimeException("Host '$host' resolves to a non-public address.");
                }
            }
        }
    }

    private function request(string $method, string $url, array $fields): array
    {
        $retries = max(1, (int) env('HTTP_RETRIES', 3));
        $last = null;
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $res = $this->once($method, $url, $fields);
                if ($res['status'] >= 500 && $attempt < $retries) {
                    usleep(300000 * $attempt);
                    continue;
                }
                return $res;
            } catch (\RuntimeException $e) {
                $last = $e;
                if (str_contains($e->getMessage(), 'allow-list') || str_contains($e->getMessage(), 'non-public') || str_contains($e->getMessage(), 'https')) {
                    throw $e; // policy violations are never retried
                }
                if ($attempt < $retries) {
                    usleep(300000 * $attempt);
                }
            }
        }
        throw $last ?? new \RuntimeException('Request failed.');
    }

    private function once(string $method, string $url, array $fields): array
    {
        $maxBytes = 15 * 1024 * 1024;
        for ($hop = 0; $hop <= 3; $hop++) {
            $this->assertAllowed($url);
            if (self::$fake !== null) {
                [$status, $body, $headers] = (self::$fake)($method, $url, $fields) + [2 => []];
            } else {
                $headers = [];
                $ch = curl_init($url);
                $ca = env('HTTP_CA_BUNDLE');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => (int) env('HTTP_CONNECT_TIMEOUT', 10),
                    CURLOPT_TIMEOUT => (int) env('HTTP_TIMEOUT', 30),
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT => (string) env('HTTP_USER_AGENT', 'PrizeBondMonitorBD/1.0'),
                    CURLOPT_MAXFILESIZE => $maxBytes,
                    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
                        if (str_contains($h, ':')) {
                            [$k, $v] = explode(':', $h, 2);
                            $headers[strtolower(trim($k))] = trim($v);
                        }
                        return strlen($h);
                    },
                ]);
                if ($ca && is_file((string) $ca)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $ca);
                }
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
                }
                $body = curl_exec($ch);
                if ($body === false) {
                    $err = curl_error($ch);
                    curl_close($ch);
                    throw new \RuntimeException('HTTP error: ' . $err);
                }
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
            }
            if (strlen((string) $body) > $maxBytes) {
                throw new \RuntimeException('Response too large.');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
                $url = $this->resolve($url, $headers['location']);
                $method = 'GET';
                $fields = [];
                continue;
            }
            return ['status' => $status, 'body' => (string) $body, 'headers' => $headers, 'url' => $url];
        }
        throw new \RuntimeException('Too many redirects.');
    }

    private function resolve(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'];
        return str_starts_with($loc, '/') ? $root . $loc : $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
    }
}
