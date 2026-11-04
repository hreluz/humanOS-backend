<?php

namespace App\Modules\Nutrition\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Removes catalog rows that are no longer in the data (SPEC 15, "Removing entries").
 *
 * Each row is hard-deleted inside a savepoint. If a foreign key declared `ON DELETE RESTRICT` blocks it
 * (directly, or through the cascade to the row's children), the savepoint is rolled back and the row is
 * soft-deleted instead. Nothing lists the referencing tables: the database's foreign keys decide.
 */
class CatalogRemover
{
    /**
     * Removes every row the query matches, soft-deleted rows included, and returns how many were hard-deleted.
     *
     * A row that is already soft-deleted is tried again, so it goes once nothing references it any more.
     *
     * @param  Builder<covariant Model>  $query  A query on a model that uses `SoftDeletes`.
     */
    public static function remove(Builder $query): int
    {
        $model = $query->getModel();
        $deletedAt = $model->getDeletedAtColumn();
        $hardDeleted = 0;

        foreach ($query->withTrashed()->get([$model->getKeyName(), $deletedAt]) as $row) {
            $base = fn () => $model->newQueryWithoutScopes()->whereKey($row->getKey())->toBase();

            try {
                // A nested transaction is a savepoint, so a failed delete doesn't abort the outer one on PostgreSQL.
                DB::transaction(fn () => $base()->delete());
                $hardDeleted++;
            } catch (QueryException $e) {
                if (! self::isForeignKeyViolation($e)) {
                    throw $e;
                }

                if ($row->{$deletedAt} === null) {
                    $base()->update([$deletedAt => $model->freshTimestampString()]);
                }
            }
        }

        return $hardDeleted;
    }

    /**
     * Whether the delete failed on a foreign key: SQLSTATE 23000 on MySQL, 23503 on PostgreSQL.
     */
    private static function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array($e->errorInfo[0] ?? null, ['23000', '23503'], true);
    }
}
