<?php namespace Gazugafan\Temporal;

use Gazugafan\Temporal\Exceptions\TemporalException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

trait Temporal
{
	/********************************************************************************
	 * Overridable Options
	 * Set a protected (non-static) property without the _temporal_ prefix to override.
	 ********************************************************************************/

	protected static $_temporal_temporal_max = '2999-01-01 00:00:00';
	protected static $_temporal_version_column = 'version';
	protected static $_temporal_temporal_start_column = 'temporal_start';
	protected static $_temporal_temporal_end_column = 'temporal_end';
	protected static $_overwritable = array();


	/********************************************************************************
	 * Option Getters
	 ********************************************************************************/

	public function getVersionColumn() { return isset($this->version_column)?$this->version_column:static::$_temporal_version_column; }
	public function getTemporalStartColumn() { return isset($this->temporal_start_column)?$this->temporal_start_column:static::$_temporal_temporal_start_column; }
	public function getTemporalEndColumn() { return isset($this->temporal_end_column)?$this->temporal_end_column:static::$_temporal_temporal_end_column; }
	public function getTemporalMax() { return isset($this->temporal_max)?$this->temporal_max:static::$_temporal_temporal_max; }
	public function getOverwritable() { return isset($this->overwritable)?$this->overwritable:static::$_overwritable; }


	/********************************************************************************
	 * Method Overrides
	 ********************************************************************************/

    /**
     * Add the global scope
     */
    public static function bootTemporal()
    {
		static::addGlobalScope(new Scopes\TemporalScope);
    }

	/**
	 * Soft Deletes the record by updating the temporal_end to the current datetime--making it invalid in the present
	 */
    public function performDeleteOnModel()
	{
		//we can only delete the current version...
		if ($this->{$this->getTemporalEndColumn()} != $this->getTemporalMax())
		{
			//double-check that we don't just have to parse the dates...
			if (Carbon::parse($this->{$this->getTemporalEndColumn()})->notEqualTo(Carbon::parse($this->getTemporalMax())))
				throw new TemporalException('You cannot delete past revisions--only the current version.');
		}

		//get a solid cutoff timestamp...
		$cutoffTimestamp = $this->freshTimestamp();

		$query = $this->newQueryWithoutScopes();
		$this->setKeysForSaveQuery($query)->toBase()->update(array(
			$this->getTemporalEndColumn() => $cutoffTimestamp
		));

		$this->{$this->getTemporalEndColumn()} = $cutoffTimestamp;
		$this->syncOriginal();
	}

