<?php

require 'lib/core/model/datasources/Datasource.php';

class Model extends Object {
    public $belongsTo = array();
    public $hasMany = array();
    public $hasOne = array();
    public $id = null;
    public $recursion = 0;
    public $schema = array();
    public $table = null;
    public $primaryKey = null;
    public $displayField = null;
    public $environment = null;
    public $conditions = array();
    public $order = null;
    public $limit = null;
    public $perPage = 20;
    public $validates = array();
    public $errors = array();
    public $associations = array(
        'hasMany' => array('primaryKey', 'foreignKey', 'conditions', 'limit', 'order'),
        'belongsTo' => array('primaryKey', 'foreignKey', 'conditions'),
        'hasOne' => array('primaryKey', 'foreignKey', 'conditions')
    );
    public $pagination = array();

    public function __construct() {
        if(is_null($this->environment)):
            $this->environment = Config::read('environment');
        endif;
        if(is_null($this->table)):
            $database = Config::read('database');
            $this->table = $database[$this->environment]['prefix'] . Inflector::underscore(get_class($this));
        endif;
        $this->setSource($this->table);
        ClassRegistry::addObject(get_class($this), $this);
        $this->createLinks();
    }
    public function __call($method, $condition) {
        if(preg_match('/(all|first)By([\w]+)/', $method, $match)):
            $field = Inflector::underscore($match[2]);
            $params = array('conditions' => array($field => $condition[0]));
            if(isset($condition[1])):
                $params = array_merge($params, $condition[1]);
            endif;
            return $this->{$match[1]}($params);
        else:
            trigger_error('Call to undefined method Model::' . $method . '()', E_USER_ERROR);
            return false;
        endif;
    }
    public static function &getConnection($environment = null) {
        static $instance = array();
        if(!isset($instance[0]) || !$instance[0]):
            $instance[0] = Connection::getDatasource($environment);
        endif;
        return $instance[0];
    }
    public function setSource($table) {
        $db =& self::getConnection($this->environment);
        if($table):
            $this->table = $table;
            $sources = $db->listSources();
            if(!in_array($this->table, $sources)):
                $this->error('missingTable', array('model' => get_class($this), 'table' => $this->table));
                return false;
            endif;
            if(empty($this->schema)):
                $this->describe();
            endif;
        endif;
        return true;
    }
    public function describe() {
        $db =& self::getConnection($this->environment);
        $schema = $db->describe($this->table);
        if(is_null($this->primaryKey)):
            foreach($schema as $field => $describe):
                if($describe['key'] == 'PRI'):
                    $this->primaryKey = $field;
                    break;
                endif;
            endforeach;
        endif;
        return $this->schema = $schema;
    }
    public function loadModel($model) {
        if(!isset($this->{$model})):
            if($class =& ClassRegistry::load($model)):
                $this->{$model} = $class;
            else:
                $this->error('missingModel', array('model' => $model));
                return false;
            endif;
        endif;
        return true;
    }
    public function createLinks() {
        foreach(array_keys($this->associations) as $type):
            $associations =& $this->{$type};
            foreach($associations as $key => $properties):
                if(is_numeric($key)):
                    unset($associations[$key]);
                    if(is_array($properties)):
                        $associations[$key = $properties['className']] = $properties;
                    else:
                        $associations[$key = $properties] = array('className' => $properties);
                    endif;
                elseif(!isset($properties['className'])):
                    $associations[$key]['className'] = $key;
                endif;
                $this->loadModel($associations[$key]['className']);
                $associations[$key] = $this->generateAssociation($type, $associations[$key]);
            endforeach;
        endforeach;
        return true;
    }
    public function generateAssociation($type, $association) {
        foreach($this->associations[$type] as $key):
            if(!isset($association[$key])):
                $data = null;
                switch($key):
                    case 'foreignKey':
                        if($type == 'belongsTo'):
                            $data = Inflector::underscore($association['className'] . 'Id');
                        else:
                            $data = Inflector::underscore(get_class($this)) . '_' . $this->primaryKey;
                        endif;
                        break;
                    case 'conditions':
                        $data = array();
                        break;
                    default:
                        $data = null;
                endswitch;
                $association[$key] = $data;
            endif;
        endforeach;
        return $association;
    }
    public function query($query) {
        $db =& self::getConnection($this->environment);
        return $db->query($query);
    }
    public function fetch($query) {
        $db =& self::getConnection($this->environment);
        return $db->fetchAll($query);
    }
    public function begin() {
        $db =& self::getConnection($this->environment);
        return $db->begin();
    }
    public function commit() {
        $db =& self::getConnection($this->environment);
        return $db->commit();
    }
    public function rollback() {
        $db =& self::getConnection($this->environment);
        return $db->rollback();
    }
    public function all($params = array()) {
        $db =& self::getConnection($this->environment);
        $params = array_merge(
            array(
                'fields' => array_keys($this->schema),
                'conditions' => isset($params['conditions']) ? array_merge($this->conditions, $params['conditions']) : $this->conditions,
                'order' => $this->order,
                'limit' => $this->limit,
                'recursion' => $this->recursion
            ),
            $params
        );
        $results = $db->read($this->table, $params);
        if($params['recursion'] >= 0):
            $results = $this->dependent($results, $params['recursion']);
        endif;
        return $results;
    }
    public function first($params = array()) {
        $params = array_merge(
            array('limit' => 1),
            $params
        );
        $results = $this->all($params);
        return empty($results) ? array() : $results[0];
    }
    public function dependent($results, $recursion = 0) {
        foreach(array_keys($this->associations) as $type):
            if($recursion < 0 and ($type != 'belongsTo' && $recursion <= 0)) continue;
            foreach($this->{$type} as $name => $association):
                foreach($results as $key => $result):
                    $name = Inflector::underscore($name);
                    $model = $association['className'];
                    $params = array();
                    if($type == 'belongsTo'):
                        $params['conditions'] = array(
                            $this->primaryKey => $result[$association['foreignKey']]
                        );
                        $params['recursion'] = $recursion - 1;
                    else:
                        $params['conditions'] = array_merge(
                            $association['conditions'],
                            array(
                                $association['foreignKey'] => $result[$this->primaryKey]
                            )
                        );
                        $params['recursion'] = $recursion - 2;
                        if($type == 'hasMany'):
                            $params['limit'] = $association['limit'];
                            $params['order'] = $association['order'];
                        endif;
                    endif;
                    $result = $this->{$model}->all($params);
                    if($type != 'hasMany' && !empty($result)):
                        $result = $result[0];
                    endif;
                    $results[$key][$name] = $result;
                endforeach;
            endforeach;
        endforeach;
        return $results;
    }
    public function count($params = array()) {
        $db =& self::getConnection($this->environment);
        $params = array_merge(
            array('fields' => '*', 'conditions' => $this->conditions),
            $params
        );
        return $db->count($this->table, $params);
    }
    public function paginate($params = array()) {
        $params = array_merge(
            array(
                'perPage' => $this->perPage,
                'page' => 1
            ),
            $params
        );
        $page = !$params['page'] ? 1 : $params['page'];
        $offset = ($page - 1) * $params['perPage'];
        $params['limit'] = $offset . ',' . $params['perPage'];

        $totalRecords = $this->count($params);
        $this->pagination = array(
            'totalRecords' => $totalRecords,
            'totalPages' => ceil($totalRecords / $params['perPage']),
            'perPage' => $params['perPage'],
            'offset' => $offset,
            'page' => $page
        );

        return $this->all($params);
    }
    public function toList($params = array()) {
        $params = array_merge(
            array(
                'key' => $this->primaryKey,
                'displayField' => $this->displayField
            ),
            $params
        );
        $all = $this->all($params);
        $results = array();
        foreach($all as $result):
            $results[$result[$params['key']]] = $result[$params['displayField']];
        endforeach;
        return $results;
    }
    public function exists($id) {
        $conditions = array_merge(
            $this->conditions,
            array(
                'conditions' => array(
                    $this->primaryKey => $id
                )
            )
        );
        $row = $this->first($conditions);
        return !empty($row);
    }
    public function insert($data) {
        $db =& self::getConnection($this->environment);
        return $db->create($this->table, $data);
    }
    public function update($params, $data) {
        $db =& self::getConnection($this->environment);
        $params = array_merge(
            array('conditions' => array(), 'order' => null, 'limit' => null),
            $params
        );
        return $db->update($this->table, array_merge($params, compact('data')));
    }
    public function save($data) {
        if(isset($data[$this->primaryKey]) && !is_null($data[$this->primaryKey])):
            $this->id = $data[$this->primaryKey];
        elseif(!is_null($this->id)):
            $data[$this->primaryKey] = $this->id;
        endif;
        foreach($data as $field => $value):
            if(!isset($this->schema[$field])):
                unset($data[$field]);
            endif;
        endforeach;
        $date = date('Y-m-d H:i:s');
        if(isset($this->schema['modified']) && !isset($data['modified'])):
            $data['modified'] = $date;
        endif;
        $exists = $this->exists($this->id);
        if(!$exists && isset($this->schema['created']) && !isset($data['created'])):
            $data['created'] = $date;
        endif;
        if(!($data = $this->beforeSave($data))) return false;
        if(!is_null($this->id) && $exists):
            $save = $this->update(array(
                'conditions' => array($this->primaryKey => $this->id),
                'limit' => 1
            ), $data);
            $created = false;
        else:
            $save = $this->insert($data);
            $created = true;
            $this->id = $this->getInsertId();
        endif;
        $this->afterSave($created);
        return $save;
    }
    public function validate($data) {
        $this->errors = array();
        $defaults = array(
            'required' => false,
            'allowEmpty' => false,
            'message' => null
        );
        foreach($this->validates as $field => $rules):
            if(!is_array($rules) || (is_array($rules) && isset($rules['rule']))):
                $rules = array($rules);
            endif;
            foreach($rules as $rule):
                if(!is_array($rule)):
                    $rule = array('rule' => $rule);
                endif;
                $rule = array_merge($defaults, $rule);
                if($rule['allowEmpty'] && empty($data[$field])):
                    continue;
                endif;
                $required = !isset($data[$field]) && $rule['required'];
                if($required):
                    $this->errors[$field] = is_null($rule['message']) ? $rule['rule'] : $rule['message'];
                elseif(isset($data[$field])):
                    if(!$this->callValidationMethod($rule['rule'], $data[$field])):
                        $message = is_null($rule['message']) ? $rule['rule'] : $rule['message'];
                        $this->errors[$field] = $message;
                        break;
                    endif;
                endif;
            endforeach;
        endforeach;
        return empty($this->errors);
    }
    public function callValidationMethod($params, $value) {
        $method = is_array($params) ? $params[0] : $params;
        $class = method_exists($this, $method) ? $this : 'Validation';
        if(is_array($params)):
            $params[0] = $value;
            return call_user_func_array(array($class, $method), $params);
        else:
            if($class == 'Validation'):
                return Validation::$params($value);
            else:
                return $this->$params($value);
            endif;
        endif;
    }
    public function beforeSave($data) {
        return $data;
    }
    public function afterSave($created) {
        return $created;
    }
    public function delete($id, $dependent = true) {
        $db =& self::getConnection($this->environment);
        $params = array('conditions' => array($this->primaryKey => $id), 'limit' => 1);
        if($this->exists($id) && $this->deleteAll($params)):
            if($dependent):
                $this->deleteDependent($id);
            endif;
            return true;
        endif;
        return false;
    }
    public function deleteDependent($id) {
        foreach(array('hasOne', 'hasMany') as $type):
            foreach($this->{$type} as $model => $assoc):
                $this->{$assoc['className']}->deleteAll(array('conditions' => array(
                    $assoc['foreignKey'] => $id
                )));
            endforeach;
        endforeach;
        return true;
    }
    public function deleteAll($params = array()) {
        $db =& self::getConnection($this->environment);
        $params = array_merge(
            array('conditions' => $this->conditions, 'order' => $this->order, 'limit' => $this->limit),
            $params
        );
        return $db->delete($this->table, $params);
    }
    public function getInsertId() {
        $db =& self::getConnection($this->environment);
        return $db->getInsertId();
    }
    public function getAffectedRows() {
        $db =& self::getConnection($this->environment);
        return $db->getAffectedRows();
    }
    public function escape($value, $column = null) {
        $db =& self::getConnection($this->environment);
        return $db->value($value, $column);
    }
}