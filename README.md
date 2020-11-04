# Laravel Temporal Models
### Temporal models and versioning for Laravel

You know what's crazy? Database updates. You update a record and BAM! The previous version of the record is overwritten. What was it like before you updated it? No one knows. The data has been lost forever. More like database overwrites, amirite?

If you're not in the business of losing data forever, you should give temporal models a try! It's like version control for your database records. Now when you update something, the previous version of it is kept intact, and a new revision is inserted instead.

Normally you're only going to care about the current versions of things, so by default that's all you'll get when querying. But the old versions are always there too if you want to get at them. Now when someone asks "what was this thing like before the latest change?" or "what did this thing dress as last Halloween?" or "did this thing always have a tail?", you'll have all the answers.

## Requirements

- This has been unit tested, but only on Laravel 5.4 with PHP 7.1 and Laravel 6.0 with PHP 7.2. Let me know if you find it works on older versions!
- Also only tested with MySQL/MariaDB. Likely will not work with SQLite, but let me know if you find it works with other databases!

## Installation

Install via Composer...

For Laravel 5 use the version 1.1
```bash
composer require gazugafan/laravel-temporal:1.1
```

For Laravel 6.0 use the version 2.0
```bash
composer require gazugafan/laravel-temporal:2.0
```

## Overview

Temporal models get three new fields...
- ```version``` represents the version number of a record (1 would be the original version, 2 would be the second version, etc.). Versions always start at 1, and will never have a gap.
- ```temporal_start``` and ```temporal_end``` represent the range of time a version is/was active. If a revision is currently active, temporal_end will automatically be set VERY far into the future.

Whenever you save or update a model, the previous version's ```temporal_end``` is updated to mark the end of its lifespan, and a new version is inserted with an incremented ```version```.

When querying for temporal models, we automatically constrain the query so that only current versions are returned. Getting at old revisions is also possible using added methods.

