<?php

abstract class Sprig_Composite extends Sprig
{
	protected function __construct()
	{
		$required_version = '1.2';
		if(self::VERSION != $required_version) {
			throw new Exception('Unsupported Sprig version. Sprig composite requires '.$required_version);
		}
		
		foreach ($this->_fields as $name => $field)
		{
			if ( is_array ($field->column))
			{
				$field->column = $field->column[0];
			}
		}
		
		parent::__construct();
	}
	
	protected $_changed_relations_new      = array(); // New relations
	protected $_changed_relations_deleted  = array(); // Deleted relations

	/**
	 * Get the value of a field.
	 *
	 * @throws  Sprig_Exception  field does not exist
	 * @param   string  field name
	 * @return  mixed
	 */
	public function __get($name)
	{
		if ( ! $this->_init)
		{
			// The constructor must always be called first
			$this->__construct();

			// This object is about to be loaded by mysql_fetch_object() or similar
			$this->state('loading');
		}

		if ( ! isset($this->_fields[$name]))
		{
			throw new Sprig_Exception(':name model does not have a field :field',
				array(':name' => get_class($this), ':field' => $name));
		}

		if (isset($this->_related[$name]))
		{
			// Shortcut to any related object
			return $this->_related[$name];
		}

		$field = $this->_fields[$name];

		if ($this->changed($name))
		{
			$value = $this->_changed[$name];
		}
		elseif (array_key_exists($name, $this->_original))
		{
			$value = $this->_original[$name];
		}

		if ($field instanceof Sprig_Field_ForeignKey)
		{
			if ( ! isset($this->_related[$name]))
			{
				$model = Sprig::factory($field->model);

				if ($field instanceof Sprig_Field_HasMany)
				{
					if ($field instanceof Sprig_Field_ManyToMany)
					{
						if (isset($value))
						{
							if (empty($value))
							{
								return new Database_Result_Cached(array(), '');
							}
							else
							{
								// TODO this needs testing
								
								if(is_array($model->pk())) {
									// -> Composite primary key
									foreach($model->pk() as $pk) {
										$pk_value = array();
										foreach($value as $this_value) {
											$pk_value[] = $this_value[$pk];
										}
										$wrapped = array_map(
											array($model->field($pk),'_database_wrap'),$pk_value);
										$query = DB::select()
											->where($pk, 'IN', $wrapped);
									}
										f::debug_printvar($query->__toString());
								}
								else {
									// -> Not composite primary key
									$wrapped = array_map(
										array($model->field($model->pk()),'_database_wrap'),
										$value);
									$query = DB::select()
										->where($model->pk(), 'IN', $wrapped);
								}
							}
						}
						else
						{
							if(is_array($this->pk()) && is_array($model->pk()))
							{
								// One, not too good, solution for composite PK
								
								// I guess both have to have composite PK for it to work.
								// Thats why we are checking both for "is_array"
								$query = DB::select()
									->from($model->table())
									->join($field->through);
						
								$columns = array_combine($model->fk($field->through), $model->pk(TRUE));
						
								foreach ($columns as $fk => $pk)
								{
									$query->on($fk, '=', $pk);
								}
						
								$columns = array_combine($this->fk($field->through), $this->pk());
						
								foreach ($columns as $fk => $pk)
								{
									$query->where($fk, '=', $this->$pk);
								}
							}
							else
							{
								// We can grab the PK from the field definition.
								// If it doesn't exist, revert to the model choice
								if ( isset($field->left_foreign_key) AND $field->left_foreign_key)
								{
									$fk = $field->through.'.'.$field->left_foreign_key;
									$fk2 = $field->through.'.'.$model->pk();
								}
								else
								{
									$fk = $this->fk($field->through);
									$fk2 = $model->fk($field->through);
								}
	
								$query = DB::select()
									->join($field->through)
										->on($fk2, '=', $model->pk(TRUE))
									->where(
										$fk,
										'=',
										$this->_fields[$this->_primary_key]->_database_wrap($this->{$this->_primary_key}));
							}
						}
					}
					else
					{
						if (isset($value))
						{
							if(is_string($model->pk()))
							{
								// Single PK
								$query = DB::select()
									->where(
										$model->pk(),
										'=',
										$field->_database_wrap($value));
							}
							else
							{
								// Composite PK
								$query = DB::select();
								foreach ($model->pk() as $pk)
								{
									$query->where($pk, '=', $value);
								}
							}
						}
						else
						{
							if(is_string($model->fk()) && is_string($this->_primary_key))
							{
								// Single PK
								if ( isset($field->foreign_key) AND $field->foreign_key)
								{
									$fk = $field->foreign_key;
								}
								else
								{
									$fk = $model->fk();
								}
	
								$query = DB::select()
									->where(
										$fk,
										'=',
										$this->_fields[$this->_primary_key]->_database_wrap($this->{$this->_primary_key}));
							}
							else
							{
								// Composite PK
								$query = DB::select();
								if(isset($field->columns))
								{
									$columns = $field->columns;
								}
								else
								{
									$columns = array_combine($this->fk_as_array(), $this->pk_as_array());
								}
								
								foreach ($columns as $fk => $pk)
								{
									$query->where($fk, '=', $this->$pk);
								}
							}
						}
					}

					$related = $model->load($query, NULL);

					if ( ! $this->changed($name))
					{
						// We can assume this is the original value because no
						// changed value exists
						$this->_original[$name] = $field->value($related);
					}
				}
				elseif ($field instanceof Sprig_Field_BelongsTo)
				{
					if ( isset($field->primary_key) AND $field->primary_key)
						$pk = $field->primary_key;
					elseif (isset($field->columns))
					{
						// TODO: Might remove this:
						
						// Optional columns for field, fk=>local key
						// The local key must be a field in the model
						// Supports composite PK
						$columns = $field->columns;
					}
					elseif(is_array($this->pk()))
					{
						// Original BelongsTo with support for composite PK
						$columns = array_combine($this->pk(), $this->pk());
					}
					else
						$pk = $model->pk();

					if(isset($columns))
					{
						$values = array();
						foreach ($columns as $fk => $pk)
						{
							if(isset($this->_changed[$pk]))
								$values[$fk] = $this->_changed[$pk];
							else
								$values[$fk] = $this->_original[$pk];
						}
						$related = $model->values($values);
					}
					else
					{
						$related = $model->values(array($pk => $value));
					}
				}
				elseif ($field instanceof Sprig_Field_HasOne)
				{
					if(is_string($this->pk()))
					{
						// Single PK
						$related = $model->values(array($this->_model => $this->{$this->_primary_key}));
					}
					else
					{
						// Composite PK
						if(isset($field->columns))
						{
							// Optional columns for field, fk=>local key
							// The local key must be a field in the model
							$columns = $field->columns;
						}
						else
						{
							// Original HasOne
							$columns = array();
							foreach ($this->pk() as $pk)
							{
								$columns[$this->_model] = $this->_original[$pk];
							}
						}
						
						$values = array();
						foreach ($columns as $fk => $pk)
						{
							if(isset($this->_changed[$pk]))
								$values[$fk] = $this->_changed[$pk];
							elseif(isset($this->_original[$pk]))
								$values[$fk] = $this->_original[$pk];
						}
	
						$related = $model->values($values);
					}
				}

				$value = $this->_related[$name] = $related;
			}
		}

		return $value;
	}

