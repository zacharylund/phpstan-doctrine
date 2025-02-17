<?php declare(strict_types = 1);

namespace PHPStan\Type\Doctrine\Query;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Tools\SchemaTool;
use PHPStan\Doctrine\Driver\DriverDetector;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Doctrine\DescriptorRegistry;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use QueryResult\Entities\Embedded;
use QueryResult\Entities\JoinedChild;
use QueryResult\Entities\Many;
use QueryResult\Entities\ManyId;
use QueryResult\Entities\NestedEmbedded;
use QueryResult\Entities\One;
use QueryResult\Entities\OneId;
use QueryResult\Entities\SingleTableChild;
use QueryResult\EntitiesEnum\EntityWithEnum;
use QueryResult\EntitiesEnum\IntEnum;
use QueryResult\EntitiesEnum\StringEnum;
use Throwable;
use Type\Doctrine\data\QueryResult\CustomIntType;
use function array_merge;
use function array_shift;
use function assert;
use function class_exists;
use function count;
use function property_exists;
use function sprintf;
use function strpos;
use function version_compare;
use const PHP_VERSION_ID;

final class QueryResultTypeWalkerTest extends PHPStanTestCase
{

	/** @var EntityManagerInterface */
	private static $em;

	/** @var DescriptorRegistry */
	private $descriptorRegistry;

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../data/QueryResult/config.neon',
		];
	}

	public static function setUpBeforeClass(): void
	{
		$em = require __DIR__ . '/../data/QueryResult/entity-manager.php';
		self::$em = $em;

		if (!DbalType::hasType(CustomIntType::NAME)) {
			DbalType::addType(CustomIntType::NAME, CustomIntType::class);
		}

		$schemaTool = new SchemaTool($em);
		$classes = $em->getMetadataFactory()->getAllMetadata();
		$schemaTool->createSchema($classes);

		$dataOne = [
			'intColumn' => [1, 2],
			'floatColumn' => [0.1, 2.0],
			'stringColumn' => ['A', 'B'],
			'stringNullColumn' => ['A', null],
		];

		$dataMany = [
			'intColumn' => [1, 2],
			'stringColumn' => ['A', 'B'],
			'stringNullColumn' => ['A', null],
		];

		$dataJoinedInheritance = [
			'parentColumn' => [1, 2],
			'parentNullColumn' => [1, null],
			'childColumn' => [1, 2],
			'childNullColumn' => [1, null],
		];

		$dataSingleTableInheritance = [
			'parentColumn' => [1, 2],
			'parentNullColumn' => [1, null],
			'childNullColumn' => [1, null],
		];

		$id = 1;

		foreach (self::combinations($dataOne) as $combination) {
			[$intColumn, $floatColumn, $stringColumn, $stringNullColumn] = $combination;
			$one = new One();
			$one->id = (string) $id++;
			$one->intColumn = $intColumn;
			$one->floatColumn = $floatColumn;
			$one->stringColumn = $stringColumn;
			$one->stringNullColumn = $stringNullColumn;
			$embedded = new Embedded();
			$embedded->intColumn = $intColumn;
			$embedded->stringColumn = $stringColumn;
			$embedded->stringNullColumn = $stringNullColumn;
			$nestedEmbedded = new NestedEmbedded();
			$nestedEmbedded->intColumn = $intColumn;
			$nestedEmbedded->stringColumn = $stringColumn;
			$nestedEmbedded->stringNullColumn = $stringNullColumn;
			$embedded->nestedEmbedded = $nestedEmbedded;
			$one->embedded = $embedded;
			$one->manies = new ArrayCollection();

			foreach (self::combinations($dataMany) as $combinationMany) {
				[$intColumnMany, $stringColumnMany, $stringNullColumnMany] = $combinationMany;
				$many = new Many();
				$many->id = (string) $id++;
				$many->intColumn = $intColumnMany;
				$many->stringColumn = $stringColumnMany;
				$many->stringNullColumn = $stringNullColumnMany;
				$many->datetimeColumn = new DateTime('2001-01-01 00:00:00');
				$many->datetimeImmutableColumn = new DateTimeImmutable('2001-01-01 00:00:00');
				$many->simpleArrayColumn = ['foo'];
				$many->one = $one;
				$one->manies->add($many);
				$em->persist($many);
			}

			$em->persist($one);
		}

		foreach (self::combinations($dataJoinedInheritance) as $combination) {
			[$parentColumn, $parentNullColumn, $childColumn, $childNullColumn] = $combination;
			$child = new JoinedChild();
			$child->id = (string) $id++;
			$child->parentColumn = $parentColumn;
			$child->parentNullColumn = $parentNullColumn;
			$child->childColumn = $childColumn;
			$child->childNullColumn = $childNullColumn;
			$em->persist($child);
		}

		foreach (self::combinations($dataSingleTableInheritance) as $combination) {
			[$parentColumn, $parentNullColumn, $childNullColumn] = $combination;
			$child = new SingleTableChild();
			$child->id = (string) $id++;
			$child->parentColumn = $parentColumn;
			$child->parentNullColumn = $parentNullColumn;
			$child->childNullColumn = $childNullColumn;
			$em->persist($child);
		}

		if (property_exists(Column::class, 'enumType') && PHP_VERSION_ID >= 80100) {
			assert(class_exists(StringEnum::class));
			assert(class_exists(IntEnum::class));

			$entityWithEnum = new EntityWithEnum();
			$entityWithEnum->id = '1';
			$entityWithEnum->stringEnumColumn = StringEnum::A;
			$entityWithEnum->intEnumColumn = IntEnum::A;
			$entityWithEnum->intEnumOnStringColumn = IntEnum::A;
			$em->persist($entityWithEnum);
		}

		$em->flush();
	}

	public static function tearDownAfterClass(): void
	{
		self::$em->clear();
	}

	public function setUp(): void
	{
		$this->descriptorRegistry = self::getContainer()->getByType(DescriptorRegistry::class);
	}

	/** @dataProvider getTestData */
	public function test(Type $expectedType, string $dql, ?string $expectedExceptionMessage = null, ?string $expectedDeprecationMessage = null): void
	{
		$em = self::$em;

		$query = $em->createQuery($dql);

		$typeBuilder = new QueryResultTypeBuilder();

		if ($expectedExceptionMessage !== null) {
			$this->expectException(Throwable::class);
			$this->expectExceptionMessage($expectedExceptionMessage);
		} elseif ($expectedDeprecationMessage !== null) {
			$this->expectDeprecation();
			$this->expectDeprecationMessage($expectedDeprecationMessage);
		}

		QueryResultTypeWalker::walk(
			$query,
			$typeBuilder,
			$this->descriptorRegistry,
			self::getContainer()->getByType(PhpVersion::class),
			self::getContainer()->getByType(DriverDetector::class)
		);

		$type = $typeBuilder->getResultType();

		self::assertSame(
			$expectedType->describe(VerbosityLevel::precise()),
			$type->describe(VerbosityLevel::precise())
		);

		// Double-check our expectations

		$query = $em->createQuery($dql);

		$result = $query->getResult();
		self::assertGreaterThan(0, count($result));

		foreach ($result as $row) {
			$rowType = ConstantTypeHelper::getTypeFromValue($row);
			self::assertTrue(
				$type->accepts($rowType, true)->yes(),
				sprintf(
					"The inferred type\n%s\nshould accept actual type\n%s",
					$type->describe(VerbosityLevel::precise()),
					$rowType->describe(VerbosityLevel::precise())
				)
			);
		}
	}

	/**
	 * @return iterable<string,array{Type,string,2?:string|null}>
	 */
	public function getTestData(): iterable
	{
		$dbalVersion = InstalledVersions::getVersion('doctrine/dbal');
		$hasDbal4 = $dbalVersion !== null && strpos($dbalVersion, '4.') === 0;

		yield 'just root entity' => [
			new ObjectType(One::class),
			'
				SELECT		o
				FROM		QueryResult\Entities\One o
			',
		];

		yield 'just root entity as alias' => [
			$this->constantArray([
				[new ConstantStringType('one'), new ObjectType(One::class)],
			]),
			'
				SELECT		o AS one
				FROM		QueryResult\Entities\One o
			',
		];

		yield 'arbitrary left join, not selected' => [
			new ObjectType(Many::class),
			'
				SELECT		m
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		// The result of arbitrary joins with multiple selected entities
		// is an alternation of all selected entities
		yield 'arbitrary left join, selected' => [
			TypeCombinator::union(
				new ObjectType(Many::class),
				TypeCombinator::addNull(new ObjectType(One::class))
			),
			'
				SELECT		m, o
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'arbitrary inner join, selected' => [
			TypeCombinator::union(
				new ObjectType(Many::class),
				new ObjectType(One::class)
			),
			'
				SELECT		m, o
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'arbitrary left join, selected, some as alias' => [
			TypeCombinator::union(
				$this->constantArray([
					[new ConstantStringType('many'), new ObjectType(Many::class)],
				]),
				$this->constantArray([
					[new ConstantIntegerType(0), TypeCombinator::addNull(new ObjectType(One::class))],
				])
			),
			'
				SELECT		m AS many, o
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'arbitrary left join, selected as alias' => [
			TypeCombinator::union(
				$this->constantArray([
					[new ConstantStringType('many'), new ObjectType(Many::class)],
				]),
				$this->constantArray([
					[new ConstantStringType('one'), TypeCombinator::addNull(new ObjectType(One::class))],
				])
			),
			'
				SELECT		m AS many, o AS one
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'arbitrary inner join, selected as alias' => [
			TypeCombinator::union(
				$this->constantArray([
					[new ConstantStringType('many'), new ObjectType(Many::class)],
				]),
				$this->constantArray([
					[new ConstantStringType('one'), new ObjectType(One::class)],
				])
			),
			'
				SELECT		m AS many, o AS one
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		// In arbitrary joins all non-entity results are returned only with
		// the last declared entity (in FROM/JOIN order)
		yield 'arbitrary inner join selected, and scalars' => [
			TypeCombinator::union(
				$this->constantArray([
					[new ConstantIntegerType(0), new ObjectType(Many::class)],
				]),
				$this->constantArray([
					[new ConstantIntegerType(0), new ObjectType(One::class)],
					[new ConstantStringType('id'), $hasDbal4 ? new IntegerType() : $this->numericString()],
					[new ConstantStringType('intColumn'), new IntegerType()],
				])
			),
			'
				SELECT		m, o, m.id, o.intColumn
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'arbitrary inner join selected, and scalars (selection order variation)' => [
			TypeCombinator::union(
				$this->constantArray([
					[new ConstantIntegerType(0), new ObjectType(One::class)],
				]),
				$this->constantArray([
					[new ConstantIntegerType(0), new ObjectType(Many::class)],
				]),
				$this->constantArray([
					[new ConstantIntegerType(0), new ObjectType(Many::class)],
					[new ConstantStringType('id'), $hasDbal4 ? new IntegerType() : $this->numericString()],
					[new ConstantStringType('intColumn'), new IntegerType()],
				])
			),
			'
				SELECT		o, m2, m, m.id, o.intColumn
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
				JOIN		QueryResult\Entities\Many m2
				WITH		IDENTITY(m2.one) = o.id
			',
		];

		yield 'arbitrary inner join selected as alias, and scalars' => [
			TypeCombinator::union(
				$this->constantArray([
					[new ConstantStringType('many'), new ObjectType(Many::class)],
				]),
				$this->constantArray([
					[new ConstantStringType('one'), new ObjectType(One::class)],
					[new ConstantStringType('id'), $hasDbal4 ? new IntegerType() : $this->numericString()],
					[new ConstantStringType('intColumn'), new IntegerType()],
				])
			),
			'
				SELECT		m AS many, o AS one, m.id, o.intColumn
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'join' => [
			new ObjectType(Many::class),
			'
				SELECT		m
				FROM		QueryResult\Entities\Many m
				JOIN		m.one o
			',
		];

		yield 'fetch-join' => [
			new ObjectType(Many::class),
			'
				SELECT		m, o
				FROM		QueryResult\Entities\Many m
				JOIN		m.one o
			',
		];

		yield 'scalar' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), new IntegerType()],
				[new ConstantStringType('stringColumn'), new StringType()],
				[new ConstantStringType('stringNullColumn'), TypeCombinator::addNull(new StringType())],
				[new ConstantStringType('datetimeColumn'), new ObjectType(DateTime::class)],
				[new ConstantStringType('datetimeImmutableColumn'), new ObjectType(DateTimeImmutable::class)],
			]),
			'
				SELECT		m.intColumn, m.stringColumn, m.stringNullColumn,
							m.datetimeColumn, m.datetimeImmutableColumn
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'scalar with alias' => [
			$this->constantArray([
				[new ConstantStringType('i'), new IntegerType()],
				[new ConstantStringType('s'), new StringType()],
				[new ConstantStringType('sn'), TypeCombinator::addNull(new StringType())],
			]),
			'
				SELECT		m.intColumn AS i, m.stringColumn AS s, m.stringNullColumn AS sn
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'scalar from join' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), new IntegerType()],
				[new ConstantStringType('stringNullColumn'), TypeCombinator::addNull(new StringType())],
			]),
			'
				SELECT		o.intColumn, o.stringNullColumn
				FROM		QueryResult\Entities\Many m
				JOIN		m.one o
			',
		];

		yield 'scalar from left join' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), TypeCombinator::addNull(new IntegerType())],
				[new ConstantStringType('stringNullColumn'), TypeCombinator::addNull(new StringType())],
			]),
			'
				SELECT		o.intColumn, o.stringNullColumn
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	m.one o
			',
		];

		yield 'scalar from arbitrary join' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), new IntegerType()],
				[new ConstantStringType('stringNullColumn'), TypeCombinator::addNull(new StringType())],
			]),
			'
				SELECT		o.intColumn, o.stringNullColumn
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'scalar from arbitrary left join' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), TypeCombinator::addNull(new IntegerType())],
				[new ConstantStringType('stringNullColumn'), TypeCombinator::addNull(new StringType())],
			]),
			'
				SELECT		o.intColumn, o.stringNullColumn
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'just root entity and scalars' => [
			$this->constantArray([
				[new ConstantIntegerType(0), new ObjectType(One::class)],
				[new ConstantStringType('id'), $hasDbal4 ? new IntegerType() : $this->numericString()],
			]),
			'
				SELECT		o, o.id
				FROM		QueryResult\Entities\One o
			',
		];

		yield 'hidden' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), new IntegerType()],
			]),
			'
				SELECT		m.intColumn, m.stringColumn AS HIDDEN sc
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'sub query are not support yet' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new MixedType()],
			]),
			'
				SELECT		(SELECT	m.intColumn FROM QueryResult\Entities\Many m)
				FROM		QueryResult\Entities\Many m2
			',
		];

		yield 'aggregate' => [
			$this->constantArray([
				[
					new ConstantStringType('many'),
					TypeCombinator::addNull(new ObjectType(Many::class)),
				],
				[
					new ConstantIntegerType(1),
					TypeCombinator::addNull($this->intOrStringified()),
				],
				[
					new ConstantIntegerType(2),
					TypeCombinator::addNull(new StringType()),
				],
				[
					new ConstantIntegerType(3),
					$this->uint(),
				],
				[
					new ConstantIntegerType(4),
					TypeCombinator::addNull($this->intOrStringified()),
				],
				[
					new ConstantIntegerType(5),
					$this->uint(),
				],
				[
					new ConstantIntegerType(6),
					TypeCombinator::addNull($this->intOrStringified()),
				],
				[
					new ConstantIntegerType(7),
					$this->intOrStringified(),
				],
			]),
			'
				SELECT		m AS many,
							MAX(m.intColumn), MAX(m.stringNullColumn), COUNT(m.stringNullColumn),
							MAX(o.intColumn), COUNT(o.stringNullColumn),
							MAX(m.intColumn+1),
							COALESCE(MAX(m.intColumn), 0)
				FROM		QueryResult\Entities\Many m
				LEFT JOIN	m.one o
			',
		];

		yield 'aggregate deeper in AST' => [
			$this->constantArray([
				[
					new ConstantStringType('many'),
					TypeCombinator::addNull(new ObjectType(Many::class)),
				],
				[
					new ConstantStringType('max'),
					$this->intOrStringified(),
				],
			]),
			'
				SELECT		m AS many,
							COALESCE(MAX(m.intColumn), 0) as max
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'aggregate lowercase' => [
			$this->constantArray([
				[
					new ConstantStringType('foo'),
					TypeCombinator::addNull($this->floatOrStringified()),
				],
			]),
			'
				SELECT		avg(m.intColumn) as foo
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'aggregate with group by' => [
			$this->constantArray([
				[
					new ConstantStringType('intColumn'),
					new IntegerType(),
				],
				[
					new ConstantStringType('max'),
					$this->intOrStringified(),
				],
				[
					new ConstantStringType('arithmetic'),
					$this->intOrStringified(),
				],
				[
					new ConstantStringType('coalesce'),
					$this->intOrStringified(),
				],
				[
					new ConstantStringType('count'),
					$this->uint(),
				],
			]),
			'
				SELECT		m.intColumn,
							MAX(m.intColumn) AS max,
							m.intColumn+1 AS arithmetic,
							COALESCE(m.intColumn, m.intColumn) AS coalesce,
							COUNT(m.intColumn) AS count
				FROM		QueryResult\Entities\Many m
				GROUP BY	m.intColumn
			',
		];

		yield 'aggregate on literal' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						$this->intOrStringified(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(2),
					TypeCombinator::union(
						$this->intOrStringified(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(3),
					TypeCombinator::union(
						$this->intOrStringified(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(4),
					TypeCombinator::union(
						$this->intOrStringified(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(5),
					TypeCombinator::union(
						$this->floatOrStringified(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(6),
					TypeCombinator::union(
						$this->floatOrStringified(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(7),
					TypeCombinator::addNull($this->intOrStringified()),
				],
				[
					new ConstantIntegerType(8),
					TypeCombinator::addNull($this->intOrStringified()),
				],
			]),
			'
				SELECT		MAX(1),
							MAX(CASE WHEN m.intColumn = 0 THEN 1 ELSE 0 END),
							MIN(1),
							MIN(CASE WHEN m.intColumn = 0 THEN 1 ELSE 0 END),
							AVG(1),
							AVG(CASE WHEN m.intColumn = 0 THEN 1 ELSE 0 END),
							SUM(1),
							SUM(CASE WHEN m.intColumn = 0 THEN 1 ELSE 0 END)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'literal' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					$this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1),
				],
				[new ConstantIntegerType(2), new ConstantStringType('hello')],
			]),
			'
				SELECT		1, \'hello\'
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'nullif' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						$this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1),
						new NullType()
					),
				],
			]),
			'
				SELECT		NULLIF(true, m.id)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'coalesce' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						new StringType(),
						$this->intOrStringified()
					),
				],
				[
					new ConstantIntegerType(2),
					TypeCombinator::union(
						new StringType(),
						new NullType()
					),
				],
				[
					new ConstantIntegerType(3),
					$this->intOrStringified(),
				],
				[
					new ConstantIntegerType(4),
					$this->stringifies()
						? $this->numericString()
						: TypeCombinator::union(
							new IntegerType(),
							new FloatType()
						),
				],
			]),
			'
				SELECT		COALESCE(m.stringNullColumn, m.intColumn, false),
							COALESCE(m.stringNullColumn, m.stringNullColumn),
							COALESCE(NULLIF(m.intColumn, 1), 0),
							COALESCE(1, 1.1)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'general case' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						new StringType(),
						$this->stringifies() ? new ConstantStringType('0') : new ConstantIntegerType(0)
					),
				],
			]),
			'
				SELECT		CASE
								WHEN m.intColumn < 10 THEN m.stringColumn
								WHEN m.intColumn < 20 THEN \'b\'
								ELSE false
							END
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'simple case' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						new StringType(),
						$this->stringifies() ? new ConstantStringType('0') : new ConstantIntegerType(0)
					),
				],
			]),
			'
				SELECT		CASE m.intColumn
								WHEN 10 THEN m.stringColumn
								WHEN 20 THEN \'b\'
								ELSE false
							END
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'Issue 311' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						$this->stringifies() ? new ConstantStringType('0') : new ConstantIntegerType(0),
						$this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1)
					),
				],
			]),
			'
				SELECT		CASE
								WHEN m.intColumn < 10 THEN true
								ELSE false
							END
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'Issue 311 - 2' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					TypeCombinator::union(
						$this->stringifies() ? new ConstantStringType('0') : new ConstantIntegerType(0),
						$this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1)
					),
				],
			]),
			'
				SELECT		CASE
								WHEN m.intColumn < 10 THEN TRUE
								ELSE FALSE
							END
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'Issue 311 - 3' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					$this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1),
				],
				[
					new ConstantIntegerType(2),
					$this->stringifies() ? new ConstantStringType('0') : new ConstantIntegerType(0),
				],
				[
					new ConstantIntegerType(3),
					$this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1),
				],
				[
					new ConstantIntegerType(4),
					$this->stringifies() ? new ConstantStringType('0') : new ConstantIntegerType(0),
				],
			]),
			'
				SELECT		(TRUE), (FALSE), (true), (false)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new' => [
			new ObjectType(ManyId::class),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'news' => [
			$this->constantArray([
				[new ConstantIntegerType(0), new ObjectType(ManyId::class)],
				[new ConstantIntegerType(1), new ObjectType(OneId::class)],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id),
							NEW QueryResult\Entities\OneId(m.id)
				FROM		QueryResult\Entities\Many m
			',
		];

		// Alias on NEW is ignored when there is only no scalars and a
		// single NEW
		yield 'new as alias' => [
			new ObjectType(ManyId::class),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'news as alias' => [
			$this->constantArray([
				[new ConstantStringType('id'), new ObjectType(ManyId::class)],
				[new ConstantStringType('id2'), new ObjectType(OneId::class)],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id,
							NEW QueryResult\Entities\OneId(m.id) as id2
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new and scalars' => [
			$this->constantArray([
				[
					new ConstantStringType('intColumn'),
					new IntegerType(),
				],
				[
					new ConstantStringType('id'),
					new ObjectType(ManyId::class),
				],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id,
							m.intColumn
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new and entity' => [
			new ObjectType(ManyId::class),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id,
							m
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'news and entity' => [
			$this->constantArray([
				[new ConstantIntegerType(0), new ObjectType(Many::class)],
				[new ConstantStringType('id'), new ObjectType(ManyId::class)],
				[new ConstantStringType('id2'), new ObjectType(OneId::class)],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id,
							NEW QueryResult\Entities\OneId(m.id) as id2,
							m
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new, scalars, and entity' => [
			$this->constantArray([
				[
					new ConstantIntegerType(0),
					new ObjectType(ManyId::class),
				],
				[
					new ConstantStringType('intColumn'),
					new IntegerType(),
				],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id),
							m.intColumn,
							m
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new as alias, scalars, and entity' => [
			$this->constantArray([
				[
					new ConstantIntegerType(0),
					new ObjectType(Many::class),
				],
				[
					new ConstantStringType('intColumn'),
					new IntegerType(),
				],
				[
					new ConstantStringType('id'),
					new ObjectType(ManyId::class),
				],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id,
							m.intColumn,
							m
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new as alias, scalars, and entity as alias' => [
			$this->constantArray([
				[
					new ConstantStringType('many'),
					new ObjectType(Many::class),
				],
				[
					new ConstantStringType('intColumn'),
					new IntegerType(),
				],
				[
					new ConstantStringType('id'),
					new ObjectType(ManyId::class),
				],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id) AS id,
							m.intColumn,
							m AS many
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'news, scalars, and entities as alias' => [
			TypeCombinator::union(
				$this->constantArray([
					[
						new ConstantStringType('many'),
						new ObjectType(Many::class),
					],
				]),
				$this->constantArray([
					[
						new ConstantStringType('one'),
						new ObjectType(One::class),
					],
					[
						new ConstantIntegerType(2),
						$this->intOrStringified(),
					],
					[
						new ConstantStringType('intColumn'),
						new IntegerType(),
					],
					[
						new ConstantIntegerType(0),
						new ObjectType(ManyId::class),
					],
					[
						new ConstantIntegerType(1),
						new ObjectType(OneId::class),
					],
				])
			),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id),
							COALESCE(1,1),
							NEW QueryResult\Entities\OneId(m.id),
							m.intColumn,
							m AS many,
							o AS one
				FROM		QueryResult\Entities\Many m
				JOIN		QueryResult\Entities\One o
				WITH		o.id = IDENTITY(m.one)
			',
		];

		yield 'news shadown scalars' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new ObjectType(OneId::class)],
				[new ConstantIntegerType(0), new ObjectType(ManyId::class)],
			]),
			'
				SELECT		NULLIF(m.intColumn, 1),
							NEW QueryResult\Entities\ManyId(m.id),
							NEW QueryResult\Entities\OneId(m.id)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'new arguments affect scalar counter' => [
			$this->constantArray([
				[new ConstantIntegerType(5), TypeCombinator::addNull($this->intOrStringified())],
				[new ConstantIntegerType(0), new ObjectType(ManyId::class)],
				[new ConstantIntegerType(1), new ObjectType(OneId::class)],
			]),
			'
				SELECT		NEW QueryResult\Entities\ManyId(m.id),
							NEW QueryResult\Entities\OneId(m.id, m.id, m.id),
							NULLIF(m.intColumn, 1)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'arithmetic' => [
			$this->constantArray([
				[new ConstantStringType('intColumn'), new IntegerType()],
				[new ConstantIntegerType(1), $this->stringifies() ? new ConstantStringType('1') : new ConstantIntegerType(1)],
				[new ConstantIntegerType(2), $this->intOrStringified()],
				[new ConstantIntegerType(3), TypeCombinator::addNull($this->intOrStringified())],
				[new ConstantIntegerType(4), $this->intOrStringified()],
				[new ConstantIntegerType(5), $this->intOrStringified()],
				[new ConstantIntegerType(6), new MixedType()],
				[new ConstantIntegerType(7), new MixedType()],
			]),
			'
				SELECT		m.intColumn,
							+1,
							1+1,
							1+nullif(1,1),
							m.intColumn*2+m.intColumn+3,
							(1+1),
							\'foo\' + \'bar\',
							\'foo\' * \'bar\'
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'abs function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), $this->uintOrStringified()],
				[new ConstantIntegerType(2), TypeCombinator::addNull($this->uintOrStringified())],
				[new ConstantIntegerType(3), $this->uintOrStringified()],
				[new ConstantIntegerType(4), new MixedType()],
			]),
			'
				SELECT		ABS(m.intColumn),
							ABS(NULLIF(m.intColumn, 1)),
							ABS(1),
							ABS(\'foo\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'abs function with mixed' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new MixedType()],
			]),
			'
				SELECT		ABS(o.mixedColumn)
				FROM		QueryResult\Entities\One o
			',
		];

		yield 'bit_and function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), $this->uintOrStringified()],
				[new ConstantIntegerType(2), TypeCombinator::addNull($this->uintOrStringified())],
				[new ConstantIntegerType(3), $this->uintOrStringified()],
			]),
			'
				SELECT		BIT_AND(m.intColumn, 1),
							BIT_AND(m.intColumn, NULLIF(1,1)),
							BIT_AND(1, 2)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'bit_or function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), $this->uintOrStringified()],
				[new ConstantIntegerType(2), TypeCombinator::addNull($this->uintOrStringified())],
				[new ConstantIntegerType(3), $this->uintOrStringified()],
			]),
			'
				SELECT		BIT_OR(m.intColumn, 1),
							BIT_OR(m.intColumn, NULLIF(1,1)),
							BIT_OR(1, 2)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'concat function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(4), new StringType()],
			]),
			'
				SELECT		CONCAT(m.stringColumn, m.stringColumn),
							CONCAT(m.stringColumn, m.stringNullColumn),
							CONCAT(m.stringColumn, m.stringColumn, m.stringNullColumn),
							CONCAT(\'foo\', \'bar\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'current_ functions' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), new StringType()],
				[new ConstantIntegerType(3), new StringType()],
			]),
			'
				SELECT		CURRENT_DATE(),
							CURRENT_TIME(),
							CURRENT_TIMESTAMP()
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'date_diff function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), $this->floatOrStringified()],
				[new ConstantIntegerType(2), TypeCombinator::addNull($this->floatOrStringified())],
				[new ConstantIntegerType(3), TypeCombinator::addNull($this->floatOrStringified())],
				[new ConstantIntegerType(4), $this->floatOrStringified()],
			]),
			'
				SELECT		DATE_DIFF(m.datetimeColumn, m.datetimeColumn),
							DATE_DIFF(m.stringNullColumn, m.datetimeColumn),
							DATE_DIFF(m.datetimeColumn, m.stringNullColumn),
							DATE_DIFF(\'2020-01-01\', \'2019-01-01\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'length function' => [
			$this->constantArray([
				[
					new ConstantIntegerType(1),
					$this->uint(),
				],
				[
					new ConstantIntegerType(2),
					TypeCombinator::addNull($this->uint()),
				],
				[
					new ConstantIntegerType(3),
					$this->uint(),
				],
			]),
			'
				SELECT		LENGTH(m.stringColumn),
							LENGTH(m.stringNullColumn),
							LENGTH(\'foo\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'lower function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), new StringType()],
			]),
			'
				SELECT		LOWER(m.stringColumn),
							LOWER(m.stringNullColumn),
							LOWER(\'foo\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'mod function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), $this->uintOrStringified()],
				[new ConstantIntegerType(2), TypeCombinator::addNull($this->uintOrStringified())],
				[new ConstantIntegerType(3), TypeCombinator::addNull($this->uintOrStringified())],
				[new ConstantIntegerType(4), $this->uintOrStringified()],
			]),
			'
				SELECT		MOD(m.intColumn, 1),
							MOD(10, m.intColumn),
							MOD(NULLIF(m.intColumn, 10), 2),
							MOD(10, 4)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'mod function error' => [
			$this->constantArray([
				[new ConstantIntegerType(1), TypeCombinator::addNull($this->uintOrStringified())],
			]),
			'
				SELECT		MOD(10, NULLIF(m.intColumn, m.intColumn))
				FROM		QueryResult\Entities\Many m
			',
			InstalledVersions::satisfies(new VersionParser(), 'doctrine/dbal', '<3.5')
				? 'Modulo by zero'
				: null,
		];

		yield 'substring function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(4), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(5), new StringType()],
			]),
			'
				SELECT		SUBSTRING(m.stringColumn, m.intColumn, m.intColumn),
							SUBSTRING(m.stringNullColumn, m.intColumn, m.intColumn),
							SUBSTRING(m.stringColumn, NULLIF(m.intColumn, 1), m.intColumn),
							SUBSTRING(m.stringColumn, m.intColumn, NULLIF(m.intColumn, 1)),
							SUBSTRING(\'foo\', 1, 2)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'trim function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), new StringType()],
			]),
			'
				SELECT		TRIM(LEADING \' \' FROM m.stringColumn),
							TRIM(LEADING \' \' FROM m.stringNullColumn),
							TRIM(LEADING \' \' FROM \'foo\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'upper function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), new StringType()],
			]),
			'
				SELECT		UPPER(m.stringColumn),
							UPPER(m.stringNullColumn),
							UPPER(\'foo\')
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'identity function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), TypeCombinator::addNull($this->intOrStringified())],
				[new ConstantIntegerType(2), $this->intOrStringified()],
				[new ConstantIntegerType(3), TypeCombinator::addNull($this->intOrStringified())],
				[new ConstantIntegerType(4), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(5), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(6), TypeCombinator::addNull($this->intOrStringified())],
				[new ConstantIntegerType(7), TypeCombinator::addNull(new MixedType())],
				[new ConstantIntegerType(8), TypeCombinator::addNull($this->intOrStringified())],
				[new ConstantIntegerType(9), TypeCombinator::addNull($this->intOrStringified())],
			]),
			'
				SELECT		IDENTITY(m.oneNull),
							IDENTITY(m.one),
							IDENTITY(m.oneDefaultNullability),
							IDENTITY(m.compoundPk),
							IDENTITY(m.compoundPk, \'id\'),
							IDENTITY(m.compoundPk, \'version\'),
							IDENTITY(m.compoundPkAssoc),
							IDENTITY(m.compoundPkAssoc, \'version\'),
							IDENTITY(o.subOne)
				FROM		QueryResult\Entities\Many m
				LEFT JOIN   m.oneNull o
			',
		];

		yield 'select nullable association' => [
			$this->constantArray([
				[new ConstantIntegerType(1), TypeCombinator::addNull($this->intOrStringified())],
			]),
			'
				SELECT		DISTINCT(m.oneNull)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'select non null association' => [
			$this->constantArray([
				[new ConstantIntegerType(1), $this->intOrStringified()],
			]),
			'
				SELECT		DISTINCT(m.one)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'select default nullability association' => [
			$this->constantArray([
				[new ConstantIntegerType(1), TypeCombinator::addNull($this->intOrStringified())],
			]),
			'
				SELECT		DISTINCT(m.oneDefaultNullability)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'select non null association in aggregated query' => [
			$this->constantArray([
				[new ConstantIntegerType(1), TypeCombinator::addNull($this->intOrStringified())],
				[
					new ConstantIntegerType(2),
					$this->uint(),
				],
			]),
			'
				SELECT		DISTINCT(m.one), COUNT(m.one)
				FROM		QueryResult\Entities\Many m
			',
		];

		yield 'joined inheritance' => [
			$this->constantArray([
				[new ConstantStringType('parentColumn'), new IntegerType()],
				[new ConstantStringType('childColumn'), new IntegerType()],
			]),
			'
				SELECT		c.parentColumn, c.childColumn
				FROM		QueryResult\Entities\JoinedChild c
			',
		];

		yield 'single table inheritance' => [
			$this->constantArray([
				[new ConstantStringType('parentColumn'), new IntegerType()],
				[new ConstantStringType('childNullColumn'), TypeCombinator::addNull(new IntegerType())],
			]),
			'
				SELECT		c.parentColumn, c.childNullColumn
				FROM		QueryResult\Entities\SingleTableChild c
			',
		];

		yield 'embedded' => [
			$this->constantArray([
				[new ConstantStringType('embedded.intColumn'), new IntegerType()],
				[new ConstantStringType('embedded.stringNullColumn'), TypeCombinator::addNull(new StringType())],
				[new ConstantStringType('embedded.nestedEmbedded.intColumn'), new IntegerType()],
				[new ConstantStringType('embedded.nestedEmbedded.stringNullColumn'), TypeCombinator::addNull(new StringType())],
			]),
			'
				SELECT		o.embedded.intColumn,
							o.embedded.stringNullColumn,
							o.embedded.nestedEmbedded.intColumn,
							o.embedded.nestedEmbedded.stringNullColumn
				FROM		QueryResult\Entities\One o
			',
		];

		yield 'unary minus' => [
			$this->constantArray([
				[new ConstantStringType('minusInt'), $this->stringifies() ? new ConstantStringType('-1') : new ConstantIntegerType(-1)],
				[new ConstantStringType('minusFloat'), $this->stringifies() ? $this->numericString() : new ConstantFloatType(-0.1)],
				[new ConstantStringType('minusIntRange'), $this->stringifies() ? $this->numericString() : IntegerRangeType::fromInterval(null, 0)],
			]),
			'
				SELECT		-1 as minusInt,
							-0.1 as minusFloat,
							-COUNT(o.intColumn) as minusIntRange
				FROM		QueryResult\Entities\One o
			',
		];

		yield from $this->yieldConditionalDataset();
	}

	/**
	 * @return iterable<mixed>
	 */
	private function yieldConditionalDataset(): iterable
	{
		if (property_exists(Column::class, 'enumType') && PHP_VERSION_ID >= 80100) {
			assert(class_exists(StringEnum::class));
			assert(class_exists(IntEnum::class));

			// https://github.com/doctrine/orm/issues/9622
			if (!$this->isDoctrine211()) {
				yield 'enum' => [
					$this->constantArray([
						[new ConstantStringType('stringEnumColumn'), new ObjectType(StringEnum::class)],
						[new ConstantStringType('intEnumColumn'), new ObjectType(IntEnum::class)],
					]),
					'
						SELECT		e.stringEnumColumn, e.intEnumColumn
						FROM		QueryResult\EntitiesEnum\EntityWithEnum e
					',
				];
			}

			yield 'enum in expression' => [
				$this->constantArray([
					[
						new ConstantIntegerType(1),
						new StringType(),
					],
					[
						new ConstantIntegerType(2),
						new IntegerType(),
					],
					[
						new ConstantIntegerType(3),
						$this->numericString(),
					],
				]),
				'
					SELECT		COALESCE(e.stringEnumColumn, e.stringEnumColumn),
								COALESCE(e.intEnumColumn, e.intEnumColumn),
								COALESCE(e.intEnumOnStringColumn, e.intEnumOnStringColumn)
					FROM		QueryResult\EntitiesEnum\EntityWithEnum e
				',
			];
		}

		if (PHP_VERSION_ID >= 70400) {
			yield 'locate function' => [
				$this->constantArray([
					[new ConstantIntegerType(1), $this->uintOrStringified()],
					[new ConstantIntegerType(2), TypeCombinator::addNull($this->uintOrStringified())],
					[new ConstantIntegerType(3), TypeCombinator::addNull($this->uintOrStringified())],
					[new ConstantIntegerType(4), $this->uintOrStringified()],
				]),
				'
				SELECT		LOCATE(m.stringColumn, m.stringColumn, 0),
							LOCATE(m.stringNullColumn, m.stringColumn, 0),
							LOCATE(m.stringColumn, m.stringNullColumn, 0),
							LOCATE(\'f\', \'foo\', 0)
				FROM		QueryResult\Entities\Many m
			',
				null,
				InstalledVersions::satisfies(new VersionParser(), 'doctrine/dbal', '>=3.4')
					? null
					: (
				PHP_VERSION_ID >= 80100
					? 'strpos(): Passing null to parameter #2 ($needle) of type string is deprecated'
					: (
				PHP_VERSION_ID < 80000
					? 'strpos(): Non-string needles will be interpreted as strings in the future. Use an explicit chr() call to preserve the current behavior'
					: null
					)
				),
			];
		}

		$ormVersion = InstalledVersions::getVersion('doctrine/orm');
		$hasOrm3 = $ormVersion !== null && strpos($ormVersion, '3.') === 0;

		if ($hasOrm3) {
			return;
		}

		yield 'date_add function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(4), new StringType()],
			]),
			'
            SELECT      DATE_ADD(m.datetimeColumn, m.intColumn, \'day\'),
                        DATE_ADD(m.stringNullColumn, m.intColumn, \'day\'),
                        DATE_ADD(m.datetimeColumn, NULLIF(m.intColumn, 1), \'day\'),
                        DATE_ADD(\'2020-01-01\', 7, \'day\')
            FROM        QueryResult\Entities\Many m
        ',
		];

		yield 'date_sub function' => [
			$this->constantArray([
				[new ConstantIntegerType(1), new StringType()],
				[new ConstantIntegerType(2), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(3), TypeCombinator::addNull(new StringType())],
				[new ConstantIntegerType(4), new StringType()],
			]),
			'
            SELECT      DATE_SUB(m.datetimeColumn, m.intColumn, \'day\'),
                        DATE_SUB(m.stringNullColumn, m.intColumn, \'day\'),
                        DATE_SUB(m.datetimeColumn, NULLIF(m.intColumn, 1), \'day\'),
                        DATE_SUB(\'2020-01-01\', 7, \'day\')
            FROM        QueryResult\Entities\Many m
        ',
		];
	}

	/**
	 * @param array<int,array{0: ConstantIntegerType|ConstantStringType, 1: Type, 2?: bool}> $elements
	 */
	private function constantArray(array $elements): Type
	{
		$builder = ConstantArrayTypeBuilder::createEmpty();

		foreach ($elements as $element) {
			$offsetType = $element[0];
			$valueType = $element[1];
			$optional = $element[2] ?? false;
			$builder->setOffsetValueType($offsetType, $valueType, $optional);
		}

		return $builder->getArray();
	}

	private function numericString(): Type
	{
		return new IntersectionType([
			new StringType(),
			new AccessoryNumericStringType(),
		]);
	}

	private function uint(): Type
	{
		return IntegerRangeType::fromInterval(0, null);
	}

	/**
	 * @param array<mixed[]> $arrays
	 *
	 * @return iterable<mixed[]>
	 */
	private static function combinations(array $arrays): iterable
	{
		if ($arrays === []) {
			yield [];
			return;
		}

		$head = array_shift($arrays);

		foreach ($head as $elem) {
			foreach (self::combinations($arrays) as $combination) {
				yield array_merge([$elem], $combination);
			}
		}
	}

	private function isDoctrine211(): bool
	{
		$version = InstalledVersions::getVersion('doctrine/orm');

		return $version !== null
			&& version_compare($version, '2.11', '>=')
			&& version_compare($version, '2.12', '<');
	}

	private function stringifies(): bool
	{
		return PHP_VERSION_ID < 80100;
	}

	private function intOrStringified(): Type
	{
		return $this->stringifies()
			? $this->numericString()
			: new IntegerType();
	}

	private function uintOrStringified(): Type
	{
		return $this->stringifies()
			? $this->numericString()
			: $this->uint();
	}

	private function floatOrStringified(): Type
	{
		return $this->stringifies()
			? $this->numericString()
			: new FloatType();
	}

}
