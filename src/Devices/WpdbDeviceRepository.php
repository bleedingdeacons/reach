<?php

declare(strict_types=1);

namespace Reach\Devices;

if (!defined('ABSPATH')) {
    exit;
}

use LogicException;
use Reach\Core\Cipher;
use RuntimeException;
use wpdb;

use function dbDelta;

/**
 * $wpdb-backed implementation of {@see DeviceRepository}.
 *
 * Schema is created via dbDelta on plugin activation (see install()).
 *
 * The table holds no personal data beyond the responder's own email —
 * which is the identity the token was minted for and the key the
 * eligibility gate is re-checked against — plus a device label the
 * handset chooses for itself. No caller data ever reaches this table;
 * that stays out of the database entirely, exactly as it does for call
 * requests.
 *
 * Indexes follow the three questions actually asked of it: authenticate
 * a bearer token (unique on token_hash), find one responder's handsets
 * (member_email), and list every live handset for a broadcast
 * (revoked_at).
 */
final class WpdbDeviceRepository implements DeviceRepository
{
    public const TABLE_SUFFIX = 'reach_devices';

    /**
     * @return literal-string
     *
     * See WpdbPasswordCredentialRepository::tableName() on why the assertion
     * is needed and why it holds.
     */
    public static function tableName(wpdb $wpdb): string
    {
        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::TABLE_SUFFIX;
    }

    /** Domain separator for the encryption key. See {@see Cipher}. */
    private const CIPHER_DOMAIN = 'reach-device-payload-key';

