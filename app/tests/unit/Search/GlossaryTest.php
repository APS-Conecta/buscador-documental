<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\Glossary;
use PHPUnit\Framework\TestCase;

class GlossaryTest extends TestCase {
	private function glossary(): Glossary {
		return new Glossary([
			['key' => 'rem', 'expansion' => 'resumen estadistico mensual', 'ambiguous' => false],
			['key' => 'aps', 'expansion' => 'atencion primaria de salud', 'ambiguous' => false],
			['key' => 'cie 10', 'expansion' => 'clasificacion internacional de enfermedades 10 version', 'ambiguous' => false],
			['key' => 'hba1c', 'expansion' => 'hemoglobina glicosilada', 'ambiguous' => false],
			['key' => 'ss', 'expansion' => 'servicio de salud', 'ambiguous' => true],
			['key' => 'ss', 'expansion' => 'sin soplos', 'ambiguous' => true],
			['key' => 'alta', 'expansion' => 'cierre administrativo', 'ambiguous' => true],
			['key' => 'cesfam', 'expansion' => 'centro de salud familiar', 'ambiguous' => false],
			['key' => 'tens', 'expansion' => 'tecnico en enfermeria de nivel superior', 'ambiguous' => false],
		]);
	}

	public function testAnAcronymInTheQueryAddsItsExpansion(): void {
		$this->assertSame(['resumen estadistico mensual'], $this->glossary()->alternatives('REM de junio'));
	}

	public function testTheExpansionInTheQueryAddsTheAcronym(): void {
		$this->assertSame(['rem'], $this->glossary()->alternatives('Resumen Estadístico Mensual de junio'));
	}

	public function testCaseAccentsAndPunctuationDoNotMatter(): void {
		$this->assertSame(['hemoglobina glicosilada'], $this->glossary()->alternatives('  HbA1c, del paciente'));
		$this->assertSame(['clasificacion internacional de enfermedades 10 version'], $this->glossary()->alternatives('codigo CIE-10 asignado'));
	}

	public function testAmbiguousAndCommonWordKeysAreNeverExpanded(): void {
		$this->assertSame([], $this->glossary()->alternatives('SS alta del servicio'));
		$this->assertSame([], $this->glossary()->alternatives('servicio de salud'), 'the reverse of an ambiguous key is not added either');
	}

	public function testOnlyWholeWordsMatch(): void {
		$this->assertSame([], $this->glossary()->alternatives('remuneraciones apsis cesfamiliar'));
	}

	public function testSeveralMatchesAreCappedAndKeepTheirOrder(): void {
		$alt = $this->glossary()->alternatives('rem aps cesfam cesfam tens hba1c cie 10');
		$this->assertSame(['resumen estadistico mensual', 'atencion primaria de salud', 'centro de salud familiar', 'tecnico en enfermeria de nivel superior', 'hemoglobina glicosilada'], $alt);
		$this->assertCount(Glossary::MAX_ALTERNATIVES, $alt);
	}

	public function testAlternativesAreInertPlainWordsWhateverTheQueryHolds(): void {
		foreach (["rem & | ! <-> ( ) \"x\" -aps 'DROP", 'rem:* aps\\ ; --', "rem\0aps\n"] as $q) {
			foreach ($this->glossary()->alternatives($q) as $a) {
				$this->assertMatchesRegularExpression('/^[a-z0-9]+( [a-z0-9]+)*$/', $a);
			}
		}
		$this->assertSame([], $this->glossary()->alternatives(''));
		$this->assertSame([], $this->glossary()->alternatives('   '));
	}

	public function testALongQueryIsHandledQuickly(): void {
		$q = str_repeat('rem aps palabra ', 2000);
		$t = microtime(true);
		$this->assertCount(2, $this->glossary()->alternatives($q));
		$this->assertLessThan(0.5, microtime(true) - $t);
	}

	public function testItLoadsTheShippedFileAndRefusesABrokenOne(): void {
		$g = Glossary::fromFile(__DIR__ . '/../../../lib/Search/glossary.json');
		$this->assertInstanceOf(Glossary::class, $g);
		$tmp = tempnam(sys_get_temp_dir(), 'gl');
		file_put_contents($tmp, '{"entries": "no"}');
		$this->expectException(\RuntimeException::class);
		Glossary::fromFile($tmp);
	}

	public function testFoldMatchesTheIndexFolding(): void {
		$this->assertSame('estadistico nandu atencion 10', Glossary::fold('Estadístico ÑANDÚ Atención 10ª'));
	}
}
