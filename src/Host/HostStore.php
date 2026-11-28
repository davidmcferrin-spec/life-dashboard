<?php

declare(strict_types=1);

namespace Dashboard\Host;

use Dashboard\DB\Database;

/**
 * Samples and the one options row. Retention deletes old samples in the collector.
 */
final class HostStore
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $row = $this->db->fetchOne(
            'SELECT sample_interval_minutes, retention_days, hidden_sensors, tunnel_name, tunnel_hostnames, tunnel_metrics_url, tunnel_state
             FROM host_monitor_options WHERE id = 1'
        );
        if ($row === null) {
            return HostReadings::defaults();
        }

        return [
            'sample_interval_minutes' => (int) $row['sample_interval_minutes'],
            'retention_days' => (int) $row['retention_days'],
            'hidden_sensors' => $this->jsonList($row['hidden_sensors']),
            'tunnel_name' => (string) $row['tunnel_name'],
            'tunnel_hostnames' => $this->jsonList($row['tunnel_hostnames']),
            'tunnel_metrics_url' => (string) $row['tunnel_metrics_url'],
            'tunnel_state' => (string) $row['tunnel_state'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function save(array $options): void
    {
        $this->db->execute(
            'UPDATE host_monitor_options
             SET sample_interval_minutes = ?, retention_days = ?, hidden_sensors = ?::jsonb,
                 tunnel_name = ?, tunnel_hostnames = ?::jsonb, tunnel_metrics_url = ?,
                 updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = 1',
            [
                $options['sample_interval_minutes'],
                $options['retention_days'],
                json_encode($options['hidden_sensors'], JSON_THROW_ON_ERROR),
                $options['tunnel_name'],
                json_encode($options['tunnel_hostnames'], JSON_THROW_ON_ERROR),
                $options['tunnel_metrics_url'],
            ]
        );
    }

    public function state(): string
    {
        return (string) $this->options()['tunnel_state'];
    }

    public function setState(string $state): void
    {
        $this->db->execute('UPDATE host_monitor_options SET tunnel_state = ? WHERE id = 1', [$state]);
    }

    public function latestAt(): ?int
    {
        $value = $this->db->fetchScalar('SELECT sampled_at FROM host_samples ORDER BY sampled_at DESC LIMIT 1');
        if ($value === null || $value === '') {
            return null;
        }

        $stamp = strtotime((string) $value . ' UTC');

        return $stamp === false ? null : $stamp;
    }

    /**
     * @param list<array{metric: string, subject: string, value: float}> $points
     */
    public function insert(string $sampledAt, array $points): int
    {
        $written = 0;
        foreach ($points as $point) {
            $written += $this->db->execute(
                'INSERT INTO host_samples (sampled_at, metric, subject, value) VALUES (?, ?, ?, ?)
                 ON CONFLICT (sampled_at, metric, subject) DO NOTHING',
                [$sampledAt, $point['metric'], $point['subject'], $point['value']]
            );
        }

        return $written;
    }

    public function prune(int $days): void
    {
        $days = max(7, min(365, $days));
        $this->db->execute(
            "DELETE FROM host_samples WHERE sampled_at < (now() AT TIME ZONE 'UTC') - (?::text || ' days')::interval",
            [(string) $days]
        );
    }

    /**
     * @return array{metric: string, subject: string, label: string, unit: string, range: string, points: list<array{at: string, value: float}>}
     */
    public function history(string $metric, string $subject, string $range): array
    {
        $metric = HostReadings::metric($metric);
        $subject = HostReadings::subject($subject);
        $range = HostReadings::range($range);
        $rows = $this->db->fetchAll(
            "SELECT sampled_at, value FROM host_samples
             WHERE metric = ? AND subject = ? AND sampled_at >= (now() AT TIME ZONE 'UTC') - INTERVAL '90 days'
             ORDER BY sampled_at",
            [$metric, $subject]
        );
        $cutoff = time() - HostReadings::RANGES[$range];
        $points = [];
        foreach ($rows as $row) {
            $stamp = strtotime((string) $row['sampled_at'] . ' UTC');
            if ($stamp === false || $stamp < $cutoff) {
                continue;
            }
            $points[] = ['at' => $stamp, 'value' => (float) $row['value']];
        }

        return [
            'metric' => $metric,
            'subject' => $subject,
            'label' => HostReadings::METRICS[$metric]['label'],
            'unit' => HostReadings::METRICS[$metric]['unit'],
            'range' => $range,
            'points' => HostReadings::downsample($points, HostReadings::BUCKETS[$range]),
        ];
    }

    /**
     * @return list<array{metric: string, label: string, unit: string, subjects: list<array{subject: string, label: string}>}>
     */
    public function catalog(array $liveSubjects = []): array
    {
        $rows = $this->db->fetchAll(
            'SELECT metric, subject FROM host_samples GROUP BY metric, subject ORDER BY metric, subject'
        );
        $byMetric = [];
        foreach (HostReadings::METRICS as $metric => $meta) {
            $byMetric[$metric] = [];
        }
        foreach ($rows as $row) {
            $metric = (string) $row['metric'];
            if (!isset(HostReadings::METRICS[$metric])) {
                continue;
            }
            $subject = (string) $row['subject'];
            $byMetric[$metric][$subject] = $subject === '' ? 'host' : $subject;
        }
        foreach ($liveSubjects as $metric => $subjects) {
            if (!isset($byMetric[$metric]) || !is_array($subjects)) {
                continue;
            }
            foreach ($subjects as $subject => $label) {
                $byMetric[$metric][(string) $subject] = (string) $label;
            }
        }
        $out = [];
        foreach (HostReadings::METRICS as $metric => $meta) {
            $subjects = [];
            foreach ($byMetric[$metric] as $subject => $label) {
                $subjects[] = ['subject' => (string) $subject, 'label' => (string) $label];
            }
            $out[] = [
                'metric' => $metric,
                'label' => $meta['label'],
                'unit' => $meta['unit'],
                'subjects' => $subjects,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string) $value, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