	/**
	 * Set the value of a field.
	 *
	 * @throws  Sprig_Exception  field does not exist
	 * @param   string  field name
	 * @param   mixed   new field value
	 * @return  void
	 */
	public function __set($name, $value)
	{
		if ( ! $this->_init)
		{
			// The constructor must always be called first
			$this->__construct();

			// This object is about to be loaded by mysql_fetch_object() or similar
			$this->state('loading');
		}

		if ( ! isset($this->_fields[$name]))
		{
			throw new Sprig_Exception(':name model does not have a field :field',
				array(':name' => get_class($this), ':field' => $name));
		}

		// Get the field object
		$field = $this->_fields[$name];

		if ($this->state() === 'loading')
		{
			// Set the original value directly
			$this->_original[$name] = $field->value($value);

			// No extra processing necessary
			return;
		}
		elseif ($field instanceof Sprig_Field_ManyToMany)
		{
			if ( ! isset($this->_original[$name]))
			{
				$model = Sprig::factory($field->model);

				// Solution with composite PK requires both $model and $this to have
				// composite PK.
				if(is_null($this->pk()))
				{
					$this->_original[$name] = array();
				}
				elseif(!is_array($model->pk()) || !is_array($this->pk()))
				{
					// Single PK
					if ( isset($field->left_foreign_key) AND $field->left_foreign_key)
					{
						$fk = $field->left_foreign_key;
					}
					else
					{
						$fk = $model->fk();
					}
	
					$result = DB::select(
							array(
								$model->field($model->pk())->_database_unwrap($fk),
								$model->fk())
							)
						->from($field->through)
						->where(
							$fk,
							'=',
							$this->_fields[$this->_primary_key]->_database_wrap($this->{$this->_primary_key}))
						->execute($this->_db);
	
					// The original value for the relationship must be defined
					// before we can tell if the value has been changed
					$this->_original[$name] = $field->value($result->as_array(NULL, $model->fk()));
				}
				else
				{
					// Composite PK
					$query = DB::select()
						->select_array(array_combine($model->fk($field->through), $model->pk()))
						->from($model->table())
						->join($field->through);
	
					$columns = array_combine($model->fk($field->through), $model->pk(TRUE));
					foreach ($columns as $fk => $pk)
					{
						$query->on($fk, '=', $pk);
					}
	
					$columns = array_combine($this->fk($field->through), $this->pk());
	
					foreach ($columns as $fk => $pk)
					{
						$query->where($fk, '=', $this->$pk);
					}
	
					$result = $query->execute($this->_db);
					$this->_original[$name] = array();
					foreach($result as $row)
					{
						$this->_original[$name][] = $field->value($row);
					}
				}
			}
		}
		elseif ($field instanceof Sprig_Field_HasMany)
		{
			foreach ($value as $key => $val)
			{
				if ( ! $val instanceof Sprig)
				{
					$model = Sprig::factory($field->model);
					$pk    = $model->pk();

					if(is_string($pk))
					{
						// Single PK
						if ( ! is_array($val))
						{
							// Assume the value is a primary key
							$val = array($pk => $val);
						}
	
						if (isset($val[$pk]))
						{
							// Load the record so that changed values can be determined
							$model->values(array($pk => $val[$pk]))->load();
						}
					}
					else
					{
						// Composite PK
						if ( ! is_array($val))
						{
							if(count($pk) == 1)
							{
								// Assume the value is a primary key
								$val = array(current($pk) => $val);
							}
							else
							{
								// No good
								// TODO: Add some other error message
								throw new Kohana_Exception('Failed!');
							}
						}
						
						// Is the primary set?
						$all_pk_set   = true;
						$pk_values    = array();
						foreach($pk as $pk)
						{
							if (!isset($val[$pk])) {
								$all_pk_set = false;
							} else {
								$pk_values[$pk] = $val[$pk];
							}
						}
						
						if($all_pk_set)
						{
							// Load the record so that changed values can be determined
							$model->values($pk_values)->load();
						}
					}

					$value[$key] = $model->values($val);
				}
			}

			// Set the related objects to this value
			$this->_related[$name] = $value;

			// No extra processing necessary
			return;
		}
		elseif ($field instanceof Sprig_Field_BelongsTo)
		{
			// Pass
		}
		elseif ($field instanceof Sprig_Field_ForeignKey)
		{
			throw new Sprig_Exception('Cannot change relationship of :model->:field using __set()',
				array(':model' => $this->_model, ':field' => $name));
		}

		// Get the correct type of value
		$changed = $field->value($value);

		if (isset($field->hash_with) AND $changed)
		{
			$changed = call_user_func($field->hash_with, $changed);
		}

		// Detection of change
		if ($field instanceof Sprig_Field_HasMany)
		{
			// TODO: Check if multi dim arrays only apply to composite PK
			 
			// HasMany must compare to multi dimentional arrays
			// If the columns arn't set in the same order, the normal check will not work correctly

			$is_changed = false; // Default
			if ($deleted = self::array_diff2($this->_original[$name], $value))
			{
				$is_changed = true; // Deleted relation
				$this->_changed_relations_deleted[$name] = $deleted;
			}
			if ($new = self::array_diff2($value, $this->_original[$name]))
			{
				$is_changed = true; // New relation
				$this->_changed_relations_new[$name] = $new;
			}
			$original = !$is_changed;
		}
		else
		{
			$re_changed = (array_key_exists($name, $this->_changed) &&
				$changed !== $this->_changed[$name]);
			$original = $changed === $this->_original[$name];
			if($re_changed OR ! $original)
				$is_changed = true;
			else
				$is_changed = false;
		}

		if ($is_changed)
		{
			if (isset($this->_related[$name]))
			{
				// Clear stale related objects
				unset($this->_related[$name]);
			}

			if ($original)
			{
				// Simply pretend the change never happened
				unset($this->_changed[$name]);
			}
			else
			{
				// Set a changed value
				$this->_changed[$name] = $changed;
			
				if ($field instanceof Sprig_Field_ForeignKey
					AND is_object($value))
				{
					// Store the related object for later use
					$this->_related[$name] = $value;
				}
				elseif($field instanceof Sprig_Field_HasMany AND is_array($value))
				{
					// Loading into objects if not already an object
					$value2 = array();
					foreach($value as $val)
					{
						if(is_object($val))
							$value2[] = $val;
						elseif(is_array($val))
							$value2[] = Sprig::factory($this->field($name)->model, $val);
					}
					// Store the related object for later use
					$this->_related[$name] = $value2;
				}
			}
		}
	}
	
