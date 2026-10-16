<?php

declare(strict_types=1);

namespace Dashboard\Calls;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Call log: upsert by Bland id, debt-collector details, contacts, vetted agencies.
 * Every read is scoped to the signed-in user.
 */
final class CallService
{
    private const OPEN = "'new', 'needs_return', 'waiting', 'escalated_to_penny'";

    private const FROM = <<<'SQL'
        SELECT c.*,
               ct.name AS contact_name,
               ct.vip_tier AS contact_vip_tier,
               ct.relationship AS contact_relationship,
               ct.handling_rule AS contact_handling_rule,
               d.call_id AS debt_call_id,
               d.collector_name AS debt_collector_name,
               d.agency AS debt_agency,
               d.agency_address AS debt_agency_address,
               d.agency_phone AS debt_agency_phone,
               d.website AS debt_website,
               d.original_creditor AS debt_original_creditor,
               d.reference_last4 AS debt_reference_last4,
               d.amount_claimed AS debt_amount_claimed,
               d.debt_date AS debt_date,
               d.validation_requested AS debt_validation_requested,
               d.verdict AS debt_verdict,
               d.scam_reasons AS debt_scam_reasons,
               d.verification_notes AS debt_verification_notes,
               d.cfpb_complaints AS debt_cfpb_complaints,
               d.state_license AS debt_state_license,
               d.ag_ftc_warnings AS debt_ag_ftc_warnings,
               d.evidence_urls AS debt_evidence_urls,
               d.vetted_at AS debt_vetted_at,
               d.referred_to_penny AS debt_referred_to_penny
        FROM calls c
        LEFT JOIN call_debt d ON d.call_id = c.id AND d.user_id = c.user_id
        LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.user_id = c.user_id
        SQL;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Insert, or update the row that already has this source_call_id.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function log(int $userId, array $body, string $defaultSource = 'manual'): array
    {
        return $this->db->transaction(function () use ($userId, $body, $defaultSource): array {
            $existing = $this->findBySource($userId, $body['source_call_id'] ?? null);
            if ($existing === null) {
                $columns = CallGuard::callColumns($this->blank(), $body, $this->clock, true, $defaultSource);
                $columns = $this->resolveLinks($userId, $columns, $body);
                $id = (int) $this->db->fetchScalar(
                    'INSERT INTO calls (
                        user_id, call_at, direction, phone, callback_number, name, company, category,
                        reason, summary, notes, urgency, deadline, return_needed, status, duration_sec,
                        transcript_url, recording_url, transcript_text, has_recording, source, source_call_id,
                        linked_todo_id, contact_id, handled_by, notified_david, notified_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?
                    ) RETURNING id',
                    $this->insertParams($userId, $columns)
                );
            } else {
                $id = (int) $existing['id'];
                $this->writeCall($userId, $id, $existing, $body, $defaultSource);
            }
            if (array_key_exists('debt', $body)) {
                $this->writeDebt($userId, $id, $body['debt']);
            }
            return $this->get($userId, $id);
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(int $userId, int $id, array $body): array
    {
        return $this->db->transaction(function () use ($userId, $id, $body): array {
            $existing = $this->raw($userId, $id);
            $this->writeCall($userId, $id, $existing, $body, (string) $existing['source']);
            if (array_key_exists('debt', $body)) {
                $this->writeDebt($userId, $id, $body['debt']);
            }
            return $this->get($userId, $id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(int $userId, int $id, string $status, ?bool $returnNeeded = null): array
    {
        $body = ['status' => $status];
        if ($returnNeeded !== null) {
            $body['return_needed'] = $returnNeeded;
        }
        return $this->update($userId, $id, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function linkTodo(int $userId, int $id, ?int $todoId): array
    {
        return $this->update($userId, $id, ['linked_todo_id' => $todoId ?? 0]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(self::FROM . ' WHERE c.user_id = ? AND c.id = ?', [$userId, $id]);
        if ($row === null) {
            throw new NotFoundException('Call not found.');
        }
        return $this->present($row, true);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listCalls(int $userId, array $filters = [], bool $withTranscript = true): array
    {
        $status = $this->optionalEnum($filters['status'] ?? null, CallGuard::STATUSES, 'status');
        $category = $this->optionalEnum($filters['category'] ?? null, CallGuard::CATEGORIES, 'category');
        $urgency = $this->optionalEnum($filters['urgency'] ?? null, CallGuard::URGENCIES, 'urgency');
        $handled = $this->optionalEnum($filters['handled_by'] ?? null, CallGuard::HANDLERS, 'handled_by');
        $returnFlag = -1;
        $returnValue = 0;
        if (array_key_exists('return_needed', $filters) && $filters['return_needed'] !== null && $filters['return_needed'] !== '') {
            $returnFlag = 1;
            $returnValue = CallGuard::flag($filters['return_needed']) ? 1 : 0;
        }
        [$from, $to] = $this->range($filters['from'] ?? null, $filters['to'] ?? null);
        $digits = CallGuard::matchDigits($filters['phone'] ?? '');
        $phoneFlag = strlen($digits) >= 3 ? '1' : '';
        $limit = $this->limit($filters['limit'] ?? 50, 100);

        $rows = $this->db->fetchAll(
            self::FROM . " WHERE c.user_id = ?
                AND (? = '' OR c.status = ?)
                AND (? = '' OR c.category = ?)
                AND (? = '' OR c.urgency = ?)
                AND (? = '' OR c.handled_by = ?)
                AND (? = -1 OR c.return_needed = ?)
                AND c.call_at >= ?
                AND c.call_at <= ?
                AND (
                    ? = ''
                    OR right(regexp_replace(COALESCE(c.phone, ''), '\\D', '', 'g'), char_length(?)) = ?
                    OR right(regexp_replace(COALESCE(c.callback_number, ''), '\\D', '', 'g'), char_length(?)) = ?
                )
                ORDER BY
                    CASE WHEN c.return_needed = 1 AND c.status IN (" . self::OPEN . ") THEN 0 ELSE 1 END,
                    CASE c.urgency WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                    c.call_at DESC, c.id DESC
                LIMIT ?",
            [
                $userId,
                $status, $status,
                $category, $category,
                $urgency, $urgency,
                $handled, $handled,
                $returnFlag, $returnValue,
                $from, $to,
                $phoneFlag, $digits, $digits, $digits, $digits,
                $limit,
            ]
        );
        return array_map(fn (array $row): array => $this->present($row, $withTranscript), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(int $userId, string $q, int $limit = 30): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            throw ValidationException::withFields(['q' => 'Search needs at least 2 characters.']);
        }
        $digits = CallGuard::matchDigits($q);
        $digitFlag = strlen($digits) >= 3 ? $digits : '___none___';
        $limit = $this->limit($limit, 50);
        $needle = mb_strtolower($q);
        $rows = $this->db->fetchAll(
            self::FROM . " WHERE c.user_id = ?
                AND (
                    strpos(lower(COALESCE(c.name, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.company, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.phone, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.callback_number, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.reason, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.summary, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.notes, '')), ?) > 0
                    OR strpos(lower(COALESCE(c.transcript_text, '')), ?) > 0
                    OR right(regexp_replace(COALESCE(c.phone, ''), '\\D', '', 'g'), char_length(?)) = ?
                    OR right(regexp_replace(COALESCE(c.callback_number, ''), '\\D', '', 'g'), char_length(?)) = ?
                )
                ORDER BY c.call_at DESC, c.id DESC
                LIMIT ?",
            [
                $userId,
                $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle,
                $digitFlag, $digitFlag, $digitFlag, $digitFlag,
                $limit,
            ]
        );
        return array_map(fn (array $row): array => $this->present($row, true), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(int $userId): array
    {
        $byCategory = array_fill_keys(CallGuard::CATEGORIES, 0);
        $byStatus = array_fill_keys(CallGuard::STATUSES, 0);
        foreach ($this->db->fetchAll(
            'SELECT category, COUNT(*) AS n FROM calls WHERE user_id = ? GROUP BY category',
            [$userId]
        ) as $row) {
            $byCategory[(string) $row['category']] = (int) $row['n'];
        }
        foreach ($this->db->fetchAll(
            'SELECT status, COUNT(*) AS n FROM calls WHERE user_id = ? GROUP BY status',
            [$userId]
        ) as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }
        $counts = $this->db->fetchOne(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE c.return_needed = 1 AND c.status IN (" . self::OPEN . ")) AS open_returns,
                COUNT(*) FILTER (WHERE c.urgency = 'urgent' OR c.category = 'urgent') AS urgent,
                COUNT(*) FILTER (WHERE c.category = 'debt_collector') AS debt,
                COUNT(*) FILTER (WHERE c.category = 'vip' OR COALESCE(ct.vip_tier, '') <> '') AS vip
             FROM calls c
             LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.user_id = c.user_id
             WHERE c.user_id = ?",
            [$userId]
        ) ?? [];
        return [
            'total'        => (int) ($counts['total'] ?? 0),
            'open_returns' => (int) ($counts['open_returns'] ?? 0),
            'urgent'       => (int) ($counts['urgent'] ?? 0),
            'debt'         => (int) ($counts['debt'] ?? 0),
            'vip'          => (int) ($counts['vip'] ?? 0),
            'by_category'  => $byCategory,
            'by_status'    => $byStatus,
        ];
    }

    /**
     * Contact plus earlier calls for this number.
     *
     * @return array<string, mixed>
     */
    public function lookup(int $userId, string $phone): array
    {
        $stored = CallGuard::phone($phone, 'phone');
        if ($stored === null) {
            throw ValidationException::withFields(['phone' => 'Use a phone number, 7 to 15 digits.']);
        }
        $contact = $this->contactByPhone($userId, $stored);
        return [
            'phone'         => $stored,
            'phone_display' => CallGuard::formatPhone($stored),
            'phone_tel'     => CallGuard::telHref($stored),
            'contact'       => $contact === null ? null : $this->presentContact($contact),
            'calls'         => $this->listCalls($userId, ['phone' => $stored, 'limit' => 20]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contacts(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM contacts WHERE user_id = ? ORDER BY lower(name), id',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->presentContact($row), $rows);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function upsertContact(int $userId, array $body): array
    {
        return $this->db->transaction(function () use ($userId, $body): array {
            $name = CallGuard::text($body['name'] ?? null, 'name', 160);
            $phones = array_key_exists('phones', $body) ? CallGuard::phones($body['phones'], 'phones') : null;
            $existing = null;
            if (isset($body['id']) && $body['id'] !== '' && $body['id'] !== null) {
                $existing = $this->rawContact($userId, (int) $body['id']);
            } else {
                foreach ($phones ?? [] as $phone) {
                    $existing = $this->contactByPhone($userId, $phone);
                    if ($existing !== null) {
                        break;
                    }
                }
                if ($existing === null && $name !== null) {
                    $matches = $this->db->fetchAll(
                        'SELECT * FROM contacts WHERE user_id = ? AND lower(name) = lower(?) ORDER BY id',
                        [$userId, $name]
                    );
                    if (count($matches) === 1) {
                        $existing = $matches[0];
                    }
                }
            }
            if ($existing === null && $name === null) {
                throw ValidationException::withFields(['name' => 'A contact needs a name.']);
            }
            $relationship = array_key_exists('relationship', $body)
                ? CallGuard::text($body['relationship'], 'relationship', 80)
                : ($existing['relationship'] ?? null);
            $vip = array_key_exists('vip_tier', $body)
                ? CallGuard::text($body['vip_tier'], 'vip_tier', 24)
                : ($existing['vip_tier'] ?? null);
            $rule = array_key_exists('handling_rule', $body)
                ? CallGuard::text($body['handling_rule'], 'handling_rule', 2000)
                : ($existing['handling_rule'] ?? null);
            $notes = array_key_exists('notes', $body)
                ? CallGuard::text($body['notes'], 'notes', 4000)
                : ($existing['notes'] ?? null);
            $phoneJson = self::jsonList($phones ?? $this->decodeList($existing['phones'] ?? '[]'));
            if ($existing === null) {
                $id = (int) $this->db->fetchScalar(
                    'INSERT INTO contacts (user_id, name, phones, relationship, vip_tier, handling_rule, notes)
                     VALUES (?, ?, ?::jsonb, ?, ?, ?, ?) RETURNING id',
                    [$userId, $name, $phoneJson, $relationship, $vip, $rule, $notes]
                );
            } else {
                $id = (int) $existing['id'];
                $this->db->execute(
                    "UPDATE contacts
                     SET name = ?, phones = ?::jsonb, relationship = ?, vip_tier = ?, handling_rule = ?, notes = ?,
                         updated_at = (now() AT TIME ZONE 'UTC')
                     WHERE id = ? AND user_id = ?",
                    [$name ?? $existing['name'], $phoneJson, $relationship, $vip, $rule, $notes, $id, $userId]
                );
            }
            $row = $this->rawContact($userId, $id);
            return $this->presentContact($row);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agencies(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM vetted_agencies WHERE user_id = ? ORDER BY lower(name), id',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->presentAgency($row), $rows);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function upsertAgency(int $userId, array $body): array
    {
        return $this->db->transaction(function () use ($userId, $body): array {
            $existing = null;
            if (isset($body['id']) && $body['id'] !== '' && $body['id'] !== null) {
                $existing = $this->rawAgency($userId, (int) $body['id']);
            }
            $name = array_key_exists('name', $body)
                ? CallGuard::text($body['name'], 'name', 160)
                : ($existing['name'] ?? null);
            if ($name === null) {
                throw ValidationException::withFields(['name' => 'An agency needs a name.']);
            }
            if ($existing === null) {
                $existing = $this->db->fetchOne(
                    'SELECT * FROM vetted_agencies WHERE user_id = ? AND lower(name) = lower(?)',
                    [$userId, $name]
                );
            }
            $phones = array_key_exists('phones', $body)
                ? CallGuard::phones($body['phones'], 'phones')
                : $this->decodeList($existing['phones'] ?? '[]');
            $website = array_key_exists('website', $body)
                ? CallGuard::url($body['website'], 'website')
                : ($existing['website'] ?? null);
            $verdict = array_key_exists('verdict', $body) || $existing === null
                ? CallGuard::enum($body['verdict'] ?? $existing['verdict'] ?? 'unverified', CallGuard::VERDICTS, 'verdict')
                : $existing['verdict'];
            $notes = array_key_exists('notes', $body)
                ? CallGuard::text($body['notes'], 'notes', 4000)
                : ($existing['notes'] ?? null);
            $urls = array_key_exists('evidence_urls', $body)
                ? CallGuard::urls($body['evidence_urls'], 'evidence_urls')
                : $this->decodeList($existing['evidence_urls'] ?? '[]');
            $vettedAt = array_key_exists('vetted_at', $body)
                ? (($body['vetted_at'] === null || $body['vetted_at'] === '') ? null : CallGuard::timestamp($body['vetted_at'], 'vetted_at', $this->clock))
                : ($existing['vetted_at'] ?? $this->clock->utcString($this->clock->now()));
            if ($existing === null && !array_key_exists('vetted_at', $body)) {
                $vettedAt = $this->clock->utcString($this->clock->now());
            }
            $phoneJson = self::jsonList($phones);
            $urlJson = self::jsonList($urls);
            if ($existing === null) {
                $id = (int) $this->db->fetchScalar(
                    'INSERT INTO vetted_agencies (user_id, name, phones, website, verdict, notes, evidence_urls, vetted_at)
                     VALUES (?, ?, ?::jsonb, ?, ?, ?, ?::jsonb, ?) RETURNING id',
                    [$userId, $name, $phoneJson, $website, $verdict, $notes, $urlJson, $vettedAt]
                );
            } else {
                $id = (int) $existing['id'];
                $this->db->execute(
                    "UPDATE vetted_agencies
                     SET name = ?, phones = ?::jsonb, website = ?, verdict = ?, notes = ?, evidence_urls = ?::jsonb,
                         vetted_at = ?, updated_at = (now() AT TIME ZONE 'UTC')
                     WHERE id = ? AND user_id = ?",
                    [$name, $phoneJson, $website, $verdict, $notes, $urlJson, $vettedAt, $id, $userId]
                );
            }
            return $this->presentAgency($this->rawAgency($userId, $id));
        });
    }

    /**
     * Widget payload. Open returns sort first. Transcript text stays off the grid.
     *
     * @return array<string, mixed>
     */
    public function board(int $userId, int $limit): array
    {
        $limit = max(3, min(40, $limit));
        return [
            'metric_ids' => [],
            'counts'     => $this->stats($userId),
            'calls'      => $this->listCalls($userId, ['limit' => $limit], false),
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $body
     */
    private function writeCall(int $userId, int $id, array $existing, array $body, string $defaultSource): void
    {
        $columns = CallGuard::callColumns($existing, $body, $this->clock, false, $defaultSource);
        $columns = $this->resolveLinks($userId, $columns, $body);
        $this->db->execute(
            "UPDATE calls SET
                call_at = ?, direction = ?, phone = ?, callback_number = ?, name = ?, company = ?, category = ?,
                reason = ?, summary = ?, notes = ?, urgency = ?, deadline = ?, return_needed = ?, status = ?,
                duration_sec = ?, transcript_url = ?, recording_url = ?, transcript_text = ?, has_recording = ?,
                source = ?, source_call_id = ?, linked_todo_id = ?, contact_id = ?, handled_by = ?,
                notified_david = ?, notified_at = ?, updated_at = (now() AT TIME ZONE 'UTC')
             WHERE id = ? AND user_id = ?",
            [
                $columns['call_at'], $columns['direction'], $columns['phone'], $columns['callback_number'],
                $columns['name'], $columns['company'], $columns['category'], $columns['reason'],
                $columns['summary'], $columns['notes'], $columns['urgency'], $columns['deadline'],
                (int) $columns['return_needed'], $columns['status'], $columns['duration_sec'],
                $columns['transcript_url'], $columns['recording_url'], $columns['transcript_text'],
                (int) $columns['has_recording'], $columns['source'], $columns['source_call_id'],
                $columns['linked_todo_id'], $columns['contact_id'], $columns['handled_by'],
                (int) $columns['notified_david'], $columns['notified_at'],
                $id, $userId,
            ]
        );
    }

    private function writeDebt(int $userId, int $id, mixed $debt): void
    {
        if ($debt === null) {
            $this->db->execute('DELETE FROM call_debt WHERE call_id = ? AND user_id = ?', [$id, $userId]);
            return;
        }
        if (!is_array($debt)) {
            throw ValidationException::withFields(['debt' => 'Debt details must be an object.']);
        }
        $existing = $this->db->fetchOne(
            'SELECT * FROM call_debt WHERE call_id = ? AND user_id = ?',
            [$id, $userId]
        );
        $current = $existing ?? CallGuard::blankDebt();
        if ($existing !== null) {
            $current['evidence_urls'] = $this->decodeList($existing['evidence_urls'] ?? '[]');
        }
        $columns = CallGuard::debtColumns($current, $debt, $this->clock);
        if (!array_key_exists('verdict', $debt) && !empty($columns['agency'])) {
            $known = $this->db->fetchOne(
                'SELECT verdict, website, vetted_at FROM vetted_agencies WHERE user_id = ? AND lower(name) = lower(?)',
                [$userId, $columns['agency']]
            );
            if ($known !== null) {
                $columns['verdict'] = $known['verdict'];
                if (empty($columns['website'])) {
                    $columns['website'] = $known['website'];
                }
                if (empty($columns['vetted_at'])) {
                    $columns['vetted_at'] = $known['vetted_at'];
                }
            }
        }
        $params = [
            $columns['collector_name'], $columns['agency'], $columns['agency_address'], $columns['agency_phone'],
            $columns['website'], $columns['original_creditor'], $columns['reference_last4'], $columns['amount_claimed'],
            $columns['debt_date'], (int) $columns['validation_requested'], $columns['verdict'], $columns['scam_reasons'],
            $columns['verification_notes'], $columns['cfpb_complaints'], $columns['state_license'],
            $columns['ag_ftc_warnings'], self::jsonList($columns['evidence_urls']), $columns['vetted_at'],
            (int) $columns['referred_to_penny'],
        ];
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO call_debt (
                    call_id, user_id, collector_name, agency, agency_address, agency_phone, website,
                    original_creditor, reference_last4, amount_claimed, debt_date, validation_requested, verdict,
                    scam_reasons, verification_notes, cfpb_complaints, state_license, ag_ftc_warnings,
                    evidence_urls, vetted_at, referred_to_penny
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?::jsonb, ?, ?
                )',
                array_merge([$id, $userId], $params)
            );
            return;
        }
        $this->db->execute(
            'UPDATE call_debt SET
                collector_name = ?, agency = ?, agency_address = ?, agency_phone = ?, website = ?,
                original_creditor = ?, reference_last4 = ?, amount_claimed = ?, debt_date = ?,
                validation_requested = ?, verdict = ?, scam_reasons = ?, verification_notes = ?,
                cfpb_complaints = ?, state_license = ?, ag_ftc_warnings = ?, evidence_urls = ?::jsonb,
                vetted_at = ?, referred_to_penny = ?
             WHERE call_id = ? AND user_id = ?',
            array_merge($params, [$id, $userId])
        );
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function resolveLinks(int $userId, array $columns, array $body): array
    {
        if ($columns['linked_todo_id'] !== null) {
            $todo = $this->db->fetchScalar(
                'SELECT i.id FROM user_todo_items i
                 JOIN user_todo_lists l ON l.id = i.list_id
                 WHERE i.id = ? AND l.user_id = ?',
                [$columns['linked_todo_id'], $userId]
            );
            if ($todo === null || $todo === false) {
                throw ValidationException::withFields(['linked_todo_id' => 'That task is not on one of your lists.']);
            }
        }
        if ($columns['contact_id'] !== null) {
            $contact = $this->db->fetchScalar(
                'SELECT id FROM contacts WHERE id = ? AND user_id = ?',
                [$columns['contact_id'], $userId]
            );
            if ($contact === null || $contact === false) {
                throw ValidationException::withFields(['contact_id' => 'That contact was not found.']);
            }
        } elseif (!array_key_exists('contact_id', $body) && empty($columns['contact_id']) && !empty($columns['phone'])) {
            $match = $this->contactByPhone($userId, (string) $columns['phone']);
            if ($match !== null) {
                $columns['contact_id'] = (int) $match['id'];
            }
        }
        return $columns;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBySource(int $userId, mixed $sourceCallId): ?array
    {
        if ($sourceCallId === null || trim((string) $sourceCallId) === '') {
            return null;
        }
        return $this->db->fetchOne(
            'SELECT * FROM calls WHERE user_id = ? AND source_call_id = ?',
            [$userId, trim((string) $sourceCallId)]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM calls WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Call not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawContact(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM contacts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Contact not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAgency(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM vetted_agencies WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Agency not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contactByPhone(int $userId, string $phone): ?array
    {
        $digits = CallGuard::matchDigits($phone);
        if (strlen($digits) < 7) {
            return null;
        }
        return $this->db->fetchOne(
            "SELECT * FROM contacts WHERE user_id = ? AND EXISTS (
                SELECT 1 FROM jsonb_array_elements_text(phones) AS p(phone)
                WHERE right(regexp_replace(p.phone, '\\D', '', 'g'), char_length(?)) = ?
            ) ORDER BY id LIMIT 1",
            [$userId, $digits, $digits]
        );
    }

    /**
     * @param array<string, mixed> $columns
     * @return list<mixed>
     */
    private function insertParams(int $userId, array $columns): array
    {
        return [
            $userId, $columns['call_at'], $columns['direction'], $columns['phone'], $columns['callback_number'],
            $columns['name'], $columns['company'], $columns['category'], $columns['reason'], $columns['summary'],
            $columns['notes'], $columns['urgency'], $columns['deadline'], (int) $columns['return_needed'],
            $columns['status'], $columns['duration_sec'], $columns['transcript_url'], $columns['recording_url'],
            $columns['transcript_text'], (int) $columns['has_recording'], $columns['source'], $columns['source_call_id'],
            $columns['linked_todo_id'], $columns['contact_id'], $columns['handled_by'],
            (int) $columns['notified_david'], $columns['notified_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [
            'call_at'          => $this->clock->utcString($this->clock->now()),
            'direction'        => '',
            'phone'            => null,
            'callback_number'  => null,
            'name'             => null,
            'company'          => null,
            'category'         => 'personal',
            'reason'           => null,
            'summary'          => null,
            'notes'            => null,
            'urgency'          => 'normal',
            'deadline'         => null,
            'return_needed'    => 0,
            'status'           => 'new',
            'duration_sec'     => null,
            'transcript_url'   => null,
            'recording_url'    => null,
            'transcript_text'  => null,
            'has_recording'    => 0,
            'source'           => 'manual',
            'source_call_id'   => null,
            'linked_todo_id'   => null,
            'contact_id'       => null,
            'handled_by'       => null,
            'notified_david'   => 0,
            'notified_at'      => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, bool $withTranscript): array
    {
        $phone = $row['phone'] !== null ? (string) $row['phone'] : null;
        $callback = $row['callback_number'] !== null ? (string) $row['callback_number'] : null;
        $status = (string) $row['status'];
        $return = (int) $row['return_needed'] === 1;
        $vipTier = isset($row['contact_vip_tier']) && $row['contact_vip_tier'] !== null && $row['contact_vip_tier'] !== ''
            ? (string) $row['contact_vip_tier']
            : null;
        $call = [
            'id'                => (int) $row['id'],
            'call_at'           => (string) $row['call_at'],
            'call_at_display'   => $this->displayTime((string) $row['call_at']),
            'direction'         => (string) $row['direction'],
            'phone'             => $phone,
            'phone_display'     => CallGuard::formatPhone($phone),
            'phone_tel'         => CallGuard::telHref($phone),
            'callback_number'   => $callback,
            'callback_display'  => CallGuard::formatPhone($callback),
            'callback_tel'      => CallGuard::telHref($callback),
            'name'              => $row['name'] !== null ? (string) $row['name'] : null,
            'company'           => $row['company'] !== null ? (string) $row['company'] : null,
            'category'          => (string) $row['category'],
            'reason'            => $row['reason'] !== null ? (string) $row['reason'] : null,
            'summary'           => $row['summary'] !== null ? (string) $row['summary'] : null,
            'notes'             => $row['notes'] !== null ? (string) $row['notes'] : null,
            'urgency'           => (string) $row['urgency'],
            'deadline'          => $row['deadline'] !== null ? (string) $row['deadline'] : null,
            'return_needed'     => $return,
            'open_return'       => $return && in_array($status, CallGuard::OPEN_RETURN, true),
            'status'            => $status,
            'duration_sec'      => $row['duration_sec'] !== null ? (int) $row['duration_sec'] : null,
            'transcript_url'    => $row['transcript_url'] !== null ? (string) $row['transcript_url'] : null,
            'recording_url'     => $row['recording_url'] !== null ? (string) $row['recording_url'] : null,
            'has_recording'     => (int) $row['has_recording'] === 1,
            'source'            => (string) $row['source'],
            'source_call_id'    => $row['source_call_id'] !== null ? (string) $row['source_call_id'] : null,
            'linked_todo_id'    => $row['linked_todo_id'] !== null ? (int) $row['linked_todo_id'] : null,
            'contact_id'        => $row['contact_id'] !== null ? (int) $row['contact_id'] : null,
            'contact_name'      => isset($row['contact_name']) && $row['contact_name'] !== null ? (string) $row['contact_name'] : null,
            'vip_tier'          => $vipTier,
            'relationship'      => isset($row['contact_relationship']) && $row['contact_relationship'] !== null ? (string) $row['contact_relationship'] : null,
            'handling_rule'     => isset($row['contact_handling_rule']) && $row['contact_handling_rule'] !== null ? (string) $row['contact_handling_rule'] : null,
            'vip'               => (string) $row['category'] === 'vip' || $vipTier !== null,
            'handled_by'        => $row['handled_by'] !== null ? (string) $row['handled_by'] : null,
            'notified_david'    => (int) $row['notified_david'] === 1,
            'notified_at'       => $row['notified_at'] !== null ? (string) $row['notified_at'] : null,
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
            'debt'              => empty($row['debt_call_id']) ? null : $this->presentDebt($row),
        ];
        if ($withTranscript) {
            $call['transcript_text'] = $row['transcript_text'] !== null ? (string) $row['transcript_text'] : null;
        }
        return $call;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentDebt(array $row): array
    {
        $amount = $row['debt_amount_claimed'];
        return [
            'collector_name'       => $row['debt_collector_name'] !== null ? (string) $row['debt_collector_name'] : null,
            'agency'                => $row['debt_agency'] !== null ? (string) $row['debt_agency'] : null,
            'agency_address'        => $row['debt_agency_address'] !== null ? (string) $row['debt_agency_address'] : null,
            'agency_phone'          => $row['debt_agency_phone'] !== null ? (string) $row['debt_agency_phone'] : null,
            'agency_phone_display'  => CallGuard::formatPhone($row['debt_agency_phone'] !== null ? (string) $row['debt_agency_phone'] : null),
            'agency_phone_tel'      => CallGuard::telHref($row['debt_agency_phone'] !== null ? (string) $row['debt_agency_phone'] : null),
            'website'               => $row['debt_website'] !== null ? (string) $row['debt_website'] : null,
            'original_creditor'     => $row['debt_original_creditor'] !== null ? (string) $row['debt_original_creditor'] : null,
            'reference_last4'       => $row['debt_reference_last4'] !== null ? (string) $row['debt_reference_last4'] : null,
            'amount_claimed'        => $amount !== null ? (float) $amount : null,
            'debt_date'             => $row['debt_date'] !== null ? (string) $row['debt_date'] : null,
            'validation_requested'  => (int) $row['debt_validation_requested'] === 1,
            'verdict'               => (string) $row['debt_verdict'],
            'scam_reasons'          => $row['debt_scam_reasons'] !== null ? (string) $row['debt_scam_reasons'] : null,
            'verification_notes'    => $row['debt_verification_notes'] !== null ? (string) $row['debt_verification_notes'] : null,
            'cfpb_complaints'       => $row['debt_cfpb_complaints'] !== null ? (string) $row['debt_cfpb_complaints'] : null,
            'state_license'         => $row['debt_state_license'] !== null ? (string) $row['debt_state_license'] : null,
            'ag_ftc_warnings'       => $row['debt_ag_ftc_warnings'] !== null ? (string) $row['debt_ag_ftc_warnings'] : null,
            'evidence_urls'         => $this->decodeList($row['debt_evidence_urls'] ?? '[]'),
            'vetted_at'             => $row['debt_vetted_at'] !== null ? (string) $row['debt_vetted_at'] : null,
            'referred_to_penny'     => (int) $row['debt_referred_to_penny'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentContact(array $row): array
    {
        $phones = $this->decodeList($row['phones'] ?? '[]');
        return [
            'id'             => (int) $row['id'],
            'name'           => (string) $row['name'],
            'phones'         => $phones,
            'phones_display' => array_map(static fn (string $p): string => CallGuard::formatPhone($p), $phones),
            'relationship'   => $row['relationship'] !== null ? (string) $row['relationship'] : null,
            'vip_tier'       => $row['vip_tier'] !== null ? (string) $row['vip_tier'] : null,
            'handling_rule'  => $row['handling_rule'] !== null ? (string) $row['handling_rule'] : null,
            'notes'          => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentAgency(array $row): array
    {
        $phones = $this->decodeList($row['phones'] ?? '[]');
        return [
            'id'             => (int) $row['id'],
            'name'           => (string) $row['name'],
            'phones'         => $phones,
            'phones_display' => array_map(static fn (string $p): string => CallGuard::formatPhone($p), $phones),
            'website'        => $row['website'] !== null ? (string) $row['website'] : null,
            'verdict'        => (string) $row['verdict'],
            'notes'          => $row['notes'] !== null ? (string) $row['notes'] : null,
            'evidence_urls'  => $this->decodeList($row['evidence_urls'] ?? '[]'),
            'vetted_at'      => $row['vetted_at'] !== null ? (string) $row['vetted_at'] : null,
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }

    private function displayTime(string $utc): string
    {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone($this->clock->timezone())->format('M j, g:i A');
    }

    /**
     * @param list<string> $allowed
     */
    private function optionalEnum(mixed $value, array $allowed, string $field): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return (string) CallGuard::enum($value, $allowed, $field);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(mixed $from, mixed $to): array
    {
        $start = '1970-01-01 00:00:00';
        $end = '2999-12-31 23:59:59';
        if ($from !== null && $from !== '') {
            $start = CallGuard::timestamp($from, 'from', $this->clock);
        }
        if ($to !== null && $to !== '') {
            $text = trim((string) $to);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
                $text .= ' 23:59:59';
            }
            $end = CallGuard::timestamp($text, 'to', $this->clock);
        }
        return [$start, $end];
    }

    private function limit(mixed $value, int $max): int
    {
        $n = (int) $value;
        if ($n < 1) {
            $n = 50;
        }
        return min($max, $n);
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('strval', $decoded));
    }

    /**
     * @param list<string> $items
     */
    private static function jsonList(array $items): string
    {
        return json_encode(array_values($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
