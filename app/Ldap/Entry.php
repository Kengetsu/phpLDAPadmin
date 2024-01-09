<?php

namespace App\Ldap;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use LdapRecord\Models\Model;

use App\Classes\LDAP\Attribute;
use App\Classes\LDAP\Attribute\Factory;

class Entry extends Model
{
	/* OVERRIDES */

	public function getAttributes(): array
	{
		return $this->getAttributesAsObjects()->toArray();
	}

	/**
	 * Determine if the new and old values for a given key are equivalent.
	 */
	protected function originalIsEquivalent(string $key): bool
	{
		if (! array_key_exists($key, $this->original)) {
			return false;
		}

		$current = $this->attributes[$key];
		$original = $this->original[$key];

		if ($current === $original) {
			return true;
		}

		//dump(['key'=>$key,'current'=>$current,'original'=>$this->original[$key],'objectvalue'=>$this->getAttributeAsObject($key)->isDirty()]);
		return ! $this->getAttributeAsObject($key)->isDirty();
	}

	public function getOriginal(): array
	{
		static $result = NULL;

		if (is_null($result)) {
			$result = collect();

			// @todo Optimise this foreach with getAttributes()
			foreach (parent::getOriginal() as $attribute => $value) {
				// If the attribute name has language tags
				$matches = [];
				if (preg_match('/^([a-zA-Z]+)(;([a-zA-Z-;]+))+/',$attribute,$matches)) {
					$attribute = $matches[1];

					// If the attribute doesnt exist we'll create it
					$o = Arr::get($result,$attribute,Factory::create($attribute,[]));
					$o->setLangTag($matches[3],$value);

				} else {
					$o = Factory::create($attribute,$value);
				}

				if (! $result->has($attribute)) {
					// Set the rdn flag
					if (preg_match('/^'.$attribute.'=/i',$this->dn))
						$o->setRDN();

					// Set required flag
					$o->required_by(collect($this->getAttribute('objectclass')));

					$result->put($attribute,$o);
				}
			}
		}

		return $result->toArray();
	}

	/* ATTRIBUTES */

	/**
	 * Return a key to use for sorting
	 *
	 * @todo This should be the DN in reverse order
	 * @return string
	 */
	public function getSortKeyAttribute(): string
	{
		return $this->getDn();
	}

	/* METHODS */

	/**
	 * Get an attribute as an object
	 *
	 * @param string $key
	 * @return Attribute|null
	 */
	public function getAttributeAsObject(string $key): Attribute|null
	{
		return Arr::get($this->getAttributesAsObjects(),$key);
	}

	/**
	 * Convert all our attribute values into an array of Objects
	 *
	 * @return Collection
	 */
	protected function getAttributesAsObjects(): Collection
	{
		static $result = NULL;

		if (is_null($result)) {
			$result = collect();

			foreach (parent::getAttributes() as $attribute => $value) {
				// If the attribute name has language tags
				$matches = [];
				if (preg_match('/^([a-zA-Z]+)(;([a-zA-Z-;]+))+/',$attribute,$matches)) {
					$attribute = $matches[1];

					// If the attribute doesnt exist we'll create it
					$o = Arr::get($result,$attribute,Factory::create($attribute,[]));
					$o->setLangTag($matches[3],$value);

				} else {
					$o = Factory::create($attribute,$value);
				}

				if (! $result->has($attribute)) {
					// Set the rdn flag
					if (preg_match('/^'.$attribute.'=/i',$this->dn))
						$o->setRDN();

					// Set required flag
					$o->required_by(collect($this->getAttribute('objectclass')));

					// Store our original value to know if this attribute has changed
					if ($x=Arr::get($this->original,$attribute))
						$o->oldValues($x);

					$result->put($attribute,$o);
				}
			}

			$sort = collect(config('ldap.attr_display_order',[]))->transform(function($item) { return strtolower($item); });

			// Order the attributes
			$result = $result->sortBy([function(Attribute $a,Attribute $b) use ($sort): int {
				if ($a === $b)
					return 0;

				// Check if $a/$b are in the configuration to be sorted first, if so get it's key
				$a_key = $sort->search($a->name_lc);
				$b_key = $sort->search($b->name_lc);

				// If the keys were not in the sort list, set the key to be the count of elements (ie: so it is last to be sorted)
				if ($a_key === FALSE)
					$a_key = $sort->count()+1;

				if ($b_key === FALSE)
					$b_key = $sort->count()+1;

				// Case where neither $a, nor $b are in ldap.attr_display_order, $a_key = $b_key = one greater than num elements.
				// So we sort them alphabetically
				if ($a_key === $b_key)
					return strcasecmp($a->name,$b->name);

				// Case where at least one attribute or its friendly name is in $attrs_display_order
				// return -1 if $a before $b in $attrs_display_order
				return ($a_key < $b_key) ? -1 : 1;
			} ]);
		}

		return $result;
	}