	/**
	 * Returns the primary key of the model, optionally with a table name.
	 *
	 * Returns array if primary key is composite PK (multiple PKs), returns
	 * string if there is only one PK and returns null if there is no PKs.
	 *
	 * @param   string  table name, TRUE for the model table
	 * @return  mixed   null, string, array
	 */
	public function pk($table = NULL)
	{
		if (is_null($this->_primary_key))
			return null;
		
		if ($table)
		{
			if ($table === TRUE)
			{
				$table = $this->_table;
			}

			$table .= '.';
		}

		if(is_string($this->_primary_key))
		{
			return $table.$this->_primary_key;
		}
		
		$keys = array();
		foreach ($this->_primary_key as $pk)
		{
			$keys[] = $table.$pk;
		}

		return $keys;
	}
	
	/**
	 * Returns the primary key of the model, optionally with a table name, as array
	 * 
	 * Returns empty array if primary key is null
	 *
	 * @param   string  table name, TRUE for the model table
	 * @return  array
	 */
	public function pk_as_array ($table = NULL)
	{
		if (is_null($this->_primary_key))
			return array();
		
		$primarys = $this->pk($table);
		if(is_string($primarys))
			return array($primarys); // Single pk
		else
			return $primarys; // Composite pk
	}
	
	/**
	 * Return the columns each primary key has got
	 * 
	 * @param   string  table name, TRUE for the model table
	 * @uses    pk()
	 * @return  array
	 */
	public function pk_columns ($table = null)
	{
		$columns = array();
		foreach($this->pk_as_array($table) as $pk)
		{
			$columns[] = $this->_fields[$pk]->column;
		}
		return $columns;
	}

