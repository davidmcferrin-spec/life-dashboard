<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Schema;
use Dashboard\Jarvis\Wizard;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class JarvisWizardTest extends TestCase
{
    public function testGreetingUsesTheHourAndTheSavedName(): void
    {
        self::assertSame(
            'Good morning, Dana. I will keep things analytical, clear and focused.',
            Wizard::greeting(8, 'Dana', 'analytical')
        );
        self::assertSame(
            'Good afternoon, Dana. I will keep things conversational.',
            Wizard::greeting(12, 'Dana', 'conversational')
        );
        self::assertSame(
            'Good evening, Dana. I will keep it short.',
            Wizard::greeting(19, 'Dana', 'concise')
        );
    }

    public function testIdentityRejectsABlankName(): void
    {
        try {
            Wizard::checkedIdentity('   ', 'JARVIS', 'analytical');
            self::fail('A blank name was accepted.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('display_name', $e->fields());
        }
    }

    public function testIdentityRejectsAnUnknownStyle(): void
    {
        try {
            Wizard::checkedIdentity('Dana', 'JARVIS', 'dramatic');
            self::fail('An unknown style was accepted.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('style', $e->fields());
        }
    }

    public function testWizardLeavesGrokAndASecondSpeechServiceAlone(): void
    {
        $template = (string) file_get_contents(ROOT_DIR . '/templates/settings/jarvis.php');
        $wizard = (string) file_get_contents(ROOT_DIR . '/src/Jarvis/Wizard.php');
        $index = (string) file_get_contents(ROOT_DIR . '/public/index.php');
        $script = (string) file_get_contents(ROOT_DIR . '/public/assets/js/jarvis-wizard.js');

        self::assertStringNotContainsString('secret-field', $template);
        self::assertStringNotContainsString('GROK', strtoupper($template));
        self::assertStringNotContainsString('GROK', strtoupper($wizard));
        self::assertStringNotContainsString('/api/v1/speech/transcribe', $index);
        self::assertStringNotContainsString('/api/v1/speech/transcribe', $wizard);
        self::assertStringContainsString('/api/v1/speech/transcribe', $script);
        self::assertArrayHasKey('0049_jarvis_wizard', Schema::pgsqlMigrationFiles());
    }

    public function testRoutinesUseTheExistingJobRunner(): void
    {
        $bin = (string) file_get_contents(ROOT_DIR . '/deploy/bin/lifedash-job');
        $timer = (string) file_get_contents(ROOT_DIR . '/deploy/systemd/lifedash-job-routines.timer');
        $provision = (string) file_get_contents(ROOT_DIR . '/deploy/provision.sh');

        self::assertStringContainsString('routines)', $bin);
        self::assertStringContainsString('cron/jarvis_routines.php', $bin);
        self::assertStringContainsString('lifedash-job@routines.service', $timer);
        self::assertStringContainsString('reddit routines', $provision);
    }
}
