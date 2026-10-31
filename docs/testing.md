# Harness\Testing

Fakes for testing hosts, tools, middleware and providers without a model.

`FakeModel` plays scripted replies, one per model call: a list of deltas, or a closure
`fn (Prompt $prompt): iterable<Delta>` that may `fiber\sleep()` or throw. `$model->prompts` records
what each call was given. Running out of replies throws.

```php
$model = new FakeModel(
    [new ToolCallStart('c1', 'read_file'), new ToolInputDelta('c1', '{"path":"a"}'), new ToolCallEnd('c1'),
     new Finish(FinishReason::ToolCalls)],
    [new TextDelta('done'), new Usage(10, 2), new Finish(FinishReason::Stop)],
);
```

`FakeTransport` plays scripted `Response`s, or closures `fn ($url, $headers, $body, Cancel $cancel):
Response`, for provider tests; `$transport->requests` records each request. A scripted body that waits
checks `$cancel` as a real transport does.

`TestCall::make(?ToolSpec $spec = null, mixed $context = null, string $id = 'test', ?Closure $emit = null)`
is a `Call` for calling a tool or a tool middleware directly. It belongs to an idle run, so
`isCancelled()` is false; `needsInput()`, `pending()` and `answer()` behave as in a turn, and `$emit`
receives the events the call emits (`progress()`).

```php
$call   = TestCall::make(new ToolSpec('write', 'Writes', ['type' => 'object'], new Hints(destructive: true)));
$result = $policy->call(new ToolCall('test', 'write', []), $call, fn () => Result::text('ran'));
assert($call->pending()?->needs[0] instanceof Approval);
$call->answer(['approval' => Decision::allow()]);     // then call the policy again

$events = [];
$shell  = new Shell('/tmp');
$shell->call(['command' => 'echo hi'], TestCall::make($shell->spec(), emit: function ($e) use (&$events) { $events[] = $e; }));
```

`TestPrompt::make(?string $system = null, array $messages = [], array $tools = [], Options $options = new Options(), bool $continue = false)`
is a `Prompt` with a `Cancel` that is never set, for calling a `ModelMiddleware` directly:

```php
$model  = new FakeModel([new TextDelta('ok'), new Finish(FinishReason::Stop)]);
$deltas = [...$compact->stream(TestPrompt::make('sys', $messages), fn (Prompt $p) => $model->stream($p))];
assert($model->prompts[0]->messages !== $messages);   // what the middleware handed on
```