	/**
	 * Returns the foreign key of the model, optionally with a table name.
	 * 
	 * Returns array if primary key is composite PK (multiple PKs), returns
	 * string if there is only one PK and returns null if there is no PKs.
	 *
	 * @param   string  table name, TRUE for the model table
	 * @return  mixed   null, string, array
	 */
	public function fk($table = NULL)
	{
		if(is_null($this->_primary_key))
			return null;
		
		if ($table)
		{
			if ($table === TRUE)
			{
				$table = $this->_table;
			}

			$table .= '.';
		}
		
		// Single pk
		if(is_string($this->_primary_key))
		{
			return $table.$this->_model.'_'.$this->_primary_key;
		}

		// Composite pk
		$keys = array();
		foreach ($this->_primary_key as $pk)
		{
			$keys[] = $table.$this->_model.'_'.$pk;
		}

		return $keys;
	}
	
	/**
	 * Returns the foreign key of the model, optionally with a table name, as array
	 * 
	 * Returns empty array if foreign key is null
	 *
	 * @param   string  table name, TRUE for the model table
	 * @return  array
	 */
	public function fk_as_array ($table = NULL)
	{
		if (is_null($this->_primary_key))
			return array();
		
		$foreigns = $this->fk($table);
		if(is_string($foreigns))
			return array($foreigns); // Single fk
		else
			return $foreigns; // Composite fk
	}

	
	/**
	 * Get new relations
	 *
	 * @return  Array
	 */
	public function changed_relations_new()
	{
		if(isset($this->_changed_relations_new))
			return $this->_changed_relations_new;
		else
			return array();
	}
	
