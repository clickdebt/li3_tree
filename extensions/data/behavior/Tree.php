<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_tree\extensions\data\behavior;

use lithium\core\ConfigException;
use UnexpectedValueException;

class Tree extends \li3_behaviors\data\model\Behavior {
	
	/**
	 * Default tree configuration
	 *
	 * @var array
	 */
	protected static $_defaults = [
		'parent' => 'parent_id',
		'left' => 'lft',
		'right' => 'rght',
		'recursive' => false,
		'scope' => []
	];
	
	protected static function _filters($model, $behavior)
	{
        $model::applyFilter('save', function($self, $params, $chain) use ($behavior) {
			if (static::_save($params, $behavior)) {
				return $chain->next($self, $params, $chain);
			}
		});
        
        $model::applyFilter('delete', function($self, $params, $chain) use ($behavior) {
			if (static::_delete($params, $behavior)) {
				return $chain->next($self, $params, $chain);
			}
		});
	}
	
	/**
	 * Setting a scope to an entity node.
	 *
	 * @param object $entity
	 * @return array The scope values
	 * @throws UnexpectedValueException
	 */
	protected function _scope($entity) {
		$scope = [];
		foreach ($this->_config['scope'] as $key => $value) {
			if (is_numeric($key)) {
				if (isset($entity, $value)) {
					$scope[$value] = $entity->$value;
				} else {
					$message = "The `{$value}` scope in not present in the entity.";
					throw new UnexpectedValueException($message);
				}
			} else {
				$scope[$key] = $value;
			}
		}
		return $scope;
	}

	/**
	 * Returns all childrens of given element (including subchildren if `$recursive` is set
	 * to true or recursive is configured true)
	 *
	 * @param object $entity The entity to fetch the children of
	 * @param Boolean $recursive Overrides configured recursive param for this method
	 */
	public function childrens($entity, $rec = null, $mode = 'all') {
		extract($this->_config);

		$recursive = $rec ? : $recursive;

		if ($recursive) {
			if ($mode === 'count') {
				return ($entity->$right - $entity->$left - 1) / 2;
			} else {
				return $model::find($mode, [
					'conditions' => [
						$left => ['>' => $entity->$left],
						$right => ['<' => $entity->$right]
					] + $this->_scope($entity),
					'order' => [$left => 'asc']]
				);
			}
		} else {
			$id = $entity->{$model::key()};
			return $model::find($mode, [
				'conditions' => [$parent => $id] + $this->_scope($entity),
				'order' => [$left => 'asc']]
			);
		}
	}

	/**
	 * Get path
	 *
	 * returns an array containing all elements from the tree root node to the node with given
	 * an entity node (including this entity node) which have a parent/child relationship
	 *
	 * @param object $entity
	 */
	public function path($model, $behavior, $entity, $params = array()) {
		extract($this->_config);

		$data = [];
		while ($entity->data($parent) !== null) {
			$data[] = $entity;
			$getOptions = isset($params['getOptions']) ? $params['getOptions'] : array();
			$entity = $this->_getById($entity->$parent, $getOptions);
		}
		$data[] = $entity;
		$data = array_reverse($data);
		$model = $entity->model();
		return $model::create($data, [
			'exists' => true,
			'class' => 'set'
		]);
	}

	/**
	 * Move
	 *
	 * performs move operations of an entity in tree
	 *
	 * @param object $entity the entity node to move
	 * @param integer $newPosition Position new position of node in same level, starting with 0
	 * @param object $newParent The new parent entity node
	 */
	public function move($entity, $newPosition, $newParent = null) {
		extract($this->_config);

		if ($newParent !== null) {
			if($this->_scope($entity) !== ($parentScope = $this->_scope($newParent))) {
				$entity->set($parentScope);
			} elseif ($newParent->$left > $entity->$left && $newParent->$right < $entity->$right) {
				return false;
			}
			$parentId = $newParent->data($model::key());
			$entity->set([$parent => $parentId]);
			$entity->save();
			$parentNode = $newParent;
		} else {
			$newParent = $this->_getById($entity->$parent);
		}

		$childrenCount = $newParent->childrens(false, 'count');
		$position = $this->_getPosition($entity, $childrenCount);
		if ($position !== false) {
			$count = abs($newPosition - $position);

			for ($i = 0; $i < $count; $i++) {
				if ($position < $newPosition) {
					$entity->moveDown();
				} else {
					$entity->moveUp();
				}
			}
		}
		return true;
	}

