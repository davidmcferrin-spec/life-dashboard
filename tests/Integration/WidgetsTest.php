<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Settings\PreferenceService;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class WidgetsTest extends PgsqlTestCase
{
    private Services $svc;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-09-25 15:00:00', new DateTimeZone('UTC'))));
    }

    public function testSeedBuildsGridFromLegacyPreferencesOnce(): void
    {
        $prefs = new PreferenceService($this->db);
        $prefs->set($this->user, PreferenceService::KEY_WIDGET_ORDER, json_encode(['stocks', 'weather', 'bookmarks', 'flights', 'packages', 'pings', 'todos']));
        $prefs->set($this->user, PreferenceService::KEY_WIDGET_VISIBILITY, json_encode(['flights' => false, 'clipboards' => true]));

        $this->svc->widgets->ensureSeeded($this->user);
        $this->svc->widgets->ensureSeeded($this->user);

        $types = array_column($this->svc->widgets->list($this->user), 'type');
        self::assertSame(
            ['habits', 'mood', 'legacy.stocks', 'legacy.weather', 'legacy.bookmarks', 'legacy.packages', 'legacy.pings', 'legacy.todos', 'legacy.clipboards'],
            $types,
            'order kept, hidden flights skipped, news omitted without pins, seeded once'
        );
        self::assertNotNull($this->svc->metrics->findByKey($this->user, 'mood'));
    }

    public function testCreateValidatesConfigAndOwnership(): void
    {
        $steps = $this->svc->metrics->create($this->user, ['name' => 'Steps', 'kind' => 'number']);
        $w = $this->svc->widgets->create($this->user, 'stat', 'Steps today', ['metric_id' => $steps['id'], 'period' => 'today']);
        self::assertEquals(['metric_id' => $steps['id'], 'agg' => 'last', 'period' => 'today', 'decimals' => 1], $w['config'], 'defaults filled');
        self::assertSame([$steps['id']], $w['metric_refs']);

        foreach ([
            ['metric_id' => 999999],                            // not mine / missing
            ['metric_id' => $steps['id'], 'period' => 'decade'], // bad enum
            ['metric_id' => $steps['id'], 'nope' => 1],          // unknown key
            [],                                                   // required missing
        ] as $bad) {
            try {
                $this->svc->widgets->create($this->user, 'stat', null, $bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
                // expected
            }
        }

        try {
            $this->svc->widgets->create($this->user, 'streak', null, ['metric_id' => $steps['id']]);
            self::fail('streak accepted a non-habit metric');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('config.metric_id', $e->fields());
        }
    }

    public function testSingletonLegacyTypes(): void
    {
        $this->svc->widgets->create($this->user, 'legacy.weather', null, []);
        $this->expectException(ValidationException::class);
        $this->svc->widgets->create($this->user, 'legacy.weather', null, []);
    }

    public function testRevisionsAndRollback(): void
    {
        $a = $this->svc->metrics->create($this->user, ['name' => 'A', 'kind' => 'number']);
        $b = $this->svc->metrics->create($this->user, ['name' => 'B', 'kind' => 'number']);
        $w = $this->svc->widgets->create($this->user, 'chart', 'Trend', ['metric_ids' => [$a['id']]]);

        $w2 = $this->svc->widgets->update($this->user, $w['id'], ['config' => ['metric_ids' => [$a['id'], $b['id']], 'style' => 'bar']], 'ai', 'added B');
        self::assertSame(2, $w2['version']);
        $same = $this->svc->widgets->update($this->user, $w['id'], ['config' => $w2['config']]);
        self::assertSame(2, $same['version'], 'no-op update records nothing');

        $w3 = $this->svc->widgets->rollback($this->user, $w['id'], 1);
        self::assertSame(3, $w3['version']);
        self::assertSame([$a['id']], $w3['config']['metric_ids']);
        self::assertSame('line', $w3['config']['style']);

        $revs = $this->svc->widgets->revisions($this->user, $w['id']);
        self::assertSame([3, 2, 1], array_column($revs, 'version'));
        self::assertSame(['user', 'ai', 'user'], array_column($revs, 'changed_by'));
        self::assertSame('rollback to v1', $revs[0]['note']);
    }

    public function testLayoutSaveValidatesBoundsAndOwnership(): void
    {
        $w = $this->svc->widgets->create($this->user, 'legacy.stocks', null, []);
        self::assertSame(0, $w['height_locked']);
        self::assertSame(1, $this->svc->widgets->saveLayout($this->user, [['id' => $w['id'], 'x' => 8, 'y' => 3, 'w' => 4, 'h' => 6, 'height_locked' => 1]]));
        self::assertSame(0, $this->svc->widgets->saveLayout($this->user, [['id' => $w['id'], 'x' => 8, 'y' => 3, 'w' => 4, 'h' => 6, 'height_locked' => 1]]), 'unchanged → no write');
        $got = $this->svc->widgets->get($this->user, $w['id']);
        self::assertSame([8, 3, 4, 6, 1], [$got['x'], $got['y'], $got['w'], $got['h'], $got['height_locked']]);
        self::assertSame(0, $this->svc->widgets->saveLayout($this->user, [['id' => $w['id'], 'x' => 8, 'y' => 3, 'w' => 4, 'h' => 6]]), 'omitted lock is left alone');
        self::assertSame(1, $this->svc->widgets->get($this->user, $w['id'])['height_locked']);

        $this->expectException(ValidationException::class);
        $this->svc->widgets->saveLayout($this->user, [['id' => $w['id'], 'x' => 10, 'y' => 0, 'w' => 4, 'h' => 4]]);
    }

    public function testCompactRefitRunsOnceAndSkipsALockedCard(): void
    {
        $weather = $this->svc->widgets->create($this->user, 'legacy.weather', null, []);
        $mood = $this->svc->widgets->create($this->user, 'mood', null, []);
        $stocks = $this->svc->widgets->create($this->user, 'legacy.stocks', null, []);
        $this->svc->widgets->saveLayout($this->user, [
            ['id' => $weather['id'], 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 5, 'height_locked' => 0],
            ['id' => $mood['id'], 'x' => 0, 'y' => 5, 'w' => 6, 'h' => 4, 'height_locked' => 0],
            ['id' => $stocks['id'], 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 4, 'height_locked' => 1],
        ]);

        $this->svc->widgets->ensureCompactRow($this->user);
        $weather = $this->svc->widgets->get($this->user, $weather['id']);
        $mood = $this->svc->widgets->get($this->user, $mood['id']);
        $stocks = $this->svc->widgets->get($this->user, $stocks['id']);

        self::assertSame([0, 0, 3, 10, 0], [$weather['x'], $weather['y'], $weather['w'], $weather['h'], $weather['height_locked']]);
        self::assertSame([3, 0, 3, 8], [$mood['x'], $mood['y'], $mood['w'], $mood['h']]);
        self::assertSame([8, 4, 8, 1], [$stocks['x'], $stocks['w'], $stocks['h'], $stocks['height_locked']]);

        $this->svc->widgets->ensureCompactRow($this->user);
        self::assertSame(10, $this->svc->widgets->get($this->user, $weather['id'])['h'], 'a second load does not scale height again');
    }

    public function testWidgetDataForTrackingTypes(): void
    {
        $mood = $this->svc->metrics->ensureMood($this->user);
        $this->svc->points->record($this->user, $mood['id'], ['value' => 4, 'note' => 'good run']);
        $habit = $this->svc->metrics->create($this->user, ['name' => 'Stretch', 'kind' => 'habit']);
        $this->svc->habits->toggle($this->user, $habit['id']);

        $mw = $this->svc->widgets->create($this->user, 'mood', null, []);
        $md = $this->svc->widgetData->forWidget($this->user, $mw['id']);
        self::assertSame([$mood['id']], $md['metric_ids']);
        self::assertSame(4.0, $md['latest']['value']);
        self::assertCount(30, $md['buckets']);
        self::assertSame('good run', $md['notes'][0]['note']);

        $hw = $this->svc->widgets->create($this->user, 'habits', null, []);
        $hd = $this->svc->widgetData->forWidget($this->user, $hw['id']);
        self::assertTrue($hd['all_habits']);
        self::assertTrue($hd['habits'][0]['done_today']);
        self::assertSame([false, false, false, false, false, false, true], $hd['habits'][0]['history']);

        $sw = $this->svc->widgets->create($this->user, 'stat', null, ['metric_id' => $mood['id'], 'agg' => 'avg', 'period' => 'today']);
        self::assertSame(4.0, $this->svc->widgetData->forWidget($this->user, $sw['id'])['value']);

        // Archiving the metric degrades gracefully instead of erroring.
        $this->svc->metrics->setArchived($this->user, $mood['id'], true);
        self::assertArrayHasKey('error', $this->svc->widgetData->forWidget($this->user, $sw['id']));
    }

    public function testDeleteCascadesRevisions(): void
    {
        $w = $this->svc->widgets->create($this->user, 'legacy.pings', null, []);
        $this->svc->widgets->delete($this->user, $w['id']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM widget_revisions WHERE widget_id = ?', [$w['id']]));
        $this->expectException(NotFoundException::class);
        $this->svc->widgets->delete($this->user, $w['id']);
    }

    public function testWideLayoutDoesNotRewriteCanonicalColumns(): void
    {
        $a = $this->svc->widgets->create($this->user, 'legacy.weather', null, []);
        $b = $this->svc->widgets->create($this->user, 'mood', null, []);
        $this->svc->widgets->saveLayout($this->user, [
            ['id' => $a['id'], 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 4, 'height_locked' => 0],
            ['id' => $b['id'], 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 4, 'height_locked' => 0],
        ]);

        $this->svc->widgets->saveBreakpointLayout($this->user, 18, [
            ['id' => $a['id'], 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 4],
            ['id' => $b['id'], 'x' => 5, 'y' => 0, 'w' => 5, 'h' => 4],
        ]);
        $got = $this->svc->widgets->get($this->user, $a['id']);
        self::assertSame([0, 0, 3, 4], [$got['x'], $got['y'], $got['w'], $got['h']]);
        $layouts = $this->svc->widgets->breakpointLayouts($this->user);
        self::assertSame(5, $layouts['18'][0]['w']);
        self::assertSame([], $layouts['24']);

        $this->svc->widgets->saveHeights($this->user, [
            ['id' => $a['id'], 'h' => 6, 'height_locked' => 1],
        ]);
        $got = $this->svc->widgets->get($this->user, $a['id']);
        self::assertSame([0, 0, 3, 6, 1], [$got['x'], $got['y'], $got['w'], $got['h'], $got['height_locked']]);
        self::assertCount(2, $this->svc->widgets->breakpointLayouts($this->user)['18']);

        $this->svc->widgets->saveLayout($this->user, [
            ['id' => $a['id'], 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 7, 'height_locked' => 1],
        ]);
        self::assertCount(2, $this->svc->widgets->breakpointLayouts($this->user)['18'], 'a height-only save keeps the wide arrangement');

        $this->svc->widgets->saveLayout($this->user, [
            ['id' => $a['id'], 'x' => 6, 'y' => 1, 'w' => 4, 'h' => 7, 'height_locked' => 1],
        ]);
        self::assertSame([], $this->svc->widgets->breakpointLayouts($this->user)['18']);
        $got = $this->svc->widgets->get($this->user, $a['id']);
        self::assertSame([6, 1, 4], [$got['x'], $got['y'], $got['w']]);
    }

    public function testWideLayoutRejectsAPositionPastItsColumns(): void
    {
        $a = $this->svc->widgets->create($this->user, 'legacy.weather', null, []);
        $this->expectException(ValidationException::class);
        $this->svc->widgets->saveBreakpointLayout($this->user, 18, [
            ['id' => $a['id'], 'x' => 12, 'y' => 0, 'w' => 8, 'h' => 4],
        ]);
    }
}
