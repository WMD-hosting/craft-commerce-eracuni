<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\KpdCatalog;
use wmd\commerceeracuni\core\KpdCheck;

final class KpdCatalogTest extends TestCase
{
    public static function typed(): array
    {
        return [
            ['53.20.09', '53.20.09'],
            ['532009', '53.20.09'],
            ['H53.20.09', '53.20.09'],
            ['h 53 20 09', '53.20.09'],
            [' 53.20.09 ', '53.20.09'],
            ['M53.20.09', '53.20.09'],
            ['53.20.0', null],
            ['5320091', null],
            ['K63.10', null],
            ['', null],
            [null, null],
        ];
    }

    #[DataProvider('typed')]
    public function testNormalize(?string $typed, ?string $expected): void
    {
        self::assertSame($expected, KpdCatalog::normalize($typed));
    }

    public function testDataFileIsTheSuiteBuildWithTheAmendment(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/kpd/kpd-2025.json'), true);
        self::assertSame('Državni zavod za statistiku', $data['header']['publisher']);
        self::assertSame('2027-01-01', $data['header']['amendments'][0]['effectiveFrom']);
        self::assertStringContainsString('119/2026', $data['header']['amendments'][0]['gazette']);
        self::assertCount(3371, array_filter(array_keys($data['items']), static fn($k) => strlen((string) $k) === 8));
    }

    public function testCourierCodesAndTheirEracuniForm(): void
    {
        $c = KpdCatalog::shared();
        $kpd = $c->find('53.20.09', '2026-11-17');
        self::assertNotNull($kpd);
        self::assertSame('H', $kpd->section);
        self::assertSame('H53.20.09', $kpd->eracuni());
        self::assertSame('Ostale poštanske i kurirske usluge, d. n.', $kpd->title);
        self::assertSame('Multimodalne kurirske usluge', $c->find('53.20.01', '2026-11-17')?->title);
        self::assertSame('K63.10.12', $c->check('631012', '2026-11-17')->eracuni(), 'What mojwmd sends in production.');
    }

    public function testTheSectionLetterComesFromTheList(): void
    {
        $check = KpdCatalog::shared()->check('M53.20.09', '2026-11-17');
        self::assertTrue($check->isValid());
        self::assertSame('H53.20.09', $check->eracuni());
    }

    public function testTheOldShippingDefaultIsUnknownWithTheCourierCodesSuggested(): void
    {
        $check = KpdCatalog::shared()->check('532000', '2026-11-17');
        self::assertSame(KpdCheck::UNKNOWN, $check->status);
        self::assertSame('53.20.00', $check->code);
        self::assertNull($check->eracuni());
        self::assertSame(['53.20.01', '53.20.02', '53.20.09'], array_map(static fn($k) => $k->code, $check->suggestions));
        self::assertSame(
            'KPD 53.20.00 is not a KPD 2025 code. Nearest valid codes: 53.20.01 (Multimodalne kurirske usluge), 53.20.02 (Usluge dostave hrane na kućnu adresu), 53.20.09 (Ostale poštanske i kurirske usluge, d. n.).',
            $check->message(),
        );
    }

    public function testEmptyAndMalformed(): void
    {
        $c = KpdCatalog::shared();
        self::assertSame(KpdCheck::EMPTY, $c->check('  ', '2026-11-17')->status);
        $format = $c->check('53.20', '2026-11-17');
        self::assertSame(KpdCheck::FORMAT, $format->status);
        self::assertSame([], $format->suggestions);
        self::assertStringContainsString('six digits', $format->message());
    }

    public function testNn11926EndsCodesAfter2026(): void
    {
        $c = KpdCatalog::shared();
        $before = $c->check('16.12.00', '2026-12-31');
        self::assertTrue($before->isValid());
        self::assertSame('2026-12-31', $before->kpd?->to);

        $after = $c->check('16.12.00', '2027-01-01');
        self::assertSame(KpdCheck::ENDED, $after->status);
        self::assertContains('16.12.10', array_map(static fn($k) => $k->code, $after->suggestions), 'Its successor is suggested.');
        self::assertStringStartsWith('KPD 16.12.00 was valid until 2026-12-31.', $after->message());
    }

    public function testNn11926StartsCodesIn2027(): void
    {
        $c = KpdCatalog::shared();
        $early = $c->check('16.12.10', '2026-11-17');
        self::assertSame(KpdCheck::NOT_YET, $early->status);
        self::assertNull($early->eracuni());
        self::assertNotContains('16.12.10', array_map(static fn($k) => $k->code, $early->suggestions));
        self::assertTrue($c->check('16.12.10', '2027-01-01')->isValid());
    }

    public function testTitlesFollowTheDate(): void
    {
        $c = KpdCatalog::shared();
        self::assertSame('Tiskane obrazovne knjige', $c->find('58.11.11', '2026-12-31')?->title);
        self::assertSame('Tiskane obrazovne, stručne, tehničke i znanstvene knjige', $c->find('58.11.11', '2027-01-01')?->title);
    }

    public function testSuggestionsOnlyHoldCodesInForceThatDay(): void
    {
        foreach (KpdCatalog::shared()->nearest('16.12.05', '2027-06-01', 10) as $kpd) {
            self::assertTrue($kpd->validOn('2027-06-01'), "{$kpd->code} is not in force on 2027-06-01");
        }
    }

    public function testAMissingFileFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KpdCatalog('/nonexistent/kpd.json'))->check('53.20.09', '2026-11-17');
    }
}
