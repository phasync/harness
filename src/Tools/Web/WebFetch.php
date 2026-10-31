<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

use Harness\Call;
use Harness\Cancel;
use Harness\Hints;
use Harness\Http\Transport;
use Harness\Internal\Fs;
use Harness\Internal\Utf8;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `web_fetch`: a URL's text, HTML converted to readable text by `Html`, read with `$userAgent`
 * (required, with no default, so a site being fetched can identify the agent instead of guessing
 * from a generic header) and `Accept: text/html,application/xhtml+xml,text/*,application/json`.
 * `text/plain`, `application/json` and other `text/*` replies come back as is; anything else is an
 * error result naming the content type. A gzip or deflate `Content-Encoding` is decoded, any other
 * is an error result (a gzip body may hold several members). The body is converted to UTF-8 from
 * a leading byte-order mark (which outranks a wrong header, as in browsers), else the `charset` of
 * `Content-Type`, else a `<meta charset>` in an HTML page's first 1024 bytes (where the HTML
 * prescan looks), else it is taken as UTF-8; a charset mbstring does not know is an error result.
 * A declared `iso-8859-1` (or `latin1`, `us-ascii`, ...) is read as windows-1252, `shift_jis` as
 * windows-31j, `gb2312` as GBK, `euc-kr` as windows-949 and `iso-8859-9` as windows-1254, the
 * decoders the WHATWG Encoding standard gives those labels, as browsers do. The response body is read up to
 * `maxBytes` (default 5 MB) of decoded bytes
 * and then stopped; the extracted text is cut at `maxChars` (default 20000) per call with the
 * files tools' tail idiom (`offset` continues from where the previous call left off). A
 * private-network or non-http(s) URL (`localhost`, `127/8`, `10/8`, `172.16/12`, `192.168/16`,
 * `169.254/16`, `::1`, `fc00::/7`, `file:`, `ftp:`, ...) is refused as an SSRF guard unless
 * `allowPrivate` is true (default false). When `cacheDir` is set, a fetched page's text is stored
 * by `sha256(url)` and reused for `cacheTtl` seconds (default 3600) without a new request; a
 * continuation (`offset` > 0) reads it however old, so it continues the page it started. Without
 * `cacheDir` a continuation fetches the page again, and a page that changed in between is read as
 * it is now.
 */