	/**
	 * Save the model to the database.
	 * If the record already exists, instead of updating it, we will update the temporal_end column and insert a new revision.
	 * New revisions automatically have their temporal_start set to the current time, and temporal_end set to the max timestamp.
	 *
	 * @param  array  $options
	 * @return bool
	 */
	public function save(array $options = [])
	{
		//we can only save the current version...
		if (isset($this->{$this->getTemporalEndColumn()}) && $this->{$this->getTemporalEndColumn()} != $this->getTemporalMax())
		{
			//double-check that we don't just have to parse the dates...
			if (Carbon::parse($this->{$this->getTemporalEndColumn()})->notEqualTo(Carbon::parse($this->getTemporalMax())))
				throw new TemporalException('You cannot save past revisions--only the current version.');
		}

		$query = $this->newQueryWithoutScopes();

		// If the "saving" event returns false we'll bail out of the save and return
		// false, indicating that the save failed. This provides a chance for any
		// listeners to cancel save operations if validations fail or whatever.
		if ($this->fireModelEvent('saving') === false) {
			return false;
		}

		// If the model already exists in the database we can just update our record
		// that is already in this database using the current IDs in this "where"
		// clause to only update this model. Otherwise, we'll just insert them.
		if ($this->exists)
		{
			//we only need to perform an "update" if the record has actually been changed...
			if ($this->isDirty())
			{
				// If the updating event returns false, we will cancel the update operation so
				// developers can hook Validation systems into their models and cancel this
				// operation if the model does not pass validation. Otherwise, we update.
				if ($this->fireModelEvent('updating') === false)
					return false;

				//if only overwritable columns have changed, then just do an overwrite...
				$overwritableColumns = $this->getOverwritable();
				$overwrite = true;
				$dirty = array_keys($this->getDirty());
				foreach($dirty as $key)
				{
					if (!in_array($key, $overwritableColumns))
					{
						$overwrite = false;
						break;
					}
				}

				if ($overwrite)
				{
					$this->overwrite();
				}
				else
				{
					//get a solid cutoff timestamp...
					$cutoffTimestamp = $this->freshTimestamp();

					//just update the temporal_end, without triggering update events or timestamp updates...
					$this->setKeysForSaveQuery($query)->toBase()->update(array(
						$this->getTemporalEndColumn()=>$cutoffTimestamp
					));

					//set new temporal properties...
					$query = $this->newQueryWithoutScopes();
					$this->{$this->getVersionColumn()}++;
					$this->{$this->getTemporalStartColumn()} = $cutoffTimestamp;
					$this->{$this->getTemporalEndColumn()} = $this->getTemporalMax();

					//update the updated_at timestamp, if necessary...
					if ($this->usesTimestamps())
						$this->updateTimestamps();

					//insert the new revision...
					$attributes = $this->attributes;
					$query->insert($attributes);

					// Once we have run the update operation, we will fire the "updated" event for
					// this model instance. This will allow developers to hook into these after
					// models are updated, giving them a chance to do any special processing.
					$this->fireModelEvent('updated', false);
				}

				$saved = true;
			}
			else
				$saved = true;
		}

		// If the model is brand new, we'll insert it into our database and set the
		// ID attribute on the model to the value of the newly inserted row's ID
		// which is typically an auto-increment value managed by the database.
		else
		{
			$this->{$this->getVersionColumn()} = 1;
			$this->{$this->getTemporalStartColumn()} = $this->freshTimestamp();
			$this->{$this->getTemporalEndColumn()} = $this->getTemporalMax();
			$saved = $this->performInsert($query);
		}

		// If the model is successfully saved, we need to do a few more things once
		// that is done. We will call the "saved" method here to run any actions
		// we need to happen after a model gets successfully saved right here.
		if ($saved) {
			$this->finishSave($options);
		}

		return $saved;
	}

	/**
	 * Set the keys for a save update query.
	 * Includes the version column
	 *
	 * @param  \Illuminate\Database\Eloquent\Builder  $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	protected function setKeysForSaveQuery(Builder $query)
	{
		$query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());
		$query->where($this->getVersionColumn(), '=', $this->{$this->getVersionColumn()});

		return $query;
	}

	/**
	 * Reload a fresh model instance from the database.
	 *
	 * @param  array|string  $with
	 * @return static|null
	 */
	public function fresh($with = [])
	{
		if (! $this->exists) {
			return;
		}

		return static::newQueryWithoutScopes()
			->with(is_string($with) ? func_get_args() : $with)
			->where($this->getKeyName(), $this->getKey())
			->where($this->getTemporalEndColumn(), $this->getTemporalMax())
			->first();
	}


	/********************************************************************************
	 * New Methods
	 ********************************************************************************/

	/**
	 * Updates the record without inserting a new revision--overwriting the current revision.
	 * Fires overwriting and overwritten events. Does not fire update or save events.
	 *
	 * @return bool True if successful. False if interrupted.
	 */
	public function overwrite()
	{
		$query = $this->newQueryWithoutScopes();

		if ($this->fireModelEvent('overwriting') === false) return false;

		$this->setKeysForSaveQuery($query);

		if ($this->usesTimestamps()) $this->updateTimestamps();

		$dirty = $this->getDirty();
		if (count($dirty) > 0)
		{
			$query->update($dirty);
			$this->fireModelEvent('overwritten', false);
			$this->syncOriginal();
		}

		return true;
	}

	/**
	 * Restores the deleted revision. If this revision wasn't deleted (there is presently an active revision), nothing happens.
	 * Fires restoring and restored events. Does not fire update or save events.
	 *
	 * @return bool True if the deleted revision was restored. False if there was already a currently active version (and we therefore didn't do anything).
	 */
	public function restore()
	{
		$query = $this->newQueryWithoutScopes();

		if ($this->fireModelEvent('restoring') === false) return false;

		//make sure there is no currently active revision...
		if (!$this->find($this->{$this->getKeyName()}))
		{
			$this->setKeysForSaveQuery($query);

			if ($this->usesTimestamps()) $this->updateTimestamps();

			$this->{$this->getTemporalEndColumn()} = $this->getTemporalMax();

			$dirty = $this->getDirty();
			if (count($dirty) > 0)
			{
				$query->update($dirty);
				$this->fireModelEvent('restored', false);
				$this->syncOriginal();
			}

			return true;
		}

		return false;
	}

