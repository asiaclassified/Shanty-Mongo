<?php
require_once 'Shanty/Mongo/Exception.php';
require_once 'Shanty/Mongo/Iterator/Default.php';

/**
 * @category   Shanty
 * @package    Shanty_Mongo
 * @copyright  Shanty Tech Pty Ltd
 * @license    New BSD License
 * @author     Coen Hyde
 */
class Shanty_Mongo_Document extends Shanty_Mongo_Collection implements ArrayAccess, Countable, IteratorAggregate
{
	protected static $_requirements = array(
		'_id' => 'Validator:MongoId'
	);
	
	protected $_docRequirements = array();
	protected $_data = array();
	protected $_cleanData = array();
	protected $_config = array(
		'new' => true,
		'connectionGroup' => null,
		'db' => null,
		'collection' => null,
		'pathToDocument' => null,
		'criteria' => array(),
		'parentIsDocumentSet' => false,
		'requirementModifiers' => array()
	);
	protected $_operations = array();
	protected $_references = null;
	
	public function __construct($data = array(), $config = array())
	{
		// Make sure mongo is initialised
		Shanty_Mongo::init();
		
		$this->_config = array_merge($this->_config, $config);
		$this->_references = new SplObjectStorage();
		
		// Store data
		if ($this->isNewDocument()) $this->_data = $data;
		else $this->_cleanData = $data;
		
		// If not connected and this is a new root document, figure out the db and collection
		if ($this->isNewDocument() && $this->isRootDocument() && !$this->isConnected()) {
			$this->setConfigAttribute('connectionGroup', static::getConnectionGroupName());
			$this->setConfigAttribute('db', static::getDbName());
			$this->setConfigAttribute('collection', static::getCollectionName());
		}
		
		// apply requirements from collection and requirement modifiers
		$this->applyRequirements(static::getCollectionRequirements(), false);
		$this->applyRequirements($this->_config['requirementModifiers'], false);
		
		// Create document id if one is required
		if ($this->isNewDocument() && ($this->hasKey() || (isset($this->_config['hasId']) && $this->_config['hasId']))) {
			$this->_id = new MongoId();
		}
		
		// If has key then add it to the update criteria
		if ($this->hasKey()) {
			$this->setCriteria($this->getPathToProperty('_id'), $this->getId());
		}
	}
	
	/**
	 * Get this document's id
	 * 
	 * @return MongoId
	 */
	public function getId()
	{
		return $this->_id;
	}
	
	/**
	 * Does this document have an id
	 * 
	 * @return boolean
	 */
	public function hasId()
	{
		return !is_null($this->getId());
	}
	
	/**
	 * Get a config attribute
	 * 
	 * @param string $attribute
	 */
	public function getConfigAttribute($attribute)
	{
		if (!$this->hasConfigAttribute($attribute)) return null;
		
		return $this->_config[$attribute];
	}
	
	/**
	 * Set a config attribute
	 * 
	 * @param string $attribute
	 * @param unknown_type $value
	 */
	public function setConfigAttribute($attribute, $value)
	{
		$this->_config[$attribute] = $value;
	}
	
	/**
	 * Determine if a config attribute is set
	 * 
	 * @param string $attribute
	 */
	public function hasConfigAttribute($attribute)
	{
		return array_key_exists($attribute, $this->_config);
	}
	
	/**
	 * Is this document connected to a db and collection
	 */
	public function isConnected()
	{
		return (!is_null($this->getConfigAttribute('connectionGroup')) && !is_null($this->getConfigAttribute('db')) && !is_null($this->getConfigAttribute('collection')));
	}
	
	/**
	 * Get the path to this document from the root document
	 * 
	 * @return string
	 */
	public function getPathToDocument()
	{
		return $this->getConfigAttribute('pathToDocument');
	}
	
	/**
	 * Set the path to this document from the root document
	 * @param unknown_type $path
	 */
	public function setPathToDocument($path)
	{
		$this->setConfigAttribute('pathToDocument', $path);
	}
	