	/**
	 * Get deleted relations
	 *
	 * @return  Array
	 */
	public function changed_relations_deleted()
	{
		if(isset($this->_changed_relations_deleted))
			return $this->_changed_relations_deleted;
		else
			return array();
	}
	
	/**
	 * Create a new record using the current data.
	 *
	 * @uses    Sprig::check()
	 * @return  $this
	 */
	public function create()
	{
		foreach ($this->_fields as $name => $field)
		{
			if ($field instanceof Sprig_Field_Timestamp AND $field->auto_now_create)
			{
				// Set the value to the current timestamp
				$this->$name = time();
			}
		}

		// Check the all current data
		$data = $this->check($this->as_array());

		$values = $relations = array();
		foreach ($data as $name => $value)
		{
			$field = $this->_fields[$name];

			if ($field instanceof Sprig_Field_Auto OR ! $field->in_db )
			{
				if ($field instanceof Sprig_Field_ManyToMany)
				{
					$model = Sprig::factory($field->model);
					
					if( 
						!is_null($this->pk()) && // Must have primary key
						!is_null($model->pk())    // in both models
					)
					{
						$relations[$name] = $value;
					}
				}

				// Skip all auto-increment fields or where in_db is false
				continue;
			}

			// Change the field name to the column name
			$values[$field->column] = $field->_database_wrap($value);
		}

		list($id) = DB::insert($this->_table, array_keys($values))
			->values($values)
			->execute($this->_db);

		if (is_array($this->_primary_key))
		{
			foreach ($this->_primary_key as $name)
			{
				if ($this->_fields[$name] instanceof Sprig_Field_Auto)
				{
					// Set the auto-increment primary key to the insert id
					$this->$name = $id;

					// There can only be 1 auto-increment column per model
					break;
				}
			}
		}
		elseif (
			!is_null($this->_primary_key) &&
			$this->_fields[$this->_primary_key] instanceof Sprig_Field_Auto
		)
		{
			$this->{$this->_primary_key} = $id;
		}

		// Object is now loaded
		$this->state('loaded');

		if ($relations)
		{
			foreach ($relations as $name => $value)
			{
				$field = $this->_fields[$name];

				if ( isset($field->foreign_key) AND $field->foreign_key)
				{
					$fk = $field->foreign_key;
				}
				else
				{
					$fk = $this->fk($field->through);
				}

				$model = Sprig::factory($field->model);

				foreach ($value as $id)
				{
					if(is_array($id))
					{
						/*
						 * Composite PK
						 * 
						 * $id should be an array with primary keys from $model
						 * Please see composite PK tests for example 
						 */
						$query = DB::insert($field->through, array_merge($this->fk(), $model->fk()));
						$insert_values = array(); // Building values to be inserted
						foreach (array_combine($this->fk(), $this->pk()) as $fk => $pk)
						{
							$insert_values[$fk] = $this->$pk; // Getting values from $this object
						}
						foreach(array_combine($model->fk(), $model->pk()) as $fk => $pk)
						{
							$insert_values[$fk] = $id[$pk]; // Getting values from $id array
						}
						$query->values($insert_values)
							->execute($this->_db);
					}
					else
					{
						// Single PK
						
						// The insert below might fail if your input is not an array but your PKs are
						DB::insert($field->through, array($fk, $model->fk()))
							->values(
								array(
									$this->_fields[$this->_primary_key]->_database_wrap($this->{$this->_primary_key}),
									$model->field($model->pk())->_database_wrap($id))
								)
							->execute($this->_db);
					}
				}
			}
		}

		return $this;
	}

