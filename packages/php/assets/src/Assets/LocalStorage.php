<?php
namespace ZoltaGaska\Assets;

use ZoltaGaska\Http\HttpException;

final class LocalStorage implements Storage
{
    private string $root;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create private storage.');
        $this->root = realpath($directory) ?: throw new \RuntimeException('Cannot resolve storage.');
        $public = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        if (!empty($_SERVER['DOCUMENT_ROOT']) && ($this->root === $public || str_starts_with($this->root, $public . '/'))) {
            throw new \InvalidArgumentException('Private storage must be outside the document root.');
        }
        foreach (['locks', 'staging', 'quarantine', 'ready'] as $area) {
            $path = $this->root . '/' . $area;
            if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) throw new \RuntimeException('Cannot create storage directory.');
        }
    }

    public function locked(string $upload, callable $operation): mixed
    {
        // Bounded lock stripes remain in place when abandoned uploads are pruned.
        $lock = fopen($this->root . '/locks/' . substr(hash('sha256', $upload), 0, 2), 'c');
        if (!$lock) throw new \RuntimeException('Cannot open upload lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Upload lock unavailable.');
            return $operation();
        } finally { fclose($lock); }
    }

    private function path(string $key): string
    {
        if (!preg_match('~^(staging|quarantine|ready)/[a-f0-9]{32}\.(model|poster)$~D', $key)) throw new \InvalidArgumentException('Invalid storage key.');
        return $this->root . '/' . $key;
    }

    public function size(string $key): ?int
    {
        $path = $this->path($key);
        clearstatcache(true, $path);
        if (!is_file($path)) return null;
        $size = filesize($path);
        if ($size === false) throw new \RuntimeException('Cannot read object size.');
        return $size;
    }

    public function append(string $key, int $offset, string $bytes): void
    {
        if (!str_starts_with($key, 'staging/')) throw new \LogicException('Only staging objects accept chunks.');
        $stream = fopen($this->path($key), 'c+b');
        if (!$stream) throw new \RuntimeException('Cannot open staging object.');
        try {
            if (fseek($stream, 0, SEEK_END) !== 0 || ftell($stream) !== $offset) throw new HttpException(409, 'Unexpected chunk offset.');
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                ftruncate($stream, $offset);
                throw new \RuntimeException('Could not persist upload chunk.');
            }
        } finally { fclose($stream); }
    }

    public function freeze(string $from, string $to): void
    {
        if (!str_starts_with($from, 'staging/') || !str_starts_with($to, 'quarantine/')) throw new \LogicException('Invalid ownership transfer.');
        if ($this->size($to) !== null) return;
        if (!rename($this->path($from), $this->path($to))) throw new \RuntimeException('Cannot freeze upload.');
    }

    public function open(string $key)
    {
        return fopen($this->path($key), 'rb') ?: throw new \RuntimeException('Cannot read stored object.');
    }

    public function install(string $key, $stream): void
    {
        if (!str_starts_with($key, 'ready/')) throw new \LogicException('Only ready objects may be installed.');
        $path = $this->path($key);
        if ($this->size($key) !== null) return;
        $tmp = tempnam(dirname($path), '.prepare-');
        if ($tmp === false) throw new \RuntimeException('Cannot allocate prepared object.');
        try {
            $out = fopen($tmp, 'wb');
            if (!$out) throw new \RuntimeException('Cannot prepare object.');
            try {
                $expected = fstat($stream)['size'] - ftell($stream);
                $written = stream_copy_to_stream($stream, $out);
                if ($written !== $expected || !fflush($out) || !fsync($out)) throw new \RuntimeException('Cannot persist prepared object.');
            } finally { fclose($out); }
            // A recovered or overlapping lease can install the same immutable bytes.
            if (!@link($tmp, $path) && $this->size($key) === null) throw new \RuntimeException('Cannot install immutable object.');
        } finally { unlink($tmp); }
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) throw new \RuntimeException('Cannot remove expired bytes.');
    }
}
