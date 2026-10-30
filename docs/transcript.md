# Harness\Transcript

Files for what a host keeps: the conversation, and the event stream.

## Transcript

```php
interface Transcript {
    public function load(): array;              // list<Message>
    public function append(array $messages): void;
    public function clear(): void;
}
```

`JsonlTranscript($path)` keeps one message per line (`Message::toArray()` as JSON). After a turn,
append what it added:

```php
$log = new JsonlTranscript('/var/lib/app/chat-42.jsonl');
$run = new Run($model, $tools, messages: $log->load());
foreach ($run->turn($message) as $event) { ... }
$log->append($run->ended()->appended);
```

A missing file is an empty transcript. Pending calls are not messages: a host that pauses persists
`Ended::$pending` next to it (see the README).

## JsonlEvents

An event sink: `write(Event)` appends one JSON line (`Event::toArray()`), `replay()` reads the file
back as typed events through `Events::fromArray()`.

```php
$sink = new JsonlEvents('/var/log/app/turn-42.jsonl');   // or 'php://stdout'
foreach ($run->turn($message) as $event) {
    $sink->write($event);
}
foreach ((new JsonlEvents($path))->replay() as $line => $event) { ... }
```

## Both in one host

A host that keeps the conversation and a log of every event, and resumes a pause in a later request:

```php
$log    = new JsonlTranscript("$dir/chat.jsonl");
$events = new JsonlEvents("$dir/events.jsonl");
$state  = \is_file("$dir/pending.json") ? \json_decode(\file_get_contents("$dir/pending.json"), true) : [];
$run    = new Run($model, $tools, messages: $log->load(), pending: \array_map(Pending::fromArray(...), $state));

foreach ($run->turn($input) as $event) {    // a user Message, or the Answers to $state
    $events->write($event);                 // and render or forward it
}
$log->append($run->ended()->appended);
\file_put_contents("$dir/pending.json", \json_encode(\array_map(fn (Pending $p) => $p->toArray(), $run->ended()->pending)));
```

`JsonlEvents` takes a path (or `php://stdout`). To stream to a browser, a Swerve route writes SSE
itself: `"data: " . json_encode($event) . "\n\n"` per event, which `JsonlEvents` cannot frame.

## Writes

Both open their file in append mode and write a whole line (or a whole `append()`) at once, so
writers in several fibers or processes interleave whole lines, never parts of them. A short write,
which only a non-blocking stream gives, waits with `fiber\writable()` and writes the rest; fibers
writing to one `JsonlEvents` at once wait their turn. Reading fails loudly, naming the line, on a
line that is not JSON or has no newline.

Two processes writing one file with plain `fopen($path, 'w')` and `fwrite()` overwrite each other:
each has its own offset, so lines from both end up cut into each other. Append mode is what keeps
them whole.
