<?php

declare(strict_types=1);

namespace TeePay\Tests;

/**
 * Starts the PHP built-in server with tests/server/router.php once per test run and stops it
 * at shutdown. Only the process started here is ever terminated.
 */
final class MockServer
{
    private static ?self $instance = null;

    /** @var resource|null */
    private $process;
    public readonly string $url;
    public readonly string $stateDir;

    private function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('No free port: ' . $error);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $this->url = 'http://127.0.0.1:' . $port;
        $this->stateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'teepay-php-mock-' . getmypid() . '-' . $port;
        @mkdir($this->stateDir, 0777, true);

        $env = getenv();
        $env['TEEPAY_MOCK_STATE'] = $this->stateDir;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if ($ini !== false) {
            $command[] = '-c';
            $command[] = $ini;
        }
        array_push($command, '-S', '127.0.0.1:' . $port, __DIR__ . '/server/router.php');
        $this->process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, __DIR__ . '/server', $env);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Could not start the mock server.');
        }
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($connection !== false) {
                fclose($connection);
                register_shutdown_function([$this, 'stop']);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new \RuntimeException('The mock server did not start.');
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    public function reset(): void
    {
        $this->control('reset', []);
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    public function enqueue(array $responses): void
    {
        $this->control('enqueue', $responses);
    }

    /**
     * @return list<array{method: string, path: string, raw_path: string, query: array<string, mixed>, raw_query: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        return json_decode($this->control('requests', null), true);
    }

    private function control(string $action, ?array $payload): string
    {
        $context = stream_context_create(['http' => [
            'method' => $payload === null ? 'GET' : 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload === null ? '' : json_encode($payload),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        return (string) file_get_contents($this->url . '/__control/' . $action, false, $context);
    }
}
