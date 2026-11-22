<?php

declare(strict_types=1);

namespace Dashboard\Health;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\HabitService;

/**
 * Meals, blood pressure, medicines, and appointments for one user.
 * Device samples stay in HealthService. These rows are what a person logs.
 */
final class HealthLogService
{
    private const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack', 'other'];

    private const ARMS = ['left', 'right'];

    private const POSITIONS = ['sitting', 'standing', 'lying'];

    private const APPOINTMENT_STATUS = ['scheduled', 'completed', 'cancelled', 'missed'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly HabitService $habits,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function logMeal(int $userId, array $input, string $source = 'api'): array
    {
        $date = $this->day($input['date'] ?? $input['day'] ?? null, 'date', true);
        if ($date > $this->clock->today()) {
            throw ValidationException::withFields(['date' => 'Date cannot be in the future.']);
        }
        $type = $this->mealType($input['meal_type'] ?? null);
        $description = $this->requiredText($input['description'] ?? $input['note'] ?? null, 500, 'description');
        $items = self::normalizeItems($input['items'] ?? []);
        $eatenOut = self::flag($input['eaten_out'] ?? false, 'eaten_out');
        $place = $this->optionalText($input['place'] ?? null, 160, 'place');
        $cost = $this->cost($input['cost'] ?? null);
        $id = $this->db->fetchScalar(
            'INSERT INTO meal_logs (user_id, eaten_on, meal_type, description, items, eaten_out, place, cost)
             VALUES (?, ?, ?, ?, ?::jsonb, ?::boolean, ?, ?)
             RETURNING id',
            [
                $userId,
                $date,
                $type,
                $description,
                json_encode($items, JSON_THROW_ON_ERROR),
                $eatenOut ? 'true' : 'false',
                $place,
                $cost,
            ]
        );
        $marked = $this->habits->markForMeal($userId, $date, $type, $eatenOut, $source);

        return [
            'meal'   => $this->meal($this->mustMeal($userId, (int) $id)),
            'habits' => $marked,
        ];
    }

    /**
     * @return array{from: string, to: string, meals: list<array<string, mixed>>}
     */
    public function listMeals(int $userId, ?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to, 13);
        $rows = $this->db->fetchAll(
            'SELECT id, eaten_on, meal_type, description, items, eaten_out, place, cost, created_at
             FROM meal_logs
             WHERE user_id = ? AND eaten_on >= ? AND eaten_on <= ?
             ORDER BY eaten_on DESC, id DESC
             LIMIT 100',
            [$userId, $from, $to]
        );

        return [
            'from'  => $from,
            'to'    => $to,
            'meals' => array_map(fn (array $row): array => $this->meal($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function logBloodPressure(int $userId, array $input): array
    {
        $systolic = $this->whole($input['systolic'] ?? null, 50, 300, 'systolic');
        $diastolic = $this->whole($input['diastolic'] ?? null, 30, 200, 'diastolic');
        if ($systolic <= $diastolic) {
            throw ValidationException::withFields(['systolic' => 'Systolic must be higher than diastolic.']);
        }
        $pulse = $this->optionalWhole($input['pulse'] ?? null, 20, 250, 'pulse');
        $taken = $this->moment($input['taken_at'] ?? null, 'taken_at', true);
        $arm = $this->choice($input['arm'] ?? null, self::ARMS, 'arm');
        $position = $this->choice($input['position'] ?? null, self::POSITIONS, 'position');
        $notes = $this->optionalText($input['notes'] ?? null, 500, 'notes');
        $id = $this->db->fetchScalar(
            'INSERT INTO blood_pressure_readings (user_id, systolic, diastolic, pulse, taken_at, arm, position, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id',
            [$userId, $systolic, $diastolic, $pulse, $taken, $arm, $position, $notes]
        );

        return ['reading' => $this->reading($this->mustReading($userId, (int) $id))];
    }

    /**
     * @return array{from: string, to: string, readings: list<array<string, mixed>>, average: array<string, mixed>}
     */
    public function listBloodPressure(int $userId, ?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to, 29);
        $rows = $this->db->fetchAll(
            'SELECT id, systolic, diastolic, pulse, taken_at, arm, position, notes, created_at
             FROM blood_pressure_readings
             WHERE user_id = ? AND taken_at::date >= ?::date AND taken_at::date <= ?::date
             ORDER BY taken_at DESC, id DESC
             LIMIT 100',
            [$userId, $from, $to]
        );
        $avg = $this->db->fetchOne(
            'SELECT AVG(systolic)::double precision AS systolic,
                    AVG(diastolic)::double precision AS diastolic,
                    AVG(pulse)::double precision AS pulse,
                    COUNT(*) AS n
             FROM blood_pressure_readings
             WHERE user_id = ? AND taken_at::date >= ?::date AND taken_at::date <= ?::date',
            [$userId, $from, $to]
        );
        $readings = array_map(fn (array $row): array => $this->reading($row), $rows);

        return [
            'from'     => $from,
            'to'       => $to,
            'readings' => $readings,
            'average'  => self::averages($avg ?? []),
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{systolic: float|null, diastolic: float|null, pulse: float|null, count: int}
     */
    public static function averages(?array $row): array
    {
        $row = $row ?? [];
        $count = (int) ($row['n'] ?? $row['count'] ?? 0);

        return [
            'systolic'  => $count === 0 || !isset($row['systolic']) || $row['systolic'] === null ? null : round((float) $row['systolic'], 1),
            'diastolic' => $count === 0 || !isset($row['diastolic']) || $row['diastolic'] === null ? null : round((float) $row['diastolic'], 1),
            'pulse'     => $count === 0 || !isset($row['pulse']) || $row['pulse'] === null ? null : round((float) $row['pulse'], 1),
            'count'     => $count,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addMedication(int $userId, array $input): array
    {
        $fields = $this->medicationFields($input, true);
        $id = $this->db->fetchScalar(
            'INSERT INTO medications (user_id, name, dose, schedule, active, started_on, stopped_on, notes)
             VALUES (?, ?, ?, ?, ?::boolean, ?, ?, ?)
             RETURNING id',
            [$userId, $fields['name'], $fields['dose'], $fields['schedule'], $fields['active'] ? 'true' : 'false', $fields['started_on'], $fields['stopped_on'], $fields['notes']]
        );

        return ['medication' => $this->medication($this->mustMedication($userId, (int) $id))];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateMedication(int $userId, int $id, array $input): array
    {
        $current = $this->medication($this->mustMedication($userId, $id));
        $merged = $input;
        foreach (['name', 'dose', 'schedule', 'active', 'started_on', 'stopped_on', 'notes'] as $key) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $current[$key];
            }
        }
        $fields = $this->medicationFields($merged, true);
        $this->db->execute(
            'UPDATE medications
             SET name = ?, dose = ?, schedule = ?, active = ?::boolean, started_on = ?, stopped_on = ?, notes = ?,
                 updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [$fields['name'], $fields['dose'], $fields['schedule'], $fields['active'] ? 'true' : 'false', $fields['started_on'], $fields['stopped_on'], $fields['notes'], $id, $userId]
        );

        return ['medication' => $this->medication($this->mustMedication($userId, $id))];
    }

    /**
     * @return array{medications: list<array<string, mixed>>}
     */
    public function listMedications(int $userId, ?bool $active): array
    {
        $flag = $active === null ? '' : ($active ? '1' : '0');
        $rows = $this->db->fetchAll(
            'SELECT id, name, dose, schedule, active, started_on, stopped_on, notes, created_at, updated_at
             FROM medications
             WHERE user_id = ? AND (? = \'\' OR active = (? = \'1\'))
             ORDER BY active DESC, name ASC, id ASC
             LIMIT 200',
            [$userId, $flag, $flag]
        );

        return ['medications' => array_map(fn (array $row): array => $this->medication($row), $rows)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function logDose(int $userId, int $medicationId, array $input): array
    {
        $this->mustMedication($userId, $medicationId);
        $taken = $this->moment($input['taken_at'] ?? null, 'taken_at', true);
        $notes = $this->optionalText($input['notes'] ?? null, 500, 'notes');
        $id = $this->db->fetchScalar(
            'INSERT INTO medication_doses (user_id, medication_id, taken_at, notes)
             VALUES (?, ?, ?, ?)
             RETURNING id',
            [$userId, $medicationId, $taken, $notes]
        );

        return ['dose' => $this->dose($this->mustDose($userId, (int) $id))];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addAppointment(int $userId, array $input): array
    {
        $fields = $this->appointmentFields($input, true);
        $id = $this->db->fetchScalar(
            'INSERT INTO appointments (user_id, provider, specialty, starts_at, location, reason, status, follow_up)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id',
            [$userId, $fields['provider'], $fields['specialty'], $fields['starts_at'], $fields['location'], $fields['reason'], $fields['status'], $fields['follow_up']]
        );

        return ['appointment' => $this->appointment($this->mustAppointment($userId, (int) $id))];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateAppointment(int $userId, int $id, array $input): array
    {
        $current = $this->appointment($this->mustAppointment($userId, $id));
        $merged = $input + [
            'provider'  => $current['provider'],
            'specialty' => $current['specialty'],
            'location'  => $current['location'],
            'reason'    => $current['reason'],
            'status'    => $current['status'],
            'follow_up' => $current['follow_up'],
        ];
        $changingTime = array_key_exists('starts_at', $input) || array_key_exists('datetime', $input) || array_key_exists('at', $input);
        if ($changingTime) {
            unset($merged['starts_at']);
        } else {
            $merged['starts_at'] = $current['starts_at'];
        }
        $fields = $this->appointmentFields($merged, true);
        if (!$changingTime) {
            // The stored clock time is already UTC. Parsing it again would shift the zone.
            $fields['starts_at'] = (string) $current['starts_at'];
        }
        $this->db->execute(
            'UPDATE appointments
             SET provider = ?, specialty = ?, starts_at = ?, location = ?, reason = ?, status = ?, follow_up = ?,
                 updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [$fields['provider'], $fields['specialty'], $fields['starts_at'], $fields['location'], $fields['reason'], $fields['status'], $fields['follow_up'], $id, $userId]
        );

        return ['appointment' => $this->appointment($this->mustAppointment($userId, $id))];
    }

    /**
     * @return array{appointments: list<array<string, mixed>>}
     */
    public function listAppointments(int $userId, bool $upcoming, ?string $from, ?string $to): array
    {
        if ($upcoming) {
            $rows = $this->db->fetchAll(
                'SELECT id, provider, specialty, starts_at, location, reason, status, follow_up, created_at, updated_at
                 FROM appointments
                 WHERE user_id = ? AND status = \'scheduled\' AND starts_at >= ?
                 ORDER BY starts_at ASC, id ASC
                 LIMIT 100',
                [$userId, $this->clock->utcString($this->clock->now())]
            );
        } else {
            [$from, $to] = $this->range($from, $to, 30);
            $rows = $this->db->fetchAll(
                'SELECT id, provider, specialty, starts_at, location, reason, status, follow_up, created_at, updated_at
                 FROM appointments
                 WHERE user_id = ? AND starts_at::date >= ?::date AND starts_at::date <= ?::date
                 ORDER BY starts_at ASC, id ASC
                 LIMIT 100',
                [$userId, $from, $to]
            );
        }

        return ['appointments' => array_map(fn (array $row): array => $this->appointment($row), $rows)];
    }

    /**
     * @return list<array{name: string, quantity: string|null}>
     */
    public static function normalizeItems(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw ValidationException::withFields(['items' => 'Items must be a list.']);
        }
        if (count($raw) > 40) {
            throw ValidationException::withFields(['items' => 'At most 40 items.']);
        }
        $out = [];
        foreach ($raw as $i => $item) {
            if (is_string($item) || is_numeric($item)) {
                $name = trim((string) $item);
                $quantity = null;
            } elseif (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                $quantity = isset($item['quantity']) && $item['quantity'] !== '' ? trim((string) $item['quantity']) : null;
            } else {
                throw ValidationException::withFields(["items.{$i}" => 'Each item needs a name.']);
            }
            if ($name === '' || mb_strlen($name) > 120) {
                throw ValidationException::withFields(["items.{$i}" => 'Each item name must be 1–120 characters.']);
            }
            if ($quantity !== null && mb_strlen($quantity) > 40) {
                throw ValidationException::withFields(["items.{$i}" => 'Quantity must be at most 40 characters.']);
            }
            $out[] = ['name' => $name, 'quantity' => $quantity];
        }

        return $out;
    }

    public static function flag(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === 'false' || $value === 'f' || $value === 'no') {
            return false;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 't' || $value === 'yes') {
            return true;
        }
        throw ValidationException::withFields([$field => $field . ' must be true or false.']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, dose: string|null, schedule: string|null, active: bool, started_on: string|null, stopped_on: string|null, notes: string|null}
     */
    private function medicationFields(array $input, bool $requireName): array
    {
        $name = $requireName
            ? $this->requiredText($input['name'] ?? null, 160, 'name')
            : $this->optionalText($input['name'] ?? null, 160, 'name');
        if ($name === null) {
            throw ValidationException::withFields(['name' => 'Name is required.']);
        }
        $started = $this->optionalDay($input['started_on'] ?? null, 'started_on');
        $stopped = $this->optionalDay($input['stopped_on'] ?? null, 'stopped_on');
        if ($started !== null && $stopped !== null && $stopped < $started) {
            throw ValidationException::withFields(['stopped_on' => 'Stop date cannot be before the start date.']);
        }

        return [
            'name'       => $name,
            'dose'       => $this->optionalText($input['dose'] ?? null, 80, 'dose'),
            'schedule'   => $this->optionalText($input['schedule'] ?? null, 160, 'schedule'),
            'active'     => array_key_exists('active', $input) ? self::flag($input['active'], 'active') : true,
            'started_on' => $started,
            'stopped_on' => $stopped,
            'notes'      => $this->optionalText($input['notes'] ?? null, 500, 'notes'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{provider: string, specialty: string|null, starts_at: string, location: string|null, reason: string|null, status: string, follow_up: string|null}
     */
    private function appointmentFields(array $input, bool $requireProvider): array
    {
        $provider = $this->requiredText($input['provider'] ?? null, 160, 'provider');
        if (!$requireProvider && $provider === '') {
            throw ValidationException::withFields(['provider' => 'Provider is required.']);
        }
        $status = $input['status'] ?? 'scheduled';
        $status = strtolower(trim((string) $status));
        if (!in_array($status, self::APPOINTMENT_STATUS, true)) {
            throw ValidationException::withFields(['status' => 'Status must be scheduled, completed, cancelled, or missed.']);
        }
        $when = $input['starts_at'] ?? $input['datetime'] ?? $input['at'] ?? null;

        return [
            'provider'  => $provider,
            'specialty' => $this->optionalText($input['specialty'] ?? null, 80, 'specialty'),
            'starts_at' => $this->moment($when, 'starts_at', false),
            'location'  => $this->optionalText($input['location'] ?? null, 200, 'location'),
            'reason'    => $this->optionalText($input['reason'] ?? null, 500, 'reason'),
            'status'    => $status,
            'follow_up' => $this->optionalText($input['follow_up'] ?? null, 1000, 'follow_up'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function meal(array $row): array
    {
        $items = is_string($row['items'] ?? null) ? json_decode((string) $row['items'], true) : ($row['items'] ?? []);

        return [
            'id'          => (int) $row['id'],
            'date'        => (string) $row['eaten_on'],
            'meal_type'   => (string) $row['meal_type'],
            'description' => (string) $row['description'],
            'items'       => is_array($items) ? $items : [],
            'eaten_out'   => self::flag($row['eaten_out'] ?? false, 'eaten_out'),
            'place'       => $row['place'] !== null ? (string) $row['place'] : null,
            'cost'        => $row['cost'] !== null ? round((float) $row['cost'], 2) : null,
            'created_at'  => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function reading(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'systolic'   => (int) $row['systolic'],
            'diastolic'  => (int) $row['diastolic'],
            'pulse'      => $row['pulse'] !== null ? (int) $row['pulse'] : null,
            'taken_at'   => (string) $row['taken_at'],
            'arm'        => $row['arm'] !== null ? (string) $row['arm'] : null,
            'position'   => $row['position'] !== null ? (string) $row['position'] : null,
            'notes'      => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function medication(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'dose'       => $row['dose'] !== null ? (string) $row['dose'] : null,
            'schedule'   => $row['schedule'] !== null ? (string) $row['schedule'] : null,
            'active'     => self::flag($row['active'] ?? false, 'active'),
            'started_on' => $row['started_on'] !== null ? (string) $row['started_on'] : null,
            'stopped_on' => $row['stopped_on'] !== null ? (string) $row['stopped_on'] : null,
            'notes'      => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function dose(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'medication_id' => (int) $row['medication_id'],
            'taken_at'      => (string) $row['taken_at'],
            'notes'         => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at'    => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function appointment(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'provider'   => (string) $row['provider'],
            'specialty'  => $row['specialty'] !== null ? (string) $row['specialty'] : null,
            'starts_at'  => (string) $row['starts_at'],
            'location'   => $row['location'] !== null ? (string) $row['location'] : null,
            'reason'     => $row['reason'] !== null ? (string) $row['reason'] : null,
            'status'     => (string) $row['status'],
            'follow_up'  => $row['follow_up'] !== null ? (string) $row['follow_up'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mustMeal(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, eaten_on, meal_type, description, items, eaten_out, place, cost, created_at
             FROM meal_logs WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Meal not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function mustReading(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, systolic, diastolic, pulse, taken_at, arm, position, notes, created_at
             FROM blood_pressure_readings WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Reading not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function mustMedication(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, dose, schedule, active, started_on, stopped_on, notes, created_at, updated_at
             FROM medications WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Medication not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function mustDose(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, medication_id, taken_at, notes, created_at
             FROM medication_doses WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Dose not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function mustAppointment(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, provider, specialty, starts_at, location, reason, status, follow_up, created_at, updated_at
             FROM appointments WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Appointment not found.');
        }

        return $row;
    }

    private function mealType(mixed $value): string
    {
        $type = strtolower(trim((string) $value));
        if (!in_array($type, self::MEAL_TYPES, true)) {
            throw ValidationException::withFields(['meal_type' => 'Meal type must be breakfast, lunch, dinner, snack, or other.']);
        }

        return $type;
    }

    private function requiredText(mixed $value, int $max, string $field): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => ucfirst(str_replace('_', ' ', $field)) . " must be 1–{$max} characters."]);
        }

        return $text;
    }

    private function optionalText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => ucfirst(str_replace('_', ' ', $field)) . " must be at most {$max} characters."]);
        }

        return $text;
    }

    private function cost(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100000) {
            throw ValidationException::withFields(['cost' => 'Cost must be from 0 to 100000, or empty.']);
        }

        return round((float) $value, 2);
    }

    private function whole(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_numeric($value) || (float) $value != (int) $value) {
            throw ValidationException::withFields([$field => ucfirst($field) . " must be a whole number from {$min} to {$max}."]);
        }
        $n = (int) $value;
        if ($n < $min || $n > $max) {
            throw ValidationException::withFields([$field => ucfirst($field) . " must be a whole number from {$min} to {$max}."]);
        }

        return $n;
    }

    private function optionalWhole(mixed $value, int $min, int $max, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->whole($value, $min, $max, $field);
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = strtolower(trim((string) $value));
        if (!in_array($text, $allowed, true)) {
            throw ValidationException::withFields([$field => ucfirst($field) . ' must be ' . implode(', ', $allowed) . '.']);
        }

        return $text;
    }

    private function day(mixed $value, string $field, bool $defaultToday): string
    {
        if (($value === null || $value === '') && $defaultToday) {
            return $this->clock->today();
        }
        try {
            return Clock::normalizeDate(trim((string) $value));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withFields([$field => $e->getMessage()]);
        }
    }

    private function optionalDay(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->day($value, $field, false);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(?string $from, ?string $to, int $back): array
    {
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            $to = $this->clock->today();
            $from = Clock::shiftDate($to, $back);
        }
        $from = $this->day($from, 'from', false);
        $to = $this->day($to, 'to', false);
        if ($from > $to) {
            throw ValidationException::withFields(['from' => 'The start day is after the end day.']);
        }

        return [$from, $to];
    }

    private function moment(mixed $value, string $field, bool $defaultNow): string
    {
        if (($value === null || $value === '') && $defaultNow) {
            return $this->clock->utcString($this->clock->now());
        }
        if ($value === null || $value === '') {
            throw ValidationException::withFields([$field => 'A date and time is required.']);
        }
        try {
            $parsed = $this->clock->parse((string) $value);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withFields([$field => $e->getMessage()]);
        }
        if ($defaultNow && $parsed > $this->clock->now()->modify('+5 minutes')) {
            throw ValidationException::withFields([$field => 'Time cannot be in the future.']);
        }

        return $this->clock->utcString($parsed);
    }
}
