<?php

declare(strict_types=1);

namespace Dashboard\Agents;

/**
 * Runs a fixed local-agent binary. The argument list is never passed through a shell.
 */
final class Command
{
    public const HERMES = '/var/lib/lifedash/hermes/.local/bin/hermes';

    public const OPENCODE = '/var/lib/lifedash/opencode/.opencode/bin/opencode';

    public const OPENCLAW = '/var/lib/lifedash/openclaw/bin/openclaw';

    public const OPENCLAW_HOME = '/var/lib/lifedash/openclaw';

    public const HERMES_HOME = '/var/lib/lifedash/hermes';

    public const OPENCODE_CONFIG = '/etc/lifedash/opencode.json';

    /** @var list<string> */
    public const BINARIES = [self::HERMES, self::OPENCODE, self::OPENCLAW];

    /**
     * @param null|callable(string, list<string>, int): array{code: int, out: string, err: string} $runner
     */
    public function __construct(private $runner = null)
    {
    }

    public function injected(): bool
    {
        return $this->runner !== null;
    }

    public static function installed(string $path): bool
    {
        return in_array($path, self::BINARIES, true) && is_file($path) && is_executable($path);
    }

    /**
     * @param list<string> $args
     * @return array{code: int, out: string, err: string}
     */
    public function run(string $binary, array $args, int $timeout): array
    {
        if (!in_array($binary, self::BINARIES, true)) {
            return ['code' => 127, 'out' => '', 'err' => 'Unknown binary.'];
        }
        foreach ($args as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                return ['code' => 127, 'out' => '', 'err' => 'Bad argument.'];
            }
        }
        if ($this->runner !== null) {
            return ($this->runner)($binary, $args, $timeout);
        }
        if (!self::installed($binary)) {
            return ['code' => 127, 'out' => '', 'err' => 'Not installed.'];
        }

        return $this->exec($binary, $args, max(1, $timeout));
    }

    /**
     * @param list<string> $args
     * @return array{code: int, out: string, err: string}
     */
    private function exec(string $binary, array $args, int $timeout): array
    {
        $command = array_merge([$binary], $args);
        $proc = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, self::env($binary));
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'Could not start the command.'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($proc);
                proc_close($proc);

                return ['code' => 124, 'out' => self::clip($out), 'err' => 'Timed out.'];
            }
            $ready = stream_select($read, $write, $except, 0, (int) min(200000, $left * 1000000));
            if ($ready === false) {
                break;
            }
            if (is_array($read)) {
                foreach ($read as $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if (!is_string($chunk) || $chunk === '') {
                        continue;
                    }
                    if ($pipe === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }
            $status = proc_get_status($proc);
            if (is_array($status) && !$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);

                return ['code' => $code, 'out' => self::clip($out), 'err' => self::clip($err)];
            }
        }
        proc_terminate($proc);
        proc_close($proc);

        return ['code' => 127, 'out' => self::clip($out), 'err' => self::clip($err)];
    }

    /** @return array<string, string> */
    private static function env(string $binary): array
    {
        $path = '/usr/bin:/bin';
        $env = ['PATH' => $path, 'LANG' => 'C.UTF-8', 'TMPDIR' => '/tmp', 'TEMP' => '/tmp'];
        if ($binary === self::HERMES) {
            $env['HOME'] = self::HERMES_HOME;
            $env['HERMES_HOME'] = self::HERMES_HOME;
            $env['PATH'] = self::HERMES_HOME . '/.local/bin:' . $path;
        }
        if ($binary === self::OPENCLAW) {
            $env['HOME'] = self::OPENCLAW_HOME;
            $env['OPENCLAW_HOME'] = self::OPENCLAW_HOME;
            $env['PATH'] = self::OPENCLAW_HOME . '/bin:' . self::OPENCLAW_HOME . '/tools/node/bin:' . $path;
        }
        if ($binary === self::OPENCODE) {
            $env['HOME'] = '/var/lib/lifedash/opencode';
            $env['OPENCODE_CONFIG'] = self::OPENCODE_CONFIG;
        }

        return $env;
    }

    private static function clip(string $text): string
    {
        return mb_substr($text, 0, 8000);
    }
}
