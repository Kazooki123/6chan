<?php
/**
 * Shared Postgres-via-PDO type-safety helpers.
 *
 * PDOs default (emulated prepares - the default unless explicitly
 * disabled, which config/db.php does not do) returns Postgres booleans
 * as the literal strings 't'/'f', not native PHP true/false. Both of
 * those are non-empty strings, so a plain !empty()/(bool) cast on them
 * is ALWAYS true regardless of the actual stored value — a real footgun
 * for any boolean column read back from the database.
 *
 */
function pg_bool(mixed $val): bool {
    if (is_bool($val)) return $val;
    if (is_int($val))  return $val !== 0;
    if (is_string($val)) return in_array(strtolower($val), ['t', 'true', '1', 'yes'], true);
    return (bool)$val;
}
