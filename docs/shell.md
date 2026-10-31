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
  middle cut out past `maxOutput` bytes with a `[N bytes cut]` marker. The cut is widened to the line
  boundaries the kept parts have, so the marker sits between whole lines; one unbroken line gets it
  inline, and no line break is invented. Bytes that are not valid UTF-8 (binary output, a character
  split by the cut) become U+FFFD.
- A non-zero exit, a timeout or a cancellation is an error result; `value` is the exit code, or
  null when the process was killed (a timeout or a cancellation), since a killed process has none.
  A command the OS refuses to start (longer than `ARG_MAX`, say) is an error result naming the
  reason and the command's size.
- The call ends when the shell exits, times out or the run is cancelled; the command's whole process
  group is then killed (on Linux it runs under `setsid`; elsewhere only bash is terminated), so a
  background child (`server &`) does not outlive the call. A host that wants long-running processes
  gives the agent a tool for them.
- Hints: `destructive` and `openWorld`, never `readOnly`, so the scheduler runs shell calls one at a
  time, and these hints are what a policy middleware keys on. The one-line policy, inside a
  `ToolMiddleware::call()`:

  ```php
  return $context->spec?->hints->destructive ? new Result([new Text('Commands are not allowed here')], error: true) : $next($call, $context);
  ```

  [`examples/shell.php`](../examples/shell.php) asks on the terminal before each command instead.
