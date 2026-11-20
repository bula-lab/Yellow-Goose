<?php
namespace ZoltaGaska\Assets;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Response;

final class FileResponse extends Response
{
    private int $start = 0;
    private int $length;

    public function __construct(private $stream, int $size, string $mime, ?string $range)
    {
        $end = $size - 1;
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'Accept-Ranges' => 'bytes'];
        if ($range !== null) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) || ($parts[1] === '' && $parts[2] === '')) {
                throw new HttpException(416, 'Invalid byte range.', [], ['Content-Range' => "bytes */$size"]);
            }
            if ($parts[1] === '') $this->start = max(0, $size - (int) $parts[2]);
            else {
                $this->start = (int) $parts[1];
                if ($parts[2] !== '') $end = min($end, (int) $parts[2]);
            }
            if ($this->start > $end) throw new HttpException(416, 'Unsatisfiable byte range.', [], ['Content-Range' => "bytes */$size"]);
            $headers['Content-Range'] = "bytes {$this->start}-$end/$size";
        }
        $this->length = $end - $this->start + 1;
        parent::__construct('', $range === null ? 200 : 206, $headers + ['Content-Length' => (string) $this->length]);
    }

    public function send(bool $head = false): void
    {
        parent::send(true);
        if ($head) return;
        if (fseek($this->stream, $this->start) !== 0) throw new \RuntimeException('Cannot seek stored object.');
        $out = fopen('php://output', 'wb');
        try { stream_copy_to_stream($this->stream, $out, $this->length); }
        finally { fclose($out); }
    }

    public function __destruct() { if (is_resource($this->stream)) fclose($this->stream); }
}
