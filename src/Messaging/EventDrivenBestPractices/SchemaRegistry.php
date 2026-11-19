<?php

declare(strict_types=1);

namespace Patterns\Messaging\EventDrivenBestPractices;

use InvalidArgumentException;

/**
 * Stores every version of every event schema and refuses changes that
 * would break someone.
 *
 * Producers and consumers are deployed independently, so at any moment
 *  - OLD consumers read NEW events  (forward compatibility), and
 *  - NEW consumers read OLD events  (backward compatibility: replays, backlogs).
 *
 * This registry enforces both ("full" compatibility). What survives is:
 * you may add optional fields, and you may drop optional fields. Nothing else.
 *
 * A schema here is: field name => ['type' => string, 'required' => bool].
 */
final class SchemaRegistry
{
    /** @var array<string, list<array<string, array{type: string, required: bool}>>> event type => versions */
    private array $schemas = [];

    /**
     * @param  array<string, array{type: string, required: bool}>  $fields
     * @return int the version number assigned
     *
     * @throws InvalidArgumentException when the change is incompatible with the previous version
     */
    public function register(string $eventType, array $fields): int
    {
        $problems = $this->incompatibilities($eventType, $fields);

        if ($problems !== []) {
            throw new InvalidArgumentException("Incompatible schema for [{$eventType}]: ".implode(' ', $problems));
        }

        $this->schemas[$eventType][] = $fields;

        return count($this->schemas[$eventType]);
    }

    /**
     * Why the proposed schema can't replace the current one. Empty = safe.
     *
     * @param  array<string, array{type: string, required: bool}>  $proposed
     * @return list<string>
     */
    public function incompatibilities(string $eventType, array $proposed): array
    {
        $versions = $this->schemas[$eventType] ?? [];

        if ($versions === []) {
            return []; // first version: nothing to break
        }

        $current = $versions[count($versions) - 1];
        $problems = [];

        foreach ($current as $name => $field) {
            if (! isset($proposed[$name])) {
                if ($field['required']) {
                    $problems[] = "Field [{$name}] was removed, but existing consumers require it.";
                }

                continue;
            }

            if ($proposed[$name]['type'] !== $field['type']) {
                $problems[] = "Field [{$name}] changed type from {$field['type']} to {$proposed[$name]['type']}.";
            }

            if ($proposed[$name]['required'] !== $field['required']) {
                $problems[] = $proposed[$name]['required']
                    ? "Field [{$name}] became required, but older events may not have it."
                    : "Field [{$name}] became optional, but existing consumers rely on it being present.";
            }
        }

        foreach (array_diff_key($proposed, $current) as $name => $field) {
            if ($field['required']) {
                $problems[] = "New field [{$name}] is required, but older events do not have it.";
            }
        }

        return $problems;
    }

    /**
     * Check a payload against a registered schema version.
     *
     * Unknown extra fields are NOT an error: consumers are tolerant readers.
     *
     * @return list<string> validation errors
     */
    public function validate(string $eventType, int $version, array $data): array
    {
        $schema = $this->schemas[$eventType][$version - 1]
            ?? throw new InvalidArgumentException("No schema registered for [{$eventType}] v{$version}.");

        $errors = [];

        foreach ($schema as $name => $field) {
            if (! array_key_exists($name, $data)) {
                if ($field['required']) {
                    $errors[] = "Missing required field [{$name}].";
                }

                continue;
            }

            if (get_debug_type($data[$name]) !== $field['type']) {
                $errors[] = "Field [{$name}] must be {$field['type']}, got ".get_debug_type($data[$name]).'.';
            }
        }

        return $errors;
    }

    public function latestVersion(string $eventType): int
    {
        return count($this->schemas[$eventType] ?? []);
    }
}
