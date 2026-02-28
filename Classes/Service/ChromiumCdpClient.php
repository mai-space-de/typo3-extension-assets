<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Service;

use Symfony\Component\Process\Process;

/**
 * Minimal Chrome DevTools Protocol (CDP) WebSocket client.
 *
 * Spawns a Chromium instance in headless mode and communicates with it via
 * the CDP WebSocket API to extract above-fold CSS coverage and inline scripts
 * for a given page URL and viewport size.
 *
 * No Node.js or external PHP packages are required beyond `symfony/process`
 * (which ships with TYPO3 core). All WebSocket framing follows RFC 6455.
 *
 * Typical usage:
 *
 *   $client = new ChromiumCdpClient('/usr/bin/chromium');
 *   $client->start();
 *   $client->setViewport(375, 667);   // mobile
 *   $client->navigate('https://example.com/about');
 *   $css = $client->getAboveFoldCriticalCss(667);
 *   $js  = $client->getAboveFoldCriticalJs(667);
 *   $client->close();
 *
 * @see CriticalAssetService
 */
final class ChromiumCdpClient
{
    private ?Process $process = null;

    /** @var resource|null */
    private $socket = null;

    private int $commandId = 1;
    private readonly int $debugPort;

    public function __construct(
        private readonly string $chromiumBin,
        private readonly int $connectTimeoutMs = 5000,
        private readonly int $pageLoadTimeoutMs = 15000,
    ) {
        $this->debugPort = random_int(9100, 9999);
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Spawn Chromium and connect to its DevTools WebSocket.
     * Must be called before any navigation or extraction methods.
     */
    public function start(): void
    {
        $this->spawnChromium();
        $this->waitForChromium();
        $wsUrl = $this->createTab();
        $this->connectWebSocket($wsUrl);
        $this->enableDomains();
    }

    /**
     * Override the browser viewport (width × height in CSS pixels).
     * Sets mobile=true when width ≤ 768 to activate the mobile UA and touch emulation.
     */
    public function setViewport(int $width, int $height): void
    {
        $this->sendCommandSync('Emulation.setDeviceMetricsOverride', [
            'width'             => $width,
            'height'            => $height,
            'deviceScaleFactor' => 1,
            'mobile'            => $width <= 768,
            'screenWidth'       => $width,
            'screenHeight'      => $height,
        ]);
    }

    /**
     * Navigate to a URL and block until Page.loadEventFired.
     * CSS rule-usage tracking is started before navigation.
     */
    public function navigate(string $url): void
    {
        $this->sendCommandSync('CSS.startRuleUsageTracking');
        $this->sendCommandSync('Page.navigate', ['url' => $url]);
        $this->waitForEvent('Page.loadEventFired');
    }

    /**
     * Extract critical CSS for elements visible above the fold.
     *
     * Strategy:
     *  1. Inject JS to collect tag names, class names, and IDs of all elements
     *     whose bounding rect top is within the current viewport height.
     *  2. Stop CSS coverage tracking to get the list of rules that were applied.
     *  3. Fetch full stylesheet texts and slice out the used rule ranges.
     *  4. Filter those ranges to only rules whose selectors reference above-fold tokens.
     *  5. Return the concatenated critical CSS string.
     */
    public function getAboveFoldCriticalCss(int $viewportHeight): string
    {
        $tokens = $this->collectAboveFoldTokens($viewportHeight);

        $coverageResult = $this->sendCommandSync('CSS.stopRuleUsageTracking');
        /** @var array<array{styleSheetId: string, startOffset: int, endOffset: int, used: bool}> $ruleUsage */
        $ruleUsage = $coverageResult['result']['ruleUsage'] ?? [];

        // Index used ranges by stylesheet id.
        /** @var array<string, list<array{start: int, end: int}>> $usedBySheet */
        $usedBySheet = [];
        foreach ($ruleUsage as $rule) {
            if (!empty($rule['used'])) {
                $usedBySheet[$rule['styleSheetId']][] = [
                    'start' => (int)$rule['startOffset'],
                    'end'   => (int)$rule['endOffset'],
                ];
            }
        }

        if ($usedBySheet === []) {
            return '';
        }

        // Get all stylesheet headers so we know which ones had coverage hits.
        $sheetsResult = $this->sendCommandSync('CSS.getAllStyleSheets');
        /** @var list<array{styleSheetId: string}> $headers */
        $headers = $sheetsResult['result']['headers'] ?? [];

        $criticalCss = '';

        foreach ($headers as $sheet) {
            $sheetId = $sheet['styleSheetId'];
            if (!isset($usedBySheet[$sheetId])) {
                continue;
            }

            $textResult = $this->sendCommandSync('CSS.getStyleSheetText', ['styleSheetId' => $sheetId]);
            $sheetText  = $textResult['result']['text'] ?? '';
            if ($sheetText === '') {
                continue;
            }

            foreach ($usedBySheet[$sheetId] as $range) {
                $ruleText = trim(substr($sheetText, $range['start'], $range['end'] - $range['start']));
                if ($ruleText !== '' && $this->ruleIsAboveFold($ruleText, $tokens)) {
                    $criticalCss .= $ruleText . "\n";
                }
            }
        }

        return $criticalCss;
    }

    /**
     * Extract synchronous inline scripts that execute before DOMContentLoaded.
     *
     * Collects <script> elements without src, defer, async, or type="module"
     * that are either inside <head> or whose top edge is within the viewport.
     * Tracking/analytics patterns are excluded.
     */
    public function getAboveFoldCriticalJs(int $viewportHeight): string
    {
        $script = sprintf(
            '(function(vh){
                var out=[];
                document.querySelectorAll("script:not([src]):not([type=\\"module\\"])").forEach(function(el){
                    var rect=el.getBoundingClientRect();
                    var content=el.textContent.trim();
                    var inHead=!!el.closest("head");
                    var aboveFold=rect.top<vh&&rect.bottom>=0;
                    if((inHead||aboveFold)&&content.length>0&&content.length<30000){
                        if(!content.match(/gtag|ga\(|fbq|analytics|dataLayer/i)){
                            out.push(content);
                        }
                    }
                });
                return JSON.stringify(out);
            })(%d)',
            $viewportHeight,
        );

        $result  = $this->sendCommandSync('Runtime.evaluate', ['expression' => $script, 'returnByValue' => true]);
        $raw     = $result['result']['result']['value'] ?? '[]';
        $scripts = json_decode((string)$raw, true);

        return is_array($scripts) ? implode("\n", $scripts) : '';
    }

