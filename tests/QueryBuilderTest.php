<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Util\Test;
use ReflectionClass;
use Spatie\QueryBuilder\Exceptions\InvalidSubject;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\QueryBuilderRequest;
use Spatie\QueryBuilder\Sorts\Sort;
use Spatie\QueryBuilder\Tests\TestClasses\Models\NestedRelatedModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\RelatedThroughPivotModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\ScopeModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\SoftDeleteModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\TestModel;

uses(TestCase::class);

it('can be given an eloquent query using where', function () {
    $queryBuilder = QueryBuilder::for(TestModel::where('id', 1));

    $eloquentBuilder = TestModel::where('id', 1);

    $this->assertEquals(
        $eloquentBuilder->toSql(),
        $queryBuilder->toSql()
    );
});

it('can be given an eloquent query using select', function () {
    $queryBuilder = QueryBuilder::for(TestModel::select('id', 'name'));

    $eloquentBuilder = TestModel::select('id', 'name');

    $this->assertEquals(
        $eloquentBuilder->toSql(),
        $queryBuilder->toSql()
    );
});

it('can be given a belongs to many relation query', function () {
    $testModel = TestModel::create(['id' => 321, 'name' => 'John Doe']);
    $relatedThroughPivotModel = RelatedThroughPivotModel::create(['id' => 789, 'name' => 'The related model']);

    $testModel->relatedThroughPivotModels()->attach($relatedThroughPivotModel);

    $queryBuilderResult = QueryBuilder::for($testModel->relatedThroughPivotModels())->first();

    $this->assertEquals(789, $queryBuilderResult->id);
});

it('can be given a belongs to many relation query with pivot', function () {
    /** @var TestModel $testModel */
    $testModel = TestModel::create(['id' => 329, 'name' => 'Illia']);

    $queryBuilder = QueryBuilder::for($testModel->relatedThroughPivotModelsWithPivot());

    $eloquentBuilder = $testModel->relatedThroughPivotModelsWithPivot();

    $this->assertEquals(
        $eloquentBuilder->toSql(),
        $queryBuilder->toSql()
    );
});

it('can be given a model class name', function () {
    $queryBuilder = QueryBuilder::for(TestModel::class);

    $this->assertEquals(
        TestModel::query()->toSql(),
        $queryBuilder->toSql()
    );
});

it('can not be given a string that is not a class name', function () {
    $this->expectException(InvalidSubject::class);

    $this->expectExceptionMessage('Subject type `string` is invalid.');

    QueryBuilder::for('not a class name');
});

it('can not be given an object that is neither relation nor eloquent builder', function () {
    $this->expectException(InvalidSubject::class);

    $this->expectExceptionMessage(sprintf('Subject class `%s` is invalid.', self::class));

    QueryBuilder::for($this);
});

it('will determine the request when its not given', function () {
    $builderReflection = new ReflectionClass(QueryBuilder::class);
    $requestProperty = $builderReflection->getProperty('request');
    $requestProperty->setAccessible(true);

    $this->getJson('/test-model?sort=name');

    $builder = QueryBuilder::for(TestModel::class);

    $this->assertInstanceOf(QueryBuilderRequest::class, $requestProperty->getValue($builder));
    $this->assertEquals(['name'], $requestProperty->getValue($builder)->sorts()->toArray());
});

it('can query soft deletes', function () {
    $queryBuilder = QueryBuilder::for(SoftDeleteModel::class);

    $this->models = SoftDeleteModel::factory()->count(5)->create();

    $this->assertCount(5, $queryBuilder->get());

    $this->models[0]->delete();

    $this->assertCount(4, $queryBuilder->get());
    $this->assertCount(5, $queryBuilder->withTrashed()->get());
});

it('can query global scopes', function () {
    ScopeModel::create(['name' => 'John Doe']);
    ScopeModel::create(['name' => 'test']);

    // Global scope on ScopeModel excludes models named 'test'
    $this->assertCount(1, QueryBuilder::for(ScopeModel::class)->get());

    $this->assertCount(2, QueryBuilder::for(ScopeModel::query()->withoutGlobalScopes())->get());

    $this->assertCount(2, QueryBuilder::for(ScopeModel::class)->withoutGlobalScopes()->get());
});

it('keeps eager loaded relationships from the base query', function () {
    TestModel::create(['name' => 'John Doe']);

    $baseQuery = TestModel::with('relatedModels');
    $queryBuilder = QueryBuilder::for($baseQuery);

    $this->assertTrue($baseQuery->first()->relationLoaded('relatedModels'));
    $this->assertTrue($queryBuilder->first()->relationLoaded('relatedModels'));
});

