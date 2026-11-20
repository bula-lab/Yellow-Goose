<?php
namespace ZoltaGaska\Http;

class Request
{
    public const MAX_BODY_BYTES = 1048576;
    public readonly string $uri;
    public readonly string $method;
    public readonly string $url;
    private array $query = [];
    private array $headers = [];

    public function __construct(string $uri, string $method, array $headers = [], private string $rawBody = '')
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/')) throw new HttpException(400, 'Nieprawidłowy adres.');
        $this->uri = $path;
        $this->url = $path . (($query = parse_url($uri, PHP_URL_QUERY)) !== null ? '?' . $query : '');
        $this->method = strtoupper($method);
        parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $this->query);
        foreach ($headers as $name => $value) $this->headers[strtolower($name)] = (string) $value;
    }

    public static function capture(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) $headers[str_replace('_', '-', substr($name, 5))] = $value;
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $name) {
            if (isset($_SERVER[$name])) $headers[str_replace('_', '-', $name)] = $_SERVER[$name];
        }
        $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        return new self(
            $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET', $headers,
            $body === false ? '' : $body,
        );
    }

    public function header(string $name, ?string $default = null): ?string { return $this->headers[strtolower($name)] ?? $default; }
    public function query(string $name, mixed $default = null): mixed { return $this->query[$name] ?? $default; }

    public function bytes(int $limit = self::MAX_BODY_BYTES): string
    {
        if ($limit < 1 || $limit > self::MAX_BODY_BYTES) throw new \InvalidArgumentException('Invalid request byte limit.');
        if (strlen($this->rawBody) > $limit) throw new HttpException(413, 'Request body exceeds the byte limit.');
        return $this->rawBody;
    }

    public function json(): array
    {
        if (strlen($this->rawBody) > self::MAX_BODY_BYTES) throw new HttpException(413, 'Żądanie jest zbyt duże.');
        if (strtolower(trim(explode(';', $this->header('Content-Type', ''))[0])) !== 'application/json') {
            throw new HttpException(415, 'Wymagany Content-Type: application/json.');
        }
        try { $body = json_decode($this->rawBody, false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(400, 'Nieprawidłowy JSON.'); }
        if (!$body instanceof \stdClass) throw new HttpException(400, 'Treść JSON musi być obiektem.');
        return (array) $body;
    }

    public function requireMethod(array $methods): void
    {
        if (!in_array($this->method, $methods, true)) {
            throw new HttpException(405, 'Niedozwolona metoda.', [], ['Allow' => implode(', ', $methods)]);
        }
    }
}
