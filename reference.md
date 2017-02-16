# Temporal Trait

Properties
----------


### $temporal_max

    protected string $temporal_max = '2999-01-01 00:00:00'

The datetime to use for the end of the currently active version



### $version_column

    protected string $version_column = 'version'

The integer column used to represent the version number of a revision



### $temporal_start_column

    protected string $temporal_start_column = 'temporal_start'

The datetime column used to represent the beginning of a version



### $temporal_end_column

    protected string $temporal_end_column = 'temporal_end'

The datetime column used to represent the end of a version



Methods
-------

### delete

Soft Deletes the record by updating the temporal_end to the current datetime--making it invalid in the present





### save

    boolean save(array $options)

Save the model to the database.

If the record already exists, instead of updating it, we will update the temporal_end column and insert a new revision.
New revisions automatically have their temporal_start set to the current time, and temporal_end set to the max timestamp.



### overwrite

    boolean overwrite()

Updates the record without inserting a new revision--overwriting the current revision.

Fires overwriting and overwritten events. Does not fire update or save events.





### restore

    boolean restore()

Restores the deleted revision. If this revision wasn't deleted (there is presently an active revision), nothing happens.

Fires restoring and restored events. Does not fire update or save events.





### purge

    boolean purge()

Permanently removes all versions of this record from the database--destroying all of the data. THIS CANNOT BE UNDONE!
Fires purging and purged events. Does not fire update, save, or delete events.





### previousVersion

    previousVersion()

Returns the previous version of the record, or null if there is no previous version.





### nextVersion

    nextVersion()

Returns the next version of the record, or null if there is no next version.





### firstVersion

    firstVersion()

Returns the first version of the record, or null if there is no first version.





### atVersion

    atVersion($version)

Returns the specified version of the record, or null if the specified version does not exist.



#### Arguments
* $version **int** The version number to retrieve



### currentVersion

    currentVersion()

Returns the current version of the record, or null if the record has been deleted.





### latestVersion

    latestVersion()

Returns the latest version of the record, or null if there is no latest version. Note that this may NOT be the currently active version, if the record was deleted.





### atDate

    atDate(Carbon|string $datetime)

Returns the record as it existed on the specified date, or null if the record did not exist then.

If multiple versions of the record happen to exist at the same second, only the newest one will be returned.



#### Arguments
* $datetime **Carbon|string** - The date/time you want to search for a revision at. Can be a Carbon instance, or a datetime string that your database recognizes.



### inRange

    inRange(Carbon|string $from = null, Carbon|string $to = null)

Returns an array of any versions of the record that existed at some point during the specified date range, or an empty array if the record did not exist at all during the date range.

The array is sorted from oldest to newest.



#### Arguments
* $from **Carbon|string** - If specified, only versions that existed on or after this date/time will be returned
* $to **Carbon|string** - If specified, only versions that existed before this date/time will be returned



### currentVersions

    \Illuminate\Database\Eloquent\Builder currentVersions()

Starts a query constrained to only the current versions. This is accomplished in normal queries automatically, as the global temporal scope applies the same constraint. Feel free to use this method instead if you want, though.

* This method is **static**.



# Query Builder Extensions




### currentVersions

    currentVersions()


The currentVersions builder method will constrain the query to revisions that are currently active. This is normally applied automatically as a global scope, so this method is not usually necessary.
#####



### allVersions

    allVersions()

The allVersions builder method will remove the constraint that normally causes just the currently active versions to be returned.



### firstVersions

    firstVersions()

The firstVersions builder method will constrain the query to original revisions.



### versionsAt

    versionsAt($version = 1)

The versionsAt builder method will constrain the query to revisions with the specified version number.

#### Arguments
* $version **int** The version number to retrieve



### versionsAtDate

    versionsAtDate($datetime = null)

The versionsAtDate builder method will constrain the query to revisions that were active during the specified date.
#####

#### Arguments
* $datetime **Carbon|string** - The date/time you want to search for revisions at. Can be a Carbon instance, or a datetime string that your database recognizes.



### versionsInRange

    versionsInRange(Carbon|string $from = null, Carbon|string $to = null)

The versionsInRange builder method will constrain the query to revisions that were active at some point in the specified date range.

You can specify null as the $from or $to date to get all revisions in that direction of time.


#### Arguments
* $from **Carbon|string** - If specified, only versions that existed on or after this date/time will be returned
* $to **Carbon|string** - If specified, only versions that existed before this date/time will be returned