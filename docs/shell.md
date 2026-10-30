# Harness\Tools\Shell

`Shell` runs `bash -c <command>` in a directory, through `proc_open`, reading with `fiber\readable()`.

```php
new Shell(cwd: '/srv/project', timeout: 120.0, maxTimeout: 600.0, maxOutput: 30000);
```

- Input: `command`, optional `timeout` in seconds (capped at `maxTimeout`).
- stdout and stderr are merged; stdin is empty. Output is reported as it arrives through
  `Call::progress()` (`ToolProgress` events), and the model gets `Exit code N` and the output, the
  middle cut out past `maxOutput` bytes with a marker.
- A non-zero exit, a timeout or a cancellation is an error result; `value` is the exit code.
- On timeout or when the run is cancelled, the command's whole process group is killed (on Linux it
  runs under `setsid`; elsewhere only bash is terminated).
- Hints: `destructive`, `openWorld`. Which commands may run is a policy for middleware:
  [`examples/shell.php`](../examples/shell.php) asks on the terminal before each one.