it('keeps local macros added to the base query', function () {
    $baseQuery = TestModel::query();

    $baseQuery->macro('customMacro', function ($builder) {
        return $builder->where('name', 'Foo');
    });

    $queryBuilder = QueryBuilder::for(clone $baseQuery);

    $this->assertEquals(
        $baseQuery->customMacro()->toSql(),
        $queryBuilder->customMacro()->toSql()
    );
});

it('keeps the on delete callback added to the base query', function () {
    $baseQuery = TestModel::query();

    $baseQuery->onDelete(function () {
        return 'onDelete called';
    });

    $this->assertEquals('onDelete called', QueryBuilder::for($baseQuery)->delete());
});

it('can query local scopes', function () {
    $queryBuilderQuery = QueryBuilder::for(TestModel::class)
        ->named('john')
        ->toSql();

    $expectedQuery = TestModel::query()->where('name', 'john')->toSql();

    $this->assertEquals($expectedQuery, $queryBuilderQuery);
});

it('executes the same query regardless of the order of applied filters or sorts', function () {
    $customSort = new class () implements Sort {
        public function __invoke(Builder $query, $descending, string $property): Builder
        {
            return $query->join(
                'related_models',
                'test_models.id',
                '=',
                'related_models.test_model_id'
            )->orderBy('related_models.name', $descending ? 'desc' : 'asc');
        }
    };

    $req = new Request([
        'filter' => ['name' => 'test'],
        'sort' => 'name',
    ]);

    $usingSortFirst = QueryBuilder::for(TestModel::class, $req)
        ->allowedSorts(\Spatie\QueryBuilder\AllowedSort::custom('name', $customSort))
        ->allowedFilters('name')
        ->toSql();

    $usingFilterFirst = QueryBuilder::for(TestModel::class, $req)
        ->allowedFilters('name')
        ->allowedSorts(\Spatie\QueryBuilder\AllowedSort::custom('name', $customSort))
        ->toSql();

    $this->assertEquals($usingSortFirst, $usingFilterFirst);
});

it('can filter when sorting by joining a related model which contains the same field name', function () {
    $customSort = new class () implements Sort {
        public function __invoke(Builder $query, $descending, string $property): Builder
        {
            return $query->join(
                'related_models',
                'nested_related_models.related_model_id',
                '=',
                'related_models.id'
            )->orderBy('related_models.name', $descending ? 'desc' : 'asc');
        }
    };

    $req = new Request([
        'filter' => ['name' => 'test'],
        'sort' => 'name',
    ]);

    QueryBuilder::for(NestedRelatedModel::class, $req)
        ->allowedSorts(\Spatie\QueryBuilder\AllowedSort::custom('name', $customSort))
        ->allowedFilters('name')
        ->get();

    $this->assertTrue(true);
});

it('queries the correct data for a relationship query', function () {
    $testModel = TestModel::create(['id' => 321, 'name' => 'John Doe']);
    $relatedThroughPivotModel = RelatedThroughPivotModel::create(['id' => 789, 'name' => 'The related model']);

    $testModel->relatedThroughPivotModels()->attach($relatedThroughPivotModel);

    $relationship = $testModel->relatedThroughPivotModels()->with('testModels');

    $queryBuilderResult = QueryBuilder::for($relationship)->first();

    $this->assertEquals(789, $queryBuilderResult->id);
    $this->assertEquals(321, $queryBuilderResult->testModels->first()->id);
});

it('does not lose pivot values with belongs to many relation', function () {
    /** @var TestModel $testModel */
    $testModel = TestModel::create(['id' => 324, 'name' => 'Illia']);

    /** @var RelatedThroughPivotModel $relatedThroughPivotModel */
    $relatedThroughPivotModel = RelatedThroughPivotModel::create(['id' => 721, 'name' => 'Kate']);

    $testModel->relatedThroughPivotModelsWithPivot()->attach($relatedThroughPivotModel, ['location' => 'Wood Cottage']);

    $foundTestModel = QueryBuilder::for($testModel->relatedThroughPivotModelsWithPivot())
        ->first();

    $this->assertSame(
        'Wood Cottage',
        $foundTestModel->pivot->location
    );
});

it('clones the subject upon cloning', function () {
    $queryBuilder = QueryBuilder::for(TestModel::class);

    $queryBuilder1 = (clone $queryBuilder)->where('id', 1);
    $queryBuilder2 = (clone $queryBuilder)->where('name', 'John Doe');

    $this->assertNotSame(
        $queryBuilder1->toSql(),
        $queryBuilder2->toSql()
    );
});

it('supports clone as method', function () {
    $queryBuilder = QueryBuilder::for(TestModel::class);

    $queryBuilder1 = $queryBuilder->clone()->where('id', 1);
    $queryBuilder2 = $queryBuilder->clone()->where('name', 'John Doe');

    $this->assertNotSame(
        $queryBuilder1->toSql(),
        $queryBuilder2->toSql()
    );
});
