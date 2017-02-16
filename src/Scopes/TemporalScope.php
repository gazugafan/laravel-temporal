<?php namespace Gazugafan\Temporal\Scopes;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TemporalScope implements Scope
{
	/**
	 * All of the extensions to be added to the builder.
	 *
	 * @var array
	 */
	protected $extensions = ['AllVersions', 'CurrentVersions', 'FirstVersions', 'VersionsAt', 'VersionsAtDate', 'VersionsInRange'];

	/**
	 * Apply the scope to a given Eloquent query builder.
	 * Only shows the currently active revision
	 *
	 * @param  \Illuminate\Database\Eloquent\Builder  $builder
	 * @param  \Illuminate\Database\Eloquent\Model  $model
	 * @return void
	 */
	public function apply(Builder $builder, Model $model)
	{
		$builder->where($model->getTemporalEndColumn(), $model->getTemporalMax());
	}

	public function extend(Builder $builder)
	{
		foreach ($this->extensions as $extension) {
			$this->{"add{$extension}"}($builder);
		}

		$builder->onDelete(function (Builder $builder) {
			$model = $builder->getModel();
			$column = $model->getTemporalEndColumn();
			return $builder->update([
				$column => $model->freshTimestampString(),
			]);
		});
	}

	/**
	 * The currentVersions builder method will constrain the query to revisions that are currently active. This is normally applied automatically as a global scope, so this method is not usually necessary.
	 */
	protected function addCurrentVersions(Builder $builder)
	{
		$builder->macro('currentVersions', function (Builder $builder)
		{
			$model = $builder->getModel();

			$builder->withoutGlobalScope($this)
				->where($model->getTemporalEndColumn(), $model->getTemporalMax());

			return $builder;
		});
	}

	/**
	 * The allVersions builder method will remove the constraint that normally causes just the currently active versions to be returned.
	 */
	protected function addAllVersions(Builder $builder)
	{
		$builder->macro('allVersions', function (Builder $builder)
		{
			return $builder->withoutGlobalScope($this);
		});
	}

	/**
	 * The firstVersions builder method will constrain the query to original revisions.
	 */
	protected function addFirstVersions(Builder $builder)
	{
		$builder->macro('firstVersions', function (Builder $builder)
		{
			$model = $builder->getModel();

			$builder->withoutGlobalScope($this)
				->where($model->getVersionColumn(), 1);

			return $builder;
		});
	}

	/**
	 * The versionsAt builder method will constrain the query to revisions with the specified version number.
	 */
	protected function addVersionsAt(Builder $builder)
	{
		$builder->macro('versionsAt', function (Builder $builder, $version = 1)
		{
			$model = $builder->getModel();

			$builder->withoutGlobalScope($this)
				->where($model->getVersionColumn(), $version);

			return $builder;
		});
	}

	/**
	 * The versionsAtDate builder method will constrain the query to revisions that were active during the specified date.
	 */
	protected function addVersionsAtDate(Builder $builder)
	{
		$builder->macro('versionsAtDate', function (Builder $builder, $datetime = null)
		{
			$datetime = new Carbon($datetime);

			$model = $builder->getModel();

			$builder->withoutGlobalScope($this)
				->where($model->getTemporalStartColumn(), '<=', $datetime)
				->where($model->getTemporalEndColumn(), '>', $datetime);

			return $builder;
		});
	}

	/**
	 * The versionsInRange builder method will constrain the query to revisions that were active at some point in the specified date range.
	 * You can specify null as the $from or $to date to get all revisions in that direction of time.
	 */
	protected function addVersionsInRange(Builder $builder)
	{
		$builder->macro('versionsInRange', function (Builder $builder, $from = null, $to = null)
		{
			if ($from !== null) $from = new Carbon($from);
			if ($to !== null) $to = new Carbon($to);

			$model = $builder->getModel();

			$builder->withoutGlobalScope($this);
			if ($from) $builder->where($model->getTemporalEndColumn(), '>=', $from);
			if ($to) $builder->where($model->getTemporalStartColumn(), '<', $to);

			return $builder;
		});
	}
}
