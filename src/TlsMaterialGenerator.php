<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use RuntimeException;
use Symfony\Component\Process\Process;

final class TlsMaterialGenerator
{
    /**
     * @return array{ca_cert: string, server_cert: string, server_key: string}
     */
    public function generate(string $clusterDir, ?string $announceIp, int $days, int $rsaBits): array
    {
        $tlsDir = sprintf('%s/tls', $clusterDir);
        if (!mkdir($concurrentDirectory = $tlsDir, 0o700, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Failed to create TLS directory: %s', $tlsDir));
        }

        $caKey = sprintf('%s/ca.key', $tlsDir);
        $caCrt = sprintf('%s/ca.crt', $tlsDir);
        $serverKey = sprintf('%s/server.key', $tlsDir);
        $serverCsr = sprintf('%s/server.csr', $tlsDir);
        $serverCrt = sprintf('%s/server.crt', $tlsDir);
        $extension = sprintf('%s/server.ext', $tlsDir);

        $altNames = [
            'IP.1=127.0.0.1',
            'DNS.1=localhost',
        ];

        if ($announceIp !== null && $announceIp !== '' && $announceIp !== '127.0.0.1') {
            $altNames[] = sprintf('IP.2=%s', $announceIp);
        }

        $extensionContents = "[v3_req]\nsubjectAltName=@alt_names\n[alt_names]\n" . implode("\n", $altNames) . "\n";
        if (file_put_contents($extension, $extensionContents, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed writing TLS extension file: %s', $extension));
        }

        $this->run(['openssl', 'genrsa', '-out', $caKey, (string) $rsaBits]);
        $this->run([
            'openssl', 'req', '-x509', '-new', '-nodes',
            '-key', $caKey,
            '-sha256',
            '-days', (string) $days,
            '-subj', '/CN=redis-ephemeral-ca',
            '-out', $caCrt,
        ]);

        $this->run(['openssl', 'genrsa', '-out', $serverKey, (string) $rsaBits]);
        $this->run([
            'openssl', 'req', '-new',
            '-key', $serverKey,
            '-subj', '/CN=redis-ephemeral-server',
            '-out', $serverCsr,
        ]);

        $this->run([
            'openssl', 'x509', '-req',
            '-in', $serverCsr,
            '-CA', $caCrt,
            '-CAkey', $caKey,
            '-CAcreateserial',
            '-out', $serverCrt,
            '-days', (string) $days,
            '-sha256',
            '-extfile', $extension,
            '-extensions', 'v3_req',
        ]);

        return [
            'ca_cert' => $caCrt,
            'server_cert' => $serverCrt,
            'server_key' => $serverKey,
        ];
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): void
    {
        $process = new Process($command);
        $process->mustRun();
    }
}
