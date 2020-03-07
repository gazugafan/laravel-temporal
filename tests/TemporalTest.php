<?php

use Carbon\Carbon;
use Gazugafan\Temporal\Exceptions\TemporalException;
use Gazugafan\Temporal\Temporal;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Container\Container;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Events\Dispatcher;

class TemporalTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $db = new DB();

        $db->addConnection([
            'driver'    => 'mysql',
            'database'  => 'testbed',
            'host'  => '127.0.0.1',
            'port'  => '3306',
            'username'  => 'root',
            'password'  => 'root',
        ]);

        $db->setEventDispatcher(new Dispatcher(new Container()));
        $db->setAsGlobal();
        $db->bootEloquent();

        $this->createSchema();
        $this->seed();
        $this->resetListeners();
    }

    /**
     * Setup the database schema.
     *
     * @return void
     */
    public function createSchema()
    {
		/**
		 * DON'T DO THIS!!! THIS IS JUST A QUICK AND DIRTY WAY TO CREATE THE TESTING TABLE WITHOUT THE DB FACADE.
		 * INSTEAD, YOU CAN EASILY CREATE TEMPORAL TABLES LIKE THIS...
		 *
		 *	$this->schema()->create('widgets', function ($table) {
		 *		$table->increments('id');
		 *		$table->timestamps();
		 *		$table->string('name');
		 *	});
		 *	Migration::make_temporal('widgets');
		 *
		 * NO NEED TO DO THE FOLLOWING...
		 */
        $this->schema()->create('widgets', function ($table) {
            $table->unsignedInteger('id');
            $table->unsignedMediumInteger('version');
            $table->dateTime('temporal_start');
            $table->dateTime('temporal_end');
            $table->timestamps();
			$table->string('name');
			$table->string('worthless')->nullable();
			$table->primary(['id', 'version']);
			$table->index(['temporal_end', 'id']);
			$table->index(['id', 'temporal_start', 'temporal_end']);
        });
		$this->connection()->statement('ALTER TABLE widgets MODIFY id INTEGER NOT NULL AUTO_INCREMENT;');

    }

	/**
	 * Seeds the database
	 */
    public function seed()
	{
		$records = rand(10, 50);
		for($x = 0; $x < $records; $x++)
		{
			$widget = new Widget();
			$widget->name = str_random();
			$widget->worthless = str_random();
			$widget->save();

			$versions = rand(0, 5);
			for($y = 0; $y < $versions; $y++)
			{
				$widget->name = str_random();
				$widget->worthless = str_random();
				$widget->save();
			}
		}
	}

    /**
     * Address a testing issue where model listeners are not reset.
     */
    public function resetListeners()
    {
        Widget::flushEventListeners();
    }

    /**
     * Tear down the database schema.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->schema()->drop('widgets');
    }

    public function testSaveNewInsertsWithTemporalColumnsFilledIn()
    {
		$widget = new Widget(['name' => 'test1']);
        $widget->save();

        $this->assertEquals(Carbon::parse($widget->temporal_end), Carbon::parse($widget->getTemporalMax()));
        $this->assertLessThan(5, Carbon::parse($widget->temporal_start)->diffInSeconds());
		$this->assertEquals(1, $widget->version);

		$widget = Widget::create(['name'=>'test1']);

        $this->assertEquals(Carbon::parse($widget->temporal_end), Carbon::parse($widget->getTemporalMax()));
        $this->assertLessThan(5, Carbon::parse($widget->temporal_start)->diffInSeconds());
		$this->assertEquals(1, $widget->version);
    }

    public function testSaveExistingInsertsNewVersion()
    {
		$widget = new Widget(['name' => 'test1']);
        $widget->save();
		$this->assertEquals(1, $widget->version);
        $original_date = $widget->temporal_start;

        sleep(2);

        $widget->name = 'test2';
        $widget->save();
		$this->assertEquals(2, $widget->version);
		$this->assertEquals(Carbon::parse($widget->temporal_end), Carbon::parse($widget->getTemporalMax()));
		$this->assertTrue(Carbon::parse($widget->temporal_start)->greaterThan($original_date));
    }

    public function testUpdateInsertsNewVersion()
    {
		$widget = new Widget(['name' => 'test1']);
        $widget->save();
		$this->assertEquals(1, $widget->version);
        $original_date = $widget->temporal_start;

        sleep(2);

        $widget->update(['name'=>'test2']);
		$this->assertEquals('test2', $widget->name);
		$this->assertEquals(2, $widget->version);
		$this->assertEquals(Carbon::parse($widget->temporal_end), Carbon::parse($widget->getTemporalMax()));
		$this->assertTrue(Carbon::parse($widget->temporal_start)->greaterThan($original_date));
    }

    public function testOverwriteRemovesPreviousVersion()
    {
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		sleep(2);

		$widget->name = 'test3';
		$widget->overwrite();

		$this->assertEquals(2, $widget->version);
		$this->assertGreaterThan(1, Carbon::parse($widget->temporal_start)->diffInSeconds());
		$this->assertLessThan(2, Carbon::parse($widget->updated_at)->diffInSeconds());

		$latestWidget = Widget::find($widget->id);
		$this->assertEquals('test3', $latestWidget->name);
		$this->assertEquals(2, $latestWidget->version);
		$this->assertGreaterThan(1, Carbon::parse($latestWidget->temporal_start)->diffInSeconds());
		$this->assertLessThan(2, Carbon::parse($latestWidget->updated_at)->diffInSeconds());

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(2, $allVersions);
    }

	public function testChangeOverwritableOverwrites()
	{
		$widget = new Widget(['name' => 'test1', 'worthless'=>'a']);
		$widget->save();
		$this->assertEquals(1, $widget->version);
		$original_date = $widget->temporal_start;

		sleep(2);

		$widget->worthless = 'b';
		$widget->save();

		$this->assertEquals(1, $widget->version);
		$this->assertGreaterThan(1, Carbon::parse($widget->temporal_start)->diffInSeconds());
		$this->assertLessThan(2, Carbon::parse($widget->updated_at)->diffInSeconds());

		$latestWidget = Widget::find($widget->id);
		$this->assertEquals('b', $latestWidget->worthless);
		$this->assertEquals(1, $latestWidget->version);
		$this->assertGreaterThan(1, Carbon::parse($latestWidget->temporal_start)->diffInSeconds());
		$this->assertLessThan(2, Carbon::parse($latestWidget->updated_at)->diffInSeconds());

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(1, $allVersions);
	}

    public function testQuickSavesCauseNoVersionConflicts()
    {
		$widget = new Widget(['name' => 'test0']);
		$widget->save();

    	for($x = 1; $x < 10; $x++)
		{
			$widget->name = 'test' . $x;
			$widget->save();
		}

    	$this->assertTrue(true);
    }

	public function testGlobalScopeFindsLatestVersion()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		$latestWidget = Widget::find($widget->id);
		$this->assertEquals($latestWidget->id, $widget->id);
		$this->assertEquals(2, $latestWidget->version);
		$this->assertEquals(Carbon::parse($latestWidget->temporal_end), Carbon::parse($widget->getTemporalMax()));

		$latestWidgets = Widget::where('id', $widget->id)->get();
		$this->assertCount(1, $latestWidgets);
		$latestWidget = $latestWidgets->first();
		$this->assertEquals($latestWidget->id, $widget->id);
		$this->assertEquals(2, $latestWidget->version);
		$this->assertEquals(Carbon::parse($latestWidget->temporal_end), Carbon::parse($widget->getTemporalMax()));
	}

	public function testDeleteUpdatesTemporalEnd()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		sleep(3);

		$widget->delete();
		$this->assertLessThan(2, Carbon::parse($widget->temporal_end)->diffInSeconds());

		$deletedWidget = Widget::find($widget->id);
		$this->assertNull($deletedWidget);

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(2, $allVersions);

		$allVersions = Widget::where('id', $widget->id)->get();
		$this->assertCount(0, $allVersions);
	}

	public function testDeleteQueryUpdatesTemporalEnd()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		sleep(3);

		Widget::where('id', $widget->id)->delete();

		$deletedWidget = Widget::find($widget->id);
		$this->assertNull($deletedWidget);

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(2, $allVersions);
		$this->assertLessThan(2, Carbon::parse($allVersions->last()->temporal_end)->diffInSeconds());

		$allVersions = Widget::where('id', $widget->id)->get();
		$this->assertCount(0, $allVersions);
	}

	public function testDestroyUpdatesTemporalEnd()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		sleep(3);

		Widget::destroy($widget->id);

		$deletedWidget = Widget::find($widget->id);
		$this->assertNull($deletedWidget);

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(2, $allVersions);
		$this->assertLessThan(2, Carbon::parse($allVersions->last()->temporal_end)->diffInSeconds());

		$allVersions = Widget::where('id', $widget->id)->get();
		$this->assertCount(0, $allVersions);
	}

	public function testRestoreDeletedUpdatesTemporalEnd()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		$widget->delete();
		$deletedWidget = Widget::find($widget->id);
		$this->assertNull($deletedWidget);

		sleep(2);

		$result = $widget->restore();
		$this->assertTrue($result);
		$this->assertEquals(2, $widget->version);
		$this->assertEquals(Carbon::parse($widget->temporal_end), Carbon::parse($widget->getTemporalMax()));
		$this->assertLessThan(2, Carbon::parse($widget->updated_at)->diffInSeconds());

		$restoredWidget = Widget::find($widget->id);
		$this->assertNotNull($restoredWidget);
		$this->assertEquals($restoredWidget->id, $widget->id);
		$this->assertEquals(2, $restoredWidget->version);
		$this->assertEquals(Carbon::parse($restoredWidget->temporal_end), Carbon::parse($widget->getTemporalMax()));
		$this->assertLessThan(2, Carbon::parse($restoredWidget->updated_at)->diffInSeconds());

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(2, $allVersions);

		$allVersions = Widget::where('id', $widget->id)->get();
		$this->assertCount(1, $allVersions);
	}

	public function testRestoreActiveDoesNothing()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		sleep(2);

		$result = $widget->restore();
		$this->assertFalse($result);
		$this->assertEquals(2, $widget->version);
		$this->assertEquals(Carbon::parse($widget->temporal_end), Carbon::parse($widget->getTemporalMax()));
		$this->assertGreaterThan(1, Carbon::parse($widget->updated_at)->diffInSeconds());

		$restoredWidget = Widget::find($widget->id);
		$this->assertNotNull($restoredWidget);
		$this->assertEquals($restoredWidget->id, $widget->id);
		$this->assertEquals(2, $restoredWidget->version);
		$this->assertEquals(Carbon::parse($restoredWidget->temporal_end), Carbon::parse($widget->getTemporalMax()));
		$this->assertGreaterThan(1, Carbon::parse($restoredWidget->updated_at)->diffInSeconds());

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(2, $allVersions);

		$allVersions = Widget::where('id', $widget->id)->get();
		$this->assertCount(1, $allVersions);
	}

	public function testPurgeDeletesAll()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();

		$result = $widget->purge();
		$this->assertTrue($result);
		$this->assertFalse($widget->exists);

		$allVersions = Widget::withoutGlobalScopes()->where('id', $widget->id)->get();
		$this->assertCount(0, $allVersions);

		$everything = Widget::where('id', '<>', $widget->id)->get();
		$this->assertGreaterThan(0, count($everything));
	}

	public function testConflictingVersions()
	{
		$widget1 = new Widget(['name' => 'test1']);
		$widget1->save();

		sleep(2);

		$widget2 = Widget::find($widget1->id);
		$widget2->name = 'test2';
		$widget2->save();

		sleep(2);

		$this->expectException(Exception::class);

		$widget1->name = 'test3';
		$widget1->save();
	}

	public function testPreviousFindsPreviousVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$previousWidget = $widget->previousVersion();
		$this->assertNotNull($previousWidget);
		$this->assertEquals($previousWidget->id, $widget->id);
		$this->assertEquals(2, $previousWidget->version);

		$previousWidget = $previousWidget->previousVersion();
		$this->assertNotNull($previousWidget);
		$this->assertEquals($previousWidget->id, $widget->id);
		$this->assertEquals(1, $previousWidget->version);

		$previousWidget = $previousWidget->previousVersion();
		$this->assertNull($previousWidget);
	}

	public function testFirstFindsFirstVersion()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$firstWidget = $widget->firstVersion();
		$this->assertNotNull($firstWidget);
		$this->assertEquals($firstWidget->id, $widget->id);
		$this->assertEquals(1, $firstWidget->version);
	}

	public function testNextFindsNextVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$firstWidget = $widget->firstVersion();
		$nextWidget = $firstWidget->nextVersion();
		$this->assertNotNull($nextWidget);
		$this->assertEquals($nextWidget->id, $widget->id);
		$this->assertEquals(2, $nextWidget->version);

		$nextWidget = $nextWidget->nextVersion();
		$this->assertNotNull($nextWidget);
		$this->assertEquals($nextWidget->id, $widget->id);
		$this->assertEquals(3, $nextWidget->version);

		$nextWidget = $nextWidget->nextVersion();
		$this->assertNull($nextWidget);
	}

	public function testCurrentFindsCurrentVersion()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$currentWidget = $widget->currentVersion();
		$this->assertNotNull($currentWidget);
		$this->assertEquals($currentWidget->id, $widget->id);

		$widget->delete();
		$currentWidget = $widget->currentVersion();
		$this->assertNull($currentWidget);
	}

	public function testLatestFindsLatestVersion()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$latestWidget = $widget->latestVersion();
		$this->assertNotNull($latestWidget);
		$this->assertEquals($latestWidget->id, $widget->id);
		$this->assertEquals(3, $latestWidget->version);

		$widget->delete();
		$latestWidget = $widget->latestVersion();
		$this->assertNotNull($latestWidget);
		$this->assertEquals($latestWidget->id, $widget->id);
		$this->assertEquals(3, $latestWidget->version);
		$this->assertLessThan(2, Carbon::parse($latestWidget->temporal_end)->diffInSeconds());
	}

	public function testAtVersionFindsVersion()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$currentWidget = $widget->atVersion(1);
		$this->assertNotNull($currentWidget);
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(1, $currentWidget->version);

		$currentWidget = $widget->atVersion(2);
		$this->assertNotNull($currentWidget);
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(2, $currentWidget->version);

		$currentWidget = $widget->atVersion(3);
		$this->assertNotNull($currentWidget);
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(3, $currentWidget->version);

		$currentWidget = $widget->atVersion(4);
		$this->assertNull($currentWidget);
	}

	public function testAtDateFindsVersion()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		sleep(2);
		$widget->name = 'test2';
		$widget->save();
		sleep(2);
		$widget->name = 'test3';
		$widget->save();

		$currentWidget = $widget->atDate(Carbon::now()->subSeconds(1));
		$this->assertNotNull($currentWidget);
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(2, $currentWidget->version);

		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();
		sleep(2);
		$widget->name = 'test4';
		$widget->save();

		$currentWidget = $widget->atDate(Carbon::now()->subSeconds(1));
		$this->assertNotNull($currentWidget);
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(3, $currentWidget->version);
	}

	public function testInRangeFindsVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		sleep(2);
		$widget->name = 'test2';
		$widget->save();
		sleep(2);
		$widget->name = 'test3';
		$widget->save();

		$atWidgets = $widget->inRange(Carbon::now()->subSeconds(3), Carbon::now()->subSeconds(1));
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(1, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(2, $atWidgets->get(1)->version);

		$atWidgets = $widget->inRange(Carbon::now()->subSeconds(1));
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(2, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(3, $atWidgets->get(1)->version);

		$atWidgets = $widget->inRange(null, Carbon::now()->subSeconds(1));
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(1, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(2, $atWidgets->get(1)->version);
	}

	public function testAllVersionsFindsAllVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$allWidgets = Widget::where('id', $widget->id)->allVersions()->get();
		$this->assertCount(3, $allWidgets);

		$allWidgets = Widget::allVersions()->where('id', $widget->id)->get();
		$this->assertCount(3, $allWidgets);
	}

	public function testCurrentVersionsFindsCurrentVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$currentWidgets = Widget::allVersions()->where('id', $widget->id)->currentVersions()->get();
		$this->assertCount(1, $currentWidgets);
		$currentWidget = $currentWidgets->first();
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(3, $currentWidget->version);

		$currentWidgets = Widget::allVersions()->currentVersions()->where('id', $widget->id)->get();
		$this->assertCount(1, $currentWidgets);
		$currentWidget = $currentWidgets->first();
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(3, $currentWidget->version);
	}

	public function testCurrentVersionsStaticFindsCurrentVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$currentWidgets = Widget::currentVersions()->where('id', $widget->id)->get();
		$this->assertCount(1, $currentWidgets);
		$currentWidget = $currentWidgets->first();
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(3, $currentWidget->version);

		$currentWidgets = Widget::currentVersions()->where('id', $widget->id)->get();
		$this->assertCount(1, $currentWidgets);
		$currentWidget = $currentWidgets->first();
		$this->assertEquals($currentWidget->id, $widget->id);
		$this->assertEquals(3, $currentWidget->version);
	}

	public function testFirstVersionsFindsFirstVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$firstWidgets = Widget::where('id', $widget->id)->firstVersions()->get();
		$this->assertCount(1, $firstWidgets);
		$firstWidget = $firstWidgets->first();
		$this->assertEquals($firstWidget->id, $widget->id);
		$this->assertEquals(1, $firstWidget->version);

		$firstWidgets = Widget::firstVersions()->where('id', $widget->id)->get();
		$this->assertCount(1, $firstWidgets);
		$firstWidget = $firstWidgets->first();
		$this->assertEquals($firstWidget->id, $widget->id);
		$this->assertEquals(1, $firstWidget->version);
	}

	public function testVersionsAtFindsAtVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$atWidgets = Widget::where('id', $widget->id)->versionsAt(2)->get();
		$this->assertCount(1, $atWidgets);
		$atWidget = $atWidgets->first();
		$this->assertEquals($atWidget->id, $widget->id);
		$this->assertEquals(2, $atWidget->version);

		$atWidgets = Widget::versionsAt(2)->where('id', $widget->id)->get();
		$this->assertCount(1, $atWidgets);
		$atWidget = $atWidgets->first();
		$this->assertEquals($atWidget->id, $widget->id);
		$this->assertEquals(2, $atWidget->version);
	}

	public function testVersionsAtDateFindsAtDates()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		sleep(2);
		$widget->name = 'test2';
		$widget->save();
		sleep(2);
		$widget->name = 'test3';
		$widget->save();

		$atWidgets = Widget::where('id', $widget->id)->versionsAtDate(Carbon::now()->subSeconds(1))->get();
		$this->assertCount(1, $atWidgets);
		$atWidget = $atWidgets->first();
		$this->assertEquals($atWidget->id, $widget->id);
		$this->assertEquals(2, $atWidget->version);

		$atWidgets = Widget::versionsAtDate(Carbon::now()->subSeconds(1))->where('id', $widget->id)->get();
		$this->assertCount(1, $atWidgets);
		$atWidget = $atWidgets->first();
		$this->assertEquals($atWidget->id, $widget->id);
		$this->assertEquals(2, $atWidget->version);
	}

	public function testVersionsInRangeFindsRanges()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		sleep(2);
		$widget->name = 'test2';
		$widget->save();
		sleep(2);
		$widget->name = 'test3';
		$widget->save();

		$atWidgets = Widget::where('id', $widget->id)->versionsInRange(Carbon::now()->subSeconds(3), Carbon::now()->subSeconds(1))->get();
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(1, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(2, $atWidgets->get(1)->version);

		$atWidgets = Widget::where('id', $widget->id)->versionsInRange(Carbon::now()->subSeconds(1))->get();
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(2, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(3, $atWidgets->get(1)->version);

		$atWidgets = Widget::where('id', $widget->id)->versionsInRange(null, Carbon::now()->subSeconds(1))->get();
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(1, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(2, $atWidgets->get(1)->version);

		$atWidgets = Widget::versionsInRange(null, Carbon::now()->subSeconds(1))->where('id', $widget->id)->get();
		$this->assertCount(2, $atWidgets);
		$this->assertEquals($atWidgets->first()->id, $widget->id);
		$this->assertEquals(1, $atWidgets->first()->version);
		$this->assertEquals($atWidgets->get(1)->id, $widget->id);
		$this->assertEquals(2, $atWidgets->get(1)->version);
	}

	public function testCannotSaveOldVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$firstWidget = $widget->firstVersion();
		$firstWidget->name = 'new name';

		$this->expectException(TemporalException::class);
		$firstWidget->save();
	}

	public function testCannotDeleteOldVersions()
	{
		$widget = new Widget(['name' => 'test1']);
		$widget->save();
		$widget->name = 'test2';
		$widget->save();
		$widget->name = 'test3';
		$widget->save();

		$firstWidget = $widget->firstVersion();

		$this->expectException(TemporalException::class);
		$firstWidget->delete();
	}

    /**
     * Get a database connection instance.
     *
     * @return Connection
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

/**
 * Eloquent Models...
 */
class Widget extends Eloquent
{
	use Temporal;

	//protected $dates = ['temporal_start', 'temporal_end'];
	protected $table = 'widgets';
	protected $guarded = [];
	protected $overwritable = ['worthless'];
}