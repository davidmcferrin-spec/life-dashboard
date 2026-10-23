<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class SpotifyMixtapeTest extends PgsqlTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
    }

    public function testChristmasAddIsRefusedOutsideTheWindowUnlessThePromptAllowsIt(): void
    {
        $uri = 'spotify:track:carol01';
        $open = 'spotify:track:plain01';
        $july = $this->library('2026-07-04 18:00:00');
        $saved = $july->mixtape()->saveMany($this->user, [[
            'uri' => $uri,
            'musical_key' => 'A minor',
            'bpm' => 96,
            'energy' => 4,
            'mood_tags' => 'warm',
            'season_tags' => 'christmas',
        ], [
            'uri' => $open,
            'energy' => 5,
            'source' => 'manual',
        ]]);
        self::assertSame('estimated', $saved['tracks'][0]['source']);
        self::assertSame('8A', $saved['tracks'][0]['camelot']);
        self::assertSame('manual', $saved['tracks'][1]['source']);

        $created = $july->createPlaylist($this->user, [
            'name' => 'Holiday',
            'prompt' => 'Warm christmas songs.',
        ], 'david');
        $id = $created['playlist']['id'];
        $promptId = $created['playlist']['source_prompt_id'];
        self::assertNotNull($promptId);

        $july->addTracks($this->user, $id, [$open], null, 'ai');
        $this->expectWindow($july, $id, [$uri]);
        $this->expectWindow($july, $id, [$uri], true);

        $december = $this->library('2026-12-25 18:00:00');
        $staged = $december->addTracks($this->user, $id, [$uri], null, 'ai');
        $preview = array_column($staged['preview'], 'uri');
        self::assertContains($uri, $preview);
        try {
            $july->mixtape()->assertPending($this->user, $id, $july->clock());
            self::fail('A pending christmas add should be refused in July.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('outside its window', $e->getMessage());
        }

        $july->discard($this->user, $id);
        $july->savePrompt($this->user, (int) $promptId, [
            'theme' => 'late night',
            'arc' => ['peak' => 8],
            'allow_seasons' => ['christmas'],
        ]);
        $prompt = $july->prompt($this->user, (int) $promptId);
        self::assertSame('late night', $prompt['theme']);
        self::assertSame(8, $prompt['arc']['peak']);
        self::assertSame(3, $prompt['arc']['opener']);
        self::assertSame(['christmas'], $prompt['allow_seasons']);
        $allowed = $july->addTracks($this->user, $id, [$uri], null, 'ai');
        self::assertContains($uri, array_column($allowed['preview'], 'uri'));

        $closed = $july->mixtape()->active($this->user, $july->clock());
        self::assertSame([], array_column($closed['seasons'], 'season'));
        $openNow = $december->mixtape()->active($this->user, $december->clock());
        self::assertContains('christmas', array_column($openNow['seasons'], 'season'));
        self::assertContains('holiday', array_column($openNow['seasons'], 'season'));
        self::assertSame('2026-12-25', $openNow['on']);
    }

    public function testSeasonWindowsFollowThanksgivingAndASavedDateRange(): void
    {
        $lib = $this->library('2026-11-26 18:00:00');
        $onThanksgiving = array_column($lib->mixtape()->active($this->user, $lib->clock())['seasons'], 'season');
        self::assertContains('christmas', $onThanksgiving);

        $before = $this->library('2026-11-25 18:00:00');
        self::assertSame([], array_column($before->mixtape()->active($this->user, $before->clock())['seasons'], 'season'));

        $jan6 = $this->library('2027-01-06 18:00:00');
        self::assertContains('christmas', array_column($jan6->mixtape()->active($this->user, $jan6->clock())['seasons'], 'season'));
        $jan7 = $this->library('2027-01-07 18:00:00');
        self::assertSame([], array_column($jan7->mixtape()->active($this->user, $jan7->clock())['seasons'], 'season'));

        $lib->mixtape()->saveSeason($this->user, [
            'season' => 'halloween',
            'start_anchor' => '',
            'start_month' => 10,
            'start_day' => 1,
            'end_month' => 10,
            'end_day' => 31,
        ]);
        $eve = $this->library('2026-10-31 18:00:00');
        self::assertContains('halloween', array_column($eve->mixtape()->active($this->user, $eve->clock())['seasons'], 'season'));
        $after = $this->library('2026-11-01 18:00:00');
        self::assertNotContains('halloween', array_column($after->mixtape()->active($this->user, $after->clock())['seasons'], 'season'));

        $uri = 'spotify:track:haunt01';
        $after->mixtape()->saveMany($this->user, [[
            'uri' => $uri,
            'season_tags' => ['halloween'],
            'energy' => 6,
            'source' => 'estimated',
        ]]);
        $summer = 'spotify:track:sun0001';
        $after->mixtape()->saveMany($this->user, [[
            'uri' => $summer,
            'season_tags' => ['summer'],
            'energy' => 7,
        ]]);
        $created = $after->createPlaylist($this->user, ['name' => 'Night', 'ai' => true, 'prompt' => 'Doors and synths.'], 'david');
        $added = $after->addTracks($this->user, $created['playlist']['id'], [$summer], null, 'ai');
        self::assertContains($summer, array_column($added['preview'], 'uri'));
        $this->expectWindow($after, $created['playlist']['id'], [$uri]);
    }

    public function testPlanOrdersStoredNotesAlongThePromptArc(): void
    {
        $lib = $this->library('2026-07-04 18:00:00');
        $rows = [
            ['uri' => 'spotify:track:low01', 'energy' => 3, 'bpm' => 90, 'camelot' => '8A', 'source' => 'manual'],
            ['uri' => 'spotify:track:mid01', 'energy' => 6, 'bpm' => 96, 'camelot' => '8A', 'source' => 'estimated'],
            ['uri' => 'spotify:track:peak01', 'energy' => 9, 'bpm' => 102, 'camelot' => '9A'],
            ['uri' => 'spotify:track:rest01', 'energy' => 5, 'bpm' => 98, 'camelot' => '8B'],
            ['uri' => 'spotify:track:end01', 'energy' => 4, 'bpm' => 140, 'camelot' => '1A'],
            ['uri' => 'spotify:track:bare01'],
        ];
        $lib->mixtape()->saveMany($this->user, $rows);
        $created = $lib->createPlaylist($this->user, [
            'name' => 'Arc',
            'prompt' => 'Build then land.',
            'ai' => true,
        ], 'david');
        $promptId = (int) $created['playlist']['source_prompt_id'];
        $lib->savePrompt($this->user, $promptId, ['arc' => ['opener' => 3, 'build' => 6, 'peak' => 9, 'breather' => 5, 'closer' => 4]]);
        $plan = $lib->mixtape()->plan($this->user, array_column($rows, 'uri'), $promptId, null);
        self::assertSame(
            ['spotify:track:low01', 'spotify:track:mid01', 'spotify:track:peak01', 'spotify:track:rest01', 'spotify:track:end01'],
            array_column($plan['order'], 'uri')
        );
        self::assertSame(['opener', 'build', 'peak', 'breather', 'closer'], array_column($plan['order'], 'section'));
        self::assertSame(['spotify:track:bare01'], array_column($plan['missing'], 'uri'));
        $rough = array_values(array_filter(
            $plan['rough'],
            static fn (array $row): bool => $row['to'] === 'spotify:track:end01'
        ));
        self::assertNotSame([], $rough);
        self::assertContains('bpm', $rough[0]['reasons']);
        self::assertContains('key', $rough[0]['reasons']);
        $ops = array_column($lib->changes($this->user, $created['playlist']['id']), 'op');
        self::assertNotContains('add', $ops);
        self::assertNotContains('replace', $ops);
    }

    /**
     * @param list<string> $uris
     */
    private function expectWindow(SpotifyLibrary $lib, int $playlistId, array $uris, bool $replace = false): void
    {
        try {
            if ($replace) {
                $lib->replaceTracks($this->user, $playlistId, $uris, 'ai');
            } else {
                $lib->addTracks($this->user, $playlistId, $uris, null, 'ai');
            }
            self::fail('A closed season should be refused.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('outside its window', $e->getMessage());
        }
    }

    private function library(string $utc): SpotifyLibrary
    {
        return new SpotifyLibrary(
            $this->db,
            new Clock('America/Chicago', new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        );
    }
}
