<?php

declare(strict_types=1);

namespace Dashboard\Jarvis;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Settings wizard storage and the routine pass invoked by lifedash-job routines.
 */
final class Wizard
{
    /** @var list<array{key: string, label: string, title: string, lead: string}> */
    public const STEPS = [
        [
            'key'   => 'identity',
            'label' => 'Identity',
            'title' => 'Define how we work together.',
            'lead'  => 'Set your identity and the way your assistant communicates.',
        ],
        [
            'key'   => 'voice',
            'label' => 'Voice',
            'title' => 'Choose the voice you will trust.',
            'lead'  => 'Choose a voice profile and send a short recording to speech.',
        ],
        [
            'key'   => 'routines',
            'label' => 'Routines',
            'title' => 'Make intelligence proactive.',
            'lead'  => 'Choose when JARVIS should prepare, optimize and assist.',
        ],
        [
            'key'   => 'privacy',
            'label' => 'Privacy',
            'title' => 'You remain in control.',
            'lead'  => 'Control how information is accessed, remembered and processed.',
        ],
        [
            'key'   => 'initialize',
            'label' => 'Initialize',
            'title' => 'Ready for initialization.',
            'lead'  => 'Review the protocol and bring your system online.',
        ],
    ];

    /** @var array<string, array{label: string, detail: string, mode: string, enabled: bool}> */
    public const ROUTINES = [
        'morning_brief' => [
            'label'   => 'Morning intelligence brief',
            'detail'  => 'Runs automatically',
            'mode'    => 'automatic',
            'enabled' => true,
        ],
        'meeting_prep' => [
            'label'   => 'Meeting preparation',
            'detail'  => 'Triggered when needed',
            'mode'    => 'triggered',
            'enabled' => true,
        ],
        'focus_mode' => [
            'label'   => 'Adaptive focus mode',
            'detail'  => 'Runs automatically',
            'mode'    => 'automatic',
            'enabled' => true,
        ],
        'day_summary' => [
            'label'   => 'End-of-day summary',
            'detail'  => 'Triggered when needed',
            'mode'    => 'triggered',
            'enabled' => false,
        ],
    ];

    /** @var list<string> */
    public const STYLES = ['concise', 'conversational', 'analytical'];

    /** @var list<string> */
    public const VOICES = ['atlas', 'nova', 'orion'];

    /** @var array<string, array{label: string, options: array<string, string>, default: string}> */
    public const PRIVACY = [
        'calendar_context' => [
            'label'   => 'Calendar context',
            'options' => ['off' => 'Off', 'allowed' => 'Allowed'],
            'default' => 'allowed',
        ],
        'microphone' => [
            'label'   => 'Microphone',
            'options' => ['off' => 'Off', 'while_active' => 'While active'],
            'default' => 'while_active',
        ],
        'memory' => [
            'label'   => 'Personal memory',
            'options' => ['off' => 'Off', 'review' => 'Review first', 'allowed' => 'Allowed'],
            'default' => 'review',
        ],
        'proactive' => [
            'label'   => 'Proactive actions',
            'options' => ['ask' => 'Ask permission', 'allowed' => 'Allowed'],
            'default' => 'ask',
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    public static function greeting(int $hour, string $name, string $style): string
    {
        $part = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
        $tail = match ($style) {
            'concise' => 'I will keep it short.',
            'conversational' => 'I will keep things conversational.',
            default => 'I will keep things analytical, clear and focused.',
        };

        return "Good {$part}, {$name}. {$tail}";
    }

    /**
     * @return array{display_name: string, assistant_name: string, style: string}
     */
    public static function checkedIdentity(string $displayName, string $assistantName, string $style): array
    {
        $name = self::singleLine($displayName);
        $assistant = self::singleLine($assistantName);
        $fields = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $fields['display_name'] = 'Use a name up to 80 characters.';
        }
        if ($assistant === '' || mb_strlen($assistant) > 40) {
            $fields['assistant_name'] = 'Use an assistant name up to 40 characters.';
        }
        if (!in_array($style, self::STYLES, true)) {
            $fields['style'] = 'Choose concise, conversational, or analytical.';
        }
        if ($fields !== []) {
            throw ValidationException::withFields($fields);
        }

        return [
            'display_name'   => $name,
            'assistant_name' => $assistant,
            'style'          => $style,
        ];
    }

    public static function checkedVoice(string $profile): string
    {
        if (!in_array($profile, self::VOICES, true)) {
            throw ValidationException::withFields(['profile' => 'Choose Atlas, Nova, or Orion.']);
        }

        return $profile;
    }

    /**
     * @param array<mixed, mixed> $input
     * @return array<string, bool>
     */
    public static function checkedRoutines(array $input): array
    {
        $enabled = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (!isset(self::ROUTINES[$key])) {
                throw ValidationException::withFields(['routines' => 'Unknown routine.']);
            }
            $enabled[$key] = self::flag($value);
        }
        foreach (self::ROUTINES as $key => $ignored) {
            $enabled[$key] = $enabled[$key] ?? false;
        }

        return $enabled;
    }