	/**
	 * Before save
	 *
	 * this method is called befor each save
	 *
	 * @param array $params
	 */
	protected static function _save($params, $behavior) {
		//calling the new _save experiment
		static::_saveNew($params, $behavior);
		return true;
		
		
		extract($this->_config);
		$entity = $params['entity'];

		if (!$entity->data($model::key())) {
			if ($entity->$parent) {
				$this->_insertParent($entity);
			} else {
				$max = $this->_getMax($entity);
				$entity->set([
					$left => $max + 1,
					$right => $max + 2
				]);
			}
		} elseif (isset($entity->$parent)) {
			if ($entity->$parent === $entity->data($model::key())) {
				return false;
			}
			$oldNode = $this->_getById($entity->data($model::key()));
			if ($oldNode->$parent === $entity->$parent) {
				return true;
			}
			if (($newScope = $this->_scope($entity)) !== ($oldScope = $this->_scope($oldNode))) {
				$this->_updateScope($entity, $oldScope, $newScope);
				return true;
			}
			$this->_updateNode($entity);
		}
		return true;
	}

	/**
	 * Before delete
	 *
	 * this method is called befor each delete
	 *
	 * @param array $params
	 */
	protected static function _delete($params, $behavior) {
		return $this->_deleteFromTree($params['entity']);
	}

	/**
	 * Insert a parent
	 *
	 * inserts a node at given last position of parent set in $entity
	 *
	 * @param object $entity
	 */
	protected function _insertParent($entity) {
		extract($this->_config);
		$parent = $this->_getById($entity->$parent);
		if ($parent) {
			$r = $parent->$right;
			$this->_update($r, '+', 2, $this->_scope($entity));
			$entity->set([
				$left => $r,
				$right => $r + 1
			]);
		}
	}

	/**
	 * Update a node (when parent is changed)
	 *
	 * all the "move an element with all its children" magic happens here!
	 * first we calculate movements (shiftX, shiftY), afterwards shifting of ranges is done,
	 * where rangeX is is the range of the element to move and rangeY the area between rangeX
	 * and the new position of rangeX.
	 * to avoid double shifting of already shifted data rangex first is shifted in area < 0
	 * (which is always empty), after correcting rangeY's left and rights we move it to its
	 * designated position.
	 *
	 * @param object $entity updated tree element
	 */
	protected function _updateNode($entity) {
		extract($this->_config);

		$span = $entity->$right - $entity->$left;
		$spanToZero = $entity->$right;

		$rangeX = ['floor' => $entity->$left, 'ceiling' => $entity->$right];
		$shiftY = $span + 1;

		if ($entity->$parent !== null) {
			$newParent = $this->_getById($entity->$parent);
			if ($newParent) {
				$boundary = $newParent->$right;
			} else {
				throw new UnexpectedValueException("The `{$parent}` with id `{$entity->$parent}` doesn't exists.");
			}
		} else {
			$boundary = $this->_getMax($entity) + 1;
		}
		$this->_updateBetween($rangeX, '-', $spanToZero, $this->_scope($entity));

		if ($entity->$right < $boundary) {
			$rangeY = ['floor' => $entity->$right + 1, 'ceiling' => $boundary - 1];
			$this->_updateBetween($rangeY, '-', $shiftY, $this->_scope($entity));
			$shiftX = $boundary - $entity->$right - 1;
		} else {
			$rangeY = ['floor' => $boundary, 'ceiling' => $entity->$left - 1];
			$this->_updateBetween($rangeY, '+', $shiftY, $this->_scope($entity));
			$shiftX = ($boundary - 1) - $entity->$left + 1;
		}
		$this->_updateBetween([
			'floor' => (0 - $span), 'ceiling' => 0
		], '+', $spanToZero + $shiftX, $this->_scope($entity));
		$entity->set([$left => $entity->$left + $shiftX, $right => $entity->$right + $shiftX]);
	}

	/**
	 * Update a node (when scope has changed)
	 *
	 * all the "move an element with all its children" magic happens here!
	 *
	 * @param object $entity Updated tree element
	 * @param array $oldScope Old scope data
	 * @param array $newScope New scope data
	 */
	protected function _updateScope($entity, $oldScope, $newScope) {
		extract($this->_config);

		$span = $entity->$right - $entity->$left;
		$spanToZero = $entity->$right;

		$rangeX = ['floor' => $entity->$left, 'ceiling' => $entity->$right];

		$this->_updateBetween($rangeX, '-', $spanToZero, $oldScope, $newScope);
		$this->_update($entity->$right, '-', $span + 1, $oldScope);

		$newParent = $this->_getById($entity->$parent);
		$r = $newParent->$right;
		$this->_update($r, '+', $span + 1, $newScope);
		$this->_updateBetween([
			'floor' => (0 - $span), 'ceiling' => 0
		], '+', $span + $r, $newScope);
		$entity->set([$left => $r, $right => $span + $r]);
	}

