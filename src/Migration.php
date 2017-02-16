<?php namespace Gazugafan\Temporal;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Gazugafan\Temporal\Exceptions\TemporalException;

class Migration
{
	/**
	 * Makes the necessary modifications on a table to make it support a temporal model, such as adding temporal and version columns, and setting up primary keys and indexes.
	 * Because Laravel does not normally work well with multiple primary keys mixed with an auto incrementing key, this is a non-trivial task. This helper handles all the dirty work for you.
	 * The result is that the version, temporal_start, and temporal_end columns are added to the table, with the version column as an additional primary key. A couple new indexes are also added to keep querying versions fast.
	 *
	 * @param string $tablename The name of the table to modify
	 * @param string $version The name of the version column (defaults to "version")
	 * @param string $temporal_start The name of the temporal_start column (defaults to "temporal_start")
	 * @param string $temporal_end The name of the temporal_end column (defaults to "temporal_end")
	 * @param string $temporal_max The name of the temporal_max column (defaults to "temporal_max")
	 * @throws TemporalException If there is any problem getting info about the table or modifying it. This is possible when using non-MySQL drivers, or if the table does not have any primary keys.
	 */
	public static function make_temporal($tablename, $version = 'version', $temporal_start = 'temporal_start', $temporal_end = 'temporal_end', $temporal_max = '2999-01-01')
	{
		$primary_keys = static::get_primary_keys($tablename);
		if (count($primary_keys) == 0)
			throw new TemporalException('The table doesn\'t seem to have any primary keys. A temporal model table needs at least one primary key. Try adding an "id" column first using $table->increments(\'id\').');

		//remove the primary key(s) and auto-increment in one statement...
		$alter_commands = array();
		$alter_commands[] = "DROP PRIMARY KEY";
		foreach($primary_keys as $primary_key=>$info)
		{
			if ($info['auto_increment'])
				$alter_commands[] = "MODIFY `$primary_key` {$info['type']} NOT NULL";
		}
		DB::statement("ALTER TABLE `$tablename` " . implode(', ', $alter_commands) . ';');

		//add the temporal columns and specify new primary keys...
		Schema::table($tablename, function ($table) use($primary_keys, $version, $temporal_start, $temporal_end, $temporal_max) {
			$keys = array_keys($primary_keys);
			$table->unsignedMediumInteger($version)->comment('The version number')->default(0)->after(end($keys));
			$table->dateTime($temporal_start)->comment('When the revision begins')->default(DB::raw('NOW()'))->after($version);
			$table->dateTime($temporal_end)->comment('When the revision ends')->default($temporal_max)->after($temporal_start);
			$table->primary(array_merge($keys, array($version)));
			$table->index(array_merge(array($temporal_end), $keys), 'current_version');
			$table->index(array_merge($keys, array($temporal_start, $temporal_end)), 'version_at_time');
		});

		//add auto-increments back...
		$alter_commands = array();
		foreach($primary_keys as $primary_key=>$info)
		{
			if ($info['auto_increment'])
				$alter_commands[] = "MODIFY `$primary_key` {$info['type']} NOT NULL AUTO_INCREMENT";
		}
		DB::statement("ALTER TABLE `$tablename` " . implode(', ', $alter_commands) . ';');
	}

	/**
	 * Figures out which columns in the specified table are primary keys, and returns information about them.
	 *
	 * @param string $tablename The table to retrieve the primary keys from
	 * @throws TemporalException If the primary key(s) cannot be retrieved (possibly due to a non-MySQL database driver)
	 * @return array An associative array of the table's primary keys, with the key being the name of the column, and the value being an array of information about the column.
	 */
	private static function get_primary_keys($tablename)
	{
		//determine if we have an auto-incrementing field...
		$primary_keys = array();
		$columns = DB::select("SHOW COLUMNS FROM `$tablename`;");
		if (count($columns))
		{
			foreach($columns as $column=>$info)
			{
				$data = array();
				foreach($info as $key=>$val)
					$data[strtolower($key)] = $val;

				if (strpos(strtolower($data['key']), 'pri') !== false)
				{
					$primary_keys[$data['field']] = array(
						'type'=>$data['type'],
						'auto_increment'=>(strpos(strtolower($data['extra']), 'auto_increment') !== false)
					);
				}
			}

			return $primary_keys;
		}
		else
			throw new TemporalException('Could not get info about the table to base modifications on. Maybe this isn\'t a MySQL database?');
	}
}