    /**
     * @param array<mixed, mixed> $input
     * @return array{calendar_context: string, microphone: string, memory: string, proactive: string}
     */
    public static function checkedPrivacy(array $input): array
    {
        $out = [];
        $fields = [];
        foreach (self::PRIVACY as $key => $def) {
            $value = isset($input[$key]) ? (string) $input[$key] : '';
            if (!isset($def['options'][$value])) {
                $fields[$key] = 'Choose a listed ' . strtolower($def['label']) . ' setting.';
                continue;
            }
            $out[$key] = $value;
        }
        if ($fields !== []) {
            throw ValidationException::withFields($fields);
        }

        return [
            'calendar_context' => $out['calendar_context'],
            'microphone'       => $out['microphone'],
            'memory'           => $out['memory'],
            'proactive'        => $out['proactive'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(int $userId, string $username): array
    {
        $profile = $this->profile($userId);
        $routines = $this->routineRows($userId);
        $privacy = $this->privacyRow($userId);
        [, , $localDate] = $this->localDayUtc();
        $runs = $this->runs($userId, $localDate);
        $voice = (string) ($profile['voice_profile'] ?? '');

        return [
            'hour'     => (int) $this->localNow()->format('G'),
            'username' => $username,
            'identity' => [
                'display_name'   => (string) ($profile['display_name'] ?? $username),
                'assistant_name' => (string) ($profile['assistant_name'] ?? 'JARVIS'),
                'style'          => (string) ($profile['style'] ?? 'analytical'),
                'saved'          => $profile !== null,
            ],
            'voice' => [
                'profile' => $voice,
                'saved'   => $voice !== '',
            ],
            'routines'       => $this->routineView($routines, $runs),
            'routines_saved' => $routines !== [],
            'privacy'        => $this->privacyView($privacy),
            'initialize'     => [
                'ready'        => $profile !== null && $voice !== '' && $routines !== [] && $privacy !== null,
                'initialized'  => $profile !== null && ($profile['initialized_at'] ?? null) !== null && (string) $profile['initialized_at'] !== '',
                'checks'       => $this->checks($userId, $profile, $routines, $privacy),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveIdentity(int $userId, string $displayName, string $assistantName, string $style, string $username): array
    {
        $clean = self::checkedIdentity($displayName, $assistantName, $style);
        $this->db->execute(
            "INSERT INTO jarvis_profiles (user_id, display_name, assistant_name, style, voice_profile)
             VALUES (?, ?, ?, ?, '')
             ON CONFLICT (user_id) DO UPDATE
             SET display_name = EXCLUDED.display_name,
                 assistant_name = EXCLUDED.assistant_name,
                 style = EXCLUDED.style,
                 updated_at = (now() AT TIME ZONE 'UTC')",
            [$userId, $clean['display_name'], $clean['assistant_name'], $clean['style']]
        );

        return $this->state($userId, $username);
    }

    /**
     * @return array<string, mixed>
     */
    public function saveVoice(int $userId, string $profile, string $username): array
    {
        $profile = self::checkedVoice($profile);
        if ($this->profile($userId) === null) {
            throw ValidationException::withFields(['profile' => 'Save your name first.']);
        }
        $this->db->execute(
            "UPDATE jarvis_profiles
             SET voice_profile = ?, updated_at = (now() AT TIME ZONE 'UTC')
             WHERE user_id = ?",
            [$profile, $userId]
        );

        return $this->state($userId, $username);
    }

    /**
     * @param array<mixed, mixed> $input
     * @return array<string, mixed>
     */
    public function saveRoutines(int $userId, array $input, string $username): array
    {
        $enabled = self::checkedRoutines($input);
        $this->db->transaction(function (Database $db) use ($userId, $enabled): void {
            foreach (self::ROUTINES as $key => $def) {
                $db->execute(
                    "INSERT INTO jarvis_routines (user_id, routine_key, enabled, mode)
                     VALUES (?, ?, ?, ?)
                     ON CONFLICT (user_id, routine_key) DO UPDATE
                     SET enabled = EXCLUDED.enabled,
                         mode = EXCLUDED.mode,
                         updated_at = (now() AT TIME ZONE 'UTC')",
                    [$userId, $key, $enabled[$key] ? 1 : 0, $def['mode']]
                );
            }
        });

        return $this->state($userId, $username);
    }

    /**
     * @param array<mixed, mixed> $input
     * @return array<string, mixed>
     */
    public function savePrivacy(int $userId, array $input, string $username): array
    {
        $clean = self::checkedPrivacy($input);
        $this->db->execute(
            "INSERT INTO jarvis_privacy (user_id, calendar_context, microphone, memory, proactive)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE
             SET calendar_context = EXCLUDED.calendar_context,
                 microphone = EXCLUDED.microphone,
                 memory = EXCLUDED.memory,
                 proactive = EXCLUDED.proactive,
                 updated_at = (now() AT TIME ZONE 'UTC')",
            [$userId, $clean['calendar_context'], $clean['microphone'], $clean['memory'], $clean['proactive']]
        );

        return $this->state($userId, $username);
    }

    /**
     * @return array<string, mixed>
     */
    public function initialize(int $userId, string $username): array
    {
        if ($this->profile($userId) === null) {
            throw ValidationException::withFields(['identity' => 'Save your name first.']);
        }
        $this->db->execute(
            "UPDATE jarvis_profiles
             SET initialized_at = COALESCE(initialized_at, (now() AT TIME ZONE 'UTC')),
                 updated_at = (now() AT TIME ZONE 'UTC')
             WHERE user_id = ?",
            [$userId]
        );

        return $this->state($userId, $username);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmRoutine(int $userId, string $key, string $username): array
    {
        if (!isset(self::ROUTINES[$key])) {
            throw ValidationException::withFields(['routine' => 'Unknown routine.']);
        }
        $row = $this->db->fetchOne(
            'SELECT enabled FROM jarvis_routines WHERE user_id = ? AND routine_key = ?',
            [$userId, $key]
        );
        if ($row === null || (int) $row['enabled'] !== 1) {
            throw ValidationException::withFields(['routine' => 'Turn the routine on first.']);
        }
        $this->runOne($userId, $key, true);

        return $this->state($userId, $username);
    }

    /**
     * @return array{ran: int, waiting: int}
     */
    public function runDue(?int $onlyUserId = null): array
    {
        if ($onlyUserId !== null) {
            $users = $this->db->fetchAll(
                'SELECT DISTINCT user_id FROM jarvis_routines WHERE enabled = 1 AND user_id = ?',
                [$onlyUserId]
            );
        } else {
            $users = $this->db->fetchAll(
                'SELECT DISTINCT user_id FROM jarvis_routines WHERE enabled = 1'
            );
        }

        $counts = ['ran' => 0, 'waiting' => 0];
        foreach ($users as $user) {
            $userId = (int) $user['user_id'];
            $rows = $this->routineRows($userId);
            foreach (self::ROUTINES as $key => $ignored) {
                $row = $rows[$key] ?? null;
                if ($row === null || (int) $row['enabled'] !== 1) {
                    continue;
                }
                $result = $this->runOne($userId, $key, false);
                if (isset($counts[$result])) {
                    $counts[$result]++;
                }
            }
        }

        return $counts;
    }

    private function runOne(int $userId, string $key, bool $force): string
    {
        $local = $this->localNow();
        if (!$force && !$this->inWindow($key, $local)) {
            return 'skipped';
        }

        [, , $localDate] = $this->localDayUtc();
        $existing = $this->db->fetchScalar(
            'SELECT status FROM jarvis_routine_runs WHERE user_id = ? AND routine_key = ? AND local_date = ?',
            [$userId, $key, $localDate]
        );
        if ($existing === 'ran') {
            return 'skipped';
        }

        $privacy = $this->effectivePrivacy($userId);
        if (!$force && !$this->hasWork($userId, $key, $privacy)) {
            return 'skipped';
        }

        $ask = !$force && $privacy['proactive'] === 'ask';
        if ($ask && $existing === 'waiting') {
            return 'skipped';
        }

        if ($ask) {
            $status = 'waiting';
            $summary = 'Waiting for permission to run.';
        } else {
            $real = $this->summaryFor($userId, $key, $privacy);
            $status = 'ran';
            $summary = $real !== '' ? $real : $this->emptyLine($key);
        }
        $this->db->execute(
            "INSERT INTO jarvis_routine_runs (user_id, routine_key, local_date, status, summary)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (user_id, routine_key, local_date) DO UPDATE
             SET status = EXCLUDED.status, summary = EXCLUDED.summary
             WHERE jarvis_routine_runs.status = 'waiting'",
            [$userId, $key, $localDate, $status, mb_substr($summary, 0, 2000)]
        );

        return $status;
    }

    private function inWindow(string $key, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');
        $weekday = (int) $local->format('N') <= 5;

        return match ($key) {
            'morning_brief' => $hour >= 6 && $hour < 10,
            'focus_mode' => $weekday && $hour >= 9 && $hour < 17,
            'day_summary' => $hour >= 17,
            'meeting_prep' => true,
            default => false,
        };
    }

    /**
     * @param array{calendar_context: string, microphone: string, memory: string, proactive: string, saved: bool} $privacy
     */
    private function hasWork(int $userId, string $key, array $privacy): bool
    {
        if ($key === 'meeting_prep') {
            return $privacy['calendar_context'] === 'allowed' && $this->agendaCount($userId) > 0;
        }
        if ($key === 'day_summary') {
            return $this->finishedCount($userId) > 0;
        }

        return true;
    }

    private function agendaCount(int $userId): int
    {
        [$start, $end, $localDate] = $this->localDayUtc();
        $due = (int) $this->db->fetchScalar(
            "SELECT COUNT(*)
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE l.user_id = ? AND i.due_date = ? AND i.status = 'open' AND i.archived_at IS NULL",
            [$userId, $localDate]
        );
        $flights = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_flight_watches WHERE user_id = ? AND departure_at >= ? AND departure_at < ?',
            [$userId, $start, $end]
        );

        return $due + $flights;
    }

    private function finishedCount(int $userId): int
    {
        [$start, $end, $localDate] = $this->localDayUtc();
        $done = (int) $this->db->fetchScalar(
            "SELECT COUNT(*)
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE l.user_id = ? AND i.status = 'completed' AND i.completed_at >= ? AND i.completed_at < ?",
            [$userId, $start, $end]
        );
        $habits = (int) $this->db->fetchScalar(
            "SELECT COUNT(*)
             FROM datapoints d
             JOIN metrics m ON m.id = d.metric_id
             WHERE d.user_id = ? AND d.local_date = ? AND m.kind = 'habit'
               AND m.archived_at IS NULL AND d.value >= 1",
            [$userId, $localDate]
        );

        return $done + $habits;
    }

    /**
     * Titles are read only when a routine is allowed to run, not while it is waiting.
     *
     * @param array{calendar_context: string, microphone: string, memory: string, proactive: string, saved: bool} $privacy
     */
    private function summaryFor(int $userId, string $key, array $privacy): string
    {
        return match ($key) {
            'morning_brief' => $this->morningSummary($userId, $privacy),
            'meeting_prep' => $this->meetingSummary($userId, $privacy),
            'focus_mode' => $this->focusSummary($userId),
            'day_summary' => $this->daySummary($userId),
            default => '',
        };
    }

    /**
     * @param array{calendar_context: string, microphone: string, memory: string, proactive: string, saved: bool} $privacy
     */
    private function morningSummary(int $userId, array $privacy): string
    {
        $since = $this->clock->now()->modify('-36 hours')->format('Y-m-d H:i:s');
        $parts = [];
        $digest = $this->column($this->db->fetchAll(
            'SELECT title FROM digest_entries WHERE user_id = ? AND first_seen_at >= ? ORDER BY first_seen_at DESC LIMIT 5',
            [$userId, $since]
        ));
        if ($digest !== []) {
            $parts[] = 'Digest: ' . implode('; ', $digest);
        }
        if ($privacy['calendar_context'] === 'allowed') {
            $agenda = $this->agendaTitles($userId);
            if ($agenda !== []) {
                $parts[] = 'Agenda: ' . implode('; ', $agenda);
            }
        }
        if ($privacy['memory'] === 'allowed') {
            $notes = $this->column($this->db->fetchAll(
                'SELECT title FROM pages WHERE user_id = ? AND archived_at IS NULL ORDER BY updated_at DESC LIMIT 5',
                [$userId]
            ));
            if ($notes !== []) {
                $parts[] = 'Notes: ' . implode('; ', $notes);
            }
        } elseif ($privacy['memory'] === 'review') {
            $held = (int) $this->db->fetchScalar(
                'SELECT COUNT(*) FROM pages WHERE user_id = ? AND archived_at IS NULL',
                [$userId]
            );
            if ($held > 0) {
                $parts[] = 'Notes held for review: ' . $held;
            }
        }
        if ($parts === []) {
            return '';
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * @param array{calendar_context: string, microphone: string, memory: string, proactive: string, saved: bool} $privacy
     */
    private function meetingSummary(int $userId, array $privacy): string
    {
        if ($privacy['calendar_context'] !== 'allowed') {
            return '';
        }
        $items = $this->agendaTitles($userId);

        return $items === [] ? '' : 'Agenda: ' . implode('; ', $items) . '.';
    }

    private function focusSummary(int $userId): string
    {
        $title = $this->column($this->db->fetchAll(
            "SELECT i.title
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE l.user_id = ? AND i.status = 'open' AND i.archived_at IS NULL
             ORDER BY i.sort_order, i.id
             LIMIT 1",
            [$userId]
        ));

        return $title === [] ? '' : 'Focus: ' . $title[0] . '.';
    }

    private function daySummary(int $userId): string
    {
        [$start, $end, $localDate] = $this->localDayUtc();
        $done = $this->column($this->db->fetchAll(
            "SELECT i.title
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE l.user_id = ? AND i.status = 'completed' AND i.completed_at >= ? AND i.completed_at < ?
             ORDER BY i.completed_at
             LIMIT 8",
            [$userId, $start, $end]
        ));
        $habits = $this->column($this->db->fetchAll(
            "SELECT m.name AS title
             FROM datapoints d
             JOIN metrics m ON m.id = d.metric_id
             WHERE d.user_id = ? AND d.local_date = ? AND m.kind = 'habit'
               AND m.archived_at IS NULL AND d.value >= 1
             ORDER BY m.sort_order
             LIMIT 8",
            [$userId, $localDate]
        ));
        $parts = [];
        if ($done !== []) {
            $parts[] = 'Finished: ' . implode('; ', $done);
        }
        if ($habits !== []) {
            $parts[] = 'Habits: ' . implode('; ', $habits);
        }

        return $parts === [] ? '' : implode('. ', $parts) . '.';
    }

    /**
     * @return list<string>
     */
    private function agendaTitles(int $userId): array
    {
        [$start, $end, $localDate] = $this->localDayUtc();
        $due = $this->column($this->db->fetchAll(
            "SELECT i.title
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE l.user_id = ? AND i.due_date = ? AND i.status = 'open' AND i.archived_at IS NULL
             ORDER BY i.id
             LIMIT 8",
            [$userId, $localDate]
        ));
        $flights = [];
        foreach ($this->db->fetchAll(
            'SELECT ident, origin_display, dest_display
             FROM user_flight_watches
             WHERE user_id = ? AND departure_at >= ? AND departure_at < ?
             ORDER BY departure_at
             LIMIT 8',
            [$userId, $start, $end]
        ) as $row) {
            $ident = trim((string) ($row['ident'] ?? ''));
            $from = trim((string) ($row['origin_display'] ?? ''));
            $to = trim((string) ($row['dest_display'] ?? ''));
            if ($ident === '') {
                continue;
            }
            $flights[] = ($from !== '' && $to !== '') ? $ident . ' ' . $from . ' to ' . $to : $ident;
        }

        return array_merge($due, $flights);
    }

    private function emptyLine(string $key): string
    {
        return match ($key) {
            'morning_brief' => 'Nothing new this morning.',
            'focus_mode' => 'No open task to focus.',
            'meeting_prep' => 'Nothing is due.',
            'day_summary' => 'Nothing finished today.',
            default => '',
        };
    }

    /**
     * Unsaved privacy does not grant calendar or memory access, and does not run routines alone.
     *
     * @return array{calendar_context: string, microphone: string, memory: string, proactive: string, saved: bool}
     */
    private function effectivePrivacy(int $userId): array
    {
        $row = $this->privacyRow($userId);
        if ($row === null) {
            return [
                'calendar_context' => 'off',
                'microphone'       => 'while_active',
                'memory'           => 'off',
                'proactive'        => 'ask',
                'saved'            => false,
            ];
        }

        return [
            'calendar_context' => (string) $row['calendar_context'],
            'microphone'       => (string) $row['microphone'],
            'memory'           => (string) $row['memory'],
            'proactive'        => (string) $row['proactive'],
            'saved'            => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profile(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT display_name, assistant_name, style, voice_profile, initialized_at
             FROM jarvis_profiles WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routineRows(int $userId): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT routine_key, enabled, mode FROM jarvis_routines WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $out[(string) $row['routine_key']] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function privacyRow(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT calendar_context, microphone, memory, proactive FROM jarvis_privacy WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runs(int $userId, string $localDate): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT routine_key, status, summary FROM jarvis_routine_runs WHERE user_id = ? AND local_date = ?',
            [$userId, $localDate]
        ) as $row) {
            $out[(string) $row['routine_key']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $runs
     * @return list<array<string, mixed>>
     */
    private function routineView(array $rows, array $runs): array
    {
        $view = [];
        foreach (self::ROUTINES as $key => $def) {
            $row = $rows[$key] ?? null;
            $run = $runs[$key] ?? null;
            $view[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'detail'      => $def['detail'],
                'mode'        => $def['mode'],
                'enabled'     => $row !== null ? (int) $row['enabled'] === 1 : $def['enabled'],
                'run_status'  => $run !== null ? (string) $run['status'] : '',
                'run_summary' => $run !== null ? (string) $run['summary'] : '',
            ];
        }

        return $view;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function privacyView(?array $row): array
    {
        $view = ['saved' => $row !== null];
        foreach (self::PRIVACY as $key => $def) {
            $view[$key] = $row !== null ? (string) $row[$key] : $def['default'];
            $view[$key . '_label'] = self::PRIVACY[$key]['options'][$view[$key]];
        }

        return $view;
    }

    /**
     * @param array<string, mixed>|null $profile
     * @param array<string, array<string, mixed>> $routines
     * @param array<string, mixed>|null $privacy
     * @return list<array{label: string, value: string}>
     */
    private function checks(int $userId, ?array $profile, array $routines, ?array $privacy): array
    {
        $active = 0;
        foreach ($routines as $row) {
            if ((int) $row['enabled'] === 1) {
                $active++;
            }
        }
        $voice = (string) ($profile['voice_profile'] ?? '');

        return [
            ['label' => 'Voice profile', 'value' => $voice !== '' ? 'Verified' : 'Not set'],
            ['label' => 'Service connections', 'value' => $this->linkedServices($userId) . ' linked'],
            ['label' => 'Privacy protocol', 'value' => $privacy !== null ? 'Verified' : 'Not set'],
            ['label' => 'Routines', 'value' => $active . ' active'],
        ];
    }

    private function linkedServices(int $userId): int
    {
        $n = 0;
        if ($this->db->fetchScalar(
            "SELECT 1 FROM spotify_account WHERE user_id = ? AND status = 'connected' LIMIT 1",
            [$userId]
        ) !== null) {
            $n++;
        }
        if ($this->db->fetchScalar(
            'SELECT 1 FROM user_feeds WHERE user_id = ? AND enabled = 1 LIMIT 1',
            [$userId]
        ) !== null) {
            $n++;
        }
        if ($this->db->fetchScalar(
            'SELECT 1 FROM user_flight_watches WHERE user_id = ? AND is_active = 1 LIMIT 1',
            [$userId]
        ) !== null) {
            $n++;
        }
        if ($this->db->fetchScalar(
            'SELECT 1 FROM user_package_watches WHERE user_id = ? AND is_active = 1 LIMIT 1',
            [$userId]
        ) !== null) {
            $n++;
        }

        return $n;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function column(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '') {
                $out[] = $title;
            }
        }

        return $out;
    }

    private function localNow(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->clock->timezone());
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function localDayUtc(): array
    {
        $start = $this->localNow()->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        $utc = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->format('Y-m-d'),
        ];
    }

    private static function singleLine(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value;
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return $value === '1' || $value === 'true';
        }

        return false;
    }
}
