<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

use StoneScriptPHP\Database;

/**
 * The write boundary: call a mutating SQL function and ASSERT it persisted.
 *
 * `Database::fn()` is the generic call primitive; zero rows is a legitimate result for a
 * SELECT, so it cannot decide that a write failed. {@see self::run()} (exposed as
 * `Database::mutate()`) is for WRITES and turns every failure signal the SQL functions
 * use into one typed {@see PersistenceException}, so no handler can forget the check and
 * answer 2xx for a write that changed nothing:
 *
 *   0. a business error the function marks PUBLIC (`public_message`, optional `error_code`, `status` 4xx,
 *      `fields`, `errors`)                                             -> that status/message, shown as-is
 *   1. an `{error: "..."}` envelope (a swallowed database error)      -> 500, generic message (text kept in getPrevious())
 *   2. a `{success: false, message}` envelope without public_message  -> 400, generic message (text kept in getPrevious())
 *   3. no row where a row was expected                                 -> 404 (notFoundMessage) or 500
 *   4. a row count different from `exactRows`                          -> 500
 *   5. the database refusing the statement (constraint, deadlock ...)  -> classified by {@see DbErrorMapper}
 *
 * Raw function/database text is NEVER the exception message and is never shown to a client.
 *
 * Envelopes are recognised as direct columns, as a single function-named column holding
 * JSON text/array, and with the framework's `o_` output-column prefix (`o_success`, `o_error`).
 * A normal RETURNING row is returned untouched.
 */
final class Mutation
{
    /**
     * @param array<int|string, mixed> $params     Positional or p_-keyed params, as Database::fn().
     * @param bool        $allowEmpty      TRUE only for a genuinely idempotent no-op (releasing zero
     *                                     reservations is success). An error/failure envelope STILL throws.
     * @param string|null $notFoundMessage When set, an empty result is a 404 with this message
     *                                     (update/delete by id: the row was not there).
     * @param int|null    $exactRows       Require exactly this many rows back (e.g. a bulk update that
     *                                     must touch N rows). Overrides the "at least one" default.
     * @param string|null $noun            Used by database-error messages ("A <noun> with these details already exists.").
     * @return array<int, array<string, mixed>> The raw rows, on success.
     * @throws PersistenceException When the write did not persist.
     */
    public static function run(
        string $function,
        array $params,
        bool $allowEmpty = false,
        ?string $notFoundMessage = null,
        ?int $exactRows = null,
        ?string $noun = null,
    ): array {
        try {
            $rows = Database::fn($function, $params);
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Connection loss keeps its dedicated typed exception (the router maps it to 503/401);
            // everything else becomes a typed, classified persistence failure.
            if ($e instanceof \StoneScriptPHP\TenantDatabaseUnavailableException) {
                throw $e;
            }
            $mapped = DbErrorMapper::resolve($e, $noun);
            throw self::fail(new PersistenceException(
                $mapped->message,
                $mapped->status,
                $e,
                $mapped->public ? PersistenceException::BUSINESS_RULE : PersistenceException::DATABASE_ERROR,
                $function,
                $mapped->errorCode,
                $mapped->public,
                $mapped->data,
                $mapped->errors,
                $mapped->explicitStatus
            ));
        }

        $envelope = self::statusEnvelope($rows);
        if ($envelope !== null) {
            // 0. explicit PUBLIC business error
            if (isset($envelope['public_message']) && is_string($envelope['public_message']) && trim($envelope['public_message']) !== '') {
                $explicit = isset($envelope['status']) && is_numeric($envelope['status']) && (int) $envelope['status'] >= 400 && (int) $envelope['status'] < 500;
                $status = $explicit ? (int) $envelope['status'] : 400;
                $code = isset($envelope['error_code']) && is_string($envelope['error_code']) && $envelope['error_code'] !== '' ? $envelope['error_code'] : null;
                $data = isset($envelope['fields']) && is_array($envelope['fields']) ? ['fields' => $envelope['fields']] : null;
                $errors = isset($envelope['errors']) && is_array($envelope['errors']) ? array_values($envelope['errors']) : null;
                throw self::fail(new PersistenceException(
                    trim($envelope['public_message']), $status, null, PersistenceException::BUSINESS_RULE, $function, $code, true,
                    $code !== null ? (['error_code' => $code] + ($data ?? [])) : $data, $errors, $explicit
                ));
            }
            // 1. swallowed DB error: the text is NOT public
            if (self::present($envelope, 'error')) {
                throw self::fail(new PersistenceException(
                    PersistenceException::DEFAULT_MESSAGE, 500,
                    new \RuntimeException(is_string($envelope['error']) ? $envelope['error'] : 'error envelope'),
                    PersistenceException::ENVELOPE_ERROR, $function
                ));
            }
            // 2. business no-op without a public message: generic, the function's text stays in the cause
            if (array_key_exists('success', $envelope) && $envelope['success'] === false) {
                $raw = isset($envelope['message']) && is_string($envelope['message']) ? $envelope['message'] : 'success=false';
                throw self::fail(new PersistenceException(
                    PersistenceException::DEFAULT_MESSAGE, 400, new \RuntimeException($raw),
                    PersistenceException::ENVELOPE_FAILURE, $function
                ));
            }
        }

        $count = count($rows);
        if ($exactRows !== null) {
            if ($count !== $exactRows) {
                if ($count === 0 && $notFoundMessage !== null) {
                    throw self::fail(new PersistenceException($notFoundMessage, 404, null, PersistenceException::NOT_FOUND, $function));
                }
                throw self::fail(new PersistenceException(
                    PersistenceException::DEFAULT_MESSAGE,
                    500,
                    null,
                    $count === 0 ? PersistenceException::EMPTY_RESULT : PersistenceException::ROW_COUNT,
                    $function
                ));
            }
            return $rows;
        }

        if ($count === 0) {
            if ($allowEmpty) {
                return [];
            }
            if ($notFoundMessage !== null) {
                throw self::fail(new PersistenceException($notFoundMessage, 404, null, PersistenceException::NOT_FOUND, $function));
            }
            throw self::fail(new PersistenceException(PersistenceException::DEFAULT_MESSAGE, 500, null, PersistenceException::EMPTY_RESULT, $function));
        }

        return $rows;
    }

