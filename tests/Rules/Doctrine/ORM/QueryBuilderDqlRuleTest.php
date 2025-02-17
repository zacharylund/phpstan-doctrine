<?php declare(strict_types = 1);

namespace PHPStan\Rules\Doctrine\ORM;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<QueryBuilderDqlRule>
 */
class QueryBuilderDqlRuleTest extends RuleTestCase
{

	protected function getRule(): Rule
	{
		return new QueryBuilderDqlRule(
			new ObjectMetadataResolver(__DIR__ . '/entity-manager.php', __DIR__ . '/../../../../tmp'),
			true
		);
	}

	public function testRule(): void
	{
		if (PHP_VERSION_ID < 70300) {
			self::markTestSkipped('For some reason PHP 7.2 cannot recover from Trying to get property value of non-object');
		}

		$this->analyse([__DIR__ . '/data/query-builder-dql.php'], [
			[
				"QueryBuilder: [Syntax Error] line 0, col 66: Error: Expected end of string, got ')'\nDQL: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE e.id = 1)",
				31,
			],
			[
				"QueryBuilder: [Syntax Error] line 0, col 68: Error: Expected end of string, got ')'\nDQL: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE e.id = :id)",
				43,
			],
			[
				"QueryBuilder: [Syntax Error] line 0, col 68: Error: Expected end of string, got ')'\nDQL: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE e.id = :id)",
				55,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 60 near \'transient = \': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named transient',
				62,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 14 near \'Foo e\': Error: Class \'Foo\' is not defined.',
				71,
			],
			[
				'Could not analyse QueryBuilder with dynamic arguments.',
				99,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 60 near \'transient = \': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named transient',
				107,
			],
			[
				"QueryBuilder: [Syntax Error] line 0, col 82: Error: Expected end of string, got ')'\nDQL: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE e.id = 1 ORDER BY e.name) ASC",
				129,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 78 near \'name ASC\': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named name',
				139,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 78 near \'name ASC\': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named name',
				160,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 60 near \'transient = 1\': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named transient',
				170,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 72 near \'nickname LIKE\': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named nickname',
				194,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 72 near \'nickname IS \': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named nickname',
				206,
			],
			[
				"QueryBuilder: [Syntax Error] line 0, col 80: Error: Expected =, <, <=, <>, >, >=, !=, got ')'\nDQL: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE e.id = 1 OR e.nickname) IS NULL",
				218,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 60 near \'transient = \': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named transient',
				234,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 60 near \'nonexistent =\': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named nonexistent',
				251,
			],
			[
				"QueryBuilder: [Syntax Error] line 0, col -1: Error: Expected =, <, <=, <>, >, >=, !=, got end of string.\nDQL: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE foo",
				281,
			],
		]);
	}

	public function testRuleBranches(): void
	{
		$errors = [
			[
				'QueryBuilder: [Semantical Error] line 0, col 58 near \'p.id = 1\': Error: \'p\' is not defined.',
				31,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 58 near \'p.id = 1\': Error: \'p\' is not defined.',
				45,
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 58 near \'p.id = 1\': Error: \'p\' is not defined.',
				59,
				'Detected from DQL branch: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE p.id = 1',
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 93 near \'t.id = 1\': Error: \'t\' is not defined.',
				90,
				'Detected from DQL branch: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e INNER JOIN e.parent p WHERE p.id = 1 AND t.id = 1',
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 95 near \'foo = 1\': Error: Class PHPStan\Rules\Doctrine\ORM\MyEntity has no field or association named foo',
				107,
				'Detected from DQL branch: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e INNER JOIN e.parent p WHERE p.id = 1 AND e.foo = 1',
			],
			[
				'QueryBuilder: [Semantical Error] line 0, col 93 near \'t.id = 1\': Error: \'t\' is not defined.',
				107,
				'Detected from DQL branch: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e INNER JOIN e.parent p WHERE p.id = 1 AND t.id = 1',
			],
		];
		$this->analyse([__DIR__ . '/data/query-builder-branches-dql.php'], $errors);
	}

	public function testAndOrVariants(): void
	{
		$this->analyse([__DIR__ . '/data/query-builder-dql-and-or-variants.php'], []);
	}

	public function testUpdateIssue(): void
	{
		$this->analyse([__DIR__ . '/data/query-builder-dql-update.php'], [
			[
				'Could not analyse QueryBuilder with dynamic arguments.',
				31,
			],
		]);
	}

	public function testBranchingPerformance(): void
	{
		$this->analyse([__DIR__ . '/data/query-builder-branches-performance.php'], [
			[
				'QueryBuilder: [Semantical Error] line 0, col 58 near \'p.id = 1 AND\': Error: \'p\' is not defined.',
				121,
				'Detected from DQL branch: SELECT e FROM PHPStan\Rules\Doctrine\ORM\MyEntity e WHERE p.id = 1 AND p.id = 3 AND p.id = 5 AND p.id = 7 AND p.id = 9 AND p.id = 11 AND p.id = 13 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15 AND p.id = 15',
			],
		]);
	}

	public function testDynamicWhere(): void
	{
		$this->analyse([__DIR__ . '/data/query-builder-dynamic.php'], [
			['Could not analyse QueryBuilder with dynamic arguments.', 40],
			['Could not analyse QueryBuilder with dynamic arguments.', 45],
			['Could not analyse QueryBuilder with dynamic arguments.', 51],
			['Could not analyse QueryBuilder with dynamic arguments.', 56],
			['Could not analyse QueryBuilder with dynamic arguments.', 61],
			['Could not analyse QueryBuilder with dynamic arguments.', 66],
			['Could not analyse QueryBuilder with dynamic arguments.', 71],
		]);
	}

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../../../extension.neon',
			__DIR__ . '/entity-manager.neon',
		];
	}

	protected function shouldFailOnPhpErrors(): bool
	{
		// doctrine/orm/src/Query/Parser.php throws assert(): assert($peek !== null) error
		return false;
	}

}
