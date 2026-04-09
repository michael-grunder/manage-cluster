<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use RuntimeException;

final class AsyncNodeLatencyMonitor
{
    private const float PROBE_TIMEOUT_SECONDS = 0.2;
    private const float SWEEP_INTERVAL_SECONDS = 0.25;

    /** @var resource|null */
    private $controlStream = null;
    private ?int $childPid = null;
    private string $readBuffer = '';
    /** @var list<int> */
    private array $trackedPorts = [];
    /** @var array<int, NodeLatencySnapshot> */
    private array $resultsByPort = [];
    /** @var array<int, true> */
    private array $pendingPorts = [];
    private bool $stopped = false;

    public function __construct(
        private readonly bool $tls,
        private readonly ?string $caCert,
    ) {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            throw new RuntimeException('Async latency monitoring requires pcntl_fork() and stream_socket_pair().');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair) || count($pair) !== 2) {
            throw new RuntimeException('Unable to create async latency monitor control socket.');
        }

        [$parentStream, $childStream] = $pair;
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($parentStream);
            fclose($childStream);
            throw new RuntimeException('Unable to fork async latency monitor.');
        }

        if ($pid === 0) {
            fclose($parentStream);
            $this->runChildLoop($childStream);
            exit(0);
        }

        fclose($childStream);
        stream_set_blocking($parentStream, false);

        $this->controlStream = $parentStream;
        $this->childPid = $pid;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param list<int> $ports
     */
    public function updatePorts(array $ports): void
    {
        if ($this->stopped) {
            return;
        }

        $ports = array_values(array_unique(array_map('intval', $ports)));
        sort($ports, SORT_NUMERIC);

        if ($ports === $this->trackedPorts) {
            $this->drainMessages();

            return;
        }

        $this->trackedPorts = $ports;
        $this->pendingPorts = array_fill_keys($ports, true);

        foreach (array_keys($this->resultsByPort) as $port) {
            if (!in_array($port, $ports, true)) {
                unset($this->resultsByPort[$port]);
            }
        }

        $this->sendMessage([
            'type' => 'ports',
            'ports' => $ports,
        ]);
        $this->drainMessages();
    }

    /**
     * @param list<int> $ports
     * @return array<int, NodeLatencySnapshot>
     */
    public function snapshotForPorts(array $ports): array
    {
        $this->drainMessages();

        $snapshots = [];
        foreach ($ports as $port) {
            $snapshots[$port] = $this->pendingPorts[$port] ?? false
                ? new NodeLatencySnapshot(NodeLatencyState::Pending)
                : ($this->resultsByPort[$port] ?? new NodeLatencySnapshot(NodeLatencyState::Pending));
        }

        return $snapshots;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        if (is_resource($this->controlStream)) {
            $this->sendMessage(['type' => 'stop']);
            fclose($this->controlStream);
            $this->controlStream = null;
        }

        if ($this->childPid !== null) {
            pcntl_waitpid($this->childPid, $status, WNOHANG);
            $this->childPid = null;
        }
    }

    /**
     * @param resource $stream
     */
    private function runChildLoop($stream): void
    {
        stream_set_blocking($stream, false);

        $trackedPorts = [];
        $running = true;
        $readBuffer = '';
        $nextSweepAt = microtime(true);
        $sweepId = 0;

        while ($running) {
            $this->childReadCommands($stream, $readBuffer, $trackedPorts, $running);
            if (!$running) {
                break;
            }

            if ($trackedPorts === []) {
                $this->waitForChildInput($stream, 200_000);
                continue;
            }

            $now = microtime(true);
            if ($now < $nextSweepAt) {
                $this->waitForChildInput($stream, max(10_000, (int) (($nextSweepAt - $now) * 1_000_000)));
                continue;
            }

            $sweepId++;
            $this->childSendMessage($stream, [
                'type' => 'sweep_started',
                'sweep' => $sweepId,
                'ports' => $trackedPorts,
            ]);

            foreach ($trackedPorts as $port) {
                $result = $this->probePort($port);
                $this->childSendMessage($stream, [
                    'type' => 'probe',
                    'sweep' => $sweepId,
                    'port' => $port,
                    'state' => $result->state->value,
                    'milliseconds' => $result->milliseconds,
                ]);

                $this->childReadCommands($stream, $readBuffer, $trackedPorts, $running);
                if (!$running) {
                    break;
                }
            }

            $nextSweepAt = microtime(true) + self::SWEEP_INTERVAL_SECONDS;
        }

        fclose($stream);
    }

    private function drainMessages(): void
    {
        if (!is_resource($this->controlStream)) {
            return;
        }

        while (true) {
            $chunk = fread($this->controlStream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->readBuffer .= $chunk;
            while (($newlinePos = strpos($this->readBuffer, "\n")) !== false) {
                $line = substr($this->readBuffer, 0, $newlinePos);
                $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);
                $this->handleMessage($line);
            }
        }
    }

    private function handleMessage(string $line): void
    {
        if ($line === '') {
            return;
        }

        $message = json_decode($line, true);
        if (!is_array($message) || !is_string($message['type'] ?? null)) {
            return;
        }

        switch ($message['type']) {
            case 'sweep_started':
                $ports = is_array($message['ports'] ?? null) ? $message['ports'] : [];
                $this->pendingPorts = [];
                foreach ($ports as $port) {
                    if (is_int($port) || is_string($port)) {
                        $this->pendingPorts[(int) $port] = true;
                    }
                }
                break;

            case 'probe':
                $port = $message['port'] ?? null;
                $state = $message['state'] ?? null;
                if ((!is_int($port) && !is_string($port)) || !is_string($state)) {
                    return;
                }

                $port = (int) $port;
                unset($this->pendingPorts[$port]);

                $enum = NodeLatencyState::tryFrom($state);
                if ($enum === null) {
                    return;
                }

                $milliseconds = $message['milliseconds'] ?? null;
                $this->resultsByPort[$port] = new NodeLatencySnapshot(
                    $enum,
                    is_int($milliseconds) || is_float($milliseconds) ? (float) $milliseconds : null,
                );
                break;
        }
    }

    /**
     * @param resource $stream
     */
    private function waitForChildInput($stream, int $timeoutMicros): void
    {
        $read = [$stream];
        $write = [];
        $except = [];
        $seconds = intdiv($timeoutMicros, 1_000_000);
        $micros = $timeoutMicros % 1_000_000;
        @stream_select($read, $write, $except, $seconds, $micros);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function sendMessage(array $message): void
    {
        if (!is_resource($this->controlStream)) {
            return;
        }

        $payload = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        @fwrite($this->controlStream, $payload);
    }

    /**
     * @param resource $stream
     * @param list<int> $trackedPorts
     */
    private function childReadCommands($stream, string &$readBuffer, array &$trackedPorts, bool &$running): void
    {
        while (true) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                if (feof($stream)) {
                    $running = false;
                }
                break;
            }

            $readBuffer .= $chunk;
            while (($newlinePos = strpos($readBuffer, "\n")) !== false) {
                $line = substr($readBuffer, 0, $newlinePos);
                $readBuffer = substr($readBuffer, $newlinePos + 1);

                $message = json_decode($line, true);
                if (!is_array($message) || !is_string($message['type'] ?? null)) {
                    continue;
                }

                if ($message['type'] === 'stop') {
                    $running = false;

                    return;
                }

                if ($message['type'] !== 'ports' || !is_array($message['ports'] ?? null)) {
                    continue;
                }

                $trackedPorts = array_values(array_unique(array_map('intval', $message['ports'])));
                sort($trackedPorts, SORT_NUMERIC);
            }
        }
    }

    /**
     * @param resource $stream
     */
    /**
     * @param resource $stream
     * @param array<string, mixed> $message
     */
    private function childSendMessage($stream, array $message): void
    {
        $payload = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        @fwrite($stream, $payload);
    }

    private function probePort(int $port): NodeLatencySnapshot
    {
        $scheme = $this->tls ? 'tls' : 'tcp';
        $context = null;

        if ($this->tls) {
            $stream = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ];

            if ($this->caCert !== null && $this->caCert !== '') {
                $stream['cafile'] = $this->caCert;
            }

            $context = stream_context_create(['ssl' => $stream]);
        }

        $startedAt = hrtime(true);
        $socket = @stream_socket_client(
            sprintf('%s://127.0.0.1:%d', $scheme, $port),
            $errorCode,
            $errorMessage,
            self::PROBE_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!is_resource($socket)) {
            $state = str_contains(strtolower((string) $errorMessage), 'timed out')
                ? NodeLatencyState::Timeout
                : NodeLatencyState::Error;

            return new NodeLatencySnapshot($state);
        }

        stream_set_timeout($socket, 0, (int) (self::PROBE_TIMEOUT_SECONDS * 1_000_000));

        try {
            $written = @fwrite($socket, "*1\r\n$4\r\nPING\r\n");
            if ($written === false || $written <= 0) {
                return new NodeLatencySnapshot(NodeLatencyState::Error);
            }

            $line = @fgets($socket);
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out'] === true) {
                return new NodeLatencySnapshot(NodeLatencyState::Timeout);
            }

            if (!is_string($line) || $line === '') {
                return new NodeLatencySnapshot(NodeLatencyState::Error);
            }
        } finally {
            fclose($socket);
        }

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        return new NodeLatencySnapshot(NodeLatencyState::Ok, $elapsedMs);
    }
}