	/**
	 * Update the current record using the current data.
	 *
	 * @uses    Sprig::check()
	 * @return  $this
	 */
	public function update()
	{
		if ($this->changed())
		{
			foreach ($this->_fields as $name => $field)
			{
				if ($field instanceof Sprig_Field_Timestamp AND $field->auto_now_update)
				{
					// Set the value to the current timestamp
					$this->$name = time();
				}
			}

			// Check the updated data
			$data = $this->check($this->changed());

			$values = $relations = array();
			foreach ($data as $name => $value)
			{
				$field = $this->_fields[$name];

				if ( ! $field->in_db)
				{
					if ($field instanceof Sprig_Field_ManyToMany)
					{
						$model = Sprig::factory($field->model);
						if( 
							!is_null($this->pk()) && // Must have primary key
							!is_null($model->pk())    // in both models
						)
						{
							$relations[$name] = $value;
						}
					}

					// Skip all fields that are not in the database
					continue;
				}

				// Change the field name to the column name
				$values[$field->column] = $field->_database_wrap($value);
			}

			if ($values)
			{
				$query = DB::update($this->_table)
					->set($values);

				if (is_array($this->_primary_key))
				{
					foreach($this->_primary_key as $field)
					{
						$query->where(
							$this->_fields[$field]->column,
							'=',
							$this->_fields[$field]->_database_wrap($this->_original[$field]));
					}
				}
				else
				{
					$query->where(
						$this->_fields[$this->_primary_key]->column,
						'=',
						$this->_fields[$this->_primary_key]->_database_wrap($this->_original[$this->_primary_key]));
				}

				$query->execute($this->_db);
			}

			if ($relations)
			{
				foreach ($relations as $name => $value)
				{
					$field = $this->_fields[$name];

					$model = Sprig::factory($field->model);

					if ( isset($field->left_foreign_key) AND $field->left_foreign_key)
					{
						$left_fk = $field->left_foreign_key;
					}
					else
					{
						$left_fk = $this->fk();
					}

					if ( isset($field->right_foreign_key) AND $field->right_foreign_key)
					{
						$right_fk = $field->right_foreign_key;
					}
					else
					{
						$right_fk = $model->fk();
					}

					// Find old relationships that must be deleted
					// Single PK
					if (
						!is_array($value) &&
						!is_array($this->_orginal[$name]) &&
						$old = array_diff($this->_original[$name], $value)
						)
					{
						// TODO this needs testing
						$old = array_map(array($this->_fields[$this->_primary_key],'_database_wrap'), $old);

						DB::delete($field->through)
							->where(
								$left_fk,
								'=',
								$this->_fields[$this->_primary_key]->_database_wrap($this->{$this->_primary_key}))
							->where($right_fk, 'IN', $old)
							->execute($this->_db);
					}
					// Composite pk
					if (isset($this->_changed_relations_deleted[$name]))
					{
						$query = DB::delete($field->through);

						$columns = array_combine($this->fk($field->through), $this->pk());
						foreach ($columns as $fk => $pk)
						{
							$query->where($fk, '=', $this->$pk);
						}

						$columns = array_combine($model->fk(), $this->pk());
						foreach ($columns as $fk => $pk)
						{
							// Extract old values
							$old2 = array();
							foreach($this->_changed_relations_deleted[$name] as $i => $valArr)
							{
								$old2[$i] = array($pk => $valArr[$pk]);
							}
							$query->where($fk, 'IN', $old2);
						}

						$query->execute($this->_db);
					}

					// Find new relationships that must be inserted
					// Single PK
					if (
						!is_array($value) &&
						!is_array($this->_original[$name]) &&
						$new = array_diff($value, $this->_original[$name])
						)
					{
						foreach ($new as $id)
						{
							DB::insert($field->through, array($left_fk, $right_fk))
								->values(
									array(
										$this->_fields[$this->_primary_key]->_database_wrap($this->{$this->_primary_key}),
										$model->field($model->pk())->_database_wrap($id)
									))
								->execute($this->_db);
						}
					}
					// Composite PK
					if (isset($this->_changed_relations_new[$name]))
					{
						foreach ($this->_changed_relations_new[$name] as $id) // $id is an array with primary keys from $model
						{
							$query = DB::insert($field->through, array_merge($this->fk(), $model->fk()));

							$insert_values = array(); // Building values to be inserted
							foreach (array_combine($this->fk(), $this->pk()) as $fk => $pk)
							{
								$insert_values[$fk] = $this->$pk; // Getting values from $this object
							}
							foreach(array_combine($model->fk(), $model->pk()) as $fk => $pk)
							{
								$insert_values[$fk] = $id[$pk]; // Getting values from $id array
							}
							$query->values($insert_values)->execute($this->_db);
						}
					}
				}
			}

			// Reset the original data for this record
			$this->_original = $this->as_array();

			// Everything has been updated
			$this->_changed = array();
		}

		return $this;
	}
	
