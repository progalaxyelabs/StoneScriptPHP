<?php
// Framework/cli/cli-server.php

class CLIServer {
    private int $port = 9810;
    private string $host = '127.0.0.1';

    public function start(): void {
        echo "🚀 StoneScriptPHP CLI Server\n";
        echo "   Listening on http://{$this->host}:{$this->port}\n";
        echo "   Press Ctrl+C to stop\n\n";

        // Start PHP built-in server with router
        $router = __DIR__ . '/cli-server-router.php';
        $command = sprintf(
            'php -S %s:%d %s',
            $this->host,
            $this->port,
            escapeshellarg($router)
        );

        passthru($command);
    }
}

// Run server
$server = new CLIServer();
$server->start();