    /**
     * Close the WebSocket connection and terminate the Chromium process.
     * Safe to call multiple times.
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            try {
                $this->sendCommand('Browser.close');
                usleep(200_000);
            } catch (\Throwable) {
                // ignore on close
            }

            fclose($this->socket);
            $this->socket = null;
        }

        if ($this->process !== null) {
            $this->process->stop(3);
            $this->process = null;
        }
    }

    // ─── Chromium lifecycle ───────────────────────────────────────────────────

    private function spawnChromium(): void
    {
        $this->process = new Process([
            $this->chromiumBin,
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-background-timer-throttling',
            '--disable-renderer-backgrounding',
            '--disable-breakpad',
            '--disable-client-side-phishing-detection',
            '--disable-default-apps',
            '--disable-hang-monitor',
            '--disable-prompt-on-repost',
            '--disable-sync',
            '--disable-translate',
            '--metrics-recording-only',
            '--safebrowsing-disable-auto-update',
            '--remote-debugging-port=' . $this->debugPort,
            '--remote-debugging-address=127.0.0.1',
            'about:blank',
        ]);

        $this->process->start();
    }

    private function waitForChromium(): void
    {
        $deadline = microtime(true) + ($this->connectTimeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $ctx      = stream_context_create(['http' => ['timeout' => 0.5]]);
            $response = @file_get_contents(
                'http://127.0.0.1:' . $this->debugPort . '/json/version',
                false,
                $ctx,
            );

            if ($response !== false) {
                return;
            }

            usleep(100_000); // 100 ms
        }

        throw new \RuntimeException(
            'Chromium did not become ready within ' . $this->connectTimeoutMs . ' ms. '
            . 'Check that the binary at "' . $this->chromiumBin . '" works and has sufficient permissions.',
        );
    }

    private function createTab(): string
    {
        $ctx      = stream_context_create(['http' => ['timeout' => 5]]);
        $response = @file_get_contents('http://127.0.0.1:' . $this->debugPort . '/json/new', false, $ctx);

        if ($response === false) {
            throw new \RuntimeException('Failed to create a Chromium debugging tab via /json/new');
        }

        $data = json_decode($response, true);

        if (!isset($data['webSocketDebuggerUrl']) || !is_string($data['webSocketDebuggerUrl'])) {
            throw new \RuntimeException('Unexpected response from /json/new — webSocketDebuggerUrl missing');
        }

        return $data['webSocketDebuggerUrl'];
    }

    // ─── WebSocket connection ─────────────────────────────────────────────────

    private function connectWebSocket(string $wsUrl): void
    {
        $parsed = parse_url($wsUrl);
        $host   = (string)($parsed['host'] ?? '127.0.0.1');
        $port   = (int)($parsed['port'] ?? 80);
        $path   = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        $socket = stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new \RuntimeException(
                "Cannot connect to Chromium WebSocket tcp://{$host}:{$port}: {$errstr} ({$errno})",
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, 30);

        // Perform the HTTP→WebSocket upgrade handshake (RFC 6455 §4.1).
        $key = base64_encode(random_bytes(16));
        fwrite(
            $this->socket,
            "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n",
        );

        $response = '';
        while ($this->socket !== null) {
            $line = fgets($this->socket);
            if ($line === false) {
                break;
            }

            $response .= $line;
            if ($line === "\r\n") {
                break;
            }
        }

        if (!str_contains($response, '101 Switching Protocols')) {
            throw new \RuntimeException('WebSocket upgrade rejected by Chromium: ' . $response);
        }
    }

    private function enableDomains(): void
    {
        $this->sendCommandSync('Page.enable');
        $this->sendCommandSync('CSS.enable');
        $this->sendCommandSync('Runtime.enable');
    }

    // ─── CDP helpers ─────────────────────────────────────────────────────────

    /**
     * Fire-and-forget CDP command (no response expected by caller).
     *
     * @param array<string, mixed> $params
     */
    private function sendCommand(string $method, array $params = []): void
    {
        $message = json_encode([
            'id'     => $this->commandId++,
            'method' => $method,
            'params' => $params,
        ]);

        $this->writeFrame((string)$message);
    }

