# Harness\Tools\Shell

`Shell` runs `bash -c <command>` in a directory, through `proc_open`, reading with `fiber\readable()`.

```php
new Shell(cwd: '/srv/project', timeout: 120.0, maxTimeout: 600.0, maxOutput: 30000);
```

Gate its commands with `Rule::shell()` ([policy.md](policy.md)); its rule class `Harness\Policy\Shell`
shares the name, so a file that uses both aliases one (`use Harness\Policy\Shell as ShellRule;`).

- Input: `command`, optional `timeout` in seconds (capped at `maxTimeout`).
- stdout and stderr are merged; stdin is empty. Output is reported as it arrives through
  `Call::progress()` (`ToolProgress` events), and the model gets `Exit code N` and the output, the
  middle cut out past `maxOutput` bytes with a marker.
- A non-zero exit, a timeout or a cancellation is an error result; `value` is the exit code.
- On timeout or when the run is cancelled, the command's whole process group is killed (on Linux it
  runs under `setsid`; elsewhere only bash is terminated).
- Hints: `destructive` and `openWorld`, never `readOnly`, so the scheduler runs shell calls one at a
  time, and these hints are what a policy middleware keys on. The one-line policy, inside a
  `ToolMiddleware::call()`:

  ```php
  return $context->spec?->hints->destructive ? new Result([new Text('Commands are not allowed here')], error: true) : $next($call, $context);
  ```

  [`examples/shell.php`](../examples/shell.php) asks on the terminal before each command instead.