	/**
	 * Delete from tree
	 *
	 * deletes a node (and its children) from the tree
	 *
	 * @param object $entity updated tree element
	 */
	protected function _deleteFromTree($entity) {
		extract($this->_config);

		$span = 1;
		if ($entity->$right - $entity->$left !== 1) {
			$span = $entity->$right - $entity->$left;
			$model::remove([$parent => $entity->data($model::key())]);
		}
		$this->_update($entity->$right, '-', $span + 1, $this->_scope($entity));
		return true;
	}

	/**
	 * Get by id
	 *
	 * returns the element with given id
	 *
	 * @param integer $id the id to fetch from db
	 */
	protected function _getById($id, $params = array()) {
		$model = $this->_config['model'];
		$conditions = array($model::key() => $id);
		if (isset($params['conditions'])) {
			$params['conditions'] = $params['conditions'] + $conditions;
		}
		$options = $params + array('conditions' => $conditions);
		$result = $model::find('all', $options);
		return $result->first();
	}

	/**
	 * Update node indices
	 *
	 * Updates the Indices in greater than $rght with given value.
	 *
	 * @param integer $rght the right index border to start indexing
	 * @param string $dir Direction +/- (defaults to +)
	 * @param integer $span value to be added/subtracted (defaults to 2)
	 * @param array $scp The scope to apply updates on
	 */
	protected function _update($rght, $dir = '+', $span = 2, $scp = []) {
		extract($this->_config);

		$model::update([$right => (object) ($right . $dir . $span)], [
			$right => ['>=' => $rght]
		] + $scp);

		$model::update([$left => (object) ($left . $dir . $span)], [
			$left => ['>' => $rght]
		] + $scp);
	}

	/**
	 * Update node indices between
	 *
	 * Updates the Indices in given range with given value.
	 *
	 * @param array $range the range to be updated
	 * @param string $dir Direction +/- (defaults to +)
	 * @param integer $span Value to be added/subtracted (defaults to 2)
	 * @param array $scp The scope to apply updates on
	 * @param array $data Additionnal scope datas (optionnal)
	 */
	protected function _updateBetween($range, $dir = '+', $span = 2, $scp = [], $data = []) {
		extract($this->_config);

		$model::update([$right => (object) ($right . $dir . $span)], [
			$right => [
				'>=' => $range['floor'],
				'<=' => $range['ceiling']
		]] + $scp);

		$model::update([$left => (object) ($left . $dir . $span)] + $data, [
			$left => [
				'>=' => $range['floor'],
				'<=' => $range['ceiling']
		]] + $scp);
	}

	/**
	 * Moves an element down in order
	 *
	 * @param object $entity The Entity to move down
	 */
	public function moveDown($entity) {
		extract($this->_config);
		$next = $model::find('first', [
					'conditions' => [
						$parent => $entity->$parent,
						$left => $entity->$right + 1
				]]);

		if ($next !== null) {
			$spanToZero = $entity->$right;
			$rangeX = ['floor' => $entity->$left, 'ceiling' => $entity->$right];
			$shiftX = ($next->$right - $next->$left) + 1;
			$rangeY = ['floor' => $next->$left, 'ceiling' => $next->$right];
			$shiftY = ($entity->$right - $entity->$left) + 1;

			$this->_updateBetween($rangeX, '-', $spanToZero, $this->_scope($entity));
			$this->_updateBetween($rangeY, '-', $shiftY, $this->_scope($entity));
			$this->_updateBetween([
				'floor' => (0 - $shiftY), 'ceiling' => 0
			], '+', $spanToZero + $shiftX, $this->_scope($entity));

			$entity->set([
				$left => $entity->$left + $shiftX, $right => $entity->$right + $shiftX
			]);
		}
		return true;
	}