    /**
     * Send a CDP command and block until a response with the matching id arrives.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sendCommandSync(string $method, array $params = []): array
    {
        $id = $this->commandId;
        $this->sendCommand($method, $params);

        $deadline = microtime(true) + ($this->pageLoadTimeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $frame = $this->readFrame(1.0);
            if ($frame === '') {
                continue;
            }

            $data = json_decode($frame, true);
            if (is_array($data) && isset($data['id']) && (int)$data['id'] === $id) {
                return $data;
            }
        }

        throw new \RuntimeException(
            "CDP command '{$method}' timed out after {$this->pageLoadTimeoutMs} ms",
        );
    }

    private function waitForEvent(string $method): void
    {
        $deadline = microtime(true) + ($this->pageLoadTimeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $frame = $this->readFrame(1.0);
            if ($frame === '') {
                continue;
            }

            $data = json_decode($frame, true);
            if (is_array($data) && isset($data['method']) && $data['method'] === $method) {
                return;
            }
        }

        throw new \RuntimeException(
            "Timed out waiting for CDP event '{$method}' after {$this->pageLoadTimeoutMs} ms",
        );
    }

    // ─── WebSocket frame encoding (RFC 6455) ─────────────────────────────────

    /**
     * Write a masked text frame. Clients MUST mask all frames sent to the server.
     */
    private function writeFrame(string $payload): void
    {
        $len   = strlen($payload);
        $frame = "\x81"; // FIN=1, opcode=1 (text frame)

        if ($len <= 125) {
            $frame .= chr($len | 0x80);
        } elseif ($len <= 65535) {
            $frame .= chr(126 | 0x80) . pack('n', $len);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $len);
        }

