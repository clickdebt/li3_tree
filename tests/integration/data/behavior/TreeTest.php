<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_tree\tests\integration\data\behavior;

use lithium\Data\Connections;
use lithium\Data\Source\Database;
use li3_fixtures\test\Fixtures;
use li3_tree\tests\fixture\model\Image;
use li3_tree\tests\fixture\model\Comment;
use li3_tree\extensions\data\behavior\Tree;

class TreeTest extends \lithium\test\Integration {

	protected $_connection = 'test';

	protected $_fixtures = array(
		'image' => 'li3_tree\tests\fixture\source\ImageFixture',
		'comment' => 'li3_tree\tests\fixture\source\CommentFixture'
	);

	/**
	 * Skip the test if no test database connection available.
	 */
	public function skip() {
		$dbConfig = Connections::get($this->_connection, array('config' => true));
		$isAvailable = (
			$dbConfig &&
			Connections::get($this->_connection)->isConnected(array('autoConnect' => true))
		);
		$this->skipIf(!$isAvailable, "No {$this->_connection} connection available.");

		$this->_db = Connections::get($this->_connection);

		$this->skipIf(
			!($this->_db instanceof Database),
			"The {$this->_connection} connection is not a relational database."
		);
	}

	/**
	 * Creating the test database
	 *
	 */
	public function setUp() {
		Fixtures::config(array(
			'db' => array(
				'adapter' => 'Connection',
				'connection' => $this->_connection,
				'fixtures' => $this->_fixtures
			)
		));
		Comment::config(array('meta' => array('conneciton' => $this->_connection)));
		Image::config(array('meta' => array('conneciton' => $this->_connection)));
	}

	/**
	 * Dropping the test database
	 */
	public function tearDown() {
		Comment::reset();
		Image::reset();
		Fixtures::clear('db');
	}

	public function testInit() {
		$this->expectException("/`'model'` option needs to be defined/");
		new Tree();
	}

	public function testVerify() {
		Fixtures::save('db', array('comment'));
		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		Comment::actsAs('Tree', array('scope' => array('image_id')));
		foreach ($entities as $entity) {
			$this->assertTrue($entity->verify() === true);
		}
	}

	public function testChildren() {
		Fixtures::save('db', array('comment'));
		$entities = Comment::find('all');
		$datas = $entities->data();
		$idField = Comment::key();
		$cpt = 0;
		foreach ($entities as $entity) {
			$expected = array();
			foreach ($datas as $data) {
				if ($entity->$idField == $data['parent_id']) {
					$expected[$data[$idField]] = $data;
				}
			}
			$cpt++;
			$this->assertEqual($expected, $entity->childrens()->data());
		}

		$this->assertEqual(7, $cpt);

		$cpt = 0;
		foreach ($entities as $entity) {
			$expected = array();
			foreach ($datas as $data) {
				if ($entity->lft < $data['lft'] &&
					$entity->rght > $data['rght']) {
					$expected[$data['id']] = $data;
				}
			}
			$cpt++;
			$tmp = $entity->childrens(true)->data();
			$result = array();
			foreach ($tmp as $value) {
				$result[$value['id']] = $value;
			}
			$this->assertEqual($expected, $result);
		}
	}

	public function testChildrenScope() {
		Fixtures::save('db', array('comment'));
		$entity = Comment::find('first');
		$this->assertEqual(5, count($entity->childrens(true)->data()));

		Comment::actsAs('Tree', array('scope' => array('image_id')));
		$this->assertEqual(3, count($entity->childrens(true)->data()));
	}

	public function testCountChildren() {
		Fixtures::save('db', array('comment'));
		$entity = Comment::find('first');
		$this->assertEqual(3, $entity->childrens(true, 'count'));
	}

	public function testCountChildrenScope() {
		Fixtures::save('db', array('comment'));
		$entity = Comment::find('first');

		Comment::actsAs('Tree', array('scope' => array('image_id')));
		$this->assertEqual(3, $entity->childrens(true, 'count'));
		$this->assertEqual(1, $entity->childrens(false, 'count'));
	}

