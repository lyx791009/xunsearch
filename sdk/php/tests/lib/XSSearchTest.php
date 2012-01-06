<?php
require_once dirname(__FILE__) . '/../../lib/XSSearch.class.php';

/**
 * Test class for XSSearch
 * Generated by PHPUnit on 2011-09-15 at 19:29:49.
 */
class XSSearchTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var XS
	 */
	protected static $xs;

	public static function setUpBeforeClass()
	{
		$data = array(
			array(
				'pid' => 3,
				'subject' => '关于 xunsearch 的 DEMO 项目测试',
				'message' => '项目测试是一个很有意思的行为！',
				'chrono' => 214336158,
				'other' => 'master',
			),
			array(
				'pid' => 11,
				'subject' => '测试第二篇',
				'message' => '这里是第二篇文章的内容',
				'chrono' => 1314336168,
				'other' => 'slave',
			),
			array(
				'pid' => 21,
				'subject' => '项目测试第三篇',
				'message' => '俗话说，无三不成礼，所以就有了第三篇',
				'chrono' => 314336178,
				'other' => 'master',
			)
		);

		// create object
		self::$xs = new XS(end($GLOBALS['fixIniData']));
		$index = self::$xs->index;

		// create testing data
		$doc = new XSDocument('utf-8');
		foreach ($data as $tmp)
		{
			$doc->setFields(null);
			$doc->setFields($tmp);
			$index->add($doc);
		}
		$index->addSynonym('project', '项目');
		$index->flushIndex();

		// create another db
		$index->setDb('db2');
		foreach ($data as $tmp)
		{
			$tmp['pid'] += 1000;
			$doc->setFields(null);
			$doc->setFields($tmp);
			$index->add($doc);
		}
		// create synonyms on db2
		$synonyms = array(
			'test' => '测试',
			'hello world' => '有意思',
			'迅搜' => 'xunsearch',
		);
		$index->openBuffer();
		foreach ($synonyms as $raw => $syn)
		{
			$index->addSynonym($raw, $syn);
		}
		$index->addSynonym('test', 'quiz');
		$index->closeBuffer();

		// flush index & logging
		$index->flushIndex();
		sleep(2);
		$index->flushLogging();
		sleep(2);
	}

	public static function tearDownAfterClass()
	{
		// clean testing data
		self::$xs->index->reopen(true);
		self::$xs->index->clean();
		self::$xs->index->setDb('db2');
		self::$xs->index->clean();
		self::$xs->index->setDb(XSSearch::LOG_DB);
		self::$xs->index->clean();
		self::$xs = null;
	}

	/**
	 * Sets up the fixture, for example, opens a network connection.
	 * This method is called before a test is executed.
	 */
	protected function setUp()
	{
		self::$xs->search->setCharset('UTF8')->setSort(null);
	}

	/**
	 * Tears down the fixture, for example, closes a network connection.
	 * This method is called after a test is executed.
	 */
	protected function tearDown()
	{
		
	}

	public function testFacets()
	{
		$search = self::$xs->search;
		$docs = $search->setQuery('subject:测试')->setFacets(array('other'))->search();

		$this->assertEquals(3, count($docs));
		$this->assertEquals('测试第二篇', $docs[0]->subject);

		$facets = $search->getFacets('other');
		$this->assertEquals($facets['master'], 2);
		$this->assertEquals($facets['slave'], 1);
	}

	public function testCharset()
	{
		$xs = self::$xs;
		$search = $xs->search;
		$query = 'subject:测试';

		$docs = $search->search($query);
		$this->assertEquals(3, count($docs));
		$this->assertEquals('测试第二篇', $docs[0]->subject);

		$this->assertEquals('GBK', $xs->getDefaultCharset());
		$search->setCharset($xs->getDefaultCharset());
		$this->assertEquals(0, $search->count($query));

		$docs = $search->search(XS::convert($query, 'GBK', 'UTF-8'));
		$this->assertEquals(3, count($docs));
		$this->assertEquals(XS::convert('测试第二篇', 'GBK', 'UTF-8'), $docs[0]->subject);
	}

	public function testFuzzy()
	{
		$search = self::$xs->search;
		$this->assertEquals(2, $search->count('subject:项目测试')); // default: non-fuzzy
		$this->assertEquals(3, $search->setFuzzy()->count('subject:项目测试'));
		// restore
		$search->setFuzzy(false);
	}

	/** 	 
	 * @dataProvider queryProvider
	 */
	public function testQuery($raw, $parsed)
	{
		$search = self::$xs->search;
		$this->assertEquals($parsed, $search->setQuery($raw)->getQuery());
	}

	public function queryProvider()
	{
		return array(
			array('测试', 'Xapian::Query(测试:(pos=1))'),
			array('subject:测试', 'Xapian::Query(B测试:(pos=1))'),
			array('subject:项目测试', 'Xapian::Query((B项目:(pos=1) AND B测试:(pos=2)))'),
			array('subject2:测试', 'Xapian::Query((Zsubject2:(pos=1) AND 测试:(pos=2)))'),
			array('subject2:Hello', 'Xapian::Query((subject2:(pos=1) PHRASE 2 hello:(pos=2)))'),
			array('项目管理制度', 'Xapian::Query((项目:(pos=1) AND (管理制度:(pos=2) SYNONYM (管理:(pos=90) AND 制度:(pos=91)))))'),
			array('subject:项目管理制度', 'Xapian::Query((B项目:(pos=1) AND (B管理制度:(pos=2) SYNONYM (B管理:(pos=90) AND B制度:(pos=91)))))'),
			array('几句说明', 'Xapian::Query((几句:(pos=1) AND 说明:(pos=2)))'),
			array('说明几句', 'Xapian::Query((说明:(pos=1) AND 几句:(pos=2)))'),
		);
	}

	public function testSetSort()
	{
		$search = self::$xs->search;

		// pid string
		$docs = $search->setSort('pid')->setLimit(1)->search('subject:测试');
		$this->assertEquals(3, $docs[0]->pid);

		$docs = $search->setSort('pid', true)->setLimit(1)->search('subject:测试');
		$this->assertEquals(11, $docs[0]->pid);

		// chrono numeric
		$docs = $search->setSort('chrono')->setLimit(1)->search('subject:测试');
		$this->assertEquals(11, $docs[0]->pid);

		$docs = $search->setSort('chrono', true)->setLimit(1)->search('subject:测试');
		$this->assertEquals(3, $docs[0]->pid);

		$search->setSort(null);
	}

	public function testSetMultiSort()
	{
		$search = self::$xs->search;

		// pid string
		$docs = $search->setMultiSort(array('pid'))->setLimit(1)->search('subject:测试');
		$this->assertEquals(3, $docs[0]->pid);

		$docs = $search->setMultiSort(array('pid' => true))->setLimit(1)->search('subject:测试');
		$this->assertEquals(11, $docs[0]->pid);

		// other (desc) + chrono (desc)
		$docs = $search->setMultiSort(array('other', 'chrono'))->setLimit(1)->search('subject:测试');
		$this->assertEquals(11, $docs[0]->pid);

		// other (asc) + chrono(desc)
		$docs = $search->setMultiSort(array('other' => true, 'chrono'))->setLimit(1)->search('subject:测试');
		$this->assertEquals(21, $docs[0]->pid);

		// other (asc) + chrono(asc)
		$docs = $search->setMultiSort(array('other' => true, 'chrono' => true))->setLimit(1)->search('subject:测试');
		$this->assertEquals(3, $docs[0]->pid);
	}

	public function testCollapse()
	{
		$search = self::$xs->search;
		$docs = $search->setCollapse('other')->search('subject:测试');
		$this->assertEquals(2, count($docs));
		$this->assertEquals(0, $docs[0]->ccount());
		$this->assertEquals(1, $docs[1]->ccount());

		$docs = $search->setCollapse('other', 2)->search('subject:测试');
		$this->assertEquals(3, count($docs));
		$this->assertEquals(0, $docs[0]->ccount());
		$this->assertEquals(0, $docs[1]->ccount());
	}

	public function testRange()
	{
		$search = self::$xs->search;
		$query = 'subject:测试';

		// string
		$docs = $search->setQuery($query)->addRange('pid', 20, 30)->search();
		$this->assertEquals(2, count($docs));
		$docs = $search->setQuery($query)->addRange('pid', null, 30)->search();
		$this->assertEquals(3, count($docs));
		$docs = $search->setQuery($query)->addRange('pid', 12, null)->search();
		$this->assertEquals(2, count($docs));

		// fast search (non-effects)
		$docs = $search->setQuery($query)->addRange('pid', 12, null)->search('subject:测试');
		$this->assertEquals(3, count($docs));

		// numeric
		$docs = $search->setQuery($query)->addRange('chrono', 214336157, 1314336168)->search();
		$this->assertEquals(3, count($docs));
		$docs = $search->setQuery($query)->addRange('chrono', null, 314336178)->search();
		$this->assertEquals(2, count($docs));
		$docs = $search->setQuery($query)->addRange('chrono', 214336159, null)->search();
		$this->assertEquals(2, count($docs));
	}

	public function testWeight()
	{
		$search = self::$xs->search;
		$query = 'subject:测试';

		$docs = $search->setQuery($query)->search();
		$this->assertEquals(11, $docs[0]->pid);

		$docs = $search->setQuery($query)->addWeight('subject', 'demo')->search();
		$this->assertEquals(3, $docs[0]->pid);

		$docs = $search->setQuery($query)->addWeight('subject', 'demo')->addWeight(null, '俗话', 2)->search();
		$this->assertEquals(21, $docs[0]->pid);
	}

	public function testLimit()
	{
		$search = self::$xs->search;
		$search->query = 'subject:测试';

		$docs = $search->search();
		$this->assertEquals(3, count($docs));

		$docs = $search->setLimit(2)->search();
		$this->assertEquals(2, count($docs));

		$docs = $search->setLimit(1)->search();
		$this->assertEquals(11, $docs[0]->pid);
		$docs = $search->setLimit(1, 1)->search();
		$this->assertEquals(21, $docs[0]->pid);
		$docs = $search->setLimit(1, 2)->search();
		$this->assertEquals(3, $docs[0]->pid);
	}

	public function testMultiDb()
	{
		$search = self::$xs->search;
		$search->setDb('db2');

		$docs = $search->search('subject:测试');
		$this->assertEquals(3, $search->dbTotal);
		$this->assertEquals(11 + 1000, $docs[0]->pid);

		$search->setDb(null);
		$docs = $search->search('subject:测试');
		$this->assertEquals(11, $docs[0]->pid);
		$this->assertEquals(3, $search->dbTotal);

		$search->addDb('db2');
		$this->assertEquals(6, $search->dbTotal);
		$docs = $search->search('subject:测试');
		$this->assertEquals(6, count($docs));
		$search->setDb(null);
	}

	public function testTerms()
	{
		$search = self::$xs->search;

		$search->query = 'subject:项目测试 working';
		$this->assertEquals(array('项目', '测试', 'working'), $search->terms());
		$this->assertEquals(array('项目', 'working'), $search->terms('项目working'));
	}

	public function testCount()
	{
		$search = self::$xs->search;

		$this->assertEquals(3, $search->count('subject:测试'));
		$this->assertEquals(1, $search->count('测试'));
		$search->query = '内容';
		$this->assertEquals(1, $search->count());
	}

	public function testSearch()
	{
		$search = self::$xs->search;
	}

	public function testHotQuery()
	{
		$search = self::$xs->search;
	}

	public function testRelatedQuery()
	{
		$search = self::$xs->search;
		$search->setQuery('项目测试')->search();
		$search->getRelatedQuery();
		$search->xs->index->reopen(true)->flushLogging();
		sleep(3);
		$search->reopen(true);

		$search->setQuery('测试')->search();
		$words = $search->getRelatedQuery();
		$this->assertEquals('项目测试', $words[0]);

		$words = $search->getRelatedQuery('项目');
		$this->assertEquals('项目测试', $words[0]);
	}

	public function testExpandedQuery()
	{
		$search = self::$xs->search;
		$this->assertEquals(array('测试'), $search->getExpandedQuery('c'));
		$this->assertEquals(array('测试'), $search->getExpandedQuery('cs'));
		$this->assertEquals(array('测试'), $search->getExpandedQuery('ces'));
		$this->assertEquals(array('测试'), $search->getExpandedQuery('测'));
	}

	public function testCorrectedQuery()
	{
		$search = self::$xs->search;
		$this->assertEquals(array('测试'), $search->getCorrectedQuery('cs'));
		$this->assertEquals(array('测试'), $search->getCorrectedQuery('策试'));
		$this->assertEquals(array('测试'), $search->getCorrectedQuery('ceshi'));
	}

	public function testHightlight()
	{
		$search = self::$xs->search;
		$search->setQuery('subject:测试 OR DEMO')->search();

		$this->assertEquals('<em>测试</em>一下 <em>DEMO</em>', $search->highlight('测试一下 DEMO'));
		$this->assertEquals('评<em>测试</em>试一下', $search->highlight('评测试试一下'));
	}

	public function testAddSearchLog()
	{
		$search = self::$xs->search;

		$search->addSearchLog('php 教程');
		$search->addSearchLog('php 教学');
		$search->addSearchLog('php 教导', 999);
		$search->addSearchLog('php 教程');
		self::$xs->index->reopen(true)->flushLogging();
		sleep(2);
		self::$xs->setScheme(XSFieldScheme::logger());
		$search->reopen(true);
		$docs = $search->setDb(XSSearch::LOG_DB)->search('php');
		$search->setDb(null);
		self::$xs->restoreScheme();
		$this->assertEquals($docs[0]->total, 999);
		$this->assertEquals($docs[1]->total, 2);
		$this->assertEquals($docs[2]->total, 1);
	}

	public function testGetAllSynonyms()
	{
		$search = self::$xs->search; /* @var $search XSSearch */
		$synonyms = $search->getAllSynonyms(0, 0, true);
		$this->assertEquals(2, count($synonyms));
		$this->assertEquals('项目', $synonyms['Zproject'][0]);
		$this->assertEquals('项目', $synonyms['project'][0]);

		$search->addDb('db2');
		$synonyms = $search->getAllSynonyms(2);
		$this->assertEquals(2, count($synonyms));
		$synonyms = $search->getAllSynonyms(2, 3);
		$this->assertEquals(1, count($synonyms));
		$synonyms = $search->getAllSynonyms(2, 4);
		$this->assertEquals(0, count($synonyms));

		$synonyms = $search->getAllSynonyms();
		$this->assertEquals('项目', $synonyms['project'][0]);
		$this->assertEquals('有意思', $synonyms['hello world'][0]);
		$this->assertEquals(4, count($synonyms));
		$search->setDb(null);
	}

	public function testSearchSynonyms()
	{
		$search = self::$xs->search; /* @var $search XSSearch */
		$search->setDb('db2');
		
		// test fuzzy multi query
		$search->setFuzzy();
		$this->testQuery('中华人民共和国', 'Xapian::Query((中华人民共和国:(pos=1) SYNONYM (中华:(pos=89) OR 人民:(pos=90) OR 共和国:(pos=91))))');
		$this->testQuery('"中华人民共和国"', 'Xapian::Query(中华人民共和国:(pos=1))');	
		$search->setFuzzy(false);
		
		// test without synonyms
		$queries = array(
			'项目test' => 'Xapian::Query((项目:(pos=1) AND Ztest:(pos=2)))',
			'俗话 subject:(项目 test)' => 'Xapian::Query((俗话:(pos=1) AND B项目:(pos=2) AND ZBtest:(pos=3)))',
			'爱写hello world' => 'Xapian::Query((爱写:(pos=1) AND Zhello:(pos=2) AND Zworld:(pos=3)))',
			'demo 迅搜' => 'Xapian::Query((Zdemo:(pos=1) AND 迅搜:(pos=2)))',
			'"demo 迅搜"' => 'Xapian::Query((demo:(pos=1) PHRASE 2 迅搜:(pos=2)))',
			'testing' => 'Xapian::Query(Ztest:(pos=1))',
		);
		foreach ($queries as $raw => $expect)
		{
			$this->testQuery($raw, $expect);
		}		
		
		// test synonym query
		$search->setAutoSynonyms();
		$queries = array(
			'项目test' => 'Xapian::Query((项目:(pos=1) AND (Ztest:(pos=2) SYNONYM quiz:(pos=79) SYNONYM 测试:(pos=80))))',
			'俗话 subject:(项目 test)' => 'Xapian::Query((俗话:(pos=1) AND B项目:(pos=2) AND (ZBtest:(pos=3) SYNONYM Bquiz:(pos=80) SYNONYM B测试:(pos=81))))',
			'爱写hello world' => 'Xapian::Query((爱写:(pos=1) AND ((Zhello:(pos=2) AND Zworld:(pos=3)) SYNONYM 有意思:(pos=68))))',
			'demo 迅搜' => 'Xapian::Query((Zdemo:(pos=1) AND (迅搜:(pos=2) SYNONYM xunsearch:(pos=90))))',
			'"demo 迅搜"' => 'Xapian::Query((demo:(pos=1) PHRASE 2 迅搜:(pos=2)))',
			'testing' => 'Xapian::Query((Ztest:(pos=1) SYNONYM Zquiz:(pos=78) SYNONYM 测试:(pos=79)))',			
		);
		foreach ($queries as $raw => $expect)
		{
			$this->testQuery($raw, $expect);
		}
		
		// test search result & highlight
		$docs = $search->setAutoSynonyms(false)->search('项目test');
		$this->assertEquals(0, count($docs));
		$docs = $search->setAutoSynonyms(true)->search('subject:项目test');
		$this->assertEquals(2, count($docs));
		
		// search with query
		$docs = $search->setAutoSynonyms(true)->setFuzzy(true)->search('项目testing');
		$this->assertEquals(1, count($docs));
		$this->assertEquals('test和<em>Quiz</em>以及<em>测试</em>', $search->highlight('test和Quiz以及测试'));
		
		// default search
		$docs = $search->setAutoSynonyms(true)->setFuzzy(false)->setQuery('hello world 项目')->search();
		$this->assertEquals(1, count($docs));
		$this->assertEquals('<em>有意思</em>的<em>项目</em>', $search->highlight('有意思的项目'));
		
		// restore db
		$search->setDb(null);
	}

	public function testSetDb()
	{
		$index = self::$xs->index;
		$index->clean();
		sleep(1);

		$search = self::$xs->search;
		try
		{
			$e1 = null;
			$search->setDb(null);
		}
		catch (XSException $e1)
		{
			
		}
		$search->reopen(true);
		try
		{
			$e2 = null;
			$search->setDb('db');
		}
		catch (XSException $e2)
		{
			
		}
		$this->assertNull($e1);
		$this->assertInstanceOf('XSException', $e2);
		$this->assertEquals(CMD_ERR_XAPIAN, $e2->getCode());
	}
}