	/**
	 * Moves an element up in order
	 *
	 * @param object $entity The Entity to move up
	 */
	public function moveUp($entity) {
		extract($this->_config);
		$prev = $model::find('first', [
			'conditions' => [
				$parent => $entity->$parent,
				$right => $entity->$left - 1
			]
		]);
		if (!$prev) {
			return true;
		}
		$spanToZero = $entity->$right;
		$rangeX = ['floor' => $entity->$left, 'ceiling' => $entity->$right];
		$shiftX = ($prev->$right - $prev->$left) + 1;
		$rangeY = ['floor' => $prev->$left, 'ceiling' => $prev->$right];
		$shiftY = ($entity->$right - $entity->$left) + 1;

		$this->_updateBetween($rangeX, '-', $spanToZero, $this->_scope($entity));
		$this->_updateBetween($rangeY, '+', $shiftY, $this->_scope($entity));
		$this->_updateBetween([
			'floor' => (0 - $shiftY), 'ceiling' => 0
		], '+', $spanToZero - $shiftX, $this->_scope($entity));

		$entity->set([
			$left => $entity->$left - $shiftX, $right => $entity->$right - $shiftX
		]);
		return true;
	}

	/**
	 * Get max
	 *
	 * @param object $entity An `Entity` object
	 * @return The highest 'right'
	 */
	protected function _getMax($entity) {
		extract($this->_config);

		$node = $model::find('first', [
			'conditions' => $this->_scope($entity),
			'order' => [$right => 'desc']
		]);
		if ($node) {
			return $node->$right;
		}
		return 0;
	}

	/**
	 * Returns the current position number of an element at the same level,
	 * where 0 is first position
	 *
	 * @param object $entity the entity node to get the position from.
	 * @param integer $childrenCount number of children of entity's parent,
	 *        performance parameter to avoid double select.
	 */
	protected function _getPosition($entity, $childrenCount = false) {
		extract($this->_config);

		$parent = $this->_getById($entity->$parent);

		if ($entity->$left === ($parent->$left + 1)) {
			return 0;
		}

		if (($entity->$right + 1) === $parent->$right) {
			if ($childrenCount === false) {
				$childrenCount = $parent->childrens(false, 'count');
			}
			return $childrenCount - 1;
		}

		$count = 0;
		$children = $parent->childrens(false);

		$id = $entity->data($model::key());
		foreach ($children as $child) {
			if ($child->data($model::key()) === $id) {
				return $count;
			}
			$count++;
		}

		return false;
	}

	/**
	 * Check if the current tree is valid.
	 *
	 * Returns true if the tree is valid otherwise an array of (type, incorrect left/right index,
	 * message)
	 *
	 * @param object $entity the entity node to get the position from.
	 * @return mixed true if the tree is valid or empty, otherwise an array of (error type [node,
	 *         boundary], [incorrect left/right boundary,node id], message)
	 */
	public function verify($entity) {
		extract($this->_config);

		$count = $model::find('count', [
			'conditions' => [
				$left => ['>' => $entity->$left],
				$right => ['<' => $entity->$right]
			] + $this->_scope($entity)
		]);
		if (!$count) {
			return true;
		}
		$min = $entity->$left;
		$edge = $entity->$right;

		if ($entity->$left >= $entity->$right) {
			$id = $entity->data($model::key());
			$errors[] = ['root node', "`{$id}`", 'has left greater than right.'];
		}

		$errors = [];

		for ($i = $min; $i <= $edge; $i++) {
			$count = $model::find('count', [
					'conditions' => [
						'or' => [$left => $i, $right => $i]
					] + $this->_scope($entity)
				]);

			if ($count !== 1) {
				if ($count === 0) {
					$errors[] = ['node boundary', "`{$i}`", 'missing'];
				} else {
					$errors[] = ['node boundary', "`{$i}`", 'duplicate'];
				}
			}
		}

		$node = $model::find('first', [
			'conditions' => [
				$right => ['<' => $left]
			] + $this->_scope($entity)
		]);

		if ($node) {
			$id = $node->data($model::key());
			$errors[] = ['node id', "`{$id}`", 'has left greater or equal to right.'];
		}

		$model::bind('belongsTo', 'Verify', [
			'to' => $model,
			'key' => $parent
		]);

		$results = $model::find('all', [
			'conditions' => $this->_scope($entity),
			'with' => ['Verify']
		]);

		$id = $model::key();
		foreach ($results as $key => $instance) {
			if (is_null($instance->$left) || is_null($instance->$right)) {
				$errors[] = ['node', $instance->$id,
					'has invalid left or right values'];
			} elseif ($instance->$left === $instance->$right) {
				$errors[] = ['node', $instance->$id,
					'left and right values identical'];
			} elseif ($instance->$parent) {
				if (!isset($instance->verify->$id) || !$instance->verify->$id) {
					$errors[] = ['node', $instance->$id,
						'The parent node ' . $instance->$parent . ' doesn\'t exist'];
				} elseif ($instance->$left < $instance->verify->$left) {
					$errors[] = ['node', $instance->$id,
						'left less than parent (node ' . $instance->verify->$id . ').'];
				} elseif ($instance->$right > $instance->verify->$right) {
					$errors[] = ['node', $instance->$id,
						'right greater than parent (node ' . $instance->verify->$id . ').'];
				}
			} elseif ($model::find('count', [
					'conditions' => [
					$left => ['<' => $instance->$left],
					$right => ['>' => $instance->$right]
					] + $this->_scope($entity)
				])) {
				$errors[] = ['node', $instance->$id, 'the parent field is blank, but has a parent'];
			}
		}

		if ($errors) {
			return $errors;
		}
		return true;
	}

	
	
	
	
	
	
	
	
	
	
	
	
	
	/**
	 * Experiments to fix the _save behaviour
	 */
	private static function _getScope($entity, $behavior) {
		$ret_scope = [];
		
		if(isset($behavior->_config['scope'])) {
			foreach($behavior->_config['scope'] as $scope) {
				$ret_scope[$scope] = $entity->{$scope};
			}
		}
		
		return $ret_scope;
	}

