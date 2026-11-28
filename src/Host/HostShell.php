<?php

declare(strict_types=1);

namespace Dashboard\Host;

final class HostShell
{
    /** @param null|\Closure(list<string>): ?array{code: int, out: string, err: string} $fake */
    public function __construct(private readonly ?\Closure $fake = null) {}

    /**
     * @param list<string> $argv
     * @return array{code: int, out: string, err: string}|null
     */
    public function run(array $argv, int $timeout = 8): ?array
    {
        if ($this->fake !== null) {
            return ($this->fake)($argv);
        }
        $bin = $argv[0] ?? '';
        if ($bin === '' || !is_executable($bin)) {
            return null;
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $start = time();
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) > $timeout) {
                proc_terminate($proc, 9);
                proc_close($proc);

                return null;
            }
            usleep(50000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'out' => $out, 'err' => $err];
    }
}