    private readonly Cipher $cipher;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->cipher = new Cipher(self::CIPHER_DOMAIN);
    }

    /**
     * Idempotent: safe to call on every activation. dbDelta diffs
     * against the live schema and only applies changes.
     *
     * token_hash is CHAR(64) because it is always a hex SHA-256 — fixed
     * width, so the unique index over it stays compact. push_token is
     * generously sized: FCM registration tokens have no documented
     * maximum and have grown twice, and a truncated one is a handset
     * that silently never rings.
     *
     * payload_key holds the base64 of an encrypted 32-byte secret, so it
     * is about 120 characters; 255 leaves room without another migration.
     * It is not indexed and never searched — the only way in is by device
     * id. Defaulted to empty rather than made nullable so that handsets
     * enrolled before this column existed read as "no key" without a
     * null check at every call site; they get one by enrolling again.
     *
     * key_fault_at is nullable rather than defaulted, because here the
     * absence genuinely is a third state: null means "this handset has
     * never reported being unable to read an alert", which is not the
     * same as reporting it at the epoch.
     *
     * lock_screen is defaulted to empty for the same reason payload_key
     * is: a handset enrolled before the column existed, or running a
     * build too old to report, reads as "not known" without a null check
     * at every call site. Empty is deliberately *not* reassuring — see
     * {@see \Reach\Devices\Device::LOCK_SCREEN_UNKNOWN}.
     */
    public static function install(wpdb $wpdb): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table   = self::tableName($wpdb);
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash CHAR(64) NOT NULL,
            member_email VARCHAR(254) NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            label VARCHAR(200) NOT NULL DEFAULT '',
            platform VARCHAR(32) NOT NULL DEFAULT '',
            push_provider VARCHAR(16) NOT NULL DEFAULT '',
            push_token VARCHAR(512) NOT NULL DEFAULT '',
            payload_key VARCHAR(255) NOT NULL DEFAULT '',
            key_fault_at BIGINT UNSIGNED NULL,
            lock_screen VARCHAR(16) NOT NULL DEFAULT '',
            created_at BIGINT UNSIGNED NOT NULL,
            last_seen_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            revoked_at BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY member_email (member_email),
            KEY revoked_at (revoked_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public function create(
        string $tokenHash,
        string $memberEmail,
        int $memberId,
        string $label,
        string $platform,
        string $pushProvider,
        string $pushToken,
        int $now,
        string $payloadKey = '',
    ): Device {
        $table = self::tableName($this->wpdb);

        // Encrypted before the insert rather than inside it, because the
        // failure has to be able to stop the enrolment.
        //
        // Cipher::encrypt() reports failure by answering '', which is also
        // exactly what a handset enrolled before this column existed
        // stores. Writing it would enrol a device the server believes has
        // no key while the handset holds one it believes is live — and the
        // handset would have no way to find out, because the key is
        // returned to it in the same response that reported success. The
        // two ends would then disagree about whether payloads are
        // encrypted, which is worse than not enrolling at all.
        $storedKey = '';
        if ($payloadKey !== '') {
            $storedKey = $this->cipher->encrypt($payloadKey);

            // Not covered by a test: openssl_encrypt() is an internal
            // function, this project has no patchwork.json making
            // internals redefinable, and there is no input to
            // aes-256-gcm with a valid 32-byte key that makes it fail.
            // Instrumenting every test run to reach one defensive branch
            // costs more than it proves. The invariant that matters —
            // that a device reported as enrolled always has a readable
            // key — is covered by
            // testTheIssuedPayloadKeyIsTheOneStoredAgainstTheDevice.
            if ($storedKey === '') {
                throw new RuntimeException(
                    'The device could not be enrolled: its payload key could not be encrypted. '
                    . 'Check that the openssl extension is available and AUTH_KEY is set.'
                );
            }
        }

        $inserted = $this->wpdb->insert(
            $table,
            [
                'token_hash'    => $tokenHash,
                'member_email'  => $memberEmail,
                'member_id'     => $memberId,
                'label'         => $label,
                'platform'      => $platform,
                'push_provider' => $pushProvider,
                'push_token'    => $pushToken,
                'payload_key'   => $storedKey,
                'created_at'    => $now,
                'last_seen_at'  => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d'],
        );

        // $wpdb->insert() reports failure by returning false, and a missing
        // table is a failure like any other. Left unchecked this returned a
        // Device with id 0 and the caller minted a token for it, so
        // enrolment answered 201 with a working-looking credential for a row
        // that did not exist. The handset stored it, 401'd on its very next
        // request, and bounced its responder back to sign-in - with an empty
        // admin device list and nothing anywhere saying why. Silence is the
        // one failure mode this feature cannot afford, so fail loudly.
        if ($inserted === false || (int) $this->wpdb->insert_id <= 0) {
            throw new RuntimeException(
                'The device could not be enrolled: the write to ' . $table . ' failed. '
                . 'If the table is missing, Reach\Core\Schema installs it on the next load.'
            );
        }

        return new Device(
            (int) $this->wpdb->insert_id,
            $memberEmail,
            $memberId,
            $label,
            $platform,
            $pushProvider,
            $pushToken,
            $now,
            $now,
        );
    }

    public function findByTokenHash(string $tokenHash): ?Device
    {
        $table = self::tableName($this->wpdb);

        // revoked_at IS NULL is part of the lookup rather than a check
        // the caller makes afterwards: a revoked token must be
        // indistinguishable from an unknown one at every call site, and
        // the surest way to guarantee that is to never return the row.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              WHERE token_hash = %s AND revoked_at IS NULL
              LIMIT 1",
            $tokenHash,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Device
    {
        $table = self::tableName($this->wpdb);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByMemberEmail(string $memberEmail): array
    {
        $table = self::tableName($this->wpdb);
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              WHERE member_email = %s AND revoked_at IS NULL
              ORDER BY id ASC",
            $memberEmail,
        ), ARRAY_A);

        return $this->hydrateAll($rows);
    }

    public function findAllLive(): array
    {
        $table = self::tableName($this->wpdb);
        $rows = $this->wpdb->get_results(
            "SELECT {$this->columns()} FROM {$table} WHERE revoked_at IS NULL ORDER BY id ASC",
            ARRAY_A,
        );

        return $this->hydrateAll($rows);
    }

    public function list(
        int $limit,
        int $offset,
        string $orderBy = '',
        string $order = 'desc',
        string $search = '',
    ): array {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $table  = self::tableName($this->wpdb);

        // Two shapes rather than one with an always-true WHERE: the
        // arguments differ, and prepare() takes them positionally.
        $sql = $search === ''
            ? $this->wpdb->prepare(
                "SELECT {$this->columns()}
                   FROM {$table}
                  {$this->orderClause($orderBy, $order)}
                  LIMIT %d OFFSET %d",
                $limit,
                $offset,
            )
            : $this->wpdb->prepare(
                "SELECT {$this->columns()}
                   FROM {$table}
                  WHERE member_email LIKE %s OR label LIKE %s OR platform LIKE %s
                  {$this->orderClause($orderBy, $order)}
                  LIMIT %d OFFSET %d",
                ...array_merge($this->searchTerms($search), [$limit, $offset]),
            );

        return $this->hydrateAll($this->wpdb->get_results($sql, ARRAY_A));
    }

    /**
     * The same LIKE term three times, escaped for it.
     *
     * <b>esc_like() before the wildcards, never after.</b> It escapes
     * the `%` and `_` a person may have typed, so wrapping first would
     * escape our own wildcards and turn every search into a search for
     * a literal percent sign.
     *
     * @return array<int, string>
     */
    private function searchTerms(string $search): array
    {
        $term = '%' . $this->wpdb->esc_like($search) . '%';

        return [$term, $term, $term];
    }

    /**
     * The ORDER BY for an admin-requested sort.
     *
     * <b>A match rather than an escape.</b> ORDER BY takes no prepared
     * placeholder, so a column arriving from a request can only be made
     * safe by refusing anything not named here — and naming them in a
     * match arm is also what keeps the composed query a literal-string,
     * which is the property {@see wpdb::prepare()} is entitled to
     * assume of everything around its placeholders.
     *
     * id DESC always tails the clause: without it, rows sharing a value
     * — every handset on the same platform, say — come back in whatever
     * order the storage engine felt like, and a row can appear on two
     * pages or on neither.
     *
     * @return literal-string
     */
    private function orderClause(string $orderBy, string $order): string
    {
        $column = match (strtolower($orderBy)) {
            'member_email'  => 'member_email',
            'label'         => 'label',
            'platform'      => 'platform',
            'push_provider' => 'push_provider',
            'created_at'    => 'created_at',
            'last_seen_at'  => 'last_seen_at',
            'revoked_at'    => 'revoked_at',
            default         => '',
        };

        if ($column === '') {
            // Live handsets first, then newest-first within each group,
            // so the admin page opens on what is currently enrolled
            // rather than on a wall of history.
            return 'ORDER BY (revoked_at IS NULL) DESC, created_at DESC, id DESC';
        }

        $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        return "ORDER BY {$column} {$direction}, id DESC";
    }

    public function countAll(string $search = ''): int
    {
        $table = self::tableName($this->wpdb);

        if ($search === '') {
            return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        // Must match list()'s WHERE exactly, or the pager counts rows the
        // table does not show and offers a page that comes back empty.
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE member_email LIKE %s OR label LIKE %s OR platform LIKE %s",
            ...$this->searchTerms($search),
        ));
    }

    public function recordLockScreen(int $id, string $lockScreen): bool
    {
        if (!in_array($lockScreen, Device::LOCK_SCREEN_STATES, true)) {
            return false;
        }

        $table = self::tableName($this->wpdb);

        $updated = $this->wpdb->update(
            $table,
            ['lock_screen' => $lockScreen],
            ['id' => $id],
            ['%s'],
            ['%d'],
        );

        // An unchanged value updates zero rows, which is a success: the
        // handset said the same thing it said last time, which is the
        // ordinary case at every launch.
        return $updated !== false;
    }

    public function markKeyFault(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);

        $updated = $this->wpdb->update(
            $table,
            ['key_fault_at' => $now],
            ['id' => $id],
            ['%d'],
            ['%d'],
        );

        return is_int($updated) && $updated > 0;
    }


    public function payloadKeyFor(int $id): string
    {
        $table = self::tableName($this->wpdb);

        $stored = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT payload_key FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ));

        if (!is_string($stored) || $stored === '') {
            return '';
        }

        return $this->cipher->decrypt($stored);
    }

    public function touch(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);
        $updated = $this->wpdb->update(
            $table,
            ['last_seen_at' => $now],
            ['id' => $id],
            ['%d'],
            ['%d'],
        );

        return is_int($updated) && $updated > 0;
    }

    public function updatePushToken(int $id, string $pushProvider, string $pushToken): bool
    {
        $table = self::tableName($this->wpdb);
        $updated = $this->wpdb->update(
            $table,
            ['push_provider' => $pushProvider, 'push_token' => $pushToken],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );

        return is_int($updated) && $updated > 0;
    }

    public function revoke(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);

        // Only a still-live row is updated, which makes revocation
        // idempotent and preserves the moment a handset was actually cut
        // off. prepare() returns null only on a placeholder/argument
        // mismatch — a coding error, not a runtime condition — and here
        // false would read as "already revoked" and hide the bug.
        $sql = $this->wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %d WHERE id = %d AND revoked_at IS NULL",
            $now,
            $id,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the device revocation query.');
        }

        $updated = $this->wpdb->query($sql);

        return is_int($updated) && $updated > 0;
    }

    public function delete(int $id): bool
    {
        $table = self::tableName($this->wpdb);

        $deleted = $this->wpdb->delete($table, ['id' => $id], ['%d']);

        return is_int($deleted) && $deleted > 0;
    }

    public function revokeAllForMember(string $memberEmail, int $now): int
    {
        $table = self::tableName($this->wpdb);

        $sql = $this->wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %d WHERE member_email = %s AND revoked_at IS NULL",
            $now,
            $memberEmail,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the bulk device revocation query.');
        }

        $updated = $this->wpdb->query($sql);

        return is_int($updated) ? $updated : 0;
    }

    /** @return literal-string */
    private function columns(): string
    {
        return 'id, token_hash, member_email, member_id, label, platform, '
            . 'push_provider, push_token, created_at, last_seen_at, revoked_at, '
            . 'key_fault_at, lock_screen';
    }

    /**
     * @param mixed $rows
     * @return array<int, Device>
     */
    private function hydrateAll($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Device
    {
        return new Device(
            (int) $row['id'],
            (string) $row['member_email'],
            (int) $row['member_id'],
            (string) $row['label'],
            (string) $row['platform'],
            (string) $row['push_provider'],
            (string) $row['push_token'],
            (int) $row['created_at'],
            (int) $row['last_seen_at'],
            $row['revoked_at'] !== null ? (int) $row['revoked_at'] : null,
            ($row['key_fault_at'] ?? null) !== null ? (int) $row['key_fault_at'] : null,
            (string) ($row['lock_screen'] ?? ''),
        );
    }
}