	public function testPath() {
		Fixtures::save('db', array('comment'));
		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));

		$expected = array(
			'1' => $entities[1]->data(),
			'2' => $entities[2]->data(),
			'4' => $entities[4]->data()
		);
		$this->assertEqual($expected, $entities[4]->path()->data());

	}

	public function testPathScope() {
		Fixtures::save('db', array('comment'));
		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));

		Comment::actsAs('Tree', array('scope' => array('image_id')));
		$expected = array(
			'1' => $entities[1]->data(),
			'2' => $entities[2]->data(),
			'4' => $entities[4]->data()
		);
		$this->assertEqual($expected, $entities[4]->path()->data());
	}

	public function testMoveUpAndDown() {
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$datas = $entities->data();

		$node6 = $entities[4]->data();
		$this->assertTrue($entities[4]->moveUp());

		$expected = array(
            'id' => 4,
            'image_id' => 1,
            'body' => 'Comment 1.1.2',
            'parent_id' => 2,
            'lft' => 3,
            'rght' => 4,
            'published' => 'Y'
        );

		$this->assertEqual($expected, $entities[4]->data());

		$this->assertTrue($entities[4]->moveUp());
		$this->assertEqual($expected, $entities[4]->data());

		$this->assertTrue($entities[4]->moveDown());
		$this->assertEqual($node6, $entities[4]->data());

		$this->assertTrue($entities[4]->moveDown());
		$this->assertEqual($node6, $entities[4]->data());

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$this->assertEqual($datas, $entities->data());
	}

	public function testMove() {
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$datas = $entities->data();

		$node6 = $entities[4]->data();
		$this->assertTrue($entities[4]->move(0));

		$expected = array(
            'id' => 4,
            'image_id' => 1,
            'body' => 'Comment 1.1.2',
            'parent_id' => 2,
            'lft' => 3,
            'rght' => 4,
            'published' => 'Y'
        );

		$this->assertEqual($expected, $entities[4]->data());

		$this->assertTrue($entities[4]->move(-1));
		$this->assertEqual($expected, $entities[4]->data());

		$this->assertTrue($entities[4]->move(1));
		$this->assertEqual($node6, $entities[4]->data());

		$this->assertTrue($entities[4]->move(2));
		$this->assertEqual($node6, $entities[4]->data());

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$this->assertEqual($datas, $entities->data());
	}

	public function testReparent() {
		Fixtures::create('db', array('image'));
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$datas = $entities->data();

		$this->assertTrue($entities[4]->move(0, $entities[3]));

		$expected = array(
            'id' => 4,
            'image_id' => 1,
            'body' => 'Comment 1.1.2',
            'parent_id' => 3,
            'lft' => 4,
            'rght' => 5,
            'published' => 'Y'
        );

		$this->assertEqual($expected, $entities[4]->data());

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$expected = array(
            'id' => 3,
            'image_id' => 1,
            'body' => 'Comment 1.1.1',
            'parent_id' => 2,
            'lft' => 3,
            'rght' => 6,
            'published' => 'N'
        );

		$this->assertEqual($expected, $entities[3]->data());

		$entities = Comment::find('all', array('order' => array('id' => 'asc')));
		$this->assertTrue($entities[1]->verify() === true);
	}

	public function testImpossibleReparent() {
		Fixtures::create('db', array('image'));
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$this->assertFalse($entities[1]->move(0, $entities[4]));

		$entities = Comment::find('all', array('order' => array('id' => 'asc')));
		$this->assertTrue($entities[1]->verify() === true);
	}

	public function testChangeScope() {
		Fixtures::create('db', array('image'));
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));

		$this->assertTrue($entities[3]->move(0, $entities[6]));

		$entities = Comment::find('all', array('order' => array('id' => 'asc')));
		$this->assertTrue($entities[1]->verify() === true);
		$this->assertTrue($entities[3]->verify() === true);
	}

	public function testChangeScopeWithSubTree() {
		Fixtures::create('db', array('image'));
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$this->assertTrue($entities[2]->move(0, $entities[6]));
		$entities = Comment::find('all', array('order' => array('id' => 'asc')));
		$this->assertTrue($entities[1]->verify() === true);
		$this->assertTrue($entities[3]->verify() === true);
	}

	public function testDelete() {
		Fixtures::create('db', array('image'));
		Fixtures::save('db', array('comment'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$entities = Comment::find('all', array('order' => array('lft' => 'asc')));
		$this->assertTrue($entities[2]->delete());
		$this->assertTrue($entities[6]->delete());
		$entities = Comment::find('all', array('order' => array('id' => 'asc')));
		$this->assertTrue($entities[1]->verify() === true);
		$this->assertTrue($entities[5]->verify() === true);
	}

	public function testCreate() {
		Fixtures::create('db', array('comment', 'image'));
		Comment::actsAs('Tree', array('scope' => array('image_id')));

		$root1 = Comment::create(array('image_id' => 1));
		$root1->save();
		$root2 = Comment::create(array('image_id' => 2));
		$root2->save();
		$neighbor1 = Comment::create(array('image_id' => 1));
		$neighbor1->save();

		$idField = Comment::key();
		$subelement1 = Comment::create(array('image_id' => 1, 'parent_id' => $neighbor1->$idField));
		$subelement1->save();

		$entities = Comment::find('all', array('order' => array('id' => 'asc')));

		$expected = array(
			'1' => array(
				'id' => '1', 'image_id' => '1', 'body' => NULL, 'parent_id' => NULL,
				'lft' => '1', 'rght' => '2', 'published' => 'N'
			),
			'2' => array(
				'id' => '2', 'image_id' => '2', 'body' => NULL, 'parent_id' => NULL,
				'lft' => '1', 'rght' => '2', 'published' => 'N'
			),
			'3' => array(
				'id' => '3', 'image_id' => '1', 'body' => NULL, 'parent_id' => NULL,
				'lft' => '3', 'rght' => '6', 'published' => 'N'
			),
			'4' => array(
				'id' => '4', 'image_id' => '1', 'body' => NULL, 'parent_id' => '3',
				'lft' => '4', 'rght' => '5', 'published' => 'N'
			)
		);
		$this->assertEqual($expected, $entities->data());
	}

}