	private static function _findParent($id, $behavior, $scope) {
		$model = $behavior->_config['model'];

		$parent = $model::find('first', [
			'conditions' => [
				$model::key() => $id,
				$scope
			]
		]);

		return $parent->data();
	}

	private static function _updateParent($id, $behavior, $scope, $right) {
		$model = $behavior->_config['model'];

		$parent = $model::find('first', [
			'conditions' => [
				$model::key() => $id,
				$scope
			]
		]);

		$update_data[$behavior->_config['right']] = $right + 1;
		$parent->set($update_data);
		$parent->save();
	}

	private static function _calculateMaster($entity_data, $behavior) {
		$left = ((isset($entity_data[$behavior->_config['left']]) && $entity_data[$behavior->_config['left']] > 0) ? $entity_data[$behavior->_config['left']] : 1);
		$right = ((isset($entity_data[$behavior->_config['right']]) && $entity_data[$behavior->_config['right']] > 0) ? $entity_data[$behavior->_config['right']] : 2);

		return [
			$left,
			$right
		];
	}

	private static function _saveNew($params, $behavior) {
		$entity = $params['entity'];
		
		$scope = static::_getScope($entity, $behavior);

		$entity_data = $entity->data();
		if(isset($entity_data['parent_id']) && !empty($entity_data['parent_id'])) {
			$parent_data = static::_findParent($entity_data['parent_id'], $behavior, $scope);
			if($entity->exists()) {
				$left = $entity_data[$behavior->_config['left']];
				$right = $entity_data[$behavior->_config['right']];
			}
			else {
				$left = $parent_data[$behavior->_config['right']];
				$right = $parent_data[$behavior->_config['right']] + 1;
			}
			static::_updateParent($entity_data['parent_id'], $behavior, $scope, $right);
		}
		else {
			list($left, $right) = static::_calculateMaster($entity_data, $behavior);
                        $entity_data['parent_id'] = null;
		}
                
		$entity_data[$behavior->_config['left']] = $left;
		$entity_data[$behavior->_config['right']] = $right;
		$entity->set($entity_data);

		return true;
	}
	
	
	
	
	
	
	
	
	
}

\lithium\util\Collection::formats('tree', function($data, $options = array()) {
    $defaults = array('left' => 'lft', 'right' => 'rght', 'parent' => 'parent_id', 'children' => 'children');
    $options += $defaults;
    if ((!is_object($data)) && (!is_a($data, 'lithium\util\Collection'))) {
        $data = new \lithium\util\Collection(compact('data'));
    }
	$data->rewind();
    $root = $data->current();
    $refs = array();
    while ($data->valid()) {
        $node = $data->current();
        $node->{$options['children']} = new \lithium\util\Collection();
        $refs[$node->id] = $node;
        if (isset($refs[$node->{$options['parent']}])) {
            $refs[$node->{$options['parent']}]->{$options['children']}[] = $node;
        }
        $data->next();
    }
    return $root;
});

?>