	/**
	 * Return a list of available attributes - as per the objectClass entry of the record
	 *
	 * @return Collection
	 */
	public function getAvailableAttributes(): Collection
	{
		$result = collect();

		foreach ($this->objectclass as $oc)
			$result = $result->merge(config('server')->schema('objectclasses',$oc)->attributes);

		return $result;
	}

	/**
	 * Return a secure version of the DN
	 * @return string
	 */
	public function getDNSecure(): string
	{
		return Crypt::encryptString($this->getDn());
	}

	/**
	 * Return a list of LDAP internal attributes
	 *
	 * @return Collection
	 */
	public function getInternalAttributes(): Collection
	{
		return collect($this->getAttributes())->filter(function($item) {
			return $item->is_internal;
		});
	}

	/**
	 * Return a list of attributes without any values
	 *
	 * @return Collection
	 */
	public function getMissingAttributes(): Collection
	{
		return $this->getAvailableAttributes()->diff($this->getVisibleAttributes());
	}

	/**
	 * Return this list of user attributes
	 *
	 * @return Collection
	 */
	public function getVisibleAttributes(): Collection
	{
		return collect($this->getAttributes())->filter(function($item) {
			return ! $item->is_internal;
		});
	}

	/**
	 * Return an icon for a DN based on objectClass
	 *
	 * @return string
	 */
	public function icon(): string
	{
		$objectclasses = array_map('strtolower',$this->objectclass);

		// Return icon based upon objectClass value
		if (in_array('person',$objectclasses) ||
			in_array('organizationalperson',$objectclasses) ||
			in_array('inetorgperson',$objectclasses) ||
			in_array('account',$objectclasses) ||
			in_array('posixaccount',$objectclasses))

			return 'fas fa-user';

		elseif (in_array('organization',$objectclasses))
			return 'fas fa-university';

		elseif (in_array('organizationalunit',$objectclasses))
			return 'fas fa-object-group';

		elseif (in_array('posixgroup',$objectclasses) ||
			in_array('groupofnames',$objectclasses) ||
			in_array('groupofuniquenames',$objectclasses) ||
			in_array('group',$objectclasses))

			return 'fas fa-users';

		elseif (in_array('dcobject',$objectclasses) ||
			in_array('domainrelatedobject',$objectclasses) ||
			in_array('domain',$objectclasses) ||
			in_array('builtindomain',$objectclasses))

			return 'fas fa-network-wired';

		elseif (in_array('alias',$objectclasses))
			return 'fas fa-theater-masks';

		elseif (in_array('country',$objectclasses))
			return sprintf('flag %s',strtolower(Arr::get($this->c,0)));

		elseif (in_array('device',$objectclasses))
			return 'fas fa-mobile-alt';

		elseif (in_array('document',$objectclasses))
			return 'fas fa-file-alt';

		elseif (in_array('iphost',$objectclasses))
			return 'fas fa-wifi';

		elseif (in_array('room',$objectclasses))
			return 'fas fa-door-open';

		elseif (in_array('server',$objectclasses))
			return 'fas fa-server';

		elseif (in_array('openldaprootdse',$objectclasses))
			return 'fas fa-info';

		// Default
		return 'fa-fw fas fa-cog';
	}
}