When paired with [laravel-changelog](https://github.com/gazugafan/laravel-changelog), this will give you the history of every change made to a record, including who made each change and exactly what was changed.


## Schema Migration

You'll need to modify your table's schema a bit. The bad news is that Laravel doesn't really "support" the modifications we need to make, so we have to resort to some ugly workarounds. The good news is that I made a helper class that handles all the dirty work for you!
```php
//create your table like normal...
Schema::create('widgets', function ($table) {
    $table->increments('id');
    $table->timestamps();
    $table->string('name');
});

//add the columns and keys needed for the temporal features...
Gazugafan\Temporal\Migration::make_temporal('widgets');
```
What's actually going on here...
> The actual changes necessary are:
> - Add an unsigned integer ```version``` column as an additional primary key.
> - Add datetime ```temporal_start``` and ```temporal_end``` columns.
> - Add indexes to make sure queries stay just as fast. I'd recommend two additional indexes...
>     - ```(temporal_end, id)``` for getting current revisions (which we do all the time)
>     - ```(id, temporal_start, temporal_end)``` for getting revisions at a certain date/time
> 
> Laravel doesn't have an easy mechanism for specifying multiple primary keys along with an auto-increment key. To work around this, ```Migration::make_temporal``` does some raw MySQL commands. This most likely will NOT work on non-MySQL databases like SQLite.

## Model Setup

To make your model temporal, just add the ```Gazugafan\Temporal\Temporal``` trait to the model's class...
```php
class Widget extends Model
{
    use Temporal; //add all the temporal features

    protected $dates = ['temporal_start', 'temporal_end']; //if you want these auto-cast to Carbon, go for it!
}
```

You can also customize the column names and maximum temporal timestamp, if you'd like to change them from the defaults...
```php
class Widget extends Model
{
    use Temporal; //add all the temporal features

    protected $version_column = 'version';
    protected $temporal_start_column = 'temporal_start';
    protected $temporal_end_column = 'temporal_end';
    protected $temporal_max = '2999-01-01 00:00:00';
    protected $overwritable = ['worthless_column']; //columns that can simply be overwritten if only they are being updated
}
```

## Usage

###### Saving temporal models
When you first save a new record, it is inserted into the database with a ```version``` of 1, a ```temporal_start``` of the current date/time, and a ```temporal_end``` of WAY in the future ('2999-01-01' by default). Thus, this first revision is currently active from now to forever.

The next time you save this record, the previous revision's ```temporal_end``` is automatically updated to the current date/time--marking the end of that version's lifespan. The new revision is then inserted into the database with its ```version``` incremented by 1, and its ```temporal_start``` and ```temporal_end``` updated as before. Thus, the previous version is now inactive, and the new version is currently active.
```php
$widget = new Widget();
$widget->name = 'Cawg';
$widget->save(); //inserts a new widget at version 1 with temporal_start=now and temporal_end='2999-01-01'

$widget->name = 'Cog';
$widget->save(); //ends the lifespan of version 1 by updating its temporal_end=now, and inserts a new revision with version=2

$anotherWidget = Widget::create(['name'=>'Other Cog']); //another way to insert a new widget at version 1
```

You can only save the newest version of a record. If you find an old version of something and try to modify it, an error will be thrown... you can't change the past.

If you want to overwrite the latest revision, use ```overwrite()``` instead of ```save()```. This will simply update the revision instead of inserting a new one. It totally defeats the purpose of using temporal models, but it can be useful for making frequent tiny changes. Like incrementing/decrementing something on a set schedule, or updating a cached calculation.

You can also automatically overwrite whenever you perform a ```save()``` if only columns you've defined as ```overwritable``` have been changed. Just add an ```$overwritable``` array property to your model like this: ```protected $overwritable = ['worthless_column', 'cached_value'];``` and we'll automatically perform an overwrite (instead of inserting a new version) when only those columns are changing. If you notice lots of unnecessary versions in your table just because of one or two columns changing that you don't care about the history of, add those columns here!


###### Retrieving temporal models
By default, all temporal model queries will be constrained so that only current versions are returned.
```php
$widget123 = Widget::find(123); //gets the current version of widget #123.
$blueWidgets = Widget::where('color', 'blue')->get(); //gets the current version of all the blue widgets
```

If you want to get all versions for some reason, you can use the ```allVersions()``` method to remove the global scope...
```php
$widget123s = Widget::where('id', 123)->allVersions()->get(); //gets all versions of widget #123
$blueWidgets = Widget::allVersions()->where('color', 'blue')->get(); //gets all versions of all blue widgets
```

You can also get specific versions, and traverse through versions of a certain record...
```php
$widget = Widget::find(123); //gets the current version of widget #123, like normal
$firstWidget = $widget->firstVersion(); //gets the first version of widget #123
$secondWidget = $firstWidget->nextVersion(); //gets version 2 of widget #123
$fifthWidget = $widget->atVersion(5); //gets version 5 of widget #123
$fourthWidget = $fifthWidget->previousVersion(); //gets version 4 of widget #123
$latestWidget = $widget->latestVersion(); //gets the latest version of widget #123 (not necessarily the current version if it was deleted)
$yesterdaysWidget = $widget->atDate(Carbon::now()->subDays(1)); //get widget #123 as it existed at this time yesterday
$januaryWidgets = $widget->inRange('2017-01-01', '2017-02-01'); //get all versions of widget #123 that were active at some point in Jan. 2017
```

And there are similar query builder methods...
```php
$firstWidgets = Widget::where('id', 123)->firstVersions()->get(); //gets the first version of widget #123 (in a collection with 1 element)
$firstWidget = $firstWidgets->first(); //since this is a collection, you can use first() to get the first (and in this case only) element
$secondBlueWidgets = Widget::versionsAt(2)->where('color', 'blue')->get(); //gets the second version of all blue widgets
$noonWidgets = Widget::versionsAtDate('2017-01-01 12:00:00')->get(); //gets all widgets as they were at noon on Jan. 1st 2017

//get all versions of widgets that were red and active at some point last week...
$lastWeeksRedWidgets = Widget::where('color', 'red')->versionsInRange(Carbon::now()->subWeeks(2), Carbon::now()->subWeeks(1))->get();
```

###### Deleting temporal models
When you call ```delete()``` on a temporal model, we don't actually DELETE anything. We just set its ```temporal_end``` to now--thereby marking the end of that revision's lifespan. And, without a current revision inserted to follow it, the record is effectively non-existant in the present. So, querying for it like normal won't get you anything.
```php
$widget = Widget::create(['name'=>'cog']); //create a new widget like normal
$widgetID = $widget->id; //get the widget's ID
$widget->delete(); //nothing is really DELETEd from the database. We just update temporal_end to now
$deletedWidget = Widget::find($widgetID); //returns null because the record no longer has a current version
```

It's like you get SoftDelete functionality for free! You can even restore deleted records...
```php
$widget->restore(); //would restore the deleted record from the example above
```

Keep in mind that you can only delete/restore the current/latest version of a record. If you really want to permanently remove a record from the database, you can use ```purge()```. This DELETEs every version of the record, and cannot be undone.
```php
$widget->purge(); //that widget's gone for good... like it never even existed in the first place.
```

## Reference

[Check out the full documentation here](reference.md)

## Pitfalls
- You cannot change the past. Attempting to save or delete anything but the current version of a record will result in an error. Attempting to restore anything but the latest version of a deleted record will result in an error.
- Mass-updating will not respect the temporal model features, and unfortunately I don't know how to throw an error if you try. If you attempt something like the following, don't expect it to insert new revisions...
```php
//don't even try this unless you want to totally screw
//things up (or you really know what you're doing)...
App\Widget::where('active', 1)->update(['description'=>'An active widget']);
```
- Saving changes to two copies of the same record will NOT cause the second save to update the first copy's record. However, because of our composite primary key (which includes ```version```, attempting this will simply throw an error...
```php
$copyA = Widget::find(1);
$copyB = Widget::find(1);

$copyA->name = 'new name A';
$copyA->save(); //updates the original revision and inserts a new revision

$copyB->name = 'new name B';
$copyB->save(); //attempts to update the original revision again and throws an error
```
- When a revision is overwritten, its ```updated_at``` field is automatically updated. This means it's possible to have an ```updated_at``` field that does not match the revision's ```temporal_start```. The ```version``` field, however, is NOT updated (which ensures version numbers always start at 1 and never have gaps).
- We can't automatically add the temporal restriction to queries outside of the query builder specific to the temporal Eloquent model. If you need to do some manual (non-ORM) queries, remember to add a WHERE clause to only get the latest versions (```WHERE temporal_end = '2999-01-01'```).
- The ```unique``` validation method does NOT respect temporal models. So, it will consider ALL versions and fail even if old versions of a record have the same value. This should quickly become apparant if you make your User model temporal. If you want a tweaked ```unique``` validation method that works for temporal models, try something like this...
```php
public function validateTemporalUnique($attribute, $value, $parameters)
{
	$this->requireParameterCount(1, $parameters, 'unique');

	list($connection, $table) = $this->parseTable($parameters[0]);

	// The second parameter position holds the name of the column that needs to
	// be verified as unique. If this parameter isn't specified we will just
	// assume that this column to be verified shares the attribute's name.
	$column = $this->getQueryColumn($parameters, $attribute);

	list($idColumn, $id) = [null, null];

	if (isset($parameters[2])) {
		list($idColumn, $id) = $this->getUniqueIds($parameters);
	}

	// The presence verifier is responsible for counting rows within this store
	// mechanism which might be a relational database or any other permanent
	// data store like Redis, etc. We will use it to determine uniqueness.
	$verifier = $this->getPresenceVerifierFor($connection);

	$extra = $this->getUniqueExtra($parameters);

	if ($this->currentRule instanceof Unique) {
		$extra = array_merge($extra, $this->currentRule->queryCallbacks());
	}

	//add the default temporal property...
	if (!array_key_exists('temporal_end', $extra))
		$extra['temporal_end'] = '2999-01-01';

	return $verifier->getCount(
			$table, $column, $value, $id, $idColumn, $extra
		) == 0;
}
 ```
 
## Credits
Inspired by [navjobs/temporal-models](https://github.com/navjobs/temporal-models) and [FuelPHP's temporal models](https://fuelphp.com/dev-docs/packages/orm/model/temporal.html)
