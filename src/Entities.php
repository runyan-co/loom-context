<?php

declare(strict_types=1);

namespace LoomContext;

use InvalidArgumentException;

/**
 * Lifts out of the narration the identifiers an engineer would otherwise have to hunt for. Only kinds every
 * project has are built in; identifiers that mean something in one project (account numbers, tenant names, a
 * narrower ticket prefix) come from an optional JSON config:
 *
 *     {"entities": [{"kind": "account", "regex": "\\bACCT\\d{6}\\b"},
 *                   {"kind": "tenant", "regex": "\\bworkspace\\s+([a-z0-9-]{3,})", "flags": "i", "lower": true}]}
 *
 * The first capture group is the value when the regex has one, and a config kind that matches a built-in
 * replaces it.
 */
class Entities
{
    public const ConfigEnv = 'LOOM_CONTEXT_CONFIG';

    public const ProjectConfig = '.loom-context.json';

    public const UserConfig = '.config/loom-context/config.json';

    // Dashed tokens that read like PROJ-123 ticket keys but are not.
    private const NotTickets = 'UTF|SHA|MD|ISO|RFC|HTTP|TLS|SSL|AES|RSA|GPT|COVID|IPV|CVE';

    /**
     * @return list<EntityPattern>
     */
    public static function builtIn(): array
    {
        return [
            new EntityPattern('ticket', sprintf('~\b(?!(?:%s)-)[A-Z][A-Z0-9]{1,9}-\d{1,6}\b~u', self::NotTickets)),
            new EntityPattern('url', '~https?://[^\s)>\]]*[^\s)>\].,;:!?]~u'),
            new EntityPattern('money', '~[$€£]\d(?:\d|,\d{3})*(?:\.\d{2})?~u'),
            new EntityPattern(
                'http_status',
                '~\b([45]\d\d)\s+(?:error|errors|status|page|internal server error|not found)\b~iu',
            ),
            new EntityPattern('http_status', '~\berror\s+([45]\d\d)\b~iu'),
            new EntityPattern('email', '~\b[\w.+-]+@[\w-]+\.[\w.-]+\b~u'),
        ];
    }

    /**
     * The config that applies here: the one named in the environment, then the project's, then the user's.
     */
    public static function findConfig(): ?string
    {
        $candidates = [
            getenv(self::ConfigEnv) ?: null,
            sprintf('%s/%s', getcwd(), self::ProjectConfig),
            sprintf('%s/%s', getenv('HOME') ?: '', self::UserConfig),
        ];

        foreach (array_filter($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A config that cannot be used is reported and ignored; it never fails a run.
     *
     * @return list<EntityPattern>
     */
    public static function patterns(?string $config): array
    {
        if ($config === null) {
            return self::builtIn();
        }

        try {
            $custom = self::fromConfig($config);
        } catch (InvalidArgumentException $exception) {
            Log::line("ignoring {$config}: {$exception->getMessage()}");

            return self::builtIn();
        }

        $replaced = array_map(fn (EntityPattern $pattern) => $pattern->kind, $custom);

        $kept = array_filter(
            self::builtIn(),
            fn (EntityPattern $pattern) => ! in_array($pattern->kind, $replaced, true),
        );

        return [...$kept, ...$custom];
    }

    /**
     * Each kind/value pair once, at the moment it is first said.
     *
     * @param  list<Phrase>  $phrases
     * @param  list<EntityPattern>  $patterns
     * @return list<Entity>
     */
    public static function spot(array $phrases, array $patterns): array
    {
        $found = [];

        foreach ($phrases as $phrase) {
            foreach ($patterns as $pattern) {
                preg_match_all($pattern->regex, $phrase->text, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $value = $match[1] ?? $match[0];

                    $value = $pattern->lower ? strtolower($value) : $value;

                    $found["{$pattern->kind}\0{$value}"] ??= new Entity($pattern->kind, $value, $phrase->seconds);
                }
            }
        }

        return array_values($found);
    }

    /**
     * @return list<EntityPattern>
     */
    private static function fromConfig(string $config): array
    {
        $entities = json_decode((string) file_get_contents($config), true)['entities'] ?? null;

        if (! is_array($entities)) {
            throw new InvalidArgumentException('expected a JSON object with an "entities" list');
        }

        return array_map(self::fromConfigItem(...), array_values($entities));
    }

    private static function fromConfigItem(mixed $item): EntityPattern
    {
        if (! is_string($item['kind'] ?? null) || ! is_string($item['regex'] ?? null)) {
            throw new InvalidArgumentException('every entity needs a "kind" and a "regex"');
        }

        $modifiers = str_contains((string) ($item['flags'] ?? ''), 'i') ? 'iu' : 'u';

        $regex = sprintf('~%s~%s', str_replace('~', '\~', $item['regex']), $modifiers);

        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException("the regex for \"{$item['kind']}\" does not compile");
        }

        return new EntityPattern($item['kind'], $regex, (bool) ($item['lower'] ?? false));
    }
}