	/**
	 * Permanently removes all versions of this record from the database--destroying all of the data. THIS CANNOT BE UNDONE!
	 * Fires purging and purged events. Does not fire update, save, or delete events.
	 *
	 * @return bool
	 */
	public function purge()
	{
		$query = $this->newQueryWithoutScopes();

		if ($this->fireModelEvent('purging') === false) return false;

		$query->where($this->getKeyName(), $this->{$this->getKeyName()});
		$query->toBase()->delete();

		$this->exists = false;

		$this->fireModelEvent('purged', false);

		return true;
	}

	/**
	 * Returns the previous version of the record, or null if there is no previous version.
	 *
	 * @return Model
	 */
	public function previousVersion()
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->where($this->getVersionColumn(), ($this->{$this->getVersionColumn()} - 1));

		return $query->first();
	}

	/**
	 * Returns the next version of the record, or null if there is no next version.
	 *
	 * @return Model
	 */
	public function nextVersion()
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->where($this->getVersionColumn(), ($this->{$this->getVersionColumn()} + 1));

		return $query->first();
	}

	/**
	 * Returns the first version of the record, or null if there is no first version.
	 *
	 * @return Model
	 */
	public function firstVersion()
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->where($this->getVersionColumn(), 1);

		return $query->first();
	}

	/**
	 * Returns the specified version of the record, or null if the specified version does not exist.
	 *
	 * @param int $version The version number to retrieve
	 * @return Model
	 */
	public function atVersion($version)
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->where($this->getVersionColumn(), $version);

		return $query->first();
	}

	/**
	 * Returns the current version of the record, or null if the record has been deleted.
	 *
	 * @return Model
	 */
	public function currentVersion()
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->where($this->getTemporalEndColumn(), $this->getTemporalMax());

		return $query->first();
	}

	/**
	 * Returns the latest version of the record, or null if there is no latest version. Note that this may NOT be the currently active version, if the record was deleted.
	 *
	 * @return Model
	 */
	public function latestVersion()
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->orderBy($this->getVersionColumn(), 'desc');

		return $query->first();
	}

	/**
	 * Returns the record as it existed on the specified date, or null if the record did not exist then.
	 * If multiple versions of the record happen to exist at the same second, only the newest one will be returned.
	 *
	 * @param Carbon|string $datetime The date/time you want to search for a revision at. Can be a Carbon instance, or a datetime string that your database recognizes.
	 * @return Model
	 */
	public function atDate($datetime)
	{
		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		$query->where($this->getTemporalStartColumn(), '<=', $datetime);
		$query->where($this->getTemporalEndColumn(), '>', $datetime);
		$query->orderBy('version', 'desc');

		return $query->first();
	}

	/**
	 * Returns an array of any versions of the record that existed at some point during the specified date range, or an empty array if the record did not exist at all during the date range.
	 * The array is sorted from oldest to newest.
	 *
	 * @param Carbon|string $from If specified, only versions that existed on or after this date/time will be returned
	 * @param Carbon|string $to If specified, only versions that existed before this date/time will be returned
	 * @return Model[]
	 */
	public function inRange($from = null, $to = null)
	{
		if ($from !== null) $from = new Carbon($from);
		if ($to !== null) $to = new Carbon($to);

		$query = $this->newQueryWithoutScopes();
		$query->where($this->getKeyName(), $this->getKeyForSaveQuery());
		if ($from) $query->where($this->getTemporalEndColumn(), '>=', $from);
		if ($to) $query->where($this->getTemporalStartColumn(), '<', $to);
		$query->orderBy('version', 'asc');

		return $query->get();
	}


	/********************************************************************************
	 * Static Methods
	 ********************************************************************************/

	/**
	 * Starts a query constrained to only the current versions. This is accomplished in normal queries automatically, as the global temporal scope applies the same constraint. Feel free to use this method instead if you want, though.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function currentVersions()
	{
		return new static();
	}
}