    /**
     * Map the rows of a SUCCESSFUL write onto the model. If strict hydration refuses the row, the write has
     * ALREADY COMMITTED: a bare 500 would make a client retry and double-apply it. So the failure becomes a
     * 500 PersistenceException carrying `persisted: true` and a correlation id (the sanitised detail is logged
     * under that id), and a message telling the user not to repeat the action.
     *
     * @template T
     * @param callable(): T $hydrate
     * @return T
     * @throws PersistenceException
     */
    public static function hydrateCommitted(string $function, callable $hydrate): mixed
    {
        try {
            return $hydrate();
        } catch (\StoneScriptPHP\Database\HydrationException $e) {
            $correlationId = bin2hex(random_bytes(6));
            $ex = new PersistenceException(
                'Your change was saved, but the result could not be displayed. Please do not repeat the action; reload to see the current state.',
                500,
                $e,
                PersistenceException::PERSISTED_UNREADABLE,
                $function,
                'persisted_unreadable',
                true,
                ['persisted' => true, 'correlation_id' => $correlationId, 'error_code' => 'persisted_unreadable'],
                null,
                true
            );
            PersistenceLedger::record($ex);
            try {
                log_error('Persistence: ' . $function . ' COMMITTED but the row could not be hydrated (persisted=true, correlation_id=' . $correlationId . '): ' . LogSanitizer::describe($e));
            } catch (\Throwable) {
            }
            throw $ex;
        }
    }

    /** The (sanitised) line written to the log for a failed write. */
    public static function failureLine(PersistenceException $e): string
    {
        return sprintf(
            'Persistence: %s failed (%s, HTTP %d): %s%s',
            $e->function() ?? 'write',
            $e->reason(),
            $e->httpStatusCode(),
            $e->getMessage(),
            $e->getPrevious() !== null ? ' | cause: ' . LogSanitizer::describe($e->getPrevious()) : ''
        );
    }

    /** Record in the ledger (so a swallowed failure is detectable) and log the full detail. */
    private static function fail(PersistenceException $e): PersistenceException
    {
        PersistenceLedger::record($e);
        try {
            log_error(self::failureLine($e));
        } catch (\Throwable) {
        }
        return $e;
    }

    /** @param array<string, mixed> $envelope */
    private static function present(array $envelope, string $key): bool
    {
        return array_key_exists($key, $envelope)
            && $envelope[$key] !== null && $envelope[$key] !== false && $envelope[$key] !== '';
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<string, mixed>|null  Keys normalised: `o_success` -> `success`, `o_error` -> `error`, `o_message` -> `message`.
     */
    private static function statusEnvelope(array $rows): ?array
    {
        $row = $rows[0] ?? null;
        if (is_object($row)) {
            $row = (array) $row;
        }
        if (!is_array($row)) {
            return null;
        }

        // A single column holding the JSON(B) envelope (function-named column).
        if (count($row) === 1) {
            $only = reset($row);
            if (is_string($only)) {
                $decoded = json_decode($only, true);
                if (is_array($decoded)) {
                    $row = $decoded;
                }
            } elseif (is_array($only)) {
                $row = $only;
            }
        }

        $norm = [];
        foreach ($row as $k => $v) {
            $key = (string) $k;
            if (in_array($key, ['o_success', 'o_error', 'o_message', 'o_error_code', 'o_public_message', 'o_status', 'o_fields', 'o_errors'], true)) {
                $key = substr($key, 2);
            }
            $norm[$key] = $v;
        }

        return (array_key_exists('success', $norm) || array_key_exists('error', $norm) || array_key_exists('public_message', $norm)) ? $norm : null;
    }
}