	/**
	 * Get the full path from the root document to a property
	 * 
	 * @param $property
	 * @return string
	 */
	public function getPathToProperty($property)
	{
		if ($this->isRootDocument()) return $property;
		
		return $this->getPathToDocument().'.'.$property;
	}

	/**
	 * Is this document a root document
	 * 
	 * @return boolean
	 */
	public function isRootDocument()
	{
		return is_null($this->getPathToDocument());
	}
	
/**
	 * Determine if this document has a key
	 * 
	 * @return boolean
	 */
	public function hasKey()
	{
		return ($this->isRootDocument() && !is_null($this->getConfigAttribute('collection')));
	}
	
	/**
	 * Is this document a child element of a document set
	 * 
	 * @return boolean
	 */
	public function isParentDocumentSet()
	{
		return $this->_config['parentIsDocumentSet'];
	}
	
	/**
	 * Determine if the document has certain criteria
	 * 
	 * @return boolean
	 */
	public function hasCriteria($property)
	{
		return array_key_exists($property, $this->_config['criteria']);
	}
	
	/**
	 * Add criteria
	 * 
	 * @param string $property
	 * @param MongoId $id
	 */
	public function setCriteria($property = null, $value = null)
	{
		$this->_config['criteria'][$property] = $value;
	}
	
	/**
	 * Get criteria
	 * 
	 * @param string $property
	 * @return mixed
	 */
	public function getCriteria($property = null)
	{
		if (is_null($property)) return $this->_config['criteria'];
		
		if (!array_key_exists($property, $this->_config['criteria'])) return null;
		
		return $this->_config['criteria'][$property];
	}
	
	/**
	 * Fetch an instance of MongoDb
	 * 
	 * @param boolean $writable
	 * @return MongoDb
	 */
	public function _getMongoDb($writable = true)
	{
		if (is_null($this->getConfigAttribute('db'))) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception('Can not fetch instance of MongoDb. Document is not connected to a db.');
		}
		
		if ($writable) $connection = Shanty_Mongo::getWriteConnection($this->getConfigAttribute('connectionGroup'));
		else $connection = Shanty_Mongo::getReadConnection($this->getConfigAttribute('connectionGroup'));
		
