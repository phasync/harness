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

## Writes

Both open their file in append mode and write a whole line (or a whole `append()`) at once, so
writers in several fibers or processes interleave whole lines, never parts of them. A short write,
which only a non-blocking stream gives, waits with `fiber\writable()` and writes the rest; fibers
writing to one `JsonlEvents` at once wait their turn. Reading fails loudly, naming the line, on a
line that is not JSON or has no newline.

Two processes writing one file with plain `fopen($path, 'w')` and `fwrite()` overwrite each other:
each has its own offset, so lines from both end up cut into each other. Append mode is what keeps
them whole.
