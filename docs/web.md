# Harness\Tools\Web

Two tools over the web: `web_search` for a query, `web_fetch` for a URL.

```php
$search = new SearXng(new StreamTransport(), 'http://127.0.0.1:8888');
$tools  = new Tools(new WebSearch($search), new WebFetch(new StreamTransport(), userAgent: 'my-agent/1.0 (+https://example.com/bot)'));
```

| Tool | Input | Hints |
| --- | --- | --- |
| `web_search` | `query`, `limit` (1-10, default 5) | readOnly, idempotent, openWorld |
| `web_fetch` | `url`, `offset` (character offset) | readOnly, idempotent, openWorld |

## Search and SearXng

`Search::search(string $query, int $limit): list<Hit>` is the interface a search engine
implements; `Hit` is a readonly value (`url`, `title`, `snippet`, `publishedAt` as the engine's own
date string when it gave one, `engine`).

`SearXng` implements it over a self-hosted [SearXng](https://docs.searxng.org/) instance's JSON
API: GET `{baseUrl}/search?q=...&format=json`, with `language`, `time_range` and `categories`
(joined by a comma) added when given. A non-200 reply, or one that is not the expected JSON,
throws `\RuntimeException` naming the URL and the status.

`WebSearch` numbers the hits for the model, each with its title, URL and a snippet cut at about
300 characters: `"1. Title — https://...\n   snippet"`. Zero hits is a normal result that says so.

## WebFetch and the extractor

`web_fetch` fetches a URL with the `userAgent` the constructor requires (no default, so a site can
identify the agent instead of guessing) and `Accept: text/html,application/xhtml+xml,text/*,
application/json`. An HTML or XHTML reply is turned into readable text by `Harness\Tools\Web\Html`,
a module-private extractor with no dependency beyond ext-dom: it drops `script`, `style`,
`noscript`, `svg`, `nav`, `footer`, `header`, `aside`, `form` and comments, keeps the `<title>` as
the first line, turns headings into `"# "`.."######" lines by level, separates paragraphs by a
blank line, renders a list as one `"- "` line per item (a nested list's items indented under the parent item), a link as `"text (url)"` only when the text
differs from the URL, keeps `pre`/`code` verbatim, renders a table as one row per line with cells
joined by `" | "`, and otherwise collapses whitespace and decodes entities. `text/plain`,
`application/json` and other `text/*` replies come back as is; anything else is an error result
naming the content type (`web_fetch does not read <type>; only text`). A gzip or deflate
`Content-Encoding` is decoded; any other is an error result. The body is converted to UTF-8 from the
`charset` of `Content-Type`, else from a `<meta charset>` in an HTML page's first 1024 bytes (where the
HTML prescan looks), else it is taken as UTF-8; a charset mbstring does not know is an error result.
The response body is read up to
`maxBytes` (default 5 MB) of decoded bytes and then stopped; the extracted text is cut at `maxChars` (default
20000) per call with the files tools' tail idiom, `offset` continuing from where the previous call
left off.

## The SSRF guard

A URL whose scheme is not `http`/`https`, or whose host is `localhost` or `*.localhost`, an
address in a private, loopback or link-local range (`0/8`, `127/8`, `10/8`, `172.16/12`,
`192.168/16`, `169.254/16`, `::1`, `fc00::/7`, `fe80::/10`, and IPv4-mapped IPv6 forms of these),
a numeric host that is not a canonical literal (`2130706433`, `127.1`, `0x7f000001`: the resolver
accepts them, so they are refused outright), or a name that resolves to any such address, is an
error result unless the constructor's `allowPrivate: true` (default `false`): `web_fetch` runs
inside agents that may be on a server with its own internal network, and this is the guard against
them being used to probe it. The name is resolved once by the guard and again by the transport, so a
record that changes between the two (DNS rebinding) is not covered; a redirect's target is checked
by the same guard, and a space or non-ASCII byte in a `Location` header is percent-encoded before
the next request.

## Cache

An optional `cacheDir` constructor argument stores a fetched page's extracted text under
`sha256(url)` and reuses it for `cacheTtl` seconds (default 3600) without a new request.

## Running SearXNG

A local instance for `SearXng` to query:

```bash
docker run -d --name searxng --restart unless-stopped -p 127.0.0.1:8888:8080 -v ./searxng:/etc/searxng searxng/searxng
```

Its `./searxng/settings.yml` (SearXng writes a default one on first run; edit it, then
`docker restart searxng`):

```yaml
use_default_settings: true
server:
  secret_key: "<random string; openssl rand -hex 32>"
  limiter: false   # fine for a localhost-only instance; the built-in rate limiter needs Redis
search:
  formats:
    - html
    - json   # off by default; this is the usual stumbling block for the JSON API SearXng uses
```

A query forwards to public search engines from your server's own IP, so keep the rate low: a
handful of queries, never a loop. Verify it with:

```bash
curl 'http://127.0.0.1:8888/search?q=test&format=json'
```