final readonly class WebFetch implements Tool
{
    /**
     * Charset labels whose decoder, in the WHATWG Encoding standard, is not the encoding the label
     * names (pages declare the standard's name and send the Windows superset), as mbstring names it.
     */
    private const DECODERS = [
        'windows-1252' => ['iso-8859-1', 'iso8859-1', 'iso_8859-1', 'iso_8859-1:1987', 'iso-ir-100', 'latin1', 'l1', 'cp819', 'ibm819', 'csisolatin1', 'us-ascii', 'ascii', 'ansi_x3.4-1968', 'cp1252', 'x-cp1252'],
        'windows-1254' => ['iso-8859-9', 'iso8859-9', 'iso_8859-9', 'iso_8859-9:1989', 'iso-ir-148', 'latin5', 'l5', 'csisolatin5', 'cp1254', 'x-cp1254'],
        'CP932'        => ['shift_jis', 'shift-jis', 'sjis', 'x-sjis', 'ms_kanji', 'ms932', 'csshiftjis', 'windows-31j'],
        'CP936'        => ['gb2312', 'gbk', 'gb_2312', 'gb_2312-80', 'chinese', 'csgb2312', 'csiso58gb231280', 'iso-ir-58', 'x-gbk'],
        'CP949'        => ['euc-kr', 'cseuckr', 'korean', 'ks_c_5601-1987', 'ks_c_5601-1989', 'ksc5601', 'ksc_5601', 'csksc56011987', 'iso-ir-149', 'windows-949'],
    ];

    /** IPv4 networks treated as private. */
    private const PRIVATE_NETS_V4 = [['0.0.0.0', 8], ['127.0.0.0', 8], ['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['169.254.0.0', 16]];

    public function __construct(
        private Transport $transport,
        private string $userAgent,
        private ?string $cacheDir = null,
        private int $cacheTtl = 3600,
        private int $maxChars = 20000,
        private int $maxBytes = 5 * 1024 * 1024,
        private bool $allowPrivate = false,
    ) {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('web_fetch', 'Fetch a URL and return its text. HTML pages are converted to readable text.', [
            'type'       => 'object',
            'properties' => [
                'url'    => ['type' => 'string', 'description' => 'The URL to fetch'],
                'offset' => ['type' => 'integer', 'description' => 'Character offset to continue from (default 0)'],
            ],
            'required' => ['url'],
        ], new Hints(readOnly: true, idempotent: true, openWorld: true));
    }

    public function call(array $input, Call $call): Result
    {
        $url    = Input::string($input, 'url');
        $offset = Input::int($input, 'offset', 0, 0);
        $this->guard($url);

        $cacheFile = null !== $this->cacheDir ? $this->cacheDir . '/' . \hash('sha256', $url) : null;
        // a continuation (offset > 0) reads the cached text however old: it continues the page it started, not a changed one
        if (null !== $cacheFile && \is_file($cacheFile) && ($offset > 0 || \filemtime($cacheFile) > \time() - $this->cacheTtl)) {
            $text = (string) \file_get_contents($cacheFile);
        } else {
            [$contentType, $charset, $body] = $this->fetch($url, $call);
            $text                           = $this->toText($contentType, $charset, $body, $url);
            if (null !== $cacheFile) {
                $this->store($cacheFile, $text, $url);
            }
        }

        return Result::text(self::tail($text, $offset, $this->maxChars));
    }

    private function guard(string $url): void
    {
        if ($this->allowPrivate) {
            return;
        }
        $parts  = \parse_url($url);
        $scheme = \strtolower(\is_array($parts) ? ($parts['scheme'] ?? '') : ''); // scheme names are case-insensitive (RFC 3986 §3.1)
        $host   = \is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!\in_array($scheme, ['http', 'https'], true) || self::isPrivateHost($host)) {
            throw new \RuntimeException("'$url' is a private-network or non-http(s) address; web_fetch refuses it as its SSRF guard (pass allowPrivate: true to the constructor to fetch it anyway)");
        }
    }

    /**
     * True for a host the guard refuses: `localhost` and `*.localhost`, an address literal in a private,
     * loopback or link-local range, a numeric host that is not a canonical literal (`2130706433`,
     * `127.1`, `0x7f000001`: the resolver accepts these forms, so they are refused outright), and a
     * name any of whose resolved addresses is private. The name is resolved here and again by the
     * transport, so a record that changes between the two (DNS rebinding) is not covered.
     */
    private static function isPrivateHost(string $host): bool
    {
        $host = \trim(\strtolower($host), '[]'); // parse_url keeps the brackets of an IPv6 literal
        if ('' === $host || 'localhost' === $host || \str_ends_with($host, '.localhost')) {
            return true;
        }
        $bin = \inet_pton($host);
        if (false !== $bin) {
            return self::isPrivateAddress($bin);
        }
        if (1 === \preg_match('/^[0-9a-fx.:]+$/', $host)) {
            return true; // numeric, but not a canonical literal
        }
        $addresses = \gethostbynamel($host) ?: [];
        foreach (\dns_get_record($host, \DNS_AAAA) ?: [] as $record) {
            $addresses[] = $record['ipv6'];
        }
        foreach ($addresses as $address) {
            $packed = \inet_pton($address);
            if (false !== $packed && self::isPrivateAddress($packed)) {
                return true;
            }
        }

        return false;
    }

    /** @param string $bin a packed address from inet_pton(), 4 or 16 bytes */
    private static function isPrivateAddress(string $bin): bool
    {
        if (16 === \strlen($bin)) {
            if ("\0\0\0\0\0\0\0\0\0\0\xff\xff" === \substr($bin, 0, 12)) {
                $bin = \substr($bin, 12); // an IPv4-mapped address is judged as its IPv4
            } else {
                return "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\x01" === $bin // ::1
                    || 0xFC === (\ord($bin[0]) & 0xFE)                                // fc00::/7
                    || (0xFE === \ord($bin[0]) && 0x80 === (\ord($bin[1]) & 0xC0));  // fe80::/10
            }
        }
        $long = (int) \unpack('N', $bin)[1];
        foreach (self::PRIVATE_NETS_V4 as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (\ip2long($net) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string, 1: string, 2: string} the lower-case content type, its charset parameter ('' when none), and the body up to maxBytes */
    private function fetch(string $url, Call $call): array
    {
        $cancel  = new Cancel($call->isCancelled(...));
        $headers = ['User-Agent' => $this->userAgent, 'Accept' => 'text/html,application/xhtml+xml,text/*,application/json'];
        for ($hops = 0;; ++$hops) {
            $this->guard($url); // every hop: a public host may redirect to a private one
            $response = $this->transport->get($url, $headers, $cancel);
            $target   = $response->redirectTarget($url);
            if (null === $target) {
                break;
            }
            if ($hops >= 5) {
                throw new \RuntimeException("'$url' redirects more than 5 times; web_fetch gives up");
            }
            foreach ($response->body() as $ignored) {
                // drained so the connection closes before the next request is made
            }
            $url = $target;
        }
        $params   = \explode(';', $response->headers['content-type'] ?? '');
        $type     = \strtolower(\trim(\array_shift($params)));
        $charset  = '';
        foreach ($params as $param) {
            [$name, $value] = \array_pad(\explode('=', $param, 2), 2, '');
            if ('charset' === \strtolower(\trim($name))) {
                $charset = \strtolower(\trim(\trim($value), '"'));
            }
        }
        $encoding = \strtolower(\trim($response->headers['content-encoding'] ?? ''));
        $inflater = match ($encoding) {
            '', 'identity'   => null,
            'gzip', 'x-gzip' => new Inflater(\ZLIB_ENCODING_GZIP, $url),
            'deflate'        => new Inflater(\ZLIB_ENCODING_DEFLATE, $url),
            default          => throw new \RuntimeException("'$url' is sent with Content-Encoding $encoding, which web_fetch cannot decode"),
        };
        $body = '';
        foreach ($response->body() as $chunk) {
            // compressed input is fed in small slices: DEFLATE expands up to ~1000:1, so one whole
            // transport chunk could decode to tens of MB before maxBytes is looked at
            foreach (null === $inflater ? [$chunk] : \str_split($chunk, 256) as $piece) {
                $body .= null === $inflater ? $piece : $inflater->add($piece);
                if (\strlen($body) >= $this->maxBytes || (null !== $inflater && $inflater->done())) {
                    break 2;
                }
            }
        }

        return [$type, $charset, \substr($body, 0, $this->maxBytes)];
    }

    /** @param string $charset the Content-Type's charset, '' when it has none */
    private function toText(string $contentType, string $charset, string $body, string $url): string
    {
        if ('' === $contentType) {
            $contentType = 'text/plain';
        }
        $html = 'text/html' === $contentType || 'application/xhtml+xml' === $contentType;
        if (!$html && 'application/json' !== $contentType && !\str_starts_with($contentType, 'text/')) {
            throw new \RuntimeException("web_fetch does not read $contentType; only text");
        }
        foreach (["\xEF\xBB\xBF" => 'utf-8', "\xFF\xFE" => 'utf-16le', "\xFE\xFF" => 'utf-16be'] as $bom => $named) {
            if (\str_starts_with($body, $bom)) { // the BOM sniff comes first, as in the WHATWG Encoding standard: it outranks a wrong header
                $charset = $named;
                $body    = \substr($body, \strlen($bom));
                break;
            }
        }
        if ('' === $charset && $html) {
            // the page's own declaration, where the HTML prescan looks for it: the first 1024 bytes, comments skipped
            $window = (string) \preg_replace('/<!--.*?(-->|$)/s', '', \substr($body, 0, 1024));
            if (1 === \preg_match('/<meta[^>]*charset\s*=\s*["\']?\s*([a-z0-9._:-]+)/i', $window, $m)) {
                $charset = \strtolower($m[1]);
            }
        }
        foreach (self::DECODERS as $decoder => $labels) {
            if (\in_array($charset, $labels, true)) {
                $charset = $decoder; // the Encoding standard's decoder for the label: pages declare Latin-1 and send cp1252's quotes and dashes
                break;
            }
        }
        if ('' !== $charset && 'utf-8' !== $charset && 'utf8' !== $charset) {
            try {
                $body = \mb_convert_encoding($body, 'UTF-8', $charset);
            } catch (\ValueError) {
                throw new \RuntimeException("'$url' is sent as charset $charset, which web_fetch cannot convert to UTF-8");
            }
        }

        return $html ? Html::extract($body) : $body;
    }

    private function store(string $cacheFile, string $text, string $url): void
    {
        for ($dir = \dirname($cacheFile); !\file_exists($dir); $dir = \dirname($dir)) {
        }
        if (!\is_dir($dir)) { // checked before mkdir(), which would warn about it
            throw new \RuntimeException("The cache directory '{$this->cacheDir}' cannot be created: '$dir' is a file, not a directory");
        }
        Fs::mkdir(\dirname($cacheFile), "the cache directory '{$this->cacheDir}'");
        Fs::write($cacheFile, $text, "the cache entry for '$url'");
    }

    private static function tail(string $text, int $offset, int $maxChars): string
    {
        $text  = Utf8::scrub($text);
        $total = Utf8::length($text);
        if ($offset > $total) {
            throw new \RuntimeException("The fetched text is $total characters; offset $offset is past the end");
        }
        $slice = Utf8::slice($text, $offset, $maxChars);
        $end   = $offset + Utf8::length($slice);
        if ($end < $total) {
            $slice .= "\n[" . ($total - $end) . ' more characters; pass offset=' . $end . ' to read on]';
        }

        return $slice;
    }
}
