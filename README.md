# Sprig Composite

Support for 2 or more primary keys. See Sprig readme.

Instead of extending Sprig, extend Sprig_Composite

#### Sprig_Field_BelongsTo

Also has `columns` for connecting primarykeys to foreign keys (array(keyBelongsTo=>KeyLocal, key2=>key2)). Default: array_combine($this->pk() => $this->pk());

#### Sprig_Field_HasMany

Also has `columns` for connecting primarykeys to foreign keys (array(fk=>pk, fk2=>pk2) where fk is in the other table and pk is the key in local table)