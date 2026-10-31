<?php

declare(strict_types=1);

namespace Harness\Policy;

/**
 * One program rule of `Rule::shell()`, matched against a simple command's parsed argv, never the
 * command string. `allow()` matches the program by its exact name (`ls`, not `/tmp/x/ls`), its first
 * argument against `$subcommands` when given, and every option (`-x`, `--name[=value]`, before
 * `--`) against `$flags` when given; other arguments are free. `deny()` matches the program by its
 * base name (`rm` and `/bin/rm`) whatever its arguments.
 *
 * `check()` parses a command line with a small POSIX-shell tokenizer (words, quotes, escapes,
 * `&&`, `||`, `;`, `|`, `&`, redirects, comments) into simple commands, peels the wrappers `env`,
 * `nice`, `timeout`, `sudo`, `nohup` and `xargs`, and decides: `Deny` when a command matches a deny
 * rule; `Allow` when every command matches an allow rule; otherwise `Ask`, and `Ask` too for what
 * it does not parse (`$` expansions, substitutions, backticks, here-documents, subshells, brace
 * expansion), for an environment assignment, and for output redirected to a file.
 */
final readonly class Shell
{
    private const WRAPPERS = ['env', 'nice', 'timeout', 'sudo', 'nohup', 'xargs'];

    /**
     * @param list<string>|null $subcommands
     * @param list<string>|null $flags
     */
    private function __construct(
        public Verdict $verdict,
        public string $program,
        public ?array $subcommands,
        public ?array $flags,
    ) {
        if ('' === $program || \str_contains($program, '/')) {
            throw new \InvalidArgumentException("A shell rule names a program by its name, without a path, got '$program'");
        }
        foreach ([...$subcommands ?? [], ...$flags ?? []] as $word) {
            if (!\is_string($word) || '' === $word) {
                throw new \InvalidArgumentException("The subcommands and flags of the shell rule for '$program' are non-empty strings");
            }
        }
        foreach ($flags ?? [] as $flag) {
            if ('-' !== $flag[0]) {
                throw new \InvalidArgumentException("The shell rule for '$program' lists the flag '$flag', which does not start with -");
            }
        }
    }

    /**
     * Allows `$program` (exact name), limited to the first argument in `$subcommands` and to the
     * options in `$flags` when they are given.
     *
     * @param list<string>|null $subcommands null: any arguments
     * @param list<string>|null $flags       null: any options
     */
    public static function allow(string $program, ?array $subcommands = null, ?array $flags = null): self
    {
        return new self(Verdict::Allow, $program, null === $subcommands ? null : \array_values($subcommands), null === $flags ? null : \array_values($flags));
    }

    /** Denies `$program` (by base name), whatever its arguments. */
    public static function deny(string $program): self
    {
        return new self(Verdict::Deny, $program, null, null);
    }

    /**
     * True when the rule applies to a simple command; `$globbed[$i]` says argument `$i` holds an
     * unquoted glob, whose expansion could be any option, so it never matches an allow rule with flags.
     *
     * @param list<string> $argv
     * @param list<bool>   $globbed
     */
    public function matches(array $argv, array $globbed): bool
    {
        if (Verdict::Deny === $this->verdict) {
            return \basename($argv[0]) === $this->program;
        }
        if ($argv[0] !== $this->program || $globbed[0]) {
            return false;
        }
        $first = 1;
        if (null !== $this->subcommands) {
            if (!isset($argv[1]) || $globbed[1] || !\in_array($argv[1], $this->subcommands, true)) {
                return false;
            }
            $first = 2;
        }
        if (null === $this->flags) {
            return true;
        }
        for ($i = $first; $i < \count($argv); ++$i) {
            if ('--' === $argv[$i]) {
                break;
            }
            if ($globbed[$i] || ('-' === ($argv[$i][0] ?? '') && '-' !== $argv[$i] && !\in_array(\explode('=', $argv[$i], 2)[0], $this->flags, true))) {
                return false;
            }
        }

        return true;
    }

    /** The rule as text: `git status|diff|log (flags --stat, -p)`. */
    public function describe(): string
    {
        return $this->program
            . (null === $this->subcommands ? '' : ' ' . \implode('|', $this->subcommands))
            . (null === $this->flags ? '' : ' (' . ([] === $this->flags ? 'no flags' : 'flags ' . \implode(', ', $this->flags)) . ')');
    }

    /**
     * Decides a command line by the rules, first match per simple command: the verdict and the
     * reason, which names the command that decided it. A command no rule matches gets `$otherwise`
     * (`Ask` or `Deny`); a deny wins over an ask anywhere in the line.
     *
     * @param list<self> $rules
     *
     * @return array{0: Verdict, 1: string}
     */
    public static function check(string $line, array $rules, Verdict $otherwise = Verdict::Ask): array
    {
        $commands = self::parse($line);
        if (\is_string($commands)) {
            return [Verdict::Ask, "the shell command cannot be checked: $commands"];
        }
        if ([] === $commands) {
            return [Verdict::Ask, 'the shell command is empty'];
        }
        $ask  = null;
        $deny = null;
        foreach ($commands as [$argv, $globbed, $writes]) {
            $peeled = self::peel($argv, $globbed);
            if (\is_string($peeled)) {
                $ask ??= $peeled;
                continue;
            }
            [$argv, $globbed] = $peeled;
            $rule             = null;
            foreach ($rules as $r) {
                if ($r->matches($argv, $globbed)) {
                    $rule = $r;
                    break;
                }
            }
            if (Verdict::Deny === $rule?->verdict) {
                return [Verdict::Deny, "the shell command '{$argv[0]}' is never run"];
            }
            if (null === $rule) {
                $why = "the shell command '" . \implode(' ', \array_slice($argv, 0, 2)) . "' matches no allow rule";
                if (Verdict::Deny === $otherwise) {
                    $deny ??= $why;
                }
                $ask ??= $why;
            } elseif ([] !== $writes) {
                $ask ??= "the shell command '{$argv[0]}' writes to '" . \implode("', '", $writes) . "'";
            }
        }

        return match (true) {
            null !== $deny => [Verdict::Deny, $deny],
            null !== $ask  => [Verdict::Ask, $ask],
            default        => [Verdict::Allow, 'the shell command is allowed'],
        };
    }

    /**
     * The wrapped command of `env`, `nice`, `timeout`, `sudo`, `nohup` and `xargs`, peeled until
     * none is left, or why it cannot be.
     *
     * @param list<string> $argv
     * @param list<bool>   $globbed
     *
     * @return array{0: list<string>, 1: list<bool>}|string
     */
    private static function peel(array $argv, array $globbed): array|string
    {
        while (true) {
            if (1 === \preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $argv[0])) {
                return "the environment assignment '{$argv[0]}' can change what runs";
            }
            $wrapper = \basename($argv[0]);
            if ($globbed[0] || !\in_array($wrapper, self::WRAPPERS, true)) {
                return [$argv, $globbed];
            }
            // Options without an argument, options with one (as the next word), and what is not peeled.
            [$plain, $valued, $refused] = match ($wrapper) {
                'env'     => [['-i', '--ignore-environment', '-0', '--null', '-v', '--debug', '-'], ['-u', '--unset', '-C', '--chdir'], ['-S', '--split-string']],
                'nice'    => [[], ['-n', '--adjustment'], []],
                'timeout' => [['--preserve-status', '--foreground', '-v', '--verbose'], ['-s', '--signal', '-k', '--kill-after'], []],
                'sudo'    => [['-E', '-H', '-n', '-S', '-b', '-k', '-P', '-A', '--preserve-env', '--set-home', '--non-interactive', '--stdin', '--background', '--askpass'], ['-u', '--user', '-g', '--group', '-C', '-h', '--host', '-p', '--prompt', '-r', '-t', '-U', '-D', '--chdir', '-R', '--chroot', '-T', '--command-timeout'], ['-i', '--login', '-s', '--shell', '-e', '--edit', '-l', '--list', '-v', '--validate', '-K']],
                'nohup'   => [[], [], []],
                'xargs'   => [['-0', '--null', '-r', '--no-run-if-empty', '-t', '--verbose', '-p', '--interactive', '-x', '--exit', '-o', '--open-tty'], ['-n', '--max-args', '-I', '--replace', '-L', '--max-lines', '-P', '--max-procs', '-d', '--delimiter', '-E', '--eof', '-s', '--max-chars', '-a', '--arg-file'], []],
            };
            $i = 1;
            while (isset($argv[$i]) && '-' === $argv[$i][0] && '-' !== $argv[$i]) {
                $option = $argv[$i];
                if ('--' === $option) {
                    ++$i;
                    break;
                }
                $name = \explode('=', $option, 2)[0];
                if (\in_array($name, $refused, true)) {
                    return "'$wrapper $name' runs something the rules cannot see";
                }
                if (\in_array($option, $plain, true)) {
                    ++$i;
                } elseif (\in_array($option, $valued, true)) {
                    $i += 2;
                } elseif (\str_contains($option, '=') && \in_array($name, $valued, true)) {
                    ++$i;
                } elseif ('nice' === $wrapper && 1 === \preg_match('/^-(n)?\d+$/', $option)) {
                    ++$i;
                } elseif (2 < \strlen($option) && '-' !== $option[1] && \in_array(\substr($option, 0, 2), $valued, true)) {
                    ++$i;   // -uroot, -n5: the value attached
                } else {
                    return "the option '$option' of '$wrapper' is not known to the rules";
                }
            }
            if ('-' === ($argv[$i] ?? null) && 'env' === $wrapper) {
                ++$i;
            }
            if ('timeout' === $wrapper) {
                ++$i;       // the duration
            }
            if (!isset($argv[$i])) {
                if ('xargs' !== $wrapper) {
                    return [$argv, $globbed];    // nothing wrapped: the program itself is checked
                }
                [$argv, $globbed] = [['echo'], [false]];    // xargs runs echo by default
                continue;
            }
            [$argv, $globbed] = [\array_slice($argv, $i), \array_slice($globbed, $i)];
        }
    }

    /**
     * The simple commands of a command line, each `[argv, globbed, writes]` (the words, whether
     * each holds an unquoted glob, and the files output is redirected to), or what it cannot parse.
     *
     * @return list<array{0: list<string>, 1: list<bool>, 2: list<string>}>|string
     */
    private static function parse(string $line): array|string
    {
        $commands = [];
        $argv     = [];
        $globbed  = [];
        $writes   = [];
        $word     = null;       // the word being read, null between words
        $glob     = false;
        $quoted   = false;
        $redirect = null;       // 'in', 'out' or 'dup': the next word is its target
        $end      = function () use (&$word, &$glob, &$quoted, &$redirect, &$argv, &$globbed, &$writes): void {
            if (null === $word) {
                return;
            }
            if (null === $redirect) {
                $argv[]    = $word;
                $globbed[] = $glob;
            } elseif ('out' === $redirect || ('dup' === $redirect && !\ctype_digit($word) && '-' !== $word)) {
                if ('/dev/null' !== $word) {
                    $writes[] = $word;
                }
            }
            [$word, $glob, $quoted, $redirect] = [null, false, false, null];
        };
        $dangling = false;      // after |, && or ||: a command must follow
        $close    = function (bool $required) use (&$commands, &$argv, &$globbed, &$writes, &$redirect, &$dangling): ?string {
            if (null !== $redirect) {
                return 'a redirect without a target';
            }
            if ([] === $argv) {
                return [] !== $writes ? 'a redirect without a command' : ($required || $dangling ? 'an operator without a command on each side' : null);
            }
            $commands[]                = [$argv, $globbed, $writes];
            [$argv, $globbed, $writes] = [[], [], []];

            return null;
        };
        $n = \strlen($line);
        for ($i = 0; $i < $n; ++$i) {
            $c = $line[$i];
            if (' ' === $c || "\t" === $c) {
                $end();
                continue;
            }
            if ("\n" === $c || ';' === $c || '&' === $c || '|' === $c) {
                if ('&' === $c && '>' === ($line[$i + 1] ?? '')) {
                    $end();
                    $i += '>' === ($line[$i + 2] ?? '') ? 2 : 1;
                    $redirect = 'out';
                    continue;
                }
                if (';' === $c && ';' === ($line[$i + 1] ?? '')) {
                    return "';;' (a case statement)";
                }
                $end();
                $double = ('&' === $c || '|' === $c) && $c === ($line[$i + 1] ?? '');
                if ($double || ('|' === $c && '&' === ($line[$i + 1] ?? ''))) {
                    ++$i;
                }
                if (null !== ($why = $close('|' === $c || $double))) {
                    return $why;
                }
                $dangling = '|' === $c || $double;
                continue;
            }
            if ('<' === $c || '>' === $c) {
                if (null !== $word && !$quoted && \ctype_digit($word)) {
                    $word = null;   // a file descriptor: 2>
                }
                $end();
                $next = $line[$i + 1] ?? '';
                if ('(' === $next) {
                    return "process substitution '$c('";
                }
                if ('<' === $c && '<' === $next) {
                    return 'a here-document or here-string';
                }
                if ('&' === $next) {
                    ++$i;
                    $redirect = 'dup';
                } elseif ('<' === $c) {
                    $redirect = 'in';
                    if ('>' === $next) {
                        ++$i;
                        $redirect = 'out';
                    }
                } else {
                    $redirect = 'out';
                    if ('>' === $next || '|' === $next) {
                        ++$i;
                    }
                }
                continue;
            }
            if ('(' === $c || ')' === $c) {
                return 'a subshell or grouping with parentheses';
            }
            if ('{' === $c || '}' === $c) {
                return 'braces (brace expansion or grouping)';
            }
            if ('`' === $c) {
                return 'command substitution with backticks';
            }
            if ('$' === $c) {
                return 'an expansion with $ (a variable, $(...) or $((...)))';
            }
            if ('#' === $c && null === $word) {
                while ($i + 1 < $n && "\n" !== $line[$i + 1]) {
                    ++$i;
                }
                continue;
            }
            if ("'" === $c) {
                $quote = \strpos($line, "'", $i + 1);
                if (false === $quote) {
                    return 'an unterminated single quote';
                }
                $word   = ($word ?? '') . \substr($line, $i + 1, $quote - $i - 1);
                $quoted = true;
                $i      = $quote;
                continue;
            }
            if ('"' === $c) {
                $word ??= '';
                $quoted = true;
                for (++$i;; ++$i) {
                    if ($i >= $n) {
                        return 'an unterminated double quote';
                    }
                    $d = $line[$i];
                    if ('"' === $d) {
                        break;
                    }
                    if ('$' === $d || '`' === $d) {
                        return 'an expansion inside double quotes';
                    }
                    if ('\\' === $d && $i + 1 < $n && \str_contains("\$`\"\\\n", $line[$i + 1])) {
                        $word .= "\n" === $line[++$i] ? '' : $line[$i];
                        continue;
                    }
                    $word .= $d;
                }
                continue;
            }
            if ('\\' === $c) {
                if ($i + 1 >= $n) {
                    return 'a backslash at the end';
                }
                if ("\n" !== $line[++$i]) {
                    $word   = ($word ?? '') . $line[$i];
                    $quoted = true;
                }
                continue;
            }
            if ('*' === $c || '?' === $c || '[' === $c) {
                $glob = true;
            }
            $word = ($word ?? '') . $c;
        }
        $end();
        if (null !== ($why = $close(false))) {
            return $why;
        }

        return $commands;
    }
}