		return $connection->selectDB($this->getConfigAttribute('db'));
	}
	
	/**
	 * Fetch an instance of MongoCollection
	 * 
	 * @param boolean $writable
	 * @return MongoCollection
	 */
	public function _getMongoCollection($writable = true)
	{
		if (is_null($this->getConfigAttribute('collection'))) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception('Can not fetch instance of MongoCollection. Document is not connected to a collection.');
		}
		
		return $this->_getMongoDb($writable)->selectCollection($this->getConfigAttribute('collection'));
	}

	/**
	 * Apply a set of requirements
	 * 
	 * @param array $requirements
	 */
	public function applyRequirements($requirements, $dirty = true)
	{
		if ($dirty) {
			$requirements = static::makeRequirementsTidy($requirements);
		}
		
		$this->_docRequirements = static::mergeRequirements($this->_docRequirements, $requirements);
	}
	
	/**
	 * Test if this document has a particular requirement
	 * 
	 * @param string $property
	 * @param string $requirement
	 */
	public function hasRequirement($property, $requirement)
	{
		if (!array_key_exists($property, $this->_docRequirements)) return false;
		
		switch($requirement) {
			case 'Document':
			case 'DocumentSet':
				foreach ($this->_docRequirements[$property] as $requirementSearch => $params) {
					$standardClass = 'Shanty_Mongo_'.$requirement;
					
					// Return basic document or document set class if requirement matches
					if ($requirementSearch == $requirement) {
						return $standardClass;
					}
					
					// Find the document class
					$matches = array();
					preg_match("/^{$requirement}:([A-Za-z][\w\-]*)$/", $requirementSearch, $matches);
					
					if (!empty($matches)) {
						if (!class_exists($matches[1])) {
							require_once 'Shanty/Mongo/Exception.php';
							throw new Shanty_Mongo_Exception("$requirement class of '{$matches[1]}' does not exist");
						}
						
						if (!is_subclass_of($matches[1], $standardClass)) {
							require_once 'Shanty/Mongo/Exception.php';
							throw new Shanty_Mongo_Exception("$requirement of '{$matches[1]}' sub is not a class of $standardClass does not exist");
						}
						
						return $matches[1];
					}
				}
				
				return false;
		}
		
		return array_key_exists($requirement, $this->_docRequirements[$property]);
	}
	
	/**
	 * Get all requirements. If prefix is provided then only the requirements for 
	 * the properties that start with prefix will be returned.
	 * 
	 * @param string $prefix
	 */
	public function getRequirements($prefix = null)
	{
		// If no prefix is provided return all requirements
		if (is_null($prefix)) return $this->_docRequirements;
		
		// Find requirements for all properties starting with prefix
		$properties = array_filter(array_keys($this->_docRequirements), function($value) use ($prefix) {
			return (substr_compare($value, $prefix, 0, strlen($prefix)) == 0 && strlen($value) > strlen($prefix));
		});
		
		$requirements = array_intersect_key($this->_docRequirements, array_flip($properties));
		
		// Remove prefix from requirement key
		$newRequirements = array();
		array_walk($requirements, function($value, $key) use ($prefix, &$newRequirements) {
			$newRequirements[substr($key, strlen($prefix))] = $value;
		});
		
		return $newRequirements;
	}
	
	/**
	 * Add a requirement to a property
	 * 
	 * @param string $property
	 * @param string $requirement
	 */
	public function addRequirement($property, $requirement, $options = null)
	{
		if (!array_key_exists($property, $this->_docRequirements)) {
			$this->_docRequirements[$property] = array();
		}
		
		$this->_docRequirements[$property][$requirement] = $options;
	}
	
	/**
	 * Remove a requirement from a property
	 * 
	 * @param string $property
	 * @param string $requirement
	 */
	public function removeRequirement($property, $requirement)
	{
		if (!array_key_exists($property, $this->_docRequirements)) return;
		
		foreach ($this->_docRequirements[$property] as $requirementItem => $options) {
			if ($requirement === $requirementItem) {
				unset($this->_docRequirements[$property][$requirementItem]);
			}
		}
	}
	
	/**
	 * Get all the properties with a particular requirement
	 * 
	 * @param array $requirement
	 */
	public function getPropertiesWithRequirement($requirement)
	{
		$properties = array();
		
		foreach ($this->_docRequirements as $property => $requirementList) {
			if (strpos($property, '.') > 0) continue;
			
			if (array_key_exists($requirement, $requirementList)) {
				$properties[] = $property;
			}
		}
		
		return $properties;
	}
	
	/**
	 * Get all validators attached to a property
	 * 
	 * @param String $property Name of property
	 * @return Zend_Validate
	 **/
	public function getValidators($property)
	{
		$validators = new Zend_Validate();
		
		// Return if no requirements are set for this property
		if (!array_key_exists($property, $this->_docRequirements)) return $validators;

		foreach ($this->_docRequirements[$property] as $requirement => $options) {
			// continue if requirement does not exist or is not a validator requirement
			$validator = Shanty_Mongo::retrieveRequirement($requirement, $options);
			if (!$validator || !($validator instanceof Zend_Validate_Interface)) continue;
			
			$validators->addValidator($validator);
		}
		
		return $validators;
	}
	
	/**
	 * Get all filters attached to a property
	 * 
	 * @param String $property
	 * @return Zend_Filter
	 */
	public function getFilters($property)
	{
		$filters = new Zend_Filter();
		
		// Return if no requirements are set for this property
		if (!array_key_exists($property, $this->_docRequirements)) return $filters;
		
		foreach ($this->_docRequirements[$property] as $requirement => $options) {
			// continue if requirement does not exist or is not a filter requirement
			$filter = Shanty_Mongo::retrieveRequirement($requirement, $options);
			if (!$filter || !($filter instanceof Zend_Filter_Interface)) continue;
			
			$filters->addFilter($filter);
		}
		
		return $filters;
	}
	
	
	/**
	 * Test if a value is valid against a property
	 * 
	 * @param String $property
	 * @param Boolean $value
	 */
	public function isValid($property, $value)
	{
		$validators = $this->getValidators($property);
		
		return $validators->isValid($value);
	}
	
	/**
	 * Get a property
	 * 
	 * @param mixed $property
	 */
	public function getProperty($property)
	{
		// If property exists and initialised then return it
		if (array_key_exists($property, $this->_data)) {
			return $this->_data[$property];
		}
		
		// Fetch clean data for this property
		if (array_key_exists($property, $this->_cleanData)) {
			$data = $this->_cleanData[$property];
		}
		else {
			$data = array();
		}
		
		// If data is not an array then we can do nothing else with it
		if (!is_array($data)) {
			$this->_data[$property] = $data;
			return $this->_data[$property];
		}
	
		// If property is supposed to be an array then initialise an array
		if ($this->hasRequirement($property, 'Array')) {
			return $this->_data[$property] = $data;
		}
		
		// If property is a reference to another document then fetch the reference document
		$db = $this->getConfigAttribute('db');
		if (MongoDBRef::isRef($data)) {
			$collection = $data['$ref'];
			$data = MongoDBRef::get($this->_getMongoDB(false), $data);
			
			// If this is a broken reference then no point keeping it for later
			if (!$data) {
				$this->_data[$property] = null;
				return $this->_data[$property];
			}
			
			$reference = true;
		}
		else {
			$collection = $this->getConfigAttribute('collection');
			$reference = false;
		}
		
		// Find out the class name of the document or document set we are loaded
		if ($className = $this->hasRequirement($property, 'DocumentSet')) {
			$docType = 'Shanty_Mongo_DocumentSet';
		}
		else {
			$className = $this->hasRequirement($property, 'Document');
			
			// Load a document anyway so long as $data is not empty
			if (!$className && !empty($data)) {
				$className = 'Shanty_Mongo_Document';
			}
			
			if ($className) $docType = 'Shanty_Mongo_Document';
		}
		
		// Nothing else to do
		if (!$className) return null;
		
		// Configure property for document/documentSet usage
		$config = array();
		$config['new'] = empty($data);
		$config['connectionGroup'] = $this->getConfigAttribute('connectionGroup');
		$config['db'] = $this->getConfigAttribute('db');
		$config['collection'] = $collection;
		$config['requirementModifiers'] = $this->getRequirements($property.'.');
		$config['hasId'] = $this->hasRequirement($property, 'hasId');
		
		if (!$reference) {
			$config['pathToDocument'] = $this->getPathToProperty($property);
			$config['criteria'] = $this->getCriteria();
		}
		
		// Initialise document
		$document = new $className($data, $config);
		
		// if this document was a reference then remember that
		if ($reference) {
			$this->_references->attach($document);
		}
		
		$this->_data[$property] = $document;
		return $this->_data[$property];
	}
	
	/**
	 * Set a property
	 * 
	 * @param mixed $property
	 * @param mixed $value
	 */
	public function setProperty($property, $value)
	{
		$validators = $this->getValidators($property);
		
		// Throw exception if value is not valid
		if (!is_null($value) && !$validators->isValid($value)) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception(implode($validators->getMessages(), "\n"));
		}
		
		// Unset property
		if (is_null($value)) {
			$this->_data[$property] = null;
			return;
		}
		
		if ($value instanceof Shanty_Mongo_Document && !$this->hasRequirement($property, 'AsReference')) {
			if (!$value->isNewDocument()) {
				$documentClass = get_class($value);
				$value = new $documentClass($value->export(), array('new' => false, 'pathToDocument' => $this->getPathToProperty($property)));
			}
			else {
				$value->setPathToDocument($this->getPathToProperty($property));
			}
			
			$value->setConfigAttribute('connectionGroup', $this->getConfigAttribute('connectionGroup'));
			$value->setConfigAttribute('db', $this->getConfigAttribute('db'));
			$value->setConfigAttribute('collection', $this->getConfigAttribute('collection'));
			$value->setConfigAttribute('criteria', $this->getCriteria());
		}
		
		// Filter value
		$value = $this->getFilters($property)->filter($value);
		
		$this->_data[$property] = $value;
	}
	
	/**
	 * Determine if this document has a property
	 * 
	 * @param $property
	 * @return boolean
	 */
	public function hasProperty($property)
	{
		// If property has been initialised
		if (array_key_exists($property, $this->_data)) {
			return !is_null($this->_data[$property]);
		}
		
		// If property has not been initialised
		if (array_key_exists($property, $this->_cleanData)) {
			return !is_null($this->_cleanData[$property]);
		}
		
		return false;
	}

	/**
	 * Get a list of all property keys in this document
	 */
	public function getPropertyKeys()
	{
		$keyList = array();
		$doNoCount = array();
		
		foreach ($this->_data as $property => $value) {
			if (($value instanceof Shanty_Mongo_Document && !$value->isEmpty()) || 
				(!($value instanceof Shanty_Mongo_Document) && !is_null($value))) {
				$keyList[] = $property;
			}
			else {
				$doNoCount[] = $property;
			}
		}
		
		foreach ($this->_cleanData as $property => $value) {
			if (in_array($property, $keyList) || in_array($property, $doNoCount)) continue;
			
			if (!is_null($value)) $keyList[] = $property;
		}
		
		return $keyList;
	}
	
	/**
	 * Create a reference to this document
	 * 
	 * @return array
	 */
	public function createReference()
	{
		if (!$this->isRootDocument()) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception('Can not create reference. Document is not a root document');
		}
		
		if (!$this->isConnected()) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception('Can not create reference. Document does not connected to a db and collection');
		}
		
		return MongoDBRef::create($this->getConfigAttribute('collection'), $this->getId());
	}
	
	/**
	 * Test to see if a document is a reference in this document
	 * 
	 * @param Shanty_Mongo_Document $document
	 * @return boolean
	 */
	public function isReference(Shanty_Mongo_Document $document)
	{
		return $this->_references->contains($document);
	}
	
	/**
	 * Export all data
	 * 
	 * @return array
	 */
	public function export()
	{
		$exportData = $this->_cleanData;
		
		foreach ($this->_data as $property => $value) {
			// If property has been deleted
			if (is_null($value)) {
				unset($exportData[$property]);
				continue;
			}
			
			// If property is a document
			if ($value instanceof Shanty_Mongo_Document) {
				if ($this->hasRequirement($property, 'AsReference') || $this->isReference($value)) {
					$exportData[$property] = $value->createReference();
					continue;
				}
				
				$data = $value->export();
				if (!empty($data)) {
					$exportData[$property] = $data;
				}
				continue;
			}
			
			$exportData[$property] = $value;
		}
		
		// make sure required properties are not empty
		$requiredProperties = $this->getPropertiesWithRequirement('Required');
		foreach ($requiredProperties as $property) {
			if (!isset($exportData[$property]) || empty($exportData[$property])) {
				require_once 'Shanty/Mongo/Exception.php';
				throw new Shanty_Mongo_Exception("Property '{$property}' must not be null.");
			}
		}
		
		return $exportData;
	}
	
	/**
	 * Is this document a new document
	 * 
	 * @return boolean
	 */
	public function isNewDocument()
	{
		return ($this->_config['new']);
	}
	
	/**
	 * Test to see if this document is empty
	 * 
	 * @return Boolean
	 */
	public function isEmpty()
	{
		$doNoCount = array();
		
		foreach ($this->_data as $property => $value) {
			if ($value instanceof Shanty_Mongo_Document) {
				if (!$value->isEmpty()) return false;
			}
			elseif (!is_null($value)) {
				return false;
			}
			
			$doNoCount[] = $property;
		}
		
		foreach ($this->_cleanData as $property => $value) {
			if (in_array($property, $doNoCount)) {
				continue;
			}
			
			if (!is_null($value)) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Convert data changes into operations
	 * 
	 * @param array $data
	 */
	public function processChanges(array $data = array())
	{
		foreach ($data as $property => $value) {
			if ($property === '_id') continue;
			
			if (!array_key_exists($property, $this->_cleanData) || $this->_cleanData[$property] !== $value) {
				$this->addOperation('$set', $property, $value);
			}
		}
		
		foreach ($this->_cleanData as $property => $value) {
			if (array_key_exists($property, $data)) continue;
			
			$this->addOperation('$unset', $property, 1);
		}
	}
	
	/**
	 * Save this document
	 * 
	 * @param boolean $entierDocument Force the saving of the entier document, instead of just the changes
	 * @return boolean Result of save
	 */
	public function save($entierDocument = false)
	{
		if (!$this->isConnected()) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception('Can not save documet. Document is not connected to a db and collection');
		}
		
		$exportData = $this->export();
		
		if ($this->isRootDocument() && ($this->isNewDocument() || $entierDocument)) {
			// Save the entier document
			$operations = $exportData;
		}
		else {
			// Update an existing document and only send the changes
			if (!$this->isRootDocument()) {
				// are we updating a child of an array?
				if ($this->isNewDocument() && $this->isParentDocumentSet()) {
					$this->_operations['$push'][$this->getPathToDocument()] = $exportData;
					$exportData = array();
				}
			}
			
			// Convert all data changes into sets and unsets
			$this->processChanges($exportData);
			
			$operations = $this->getOperations(true);

			// There are no changes, return so we don't blank the object
			if (empty($operations)) {
				return true;
			}
		}
		
		$result = $this->_getMongoCollection(true)->update($this->getCriteria(), $operations, array('upsert' => true));
		$this->_data = array();
		$this->_cleanData = $exportData;
		$this->purgeOperations(true);
		
		return $result;
	}
	
	/**
	 * Delete this document
	 * 
	 * $return boolean Result of delete
	 */
	public function delete()
	{
		if (!$this->isConnected()) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception('Can not delete document. Document is not connected to a db and collection');
		}
		
		$mongoCollection = $this->_getMongoCollection(true);
		
		if (!$this->isRootDocument()) {
			$result = $mongoCollection->update($this->getCriteria(), array('$unset' => array($this->getPathToDocument() => 1)));
		}
		else {
			$result = $mongoCollection->remove($this->getCriteria(), true);
		}
		
		return $result;
	}
	
	/**
	 * Get a property
	 * 
	 * @param $property
	 * @return mixed
	 */
	public function __get($property)
	{
		return $this->getProperty($property);
	}
	
	/**
	 * Set a property
	 * 
	 * @param string $property
	 * @param mixed $value
	 */
	public function __set($property, $value)
	{
		return $this->setProperty($property, $value);
	}
	
	/**
	 * Test to see if a property is set
	 * 
	 * @param string $property
	 */
	public function __isset($property)
	{
		return $this->hasProperty($property);
	}
	
	/**
	 * Unset a property
	 * 
	 * @param string $property
	 */
	public function __unset($property)
	{
		$this->_data[$property] = null;
	}
	
	/**
	 * Get an offset
	 * 
	 * @param string $offset
	 * @return mixed
	 */
	public function offsetGet($offset)
	{
		return $this->getProperty($offset);
	}
	
	/**
	 * set an offset
	 * 
	 * @param string $offset
	 * @param mixed $value
	 */
	public function offsetSet($offset, $value)
	{
		return $this->setProperty($offset, $value);
	}
	
	/**
	 * Test to see if an offset exists
	 * 
	 * @param string $offset
	 */
	public function offsetExists($offset)
	{
		return $this->hasProperty($offset);
	}
	
	/**
	 * Unset a property
	 * 
	 * @param string $offset
	 */
	public function offsetUnset($offset)
	{
		$this->_data[$offset] = null;
	}
	
	/**
	 * Count all properties in this document
	 * 
	 * @return int
	 */
	public function count()
	{
		return count($this->getPropertyKeys());
	}
	
	/**
	 * Get the document iterator
	 * 
	 * @return Shanty_Mongo_DocumentIterator
	 */
	public function getIterator()
	{
		return new Shanty_Mongo_Iterator_Default($this);
	}
	
	/**
	 * Get all operations
	 * 
	 * @param Boolean $includingChildren Get operations from children as well
	 */
	public function getOperations($includingChildren = false)
	{
		$operations = array();
		if ($includingChildren) {
			foreach ($this->_data as $property => $document) {
				if (!($document instanceof Shanty_Mongo_Document)) continue;
				
				if (!$this->isReference($document) && !$this->hasRequirement($property, 'AsReference')) {
					$operations = array_merge($operations, $document->getOperations(true));
				}
			}
		}
		
		return array_merge($operations, $this->_operations);
	}
	
	/**
	 * Remove all operations
	 * 
	 * @param Boolean $includingChildren Remove operations from children as wells
	 */
	public function purgeOperations($includingChildren = false)
	{
		if ($includingChildren) {
			foreach ($this->_data as $property => $document) {
				if (!($document instanceof Shanty_Mongo_Document)) continue;
				
				if (!$this->isReference($document) || $this->hasRequirement($property, 'AsReference')) {
					$document->purgeOperations(true);
				}
			}
		}
		
		$this->_operations = array();
	}
	
	/**
	 * Add an operation
	 * 
	 * @param string $operation
	 * @param array $data
	 */
	public function addOperation($operation, $property, $value = null)
	{
		// Make sure the operation is valid
		if (!Shanty_Mongo::isValidOperation($operation)) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception("'{$operation}' is not valid operation");
		}
		
		// Prime the specific operation
		if (!array_key_exists($operation, $this->_operations)) {
			$this->_operations[$operation] = array();
		}
		
		// Save the operation
		$this->_operations[$operation][$this->getPathToProperty($property)] = $value;
	}
	
	/**
	 * Increment a property by a specified amount
	 * 
	 * @param string $property
	 * @param int $value
	 */
	public function inc($property, $value)
	{
		return $this->addOperation('$inc', $property, $value);
	}
	
	/**
	 * Push a value to a property
	 * 
	 * @param string $property
	 * @param mixed $value
	 */
	public function push($property, $value)
	{
		// Export value if needed
		if ($value instanceof Shanty_Mongo_Document) {
			$value = $value->export();
		}
		
		return $this->addOperation('$pushAll', $property, array($value));
	}
	
	/**
	 * Pull all occurrences a value from an array
	 * 
	 * @param string $property
	 * @param mixed $value
	 */
	public function pull($property, $value)
	{
		return $this->addOperation('$pullAll', $property, $value);
	}
	
	/*
	 * Adds value to the array only if its not in the array already.
	 * 
	 * @param string $property
	 * @param mixed $value
	 */
	public function addToSet($property, $value)
	{
		return $this->addOperation('$addToSet', $property, $value);
	}
	
	/*
	 * Removes an element from an array
	 * 
	 * @param string $property
	 * @param mixed $value
	 */
	public function pop($property, $value)
	{
		return $this->addOperation('$pop', $property, $value);
	}
}