        $mask   = random_bytes(4);
        $frame .= $mask;

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        fwrite($this->socket, $frame . $masked);
    }

    /**
     * Read one WebSocket frame from the socket, waiting at most $timeoutSec seconds.
     * Returns an empty string on timeout or connection error.
     */
    private function readFrame(float $timeoutSec): string
    {
        if ($this->socket === null) {
            return '';
        }

        stream_set_timeout(
            $this->socket,
            (int)$timeoutSec,
            (int)(fmod($timeoutSec, 1.0) * 1_000_000),
        );

        $header = fread($this->socket, 2);
        if ($header === false || strlen($header) < 2) {
            return '';
        }

        if (stream_get_meta_data($this->socket)['timed_out']) {
            return '';
        }

        $payloadLen = ord($header[1]) & 0x7F;

        if ($payloadLen === 126) {
            $ext = fread($this->socket, 2);
            if ($ext === false || strlen($ext) < 2) {
                return '';
            }

            $payloadLen = (int)unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = fread($this->socket, 8);
            if ($ext === false || strlen($ext) < 8) {
                return '';
            }

            $payloadLen = (int)unpack('J', $ext)[1];
        }

        if ($payloadLen === 0) {
            return '';
        }

        $payload   = '';
        $remaining = $payloadLen;

        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $payload   .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $payload;
    }

    // ─── Above-fold helpers ───────────────────────────────────────────────────

    /**
     * Return the set of CSS selector tokens (tag names, class names, IDs) for all
     * elements whose top edge is within the viewport height.
     *
     * @return list<string>
     */
    private function collectAboveFoldTokens(int $viewportHeight): array
    {
        $script = sprintf(
            '(function(vh){
                var t=new Set(["html","body","*",":root","::before","::after","::placeholder","::selection"]);
                document.querySelectorAll("*").forEach(function(el){
                    var r=el.getBoundingClientRect();
                    if(r.top<vh&&r.bottom>=0){
                        t.add(el.tagName.toLowerCase());
                        if(el.id){t.add("#"+el.id);}
                        el.classList.forEach(function(c){
                            t.add("."+c);
                            t.add(el.tagName.toLowerCase()+"."+c);
                        });
                    }
                });
                return JSON.stringify([...t]);
            })(%d)',
            $viewportHeight,
        );

        $result  = $this->sendCommandSync('Runtime.evaluate', ['expression' => $script, 'returnByValue' => true]);
        $raw     = $result['result']['result']['value'] ?? '[]';
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Return true when the CSS rule text is relevant to above-fold elements.
     *
     * @param list<string> $tokens
     */
    private function ruleIsAboveFold(string $ruleText, array $tokens): bool
    {
        $trimmed = ltrim($ruleText);

        // Always include @-rules (keyframes, custom properties, media, font-face, etc.)
        if (str_starts_with($trimmed, '@')) {
            return true;
        }

        // Always include universal / root element rules.
        if (preg_match('/^(html|body|\*|:root|::?[a-z])/i', $trimmed)) {
            return true;
        }

        // Check if any above-fold token appears in the selector portion of the rule.
        $bracePos = strpos($ruleText, '{');
        $selector = $bracePos !== false ? substr($ruleText, 0, $bracePos) : $ruleText;

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($selector, $token)) {
                return true;
            }
        }

        return false;
    }
}
