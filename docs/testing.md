# Harness\Testing

Fakes for testing hosts, tools and providers without a model.

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

`FakeTransport` plays scripted `Response`s (or closures `fn ($url, $headers, $body): Response`) for
provider tests; `$transport->requests` records each request.

A tool can be called directly with a `Call` built for the purpose; see `callFor()` in
`tests/Pest.php`.
