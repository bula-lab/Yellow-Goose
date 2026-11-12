<?php
namespace ZoltaGaska\Frontend;

use ZoltaGaska\Http\HttpException;

final class NodeRenderer implements Renderer
{
    public function __construct(
        private string $bundle,
        private string $node = 'node',
        private float $timeout = 5.0,
        private int $maxOutput = 2097152,
    ) {
        if ($timeout <= 0 || $maxOutput < 1) throw new \InvalidArgumentException('Invalid SSR limits.');
    }

    public function render(string $page, array $props): string
    {
        if (!is_file($this->bundle)) {
            throw new HttpException(503, 'Brak builda SSR. Uruchom npm run build.');
        }
        $input = json_encode(['page' => $page, 'props' => (object) $props], JSON_THROW_ON_ERROR);
        if (strlen($input) > 1048576) throw new \RuntimeException('SSR props exceed 1 MiB.');
        $process = proc_open([
            $this->node, dirname(__DIR__, 2) . '/resources/ssr.mjs', $this->bundle,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname($this->bundle));
        if (!is_resource($process)) throw new \RuntimeException('Cannot start SSR process.');
        $stdout = '';
        $stderr = '';
        $offset = 0;
        $started = hrtime(true);
        $exitCode = -1;
        try {
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            while (true) {
                if ((hrtime(true) - $started) / 1e9 > $this->timeout) {
                    throw new \RuntimeException('SSR process timed out.');
                }
                if (isset($pipes[0])) {
                    $written = @fwrite($pipes[0], substr($input, $offset, 8192));
                    if ($written === false) throw new \RuntimeException('Cannot send SSR props.');
                    $offset += $written;
                    if ($offset === strlen($input)) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
                $stdout .= fread($pipes[1], 8192);
                $stderr .= fread($pipes[2], 8192);
                if (strlen($stdout) + strlen($stderr) > $this->maxOutput) {
                    throw new \RuntimeException('SSR output limit exceeded.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    if ($status['exitcode'] >= 0) $exitCode = $status['exitcode'];
                    if (feof($pipes[1]) && feof($pipes[2])) break;
                }
                usleep(1000);
            }
            if ($exitCode !== 0) throw new \RuntimeException('SSR failed: ' . substr($stderr, 0, 4096));
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !isset($result['html']) || !is_string($result['html'])) {
                throw new \RuntimeException('Invalid SSR response.');
            }
            return $result['html'];
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process, 9);
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
        }
    }
}
