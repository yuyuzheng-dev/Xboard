<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Deduplicate invite codes and enforce uniqueness.
     *
     * Keep the oldest row's owner for attribution compatibility, but preserve
     * "used" state if any duplicate was used and aggregate page views.
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_invite_code')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        switch ($driver) {
            case 'mysql':
                DB::statement("
                    UPDATE v2_invite_code c
                    INNER JOIN (
                        SELECT code, MIN(id) AS keep_id, MAX(status) AS used_status, SUM(pv) AS total_pv
                        FROM v2_invite_code
                        GROUP BY code
                        HAVING COUNT(*) > 1
                    ) d ON c.code = d.code AND c.id = d.keep_id
                    SET c.status = d.used_status, c.pv = d.total_pv
                ");
                DB::statement("
                    DELETE c FROM v2_invite_code c
                    INNER JOIN (
                        SELECT code, MIN(id) AS keep_id
                        FROM v2_invite_code
                        GROUP BY code
                    ) k ON c.code = k.code AND c.id <> k.keep_id
                ");
                break;

            case 'pgsql':
                DB::statement("
                    WITH duplicate_stats AS (
                        SELECT code, MIN(id) AS keep_id, BOOL_OR(status) AS used_status, SUM(pv) AS total_pv
                        FROM v2_invite_code
                        GROUP BY code
                        HAVING COUNT(*) > 1
                    )
                    UPDATE v2_invite_code AS c
                    SET status = duplicate_stats.used_status, pv = duplicate_stats.total_pv
                    FROM duplicate_stats
                    WHERE c.id = duplicate_stats.keep_id
                ");
                DB::statement("
                    DELETE FROM v2_invite_code AS c
                    USING (
                        SELECT code, MIN(id) AS keep_id
                        FROM v2_invite_code
                        GROUP BY code
                    ) AS k
                    WHERE c.code = k.code AND c.id <> k.keep_id
                ");
                break;

            case 'sqlsrv':
                DB::statement("
                    UPDATE c
                    SET status = CAST(stats.used_status AS BIT), pv = stats.total_pv
                    FROM v2_invite_code AS c
                    INNER JOIN (
                        SELECT code, MIN(id) AS keep_id,
                               MAX(CAST(status AS INT)) AS used_status, SUM(pv) AS total_pv
                        FROM v2_invite_code
                        GROUP BY code
                        HAVING COUNT(*) > 1
                    ) AS stats ON c.code = stats.code AND c.id = stats.keep_id
                ");
                DB::statement("
                    DELETE c FROM v2_invite_code AS c
                    INNER JOIN (
                        SELECT code, MIN(id) AS keep_id
                        FROM v2_invite_code
                        GROUP BY code
                    ) AS k ON c.code = k.code AND c.id <> k.keep_id
                ");
                break;

            default:
                // SQLite-compatible SQL; also works for the default SQLite test database.
                DB::statement("
                    UPDATE v2_invite_code
                    SET status = (
                        SELECT MAX(CAST(d.status AS INTEGER))
                        FROM v2_invite_code AS d
                        WHERE d.code = v2_invite_code.code
                    ),
                    pv = (
                        SELECT SUM(d.pv)
                        FROM v2_invite_code AS d
                        WHERE d.code = v2_invite_code.code
                    )
                    WHERE id IN (
                        SELECT MIN(id)
                        FROM v2_invite_code
                        GROUP BY code
                        HAVING COUNT(*) > 1
                    )
                ");
                DB::statement("
                    DELETE FROM v2_invite_code
                    WHERE id NOT IN (
                        SELECT keep_id FROM (
                            SELECT MIN(id) AS keep_id
                            FROM v2_invite_code
                            GROUP BY code
                        ) AS keepers
                    )
                ");
                break;
        }

        if (!Schema::hasIndex('v2_invite_code', 'uk_invite_code', 'unique')) {
            Schema::table('v2_invite_code', function (Blueprint $table) {
                $table->unique('code', 'uk_invite_code');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_invite_code')) {
            return;
        }

        if (Schema::hasIndex('v2_invite_code', 'uk_invite_code', 'unique')) {
            Schema::table('v2_invite_code', function (Blueprint $table) {
                $table->dropUnique('uk_invite_code');
            });
        }

        // Duplicate rows removed by up() cannot be reconstructed by rollback.
    }
};