	/**
	 * Array diff for composite keys (array of arrays)
	 * 
	 * @return array Returns multidim array of those arrays in array1 that isn't in array2 
	 */
	public static function array_diff2($array1, $array2)
	{
		$notdiff_found_array1 = array();
		$notdiff_found_array2 = array();
		foreach($array1 as $idx1 => $arr1)
		{
			foreach($array2 as $idx2 => $arr2)
			{			
				if($arr1 == $arr2)
				{
					$notdiff_found_array1[$idx1] = $idx1;
					$notdiff_found_array2[$idx2] = $idx2;
				}
			}
		}
		
		$diff = array();
		foreach(array_diff(array_keys($array1), $notdiff_found_array1) as $arr)
		{
			$diff[] = $array1[$arr];
		}
		/*foreach(array_diff(array_keys($array2), $notdiff_found_array2) as $arr)
		{
			$diff[] = $array2[$arr];
		}*/
		return $diff;
	}

	/**
	 * Callback for validating unique fields.
	 *
	 * @param   object  Validate array
	 * @param   string  field name
	 * @return  void
	 */
	public function _unique_field(Validate $array, $field)
	{
		if ($array[$field])
		{
			$query = DB::select();
			
			// pk_columns returns array to support composite PK
			foreach($this->pk_columns() as $pk_col) {
				$query->select($pk_col);
			}
			$query
				->from($this->_table)
				->where(
					$this->_fields[$field]->column,
					'=',
					$this->_fields[$field]->_database_wrap($array[$field]));
			$query = $query
				->execute($this->_db);

			if (count($query))
			{
				$array->error($field, 'unique');
			}
		}
	}
}
